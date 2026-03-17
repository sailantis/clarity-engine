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
 * • var-chains (foo.bar[x].baz) → $vars['foo']['bar'][$vars['x']]['baz']
 * • logical operators:  and → &&,  or → ||,  not → !
 * • concat operator:    ~   → .
 * • all other tokens pass through unchanged (PHP validates them)
 *
 * Pipeline (|>)
 * • Each step after |> is a filter: name  or  name(arg1, arg2)
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

    private array $varChainCache = [];

    /**
    * Compile-time local variable context: templateVarName → PHP variable string.
    * Set by the Compiler when entering/leaving loop scopes so that expressions
    * inside loops resolve loop variables to direct PHP local variables instead
    * of $vars['name'] lookups.
    *
    * @var array<string, string>
    */
    private array $localVars = [];
    private const IDENT_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const CHAIN_RE = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    private const RE_FILTER = '/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:\(\s*(.*)\s*\))?\s*$/s';

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
    * ($__lv_item_0) rather than $vars['item'] array lookups.
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
    * Each element is:  ['type' => TEXT|OUTPUT|BLOCK, 'content' => string, 'line' => int]
    *
    * @param string $source Raw template source.
    * @return array<int, array{int, string, int}>
    */
    public function tokenize(string $source): array
    {
        $segments = [];
        $pattern = '/\{\{.*?\}\}++|\{%.*?%\}++|\{#.*?#\}++/s';
        if (!\preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [
                [
                    self::KEY_TYPE => self::TEXT,
                    self::KEY_CONTENT => \trim($source),
                    self::KEY_LINE => 1
                ]
            ];
        }

        $line = 1;
        $pos = 0;
        foreach ($matches[0] as [$match, $offset]) {
            if ($offset > $pos) {
                $text = \substr($source, $pos, $offset - $pos);
                if (\trim($text) !== '') {
                    $segments[] = [
                        self::KEY_TYPE => self::TEXT,
                        self::KEY_CONTENT => $text,
                        self::KEY_LINE => $line
                    ];
                }
                $line += \substr_count($text, "\n");
            }

            $len = \strlen($match);
            $inner = \trim(\substr($match, 2, $len - 4));
            switch ($match[1]) {
                case '{':
                    $type = self::OUTPUT;
                    break;
                case '%':
                    $type = self::BLOCK;
                    break;
                case '#':
                    $type = self::COMMENT;
                    break;
                default:
                    throw new ClarityException("Unexpected tag type in match: {$match[0]}");
            }
            $segments[] = [
                self::KEY_TYPE => $type,
                self::KEY_CONTENT => $inner,
                self::KEY_LINE => $line
            ];

            $line += \substr_count($match, "\n");
            $pos = $offset + $len;
        }

        if ($pos < \strlen($source)) {
            $rest = \substr($source, $pos);
            if (\trim($rest) !== '') {
                $segments[] = [
                    self::KEY_TYPE => self::TEXT,
                    self::KEY_CONTENT => $rest,
                    self::KEY_LINE => $line
                ];
            }
        }

        return $segments;
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
        [$expr, $filters] = $this->splitPipeline($expression);

        $phpExpr = $this->convertVarsAndOps($expr);

        // Wrap in filter calls (innermost first → outermost last)
        foreach ($filters as $filterSegment) {
            $phpExpr = $this->buildFilterCall($filterSegment, $phpExpr);
        }

        if ($this->autoEscape) {
            $phpExpr = match ($this->escapeContext) {
                'js' => '\\json_encode(' . $phpExpr . ', 271)', // HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT|UNESCAPED_UNICODE
                'css' => '(string)(' . $phpExpr . ')',          // raw — CSS values are not HTML-escaped
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
        [$expr, $filters] = $this->splitPipeline($expression);
        $phpExpr = $this->convertVarsAndOps($expr);

        foreach ($filters as $filterSegment) {
            $phpExpr = $this->buildFilterCall($filterSegment, $phpExpr);
        }

        return $phpExpr;
    }

    /**
    * Convert a Clarity variable chain to its PHP $vars[...] equivalent.
    * Used for the left-hand side of {% set var = ... %}.
    *
    * @param string $var Clarity variable name (e.g. 'user.name', 'items[0]').
    * @return string PHP lvalue (e.g. '$vars[\'user\'][\'name\']').
    */
    public function processLvalue(string $var): string
    {
        return $this->varChainToPhp(\trim($var));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

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
        $parts = [];
        $current = '';
        $len = \strlen($subject);
        $dlen = \strlen($delimiter);
        $i = 0;
        $inSingle = false;
        $inDouble = false;
        $depth = 0; // parenthesis / square / curly-brace nesting depth

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
    * 1. Replacing var-chains with $vars[...] accesses
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
            'and' => '&&',
            'or' => '||',
            'not' => '!',
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
        ];

        $len = \strlen($expr);
        $i = 0;
        $out = '';
        $inSingle = false;
        $inDouble = false;

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

            // Disallow bare PHP dollar-sign: $var or $$var inside an expression
            // would compile to direct PHP variable access, bypassing the sandbox.
            // All variables must be accessed via the dot-chain syntax (foo.bar).
            if ($ch === '$') {
                throw new ClarityException("Direct PHP variable access ('\$') is not allowed in Clarity expressions; use dot-notation instead.");
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
                if ($nextAfterIdent !== '.' && $nextAfterIdent !== '[') {
                    // Plain identifier — may be a keyword or a cacheable single-segment chain
                    $token = \substr($expr, $start, $idEnd - $start);
                    $i = $idEnd;

                    $prevChar = ($start - 1 >= 0) ? $expr[$start - 1] : null;
                    $nextChar = $nextAfterIdent !== '' ? $nextAfterIdent : null;
                    $prevIsId = $prevChar !== null && (\ctype_alnum($prevChar) || $prevChar === '_');
                    $nextIsId = $nextChar !== null && (\ctype_alnum($nextChar) || $nextChar === '_');
                    $lower = \strtolower($token);

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
                    // variable must resolve to its PHP local var, not to $vars['name'].
                    if (isset($this->localVars[$token])) {
                        $out .= $this->localVars[$token];
                        continue;
                    }

                    if (isset($this->varChainCache[$token])) {
                        $out .= $this->varChainCache[$token];
                    } else {
                        $parsed = $this->parseVarChainAt($expr, $start);
                        $php = $parsed !== null
                            ? $this->varChainToPhpWithSegments($token, $parsed['segments'])
                            : $token;
                        $out .= $php;
                    }
                    continue;
                }

                // Identifier followed by '.' or '[' — full chain parsing required
                $parsed = $this->parseVarChainAt($expr, $start);
                if ($parsed === null) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                $i = $parsed['end'];
                $segments = $parsed['segments'];
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

            $rawKey = \trim(\substr($entry, 0, $colonPos));
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
        $last = $rawKey[\strlen($rawKey) - 1];
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
        $i = $start;

        while ($i < $len) {
            while ($i < $len && \ctype_space($expr[$i])) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            if ($expr[$i] === '.') {
                $nameStart = $i + 1;
                if ($nameStart >= $len || !(\ctype_alpha($expr[$nameStart]) || $expr[$nameStart] === '_')) {
                    break;
                }

                $nameEnd = $nameStart + 1;
                while ($nameEnd < $len && (\ctype_alnum($expr[$nameEnd]) || $expr[$nameEnd] === '_')) {
                    $nameEnd++;
                }

                $name = \substr($expr, $nameStart, $nameEnd - $nameStart);
                $php = '(' . $php . ')[\'' . $name . '\']';
                $i = $nameEnd;
                continue;
            }

            if ($expr[$i] === '[') {
                [$inner, $end] = $this->extractBalancedSegment($expr, $i);
                $inner = \trim($inner);
                if ($inner === '') {
                    throw new ClarityException('Index access must not be empty.');
                }

                $indexPhp = \ctype_digit($inner) ? $inner : $this->processCondition($inner);
                $php = '(' . $php . ')[' . $indexPhp . ']';
                $i = $end;
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
        $open = $subject[$start] ?? null;
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        if ($open === null || !isset($pairs[$open])) {
            throw new ClarityException('Expected a balanced segment opener.');
        }

        $stack = [$pairs[$open]];
        $len = \strlen($subject);
        $i = $start + 1;
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

    private function findTopLevelChar(string $subject, string $needle): int|false
    {
        $len = \strlen($subject);
        $inSingle = false;
        $inDouble = false;
        $stack = [];
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];

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
        $i = $openParen + 1; // skip '('
        $argStart = $i;
        $depth = 1;
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

        // Pruned function: compile to '' at template compile time (e.g. dump in production).
        if (isset($this->prunedFunctions[$name])) {
            return ["''", $i];
        }

        switch ($name) {
            case 'context':
                if (\trim($argsRaw) !== '') {
                    throw new ClarityException('context() does not accept any arguments.');
                }
                return ['$vars', $i];
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
            $call = "\$__fn[{$safeName}]({$contextLit}";
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
    * @return array{end:int, segments:array<int,array{type:string,value:string}>}|null
    */
    private function parseVarChainAt(string $subject, int $start): ?array
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

        $segments = [
            ['type' => 'key', 'value' => \substr($subject, $start, $i - $start)]
        ];

        while ($i < $len) {
            $ch = $subject[$i];

            if ($ch === '.') {
                $dotPos = $i;
                $i++;
                if ($i < $len && (\ctype_alpha($subject[$i]) || $subject[$i] === '_')) {
                    $idStart = $i;
                    $i++;
                    while ($i < $len && (\ctype_alnum($subject[$i]) || $subject[$i] === '_')) {
                        $i++;
                    }
                    $segments[] = ['type' => 'key', 'value' => \substr($subject, $idStart, $i - $idStart)];
                    continue;
                }

                // Not a valid dot-access continuation; keep '.' outside token.
                $i = $dotPos;
                break;
            }

            if ($ch === '[') {
                $i++; // skip '['
                $innerStart = $i;
                $depth = 1;

                while ($i < $len) {
                    $cc = $subject[$i];

                    if (($cc === "'" || $cc === '"')) {
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

                    if ($cc === '[') {
                        $depth++;
                        $i++;
                        continue;
                    }

                    if ($cc === ']') {
                        $depth--;
                        if ($depth === 0) {
                            $inner = \substr($subject, $innerStart, $i - $innerStart);
                            $segments[] = ['type' => 'index', 'value' => $inner];
                            $i++; // consume closing ']'
                            break;
                        }
                        $i++;
                        continue;
                    }

                    $i++;
                }

                if ($depth > 0) {
                    // Unterminated index expression: consume to end as one index segment.
                    $segments[] = ['type' => 'index', 'value' => substr($subject, $innerStart)];
                    $i = $len;
                }

                continue;
            }

            break;
        }

        return ['end' => $i, 'segments' => $segments];
    }

    /**
    * Convert parsed var-chain segments to PHP.
    *
    * @param array<int,array{type:string,value:string}> $segments
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

        $php = '$vars[\'' . $first . '\']';
        $n = \count($segments);

        for ($k = 1; $k < $n; $k++) {
            $seg = $segments[$k];
            $value = $seg['value'];

            if ($seg['type'] === 'key') {
                if (!\preg_match(self::IDENT_RE, $value)) {
                    throw new ClarityException("Invalid identifier in var chain: {$value}");
                }
                $php .= '[\'' . $value . '\']';
                continue;
            }

            // index segment
            $inner = $value;
            if ($inner !== '' && \ctype_digit($inner)) {
                $php .= '[' . $inner . ']';
                continue;
            }

            if ($inner !== '' && \preg_match(self::CHAIN_RE, $inner)) {
                $php .= '[' . $this->varChainToPhp($inner) . ']';
                continue;
            }

            $php .= '[' . $this->convertVarsAndOps($inner) . ']';
        }

        return $php;
    }

    /**
    * Convert a parsed var-chain and memoize by raw chain string.
    *
    * @param array<int,array{type:string,value:string}> $segments
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
    * @param array<int,array{type:string,value:string}> $segments
    */
    private function buildVarChainPhpWithLocalRoot(array $segments): string
    {
        $first = $segments[0]['value'];
        $php = $this->localVars[$first]; // e.g. '$item'
        $n = \count($segments);

        for ($k = 1; $k < $n; $k++) {
            $seg = $segments[$k];
            $value = $seg['value'];

            if ($seg['type'] === 'key') {
                $php .= '[\'' . $value . '\']';
                continue;
            }

            // index segment — use convertVarsAndOps so nested local vars resolve correctly
            if ($value !== '' && \ctype_digit($value)) {
                $php .= '[' . $value . ']';
            } else {
                $php .= '[' . $this->convertVarsAndOps($value) . ']';
            }
        }

        return $php;
    }

    /**
    * Convert a Clarity var-chain string to a PHP $vars[...] expression.
    *
    * Supports:
    *   foo           → $vars['foo']
    *   foo.bar       → $vars['foo']['bar']
    *   items[0]      → $vars['items'][0]
    *   items[index]  → $vars['items'][$vars['index']]
    *   a.b[c.d].e    → $vars['a']['b'][$vars['c']['d']]['e']
    */
    public function varChainToPhp(string $chain): string
    {
        if ($chain === '') {
            return '';
        }

        // Memoization
        if (isset($this->varChainCache[$chain])) {
            return $this->varChainCache[$chain];
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

        return $this->varChainToPhpWithSegments($chain, $parsed['segments']);
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
        $result = [];
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
        $named = [];
        $seenNamed = false;

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
        $count = \count($parts);

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
        if (\preg_match(self::RE_FILTER, $filterSegment, $m)) {
            $name = $m[1];
            $args = $m[2] ?? '';
        } else {
            throw new ClarityException("Invalid filter segment: '{$filterSegment}'");
        }

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
            return $inlineCall;
        }

        $safeName = "'" . \addslashes($name) . "'";
        $call = "\$__fl[{$safeName}]({$phpValue}";

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
        return $call;
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
        $params = $definition['params'] ?? [];
        $defaults = $definition['defaults'] ?? [];
        $slots = [1 => $phpValue];
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
            $last = $trimmed[-1];
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
    private function findLambdaArrow(string $s): int|false
    {
        $len = \strlen($s);
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
    *   while all other identifiers are resolved from the captured $vars.
    * - Both $vars and $this->__fl (the filter registry) are captured by value so
    *   the closure can access outer template variables and other filters.
    *
    * @param string $arg      The full lambda string (e.g. 'item => item.name').
    * @param int    $arrow    Position of '=>' in $arg.
    * @param string $filterName The callable filter currently being compiled.
    */
    private function compileLambda(string $arg, int $arrow, string $filterName): string
    {
        $paramList = \trim(\substr($arg, 0, $arrow));
        $body = \trim(\substr($arg, $arrow + 2));

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
        // convertVarsAndOps maps all identifiers to $vars['name'], so we fix
        // up the parameter references afterwards with a targeted substitution.
        $phpBody = $this->processCondition($body);

        $phpBody = \str_replace("\$vars['{$first}']", '$' . $first, $phpBody);
        $signature = "mixed \${$first}";

        if ($filterName === 'reduce') {
            if (!isset($second)) {
                throw new ClarityException(
                    "The 'reduce' filter lambda must declare two parameters separated by a comma "
                    . "(e.g. 'acc, item => acc + item'), got: '{$paramList}'"
                );
            }
            $phpBody = \str_replace("\$vars['{$second}']", '$' . $second, $phpBody);
            $signature .= ", mixed \${$second}";
        } elseif (isset($second)) {
            throw new ClarityException(
                "The '{$filterName}' filter lambda must declare only one parameter, got: '{$paramList}'"
            );
        }

        return "static function({$signature}) use (\$vars): mixed { return {$phpBody}; }";
    }

}