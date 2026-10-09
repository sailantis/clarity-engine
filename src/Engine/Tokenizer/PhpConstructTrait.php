<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 *
 * Three constructs name a PHP CLASS rather than a template value: `new Foo(...)`,
 * `Foo::member`, and the right operand of `instanceof`.
 *
 * `new` and `Foo::member` are gated by the `newExpressions` and `staticCalls`
 * policy rules. `instanceof` is not gated by a rule.
 *
 * All three compile the class name to a fully qualified form with a leading `\`,
 * so the name resolves as a global class name regardless of the namespace the
 * compiled template is emitted in.
 */
trait PhpConstructTrait
{
    /**
     * Read a class name at $p, including any namespace separators, and return
     * `[rawName, endIndex]`.  The raw name still carries whatever leading `\` the
     * author wrote; {@see qualifyClassName()} normalises it.
     *
     * Returns null when no identifier starts at $p.
     */
    private function readQualifiedName(string $expr, int $p, int $len): ?array
    {
        $start = $p;

        if (($expr[$p] ?? '') === '\\') {
            $p++;
        }
        if (!self::isIdentifierStart($expr[$p] ?? '')) {
            return null;
        }

        $p++;
        while ($p < $len && self::isIdentifierChar($expr[$p])) {
            $p++;
        }

        // Trailing segments: `Foo\Bar\Baz`. A `\` not followed by an identifier
        // is not part of the name, so a stray backslash is never swallowed.
        while (($expr[$p] ?? '') === '\\' && self::isIdentifierStart($expr[$p + 1] ?? '')) {
            $p++;
            while ($p < $len && self::isIdentifierChar($expr[$p])) {
                $p++;
            }
        }

        return [\substr($expr, $start, $p - $start), $p];
    }

    /**
     * Turn a raw class name into the fully qualified form the compiled template
     * uses. `Foo`, `\Foo` and `\Foo\Bar` all name a global class, and the output
     * always has exactly one leading `\`.
     */
    private function qualifyClassName(string $raw): string
    {
        return '\\' . \ltrim($raw, '\\');
    }

    /**
     * Compile `new <Name>[(args)]` starting just past the `new` keyword.
     *
     * `new` is an accepted Clarity keyword but names a PHP construct rather than
     * a scope value, so it is recognised explicitly here.  Arguments are full
     * Clarity expressions, which is what keeps `new Foo(name)` reading the
     * template variable `name` rather than the PHP constant of that name.
     *
     * @param int      $p   Index of the first character after `new`.
     * @param int|null $end Set to the index after the construct on success.
     */
    private function compileNewExpression(string $expr, int $p, int $len, ?int &$end): string
    {
        while ($p < $len && \ctype_space($expr[$p])) {
            $p++;
        }

        $name = $this->readQualifiedName($expr, $p, $len);
        if ($name === null) {
            throw new ClarityException(
                "Expected a class name after 'new' in '{$expr}'."
            );
        }
        [$rawName, $p] = $name;

        $args = '';
        $q    = $p;
        while ($q < $len && \ctype_space($expr[$q])) {
            $q++;
        }
        if ($q < $len && $expr[$q] === '(') {
            [$inner, $afterArgs] = $this->extractBalancedSegment($expr, $q);
            $args = '(' . ($inner === ''
                    ? ''
                    : \implode(', ', $this->compileArgList(
                        $this->splitRespectingStrings($inner, ',')
                    ))) . ')';
            $end = $afterArgs;
        } else {
            $end = $p;
        }

        return 'new ' . $this->qualifyClassName($rawName) . $args;
    }

    /**
     * Compile the right operand of `instanceof`, starting just past the keyword.
     *
     * The operand is a class name, never a template value: `x instanceof Foo` has
     * to mean the class, or the operator would test against whatever the scope
     * happened to hold under that name.
     */
    private function compileInstanceofOperand(string $expr, int $p, int $len, ?int &$end): string
    {
        while ($p < $len && \ctype_space($expr[$p])) {
            $p++;
        }

        $name = $this->readQualifiedName($expr, $p, $len);
        if ($name === null) {
            throw new ClarityException(
                "Expected a class name after 'instanceof' in '{$expr}'."
            );
        }
        [$rawName, $end] = $name;

        return $this->qualifyClassName($rawName);
    }

    /**
     * Compile `Foo::member`, `Foo::method(args)`, `Foo::CONST`, `Foo::$prop` or
     * `Foo::class`.
     *
     * @param int $start Index of the first character of the class name.
     * @return array{php: string, end: int}|null Null when this is not a static
     *                                           access after all, so the caller
     *                                           can fall back to an ordinary name.
     */
    private function tryCompileStaticCall(string $expr, int $start, int $len): ?array
    {
        $name = $this->readQualifiedName($expr, $start, $len);
        if ($name === null) {
            return null;
        }
        [$rawName, $p] = $name;

        if (($expr[$p] ?? '') !== ':' || ($expr[$p + 1] ?? '') !== ':') {
            return null;
        }
        $p += 2;

        $class = $this->qualifyClassName($rawName);

        // `::class` is a compile-time string, not a member read.
        if (
            ($expr[$p] ?? '') === 'c' && \substr($expr, $p, 5) === 'class'
                && !self::isIdentifierChar($expr[$p + 5] ?? '')
        ) {
            return ['php' => $class . '::class', 'end' => $p + 5];
        }

        // `Foo::$property`.
        if (($expr[$p] ?? '') === '$') {
            $nameStart = $p + 1;
            if (!self::isIdentifierStart($expr[$nameStart] ?? '')) {
                throw new ClarityException("Expected a property name after '{$class}::' in '{$expr}'.");
            }
            $q = $nameStart + 1;
            while ($q < $len && self::isIdentifierChar($expr[$q])) {
                $q++;
            }
            return ['php' => $class . '::$' . \substr($expr, $nameStart, $q - $nameStart), 'end' => $q];
        }

        if (!self::isIdentifierStart($expr[$p] ?? '')) {
            throw new ClarityException(
                "Expected a member name after '{$class}::' in '{$expr}'."
            );
        }

        $memberStart = $p;
        $p++;
        while ($p < $len && self::isIdentifierChar($expr[$p])) {
            $p++;
        }
        $member = \substr($expr, $memberStart, $p - $memberStart);

        // Method call arguments, if any.
        $q = $p;
        while ($q < $len && \ctype_space($expr[$q])) {
            $q++;
        }
        if ($q < $len && $expr[$q] === '(') {
            [$inner, $end] = $this->extractBalancedSegment($expr, $q);
            $args = $inner === ''
                ? ''
                : \implode(', ', $this->compileArgList($this->splitRespectingStrings($inner, ',')));
            return ['php' => $class . '::' . $member . '(' . $args . ')', 'end' => $end];
        }

        return ['php' => $class . '::' . $member, 'end' => $p];
    }
}
