<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait OperatorTestTrait
{

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
        'defined'   => ['binary' => false, 'tolerates' => true, 'call' => 'defined'],
        'null'      => ['binary' => false, 'tolerates' => true, 'call' => 'is_null'],
        // `none` and `nil` are accepted as spellings of the same test. They are
        // tests rather than values, so a variable of either name stays readable.
        'none'      => ['binary' => false, 'tolerates' => true, 'call' => 'is_null'],
        'nil'       => ['binary' => false, 'tolerates' => true, 'call' => 'is_null'],
        'empty'     => ['binary' => false, 'tolerates' => true, 'call' => 'is_empty'],
        'iterable'  => ['binary' => false, 'tolerates' => false, 'call' => 'iterable'],
        'even'      => ['binary' => false, 'tolerates' => false, 'call' => 'is_even'],
        'odd'       => ['binary' => false, 'tolerates' => false, 'call' => 'is_odd'],
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
     *
     * `isset()` reports a present-but-null name as absent, deliberately: that is
     * the one answer a PHP local can give, so it is the only answer both modes
     * can give together. See {@see presenceProbeFor()}.
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
     * A presence probe for a compiled access expression, or a `null` comparison
     * for anything that cannot appear inside isset().
     *
     * `isset()` is the probe for EVERY form, one spelling in both modes. An
     * earlier version special-cased a bare `$__c_va['name']` with
     * `array_key_exists()` so that a scope entry holding an explicit `null`
     * counted as defined. That special case is gone: open mode emits a bare
     * PHP local for the same name, and no O(1) existence test for a local can
     * see through null — so the two modes could not agree, and `x is defined`
     * meant something different depending on a setting the template cannot see.
     *
     * The contract is now one sentence: a name counts as defined when it holds a
     * value other than null. That is also what a local can answer, so both modes
     * compile to the same expression and a template never has to know the mode.
     */
    private function presenceProbeFor(string $php): string
    {
        // A variable or a chain over one (the only forms isset() accepts). One
        // regex covers both `$__c_va['a']['b']` and open mode's `$a['b']`, so no
        // branch here depends on the mode.
        $chain = '/^\$[A-Za-z_][A-Za-z0-9_]*(?:(?:\[(?:\'[^\']*\'|-?\d+)\]|->[A-Za-z_][A-Za-z0-9_]*))*$/';
        if (\preg_match($chain, $php) === 1) {
            return 'isset(' . $php . ')';
        }

        // Anything else is an expression; it has a value, so it is defined
        // unless that value is null.
        return '(' . $php . ') !== null';
    }
}
