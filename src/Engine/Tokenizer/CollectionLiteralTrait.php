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
}
