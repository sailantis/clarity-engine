<?php
namespace Clarity\Engine;

/**
 * Compact wire format for the compiled source map.
 *
 * The compiler emits the source map into the compiled cache file as a PHP
 * literal.  The natural representation — `list<array{int,int,int}>` via
 * var_export() — is very expensive for what it holds: every range costs ~65
 * bytes of PHP to express three small integers (~236 B of retained memory),
 * which made the metadata ~2.3x the size of the render body it annotates.
 *
 * This class encodes the same information as ONE delimited string:
 *
 *   "lineDelta,fileIndex,tplLineDelta;lineDelta,fileIndex,tplLineDelta;..."
 *
 * Line numbers are delta-encoded because the map is appended in ascending line
 * order, so the deltas stay in single digits however long the template is; the
 * file index is left absolute since it is already tiny.
 *
 * For a 1000-range map the packed string uses about 95% less memory than the
 * equivalent nested arrays.
 *
 * The decode cost (~0.1 ms per 500 ranges) is paid only on the error path,
 * which is the only place the map is read.
 *
 * Invariant: decode(encode($map)) === $map for any map the compiler produces
 * (ascending phpLine starts, integer file indices and template lines).
 */
final class SourceMap
{
    /** Separates one range from the next. */
    private const RANGE_SEPARATOR = ';';

    /** Separates the three numbers inside a range. */
    private const FIELD_SEPARATOR = ',';

    /**
     * Encode a source map as the compact string form.
     *
     * @param array<int, array{0:int,1:int,2:int}> $map list of [phpLineStart, fileIndex, templateLine]
     * @return string Empty string for an empty map.
     */
    public static function encode(array $map): string
    {
        if ($map === []) {
            return '';
        }

        $chunks   = [];
        $prevLine = 0;
        $prevTpl  = 0;

        foreach ($map as $range) {
            $line = (int) $range[0];
            $file = (int) $range[1];
            $tpl  = (int) $range[2];

            $chunks[] = ($line - $prevLine) . self::FIELD_SEPARATOR
                . $file . self::FIELD_SEPARATOR
                . ($tpl - $prevTpl);

            $prevLine = $line;
            $prevTpl  = $tpl;
        }

        return \implode(self::RANGE_SEPARATOR, $chunks);
    }

    /**
     * Decode the compact string form back into the list-of-ranges shape.
     *
     * @param string $packed String produced by {@see encode()}.
     * @return array<int, array{0:int,1:int,2:int}> list of [phpLineStart, fileIndex, templateLine]
     */
    public static function decode(string $packed): array
    {
        if ($packed === '') {
            return [];
        }

        $map  = [];
        $line = 0;
        $tpl  = 0;

        foreach (\explode(self::RANGE_SEPARATOR, $packed) as $chunk) {
            $fields = \explode(self::FIELD_SEPARATOR, $chunk);
            if (\count($fields) !== 3) {
                // A malformed literal must not produce a silently wrong map:
                // returning an empty map makes the caller fall back to "no
                // mapping", which is the safe degradation.
                return [];
            }

            $line += (int) $fields[0];
            $tpl += (int) $fields[2];
            $map[] = [$line, (int) $fields[1], $tpl];
        }

        return $map;
    }

    /**
     * Normalise a compiled class's `$sourceMap` property to the list-of-ranges
     * shape, whatever form it is in.
     *
     * Current classes hold the packed string ({@see packedLiteral()}); a class
     * emitted by an older compiler holds the raw nested array. This is read on
     * the ERROR path, so it must never throw — a TypeError raised while
     * formatting another exception would replace the real error. Anything
     * unrecognised degrades to an empty map, i.e. "no line mapping", which is
     * the safe answer (an absent line number beats a wrong one).
     *
     * @param mixed $packed Value of a compiled class's $sourceMap property.
     * @return array<int, array{0:int,1:int,2:int}> list of [phpLineStart, fileIndex, templateLine]
     */
    public static function normalise(mixed $packed): array
    {
        // Classes compiled before the packed format: already this shape.
        if (\is_array($packed)) {
            return $packed;
        }

        if (!\is_string($packed)) {
            return [];
        }

        return self::decode($packed);
    }

    /**
     * Build the PHP literal for a compiled class's `$sourceMap` property.
     *
     * The packed form is a single-quoted string.  It cannot contain a quote or
     * a backslash (it is only digits and separators), but addcslashes() is used
     * anyway so the invariant holds even if the separators are ever changed.
     *
     * The returned literal always fits on one line, whatever the map size,
     * which keeps compiled files readable and avoids pathological line counts.
     *
     * @param array<int, array{0:int,1:int,2:int}> $map Source map to emit.
     * @return string PHP expression, e.g. "'1,0,1;3,0,3'"
     */
    public static function packedLiteral(array $map): string
    {
        return "'" . \addcslashes(self::encode($map), "\\'") . "'";
    }

    /**
     * Normalise a compiled class's `$sourcePaths` property to `list<string>`.
     *
     * The compiler emits it as a plain nested array (a path cannot use the
     * packed integer form), so this only has to tolerate a class from a compiler
     * that predates the property — hence "empty list" rather than an error, for
     * the same reason {@see normalise()} degrades: it runs on the error path,
     * where a TypeError would replace the real exception.
     *
     * @param mixed $paths Value of a compiled class's $sourcePaths property.
     * @return list<string>
     */
    public static function normalisePaths(mixed $paths): array
    {
        if (!\is_array($paths)) {
            return [];
        }

        $out = [];
        foreach ($paths as $path) {
            $out[] = \is_string($path) ? $path : '';
        }

        return $out;
    }
}
