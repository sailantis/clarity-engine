<?php
namespace Clarity\Tests\Engine;

use Clarity\Engine\SourceMap;
use Clarity\Tests\BaseTestCase;

/**
 * The compiled source map is stored as one packed string rather than a nested
 * array literal.  These tests pin the wire format, the round trip, and the
 * failure mode, because the map is the only thing standing between a runtime
 * error and "which template line caused it".
 */
class SourceMapTest extends BaseTestCase
{
    // =========================================================================
    // Round trip
    // =========================================================================

    public function testEmptyMapRoundTrips(): void
    {
        self::assertSame('', SourceMap::encode([]));
        self::assertSame([], SourceMap::decode(''));
    }

    public function testSingleRangeRoundTrips(): void
    {
        $map = [[1, 0, 1]];

        self::assertSame('1,0,1', SourceMap::encode($map));
        self::assertSame($map, SourceMap::decode(SourceMap::encode($map)));
    }

    public function testMapRoundTripsExactly(): void
    {
        // Ascending phpLine starts with a file index that goes up and down again
        // (extends + include both occur), which is the shape the compiler emits.
        $map = [
            [1, 0, 1],
            [2, 0, 4],
            [4, 1, 1],
            [5, 1, 2],
            [9, 2, 1],
            [10, 2, 7],
            [25, 1, 19],
            [27, 0, 7],
        ];

        self::assertSame($map, SourceMap::decode(SourceMap::encode($map)));
    }

    /**
     * Delta encoding must survive a long template, where the deltas stay small
     * but the absolute line numbers do not.
     */
    public function testLongMapRoundTripsAndStaysCompact(): void
    {
        $map  = [];
        $line = 1;
        for ($i = 0; $i < 2000; $i++) {
            $map[] = [$line, $i % 5, $i + 1];
            $line += 1 + ($i % 4);
        }

        self::assertSame($map, SourceMap::decode(SourceMap::encode($map)));

        $packed = SourceMap::encode($map);
        $nested = \strlen(\var_export($map, true));

        self::assertLessThan(
            0.25 * $nested,
            \strlen($packed),
            'the packed form must stay well under a quarter of the nested literal'
        );
    }

    /**
     * Values a template could plausibly reach.  A map that decodes to something
     * *close* to the original is worse than no map at all, so this pins exactness
     * rather than a tolerance.
     */
    public function testLargeLineAndFileNumbersRoundTrip(): void
    {
        $map = [
            [1, 0, 1],
            [100000, 12, 99999],
            [100001, 12, 100000],
            [250000, 999, 1234567],
        ];

        self::assertSame($map, SourceMap::decode(SourceMap::encode($map)));
    }

    // =========================================================================
    // Emitted literal
    // =========================================================================

    /**
     * packedLiteral() output is embedded directly into generated PHP, so it must
     * be a valid single-quoted string that eval() sees as the encoded map.
     */
    public function testPackedLiteralIsAValidSingleLinePhpString(): void
    {
        $map     = [[1, 0, 1], [4, 1, 2], [9, 0, 3]];
        $literal = SourceMap::packedLiteral($map);

        self::assertStringStartsWith("'", $literal);
        self::assertStringEndsWith("'", $literal);
        self::assertStringNotContainsString("\n", $literal, 'the literal must stay on one line');

        $evaluated = eval('return ' . $literal . ';');
        self::assertSame(SourceMap::encode($map), $evaluated);
        self::assertSame($map, SourceMap::decode($evaluated));
    }

    public function testPackedLiteralForEmptyMapIsAnEmptyString(): void
    {
        self::assertSame("''", SourceMap::packedLiteral([]));
        self::assertSame([], SourceMap::decode(eval('return ' . SourceMap::packedLiteral([]) . ';')));
    }

    // =========================================================================
    // Failure mode: a corrupt literal must degrade, not mislead
    // =========================================================================

    /**
     * A malformed packed value must decode to an EMPTY map (→ "no mapping
     * available") rather than to a shifted one.  A wrong line number is worse
     * than an absent one.
     */
    public function testMalformedPackedValueDecodesToEmptyMap(): void
    {
        self::assertSame([], SourceMap::decode('1,0'));
        self::assertSame([], SourceMap::decode('1,0,1;oops'));
        self::assertSame([], SourceMap::decode('1,0,1,4'));
    }

    // =========================================================================
    // normalise(): tolerate classes compiled by an older compiler
    // =========================================================================

    /**
     * A class compiled before the packed format holds the raw array.  Since the
     * map is only read on the error path, normalise() must pass it through
     * rather than throw — a TypeError while formatting another exception would
     * hide the real error.
     */
    public function testNormalisePassesThroughAnAlreadyDecodedMap(): void
    {
        $map = [[1, 0, 1], [4, 1, 2]];

        self::assertSame($map, SourceMap::normalise($map));
    }

    public function testNormaliseDecodesAPackedString(): void
    {
        $map = [[1, 0, 1], [4, 1, 2]];

        self::assertSame($map, SourceMap::normalise(SourceMap::encode($map)));
    }

    public function testNormaliseDegradesForUnexpectedTypes(): void
    {
        self::assertSame([], SourceMap::normalise(null));
        self::assertSame([], SourceMap::normalise(123));
        self::assertSame([], SourceMap::normalise(''));
    }
}
