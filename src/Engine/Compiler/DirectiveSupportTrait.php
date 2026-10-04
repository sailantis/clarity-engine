<?php

namespace Clarity\Engine\Compiler;

use Clarity\ClarityException;
use Clarity\Engine\Tokenizer;

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
     * This is the extension point a custom directive uses to bind a variable it
     * emits itself — so the name is a PHP LOCAL that nothing writes back into the
     * scope array, exactly like a loop variable. It is therefore also recorded as
     * a dynamic binding, which is what lets a `vars()` snapshot inside the
     * directive's scope include it. A directive that binds a name it also stores
     * in `$__c_va` will simply see that entry win in the snapshot.
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

        $this->localVars[$name]       = '$' . $name;
        $this->dynamicBindings[$name] = true;
        $this->tokenizer->setLocalVars($this->localVars);
        $this->tokenizer->setDynamicBindings($this->dynamicBindings);
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
        unset($this->localVars[$name], $this->dynamicBindings[$name]);
        $this->tokenizer->setLocalVars($this->localVars);
        $this->tokenizer->setDynamicBindings($this->dynamicBindings);
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

    /**
     * Directive keywords a macro may not be named after.
     *
     * `{% call name() %}` puts the macro name in the keyword namespace, so a name
     * that is itself a tag keyword would make `{% call if(…) %}` ambiguous —
     * {@see BodyCompilerTrait::compileBlock()} dispatches on the first keyword and
     * never reaches `call`. The registry is checked for its own keywords
     * (`hasDirective()`) rather than mirrored here, so a module-added directive
     * cannot be shadowed — except by a built-in keyword, which this list is.
     *
     * `extends`, `block` and `endblock` are included even though they are consumed
     * before that dispatch: a macro body is spliced inside a layout's structure,
     * never able to provide one, so naming a macro after them reads as a promise
     * the engine cannot keep.
     */
    private const RESERVED_MACRO_NAMES = [
        'if', 'elseif', 'else', 'endif',
        'for', 'endfor',
        'set', 'extends', 'block', 'endblock',
        'include', 'php', 'call', 'parent',
    ];

    /**
     * A macro tag: the opening `{% macro NAME(params) %}` form, and the closing
     * `{% endmacro %}`.
     *
     * The opening form's name and parameter list are optional in the pattern so
     * that a bare `{% macro %}` is SEEN and refused, rather than skipped and left
     * to fail later as something else. The `@` is matched for the same reason:
     * `{% macro @name %}` is the removed spelling, and recognising it here is what
     * lets the scan refuse it with a message naming the replacement.
     *
     * The tag is matched by named group rather than by number, because whichever
     * alternative matched leaves the other's groups unset.
     */
    private const MACRO_TAG_RE = '/\{%-?\s*(?:(?P<end>endmacro)\s*-?%\}|macro(?:\s+(?P<at>@)?(?P<name>[a-zA-Z_][a-zA-Z0-9_]*)\s*\((?P<params>[^)]*)\))?\s*-?%\})/s';

    // -------------------------------------------------------------------------
    // Macros
    // -------------------------------------------------------------------------

    /**
     * Scan $source for `{% macro NAME(params) %}...{% endmacro %}` definitions,
     * store them in $this->macros, and strip the definitions from the source.
     *
     * The definitions are matched textually rather than lexed. A macro body is
     * spliced inline at each call site ({@see compileMacroCall()}), so it is never
     * compiled where it is written; holding the body aside as text and leaving
     * nothing behind is what makes the definition cost nothing at the point it is
     * declared.
     *
     * Removing the definition has to reproduce what the tokenizer would have done
     * with it, because the tokenizer never sees it; the trim rules are on
     * {@see scanMacros()}.
     *
     * @param string $source       Merged template source (mutated in place).
     * @param string $templateName Logical name, for the location of a bad definition.
     */
    private function extractMacros(string &$source, string $templateName = ''): void
    {
        if (!\str_contains($source, 'macro')) {
            return;
        }

        $body = '';
        $after = 0;
        // Whitespace control carried from the last removed definition's closing
        // tag to the text that follows it; one of the two is always pending once
        // a definition has been removed.
        $trimTail = false;
        $this->scanMacros($source, 0, $templateName, $body, $after, $trimTail);

        if ($after === 0) {
            return;
        }

        $tail = \substr($source, $after);
        $source = $body . ($trimTail
            ? \ltrim($tail, " \t\r\n")
            : self::stripOneLineBreakAfterTag($tail));
    }

    /**
     * Walk $source from $from for macro definitions and splice them out of it.
     *
     * The scan pairs `macro` against `endmacro` rather than taking the next
     * `{% endmacro %}` it sees. Pairing is what makes a definition missing its own
     * `{% endmacro %}` report THAT, instead of silently swallowing the next
     * definition's closing tag and blaming the file for a stray one.
     *
     * A definition inside another definition's body is refused. It would read as a
     * private helper, but the scan is textual and flat, so the name would be
     * registered exactly like a top-level one and callable from anywhere — the
     * nesting would promise a scope that does not exist.
     *
     * A definition at this level is removed from the text: its body is recorded as
     * a macro, and nothing is left where it stood. The text before it is appended
     * to $body with the trims that apply to it — a definition is an ordinary
     * `{% %}` tag, so it eats nothing before it, and only the `-%}` that closes
     * `{% endmacro %}` reaches forward. The one exception is the closing tag's own
     * `{%`, a tag boundary, so a definition whose `{% endmacro %}` is the last
     * thing in the source still eats the break after it.
     *
     * The body's leading line break is kept: it belongs to the body, and the body
     * is spliced in at the call site, so it lands between the call's own two tags
     * rather than at the end of the output.
     *
     * @param int    $from     Offset to start scanning from.
     * @param string $body     The text this level keeps, with definitions removed (appended to).
     * @param int    $after    Offset just past the last definition removed.
     * @param bool   $trimTail Out: whether the last removed definition's closing
     *                         tag reaches forward into the text after it.
     */
    private function scanMacros(
        string $source,
        int $from,
        string $templateName,
        string &$body,
        int &$after,
        bool &$trimTail
    ): void {
        $regex = self::MACRO_TAG_RE;
        $pos   = $from;

        /** @var null|array{start:int,end:int,name:string,params:list<string>} */
        $open = null;

        while (\preg_match($regex, $source, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $tagStart = $m[0][1];
            $tagEnd   = $tagStart + \strlen($m[0][0]);

            if (($m['end'][0] ?? '') !== '') {
                if ($open === null) {
                    throw new ClarityException(
                        "'{% endmacro %}' without a matching '{% macro %}'.",
                        $templateName,
                        $this->sourceLineAtOffset($source, $tagStart)
                    );
                }

                $this->registerMacro(
                    $open['name'],
                    $open['params'],
                    \substr($source, $open['end'], $tagStart - $open['end']),
                    $templateName,
                    $source,
                    $open['start']
                );

                $open     = null;
                $pos      = $tagEnd;
                $after    = $tagEnd;
                $trimTail = \str_ends_with(\rtrim($m[0][0]), '-%}');
                continue;
            }

            if (($m['at'][0] ?? '') === '@') {
                throw new ClarityException(
                    "Macro definitions are spelled '{% macro name(params) %}': drop the '@'.",
                    $templateName,
                    $this->sourceLineAtOffset($source, $tagStart)
                );
            }

            if (($m['name'][0] ?? '') === '') {
                throw new ClarityException(
                    "Empty '{% macro %}' tag: it needs a name, as in '{% macro card(title) %}'.",
                    $templateName,
                    $this->sourceLineAtOffset($source, $tagStart)
                );
            }

            if ($open !== null) {
                throw new ClarityException(
                    "Nested macro definitions are not supported: define '{$m['name'][0]}' at the "
                        . 'top level, or in an included macro library.',
                    $templateName,
                    $this->sourceLineAtOffset($source, $tagStart)
                );
            }

            $chunk = \substr($source, $pos, $tagStart - $pos);
            if ($tagStart + 2 <= \strlen($source) && $source[$tagStart + 2] === '-') {
                $chunk = \rtrim($chunk, " \t\r\n");
            }
            $body .= $trimTail ? \ltrim($chunk, " \t\r\n") : $chunk;

            $open = [
                'start'  => $tagStart,
                'end'    => $tagEnd,
                'name'   => $m['name'][0],
                'params' => \trim($m['params'][0]) !== '' ? \array_map('trim', \explode(',', $m['params'][0])) : [],
            ];
            $pos = $tagEnd;
        }

        if ($open !== null) {
            throw new ClarityException(
                "Unclosed '{% macro %}' tag: add '{% endmacro %}'.",
                $templateName,
                $this->sourceLineAtOffset($source, $open['start'])
            );
        }
    }

    /**
     * Validate one `{% macro %}` definition and record it.
     *
     * @param list<string> $params
     */
    private function registerMacro(
        string $name,
        array $params,
        string $body,
        string $templateName,
        string $source,
        int $tagStart
    ): void {
        foreach ($params as $param) {
            $this->validateLocalName($param, 'macro parameter', $templateName, $source, $tagStart);
        }
        if (\in_array($name, self::RESERVED_MACRO_NAMES, true)) {
            throw new ClarityException(
                "Macro name '{$name}' is a directive keyword. Choose another name.",
                $templateName,
                $this->sourceLineAtOffset($source, $tagStart)
            );
        }

        $this->macros[$name] = ['params' => $params, 'body' => $body];
    }

    /**
     * Reject a name a template binds as a PHP LOCAL of its own, where the name
     * is one the render frame already uses for something else.
     *
     * A macro parameter is the one binding site that spells a plain PHP local
     * directly (`$p = …`) instead of going through {@see assertBindableName()},
     * so the reserved-prefix rule has to be repeated here.  Without it a macro
     * parameter could claim `__c_fn`, and in sandbox mode — where a filter use
     * forces `$__c_fn = $this->__c_fn;` to be unpacked — the body could then read
     * the callable registry through it.
     *
     * @param int $offsetIn Where to measure the reported line from, when the
     *                      binding tag is not the text `$name` appears in.
     */
    private function validateLocalName(
        string $name,
        string $what,
        string $templateName,
        string $source,
        int $offsetIn = -1
    ): void {
        // The reservation is collision avoidance and, for `__c_fn`/`__c_sv`,
        // reachability: those two are the registries a sandboxed template must
        // never name.  `assertBindableName()` enforces the same rule for every
        // other binder, so the wording is shared rather than duplicated.
        if (\str_starts_with($name, self::INTERNAL_PREFIX)) {
            throw new ClarityException(
                "Invalid {$what} name '{$name}': names starting with '"
                    . self::INTERNAL_PREFIX . "' are reserved for internal use.",
                $templateName,
                $offsetIn < 0 ? 0 : $this->sourceLineAtOffset($source, $offsetIn)
            );
        }

        if (!\in_array($name, self::RESERVED_NAMES, true)) {
            return;
        }

        throw new ClarityException(
            "Invalid {$what} name '{$name}': PHP cannot bind it.",
            $templateName,
            $offsetIn < 0 ? 0 : $this->sourceLineAtOffset($source, $offsetIn)
        );
    }

    /**
     * Extract `{% php <code> %}` regions from the source, replacing each with a
     * line-preserving sentinel tag that {@see compileSourceInto()} later turns
     * back into raw PHP.
     *
     * One spelling is accepted:
     *
     *   {% php <code> %}
     *
     * It carries one statement -- or one fragment of a control structure -- per
     * tag, which is what lets PHP structure wrap template markup without a single
     * tag spanning it:
     *
     *   {% php if ($items) : %}
     *   ... markup ...
     *   {% php endif %}
     *
     * The body may also span lines: the `.` below is `/s`, so
     *
     *   {% php
     *   $a = 1;
     *   echo $a;
     *   %}
     *
     * is a multi-statement tag.  It is an OPEN-MODE feature and is rejected while
     * the sandbox is enabled.
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
     * when that exact sequence is needed.
     *
     * @param string $source Merged template source (mutated in place).
     */
    private function extractPhpBlocks(string &$source): void
    {
        if (!\str_contains($source, '{%')) {
            return;
        }

        // Deny BEFORE the scan, not while storing a body: the scan replaces each
        // tag with a line-only sentinel, so a check inside it can no longer see
        // where the tag was.  Asking here keeps the tag's own line, and makes
        // this the single gate -- storePhpBlock() is unreachable while the
        // rule is denied.
        //
        // The opener is matched exactly as the extraction below spells it, so the
        // refusal covers precisely the tags extraction would have stored: an
        // empty `{% php %}` is not a raw-PHP region and keeps its own message
        // (raised by the code generator, which still sees the tag).
        if (!$this->policy->allows('rawPhp')
            && \preg_match('/\{%-?\s*php\s+(?!-?%\})/i', $source, $m, \PREG_OFFSET_CAPTURE)
        ) {
            [$file, $line] = $this->resolveOffsetLocation($source, $m[0][1]);
            throw new ClarityException(
                "'{% php %}' is not allowed by this policy. "
                    . "Grant the 'rawPhp' rule to allow it.",
                $file,
                $line,
                $this->templatePath($file)
            );
        }

        // The body must begin with a non-whitespace character, which is exactly
        // what keeps an empty `{% php %}` opener out.  The lookahead rejects it
        // before the body is scanned: without it, the non-greedy `(.+?)` would
        // treat the opener's own `%}` as the start of a body and swallow the rest
        // of the template as one (invalid) PHP statement.  It deliberately rejects
        // only `%}` / `-%}`, so a lone `}` -- the closing brace of a brace-style
        // block, `{% php } %}` -- is still a legal body.
        $source = (string) \preg_replace_callback(
            '/(\{%-?\s*php\s+(?!-?%\}))(.+?)(-?%\})/is',
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
     * Locate a compile construct from its byte offset in the source currently
     * being compiled, as a [file, line] pair for a {@see ClarityException}.
     *
     * The compiler's normal mapping (the `$mappedSourcePath` cursor) is driven by
     * the SEGMENT scan: it advances as segments are compiled, so it cannot answer
     * for a pre-scan construct, and it cannot see that an inlined include started
     * mid-file.  Both are answered here instead:
     *
     *  - `{# @source … #}` markers, which inheritance and includes already emit,
     *    are the file identity for every region of the merged source, so the
     *    innermost marker before the offset names the file;
     *  - inlining an include copies its markers into the host's source, so the
     *    line is resolved relative to that marker and is correct for both an
     *    included file and a plain one.
     *
     * @param string $source Merged source being compiled.
     * @param int    $offset Byte offset of the construct inside $source.
     * @return array{0:string, 1:int} Logical template name and 1-based line.
     */
    private function resolveOffsetLocation(string $source, int $offset): array
    {
        $sourceName = $this->compileStack === [] ? '' : \end($this->compileStack);

        // A macro body is compiled as its own unit and carries no `{# @source #}`
        // marker of its own, so the markers (or the unit name) would otherwise be
        // returned with the internal `#macro#` suffix — the same reason
        // {@see resolveCurrentLocation()} strips it.
        $macroAt = \strpos($sourceName, '#macro#');
        if ($macroAt !== false) {
            return [\substr($sourceName, 0, $macroAt), $this->sourceLineAtOffset($source, $offset)];
        }

        // No `resolveSegmentSource()` here: that cursor tracks the SEGMENT scan of
        // the source being inlined INTO.  An include's own pre-scan happens while
        // that cursor still names the host, so applying it would report the host
        // file for an error in the included one.  The markers are already the
        // authoritative unit identity.
        return $this->resolveSourceOriginAtOffset($source, $sourceName, $offset);
    }

    /**
     * The physical path the active loader reported for a logical template name,
     * or '' when it reported none.
     *
     * Paths travel with the source ({@see TemplateSource::$path}) and are recorded
     * by {@see readWithDep()}, so this is a lookup rather than a re-derivation —
     * the compiler has no loader of its own and must not grow one.
     *
     * @param string $templateName Logical name, or a `<name>#macro#<macro>` unit.
     */
    private function templatePath(string $templateName): string
    {
        return $this->resolvedPaths[self::baseTemplateName($templateName)] ?? '';
    }

    /**
     * Strip the internal `#macro#…` suffix a macro body carries as its unit name.
     */
    private static function baseTemplateName(string $unitName): string
    {
        $macroAt = \strpos($unitName, '#macro#');

        return $macroAt === false ? $unitName : \substr($unitName, 0, $macroAt);
    }

    /**
     * Register one raw-PHP body and return the sentinel tag that replaces it.
     *
     * The `rawPhp` rule was already checked by {@see extractPhpBlocks()},
     * which is the only caller: it has to refuse before the sentinel exists, or
     * the offending tag has no position left to report.
     *
     * @param string $openTag     The tag's opening portion, verbatim.
     * @param string $bodyRaw     Body exactly as written, before the closing `%}`.
     * @param int    $regionLines Line breaks the whole tag spans; the sentinel
     *                            carries them so following segments stay aligned.
     */
    private function storePhpBlock(string $openTag, string $bodyRaw, int $regionLines): string
    {
        // Template line of the body's FIRST line, relative to the tag's line: the
        // breaks the opening portion itself spans.  The body never starts with a
        // break -- the `\s+` before it consumes them -- so the body's own
        // indentation survives as written.
        $offset = \substr_count($openTag, "\n");
        $body   = \rtrim($bodyRaw);

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
     * Compile `{% call name(args) %}`: the tag form of a macro call.
     *
     * `call` is dispatched here rather than through the registry, so a call is
     * never routed to a registered directive of the same name. The parse is
     * deliberately loose about the closing parenthesis — {@see splitArgList()}
     * understands nesting and quotes, so a trailing `)` is the only thing that
     * can actually be wrong, and the hint handles that case.
     */
    private function compileMacroCallTag(
        string $rest,
        string $sourcePath,
        int $tplLine,
        array &$lines
    ): string {
        if (!\preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)\s*$/s', \trim($rest), $m)) {
            throw new ClarityException(
                "Invalid macro call syntax: '{$rest}'. Write {% call name(args) %}.",
                $sourcePath,
                $tplLine,
                $this->templatePath($sourcePath)
            );
        }

        $this->compileMacroCall($m[1], $m[2], $sourcePath, $tplLine, $lines);

        return '';
    }

    /**
     * Explain a bare `{% name(args) %}` that names a macro, suggesting `call`.
     *
     * Without this the tag dies as "Unknown directive 'card'", which sends the
     * author looking for a directive that was never meant to exist. Returns null
     * when $keyword names no macro, so the caller keeps the ordinary error.
     */
    /**
     * Explain a bare `{% name(args) %}` that names a macro, suggesting `call`.
     *
     * Without this the tag dies as "Unknown directive 'card'", which sends the
     * author looking for a directive that was never meant to exist. Returns null
     * when the tag names no macro, so the caller keeps the ordinary error.
     *
     * The name is read from the raw tag, not from the lowercased keyword: a macro
     * name is an identifier, so `Card` and `card` are different macros.
     *
     * A tag whose name is glued to its parenthesis (`{% card(x) %}`) has no
     * separating space, so `$content` is used rather than the keyword the caller
     * split off — which for such a tag is the whole call.
     */
    private function macroCallSyntaxHint(string $content): ?string
    {
        if (!\preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $m) || !isset($this->macros[$m[1]])) {
            return null;
        }

        return "Unknown directive '{$m[1]}'. Did you mean {% call {$content} %}?";
    }

    /**
     * Explain a `{% macro … %}` tag that reached directive dispatch — either a
     * definition with no `{% endmacro %}`, or the removed `@`-prefixed spelling.
     * The pre-scan handles the well-formed case, so neither shape can be parsed
     * here; this only has to say which mistake it was.
     */
    /** A macro definition that reached directive dispatch: unclosed, or legacy. */
    private function macroDefinitionError(string $content): string
    {
        return \str_contains($content, '@')
            ? "Macro definitions are spelled '{% macro name(params) %}': drop the '@'."
            : "Unclosed '{% macro %}' tag: add '{% endmacro %}'.";
    }

    /**
     * Inline a macro call into the current output. Params become
     * PHP locals ($__c_m_paramName) scoped to the macro body.
     */
    private function compileMacroCall(
        string $name,
        string $argsRaw,
        string $sourcePath,
        int $tplLine,
        array &$lines
    ): void {
        if (!isset($this->macros[$name])) {
            throw new ClarityException("Call to undefined macro '{$name}'", $sourcePath, $tplLine);
        }

        // Cycle detection: if this macro is already on the expansion stack, we have a cycle.
        if (\in_array($name, $this->macroExpansionStack, true)) {
            $cycle = [...$this->macroExpansionStack, $name];
            throw new ClarityException(
                'Macro cycle detected: ' . \implode(' → ', $cycle),
                $sourcePath,
                $tplLine
            );
        }

        $macro  = $this->macros[$name];
        $params = $macro['params'];
        $args   = $argsRaw !== '' ? $this->splitArgList($argsRaw) : [];

        if (\count($args) !== \count($params)) {
            throw new ClarityException(
                "Macro '{$name}' expects " . \count($params) . " argument(s), got " . \count($args),
                $sourcePath,
                $tplLine
            );
        }

        // Assign each argument to a unique PHP local; save compile-scope for restore.
        $restore = [];
        foreach ($params as $idx => $param) {
            $phpVar  = '$__c_m_' . $param;
            $phpExpr = $this->withLocation(
                fn() => $this->tokenizer->processCondition(\trim($args[$idx])),
                $sourcePath,
                $tplLine
            );
            $this->addPhpLines($lines, $phpVar . ' = ' . $phpExpr . ';', $tplLine, $sourcePath);
            $restore[$param] = [
                'var' => $this->localVars[$param] ?? null,
                'dyn' => isset($this->dynamicBindings[$param]),
            ];
            $this->localVars[$param]        = $phpVar;
            $this->dynamicBindings[$param] = true;
        }
        $this->tokenizer->setLocalVars($this->localVars);
        $this->tokenizer->setDynamicBindings($this->dynamicBindings);

        // Push to expansion stack, compile the macro body inline, then pop.
        $this->macroExpansionStack[] = $name;
        try {
            $this->compileSourceInto($macro['body'], $sourcePath . '#macro#' . $name, $lines);
        } finally {
            \array_pop($this->macroExpansionStack);
        }

        // Restore compile-scope.
        foreach ($restore as $param => $old) {
            if ($old['var'] === null) {
                unset($this->localVars[$param]);
            } else {
                $this->localVars[$param] = $old['var'];
            }

            if ($old['dyn']) {
                $this->dynamicBindings[$param] = true;
            } else {
                unset($this->dynamicBindings[$param]);
            }
        }
        $this->tokenizer->setLocalVars($this->localVars);
        $this->tokenizer->setDynamicBindings($this->dynamicBindings);
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
