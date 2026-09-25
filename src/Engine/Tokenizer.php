<?php
namespace Clarity\Engine;

use Clarity\ClarityException;

/**
 * Splits a Clarity template source into typed segments and processes
 * DSL expressions into PHP-ready strings.
 *
 * Segment types (constants on this class)
 * ----------------------------------------
 * TEXT        – raw HTML/text passed through verbatim
 * OUTPUT_TAG  – {{ expression }} – rendered (auto-escaped by default)
 * BLOCK_TAG   – {% directive %}  – control structures / directives
 *
 * Expression processing
 * ---------------------
 * The tokenizer converts Clarity expression syntax to valid PHP so the
 * Compiler can embed it directly.  PHP itself validates the resulting
 * syntax when the compiled class file is first loaded, so we intentionally
 * do not perform a full grammar check here.
 *
 * Conversions performed
 * • var-chains (foo.bar[x].baz) → $__va['foo']['bar'][$__va['x']]['baz']
 * • logical operators:  and → &&,  or → ||,  not → !
 * • bitwise operators:  bor → |,  band → &,  bxor → ^,  bnot → ~,  blsh → <<,  brsh → >>
 * • concat operator:    ~   → .
 * • all other tokens pass through unchanged (PHP validates them)
 *
 * Pipeline (| or |>)
 * • Both | and |> act as the filter pipe operator (| is normalized to |> before processing)
 * • Each step after the pipe is a filter: name  or  name(arg1, arg2)
 * • Arguments are themselves processed as expressions
 * • Result: nested $this->__fl['name']($this->__fl['name']($expr, arg), …)
 *
 * Named arguments
 * • Clarity uses `=` syntax: filter(precision=2) or fn(from="system")
 * • These are emitted directly as PHP named arguments: `precision: 2`, `from: 'system'`
 * • PHP itself validates parameter names and arity at runtime — no reflection needed
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
     * A BARE root dereference: `$__va['name']`. These are special because
     * isset() on them reports an ABSENT ROOT as false with no warning, which is
     * exactly the tolerance `?` promises.
     *
     * Deliberately excludes anything with `->` or a second key: isset() would
     * suppress a missing PROPERTY or an intermediate missing KEY too, turning a
     * mistyped strict segment into a silent null.
     */
    private const BARE_ROOT_RE = '/^\$__va\[\'[A-Za-z_][A-Za-z0-9_]*\'\]$/';

    /**
     * Monotonic counter for temporaries emitted by optional array guards, so two
     * guards in one expression never collide. Per-instance, compile-time only.
     */
    private int $guardCounter = 0;
    private array $varChainCache = [];

    /**
     * Compile-time local variable context: templateVarName → PHP variable string.
     * Set by the Compiler when entering/leaving loop scopes so that expressions
     * inside loops resolve loop variables to direct PHP local variables instead
     * of $__va['name'] lookups.
     *
     * @var array<string, string>
     */
    private array $localVars = [];
    private const IDENT_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const CHAIN_RE = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/';

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

    public function setRegistry(Registry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Update the compile-time local variable context.
     *
     * Called by the Compiler when entering or exiting a loop scope so that
     * variable resolution inside the loop uses direct PHP local variables
     * ($__lv_item_0) rather than $__va['item'] array lookups.
     *
     * @param array<string, string> $localVars  templateVarName → PHP variable string
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

        $segments  = [];
        $sourceLen = \strlen($source);
        $line      = 1;
        $pos       = 0;

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

            if ($tagPos > $pos) {
                $text = \substr($source, $pos, $tagPos - $pos);
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

            $segments[] = [
                self::KEY_TYPE    => $type,
                self::KEY_CONTENT => \trim(\substr($source, $innerStart, $end - 2 - $innerStart)),
                self::KEY_LINE    => $line,
            ];

            $line += \substr_count(\substr($source, $tagPos, $end - $tagPos), "\n");
            $pos = $end;
        }

        if ($pos < $sourceLen) {
            $rest = \substr($source, $pos);
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

        // Wrap in filter calls (innermost first → outermost last)
        foreach ($filters as $filterSegment) {
            $phpExpr = $this->buildFilterCall($filterSegment, $phpExpr);
        }

        if ($this->autoEscape) {
            $phpExpr = match ($this->escapeContext) {
                'js'    => '\\json_encode(' . $phpExpr . ', 271)', // HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT|UNESCAPED_UNICODE
                'css'   => '(string)(' . $phpExpr . ')',           // raw — CSS values are not HTML-escaped
                default => "\\htmlspecialchars((string)({$phpExpr}), 11, 'UTF-8')",
            };
        }

        return $phpExpr;
    }

    /**
     * Convert a Clarity expression without pipeline — used for control
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
     * Convert a Clarity variable chain to its PHP $__va[...] equivalent.
     * Used for the left-hand side of {% set var = ... %}.
     *
     * @param string $var Clarity variable name (e.g. 'user.name', 'items[0]').
     * @return string PHP lvalue (e.g. '$__va[\'user\'][\'name\']').
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
     *   ||  → passed through unchanged  (PHP logical OR)
     *   |>  → passed through unchanged  (already the canonical pipe)
     *   |   → rewritten to |>           (Twig/Svelte-compatible shorthand)
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

            // Pipe handling — only at top level
            if ($depth === 0 && $ch === '|') {
                $next = $expr[$i + 1] ?? '';
                if ($next === '|') {
                    // || → logical OR, pass through
                    $out .= '||';
                    $i += 2;
                    continue;
                }
                if ($next === '>') {
                    // |> → already canonical, pass through
                    $out .= '|>';
                    $i += 2;
                    continue;
                }
                // bare | → normalize to |>
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
     * simply explode — we split only on |> that are not inside quotes.
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
     * 1. Replacing var-chains with $__va[...] accesses
     * 2. Replacing logical/string operators with PHP equivalents
     * 3. Rejecting function-call syntax: any identifier followed by '(' throws
     *    a ClarityException at compile time — use the |> filter pipeline instead.
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
        // error — which happens when the generated class is loaded and cannot be
        // caught — into a normal compile error with a line number.
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
                        $ternarySeen       = true;
                        $ternaryPhases[]   = 'then';
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
            //   • `?:` glued both sides is the optional-key operator.
            //   • a colon glued to a following identifier would read as a key
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
                // fresh scope where a new ternary is legal — this call recurses
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
            // nullsafe syntax — a leak the sigil rule exists to prevent — and a
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
                if (!(\ctype_alpha($next) || $next === '_')) {
                    throw new ClarityException(
                        "Direct PHP variable access ('\$') is not allowed in Clarity expressions; "
                            . "use a variable name after the sigil (\$name) or dot-notation (name.field)."
                    );
                }

                $parsed = $this->parseVarChainAt($expr, $sigilStart, true, $ternarySeen);
                if ($parsed === null) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                $i        = $parsed['end'];
                $segments = $parsed['segments'];
                $token    = \substr($expr, $sigilStart, $i - $sigilStart);

                // Property access can never be a method call.
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
            if (\ctype_alpha($ch) || $ch === '_') {
                $start = $i;

                // --- Performance: try the cache with just the raw identifier first.
                // For simple single-word names (the dominant case) this avoids calling
                // parseVarChainAt() at all.  We peek ahead to find the identifier end,
                // check the cache, and only fall through to full parsing on a miss or
                // when the identifier is followed by '.' or '['.
                $idEnd = $i + 1;
                while ($idEnd < $len && (\ctype_alnum($expr[$idEnd]) || $expr[$idEnd] === '_')) {
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

                if (
                    $contChar !== '.' && $contChar !== '['
                        && $contChar !== '{' && $contChar !== ':'
                        && $contChar !== '?' && $contTwo !== '->'
                ) {
                    // Plain identifier — may be a keyword or a cacheable single-segment chain
                    $token = \substr($expr, $start, $idEnd - $start);
                    $i     = $idEnd;

                    $prevChar = ($start - 1 >= 0) ? $expr[$start - 1] : null;
                    $nextChar = $nextAfterIdent !== '' ? $nextAfterIdent : null;
                    $prevIsId = $prevChar !== null && (\ctype_alnum($prevChar) || $prevChar === '_');
                    $nextIsId = $nextChar !== null && (\ctype_alnum($nextChar) || $nextChar === '_');
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
                        $context = \substr($expr, \max(0, $start - 10), \min(60, $len - $start + 10));
                        throw new ClarityException("Call to unregistered function in context '{$context}'. Register it via addFunction() first.");
                    }

                    // Check local vars (loop variables) before the cache: a locally-bound
                    // variable must resolve to its PHP local var, not to $__va['name'].
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
                        $out .= $php;
                    }
                    continue;
                }

                // Identifier followed by a chain continuation — full chain parsing required.
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
                        $out .= $this->varChainToPhpWithSegments($token, $segments);
                    }
                    continue;
                }

                $i     = $parsed['end'];
                $token = \substr($expr, $start, $i - $start);

                // Dot/bracket chains cannot be function calls — always forbidden.
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

                if ($nameStart >= $len || !(\ctype_alpha($expr[$nameStart]) || $expr[$nameStart] === '_')) {
                    throw new ClarityException(
                        "Property access operator '.' must be followed by a property name in '{$expr}'."
                    );
                }

                $nameEnd = $nameStart + 1;
                while ($nameEnd < $len && (\ctype_alnum($expr[$nameEnd]) || $expr[$nameEnd] === '_')) {
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
                while ($nameEnd < $len && (\ctype_alnum($expr[$nameEnd]) || $expr[$nameEnd] === '_')) {
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
     * Generated code: $this->__fn['name']($phpArg1, name2: $phpArg2, ...)
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
                return ['$__va', $i];
            case 'include':
                $this->autoEscape = false;
                break;
        }

        $safeName = "'" . \addslashes($name) . "'";

        // Context-injected function: prepend compile-time escape context as a
        // string literal first argument (e.g. dump/dd receive 'html'|'js'|'css').
        // These functions return raw HTML/JS markup — disable auto-escaping so
        // their output is never passed through htmlspecialchars/json_encode.
        if (isset($this->contextInjectedFunctions[$name])) {
            $this->autoEscape = false;
            $contextLit = "'" . $this->escapeContext . "'";
            $call       = "\$__fn[{$safeName}]({$contextLit}";
            if (\trim($argsRaw) !== '') {
                $argList = $this->splitRespectingStrings($argsRaw, ',');
                $call .= ', ' . \implode(', ', $this->compileArgList($argList));
            }
            $call .= ')';
            return [$call, $i];
        }

        $call = "\$__fn[{$safeName}](";

        if (\trim($argsRaw) !== '') {
            $argList = $this->splitRespectingStrings($argsRaw, ',');
            $call .= \implode(', ', $this->compileArgList($argList));
        }

        $call .= ')';

        return [$call, $i];
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
     * The root segment is always `key` and resolves against the render scope
     * ($__va['name']); only its NAME is used, so the `$` sigil form ($a.b) and
     * the bare form (a.b) produce identical segments.
     *
     * Every continuation carries an `optional` flag. Optional access is the
     * author's opt-out from the strict "missing access throws" contract.
     *
     * Whitespace
     * ---------
     * A chain continuation may be separated from the value it continues by ANY
     * amount of whitespace, including newlines, so a long chain can wrap
     * Go-style (`user.\naddress.\ncity`, `config:\nversion`). This is safe
     * precisely because `.` is NOT the concatenation operator — it always means
     * property access, so `a . b` has one reading and no ambiguity to preserve.
     *
     * Two operators must stay GLUED to the value on their left, because a
     * spaced spelling would collide with the ternary operator:
     *   • `?`  — a spaced `?` is a ternary; `? .` / `?[` / `?:` / `?->` written
     *            with a gap are therefore NOT optional access.
     *   • a `.` or `->` with no member after it is an authoring ERROR, not a
     *     value: there is nothing else it could mean.
     *
     * `:` — the ternary problem
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
     * @return array{end:int, segments:array<int,array{type:string,value:string,optional:bool}>}|null
     */
    private function parseVarChainAt(
        string $subject,
        int $start,
        bool $allowArrow = false,
        bool $ternaryOpen = false
    ): ?array
    {
        $len = \strlen($subject);
        if ($start >= $len) {
            return null;
        }

        $first = $subject[$start];
        if (!(\ctype_alpha($first) || $first === '_')) {
            return null;
        }

        $i = $start + 1;
        while ($i < $len && (\ctype_alnum($subject[$i]) || $subject[$i] === '_')) {
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
            // (`+`, `?`, `and`, …) the whitespace separates operands and the
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
                            . "write \${$root['value']}?->… or {$root['value']}?.… instead of {$root['value']}?->…"
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
                // continuation — `cond ? a : b` and `x ? .5 : 1` rely on this.
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
                                . "write \${$root['value']}->… (or {$root['value']}?.…) instead of {$root['value']}->…"
                        );
                    }
                }

                // Whitespace after the operator is allowed: a chain may wrap.
                $j = $i + $opLen;
                while ($j < $len && \ctype_space($subject[$j])) {
                    $j++;
                }

                if ($j >= $len || !(\ctype_alpha($subject[$j]) || $subject[$j] === '_')) {
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
                $i = $idStart + 1;
                while ($i < $len && (\ctype_alnum($subject[$i]) || $subject[$i] === '_')) {
                    $i++;
                }

                $segments[] = [
                    'type'     => 'prop',
                    'value'    => \substr($subject, $idStart, $i - $idStart),
                    'optional' => $optional,
                ];
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
                            $segments[] = [
                                'type'     => $ch === '[' ? 'index' : 'dyn',
                                'value'    => \substr($subject, $innerStart, $i - $innerStart),
                                'optional' => $optional,
                            ];
                            $i++;
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
            // GLUED on both sides to count as a key read — that is what leaves
            // `cond ? x : y` as a ternary.
            if ($ch === ':' && $this->isChainColon($subject, $i, $ternaryOpen)) {
                $idStart = $i + 1;
                while ($idStart < $len && \ctype_space($subject[$idStart])) {
                    $idStart++;
                }

                $j = $idStart + 1;
                while ($j < $len && (\ctype_alnum($subject[$j]) || $subject[$j] === '_')) {
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
     * Whether the character can start a chain continuation identifier.
     */
    private function startsIdentifier(string $ch): bool
    {
        return $ch !== '' && (\ctype_alpha($ch) || $ch === '_');
    }

    /**
     * Whether the character can appear inside an identifier (so a keyword can be
     * told apart from a longer name that merely starts with it).
     */
    private function isIdentChar(string $ch): bool
    {
        return $ch !== '' && (\ctype_alnum($ch) || $ch === '_');
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
     * unambiguous — every other spacing belongs to a ternary:
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
     * guard is only ever used for the OPTIONAL forms — a strict read is plain
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

        $php = '$__va[\'' . $first . '\']';
        $n   = \count($segments);

        for ($k = 1; $k < $n; $k++) {
            $php = $this->appendChainSegmentPhp($php, $segments[$k]);
        }

        return $php;
    }

    /**
     * Emit one chain continuation onto an existing PHP expression.
     *
     * @param array{type:string,value:string,optional?:bool} $seg
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

            // `?` guards the RECEIVER only — the read itself stays STRICT. An
            // absent receiver yields null, but a missing KEY still raises
            // "Undefined array key". `$receiver[$k] ?? null` would swallow both
            // and silently hide a mistyped key, which is the exact failure mode
            // the strict-access design exists to prevent.
            //
            // Two forms:
            //   bare root    -> (isset($__va['a'])     ? $__va['a']['k']     : null)
            //   anything else-> (($t = RECV) === null ? null : $t['k'])
            // The first is preferred where it is CORRECT: isset() reports an
            // absent ROOT as false without a warning, which is precisely the
            // tolerance asked for. It is wrong anywhere else, because isset()
            // would also swallow a missing property or an intermediate missing
            // key. The second form binds the receiver ONCE, so a nested optional
            // chain stays linear instead of duplicating its receiver (the
            // duplication is what made an earlier revision grow as 2^N — six
            // optional segments emitted 2 245 characters for one read). Both
            // forms keep the expression nestable.
            if (\preg_match(self::BARE_ROOT_RE, $php)) {
                return '(isset(' . $php . ') ? ' . $php . '[' . $key . '] : null)';
            }

            $tmp = '$__g' . (++$this->guardCounter);
            return '((' . $tmp . ' = ' . $php . ') === null ? null : ' . $tmp . '[' . $key . '])';
        }

        // Property access (static or dynamic).
        $prop = $seg['type'] === 'dyn'
            ? '{' . $this->compileIndexExpression($value) . '}'
            : $value;

        if ($seg['type'] === 'prop' && !\preg_match(self::IDENT_RE, $value)) {
            throw new ClarityException("Invalid identifier in var chain: {$value}");
        }

        if (!$optional) {
            return $php . '->' . $prop;
        }

        // `?->` tolerates a NULL receiver while leaving the PROPERTY READ strict,
        // so a present object lacking the property still raises "Undefined
        // property" — the feedback we want. It short-circuits the rest of the
        // chain and nests without any guard expression.
        //
        // An ABSENT root is a separate case: `$__va['a']?->b` still raises
        // "Undefined array key 'a'", so the receiver is guarded with isset()
        // there — the same tolerance the array side gets, which keeps `?.` and
        // `?:` consistent about an absent root. (Emitting `?? null` instead would
        // additionally swallow a missing property.)
        if (\preg_match(self::BARE_ROOT_RE, $php)) {
            return '(isset(' . $php . ') ? ' . $php . '->' . $prop . ' : null)';
        }

        return $php . '?->' . $prop;
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
     * Convert a Clarity var-chain string to a PHP $__va[...] expression.
     *
     * Supports:
     *   foo           → $__va['foo']
     *   foo.bar       → $__va['foo']['bar']
     *   items[0]      → $__va['items'][0]
     *   items[index]  → $__va['items'][$__va['index']]
     *   a.b[c.d].e    → $__va['a']['b'][$__va['c']['d']]['e']
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
     * : is not part of ::), return ['name'=>…, 'expr'=>…].
     * Returns null for ordinary positional arguments.
     */
    private function parseNamedArg(string $arg): ?array
    {
        // identifier followed by = that is not == ; also must not be !=, <=, >=
        if (\preg_match('/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*:(?!:)(.+)$/s', $arg, $m)) {
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
        // Split top-level commas (your existing helper)
        $parts = $this->splitRespectingStrings($args, ',');

        if ($parts === []) {
            return [];
        }

        // Case 1: first argument already contains =>
        if ($this->findLambdaArrow($parts[0]) !== false) {
            return $parts;
        }

        // Case 2: find the first argument that contains =>
        $lambdaEnd = null;
        $count     = \count($parts);

        for ($i = 1; $i < $count; $i++) {
            if ($this->findLambdaArrow($parts[$i]) !== false) {
                $lambdaEnd = $i;
                break;
            }
        }

        // No lambda found or lambda is first argument → nothing to merge
        if ($lambdaEnd === null) {
            return $parts;
        }

        // Merge everything up to the lambda arrow into one argument
        $lambdaArg = '';
        for ($i = 0; $i <= $lambdaEnd; $i++) {
            if ($i > 0) {
                $lambdaArg .= ', ';
            }
            $lambdaArg .= \trim($parts[$i]);
        }

        // Build final argument list
        $result = [$lambdaArg];

        for ($i = $lambdaEnd + 1; $i < $count; $i++) {
            $result[] = $parts[$i];
        }

        return $result;
    }

    /**
     * Build a PHP filter call:  $this->__fl['name']($value, arg1, name2: arg2)
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

        $inlineCall = $this->buildInlineFilterCall($name, $phpValue, $argList);
        if ($inlineCall !== null) {
            return $trailing !== '' ? $inlineCall . $this->convertVarsAndOps($trailing) : $inlineCall;
        }

        $safeName = "'" . \addslashes($name) . "'";
        $call     = "\$__fl[{$safeName}]({$phpValue}";

        if ($argList !== []) {
            $isCallableFilter = isset(self::CALLABLE_ARG_FILTERS[$name]);

            if ($isCallableFilter) {
                // map/filter/reduce: first arg is a lambda/filter-ref, rest are positional only.
                foreach ($argList as $i => $arg) {
                    $arg = \trim($arg);
                    $call .= ', ';
                    if ($i === 0) {
                        $call .= $this->compileCallableArg($arg, $name);
                    } else {
                        $call .= $this->processCondition($arg);
                    }
                }
            } else {
                // Standard filter: emit args directly; named args become PHP named args.
                foreach ($this->compileArgList($argList) as $phpArg) {
                    $call .= ', ' . $phpArg;
                }
            }
        }

        $call .= ')';
        if ($trailing !== '') {
            $call .= $this->convertVarsAndOps($trailing);
        }
        return $call;
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
        if (!\preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)/', $segment, $m)) {
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

        // Plain filter — no trailing comparison operator
        if (\trim($rest) === '') {
            return ['name' => $name, 'args' => $args, 'trailing' => ''];
        }

        // Rest must be an operator followed by a non-empty operand.
        // `??` (null-coalescing) is included so a filter may be followed by a
        // fallback: `{{ items |> length ?? 0 }}`, `{{ x |> expand ?? 'fb' }}`.
        // Note: `??` catches a NULL RETURN only — it cannot catch an exception.
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
     * @param array{php?: string, params?: string[], defaults?: array<string, string>, variadic?: bool} $definition
     * @param string[] $positionalArgs
     * @param array<string, string> $namedArgs
     * @return array<int, string>
     */
    private function resolveInlineFilterSlots(string $filterName, array $definition, string $phpValue, array $positionalArgs, array $namedArgs): array
    {
        $params   = $definition['params'] ?? [];
        $defaults = $definition['defaults'] ?? [];
        $slots    = [1 => $phpValue];
        $assigned = [];

        foreach ($positionalArgs as $index => $phpArg) {
            if (!isset($params[$index])) {
                throw new ClarityException(
                    "Filter '{$filterName}' received too many positional arguments."
                );
            }

            $paramName = $params[$index];
            $slots[$index + 2] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($namedArgs as $paramName => $phpArg) {
            $paramIndex = \array_search($paramName, $params, true);
            if ($paramIndex === false) {
                throw new ClarityException(
                    "Unknown named argument '{$paramName}' for filter '{$filterName}'."
                );
            }
            if (isset($assigned[$paramName])) {
                throw new ClarityException(
                    "Filter '{$filterName}' received '{$paramName}' more than once."
                );
            }

            $slots[$paramIndex + 2] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($params as $index => $paramName) {
            $slotIndex = $index + 2;
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
     *   'filterName' / "filterName"         reference to a registered filter
     *                                        or to an inline built-in filter for map()
     *
     * Anything else (bare variable names, function calls, …) is rejected.
     */
    private function compileCallableArg(string $arg, string $filterName): string
    {
        // ── Filter reference: 'name' or "name" ───────────────────────────────
        $trimmed = \trim($arg);
        if (\strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            $last  = $trimmed[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $refName = \substr($trimmed, 1, -1);
                if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $refName)) {
                    throw new ClarityException(
                        "Filter reference must be a plain identifier, got: '{$refName}'"
                    );
                }

                if ($this->shouldInlineCallableFilterReference($filterName, $refName)) {
                    return $this->buildInlineCallableFilterReference($refName);
                }

                return "\$__fl['" . \addslashes($refName) . "']";
            }
        }

        // ── Lambda: param => expression / acc, item => expression ───────────
        $arrowPos = $this->findLambdaArrow($arg);
        if ($arrowPos !== false) {
            return $this->compileLambda($arg, $arrowPos, $filterName);
        }

        throw new ClarityException(
            "The '{$filterName}' filter requires a lambda (e.g. 'item => item.name') "
                . "or a filter reference (e.g. '\"upper\"'), got: '{$arg}'"
        );
    }

    private function shouldInlineCallableFilterReference(string $filterName, string $referenceName): bool
    {
        return $filterName === 'map'
            && $this->registry->hasInlineFilter($referenceName);
    }

    private function buildInlineCallableFilterReference(string $referenceName): string
    {
        $inlineCall = $this->buildInlineFilterCall($referenceName, '$__val', []);
        if ($inlineCall === null) {
            throw new ClarityException("Unknown inline filter reference: '{$referenceName}'");
        }

        return "static fn(mixed \$__val): mixed => {$inlineCall}";
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
     *   while all other identifiers are resolved from the captured $__va.
     * - Both $__va and $this->__fl (the filter registry) are captured by value so
     *   the closure can access outer template variables and other filters.
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
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $second)) {
                throw new ClarityException("Invalid lambda parameter: '{$second}'");
            }
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $first)) {
            throw new ClarityException("Invalid lambda parameter: '{$first}'");
        }

        // Compile the body as a full Clarity expression (handles |> pipelines).
        // convertVarsAndOps maps all identifiers to $__va['name'], so we fix
        // up the parameter references afterwards with a targeted substitution.
        $phpBody = $this->processCondition($body);

        $phpBody   = \str_replace("\$__va['{$first}']", '$' . $first, $phpBody);
        $signature = "mixed \${$first}";

        if ($filterName === 'reduce') {
            if (!isset($second)) {
                throw new ClarityException(
                    "The 'reduce' filter lambda must declare two parameters separated by a comma "
                        . "(e.g. 'acc, item => acc + item'), got: '{$paramList}'"
                );
            }
            $phpBody = \str_replace("\$__va['{$second}']", '$' . $second, $phpBody);
            $signature .= ", mixed \${$second}";
        } elseif (isset($second)) {
            throw new ClarityException(
                "The '{$filterName}' filter lambda must declare only one parameter, got: '{$paramList}'"
            );
        }

        return "static function({$signature}) use (\$__va): mixed { return {$phpBody}; }";
    }

}