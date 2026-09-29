<?php

namespace Clarity\Engine\Compiler;

use Clarity\ClarityException;
use Clarity\Engine\CompiledTemplate;
use Clarity\Engine\Compiler;
use Clarity\Engine\Registry;
use Clarity\Engine\SourceMap;
use Clarity\Engine\Tokenizer;
use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLoader;

/**
 * Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.
 */
trait DirectiveSupportTrait
{


    /**
     * Names a template may never BIND, because PHP itself cannot accept them as
     * an assignment target: `$this` and `$GLOBALS` both raise an UNCATCHABLE
     * fatal ("Cannot re-assign $this" / "Cannot re-assign $GLOBALS"), so they
     * have to be caught while compiling rather than while rendering.
     *
     * This is a syntax-validity list, not a security one.  Every other name —
     * superglobals included — is an ordinary template variable: in open mode
     * `_SERVER` resolves to the real superglobal exactly as raw PHP would, and
     * in sandbox mode it resolves inside `$__c_va` like any other name.
     */
    private const RESERVED_NAMES = ['this', 'GLOBALS'];

    /**
     * Validate a name a template wants to BIND (a `{% for %}` variable, or the
     * root of a `{% set %}` lvalue).
     *
     * Two rules, for two different reasons:
     *  - `__c_…` is the engine's own namespace in the render frame.  Binding one
     *    would clobber an internal for the rest of the render (a filter registry
     *    swapped out at line 3 breaks every filter after it), so it is refused in
     *    BOTH modes.  This is collision avoidance, not a boundary — open mode
     *    reaches the same internals through raw PHP anyway.
     *  - `$this` / `$GLOBALS` are PHP grammar, and fail uncatchably at runtime.
     *
     * Every other name is allowed, including `_SERVER` and the other
     * superglobals, and including ordinary `__foo` / `_c_foo` / `___foo` names.
     *
     * @param string   $name    Identifier, sigil already stripped.
     * @param string   $raw     Original spelling, for the error message.
     * @param int|null $tplLine Directive line, when known.
     */
    private function assertBindableName(string $name, string $raw, ?int $tplLine): void
    {
        // PHP's variable-name grammar, bytes 128-255 included — see
        // Tokenizer::isIdentifier().  Shared with the tokenizer so this method's
        // accepted set can never drift from what the scanner produces, which is
        // the set it will later resolve back out of the local-variable map.
        if (!Tokenizer::isIdentifier($name)) {
            $this->rejectVar("Invalid variable name: '{$raw}'", $tplLine);
        }

        if (\str_starts_with($name, self::INTERNAL_PREFIX)) {
            $this->rejectVar(
                "Variable names starting with '" . self::INTERNAL_PREFIX . "' are reserved for internal use.",
                $tplLine
            );
        }

        if (\in_array($name, self::RESERVED_NAMES, true)) {
            $this->rejectVar("Variable '{$name}' cannot be assigned to in a template.", $tplLine);
        }
    }

    /**
     * Register a local variable in the compile-time context.
     *
     * @param string   $name    The name of the variable to register.
     * @param int|null $tplLine Line of the directive that requested the
     *                          registration, when known.  It is only used to
     *                          point the error at the offending directive
     *                          rather than at a bare variable name.
     */
    public function registerVar(string $name, ?int $tplLine = null)
    {
        // Accept `name` (what the tokenizer passes) as well as `$name`, the
        // spelling a directive author is more likely to use.  Exactly ONE sigil
        // is stripped: `$$x` is not a variable name, and stripping both would
        // silently register `x` — a name the caller never asked for.
        if ($name !== '' && $name[0] === '$') {
            if (($name[1] ?? '') === '$') {
                $this->rejectVar("Invalid variable name: '{$name}'", $tplLine);
            }
            $raw  = $name;
            $name = \substr($name, 1);
        } else {
            $raw = $name;
        }

        $this->assertBindableName($name, $raw, $tplLine);

        $this->localVars[$name] = '$' . $name;
        $this->tokenizer->setLocalVars($this->localVars);
    }

    private function rejectVar(string $message, ?int $tplLine): void
    {
        [$file, $line] = $this->resolveCurrentLocation($tplLine);
        throw new ClarityException($message, $file, $line);
    }

    /**
     * Unregister a local variable from the compile-time context.
     *
     * @param string $name  The name of the variable to unregister.
     */
    public function unregisterVar(string $name)
    {
        unset($this->localVars[$name]);
        $this->tokenizer->setLocalVars($this->localVars);
    }

    /**
     * Get the currently registered local variables.
     *
     * @return array<string, string> Map of local variable names to their PHP representations.
     */
    public function getVars(): array
    {
        return $this->localVars;
    }

    // -------------------------------------------------------------------------
    // Macros
    // -------------------------------------------------------------------------

    /**
     * Scan $source for {% macro @name(params) %}...{% endmacro %} definitions,
     * store them in $this->macros, and strip the definitions from the source.
     */
    private function extractMacros(string &$source): void
    {
        $pattern = '/\{%-?\s*macro\s+@([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)\s*-?%\}(.*?)\{%-?\s*endmacro\s*-?%\}/s';
        $source  = (string) \preg_replace_callback($pattern, function (array $m): string {
            $name   = $m[1];
            $params = $m[2] !== '' ? \array_map('trim', \explode(',', $m[2])) : [];
            $this->macros[$name] = ['params' => $params, 'body' => $m[3]];
            return '';
        }, $source);
    }

    /**
     * Extract `{% php %}…{% endphp %}` regions from the source, replacing each
     * with a line-preserving sentinel tag that {@see compileSourceInto()} later
     * turns back into raw PHP.
     *
     * Two interchangeable spellings are accepted:
     *
     *   block form       {% php %} ... {% endphp %}
     *   standalone form  {% php <code> %}
     *
     * The standalone form carries one statement -- or one fragment of a control
     * structure -- per tag, which is what lets PHP structure wrap template
     * markup without a single block spanning it:
     *
     *   {% php if ($items) : %}
     *   ... markup ...
     *   {% php endif %}
     *
     * Both are OPEN-MODE features and are rejected while the sandbox is enabled.
     *
     * The body is held aside rather than tokenized, for two reasons:
     *  - It must reach the compiled class VERBATIM: only the surrounding `{% %}`
     *    trivia is stripped, the body's own whitespace is kept.
     *  - Its interior may be text the template tokenizer would mis-scan (an
     *    unbalanced `{`, a `?>` tag, a template fragment), so the sentinel keeps
     *    only the region's LINE COUNT, which preserves the mapping of every
     *    following segment.
     *
     * LIMITATION: the closing delimiter is found by a plain non-greedy match, so
     * a literal `%}` inside the body ends the region early -- spell it `'%' . '}'`
     * when that exact sequence is needed.  (The block form has the same rule
     * about a literal `endphp %}`.)
     *
     * @param string $source Merged template source (mutated in place).
     */
    private function extractPhpBlocks(string &$source): void
    {
        if (!\str_contains($source, '{%')) {
            return;
        }

        // Block form first: its opener is indistinguishable from an empty
        // standalone tag, so consuming it here keeps the two passes from ever
        // competing for the same text.
        $source = (string) \preg_replace_callback(
            '/(\{%-?\s*php\s*-?%\})(.*?)(\{%-?\s*endphp\s*-?%\})/is',
            fn(array $m): string =>
                $this->storePhpBlock($m[1], $m[2], \substr_count($m[0], "\n")),
            $source
        );

        // Standalone form.  The body must begin with a non-whitespace character,
        // which is exactly what keeps an empty `{% php %}` opener out.
        $source = (string) \preg_replace_callback(
            '/(\{%-?\s*php\s+)(.+?)(-?%\})/is',
            function (array $m): string {
                if (\trim($m[2]) === '') {
                    return $m[0];
                }
                return $this->storePhpBlock($m[1], $m[2], \substr_count($m[0], "\n"));
            },
            $source
        );
    }

    /**
     * Register one raw-PHP body and return the sentinel tag that replaces it.
     *
     * @param string $openTag     The region's opening tag, verbatim.
     * @param string $bodyRaw     Body exactly as written between the delimiters.
     * @param int    $regionLines Line breaks the whole region spans; the sentinel
     *                            carries them so following segments stay aligned.
     */
    private function storePhpBlock(string $openTag, string $bodyRaw, int $regionLines): string
    {
        if ($this->sandboxMode) {
            throw new ClarityException(
                "'{% php %}' is not allowed in sandbox mode. "
                    . "Call setSandboxMode(false) to allow raw PHP."
            );
        }

        // Template line of the body's FIRST line, relative to the region's line:
        // the breaks the opening tag itself spans, plus one when the layout put
        // the body on the following line.
        $offset = \substr_count($openTag, "\n");

        $body = $bodyRaw;
        if (\preg_match('/^\r?\n/', $body) === 1) {
            // ONE leading break is layout, not code: strip it, but count it, so
            // the mapping stays exact and the body's own indentation survives.
            $body = (string) \preg_replace('/^\r?\n/', '', $body, 1);
            $offset++;
        }
        $body = \rtrim($body);

        // Give a complete statement its terminator, but never a fragment that
        // ends INSIDE a control structure: `if ($x) :`, `else:`, `{` and `}` are
        // decided by the author's last character (`endif` still needs its `;`).
        // Appending after `:` or `{` would be legal but would also mangle the
        // text a reader sees in the compiled class.
        if ($body !== '' && !\str_contains(';}{:', $body[-1])) {
            $body .= ';';
        }

        $token = '@@CLARITY_PHP_' . (++$this->phpBlockSeq) . '@@';
        $this->phpBlockBodies[$token] = ['body' => $body, 'offset' => $offset];

        // Keep it a BLOCK tag so the tokenizer routes it to the block arm.  The
        // region's newlines go INSIDE the tag (not after it): that preserves
        // every following segment's original line number for the source map
        // without turning the padding into output text.
        return '{%' . $token . \str_repeat("\n", $regionLines) . '%}';
    }

    /**
     * Inline a macro call into the current output.
     * Params become PHP locals ($__c_m_paramName) scoped to the macro body.
     */
    private function compileMacroCall(
        string $name,
        string $argsRaw,
        string $sourcePath,
        int $tplLine,
        array &$lines
    ): void {
        if (!isset($this->macros[$name])) {
            throw new ClarityException("Call to undefined macro '@{$name}'", $sourcePath, $tplLine);
        }

        // Cycle detection: if this macro is already on the expansion stack, we have a cycle.
        if (\in_array($name, $this->macroExpansionStack, true)) {
            $cycle = [...$this->macroExpansionStack, $name];
            throw new ClarityException(
                'Macro cycle detected: @' . \implode(' → @', $cycle),
                $sourcePath,
                $tplLine
            );
        }

        $macro  = $this->macros[$name];
        $params = $macro['params'];
        $args   = $argsRaw !== '' ? $this->splitArgList($argsRaw) : [];

        if (\count($args) !== \count($params)) {
            throw new ClarityException(
                "Macro '@{$name}' expects " . \count($params) . " argument(s), got " . \count($args),
                $sourcePath,
                $tplLine
            );
        }

        // Assign each argument to a unique PHP local; save compile-scope for restore.
        $restore = [];
        foreach ($params as $idx => $param) {
            $phpVar  = '$__c_m_' . $param;
            $phpExpr = $this->tokenizer->processCondition(\trim($args[$idx]));
            $this->addPhpLines($lines, $phpVar . ' = ' . $phpExpr . ';', $tplLine, $sourcePath);
            $restore[$param] = $this->localVars[$param] ?? null;
            $this->localVars[$param] = $phpVar;
        }
        $this->tokenizer->setLocalVars($this->localVars);

        // Push to expansion stack, compile the macro body inline, then pop.
        $this->macroExpansionStack[] = $name;
        try {
            $this->compileSourceInto($macro['body'], $sourcePath . '#macro@' . $name, $lines);
        } finally {
            \array_pop($this->macroExpansionStack);
        }

        // Restore compile-scope.
        foreach ($restore as $param => $old) {
            if ($old === null) {
                unset($this->localVars[$param]);
            } else {
                $this->localVars[$param] = $old;
            }
        }
        $this->tokenizer->setLocalVars($this->localVars);
    }

    /**
     * Split a comma-separated argument list, respecting nested parentheses and quoted strings.
     *
     * @return list<string>
     */
    private function splitArgList(string $input): array
    {
        $parts    = [];
        $depth    = 0;
        $start    = 0;
        $len      = \strlen($input);
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];
            if (($inSingle || $inDouble) && $ch === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }
            if (!$inSingle && !$inDouble) {
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $parts[] = \substr($input, $start, $i - $start);
                    $start = $i + 1;
                }
            }
        }
        $parts[] = \substr($input, $start);
        return $parts;
    }
}
