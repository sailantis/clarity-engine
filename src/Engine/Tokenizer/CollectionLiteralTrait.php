<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait CollectionLiteralTrait
{

    /**
     * Parse a Clarity array/object literal and any trailing property/index access.
     *
     * @param bool $asObject Whether this brace literal is an OBJECT literal, i.e.
     *                       `{ … }` in the operand of an `object` cast. Ignored
     *                       for `[ … ]`, whose items are always values.
     * @return array{0:string,1:int}
     */
    private function parseCollectionLiteralAt(string $expr, int $start, bool $asObject = false): array
    {
        [$inner, $end] = $this->extractBalancedSegment($expr, $start);

        $php = $expr[$start] === '['
            ? $this->compileArrayLiteral($inner)
            : $this->compileObjectLiteral($inner, $asObject);

        return $this->compilePostfixAccessChain($expr, $end, $php);
    }

    private function compileArrayLiteral(string $inner): string
    {
        $inner = \trim($inner);
        if ($inner === '') {
            return '[]';
        }

        // An array element is always a VALUE, never a nested object literal:
        // `[ {a: 1} ]` holds a map, at every depth. Object-ness is a property of
        // the literal that was cast, not of the enclosing collection — see
        // {@see compileObjectLiteral()}.
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

    /**
     * Compile a `{ key: value, … }` literal to a PHP array.
     *
     * $asObject states what this literal IS, which is the same rule the access
     * operators follow: `(object) { … }` builds an object, so its values are
     * object members. A value that is itself a brace literal is then another
     * object literal, at any depth, which is what makes `.` reachable:
     *
     *   `(object) { name: "Alice", address: { city: "Rome" } }`
     *   → `(object) (['name' => "Alice", 'address' => (object) (['city' => "Rome"])])`
     *
     * The wrap is LEXICAL, not a conversion: `(object) $runtimeValue` stays the
     * shallow PHP cast (a cast of a value is not a literal), and an array
     * element stays a map even inside an object literal — `{ tags: [{a: 1}] }`
     * has an ARRAY of arrays, because `[` states that its items are values.
     *
     * @param string $inner    The literal body, without the braces.
     * @param bool   $asObject Whether this literal is an object literal.
     */
    private function compileObjectLiteral(string $inner, bool $asObject = false): string
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

            $items[] = $this->compileObjectKey($rawKey) . ' => ' . $this->compileObjectValue($rawValue, $asObject);
        }

        return '[' . \implode(', ', $items) . ']';
    }

    /**
     * Compile one value slot of an object literal.
     *
     * Only a WHOLE brace literal becomes an object; anything else — a chain, a
     * call, a ternary — compiles unchanged, so a nested literal inside a larger
     * expression keeps its own (array) meaning. Inside
     * `(object) { a: { b: 1 } }` the value slot is exactly `{ b: 1 }`, so `a`
     * becomes an object; inside `(object) { a: cond ? {b:1} : {} }` it is not,
     * because the literal is only part of the expression.
     */
    private function compileObjectValue(string $rawValue, bool $asObject): string
    {
        if ($asObject && \str_starts_with($rawValue, '{')) {
            // Balance check: `{ a: 1 } ~ x` starts with `{` but is an
            // expression containing a literal, not a literal itself.
            [$inner, $after] = $this->extractBalancedSegment($rawValue, 0);
            if (\trim(\substr($rawValue, $after)) === '') {
                // `{ name }` is dynamic PROPERTY access, not an empty literal, so
                // only a body with a top-level `:` (or an empty `{}`) is a nested
                // object literal. Everything else keeps its existing reading.
                if (\trim($inner) === '' || $this->findTopLevelChar($inner, ':') !== false) {
                    return '(object) (' . $this->compileObjectLiteral($inner, true) . ')';
                }
            }
        }

        return $this->processCondition($rawValue);
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
     * Consume chained access after a compiled literal. Supported forms:
     * `.name`, `[index]`, `{dynamic}`, `:key`, each optionally prefixed with `?`.
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
                // Whitespace after `.` is allowed, as in parseVarChainAt.
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
}
