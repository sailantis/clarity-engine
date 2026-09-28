<?php
namespace Clarity\Engine;

use Clarity\ClarityException;

/**
 * Splits a Clarity template source into typed segments and processes
 * DSL expressions into PHP-ready strings.
 *
 * Segment types (constants on this class)
 * ----------------------------------------
 * TEXT        â€“ raw HTML/text passed through verbatim
 * OUTPUT_TAG  â€“ {{ expression }} â€“ rendered (auto-escaped by default)
 * BLOCK_TAG   â€“ {% directive %}  â€“ control structures / directives
 *
 * Expression processing
 * ---------------------
 * The tokenizer converts Clarity expression syntax to valid PHP so the
 * Compiler can embed it directly.  PHP itself validates the resulting
 * syntax when the compiled class file is first loaded, so we intentionally
 * do not perform a full grammar check here.
 *
 * Conversions performed
 * â€¢ var-chains (foo.bar[x].baz) â†’ $__c_va['foo']['bar'][$__c_va['x']]['baz']
 * â€¢ logical operators:  and â†’ &&,  or â†’ ||,  not â†’ !
 * â€¢ bitwise operators:  bor â†’ |,  band â†’ &,  bxor â†’ ^,  bnot â†’ ~,  blsh â†’ <<,  brsh â†’ >>
 * â€¢ concat operator:    ~   â†’ .
 * â€¢ all other tokens pass through unchanged (PHP validates them)
 *
 * Pipeline (| or |>)
 * â€¢ Both | and |> act as the filter pipe operator (| is normalized to |> before processing)
 * â€¢ Each step after the pipe is a filter: name  or  name(arg1, arg2)
 * â€¢ Arguments are themselves processed as expressions
 * â€¢ Result: nested $__c_fn['name']($__c_fn['name']($expr, arg), â€¦)
 *
 * Named arguments
 * â€¢ Clarity uses `=` syntax: filter(precision=2) or fn(from="system")
 * â€¢ These are emitted directly as PHP named arguments: `precision: 2`, `from: 'system'`
 * â€¢ PHP itself validates parameter names and arity at runtime â€” no reflection needed
 */
class Tokenizer
{
    public const TEXT = 1;
    public const OUTPUT = 2;
    public const BLOCK = 3;
    public const COMMENT = 4;

    public const KEY_TYPE = 0;
    public const KEY_CONTENT = 1;
    public const KEY_LINE = 2;

    private bool $autoEscape = true;

    /** Output-escaping context: 'html' | 'js' | 'css' */
    private string $escapeContext = 'html';

    private ?Registry $registry = null;

    /**
     * A BARE root dereference: `$__c_va['name']`. These are special because
     * isset() on them reports an ABSENT ROOT as false with no warning, which is
     * exactly the tolerance `?` promises.
     *
     * Deliberately excludes anything with `->` or a second key: isset() would
     * suppress a missing PROPERTY or an intermediate missing KEY too, turning a
     * mistyped strict segment into a silent null.
     */
    private const BARE_ROOT_RE = '/^\$__c_va\[\'[A-Za-z_][A-Za-z0-9_]*\'\]$/';

    /**
     * A lambda PARAMETER as it appears in a compiled body: `$name`.
     *
     * Kept separate from {@see BARE_ROOT_RE} because the two mean opposite
     * things — a bare root is a scope read that may be absent, whereas a
     * parameter is a real local that is always bound.
     */
    private const BARE_PARAM_RE = '/^\$[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Monotonic counter for temporaries emitted by optional array guards, so two
     * guards in one expression never collide. Per-instance, compile-time only.
     */
    private int $guardCounter = 0;
    private array $varChainCache = [];

    /**
     * Compile-time local variable context: templateVarName â†’ PHP variable string.
     * Set by the Compiler when entering/leaving loop scopes so that expressions
     * inside loops resolve loop variables to direct PHP local variables instead
     * of $__c_va['name'] lookups.
     *
     * @var array<string, string>
     */
    private array $localVars = [];
    /**
     * PHP's variable-name grammar, byte-wise:
     *
     *     ^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$
     *
     * This is the grammar from the PHP manual ("the bytes from 128 through
     * 255"), which is exactly what the PHP lexer accepts, so a name this
     * class accepts always compiles to a real PHP variable.  The high range is
     * what makes `$Ã¶Ã¤` / `$tÃ¤yte` work in UTF-8: every byte of a multi-byte
     * sequence falls inside it.
     *
     * Deliberate divergence from Twig: Twig's lexer uses `\x7f-\xff`, i.e. it
     * also accepts DEL (0x7F), which PHP's own documented range excludes.  A
     * DEL in a template identifier is never intentional, so this follows PHP.
     *
     * Byte comparison, not `/u`: PHP compares variable names as BYTES and never
     * validates their encoding, so an invalid-UTF-8 name is a legal variable
     * that a `\p{L}`-style class would wrongly reject.
     */
    private const IDENT_RE = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/';

    /**
     * Filters whose first argument must be a lambda expression or a filter
     * reference (quoted string). Plain variable references are rejected to
     * prevent callable injection from template variables.
     */
    private const CALLABLE_ARG_FILTERS = ['map' => true, 'filter' => true, 'reduce' => true];

    /**
     * Function names that are compiled to the literal '' (eliminated at compile
     * time).  Used to prune dump() in production mode with zero runtime cost.
     *
     * @var array<string, true>
     */
    private array $prunedFunctions = [];

    /**
     * Function names that receive the current escape context ('html'|'js'|'css')
     * as an extra first string argument in the emitted PHP call.
     *
     * @var array<string, true>
     */
    private array $contextInjectedFunctions = [];

    /**
     * When true (default) templates are sandboxed: PHP function calls and
     * method calls are rejected unless the callee is registered.  When false
     * ("open mode") templates may call arbitrary PHP functions and methods,
     * for parity with Blade / Stempler / Plates.
     *
     * Set by the Compiler from the engine's `sandbox` configuration.
     */
    private bool $sandboxMode = true;

    /**
     * Open mode only: the render scope is seeded into PHP LOCALS (the Compiler
     * emits `extract($__c_va, EXTR_SKIP)` at the top of render()), so a chain root
     * is emitted as a plain local variable instead of a `$__c_va` lookup.
     *
     * This is what lets one name work in both worlds â€” `{{ title }}` and
     * `{% php echo $title; %}` are then the same variable, not two.
     *
     * No guard expression is emitted with the read: an unknown name raises PHP's
     * own "Undefined variable" warning, which the engine's error handler maps to
     * a ClarityException with the template line.  That keeps strict access
     * identical to sandbox mode.
     */
    private bool $localRoots = false;

    /**
     * Stack of lambda PARAMETER frames, innermost LAST.
     *
     * A lambda is emitted as a `static function (...) use (...) { … }`, so the
     * render scope's locals are not in scope inside it: a root must keep reading
     * `$__c_va`. A lambda PARAMETER, however, IS a real local — and because
     * lambdas NEST (`map(rows, r => map(r.vals, v => v ~ r.name))`), a parameter
     * of an enclosing lambda stays visible to the body being compiled.
     *
     * So a root name matching a parameter of ANY enclosing frame is emitted as
     * the bare `$name`: the closure that declares it is the enclosing one, and
     * PHP binds it lexically. An empty stack means "not inside a lambda", which
     * is also what the former `inLambda` boolean expressed.
     *
     * @var list<array<string, true>>
     */
    private array $lambdaFrames = [];

    /**
     * Function names that stay blocked in open mode.  Empty by default (open
     * mode is full PHP access); an application may add its own guardrails via
     * the engine's setDeniedFunctions().  Keys are lowercase names.
     *
     * @var array<string, true>
     */
    private array $deniedFunctions = Registry::DEFAULT_DENIED_FUNCTIONS;

    /**
     * True while compiling the argument list of a filter routed to a PHP
     * function in open mode, so `_` resolves to the piped value instead of the
     * template variable named `_`.  Scoped with try/finally around the compile
     * of one filter segment.
     */
    private bool $inOpenFilterArgs = false;

    /**
     * The piped value that `_` resolves to while $inOpenFilterArgs is true.
     * Saved and restored around nested open-filter argument compilation so the
     * nearest enclosing filter wins.
     */
    private string $openFilterValue = '';

    /** @param array<string, true> $names */
    public function setPrunedFunctions(array $names): void
    {
        $this->prunedFunctions = $names;
    }

    /** @param array<string, true> $names */
    public function setContextInjectedFunctions(array $names): void
    {
        $this->contextInjectedFunctions = $names;
    }

    /**
     * Enable or disable sandbox mode.  `true` (default) rejects arbitrary PHP
     * function and method calls; `false` allows them (see class docs).
     */
    public function setSandboxMode(bool $sandboxed): void
    {
        $this->sandboxMode = $sandboxed;
    }

    /**
     * Open mode only: emit chain roots as PHP locals (see {@see $localRoots}).
     *
     * The Compiler enables this together with the `extract()` seeding, so a
     * compiler that seeds no locals never emits a local read.
     */
    public function setLocalRoots(bool $enabled): void
    {
        $this->localRoots    = $enabled;
        $this->varChainCache = [];
    }

    public function isSandboxed(): bool
    {
        return $this->sandboxMode;
    }

    /**
     * Replace the open-mode function guardrails.  Keys are lowercase function
     * names; empty (the default) allows every PHP function.
     *
     * @param array<string, true> $names
     */
    public function setDeniedFunctions(array $names): void
    {
        $this->deniedFunctions = $names;
    }

    /**
     * Whether a PHP function may be called in open mode.
     *
     * PHP function names are case-insensitive and may be written with a leading
     * namespace separator, so both are normalised before the deny-list lookup.
     */
    private function isFunctionCallAllowed(string $name): bool
    {
        return !isset($this->deniedFunctions[\strtolower(\ltrim($name, '\\'))]);
    }

    public function setRegistry(Registry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Update the compile-time local variable context.
     *
     * Called by the Compiler when entering or exiting a loop scope so that
     * variable resolution inside the loop uses direct PHP local variables
     * (e.g. `$item`) rather than $__c_va['item'] array lookups.
     *
     * @param array<string, string> $localVars  templateVarName â†’ PHP variable string
     */
    public function setLocalVars(array $localVars): void
    {
        $this->localVars = $localVars;
        // Invalidate the cache: cached chain strings may reference identifiers
        // whose resolution changes when the local-var context changes.
        $this->varChainCache = [];
    }

    // -------------------------------------------------------------------------
    // Segment splitting
    // -------------------------------------------------------------------------

    /**
     * Split a raw template source into an ordered array of segments.
     *
     * Tag boundaries are located by a quote-aware, brace-depth-aware scanner
     * rather than a single flat regex. A closing delimiter may legitimately
     * appear inside a string literal (`{{ '}}' }}`) or next to a literal brace
     * (`{{ v }}}`, `{{ { a: 1 } }}`, `{{ user{k}}}`), none of which a naive
     * lazy match can handle.
     *
     * Each element is:  ['type' => TEXT|OUTPUT|BLOCK, 'content' => string, 'line' => int]
     *
     * @param string $source Raw template source.
     * @return array<int, array{int, string, int}>
     * @throws ClarityException When a tag is opened and never closed. A stray
     *                          delimiter is almost always an authoring bug, so
     *                          it is reported rather than emitted as text.
     */
    public function tokenize(string $source): array
    {
        // All opener candidates in ONE regex pass. Openers that turn out to sit
        // inside a previous tag's content (e.g. a literal `{{` in a string) are
        // skipped by the $pos guard below, so this stays correct while paying
        // for a single PCRE invocation rather than one per tag.
        if (!\preg_match_all('/\{\{|\{%|\{#/', $source, $matches, \PREG_OFFSET_CAPTURE)) {
            return [
                [
                    self::KEY_TYPE    => self::TEXT,
                    self::KEY_CONTENT => \trim($source),
                    self::KEY_LINE    => 1,
                ]
            ];
        }

        $segments     = [];
        $sourceLen    = \strlen($source);
        $line         = 1;
        $pos          = 0;
        $trimNextText = false;

        foreach ($matches[0] as [$opener, $tagPos]) {
            if ($tagPos < $pos) {
                continue; // opener inside the content of an already-consumed tag
            }

            switch ($opener) {
                case '{{':
                    $type = self::OUTPUT;
                    break;
                case '{%':
                    $type = self::BLOCK;
                    break;
                default:
                    $type = self::COMMENT;
                    break;
            }

            // Whitespace control: `{%-` (and `{{-`, `{#-`) suppress the
            // whitespace immediately BEFORE the tag; a `-` before the closer
            // suppresses the whitespace immediately AFTER it.
            $trimBefore = ($source[$tagPos + 2] ?? '') === '-';
            $beforePos  = $tagPos;

            if ($trimBefore) {
                // `{%-` consumes the whitespace up to $beforePos. Extend it
                // leftwards over spaces, tabs and newlines, but never over the
                // previous tag's closing delimiter — which is what keeps
                // `{% set a = 1 -%}{%- if x %}` from swallowing the assignment.
                $b = $beforePos;
                while ($b > $pos && \str_contains(" \t\r\n", $source[$b - 1])) {
                    $b--;
                }
                $beforePos = $b;
            }

            if ($beforePos > $pos) {
                $text = \substr($source, $pos, $beforePos - $pos);
                if ($trimNextText) {
                    $text = self::trimLeftWhitespace($text);
                }
                if (self::hasVisibleText($text)) {
                    $segments[] = [
                        self::KEY_TYPE    => self::TEXT,
                        self::KEY_CONTENT => $text,
                        self::KEY_LINE    => $line,
                    ];
                }
                $line += \substr_count($text, "\n");
            }

            $innerStart = $tagPos + 2;
            if ($trimBefore) {
                $innerStart++;
            }

            if ($type === self::COMMENT) {
                $close = \strpos($source, '#}', $innerStart);
                if ($close === false) {
                    throw new ClarityException(
                        'Unclosed comment tag opened on template line ' . $line
                            . ": no matching '#}' before the end of the template.",
                        '',
                        $line
                    );
                }
                $end = $close + 2;
            } else {
                $closer = $type === self::OUTPUT ? '}}' : '%}';
                $close  = self::findTagClose($source, $innerStart, $sourceLen, $closer);
                if ($close === null) {
                    throw new ClarityException(
                        'Unclosed ' . ($type === self::OUTPUT ? 'output' : 'block')
                            . ' tag opened on template line ' . $line
                            . ": no matching '{$closer}' before the end of the template.",
                        '',
                        $line
                    );
                }
                $end = $close + 2;
            }

            // A `-` glued to the closer suppresses following whitespace.
            $trimAfter    = ($source[$end - 3] ?? '') === '-';
            $trimNextText = $trimAfter;

            $contentEnd = $end - 2;
            if ($trimAfter) {
                $contentEnd--;
            }

            $segments[] = [
                self::KEY_TYPE    => $type,
                self::KEY_CONTENT => \trim(\substr($source, $innerStart, $contentEnd - $innerStart)),
                self::KEY_LINE    => $line,
            ];

            $line += \substr_count(\substr($source, $tagPos, $end - $tagPos), "\n");
            $pos = $end;
        }

        if ($pos < $sourceLen) {
            $rest = \substr($source, $pos);
            if ($trimNextText) {
                $rest = self::trimLeftWhitespace($rest);
            }
            if (self::hasVisibleText($rest)) {
                $segments[] = [
                    self::KEY_TYPE    => self::TEXT,
                    self::KEY_CONTENT => $rest,
                    self::KEY_LINE    => $line,
                ];
            }
        }

        return $segments;
    }

    /**
     * Strip leading whitespace (spaces, tabs, newlines) from a text segment.
     */
    private static function trimLeftWhitespace(string $text): string
    {
        return \ltrim($text, " \t\r\n");
    }

    /**
     * Whether a text segment carries anything other than whitespace.
     *
     * Equivalent to `\trim($segment) !== ''` but without materialising the
     * trimmed copy, which matters because generated pages run to hundreds of
     * kilobytes and a `trim()` of every text run shows up in the compile cost.
     */
    private static function hasVisibleText(string $segment): bool
    {
        return \strspn($segment, " \t\n\r\0\x0B") < \strlen($segment);
    }

    /**
     * Locate a tag's closing delimiter, ignoring delimiters that appear inside
     * a string literal and braces nested in a collection literal.
     *
     * @param string $source Full template source.
     * @param int    $from   Offset just past the two-character opener.
     * @param int    $len    Length of $source.
     * @param string $closer Two-character closer ('}}' or '%}').
     * @return int|null Offset of the closer's first character, or null when the
     *                  source ends before a balanced closer is found.
     */
    private static function findTagClose(string $source, int $from, int $len, string $closer): ?int
    {
        // Fast path: the overwhelming majority of tags contain no quote and no
        // brace, so the first closer found by strpos is already the answer. One
        // bulk scan from $from decides that without entering the loop below.
        $firstCloser = \strpos($source, $closer, $from);
        if ($firstCloser === false) {
            return null;
        }

        // Characters that can affect the scan. Everything else is skipped in
        // bulk: strcspn returns the length of the run that contains none of
        // them, so a long literal string costs one engine call, not one PHP
        // loop iteration per byte. The closer's own characters are folded in
        // (duplicates are harmless).
        $special = "\"'{" . $closer;
        $i       = $from + \strcspn($source, $special, $from, $firstCloser - $from);

        if ($i >= $firstCloser) {
            return $firstCloser;
        }

        $depth = 0;
        $c1    = $closer[0];
        $c2    = $closer[1];

        while ($i < $len) {
            $i += \strcspn($source, $special, $i, $len - $i);
            if ($i >= $len) {
                break;
            }

            $ch = $source[$i];

            if ($ch === "'" || $ch === '"') {
                $i = self::skipStringLiteral($source, $i, $len);
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                if ($depth > 0) {
                    $depth--;
                } elseif ($c1 === '}' && ($source[$i + 1] ?? '') === $c2) {
                    return $i;
                }
            } elseif ($depth === 0 && $ch === $c1 && ($source[$i + 1] ?? '') === $c2) {
                return $i;
            }

            $i++;
        }

        return null;
    }

    /**
     * Advance past a quoted string literal. $i must point at the opening quote.
     * Backslash escapes are honoured; an unterminated literal runs to the end.
     *
     * @return int Offset just past the closing quote, or $len when unterminated.
     */
    private static function skipStringLiteral(string $source, int $i, int $len): int
    {
        $quote = $source[$i];
        $i++;

        while ($i < $len) {
            $ch = $source[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === $quote) {
                return $i + 1;
            }
            $i++;
        }

        return $len;
    }

    // -------------------------------------------------------------------------
    // Expression processing
    // -------------------------------------------------------------------------

    /**
     * Convert a Clarity expression string to a PHP expression string.
     *
     * The pipeline (|>) is processed first; the leftmost segment is the
     * expression and each subsequent segment is a filter call.
     *
     * @param string $expression Raw expression from inside {{ ... }} or the
     *                           right-hand side of {% set var = ... %}.
     * @param bool   $autoEscape When true and there is no |> raw at the end,
     *                           wraps the whole result in htmlspecialchars().
     * @return string PHP expression (no leading <?= or trailing ?>).
     */
    /**
     * Set the output-escaping context for the next processExpression() call.
     * Called by the Compiler as it tracks the current position in the template.
     *
     * @param string $context  'html' | 'js' | 'css'
     */
    public function setEscapeContext(string $context): void
    {
        $this->escapeContext = $context;
    }

    public function processExpression(string $expression): string
    {
        $this->autoEscape = true;
        [$expr, $filters] = $this->splitPipeline($this->normalizePipeOperator($expression));

        $phpExpr = $this->convertVarsAndOps($expr);

        // Wrap in filter calls (innermost first â†’ outermost last)
        foreach ($filters as $filterSegment) {
            $phpExpr = $this->buildFilterCall($filterSegment, $phpExpr);
        }

        if ($this->autoEscape) {
            $phpExpr = match ($this->escapeContext) {
                'js'    => '\\json_encode(' . $phpExpr . ', 271)', // HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT|UNESCAPED_UNICODE
                'css'   => '(string)(' . $phpExpr . ')',           // raw â€” CSS values are not HTML-escaped
                default => "\\htmlspecialchars((string)({$phpExpr}), 11, 'UTF-8')",
            };
        }

        return $phpExpr;
    }

    /**
     * Convert a Clarity expression without pipeline â€” used for control
     * structure conditions (if, for, set) where auto-escape is meaningless.
     *
     * @param string $expression Raw Clarity expression.
     * @return string PHP expression.
     */
    public function processCondition(string $expression): string
    {
        [$expr, $filters] = $this->splitPipeline($this->normalizePipeOperator($expression));
        $phpExpr = $this->convertVarsAndOps($expr);

        foreach ($filters as $filterSegment) {
            $phpExpr = $this->buildFilterCall($filterSegment, $phpExpr);
        }

        return $phpExpr;
    }

    /**
     * Convert a Clarity variable chain to its PHP lvalue equivalent, for the
     * left-hand side of {% set var = ... %}.
     *
     * Scope-aware by construction: open mode seeds the render scope into locals,
     * so `{% set a = â€¦ %}` compiles to a plain `$a = â€¦` and both worlds read the
     * SAME slot.  Sandbox mode targets `$__c_va['a']` exactly as before.  The
     * choice lives in the chain emitter, so it cannot drift from the read path.
     *
     * @param string $var Clarity variable name (e.g. 'user.name', 'items[0]').
     * @return string PHP lvalue (e.g. '$user', or '$__c_va[\'user\'][\'name\']').
     */
    public function processLvalue(string $var): string
    {
        return $this->varChainToPhp(\trim($var));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize bare | to |> so that both act as the filter pipe operator.
     *
     * Rules (applied only at the top nesting level, outside quoted strings):
     *   ||  â†’ passed through unchanged  (PHP logical OR)
     *   |>  â†’ passed through unchanged  (already the canonical pipe)
     *   |   â†’ rewritten to |>           (Twig/Svelte-compatible shorthand)
     *
     * This runs before splitPipeline() so that the rest of the pipeline logic
     * only ever sees |> as the delimiter.
     */
    private function normalizePipeOperator(string $expr): string
    {
        $len = \strlen($expr);
        if ($len === 0) {
            return $expr;
        }

        $out      = '';
        $i        = 0;
        $inSingle = false;
        $inDouble = false;
        $depth    = 0;

        while ($i < $len) {
            $ch = $expr[$i];

            // Escape sequences inside strings
            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $out .= $ch . $expr[$i + 1];
                $i += 2;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $out .= $ch;
                $i++;
                continue;
            }

            if ($inSingle || $inDouble) {
                $out .= $ch;
                $i++;
                continue;
            }

            // Track bracket depth
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                if ($depth > 0) {
                    $depth--;
                }
                $out .= $ch;
                $i++;
                continue;
            }

            // Pipe handling â€” only at top level
            if ($depth === 0 && $ch === '|') {
                $next = $expr[$i + 1] ?? '';
                if ($next === '|') {
                    // || â†’ logical OR, pass through
                    $out .= '||';
                    $i += 2;
                    continue;
                }
                if ($next === '>') {
                    // |> â†’ already canonical, pass through
                    $out .= '|>';
                    $i += 2;
                    continue;
                }
                // bare | â†’ normalize to |>
                $out .= '|>';
                $i++;
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Split an expression string on the |> pipeline operator.
     *
     * Returns [expressionString, [filterSegment, ...]].
     * The expression string may still contain quoted strings, so we cannot
     * simply explode â€” we split only on |> that are not inside quotes.
     *
     * @return array{0: string, 1: string[]}
     */
    private function splitPipeline(string $expression): array
    {
        $parts = $this->splitRespectingStrings($expression, '|>');

        $expr = \trim(\array_shift($parts));
        foreach ($parts as &$part) {
            $part = \trim($part);
        }
        unset($part);

        return [$expr, $parts];
    }

    /**
     * Split $subject on $delimiter while respecting single- and double-quoted
     * string literals and balanced parentheses / square / curly brackets (i.e.
     * do not split on delimiters that are inside quotes or nested structures).
     *
     * This ensures that lambdas with inner pipelines work correctly, for example:
     *   items |> map(item => item |> upper) |> join(",")
     * The |> inside map(...) is at depth > 0 and is not treated as a split point.
     *
     * @return string[]
     */
    private function splitRespectingStrings(string $subject, string $delimiter): array
    {
        $parts    = [];
        $current  = '';
        $len      = \strlen($subject);
        $dlen     = \strlen($delimiter);
        $i        = 0;
        $inSingle = false;
        $inDouble = false;
        $depth    = 0; // parenthesis / square / curly-brace nesting depth

        while ($i < $len) {
            $ch = $subject[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $current .= $ch . $subject[$i + 1];
                $i += 2;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $current .= $ch;
                $i++;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $current .= $ch;
                $i++;
            } elseif (!$inSingle && !$inDouble && ($ch === '(' || $ch === '[' || $ch === '{')) {
                $depth++;
                $current .= $ch;
                $i++;
            } elseif (!$inSingle && !$inDouble && ($ch === ')' || $ch === ']' || $ch === '}')) {
                if ($depth > 0) {
                    $depth--;
                }
                $current .= $ch;
                $i++;
            } elseif (!$inSingle && !$inDouble && $depth === 0 && \substr($subject, $i, $dlen) === $delimiter) {
                $parts[] = $current;
                $current = '';
                $i += $dlen;
            } else {
                $current .= $ch;
                $i++;
            }
        }

        $parts[] = $current;
        return $parts;
    }

    /**
     * Convert a Clarity expression (no pipeline) to PHP by:
     * 1. Replacing var-chains with $__c_va[...] accesses
     * 2. Replacing logical/string operators with PHP equivalents
     * 3. Rejecting function-call syntax: any identifier followed by '(' throws
     *    a ClarityException at compile time â€” use the |> filter pipeline instead.
     *
     * Strategy: tokenize the expression into atoms (quoted strings, numbers,
     * identifiers/var-chains, operators, punctuation) and process each atom.
     */
    public function convertVarsAndOps(string $expr): string
    {
        static $keywordMap = [
            'and'   => '&&',
            'or'    => '||',
            'not'   => '!',
            'bor'   => '|',
            'band'  => '&',
            'bxor'  => '^',
            'bnot'  => '~',
            'blsh'  => '<<',
            'brsh'  => '>>',
            'true'  => 'true',
            'false' => 'false',
            'null'  => 'null',
        ];

        $len      = \strlen($expr);
        $i        = 0;
        $out      = '';
        $inSingle = false;
        $inDouble = false;

        // Ternary tracking, LOCAL to this call so parentheses get their own
        // scope for free: `(` recurses through processCondition(), and the paren
        // body is compiled by a fresh convertVarsAndOps() with its own state.
        // That is what lets `cond ? (foo ? bar : blubb) : blobb` nest to any
        // depth without a shared counter.
        //
        // `$ternarySeen` answers the one question the parser cannot answer from
        // spacing alone: whether a `:` is a ternary separator or an array-key
        // read. Once a ternary is open, a colon only reads as a key when it is
        // glued to both sides.
        //
        // `$ternaryPhases` records the branch each open ternary is in, because
        // PHP rejects a ternary CHAINED in an else position
        // (`a ? b : c ? d : e`) while accepting the nested then-branch form
        // (`a ? b ? c : d : e`). Catching that here turns a fatal PHP parse
        // error â€” which happens when the generated class is loaded and cannot be
        // caught â€” into a normal compile error with a line number.
        $ternarySeen     = false;
        $ternaryPhases   = [];
        $ternaryOpenedAt = [];

        while ($i < $len) {
            $ch = $expr[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $out .= $ch . $expr[$i + 1];
                $i += 2;
                continue;
            }

            // Quote handling
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($inSingle || $inDouble) {
                // A string literal is TEXT, never PHP interpolation.  A `$`
                // inside a double-quoted string would otherwise be interpolated
                // by PHP (`"$name"`, and the deprecated `"${name}"`) â€” a leak of
                // PHP semantics into a template literal, and the reason
                // `{{ "${x}" }}` used to emit a deprecation instead of the
                // literal text.  Escaping the dollar keeps the literal literal.
                if ($inDouble && $ch === '$') {
                    $out .= '\\$';
                    $i++;
                    continue;
                }
                $out .= $ch;
                $i++;
                continue;
            }

            // A ternary `?` opens a branch that ends at the next `:` that is not
            // an array-key read. `??`, `?.`, `?[`, `?{` and `?->` are separate
            // operators and leave the state alone. `?:` (optional key) is glued
            // to a key, so it is not a ternary either.
            if ($ch === '?') {
                $next = $expr[$i + 1] ?? '';
                if ($next !== '?' && $next !== '.' && $next !== '[' && $next !== '{' && $next !== ':') {
                    $isArrow = $next === '-' && ($expr[$i + 2] ?? '') === '>';
                    if (
                        !$isArrow
                            && $i > 0 && \ctype_space($expr[$i - 1])
                            && $next !== '' && \ctype_space($next)
                    ) {
                        // A ternary opening inside an ELSE branch would be
                        // chained, and PHP rejects `a ? b : c ? d : e` outright.
                        // Because the parenthesised form recurses, an else
                        // branch that is wrapped in `(` never reaches here, so
                        // reaching it is unambiguous.
                        if (\end($ternaryPhases) === 'else') {
                            throw new ClarityException(
                                "Unparenthesised nested ternary: PHP parses 'a ? b : c ? d : e' as invalid. "
                                    . "Wrap the else-branch in parentheses, as in 'a ? b : (c ? d : e)', in '{$expr}'."
                            );
                        }

                        // `cond ? cond2 ? x : y : z` nests in the THEN branch,
                        // which PHP accepts and this engine already supported.
                        $ternarySeen = true;
                        $ternaryPhases[] = 'then';
                        $ternaryOpenedAt[] = $i;
                    }
                }
            }

            // Ternary separator: reached only when a ternary is open AND the
            // colon is not a key read. Chains are compiled before this point, so
            // a glued `a:b` is consumed as a key and never arrives here.
            //
            // A ternary colon may be spaced on either side (`x : y`, `x: y`,
            // `x :y`) because an open ternary makes the reading unambiguous. Two
            // spellings are errors rather than ambiguity:
            //   â€¢ `?:` glued both sides is the optional-key operator.
            //   â€¢ a colon glued to a following identifier would read as a key
            //     chain, which is the trap this whole rule exists to avoid.
            if ($ch === ':' && $ternarySeen && !$this->isChainColon($expr, $i, true)) {
                $phase  = array_pop($ternaryPhases) ?? 'then';
                $opened = array_pop($ternaryOpenedAt) ?? 0;

                // An empty then-branch would compile to PHP's `?:` shorthand
                // (`a ?: b`), which tests the CONDITION for truthiness instead
                // of reading an optional key. The two spellings differ by one
                // space, so this is reported rather than left to mean something
                // the author did not write.
                $then = \trim(\substr($expr, $opened + 1, $i - $opened - 1));
                if ($then === '') {
                    throw new ClarityException(
                        "Ternary is missing its then-branch in '{$expr}'. "
                            . "For an optional array key write 'name?:key' without the space."
                    );
                }

                $j = $i + 1;
                while ($j < $len && \ctype_space($expr[$j])) {
                    $j++;
                }
                $after = $expr[$j] ?? '';

                // `else if` is not a Clarity construct; an if-chain is a nested
                // ternary whose else-branch is itself a ternary. PHP only parses
                // that when the branch is parenthesised, so require it here
                // rather than emitting code PHP will refuse to load.
                if (
                    $this->startsIdentifier($after)
                        && substr($expr, $j, 2) === 'if'
                        && !$this->isIdentChar($expr[$j + 2] ?? '')
                ) {
                    throw new ClarityException(
                        "Nested ternary else-branch must be parenthesised: write "
                            . "'cond ? x : (cond2 ? y : z)' instead of using 'else if' in '{$expr}'."
                    );
                }

                // The `else` branch may not itself be an unparenthesised ternary:
                // PHP rejects `a ? b : c ? d : e` outright. `a ? b ? c : d : e`
                // is fine (then-branch), and a parenthesised else-branch starts a
                // fresh scope where a new ternary is legal â€” this call recurses
                // for it via processCondition(), so the tracked phase there is
                // independent.
                //
                // Spacing around this colon carries no meaning beyond what
                // isChainColon() already decided: reaching this point means the
                // colon is a separator, so `x : y`, `x: y` and `x :y` are all
                // ternaries. (A colon glued on BOTH sides is a key read and was
                // consumed above, which is what keeps `cond ? a:b : c` working.)
                $next = $expr[$i + 1] ?? '';
                if ($phase === 'then' && $next !== '(' && $next !== '') {
                    $ternaryPhases[] = 'else';
                }

                $ternarySeen = $ternaryPhases !== [];
            }

            // Map single-char operator ~ outside strings
            if ($ch === '~') {
                $out .= '.';
                $i++;
                continue;
            }

            // Disallow statement delimiters and backticks anywhere outside of strings
            if ($ch === ';' || $ch === '`' || ($ch === '?' && ($expr[$i + 1] ?? '') === '>') || ($ch === '<' && ($expr[$i + 1] ?? '') === '?')) {
                throw new ClarityException('Expressions must not contain statement delimiters or backticks.');
            }

            // Disallow heredoc/nowdoc openers: <<< would compile to a PHP heredoc
            // inside the generated echo statement, enabling code injection.
            if ($ch === '<' && substr($expr, $i, 3) === '<<<') {
                throw new ClarityException('Heredoc/nowdoc syntax (<<<) is not allowed in Clarity expressions.');
            }

            // A `?->` that reaches here has no `$` sigil, so it is a plain
            // expression. Left alone it would be copied through as raw PHP
            // nullsafe syntax â€” a leak the sigil rule exists to prevent â€” and a
            // SPACED `? ->` is a syntax error in PHP rather than a nullsafe read.
            if ($ch === '?' && ($expr[$i + 1] ?? '') === '-') {
                $afterArrow = $expr[$i + 2] ?? '';
                if ($afterArrow === '>') {
                    throw new ClarityException(
                        "PHP-style property access ('->') requires the \$ sigil: "
                            . "write \$a?->b or a?.b instead of '?->' in '{$expr}'."
                    );
                }
            }

            // The `$` sigil introduces a PHP-style chain: $user->name, $a.b,
            // $a?.b. It is the ONLY way to spell `->`; a bare `a->b` is rejected
            // by parseVarChainAt() so raw PHP property syntax can never be
            // emitted from an unmarked expression.
            if ($ch === '$') {
                $sigilStart = $i + 1;
                $next       = $expr[$sigilStart] ?? '';

                // `${expr}` â€” and its shorthand `$$name` â€” read the variable
                // whose NAME is produced by an expression.  The two spellings
                // are the same construct (`$$name` is `${name}`) and compile to
                // the same lookup.
                //
                // The lookup resolves against `$__c_va` and the loop-local map
                // (never a PHP dynamic variable), so it can reach neither a
                // superglobal nor an engine internal: a name such as `__c_fn` is
                // simply absent from the scope.  An absent name is STRICT unless
                // a `??` follows, in which case the absent branch yields null so
                // the operator can supply the fallback â€” exactly the behaviour of
                // a literal `{{ name }}` / `{{ name ?? 'x' }}`.
                if ($next === '{' || $next === '$') {
                    if ($next === '{') {
                        [$inner, $end] = $this->extractBalancedSegment($expr, $sigilStart);
                        $nameRaw = \trim($inner);
                        if ($nameRaw === '') {
                            throw new ClarityException('Dynamic variable name must not be empty (${}).');
                        }
                        $namePhp = $this->processCondition($nameRaw);
                    } else {
                        $nameEnd = $sigilStart + 1; // after the second '$'
                        if (!self::isIdentifierStart($expr[$nameEnd] ?? '')) {
                            throw new ClarityException(
                                "Direct PHP variable access ('\$') is not allowed in Clarity expressions; "
                                    . "use a variable name after the sigil (\$name) or dot-notation (name.field)."
                            );
                        }
                        $nameEnd++;
                        while ($nameEnd < $len && self::isIdentifierChar($expr[$nameEnd])) {
                            $nameEnd++;
                        }
                        // `$$name` names the variable read from `name` itself.
                        $namePhp = $this->processCondition(\substr($expr, $sigilStart + 1, $nameEnd - $sigilStart - 1));
                        $end     = $nameEnd;
                    }

                    $k = $end;
                    while ($k < $len && \ctype_space($expr[$k])) {
                        $k++;
                    }
                    $coalesces = ($expr[$k] ?? '') === '?' && ($expr[$k + 1] ?? '') === '?';

                    $root = $this->buildDynamicLookup($namePhp, $coalesces);

                    // Chained access applies to the LOOKED-UP value:
                    // `${ref}.name`, `${ref}[0]`.
                    [$root, $i] = $this->compilePostfixAccessChain($expr, $end, $root);
                    $out .= $root;
                    continue;
                }

                if (!self::isIdentifierStart($next)) {
                    throw new ClarityException(
                        "Direct PHP variable access ('\$') is not allowed in Clarity expressions; "
                            . "use a variable name after the sigil (\$name) or dot-notation (name.field)."
                    );
                }

                $parsed = $this->parseVarChainAt($expr, $sigilStart, true, $ternarySeen, !$this->sandboxMode);
                if ($parsed === null) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                $i        = $parsed['end'];
                $segments = $parsed['segments'];
                $token    = \substr($expr, $sigilStart, $i - $sigilStart);

                // A `(` that survives chain parsing is a call on the ROOT value
                // (e.g. `$fn()`), not a method call â€” method calls are consumed
                // into their property segment.  Root invocation stays rejected:
                // a variable-driven callable is the function-level equivalent of
                // variable-variable expansion.
                $j = $i;
                while ($j < $len && \ctype_space($expr[$j])) {
                    $j++;
                }
                if ($j < $len && $expr[$j] === '(') {
                    $context = \substr($expr, \max(0, $sigilStart - 10), 70);
                    throw new ClarityException("Method calls are not allowed in expressions: '\${$token}(...)' in context '{$context}'");
                }

                if (isset($this->localVars[$segments[0]['value']])) {
                    $out .= $this->buildVarChainPhpWithLocalRoot($segments);
                } else {
                    $out .= $this->varChainToPhpWithSegments('$' . $token, $segments);
                }
                continue;
            }

            if (
                $ch === '.'
                    && ($expr[$i + 1] ?? '') === '.'
                    && ($expr[$i + 2] ?? '') === '.'
            ) {
                throw new ClarityException('Spread operator is only allowed inside array and object literals.');
            }

            if ($ch === '[' || $ch === '{') {
                [$literalPhp, $i] = $this->parseCollectionLiteralAt($expr, $i);
                $out .= $literalPhp;
                continue;
            }

            if ($ch === '(') {
                [$inner, $end] = $this->extractBalancedSegment($expr, $i);
                $out .= '(' . $this->processCondition($inner) . ')';
                $i = $end;
                continue;
            }

            // Identifier / var-chain detection
            if (self::isIdentifierStart($ch)) {
                $start = $i;

                // --- Performance: try the cache with just the raw identifier first.
                // For simple single-word names (the dominant case) this avoids calling
                // parseVarChainAt() at all.  We peek ahead to find the identifier end,
                // check the cache, and only fall through to full parsing on a miss or
                // when the identifier is followed by '.' or '['.
                $idEnd = $i + 1;
                while ($idEnd < $len && self::isIdentifierChar($expr[$idEnd])) {
                    $idEnd++;
                }
                $nextAfterIdent = $expr[$idEnd] ?? '';
                $nextTwo        = \substr($expr, $idEnd, 2);

                // Whitespace may separate an identifier from its continuation,
                // so the fast path must look past it before deciding that this
                // is a plain name. Doing so here (rather than after the token is
                // emitted) is what keeps `user.\nname` on the chain path.
                $contPos = $idEnd;
                while ($contPos < $len && \ctype_space($expr[$contPos])) {
                    $contPos++;
                }
                $contChar = $expr[$contPos] ?? '';
                $contTwo  = \substr($expr, $contPos, 2);

                // Twig-style infix/prefix TESTS: `x in y`, `x is defined`,
                // `x is not empty`, `x starts with y`, `x matches p`, …
                // Dispatched on a word boundary so an ordinary variable named
                // `in`/`is` (used as `{{ in }}`) is untouched. This runs BEFORE
                // the chain-continuation branch below, because a right operand
                // that starts with `[`/`(` would otherwise read as an index read
                // (`x in [1,2,3]` mis-compiling to `$__c_va['in'][…]`).
                if (!($start > 0 && self::isIdentifierChar($expr[$start - 1]))) {
                    if ($this->tryCompileOperatorTest($expr, $start, $idEnd, $ternarySeen, $out, $i)) {
                        continue;
                    }
                }

                // Open-mode filter placeholder: a lone `_` stands for the piped
                // value while compiling a PHP function's argument list.  It is
                // recognised ONLY there (and only when not a chain root), so a
                // template variable named `_` keeps working everywhere else.
                if (
                    $this->inOpenFilterArgs
                        && $idEnd - $start === 1
                        && $expr[$start] === '_'
                        && $contChar !== '('
                        && $contChar !== '.'
                        && $contChar !== '['
                        && $contChar !== '{'
                ) {
                    $out .= '(' . $this->openFilterValue . ')';
                    $i = $idEnd;
                    continue;
                }

                if (
                    $contChar !== '.' && $contChar !== '['
                        && $contChar !== '{' && $contChar !== ':'
                        && $contChar !== '?' && $contTwo !== '->'
                ) {
                    // Plain identifier â€” may be a keyword or a cacheable single-segment chain
                    $token = \substr($expr, $start, $idEnd - $start);
                    $i     = $idEnd;

                    $prevChar = ($start - 1 >= 0) ? $expr[$start - 1] : null;
                    $nextChar = $nextAfterIdent !== '' ? $nextAfterIdent : null;
                    $prevIsId = $prevChar !== null && self::isIdentifierChar($prevChar);
                    $nextIsId = $nextChar !== null && self::isIdentifierChar($nextChar);
                    $lower    = \strtolower($token);

                    if (!$prevIsId && !$nextIsId && isset($keywordMap[$lower])) {
                        $out .= $keywordMap[$lower];
                        continue;
                    }

                    // Function-call syntax: allowed only for explicitly registered functions.
                    $j = $i;
                    while ($j < $len && \ctype_space($expr[$j])) {
                        $j++;
                    }
                    if ($j < $len && $expr[$j] === '(') {
                        if ($this->registry !== null && $this->registry->hasFunction($token)) {
                            [$call, $i] = $this->buildFunctionCallInExpr($token, $expr, $j, $len);
                            $out .= $call;
                            continue;
                        }
                        // Open mode: an unregistered name is emitted as a direct
                        // PHP function call, minus the deny-list.
                        if (!$this->sandboxMode) {
                            if (!$this->isFunctionCallAllowed($token)) {
                                throw new ClarityException(
                                    "Function '{$token}()' is blocked in open mode. Allow it by removing it from the deny-list."
                                );
                            }
                            [$call, $i] = $this->buildFunctionCallInExpr($token, $expr, $j, $len);
                            $out .= $call;
                            continue;
                        }
                        $context = \substr($expr, \max(0, $start - 10), \min(60, $len - $start + 10));
                        throw new ClarityException("Call to unregistered function in context '{$context}'. Register it via addFunction() first.");
                    }

                    // Check local vars (loop variables) before the cache: a locally-bound
                    // variable must resolve to its PHP local var, not to $__c_va['name'].
                    if (isset($this->localVars[$token])) {
                        $out .= $this->localVars[$token];
                        continue;
                    }

                    if (isset($this->varChainCache[$token])) {
                        $out .= $this->varChainCache[$token];
                    } else {
                        $parsed = $this->parseVarChainAt($expr, $start, false, $ternarySeen);
                        $php    = $parsed !== null
                            ? $this->varChainToPhpWithSegments($token, $parsed['segments'])
                            : $token;
                        if ($parsed !== null) {
                            $this->varChainCache[$token] = $php;
                        }
                        $out .= $php;
                    }
                    continue;
                }

                // Identifier followed by a chain continuation â€” full chain parsing required.
                $parsed = $this->parseVarChainAt($expr, $start, false, $ternarySeen);
                if ($parsed === null) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                $segments = $parsed['segments'];

                // A chain that could not consume anything past the identifier is not
                // a chain at all (e.g. `a ? b`, `a - b`). Emit the bare name so the
                // operator path below handles the rest.
                if (\count($segments) === 1) {
                    $token = $segments[0]['value'];
                    $i     = $idEnd;
                    if (isset($this->localVars[$token])) {
                        $out .= $this->localVars[$token];
                    } elseif (isset($this->varChainCache[$token])) {
                        $out .= $this->varChainCache[$token];
                    } else {
                        $php = $this->varChainToPhpWithSegments($token, $segments);
                        $this->varChainCache[$token] = $php;
                        $out .= $php;
                    }
                    continue;
                }

                $i     = $parsed['end'];
                $token = \substr($expr, $start, $i - $start);

                // Dot/bracket chains cannot be function calls â€” always forbidden.
                $j = $i;
                while ($j < $len && \ctype_space($expr[$j])) {
                    $j++;
                }
                if ($j < $len && $expr[$j] === '(') {
                    $context = \substr($expr, \max(0, $start - 10), \min(60, $len - $start + 10));
                    throw new ClarityException("Method calls are not allowed in expressions: '{$token}(...)' in context '{$context}'");
                }
                // Check if the chain root is a locally-bound variable (loop var)
                if (isset($this->localVars[$segments[0]['value']])) {
                    $out .= $this->buildVarChainPhpWithLocalRoot($segments);
                } else {
                    $out .= $this->varChainToPhpWithSegments($token, $segments);
                }
                continue;
            }

            // A `.` that reached the fall-through is not concatenation and not a
            // chain continuation, so it is a mistake. Concatenation is `~`
            // (`a ~ b`); `.` always means property access. A valid chain never
            // arrives here, because parseVarChainAt() consumes the operator and
            // its member together.
            //
            // The one non-chain reading is a DECIMAL literal, which keeps its
            // dot: `1234.56` (digit on both sides) and `.5` (nothing binding on
            // the left). That distinction is what keeps a float from being
            // reported as a missing property name.
            if ($ch === '.') {
                $dotPrev = $i > 0 ? $expr[$i - 1] : '';
                $dotNext = $expr[$i + 1] ?? '';
                $binds   = \ctype_alnum($dotPrev) || $dotPrev === '_'
                    || $dotPrev === ')' || $dotPrev === ']' || $dotPrev === '}';

                if ((\ctype_digit($dotPrev) && \ctype_digit($dotNext)) || (!$binds && \ctype_digit($dotNext))) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                throw new ClarityException(
                    "Property access operator '.' must be followed by a property name in '{$expr}'. "
                        . "Use '~' for string concatenation."
                );
            }

            // default: copy
            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Parse a Clarity array/object literal and any trailing property/index access.
     *
     * @return array{0:string,1:int}
     */
    private function parseCollectionLiteralAt(string $expr, int $start): array
    {
        [$inner, $end] = $this->extractBalancedSegment($expr, $start);

        $php = $expr[$start] === '['
            ? $this->compileArrayLiteral($inner)
            : $this->compileObjectLiteral($inner);

        return $this->compilePostfixAccessChain($expr, $end, $php);
    }

    private function compileArrayLiteral(string $inner): string
    {
        $inner = \trim($inner);
        if ($inner === '') {
            return '[]';
        }

        $items = [];
        foreach ($this->splitRespectingStrings($inner, ',') as $part) {
            $part = \trim($part);
            if ($part === '') {
                throw new ClarityException('Array literals must not contain empty elements.');
            }
            if (\str_starts_with($part, '...')) {
                $spread = \trim(\substr($part, 3));
                if ($spread === '') {
                    throw new ClarityException('Array spread operator must be followed by an expression.');
                }
                $items[] = '...' . $this->processCondition($spread);
                continue;
            }

            $items[] = $this->processCondition($part);
        }

        return '[' . \implode(', ', $items) . ']';
    }

    private function compileObjectLiteral(string $inner): string
    {
        $inner = \trim($inner);
        if ($inner === '') {
            return '[]';
        }

        $items = [];
        foreach ($this->splitRespectingStrings($inner, ',') as $entry) {
            $entry = \trim($entry);
            if ($entry === '') {
                throw new ClarityException('Object literals must not contain empty entries.');
            }

            if (\str_starts_with($entry, '...')) {
                $spread = \trim(\substr($entry, 3));
                if ($spread === '') {
                    throw new ClarityException('Object spread operator must be followed by an expression.');
                }
                $items[] = '...' . $this->processCondition($spread);
                continue;
            }

            $colonPos = $this->findTopLevelChar($entry, ':');
            if ($colonPos === false) {
                throw new ClarityException("Object literal entries must use 'key: value' syntax: '{$entry}'");
            }

            $rawKey   = \trim(\substr($entry, 0, $colonPos));
            $rawValue = \trim(\substr($entry, $colonPos + 1));

            if ($rawValue === '') {
                throw new ClarityException("Object literal entry is missing a value for key '{$rawKey}'.");
            }

            $items[] = $this->compileObjectKey($rawKey) . ' => ' . $this->processCondition($rawValue);
        }

        return '[' . \implode(', ', $items) . ']';
    }

    private function compileObjectKey(string $rawKey): string
    {
        if ($rawKey === '') {
            throw new ClarityException('Object literal keys must not be empty.');
        }

        $first = $rawKey[0];
        $last  = $rawKey[\strlen($rawKey) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return $rawKey;
        }

        if (!\preg_match(self::IDENT_RE, $rawKey)) {
            throw new ClarityException(
                "Object literal keys must be fixed strings or identifiers, got '{$rawKey}'."
            );
        }

        return "'" . \addslashes($rawKey) . "'";
    }

    /**
     * Consume chained property/index access after a compiled expression.
     *
     * @return array{0:string,1:int}
     */
    private function compilePostfixAccessChain(string $expr, int $start, string $php): array
    {
        $len = \strlen($expr);
        $i   = $start;

        while ($i < $len) {
            while ($i < $len && \ctype_space($expr[$i])) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            // Optional prefix: `?` immediately before a continuation.
            $optional = false;
            if ($expr[$i] === '?') {
                $next = $expr[$i + 1] ?? '';
                if (
                    $next === '.' || $next === '[' || $next === '{'
                        || ($next === ':' && $this->isChainColon($expr, $i + 1, true)
                            && $this->startsIdentifier($expr[$i + 2] ?? ''))
                ) {
                    $optional = true;
                    $i++;
                } else {
                    break;
                }
            }

            if ($expr[$i] === '.') {
                // Whitespace after `.` is allowed, exactly as in parseVarChainAt.
                $nameStart = $i + 1;
                while ($nameStart < $len && \ctype_space($expr[$nameStart])) {
                    $nameStart++;
                }

                if ($nameStart >= $len || !self::isIdentifierStart($expr[$nameStart])) {
                    throw new ClarityException(
                        "Property access operator '.' must be followed by a property name in '{$expr}'."
                    );
                }

                $nameEnd = $nameStart + 1;
                while ($nameEnd < $len && self::isIdentifierChar($expr[$nameEnd])) {
                    $nameEnd++;
                }

                $php = $this->appendChainSegmentPhp($php, [
                    'type'     => 'prop',
                    'value'    => \substr($expr, $nameStart, $nameEnd - $nameStart),
                    'optional' => $optional,
                ]);
                $i = $nameEnd;
                continue;
            }

            if ($expr[$i] === '[' || $expr[$i] === '{') {
                $isBrace = $expr[$i] === '{';
                [$inner, $end] = $this->extractBalancedSegment($expr, $i);
                $inner = \trim($inner);
                if ($inner === '') {
                    throw new ClarityException('Index access must not be empty.');
                }

                $php = $this->appendChainSegmentPhp($php, [
                    'type'     => $isBrace ? 'dyn' : 'index',
                    'value'    => $inner,
                    'optional' => $optional,
                ]);
                $i = $end;
                continue;
            }

            if ($expr[$i] === ':' && $this->isChainColon($expr, $i, true)) {
                $nameStart = $i + 1;
                while ($nameStart < $len && \ctype_space($expr[$nameStart])) {
                    $nameStart++;
                }

                $nameEnd = $nameStart + 1;
                while ($nameEnd < $len && self::isIdentifierChar($expr[$nameEnd])) {
                    $nameEnd++;
                }

                $php = $this->appendChainSegmentPhp($php, [
                    'type'     => 'key',
                    'value'    => \substr($expr, $nameStart, $nameEnd - $nameStart),
                    'optional' => $optional,
                ]);
                $i = $nameEnd;
                continue;
            }

            break;
        }

        return [$php, $i];
    }

    /**
     * Extract the contents of a balanced (), [] or {} segment starting at $start.
     *
     * @return array{0:string,1:int}
     */
    private function extractBalancedSegment(string $subject, int $start): array
    {
        $open  = $subject[$start] ?? null;
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        if ($open === null || !isset($pairs[$open])) {
            throw new ClarityException('Expected a balanced segment opener.');
        }

        $stack    = [$pairs[$open]];
        $len      = \strlen($subject);
        $i        = $start + 1;
        $inSingle = false;
        $inDouble = false;

        while ($i < $len) {
            $ch = $subject[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $i += 2;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $i++;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $i++;
                continue;
            }

            if ($inSingle || $inDouble) {
                $i++;
                continue;
            }

            if (isset($pairs[$ch])) {
                $stack[] = $pairs[$ch];
                $i++;
                continue;
            }

            $expected = $stack[\count($stack) - 1];
            if ($ch === $expected) {
                \array_pop($stack);
                if ($stack === []) {
                    return [\substr($subject, $start + 1, $i - $start - 1), $i + 1];
                }
            }

            $i++;
        }

        throw new ClarityException("Unterminated '{$open}' segment in expression.");
    }

    private function findTopLevelChar(string $subject, string $needle): int|bool
    {
        $len      = \strlen($subject);
        $inSingle = false;
        $inDouble = false;
        $stack    = [];
        $pairs    = ['(' => ')', '[' => ']', '{' => '}'];

        for ($i = 0; $i < $len; $i++) {
            $ch = $subject[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
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

            if ($inSingle || $inDouble) {
                continue;
            }

            if (isset($pairs[$ch])) {
                $stack[] = $pairs[$ch];
                continue;
            }

            if ($stack !== [] && $ch === $stack[\count($stack) - 1]) {
                \array_pop($stack);
                continue;
            }

            if ($stack === [] && $ch === $needle) {
                return $i;
            }
        }

        return false;
    }

    /**
     * Parse a registered-function call starting at the opening '(' in $expr
     * and return the compiled PHP expression plus the new position after ')'.
     *
     * Each argument is compiled as a full Clarity expression (pipelines and
     * nested function calls work inside arguments). Named arguments use the
     * Clarity `name=expression` syntax and are emitted as PHP named arguments
     * (`name: phpExpr`).
     *
     * Generated code: $this->__c_fn['name']($phpArg1, name2: $phpArg2, ...)
     *
     * @param string $name      The function name (already validated as registered).
     * @param string $expr      The full expression string being compiled.
     * @param int    $openParen Position of the '(' character in $expr.
     * @param int    $len       Length of $expr.
     * @return array{0: string, 1: int}  [phpCallExpression, indexAfterClosingParen]
     */
    private function buildFunctionCallInExpr(string $name, string $expr, int $openParen, int $len): array
    {
        $i        = $openParen + 1; // skip '('
        $argStart = $i;
        $depth    = 1;
        $inSingle = false;
        $inDouble = false;

        while ($i < $len && $depth > 0) {
            $ch = $expr[$i];
            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $i += 2;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $i++;
        }

        $argsRaw = \substr($expr, $argStart, $i - $argStart);
        $i++; // consume closing ')'

        if (isset($this->prunedFunctions[$name])) {
            return ["''", $i];
        }

        switch ($name) {
            case 'context':
                if (\trim($argsRaw) !== '') {
                    throw new ClarityException('context() does not accept any arguments.');
                }
                return ['$__c_va', $i];
            case 'include':
                $this->autoEscape = false;
                break;
        }

        // Open mode: a name that is not a registered function is emitted as a
        // direct PHP call.  Registered names keep precedence so engine- and
        // extension-defined functions are never shadowed by a PHP builtin.
        if ($this->registry === null || !$this->registry->hasFunction($name)) {
            $argList = \trim($argsRaw) !== '' ? $this->splitRespectingStrings($argsRaw, ',') : [];
            $callee  = '\\' . \ltrim($name, '\\');
            $call    = $callee . '(' . \implode(', ', $this->compileArgList($argList)) . ')';
            return [$call, $i];
        }

        // Context-injected function: prepend compile-time escape context as a
        // string literal first argument (e.g. dump/dd receive 'html'|'js'|'css').
        // These functions return raw HTML/JS markup — disable auto-escaping so
        // their output is never passed through htmlspecialchars/json_encode.
        if (isset($this->contextInjectedFunctions[$name])) {
            $this->autoEscape = false;
            $compiledArgs = ["'" . $this->escapeContext . "'"];
            if (\trim($argsRaw) !== '') {
                $argList = $this->splitRespectingStrings($argsRaw, ',');
                foreach ($this->compileArgList($argList) as $phpArg) {
                    $compiledArgs[] = $phpArg;
                }
            }
            return [$this->buildCall($name, $compiledArgs), $i];
        }

        // A registered callable (context, include, json at runtime, user
        // addFunction) dispatches through $__c_fn.
        if ($this->registry->getCallable($name) !== null) {
            // map/filter/reduce take a lambda/filter-ref as their SECOND argument
            // in call form (`map(items, x => …)`); compile it like the filter form.
            if (isset(self::CALLABLE_ARG_FILTERS[$name]) && \trim($argsRaw) !== '') {
                $argList      = $this->splitCallableArgs($argsRaw, true);
                $compiledArgs = [];
                foreach ($argList as $idx => $arg) {
                    $compiledArgs[] = $idx === 1
                        ? $this->compileCallableArg(\trim($arg), $name)
                        : $this->processCondition(\trim($arg));
                }
                return [$this->buildCall($name, $compiledArgs), $i];
            }

            $compiledArgs = \trim($argsRaw) !== ''
                ? $this->compileArgList($this->splitRespectingStrings($argsRaw, ','))
                : [];
            return [$this->buildCall($name, $compiledArgs), $i];
        }

        // Inline-only filter used under call syntax: derive the call from the
        // same template as the filter form. This is where `round(x, 2)` and
        // `date('Y-m-d', ts)` compile without any runtime dispatch.
        $argList = \trim($argsRaw) !== '' ? $this->splitRespectingStrings($argsRaw, ',') : [];
        $inline  = $this->buildInlineCallForm($name, $argList);
        if ($inline !== null) {
            return [$inline, $i];
        }

        // Registered, but neither a runtime callable nor an inline template
        // (a bare `filter` marker with no implementation): call `$__c_fn` so the
        // failure is a clear runtime error rather than a compile-time dead end.
        $compiledArgs = \trim($argsRaw) !== ''
            ? $this->compileArgList($this->splitRespectingStrings($argsRaw, ','))
            : [];
        return [$this->buildCall($name, $compiledArgs), $i];
    }

    /**
     * Parse a var-chain from $subject starting at $start.
     *
     * Returns null if no valid identifier starts at $start.
     *
     * Segment types
     * -------------
     *  key    array static key    a:b        a?:b
     *  index  array dynamic index a[i]       a?[i]
     *  prop   object property     a.b  a->b  a?.b  a?->b
     *  dyn    object dyn property a{k}       a?{k}
     *
     * The root segment is always `key` and resolves against the render scope;
     * only its NAME is used, so the `$` sigil form ($a.b) and the bare form
     * (a.b) produce identical segments.  A scope-seeded root (open mode) is
     * emitted as a PHP local, otherwise as a `$__c_va['name']` lookup.
     *
     * Every continuation carries an `optional` flag. Optional access is the
     * author's opt-out from the strict "missing access throws" contract.
     *
     * Whitespace
     * ---------
     * A chain continuation may be separated from the value it continues by ANY
     * amount of whitespace, including newlines, so a long chain can wrap
     * Go-style (`user.\naddress.\ncity`, `config:\nversion`). This is safe
     * precisely because `.` is NOT the concatenation operator â€” it always means
     * property access, so `a . b` has one reading and no ambiguity to preserve.
     *
     * Two operators must stay GLUED to the value on their left, because a
     * spaced spelling would collide with the ternary operator:
     *   â€¢ `?`  â€” a spaced `?` is a ternary; `? .` / `?[` / `?:` / `?->` written
     *            with a gap are therefore NOT optional access.
     *   â€¢ a `.` or `->` with no member after it is an authoring ERROR, not a
     *     value: there is nothing else it could mean.
     *
     * `:` â€” the ternary problem
     * -------------------------
     * A key colon is `:key`. Whitespace on either side is allowed
     * (`config : version` is the same read as `config:version`), EXCEPT while a
     * ternary is waiting for its branch separator: then a colon only counts as a
     * key read when it is glued to BOTH sides. That single rule keeps all of
     * these working, which no whitespace rule alone can do:
     *   cond ? x : y     -> ternary      (spaced both sides)
     *   cond ? x: y      -> ternary      (not glued right)
     *   cond ? x :y      -> ternary      (not glued left)
     *   cond ? a:b : c   -> key in then-branch, then the separator
     *
     * Optional access guards the RECEIVER only; the member read stays strict.
     * (`?->` on the object side, `=== null` guard on the array side.)
     *
     * @param bool $allowArrow      Whether `->` / `?->` count as property access.
     *                              True only for `$`-sigil roots; a bare `a->b` is
     *                              rejected by the caller.
     * @param bool $ternaryOpen  Whether a `?` is waiting for its branch
     *                              separator in the enclosing expression.
     * @param bool $allowCall    Whether a property read may be followed by a
     *                              method-call argument list (`$obj->m(...)`).
     *                              True only in open mode, and only on the
     *                              `$`-sigil path.
     * @return array{end:int, segments:array<int,array{type:string,value:string,optional:bool,call?:string}>}|null
     */
    private function parseVarChainAt(
        string $subject,
        int $start,
        bool $allowArrow = false,
        bool $ternaryOpen = false,
        bool $allowCall = false
    ): ?array {
        $len = \strlen($subject);
        if ($start >= $len) {
            return null;
        }

        $first = $subject[$start];
        if (!self::isIdentifierStart($first)) {
            return null;
        }

        $i = $start + 1;
        while ($i < $len && self::isIdentifierChar($subject[$i])) {
            $i++;
        }

        $root = [
            'type'     => 'key',
            'value'    => \substr($subject, $start, $i - $start),
            'optional' => false
        ];
        $segments = [$root];

        while ($i < $len) {
            $ch       = $subject[$i];
            $optional = false;

            // Whitespace before a chain continuation is not significant, so a
            // chain may wrap Go-style (`user.\naddress.\ncity`,
            // `config:\nversion`). Look past it, but ONLY when a continuation
            // really follows: if the next non-space character is an operator
            // (`+`, `?`, `and`, â€¦) the whitespace separates operands and the
            // chain ends here.
            if (\ctype_space($ch)) {
                $k = $i;
                while ($k < $len && \ctype_space($subject[$k])) {
                    $k++;
                }

                $nc      = $subject[$k] ?? '';
                $ncArrow = $nc === '-' && ($subject[$k + 1] ?? '') === '>';

                if (
                    $nc === '.' || $nc === '[' || $nc === '{' || $ncArrow
                        || ($nc === ':' && $this->isChainColon($subject, $k, $ternaryOpen))
                ) {
                    $i  = $k;
                    $ch = $nc;
                } else {
                    break;
                }
            }

            // ---- optional prefix: `?` glued to its continuation ------------
            if ($ch === '?') {
                $next    = $subject[$i + 1] ?? '';
                $isArrow = $next === '-' && ($subject[$i + 2] ?? '') === '>';

                if ($isArrow && !$allowArrow) {
                    // `a?->b` without the `$` sigil would otherwise fall through
                    // and emit raw PHP `?->`. Reject it here, where the `?` is
                    // still visible, rather than at the emission point. Note
                    // `?->` is THREE characters, so testing only the character
                    // after `?` never matches it.
                    throw new ClarityException(
                        "PHP-style property access ('->') requires the \$ sigil: "
                            . "write \${$root['value']}?->â€¦ or {$root['value']}?.â€¦ instead of {$root['value']}?->â€¦"
                    );
                }

                if (
                    $next === '.' || $next === '[' || $next === '{'
                        || ($isArrow && $allowArrow)
                        || ($next === ':' && $this->isGlued($subject, $i + 1, $ternaryOpen)
                            && $this->startsIdentifier($subject[$i + 2] ?? ''))
                ) {
                    $optional = true;
                    $i++;
                    $ch = $subject[$i];
                } else {
                    break; // `??` operator, a ternary `?`, or a spaced `?`
                }
            }

            // ---- property access: `.` or (`$`-sigil only) `->` --------------
            if ($ch === '.' || ($ch === '-' && ($subject[$i + 1] ?? '') === '>')) {
                $opLen = $ch === '-' ? 2 : 1;

                // A SPACED `.` or `->` after a ternary `?` is not a chain
                // continuation â€” `cond ? a : b` and `x ? .5 : 1` rely on this.
                if (!$this->isGlued($subject, $i + $opLen, $ternaryOpen)) {
                    break;
                }

                if ($ch === '-') {
                    // `->` is raw PHP property syntax. It is reachable two ways:
                    // as a bare `a->b` (checked below), and as `a?->b`, where the
                    // optional prefix has already consumed the `?` and would
                    // otherwise fall through to the same emission. Both must be
                    // rejected when the chain has no `$` sigil, or raw PHP
                    // `?->` would be emitted from a plain expression.
                    if (!$allowArrow) {
                        throw new ClarityException(
                            "PHP-style property access ('->') requires the \$ sigil: "
                                . "write \${$root['value']}->â€¦ (or {$root['value']}?.â€¦) instead of {$root['value']}->â€¦"
                        );
                    }
                }

                // Whitespace after the operator is allowed: a chain may wrap.
                $j = $i + $opLen;
                while ($j < $len && \ctype_space($subject[$j])) {
                    $j++;
                }

                // Dynamic property/method name after `->`: `$obj->{$m}` and
                // `$obj->{$m}(...)`.  Emitted as a `dyn` segment, exactly like
                // the standalone `a{k}` form.
                if ($j < $len && $subject[$j] === '{') {
                    [$inner, $end] = $this->extractBalancedSegment($subject, $j);

                    $segIndex = \count($segments);
                    $segments[] = [
                        'type'     => 'dyn',
                        'value'    => $inner,
                        'optional' => $optional,
                    ];
                    $i = $end;

                    if ($allowCall) {
                        $k2 = $i;
                        while ($k2 < $len && \ctype_space($subject[$k2])) {
                            $k2++;
                        }
                        if ($k2 < $len && $subject[$k2] === '(') {
                            [$callArgs, $callEnd] = $this->extractBalancedSegment($subject, $k2);
                            $segments[$segIndex]['call'] = $callArgs;
                            $i = $callEnd;
                        }
                    }
                    continue;
                }

                if ($j >= $len || !self::isIdentifierStart($subject[$j])) {
                    // A dangling `.` is never concatenation: `.` always means
                    // property access, so an operator with no member after it is
                    // an authoring mistake. Reporting it here is what stops a
                    // typo from becoming a silent string concat.
                    throw new ClarityException(
                        "Property access operator '"
                            . ($ch === '-' ? '->' : '.')
                            . "' must be followed by a property name in '"
                            . \substr($subject, $start) . "'."
                    );
                }

                $idStart = $j;
                $i       = $idStart + 1;
                while ($i < $len && self::isIdentifierChar($subject[$i])) {
                    $i++;
                }

                $segIndex = \count($segments);
                $segments[] = [
                    'type'     => 'prop',
                    'value'    => \substr($subject, $idStart, $i - $idStart),
                    'optional' => $optional,
                ];

                // Method call: attach the argument list to the property segment
                // so a nullsafe receiver stays one `?->m(args)` expression (PHP
                // short-circuits the whole call) instead of a guarded read that
                // a trailing `(...)` could never legally follow.
                if ($allowCall) {
                    $k2 = $i;
                    while ($k2 < $len && \ctype_space($subject[$k2])) {
                        $k2++;
                    }
                    if ($k2 < $len && $subject[$k2] === '(') {
                        [$callArgs, $callEnd] = $this->extractBalancedSegment($subject, $k2);
                        $segments[$segIndex]['call'] = $callArgs;
                        $i = $callEnd;
                    }
                }
                continue;
            }

            // ---- dynamic access: `[expr]` (array) or `{expr}` (property) ----
            if ($ch === '[' || $ch === '{') {
                $close = $ch === '[' ? ']' : '}';
                $i++;
                $innerStart = $i;
                $depth      = 1;

                while ($i < $len) {
                    $cc = $subject[$i];

                    if ($cc === "'" || $cc === '"') {
                        $quote = $cc;
                        $i++;
                        while ($i < $len) {
                            if ($subject[$i] === '\\' && ($i + 1) < $len) {
                                $i += 2;
                                continue;
                            }
                            if ($subject[$i] === $quote) {
                                $i++;
                                break;
                            }
                            $i++;
                        }
                        continue;
                    }

                    if ($cc === '[' || $cc === '{') {
                        $depth++;
                        $i++;
                        continue;
                    }

                    if ($cc === ']' || $cc === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $segIndex = \count($segments);
                            $segments[] = [
                                'type'     => $ch === '[' ? 'index' : 'dyn',
                                'value'    => \substr($subject, $innerStart, $i - $innerStart),
                                'optional' => $optional,
                            ];
                            $i++;
                            // Dynamic method call: `$obj->{$m}(...)`.
                            if ($allowCall && $ch === '{') {
                                $k2 = $i;
                                while ($k2 < $len && \ctype_space($subject[$k2])) {
                                    $k2++;
                                }
                                if ($k2 < $len && $subject[$k2] === '(') {
                                    [$callArgs, $callEnd] = $this->extractBalancedSegment($subject, $k2);
                                    $segments[$segIndex]['call'] = $callArgs;
                                    $i = $callEnd;
                                }
                            }
                            break;
                        }
                        $i++;
                        continue;
                    }

                    $i++;
                }

                if ($depth > 0) {
                    // Unterminated access expression: consume to end as one segment.
                    $segments[] = [
                        'type'     => $ch === '[' ? 'index' : 'dyn',
                        'value'    => \substr($subject, $innerStart),
                        'optional' => $optional,
                    ];
                    $i = $len;
                }

                continue;
            }

            // ---- static array key: `:key` ----------------------------------
            // Whitespace may sit on either side of the colon, so the key may be
            // on the next line. While a ternary is pending, the colon must be
            // GLUED on both sides to count as a key read â€” that is what leaves
            // `cond ? x : y` as a ternary.
            if ($ch === ':' && $this->isChainColon($subject, $i, $ternaryOpen)) {
                $idStart = $i + 1;
                while ($idStart < $len && \ctype_space($subject[$idStart])) {
                    $idStart++;
                }

                $j = $idStart + 1;
                while ($j < $len && self::isIdentifierChar($subject[$j])) {
                    $j++;
                }

                $segments[] = [
                    'type'     => 'key',
                    'value'    => \substr($subject, $idStart, $j - $idStart),
                    'optional' => $optional,
                ];
                $i = $j;
                continue;
            }

            break;
        }

        return ['end' => $i, 'segments' => $segments];
    }

    /**
     * Whether the character (a single BYTE) can start an identifier.
     *
     * Mirrors PHP's variable-name rule: a letter, an underscore, or a byte in
     * 0x80-0xFF.  Uses `ord()` rather than ctype_alpha(), which is
     * locale-dependent and would disagree with the runtime on a non-C locale.
     */
    public static function isIdentifierStart(string $ch): bool
    {
        if ($ch === '') {
            return false;
        }
        $b = \ord($ch);
        return ($b >= 0x41 && $b <= 0x5A)
            || ($b >= 0x61 && $b <= 0x7A)
            || $b === 0x5F
            || $b >= 0x80; // PHP accepts bytes 128-255
    }

    /**
     * Whether the character (a single BYTE) can appear inside an identifier.
     */
    public static function isIdentifierChar(string $ch): bool
    {
        if ($ch === '') {
            return false;
        }
        $b = \ord($ch);
        return ($b >= 0x30 && $b <= 0x39)
            || self::isIdentifierStart($ch);
    }

    /**
     * Whether the whole string is one PHP variable name.
     *
     * Callers that VALIDATE a name (rather than scan for one) must use this so
     * their accepted set can never drift from what the scanner above will
     * tokenize back out.
     */
    public static function isIdentifier(string $name): bool
    {
        return (bool) \preg_match(self::IDENT_RE, $name);
    }

    /**
     * Whether the character can start a chain continuation identifier.
     */
    private function startsIdentifier(string $ch): bool
    {
        return self::isIdentifierStart($ch);
    }

    /**
     * Whether the character can appear inside an identifier (so a keyword can be
     * told apart from a longer name that merely starts with it).
     */
    private function isIdentChar(string $ch): bool
    {
        return self::isIdentifierChar($ch);
    }

    /**
     * Whether a chain operator is GLUED to the value on its left. Meaningful
     * only for `?`, the one chain operator that shares its character with a
     * spaced operator (`a ? b : c`): a spaced `?` is a ternary, so it must not
     * open an optional access.
     */
    private function isGlued(string $subject, int $nextPos, bool $ternaryOpen): bool
    {
        return !$ternaryOpen || $nextPos >= \strlen($subject) || !\ctype_space($subject[$nextPos]);
    }

    /**
     * Whether the `:` at $pos starts an array-key continuation rather than the
     * separator of a ternary.
     *
     * The colon must be followed by a key. Whitespace is allowed on either side,
     * with ONE exception: while a ternary is pending, a colon only reads as a
     * key when it is glued on both sides. Only the glued both-sides form is
     * unambiguous â€” every other spacing belongs to a ternary:
     *
     *   config:version    key        a:b:c        key chain
     *   config : version  key        config: version   key
     *   cond ? x : y      ternary    cond ? x: y  ternary
     *   cond ? a:b : c    key (then) + separator
     */
    private function isChainColon(string $subject, int $pos, bool $ternaryOpen = false): bool
    {
        $idStart = $pos + 1;
        $len     = \strlen($subject);
        while ($idStart < $len && \ctype_space($subject[$idStart])) {
            $idStart++;
        }

        if (!$this->startsIdentifier($subject[$idStart] ?? '')) {
            return false;
        }

        if (!$ternaryOpen) {
            return true;
        }

        // Whitespace on the LEFT of the colon: it separates a ternary.
        if ($pos === 0 || \ctype_space($subject[$pos - 1])) {
            return false;
        }

        // Whitespace on the RIGHT: it separates a ternary.
        return !\ctype_space($subject[$pos + 1] ?? '');
    }

    // -------------------------------------------------------------------------
    // Twig-style operator tests
    // -------------------------------------------------------------------------
    // Twig-style operator tests
    // -------------------------------------------------------------------------

    /**
     * Operator tests, keyed by the (underscored) test name as written after
     * `is`, or by the operator word itself for the one infix test (`in`).
     *
     * Record schema
     * -------------
     *   binary    true  -> the test takes a right operand/argument (`x in y`,
     *                      `x matches p`, `x starts with p`).
     *   tolerates true  -> the left operand may be ABSENT; the test is compiled
     *                      as a presence probe instead of a read, so it answers
     *                      instead of throwing (`defined`, `null`, `empty`).
     *   call            -> the runtime callable that backs the test.
     *
     * Every test is VALUE-FIRST: the left operand becomes the first argument,
     * so `x in y` compiles to `in(x, y)` and `x starts with p` to
     * `starts_with(x, p)`.
     *
     * @var array<string, array{binary: bool, tolerates: bool, call: string}>
     */
    private const OPERATOR_TESTS = [
        // Infix: `value in container`.
        'in' => ['binary' => true, 'tolerates' => false, 'call' => 'in'],
        // `value is <test>` with a right operand.
        'matches'      => ['binary' => true, 'tolerates' => false, 'call' => 'matches'],
        'starts_with'  => ['binary' => true, 'tolerates' => false, 'call' => 'starts_with'],
        'ends_with'    => ['binary' => true, 'tolerates' => false, 'call' => 'ends_with'],
        'divisible_by' => ['binary' => true, 'tolerates' => false, 'call' => 'divisible_by'],
        'same_as'      => ['binary' => true, 'tolerates' => false, 'call' => 'same_as'],
        // `value is <test>` — no right operand.
        'defined'  => ['binary' => false, 'tolerates' => true, 'call' => 'defined'],
        'null'     => ['binary' => false, 'tolerates' => true, 'call' => 'is_null'],
        'none'     => ['binary' => false, 'tolerates' => true, 'call' => 'is_null'],
        'empty'    => ['binary' => false, 'tolerates' => true, 'call' => 'is_empty'],
        'iterable' => ['binary' => false, 'tolerates' => false, 'call' => 'iterable'],
        'even'     => ['binary' => false, 'tolerates' => false, 'call' => 'is_even'],
        'odd'      => ['binary' => false, 'tolerates' => false, 'call' => 'is_odd'],
    ];

    /**
     * Binary-operator keywords that end a top-level operand. Used both to find
     * the left operand of a test and to bound its (bare) right operand.
     */
    private const OPERATOR_WORDS = [
        'and'  => true,
        'or'   => true,
        'not'  => true,
        'in'   => true,
        'is'   => true,
        'bor'  => true,
        'band' => true,
        'bxor' => true,
        'bnot' => true,
        'blsh' => true,
        'brsh' => true,
    ];

    /**
     * Words that begin a standalone test (`<value> starts with <x>`) rather than
     * requiring the `is` marker. `is` itself is handled separately.
     */
    private const STANDALONE_TEST_WORDS = [
        'starts'    => true,
        'ends'      => true,
        'matches'   => true,
        'divisible' => true,
        'same'      => true,
    ];

    /**
     * Read a test name (one or two words) at $p, returning [underscoredName, endPos].
     * Returns ['', $p] when no identifier starts at $p.
     */
    private function readOperatorTestName(string $expr, int $p, int $len): array
    {
        if (!self::isIdentifierStart($expr[$p] ?? '')) {
            return ['', $p];
        }

        $start = $p;
        while ($p < $len && self::isIdentifierChar($expr[$p])) {
            $p++;
        }
        $name = \strtolower(\substr($expr, $start, $p - $start));

        // Multi-word names: `starts with`, `ends with`, `divisible by`,
        // `same as`. A single space only — `starts  with` is not a test.
        if (($expr[$p] ?? '') === ' ' && ($expr[$p + 1] ?? '') !== '' && !\ctype_space($expr[$p + 1])) {
            $q = $p + 1;
            while ($q < $len && self::isIdentifierChar($expr[$q])) {
                $q++;
            }
            $twoWord = $name . '_' . \strtolower(\substr($expr, $p + 1, $q - $p - 1));
            if (\array_key_exists($twoWord, self::OPERATOR_TESTS)) {
                return [$twoWord, $q];
            }
        }

        return [$name, $p];
    }

    /**
     * Try to compile a Twig-style test beginning at the identifier
     * [$start, $idEnd).  On success appends PHP to $out, sets $i past the test
     * and returns true; otherwise leaves both untouched and returns false.
     *
     * Grammar
     * -------
     *   <value> in <container>             -> in(<value>, <container>)
     *   <value> not in <container>         -> !in(<value>, <container>)
     *   <value> is <test>                  -> <test>(<value>)
     *   <value> is <test>(<args>)          -> <test>(<value>, <args>)
     *   <value> is not <test>[(<args>)]    -> !<test>(<value>, <args>)
     *
     * The binary tests also accept a bare right operand, which is what makes
     * `x in y` the natural spelling:
     *   <value> in <expr>                  -> in(<value>, <expr>)
     *   <value> matches <expr>             -> matches(<value>, <expr>)
     *
     * The left operand is the last top-level operand before the operator, so a
     * test composes with the operators around it (`a + b in c` keeps `b`).
     *
     * @param string $expr        Full expression being compiled.
     * @param int    $start       Index of the operator word's first character.
     * @param int    $idEnd       Index just past the operator word.
     * @param bool   $ternaryOpen Whether a ternary is currently awaiting its `:`.
     * @param string $out         PHP emitted so far (mutated on success).
     * @param int    $i           Current scan position (mutated on success).
     */
    private function tryCompileOperatorTest(
        string $expr,
        int $start,
        int $idEnd,
        bool $ternaryOpen,
        string &$out,
        int &$i
    ): bool {
        $len   = \strlen($expr);
        $lower = \strtolower(\substr($expr, $start, $idEnd - $start));

        // Trigger words:
        //   in                       -> `<value> in <container>`
        //   is                       -> `<value> is [not] <test>…`
        //   starts/ends/matches/…    -> standalone `<value> starts with <x>`
        $negated = false;
        $opPos   = $start;

        if ($lower === 'in') {
            $name = 'in';
            $p    = $idEnd;
            while ($p < $len && \ctype_space($expr[$p])) {
                $p++;
            }
            // `x not in y`: the `not` sits BEFORE `in`, so look backwards. It has
            // already been emitted as `!`, which is stripped with the operand.
            $b = $start;
            while ($b > 0 && \ctype_space($expr[$b - 1])) {
                $b--;
            }
            if (
                $b >= 3 && \substr($expr, $b - 3, 3) === 'not'
                    && ($b - 3 === 0 || !self::isIdentifierChar($expr[$b - 4]))
            ) {
                $negated = true;
                $opPos   = $b - 3;
            }
        } elseif ($lower === 'is') {
            $p = $idEnd;
            while ($p < $len && \ctype_space($expr[$p])) {
                $p++;
            }
            if (\substr($expr, $p, 3) === 'not' && !self::isIdentifierChar($expr[$p + 3] ?? '')) {
                $negated = true;
                $p += 3;
                while ($p < $len && \ctype_space($expr[$p])) {
                    $p++;
                }
            }
            [$name, $p] = $this->readOperatorTestName($expr, $p, $len);
            if ($name === '') {
                return false;
            }
        } elseif (isset(self::STANDALONE_TEST_WORDS[$lower])) {
            [$name, $p] = $this->readOperatorTestName($expr, $start, $len);
            if ($name === '') {
                return false;
            }
        } else {
            return false;
        }

        if (!\array_key_exists($name, self::OPERATOR_TESTS)) {
            // An unrecognised word after `is` is a mistake worth reporting; the
            // other triggers only fire on a known test word, so they cannot get
            // here with an unknown name.
            if ($lower === 'is') {
                throw new ClarityException(
                    "Unknown test '{$name}' after 'is'. Supported tests: "
                        . \implode(', ', \array_keys(self::OPERATOR_TESTS)) . '.'
                );
            }
            return false;
        }

        // The argument list / bare operand follows the test name.
        while ($p < $len && \ctype_space($expr[$p])) {
            $p++;
        }
        $after = $expr[$p] ?? '';

        $spec = self::OPERATOR_TESTS[$name];

        // Left operand first: everything before the operator, back to the last
        // top-level boundary. It is required. For `not in` the operator begins
        // at the `not`, so $opPos (not $start) bounds the operand.
        $lhsStart = $this->operatorTestLhsStart($expr, $opPos);
        if ($lhsStart === null) {
            return false;
        }
        $lhsRaw = \rtrim(\substr($expr, $lhsStart, $opPos - $lhsStart));
        if ($lhsRaw === '') {
            return false;
        }

        // Right operand: an explicit `(…)` group, or (for the binary tests) a
        // bare operand out to the next top-level boundary.
        $end = $p;
        if ($after === '(') {
            [$inner, $end] = $this->extractBalancedSegment($expr, $p);
            $argsPhp = $inner === '' ? '' : $this->processCondition($inner);
        } elseif ($spec['binary']) {
            $opEnd  = $this->scanOperandEnd($expr, $p);
            $rhsRaw = \trim(\substr($expr, $p, $opEnd - $p));
            if ($rhsRaw === '') {
                return false;
            }
            try {
                $argsPhp = $this->processCondition($rhsRaw);
            } catch (ClarityException) {
                return false;
            }
            $end = $opEnd;
        } else {
            $argsPhp = '';
        }

        // A failure to compile either operand means this was never a test.
        try {
            $lhsVal = $this->processCondition($lhsRaw);
        } catch (ClarityException) {
            return false;
        }

        // Replace the PHP already emitted for the left operand: it is either
        // reused as the call's first argument or replaced by a presence probe.
        // Trailing whitespace before the operator word is dropped first; PHP
        // output never ends in whitespace, so the suffix test is exact.
        $stripped = \rtrim($out);

        // `x not in y`: the `not` was already emitted as `!` (its keyword-map
        // expansion) while the scanner was before the `in`. It is part of the
        // test, so drop it along with the operand.
        if ($lower === 'in' && $negated && \str_ends_with($stripped, '!')) {
            $stripped = \rtrim(\substr($stripped, 0, -1));
        }

        if ($lhsVal !== '' && \str_ends_with($stripped, $lhsVal)) {
            $out = \substr($stripped, 0, \strlen($stripped) - \strlen($lhsVal));
        } else {
            $out = $stripped;
        }

        if ($spec['tolerates']) {
            // Absence-tolerant: a presence probe answers instead of a read.
            $probe = $this->buildPresenceTest($lhsVal, $spec['call']);
            $out .= $negated ? '!(' . $probe . ')' : '(' . $probe . ')';
        } else {
            $call = $this->buildCall($spec['call'], $argsPhp === '' ? [$lhsVal] : [$lhsVal, $argsPhp]);
            $out .= ($negated ? '!(' : '(') . $call . ')';
        }

        $i = $end;

        return true;
    }

    /**
     * Find the start of the last top-level operand within $expr[0..$opPos).
     *
     * Returns null when nothing operand-like precedes the operator. The scan
     * respects quoted strings and bracket nesting; at depth 0 an operand ends
     * after a binary-operator character or a binary-operator keyword. A chain
     * colon (`user:name`) and a property dot are NOT boundaries.
     */
    private function operatorTestLhsStart(string $expr, int $opPos): ?int
    {
        $boundary = 0;
        $i        = 0;
        $inSingle = false;
        $inDouble = false;
        $depth    = 0;

        while ($i < $opPos) {
            $ch = $expr[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $opPos) {
                $i += 2;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $i++;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $i++;
                continue;
            }
            if ($inSingle || $inDouble) {
                $i++;
                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                $i++;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                if ($depth > 0) {
                    $depth--;
                }
                $i++;
                continue;
            }

            if ($depth === 0) {
                // A binary-operator keyword (surrounded by word boundaries) ends
                // the operand before it; the next operand starts after it.
                if (self::isIdentifierStart($ch) && ($i === 0 || !$this->isIdentChar($expr[$i - 1]))) {
                    $w = $i;
                    while ($w < $opPos && self::isIdentifierChar($expr[$w])) {
                        $w++;
                    }
                    if (isset(self::OPERATOR_WORDS[\strtolower(\substr($expr, $i, $w - $i))])) {
                        $boundary = $w;
                        $i        = $w;
                        continue;
                    }
                    $i = $w;
                    continue;
                }

                if (\str_contains('+-*/%~!<>=&|^?,', $ch)) {
                    $boundary = $i + 1;
                    $i++;
                    continue;
                }
            }

            $i++;
        }

        $s = $boundary;
        while ($s < $opPos && \ctype_space($expr[$s])) {
            $s++;
        }

        return $s < $opPos ? $s : null;
    }

    /**
     * Scan forward from $start to the end of one bare operand: the first
     * top-level whitespace-delimited operator boundary, or the enclosing closer.
     */
    private function scanOperandEnd(string $expr, int $start): int
    {
        $len      = \strlen($expr);
        $i        = $start;
        $inSingle = false;
        $inDouble = false;
        $depth    = 0;

        while ($i < $len) {
            $ch = $expr[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $i += 2;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $i++;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $i++;
                continue;
            }
            if ($inSingle || $inDouble) {
                $i++;
                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                $i++;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
                $i++;
                continue;
            }

            if ($depth === 0 && \ctype_space($ch)) {
                $k = $i;
                while ($k < $len && \ctype_space($expr[$k])) {
                    $k++;
                }
                if ($k >= $len) {
                    return $len;
                }

                // A following operator word or operator character ends the
                // operand; otherwise the whitespace is a chain continuation.
                if (self::isIdentifierStart($expr[$k])) {
                    $w = $k;
                    while ($w < $len && self::isIdentifierChar($expr[$w])) {
                        $w++;
                    }
                    if (isset(self::OPERATOR_WORDS[\strtolower(\substr($expr, $k, $w - $k))])) {
                        return $i;
                    }
                    $i = $w;
                    continue;
                }
                if (\str_contains('+-*/%~!<>=&|^?,', $expr[$k])) {
                    return $i;
                }
            }

            $i++;
        }

        return $len;
    }

    /**
     * Compile an absence-tolerant test (`defined`, `null`, `empty`) over the RAW
     * source of the left operand.
     *
     * A value-passing callable cannot tell an absent name from a null one, and a
     * strict read throws before the test could answer, so these are compiled
     * from the text:
     *
     *   defined  ->  isset(<access>)            (presence)
     *   null     ->  !isset(<access>) || <value> === null
     *   empty    ->  !isset(<access>) || empty(<value>)
     *
     * The probe is a presence test that never warns, and the value read sits
     * behind a short-circuit `||`, so an absent name is never read.
     */
    private function buildPresenceTest(string $value, string $call): string
    {
        $probe = $this->presenceProbeFor($value);

        return match ($call) {
            'defined'  => $probe,
            // An absent name is null, so `is null` is true when the name is
            // missing OR present-and-null. `!probe` short-circuits the read.
            'is_null'  => '(!' . $probe . ' || (' . $value . ') === null)',
            'is_empty' => '(!' . $probe . ' || empty(' . $value . '))',
            default    => $probe,
        };
    }

    /**
     * An `isset()` probe for a compiled access expression, or a `true` fallback
     * for anything that is not a plain variable/property/key chain (which cannot
     * appear inside isset()).
     */
    private function presenceProbeFor(string $php): string
    {
        // A bare scope variable is PRESENT when the key exists, even if its
        // value is null — `array_key_exists` rather than `isset` is what makes
        // `x is defined` true for an explicitly-null scope entry.
        if (\preg_match('/^\$__c_va\[\'([^\']*)\'\]$/', $php, $m) === 1) {
            return 'array_key_exists(\'' . \addslashes($m[1]) . '\', $__c_va)';
        }

        // A longer chain: any intermediate absence makes it undefined, and a
        // null leaf is indistinguishable from an absent one, so isset() is the
        // right probe. Restricted to forms valid inside isset().
        $chain = '/^\$[A-Za-z_][A-Za-z0-9_]*(?:(?:\[(?:\'[^\']*\'|-?\d+)\]|->[A-Za-z_][A-Za-z0-9_]*))*$/';
        if (\preg_match($chain, $php) === 1) {
            return 'isset(' . $php . ')';
        }

        $chainScope = '/^\$__c_va\[(?:\'[^\']*\'|-?\d+)\](?:(?:\[(?:\'[^\']*\'|-?\d+)\]|->[A-Za-z_][A-Za-z0-9_]*))*$/';
        if (\preg_match($chainScope, $php) === 1) {
            return 'isset(' . $php . ')';
        }

        // Anything else is an expression; it has a value, so it is defined
        // unless that value is null.
        return '(' . $php . ') !== null';
    }

    /**
     * Convert parsed var-chain segments to PHP.
     *
     * Emission rules
     * --------------
     *  key   a:b        -> ['b']        (array key, strict)
     *  index a[i]       -> [$i]         (array index, strict)
     *  prop  a.b / a->b -> ->b          (object property, strict)
     *  dyn   a{k}       -> ->{$k}       (object dynamic property, strict)
     *
     * A segment flagged `optional` is emitted through the matching Access::*
     * guard, so an absent key/property yields null instead of raising. The
     * guard is only ever used for the OPTIONAL forms â€” a strict read is plain
     * PHP indexing/property access, which is what makes the strict contract
     * cost nothing at runtime.
     *
     * @param array<int,array{type:string,value:string,optional?:bool}> $segments
     */
    private function buildVarChainPhp(array $segments): string
    {
        if (empty($segments)) {
            return '';
        }

        $first = $segments[0]['value'];
        if (!\preg_match(self::IDENT_RE, $first)) {
            throw new ClarityException("Invalid identifier in var chain: {$first}");
        }

        $php = $this->rootPhp($first);
        $n   = \count($segments);

        for ($k = 1; $k < $n; $k++) {
            $php = $this->appendChainSegmentPhp($php, $segments[$k]);
        }

        return $php;
    }

    /**
     * Emit one chain continuation onto an existing PHP expression.
     *
     * A `prop`/`dyn` segment may carry an optional `call` (its raw argument
     * list) and `dynName` (the compiled PHP for a computed method name), which
     * together emit `->method(args)` / `->{$expr}(args)`.
     *
     * @param array{type:string,value:string,optional?:bool,call?:string,dynName?:string} $seg
     */
    private function appendChainSegmentPhp(string $php, array $seg): string
    {
        $optional = (bool) ($seg['optional'] ?? false);
        $value    = $seg['value'];

        if ($seg['type'] === 'key' || $seg['type'] === 'index') {
            $key = $seg['type'] === 'key'
                ? $this->staticKeyLiteral($value)
                : $this->compileIndexExpression($value);

            if (!$optional) {
                return $php . '[' . $key . ']';
            }

            // `?` guards the RECEIVER only â€” the read itself stays STRICT. An
            // absent receiver yields null, but a missing KEY still raises
            // "Undefined array key". `$receiver[$k] ?? null` would swallow both
            // and silently hide a mistyped key, which is the exact failure mode
            // the strict-access design exists to prevent.
            //
            // Two forms:
            //   bare root    -> (isset($a)           ? $a['k']           : null)
            //   anything else-> (($t = RECV) === null ? null : $t['k'])
            // The first is preferred where it is CORRECT: isset() reports an
            // absent ROOT as false without a warning, which is precisely the
            // tolerance asked for. It is wrong anywhere else, because isset()
            // would also swallow a missing property or an intermediate missing
            // key. The second form binds the receiver ONCE, so a nested optional
            // chain stays linear instead of duplicating its receiver (the
            // duplication is what made an earlier revision grow as 2^N â€” six
            // optional segments emitted 2 245 characters for one read). Both
            // forms keep the expression nestable.
            //
            // The guarded subject is the SHORT root (no `?? â€¦` tail): wrapping an
            // already-coalesced root in isset() is invalid PHP.
            $subject = $this->toLocalSubject($php);
            if (\preg_match(self::BARE_ROOT_RE, $subject)) {
                return '(isset(' . $subject . ') ? ' . $subject . '[' . $key . '] : null)';
            }

            $tmp = '$__c_g' . (++$this->guardCounter);
            return '((' . $tmp . ' = ' . $php . ') === null ? null : ' . $tmp . '[' . $key . '])';
        }

        // Property access (static or dynamic).
        $prop = $seg['type'] === 'dyn'
            ? '{' . $this->compileIndexExpression($value) . '}'
            : $value;

        if ($seg['type'] === 'prop' && !\preg_match(self::IDENT_RE, $value)) {
            throw new ClarityException("Invalid identifier in var chain: {$value}");
        }

        // Method call attached to the property read (open mode only).
        $call = isset($seg['call'])
            ? '(' . $this->compileMethodArgs((string) $seg['call']) . ')'
            : '';

        if (!$optional) {
            return $php . '->' . $prop . $call;
        }

        // `?->` tolerates a NULL receiver while leaving the PROPERTY READ strict,
        // so a present object lacking the property still raises "Undefined
        // property" â€” the feedback we want. It short-circuits the rest of the
        // chain and nests without any guard expression.
        //
        // An ABSENT root is a separate case: `$__c_va['a']?->b` still raises
        // "Undefined array key 'a'", so the receiver is guarded with isset()
        // there â€” the same tolerance the array side gets, which keeps `?.` and
        // `?:` consistent about an absent root. (Emitting `?? null` instead would
        // additionally swallow a missing property.)
        $subject = $this->toLocalSubject($php);
        if (\preg_match(self::BARE_ROOT_RE, $subject)) {
            return '(isset(' . $subject . ') ? ' . $subject . '->' . $prop . $call . ' : null)';
        }

        return $php . '?->' . $prop . $call;
    }

    /**
     * Compile a method-call argument list to PHP.
     *
     * Only reachable in open mode: method calls require both the `$` sigil and
     * the sandbox disabled.  Arguments are full Clarity expressions and named
     * arguments become PHP named arguments.
     */
    private function compileMethodArgs(string $argsRaw): string
    {
        if (\trim($argsRaw) === '') {
            return '';
        }
        return \implode(', ', $this->compileArgList($this->splitRespectingStrings($argsRaw, ',')));
    }

    /**
     * A static array key emitted as a PHP string-index literal.
     */
    private function staticKeyLiteral(string $key): string
    {
        if (!\preg_match(self::IDENT_RE, $key)) {
            throw new ClarityException("Invalid identifier in var chain: {$key}");
        }
        return "'" . \addslashes($key) . "'";
    }

    /**
     * Compile an index/property-key expression: a digit run stays literal,
     * anything else is compiled as a full Clarity expression.
     */
    private function compileIndexExpression(string $inner): string
    {
        if ($inner !== '' && \ctype_digit($inner)) {
            return $inner;
        }
        return $this->convertVarsAndOps($inner);
    }

    /**
     * Convert a parsed var-chain and memoize by raw chain string.
     *
     * @param array<int,array{type:string,value:string,optional?:bool}> $segments
     */
    private function varChainToPhpWithSegments(string $chain, array $segments): string
    {
        if (isset($this->varChainCache[$chain])) {
            return $this->varChainCache[$chain];
        }

        $php = $this->buildVarChainPhp($segments);
        $this->varChainCache[$chain] = $php;
        return $php;
    }

    /**
     * Like buildVarChainPhp() but uses the local-var PHP variable for the root segment.
     * Called when the chain root is a locally-bound loop variable (e.g. $item.foo).
     *
     * @param array<int,array{type:string,value:string,optional?:bool}> $segments
     */
    private function buildVarChainPhpWithLocalRoot(array $segments): string
    {
        $first = $segments[0]['value'];
        $php   = $this->localVars[$first]; // e.g. '$item'
        $n     = \count($segments);

        for ($k = 1; $k < $n; $k++) {
            $php = $this->appendChainSegmentPhp($php, $segments[$k]);
        }

        return $php;
    }

    /**
     * Emit a chain root: a PHP local in open mode, else a `$__c_va` lookup.
     *
     * Open mode seeds the render scope into locals, so the root IS the local.
     * No guard expression is emitted: an unknown name then raises PHP's own
     * "Undefined variable" warning, which {@see ClarityEngineTrait::buildErrorHandler()}
     * already maps to a ClarityException carrying the template line. That keeps
     * the strict-access contract identical to sandbox mode â€” and a `?? $__c_va[â€¦]`
     * fallback would silently suppress it, which is the failure strict access
     * exists to prevent.
     *
     * Read and write are therefore the SAME text (`$name`), so no lvalue flag is
     * needed in either mode.
     *
     * A name matching an enclosing lambda's PARAMETER is emitted bare as well:
     * that parameter is a real local of the enclosing closure, so reading it
     * through `$__c_va` would report it absent. See {@see $lambdaFrames}.
     */
    private function rootPhp(string $name): string
    {
        if ($this->isLambdaParam($name)) {
            return '$' . $name;
        }

        if (!$this->localRoots || $this->lambdaFrames !== []) {
            return '$__c_va[\'' . $name . '\']';
        }

        return '$' . $name;
    }

    /**
     * Is $name a parameter declared by an enclosing lambda (or the one being
     * compiled)?
     */
    private function isLambdaParam(string $name): bool
    {
        for ($i = \count($this->lambdaFrames) - 1; $i >= 0; $i--) {
            if (isset($this->lambdaFrames[$i][$name])) {
                return true;
            }
        }
        return false;
    }

    /**
     * `$__c_va['a']` â†’ `$a` in open mode.  The guard helpers only care about the
     * subject, so the emitted form must match what rootPhp() produces for a
     * bare root.  A root that is not a bare `$__c_va[...]` (an already-guarded
     * expression, or a nested chain) is returned unchanged.
     */
    private function toLocalSubject(string $php): string
    {
        // A lambda parameter is already a real local, so it is a valid
        // isset()/`?->` subject as emitted.
        if (\preg_match(self::BARE_PARAM_RE, $php) && $this->isLambdaParam(\substr($php, 1))) {
            return $php;
        }

        if (!$this->localRoots || $this->lambdaFrames !== []) {
            return $php;
        }

        return (string) \preg_replace(
            '/^\$__c_va\[\'([A-Za-z_][A-Za-z0-9_]*)\'\]$/',
            '$$1',
            $php
        );
    }

    /**
     * Convert a Clarity var-chain string to a PHP $__c_va[...] expression.
     *
     * Supports:
     *   foo           â†’ $__c_va['foo']
     *   foo.bar       â†’ $__c_va['foo']['bar']
     *   items[0]      â†’ $__c_va['items'][0]
     *   items[index]  â†’ $__c_va['items'][$__c_va['index']]
     *   a.b[c.d].e    â†’ $__c_va['a']['b'][$__c_va['c']['d']]['e']
     */
    public function varChainToPhp(string $chain): string
    {
        if ($chain === '') {
            return '';
        }

        // Whitespace between a value and its chain operator is not significant,
        // so `user . name` and `user.name` must not occupy separate cache
        // entries (and must not produce different PHP).
        $key = \preg_replace('/\s+/', '', $chain);

        // Memoization
        if (isset($this->varChainCache[$key])) {
            return $this->varChainCache[$key];
        }

        $parsed = $this->parseVarChainAt($chain, 0);
        if ($parsed === null) {
            return $chain;
        }

        // Keep legacy behavior for malformed tails by returning original chain
        // when parsing does not consume the full input.
        if ($parsed['end'] !== \strlen($chain)) {
            return $chain;
        }

        return $this->varChainToPhpWithSegments($key, $parsed['segments']);
    }

    /**
     * If $arg is a named argument of the form  identifier:expression  (where
     * : is not part of ::), return ['name'=>â€¦, 'expr'=>â€¦].
     * Returns null for ordinary positional arguments.
     */
    private function parseNamedArg(string $arg): ?array
    {
        // identifier followed by = that is not == ; also must not be !=, <=, >=
        if (\preg_match('/^\s*([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*:(?!:)(.+)$/s', $arg, $m)) {
            return ['name' => $m[1], 'expr' => \trim($m[2])];
        }
        return null;
    }

    /**
     * Compile a list of raw argument strings (already split on `,`) to PHP expressions.
     *
     * Named arguments (`identifier=expression`) are emitted as PHP named arguments
     * (`identifier: phpExpr`), letting PHP validate parameter names and arity at
     * runtime. This means function and filter signatures can change without requiring
     * template recompilation.
     *
     * Positional arguments are compiled as full Clarity expressions (pipelines and
     * nested function calls are supported).
     *
     * A positional argument after a named argument is rejected at compile time to
     * prevent generating syntactically invalid PHP.
     *
     * @param  string[] $argList Raw argument strings (already split on ',').
     * @return string[] Compiled PHP argument strings, ready to join with ', '.
     */
    private function compileArgList(array $argList): array
    {
        $result    = [];
        $seenNamed = false;
        foreach ($argList as $arg) {
            $arg = \trim($arg);
            if (\str_starts_with($arg, '...')) {
                throw new ClarityException('Spread operator is only allowed inside array and object literals.');
            }
            $named = $this->parseNamedArg($arg);
            if ($named !== null) {
                $seenNamed = true;
                $result[] = $named['name'] . ': ' . $this->processCondition($named['expr']);
            } else {
                if ($seenNamed) {
                    throw new ClarityException(
                        "Positional argument after named argument in argument list: '{$arg}'"
                    );
                }
                $result[] = $this->processCondition($arg);
            }
        }
        return $result;
    }

    /**
     * @param string[] $argList
     * @return array{0: string[], 1: array<string, string>}
     */
    private function compileFilterArguments(array $argList): array
    {
        $positional = [];
        $named      = [];
        $seenNamed  = false;

        foreach ($argList as $arg) {
            $arg = \trim($arg);
            if (\str_starts_with($arg, '...')) {
                throw new ClarityException('Spread operator is only allowed inside array and object literals.');
            }

            $parsedNamed = $this->parseNamedArg($arg);
            if ($parsedNamed !== null) {
                $seenNamed = true;
                $named[$parsedNamed['name']] = $this->processCondition($parsedNamed['expr']);
                continue;
            }

            if ($seenNamed) {
                throw new ClarityException(
                    "Positional argument after named argument in argument list: '{$arg}'"
                );
            }

            $positional[] = $this->processCondition($arg);
        }

        return [$positional, $named];
    }

    /**
     * map/filter/reduce accept a callable as their first argument. For reduce,
     * that callable may declare two comma-separated parameters on the left side
     * of the lambda arrow, so we merge split segments back into one callable arg.
     *
     * @return string[]
     */
    private function splitCallableFilterArgs(string $args): array
    {
        return $this->splitCallableArgs($args, false);
    }

    /**
     * Split an argument list in which the CALLABLE argument (a lambda or a
     * quoted filter reference) may span several comma-separated segments,
     * because `reduce` lambdas declare two parameters before the arrow
     * (`carry, item => …`).
     *
     * @param bool $valueFirst When true, the value leads the argument list and
     *                         the callable is the SECOND argument (call form
     *                         `map(items, x => …)`); when false the callable is
     *                         the first argument (filter form `items |> map(…)`).
     * @return string[]
     */
    private function splitCallableArgs(string $args, bool $valueFirst): array
    {
        // Split top-level commas (your existing helper)
        $parts = $this->splitRespectingStrings($args, ',');

        if ($parts === []) {
            return [];
        }

        // Call form: the value leads, so leave it untouched and merge the
        // lambda across the segments that follow it.
        $offset = $valueFirst ? 1 : 0;

        if ($valueFirst && $this->findLambdaArrow($parts[0]) !== false) {
            // `map(x => …, …)` — the value is itself a lambda (nonsensical but
            // must not be mistaken for the callable). Treat segment 0 as value.
            $offset = 1;
        }

        if ($offset >= \count($parts)) {
            return $parts;
        }

        // Case 1: the callable segment already contains =>
        if ($this->findLambdaArrow($parts[$offset]) !== false) {
            return $parts;
        }

        // Case 2: find the first later segment that contains =>
        $lambdaEnd = null;
        $count     = \count($parts);

        for ($i = $offset + 1; $i < $count; $i++) {
            if ($this->findLambdaArrow($parts[$i]) !== false) {
                $lambdaEnd = $i;
                break;
            }
        }

        // No lambda found → nothing to merge
        if ($lambdaEnd === null) {
            return $parts;
        }

        // Merge everything from the callable's start up to the lambda arrow
        // into one argument.
        $lambdaArg = '';
        for ($i = $offset; $i <= $lambdaEnd; $i++) {
            if ($i > $offset) {
                $lambdaArg .= ', ';
            }
            $lambdaArg .= \trim($parts[$i]);
        }

        // Build final argument list: everything before the callable (the value,
        // in the call form), then the merged callable, then the rest.
        $result = [];
        for ($i = 0; $i < $offset; $i++) {
            $result[] = $parts[$i];
        }
        $result[] = $lambdaArg;

        for ($i = $lambdaEnd + 1; $i < $count; $i++) {
            $result[] = $parts[$i];
        }

        return $result;
    }

    /**
     * Build a PHP filter call:  $__c_fn['name']($value, arg1, name2: arg2)
     *
     * For map / filter / reduce the first argument must be either:
     *   - a lambda expression:  param => expression
     *   - a filter reference:   'filterName' or "filterName"
     * Bare variable names are rejected at compile time.
     *
     * Named arguments (`identifier=expression`) are emitted directly as PHP named
     * arguments (`identifier: phpExpr`). PHP validates names and arity at runtime.
     *
     * @param string $filterSegment Clarity filter segment e.g. 'number(2)' or 'upper'
     * @param string $phpValue      Already-converted PHP expression for the input value.
     * @return string PHP call expression.
     */
    public function buildFilterCall(string $filterSegment, string $phpValue): string
    {
        $parsed = $this->tryParseFilterWithTrailing($filterSegment);
        if ($parsed === null) {
            throw new ClarityException("Invalid filter segment: '{$filterSegment}'");
        }
        $name     = $parsed['name'];
        $args     = $parsed['args'];
        $trailing = $parsed['trailing'];

        if ($name === 'raw') {
            $this->autoEscape = false;
            return $phpValue;
        }

        if ($args === '') {
            $argList = [];
        } elseif (isset(self::CALLABLE_ARG_FILTERS[$name])) {
            $argList = $this->splitCallableFilterArgs($args);
        } else {
            $argList = $this->splitRespectingStrings($args, ',');
        }

        $isCallableFilter = isset(self::CALLABLE_ARG_FILTERS[$name]);
        $isRegistered     = $isCallableFilter
            || ($this->registry !== null && ($this->registry->hasInlineFilter($name) || $this->registry->hasFilter($name)));

        // A name that is callable but NOT filterable (`context`, `include`,
        // `dump`, `dd`) was registered for call syntax only; its first parameter
        // is not a piped value. Rejecting it here turns `{{ x |> context }}`
        // into a clear compile-time error in BOTH modes — the runtime table no
        // longer carries these names, so without this guard the failure would
        // surface as an opaque "call to undefined array key" mid-render.
        if (
            $this->registry !== null
                && !$isRegistered
                && $this->registry->hasCallable($name)
                && !$this->registry->hasFilter($name)
        ) {
            throw new ClarityException(
                "'{$name}' is a function, not a filter; call it as {$name}(…)."
            );
        }

        // Registered filters win over PHP functions of the same name.  In
        // sandbox mode an unregistered name ALSO takes this path, exactly as
        // before: it compiles to a $__c_fn lookup and fails at runtime.
        if ($isRegistered || $this->sandboxMode) {
            $inlineCall = $this->buildInlineFilterCall($name, $phpValue, $argList);
            if ($inlineCall !== null) {
                return $trailing !== '' ? $inlineCall . $this->convertVarsAndOps($trailing) : $inlineCall;
            }

            $compiledArgs = [];

            if ($argList !== []) {
                if ($isCallableFilter) {
                    // map/filter/reduce: first arg is a lambda/filter-ref, rest are positional only.
                    foreach ($argList as $i => $arg) {
                        $arg = \trim($arg);
                        $compiledArgs[] = $i === 0
                            ? $this->compileCallableArg($arg, $name)
                            : $this->processCondition($arg);
                    }
                } else {
                    // Standard filter: emit args directly; named args become PHP named args.
                    $compiledArgs = $this->compileArgList($argList);
                }
            }

            // The filter form binds the piped value first (or, when the filter
            // declares a `valueParam`, in that parameter's slot — Phase 2).
            \array_unshift($compiledArgs, $phpValue);
            $call = $this->buildCall($name, $compiledArgs);

            if ($trailing !== '') {
                $call .= $this->convertVarsAndOps($trailing);
            }
            return $call;
        }

        // Open mode: a filter name that is not registered resolves to a PHP
        // function of the same name (Blade / Stempler / Plates parity).
        return $this->buildOpenFilterCall($name, $phpValue, $argList, $trailing);
    }

    /**
     * Emit a registry-dispatched call: `$__c_fn['name'](args)`.
     *
     * This is the ONE place a registered name becomes PHP. Both the pipe form
     * ({@see buildFilterCall()}) and the call-syntax form
     * ({@see buildFunctionCallInExpr()}) funnel through it after compiling
     * their own argument lists; the only difference is whether the piped value
     * leads the arguments (and where the value slot lands — see
     * {@see resolveInlineFilterSlots()}). There is one runtime table, not two:
     * a registered name is callable, and whether it may be piped is a separate,
     * compile-time question ({@see buildFilterCall()}).
     *
     * @param list<string> $compiledArgs Already-compiled PHP argument expressions.
     */
    private function buildCall(string $name, array $compiledArgs): string
    {
        $safeName = "'" . \addslashes($name) . "'";
        return '$__c_fn' . '[' . $safeName . '](' . \implode(', ', $compiledArgs) . ')';
    }

    /**
     * Compile a filter segment that resolves to a PHP function (open mode only).
     *
     * By default the piped value becomes the first argument:
     *   {{ 'ab' |> strtoupper }}               â†’ \strtoupper($value)
     *   {{ 'a' |> str_replace('a', 'b') }}     â†’ \str_replace($value, 'a', 'b')
     *
     * A single `_` placeholder in the argument list positions the value
     * explicitly, for functions whose value argument is not first:
     *   {{ 'k' |> array_key_exists(_, $arr) }} â†’ \array_key_exists($value, $arr)
     *
     * @param list<string> $argList Raw argument strings (already comma-split).
     * @param string       $phpValue Already-compiled PHP for the piped value.
     * @param string       $trailing Trailing comparison operator, if any.
     */
    private function buildOpenFilterCall(string $name, string $phpValue, array $argList, string $trailing): string
    {
        if (!$this->isFunctionCallAllowed($name)) {
            throw new ClarityException(
                "Function '{$name}' is blocked in open mode. Allow it by removing it from the deny-list."
            );
        }

        $fn = \ltrim($name, '\\');
        if (!\function_exists($fn)) {
            throw new ClarityException(
                "Unknown filter '{$name}': no filter is registered under that name and no PHP function '{$fn}()' exists."
            );
        }

        $callee = '\\' . $fn;

        if ($argList === []) {
            $call = $callee . '(' . $phpValue . ')';
            return $trailing !== '' ? $call . $this->convertVarsAndOps($trailing) : $call;
        }

        $prevInArgs = $this->inOpenFilterArgs;
        $prevValue  = $this->openFilterValue;
        $this->inOpenFilterArgs = true;
        $this->openFilterValue  = $phpValue;

        try {
            $outArgs   = [];
            $seenNamed = false;
            $valuePos  = null;

            foreach ($argList as $idx => $arg) {
                $arg = \trim($arg);
                if (\str_starts_with($arg, '...')) {
                    throw new ClarityException('Spread operator is only allowed inside array and object literals.');
                }

                if ($arg === '_') {
                    if ($valuePos !== null) {
                        throw new ClarityException("Filter '{$name}' may use the '_' placeholder only once.");
                    }
                    $valuePos = $idx;
                    $outArgs[$idx] = $phpValue;
                    continue;
                }

                $named = $this->parseNamedArg($arg);
                if ($named !== null) {
                    $seenNamed = true;
                    $outArgs[$idx] = $named['name'] . ': ' . $this->processCondition($named['expr']);
                    continue;
                }

                if ($seenNamed) {
                    throw new ClarityException(
                        "Positional argument after named argument in argument list: '{$arg}'"
                    );
                }

                $outArgs[$idx] = $this->processCondition($arg);
            }
        } finally {
            $this->inOpenFilterArgs = $prevInArgs;
            $this->openFilterValue  = $prevValue;
        }

        if ($valuePos === null) {
            \array_unshift($outArgs, $phpValue);
        }

        $call = $callee . '(' . \implode(', ', $outArgs) . ')';
        return $trailing !== '' ? $call . $this->convertVarsAndOps($trailing) : $call;
    }

    /**
     * When a filter segment contains a trailing comparison operator
     * (e.g. `length > 1`, `count == 0`, `upper != 'FOO'`) this method
     * extracts the filter name, optional balanced argument list, and the
     * trailing operator+operand as a raw Clarity expression.
     *
     * Returns null when the segment cannot be parsed this way.
     *
     * @return array{name:string,args:string,trailing:string}|null
     */
    private function tryParseFilterWithTrailing(string $filterSegment): ?array
    {
        $segment = \ltrim($filterSegment);

        // Must start with a valid identifier
        if (!\preg_match('/^([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $segment, $m)) {
            return null;
        }
        $name = $m[1];
        $rest = \ltrim(\substr($segment, \strlen($name)));

        // Optional balanced argument list in parentheses
        $args = '';
        if (($rest[0] ?? '') === '(') {
            [$args, $endPos] = $this->extractBalancedSegment($rest, 0);
            $rest = \ltrim(\substr($rest, $endPos));
        }

        // Plain filter â€” no trailing comparison operator
        if (\trim($rest) === '') {
            return ['name' => $name, 'args' => $args, 'trailing' => ''];
        }

        // Rest must be an operator followed by a non-empty operand.
        // `??` (null-coalescing) is included so a filter may be followed by a
        // fallback: `{{ items |> length ?? 0 }}`, `{{ x ?? 'fb' }}`.
        // Note: `??` catches a NULL RETURN only â€” it cannot catch an exception.
        if (!\preg_match('/^(===|!==|==|!=|>=|<=|<>|\?\?|>|<)(.+)$/s', $rest, $cm)) {
            return null;
        }

        return [
            'name'     => $name,
            'args'     => $args,
            'trailing' => ' ' . $cm[1] . ' ' . \ltrim($cm[2]),
        ];
    }

    /**
     * @param string[] $argList
     */
    private function buildInlineFilterCall(string $name, string $phpValue, array $argList): ?string
    {
        $definition = $this->registry->getInlineFilter($name);
        if ($definition === null) {
            return null;
        }

        [$positionalArgs, $namedArgs] = $this->compileFilterArguments($argList);

        if (($definition['variadic'] ?? false) === true) {
            return $this->buildInlineVariadicFilterCall($name, $definition['php'], $phpValue, $positionalArgs, $namedArgs);
        }

        $slots = $this->resolveInlineFilterSlots($name, $definition, $phpValue, $positionalArgs, $namedArgs);
        return $this->substituteInlineFilterTemplate($definition['php'], $slots);
    }

    /**
     * Compile an inline filter's CALL form: `name(a1, a2, …)`.
     *
     * The call form is derived from the same `php` template as the filter form;
     * only the SLOT ASSIGNMENT differs:
     *   - valueParam UNSET: `arg[0]` is the value (`{1}`), the rest fill `params`.
     *     This is byte-identical to the filter form, so `round(x, 2)` compiles
     *     exactly like `x |> round(2)`.
     *   - valueParam SET: every argument fills its `params` slot in order; the
     *     value param has no default (e.g. `join`), so calling it without
     *     supplying it is a loud compile error.
     *
     * Returns null when the name has no inline template (callable-only or
     * unregistered); the caller then falls back to `$__c_fn` dispatch or a
     * direct PHP call.
     *
     * @param string[] $argList Raw, comma-split argument strings.
     */
    private function buildInlineCallForm(string $name, array $argList): ?string
    {
        $definition = $this->registry->getInlineFilter($name);
        if ($definition === null) {
            return null;
        }

        [$positionalArgs, $namedArgs] = $this->compileFilterArguments($argList);

        if (($definition['variadic'] ?? false) === true) {
            // Value-led variadic: the first argument is the value (the format
            // string), the rest are the variadic arguments.
            if ($positionalArgs === [] || $namedArgs !== []) {
                throw new ClarityException(
                    "Function '{$name}' requires a positional value argument."
                );
            }
            $value = $positionalArgs[0];
            $rest  = \array_slice($positionalArgs, 1);

            return $this->buildInlineVariadicFilterCall($name, $definition['php'], $value, $rest, []);
        }

        $params     = $definition['params'] ?? [];
        $defaults   = $definition['defaults'] ?? [];
        $valueParam = $definition['valueParam'] ?? null;

        if ($valueParam === null) {
            // Value-led: the first argument is the value; the rest are params.
            if ($positionalArgs === [] && $namedArgs === []) {
                throw new ClarityException(
                    "Function '{$name}' requires at least the value argument."
                );
            }

            $value = $positionalArgs[0] ?? null;
            if ($value === null) {
                throw new ClarityException(
                    "Function '{$name}' cannot take the value as a named argument."
                );
            }
            $restPositional = \array_slice($positionalArgs, 1);

            $slots    = [1 => $value];
            $assigned = [];
            foreach ($restPositional as $index => $phpArg) {
                if (!isset($params[$index])) {
                    throw new ClarityException(
                        "Function '{$name}' received too many positional arguments."
                    );
                }
                $slots[$index + 2] = $phpArg;
                $assigned[$params[$index]] = true;
            }
            $this->fillDefaultSlots($name, 'Function', $params, $defaults, 2, $slots, $assigned, $namedArgs);

            return $this->substituteInlineFilterTemplate($definition['php'], $slots);
        }

        // Params-led: every argument is a declared param (value included).
        $slots    = [];
        $assigned = [];
        foreach ($positionalArgs as $index => $phpArg) {
            if (!isset($params[$index])) {
                throw new ClarityException(
                    "Function '{$name}' received too many positional arguments."
                );
            }
            $slots[$index + 1] = $phpArg;
            $assigned[$params[$index]] = true;
        }

        if (\array_key_exists($valueParam, $namedArgs)) {
            $valueIndex = \array_search($valueParam, $params, true);
            if ($valueIndex === false) {
                throw new ClarityException(
                    "Function '{$name}' declares valueParam '{$valueParam}', which is not one of its params."
                );
            }
            $slots[$valueIndex + 1] = $namedArgs[$valueParam];
            $assigned[$valueParam] = true;
            unset($namedArgs[$valueParam]);
        }

        $this->fillDefaultSlots($name, 'Function', $params, $defaults, 1, $slots, $assigned, $namedArgs);

        return $this->substituteInlineFilterTemplate($definition['php'], $slots);
    }

    /**
     * Fill unfilled `params` slots from `$namedArgs` or `$defaults`, throwing
     * on an unknown name, a duplicate, or a missing required argument.
     *
     * @param list<string>          $params
     * @param array<string, string> $defaults
     * @param array<int, string>    $slots    Modified in place.
     * @param array<string, true>   $assigned Modified in place.
     * @param array<string, string> $namedArgs
     */
    private function fillDefaultSlots(
        string $name,
        string $form,
        array $params,
        array $defaults,
        int $base,
        array &$slots,
        array &$assigned,
        array $namedArgs,
    ): void {
        foreach ($namedArgs as $paramName => $phpArg) {
            $paramIndex = \array_search($paramName, $params, true);
            if ($paramIndex === false) {
                throw new ClarityException(
                    "Unknown named argument '{$paramName}' for {$form} '{$name}'."
                );
            }
            if (isset($assigned[$paramName])) {
                throw new ClarityException(
                    "{$form} '{$name}' received '{$paramName}' more than once."
                );
            }
            $slots[$paramIndex + $base] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($params as $index => $paramName) {
            $slotIndex = $index + $base;
            if (isset($slots[$slotIndex])) {
                continue;
            }
            if (isset($defaults[$paramName])) {
                $slots[$slotIndex] = $defaults[$paramName];
                continue;
            }
            throw new ClarityException(
                "Missing required argument '{$paramName}' for {$form} '{$name}'."
            );
        }
    }

    /**
     * Resolve a filter template's numeric slots for the FILTER form.
     *
     * Slot model
     * ----------
     *   valueParam UNSET : `{1}` = the piped value, `{2}`, `{3}`, … = `params`.
     *   valueParam SET   : `{1}`, `{2}`, … = `params` in declared order, and the
     *                      piped value lands on the slot of the named param
     *                      (so `join`'s `{2}` is its `array`, `date`'s `{2}` is
     *                      its `date`). This lets a template be written in the
     *                      same order as the PHP call it compiles to.
     *
     * @param array{php?: string, params?: string[], defaults?: array<string, string>, variadic?: bool, valueParam?: string} $definition
     * @param string[] $positionalArgs
     * @param array<string, string> $namedArgs
     * @return array<int, string>
     */
    private function resolveInlineFilterSlots(string $filterName, array $definition, string $phpValue, array $positionalArgs, array $namedArgs): array
    {
        $params     = $definition['params'] ?? [];
        $defaults   = $definition['defaults'] ?? [];
        $valueParam = $definition['valueParam'] ?? null;

        if ($valueParam === null) {
            $slotBase  = 2;
            $valueSlot = 1;
        } else {
            $valueIndex = \array_search($valueParam, $params, true);
            if ($valueIndex === false) {
                throw new ClarityException(
                    "Filter '{$filterName}' declares valueParam '{$valueParam}', which is not one of its params."
                );
            }
            $slotBase  = 1;
            $valueSlot = $valueIndex + 1;
        }

        $slots    = [$valueSlot => $phpValue];
        $assigned = [];

        foreach ($positionalArgs as $index => $phpArg) {
            if (!isset($params[$index])) {
                throw new ClarityException(
                    "Filter '{$filterName}' received too many positional arguments."
                );
            }

            $paramName = $params[$index];
            $slotIndex = $index + $slotBase;
            if ($slotIndex === $valueSlot) {
                throw new ClarityException(
                    "Filter '{$filterName}' received a positional argument for '{$paramName}', which receives the piped value."
                );
            }

            $slots[$slotIndex] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($namedArgs as $paramName => $phpArg) {
            $paramIndex = \array_search($paramName, $params, true);
            if ($paramIndex === false) {
                throw new ClarityException(
                    "Unknown named argument '{$paramName}' for filter '{$filterName}'."
                );
            }
            if ($paramName === $valueParam) {
                throw new ClarityException(
                    "Filter '{$filterName}' cannot bind '{$paramName}': it receives the piped value."
                );
            }
            if (isset($assigned[$paramName])) {
                throw new ClarityException(
                    "Filter '{$filterName}' received '{$paramName}' more than once."
                );
            }

            $slots[$paramIndex + $slotBase] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($params as $index => $paramName) {
            $slotIndex = $index + $slotBase;
            if (isset($slots[$slotIndex])) {
                continue;
            }
            if (isset($defaults[$paramName])) {
                $slots[$slotIndex] = $defaults[$paramName];
                continue;
            }
            throw new ClarityException(
                "Missing required argument '{$paramName}' for filter '{$filterName}'."
            );
        }

        return $slots;
    }

    /**
     * @param string[] $positionalArgs
     * @param array<string, string> $namedArgs
     */
    private function buildInlineVariadicFilterCall(string $filterName, string $name, string $phpValue, array $positionalArgs, array $namedArgs): ?string
    {
        if ($namedArgs !== []) {
            $firstNamedArg = \array_key_first($namedArgs);
            throw new ClarityException(
                "Unknown named argument '{$firstNamedArg}' for filter '{$filterName}'."
            );
        }

        $pieces = ["(string) ({$phpValue})"];
        foreach ($positionalArgs as $phpArg) {
            $pieces[] = '(' . $phpArg . ')';
        }

        return $name . '(' . \implode(', ', $pieces) . ')';
    }

    /**
     * @param array<int, string> $slots
     */
    private function substituteInlineFilterTemplate(string $template, array $slots): string
    {
        return (string) \preg_replace_callback(
            '/\{(\d+)\}/',
            static function (array $matches) use ($slots): string {
                $slotIndex = (int) $matches[1];
                if (!isset($slots[$slotIndex])) {
                    throw new ClarityException("Missing filter argument {{$slotIndex}}.");
                }

                return '(' . $slots[$slotIndex] . ')';
            },
            $template
        );
    }

    /**
     * Compile the callable argument accepted by map / filter / reduce.
     *
     * Accepted forms:
     *   param => expression                  single-parameter lambda
     *   acc, item => expression             explicit two-parameter reduce lambda
     *   'filterName' / "filterName"         reference to a registered filter or
     *                                        to an inline built-in filter, which
     *                                        is compiled into a closure (see
     *                                        {@see shouldInlineCallableFilterReference()})
     *
     * Anything else (bare variable names, function calls, â€¦) is rejected.
     */
    private function compileCallableArg(string $arg, string $filterName): string
    {
        // â”€â”€ Filter reference: 'name' or "name" â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $trimmed = \trim($arg);
        if (\strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            $last  = $trimmed[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $refName = \substr($trimmed, 1, -1);
                if (!\preg_match(self::IDENT_RE, $refName)) {
                    throw new ClarityException(
                        "Filter reference must be a plain identifier, got: '{$refName}'"
                    );
                }

                if ($this->shouldInlineCallableFilterReference($refName)) {
                    // `reduce` needs a BINARY callable — carry, item. An inline
                    // filter is a unary template with one `{1}` slot, so there is
                    // no way to express "combine the carry with the element".
                    // PHP would bind the ACCUMULATOR to that slot, the closure
                    // would ignore the element, and reduce would silently return
                    // the initial value. Reject it instead of compiling a
                    // no-op.
                    if ($filterName === 'reduce') {
                        throw new ClarityException(
                            "The 'reduce' filter needs a two-parameter lambda (e.g. 'carry, item => carry + item');"
                                . " the inline filter '{$refName}' is unary and cannot combine elements."
                        );
                    }

                    return $this->buildInlineCallableFilterReference($refName);
                }

                // A registered filter (inline template or runtime callable) is
                // dispatched through the ONE runtime table, `$__c_fn`. Whether a
                // name may be used as a filter at all is decided HERE, at compile
                // time, so the runtime table stays a plain callable map.
                $isRegisteredFilter = $this->registry !== null
                    && ($this->registry->hasFilter($refName) || $this->registry->hasInlineFilter($refName));

                if ($isRegisteredFilter) {
                    return "\$__c_fn['" . \addslashes($refName) . "']";
                }

                // Open mode: a quoted name that is not a registered filter may
                // name a PHP function (Blade parity).
                if (!$this->sandboxMode && $this->registry !== null) {
                    if (!$this->isFunctionCallAllowed($refName)) {
                        throw new ClarityException(
                            "Function '{$refName}' is blocked in open mode. Allow it by removing it from the deny-list."
                        );
                    }
                    if (!\function_exists(\ltrim($refName, '\\'))) {
                        throw new ClarityException(
                            "Unknown callable '{$refName}': not a registered filter and no PHP function exists."
                        );
                    }
                    return "'" . \addslashes(\ltrim($refName, '\\')) . "'";
                }

                // Sandbox: an unknown reference is a compile-time error — the
                // callable-injection guard rejects anything not a filter.
                throw new ClarityException(
                    "Filter reference '{$refName}' is not a filter"
                        . ($this->registry !== null && $this->registry->hasCallable($refName)
                            ? "; it is a function, not a filter."
                            : " and no filter is registered under that name.")
                );
            }
        }

        // â”€â”€ Lambda: param => expression / acc, item => expression â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $arrowPos = $this->findLambdaArrow($arg);
        if ($arrowPos !== false) {
            return $this->compileLambda($arg, $arrowPos, $filterName);
        }

        throw new ClarityException(
            "The '{$filterName}' filter requires a lambda (e.g. 'item => item.name') "
                . "or a filter reference (e.g. '\"upper\"'), got: '{$arg}'"
        );
    }

    /**
     * Decide how a quoted filter reference (`map(items, "upper")`) compiles.
     *
     * An INLINE-only filter has no runtime entry — it is codegen, not a
     * callable — so it must be compiled into a closure right here, exactly as
     * the compiler does for any other inline filter use. Emitting a registry
     * lookup for it would produce code that can never resolve. Callable
     * filters (`slug`) are the opposite: they live in the runtime table and are
     * dispatched as `$__c_fn['slug']`, which keeps the registry as the one
     * source of callables and avoids re-emitting an equivalent closure.
     */
    private function shouldInlineCallableFilterReference(string $referenceName): bool
    {
        return $this->registry !== null && $this->registry->hasInlineFilter($referenceName);
    }

    private function buildInlineCallableFilterReference(string $referenceName): string
    {
        $inlineCall = $this->buildInlineFilterCall($referenceName, '$__c_val', []);
        if ($inlineCall === null) {
            throw new ClarityException("Unknown inline filter reference: '{$referenceName}'");
        }

        return "static fn(mixed \$__c_val): mixed => {$inlineCall}";
    }

    /**
     * Find the position of the first '=>' operator that is not inside a quoted
     * string. Returns false if none is found.
     */
    private function findLambdaArrow(string $s): int|bool
    {
        $len      = \strlen($s);
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < $len - 1; $i++) {
            $ch = $s[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
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

            if (!$inSingle && !$inDouble && $ch === '=' && $s[$i + 1] === '>') {
                return $i;
            }
        }

        return false;
    }

    /**
     * Compile a Clarity lambda expression to a PHP static closure.
     *
     * Syntax:
     *   param => body_expression
     *   acc, item => body_expression    (reduce only)
     *
     * - Each lambda parameter becomes a PHP closure parameter with the same name.
     * - 'map' and 'filter' require exactly one parameter.
     * - 'reduce' requires exactly two parameters so you can write:
     *       carry, item => carry + item
     * - The body is compiled as a full Clarity expression (including filter
     *   pipelines) with the parameter name(s) treated as local variables,
     *   while all other identifiers are resolved from the captured $__c_va.
     * - $__c_va is always captured; $__c_fn and $__c_sv are added to the `use`
     *   clause only when the compiled body actually references them (they are
     *   render-frame locals, so an uncaptured reference would fatal at render
     *   time — see {@see $captures} below).
     *
     * @param string $arg      The full lambda string (e.g. 'item => item.name').
     * @param int    $arrow    Position of '=>' in $arg.
     * @param string $filterName The callable filter currently being compiled.
     */
    private function compileLambda(string $arg, int $arrow, string $filterName): string
    {
        $paramList = \trim(\substr($arg, 0, $arrow));
        $body      = \trim(\substr($arg, $arrow + 2));

        $first = \strstr($paramList, ',', true);
        if ($first === false) {
            $first = $paramList;
        } else {
            $second = \ltrim(\substr($paramList, \strpos($paramList, ',') + 1));
            if (!\preg_match(self::IDENT_RE, $second)) {
                throw new ClarityException("Invalid lambda parameter: '{$second}'");
            }
        }
        if (!\preg_match(self::IDENT_RE, $first)) {
            throw new ClarityException("Invalid lambda parameter: '{$first}'");
        }

        // Compile the body as a full Clarity expression (handles |> pipelines).
        //
        // The parameter frame is pushed FIRST, so during compilation a root that
        // names a parameter of THIS lambda — or of any enclosing one — is emitted
        // as the bare `$name` by rootPhp(). No post-hoc string substitution is
        // needed, and a NESTED lambda's inner body can reference the outer
        // lambda's parameter directly, because that name is still on the stack.
        //
        // The stack (not a boolean) is what makes nesting work: `inLambda` alone
        // only said "not the render scope", which turned an outer parameter into
        // an absent `$__c_va[...]` read.
        $params = [$first => true];
        if (isset($second)) {
            $params[$second] = true;
        }

        $this->lambdaFrames[] = $params;
        try {
            $phpBody = $this->processCondition($body);
        } finally {
            \array_pop($this->lambdaFrames);
        }

        $signature = "mixed \${$first}";

        if ($filterName === 'reduce') {
            if (!isset($second)) {
                throw new ClarityException(
                    "The 'reduce' filter lambda must declare two parameters separated by a comma "
                        . "(e.g. 'acc, item => acc + item'), got: '{$paramList}'"
                );
            }
            $signature .= ", mixed \${$second}";
        } elseif (isset($second)) {
            throw new ClarityException(
                "The '{$filterName}' filter lambda must declare only one parameter, got: '{$paramList}'"
            );
        }

        // The closure captures $__c_va unconditionally (outer template variables
        // are always read through it). Two further groups of names are captured
        // only when the compiled body actually references them:
        //
        //   • $__c_fn / $__c_sv — render-frame LOCALS, not parameters. Without
        //     the capture a body reaching the callable registry or a service
        //     fatals with "Variable $__c_fn is not defined".
        //   • an ENCLOSING lambda's parameter — a local of the closure that
        //     declared it. A nested lambda referencing it compiles to a bare
        //     `$name` (see rootPhp()), and PHP only binds that from an explicit
        //     `use (...)`; without it the reference is undefined at render time.
        //
        // $this->lambdaFrames now holds only the ENCLOSING frames (this lambda's
        // own frame was popped above), so its parameters are exactly the ones
        // that need capturing.
        $captures = ['$__c_va'];
        foreach (['$__c_fn', '$__c_sv'] as $internal) {
            if (\str_contains($phpBody, $internal)) {
                $captures[] = $internal;
            }
        }
        foreach ($this->lambdaFrames as $frame) {
            foreach ($frame as $enclosing => $_) {
                if (isset($params[$enclosing]) || \in_array('$' . $enclosing, $captures, true)) {
                    continue;
                }
                // Word-boundary match so `$c` does not match `$count`, and `$x`
                // does not match `$__c_va['x']` (the quote is not a word char).
                if (\preg_match('/\$' . \preg_quote($enclosing, '/') . '\b/', $phpBody)) {
                    $captures[] = '$' . $enclosing;
                }
            }
        }
        $useClause = 'use (' . \implode(', ', $captures) . ')';

        return "static function({$signature}) {$useClause}: mixed { return {$phpBody}; }";
    }

    /**
     * Emit the lookup for `${expr}` / `$$name`: read the variable whose NAME is
     * produced by a runtime expression.
     *
     * The lookup uses the SAME variable model as a literal `{{ name }}`:
     *
     *  â€¢ outside a loop  â†’ `$__c_va[$name]`
     *  â€¢ inside a loop   â†’ loop locals first (they are real PHP locals, not
     *                      scope entries), then `$__c_va[$name]`
     *
     * Presence is tested with `array_key_exists`, NOT `isset`: a NULL value is
     * PRESENT, exactly as a literal `{{ name }}` treats it (isset would report it
     * absent and trigger the strict throw).
     *
     * Security: the name only ever indexes `$__c_va` or selects among the
     * compile-time known loop locals (the map literal).  It is never emitted as
     * a PHP dynamic variable, so it can reach neither a superglobal nor an engine
     * internal.  `__c_`-prefixed names are simply absent from the scope.
     *
     * Absent names are STRICT (a line-numbered ClarityException) unless a `??`
     * follows the lookup, in which case the absent branch is `null` so the
     * operator supplies the fallback â€” mirroring a literal `{{ name ?? 'x' }}`.
     *
     * @param bool $coalesces Whether the character after the lookup is `??`.
     */
    private function buildDynamicLookup(string $namePhp, bool $coalesces): string
    {
        // When a `??` follows, the NAME expression is made null-safe too, so
        // `${ref} ?? 'x'` behaves like a literal `{{ name ?? 'x' }}`: absence in
        // either the name or the looked-up variable yields the fallback.  Without
        // this, an absent `ref` would warn while the name is evaluated â€” and that
        // happens INSIDE the guarding ternary, so the outer `??` would not
        // suppress it.
        $nameExpr = $coalesces
            ? '(string) ((' . $namePhp . ') ?? \'\')'
            : '(string) (' . $namePhp . ')';

        // The guard always assigns `$__c_tmp = (string)(name)` before testing, so
        // the miss branch can reuse it rather than evaluating the expression twice.
        $miss = $coalesces
            ? 'null'
            : 'throw new \\Clarity\\ClarityException("Undefined variable: " . $__c_tmp)';

        if (!empty($this->localVars)) {
            $entries = [];
            foreach ($this->localVars as $tplName => $phpVar) {
                $entries[] = \var_export($tplName, true) . ' => 1';
            }
            $mapLiteral = '[' . \implode(', ', $entries) . ']';

            // The dynamic read `\${$__c_tmp}` is only reached when the name is
            // one of the compile-time known loop locals (the map literal), so it
            // can select only among variables the compiler bound itself.
            // The whole ternary is parenthesised so a following `??` binds to the
            // lookup RESULT, not into the else-branch.
            return '((\array_key_exists($__c_tmp = ' . $nameExpr . ', ' . $mapLiteral . ')'
                . ' ? ${$__c_tmp}'
                . ' : (array_key_exists($__c_tmp, $__c_va) ? $__c_va[$__c_tmp] : (' . $miss . '))))';
        }

        return '((array_key_exists($__c_tmp = ' . $nameExpr . ', $__c_va) ? $__c_va[$__c_tmp] : (' . $miss . ')))';
    }
}
