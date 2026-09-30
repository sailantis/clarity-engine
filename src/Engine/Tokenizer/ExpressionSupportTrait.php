<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait ExpressionSupportTrait
{

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
}
