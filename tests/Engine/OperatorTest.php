<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;

/**
 * Twig-style operator tests:
 *
 *   x in y             x not in y
 *   x is defined       x is not defined
 *   x is null          x is not null
 *   x is empty         x is not empty
 *   x is iterable      x is even       x is odd
 *   x starts with y    x ends with y
 *   x matches p        x divisible by n
 *   x is same as(y)
 *
 * All of these compile to registered runtime callables, so they behave the same
 * in sandbox and open mode. The three absence-tolerant tests (`defined`, `null`,
 * `empty`) must NOT throw when the left operand is absent — that is the whole
 * point of them, and the reason they compile to a presence probe rather than a
 * value call.
 */
class OperatorTest extends BaseTestCase
{
    // =========================================================================
    // in / not in
    // =========================================================================

    public function testInArrayByValue(): void
    {
        self::tpl('op_in_value', '{% if 2 in [1, 2, 3] %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_in_value'));
    }

    public function testInMappingByKey(): void
    {
        self::tpl('op_in_key', "{% if 'a' in map %}Y{% else %}N{% endif %}");
        $this->assertSame('Y', self::render('op_in_key', ['map' => ['a' => 1]]));
        $this->assertSame('N', self::render('op_in_key', ['map' => ['b' => 1]]));
    }

    public function testInVariable(): void
    {
        self::tpl('op_in_var', '{% if x in items %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_in_var', ['x' => 'b', 'items' => ['a', 'b']]));
        $this->assertSame('N', self::render('op_in_var', ['x' => 'z', 'items' => ['a', 'b']]));
    }

    public function testInString(): void
    {
        self::tpl('op_in_str', "{% if 'ell' in word %}Y{% else %}N{% endif %}");
        $this->assertSame('Y', self::render('op_in_str', ['word' => 'hello']));
        $this->assertSame('N', self::render('op_in_str', ['word' => 'world']));
    }

    public function testNotIn(): void
    {
        self::tpl('op_not_in', '{% if 9 not in [1, 2, 3] %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_not_in'));
    }

    public function testNotInString(): void
    {
        self::tpl('op_not_in_str', "{% if 'zz' not in word %}Y{% else %}N{% endif %}");
        $this->assertSame('Y', self::render('op_not_in_str', ['word' => 'hello']));
    }

    public function testInChainedRight(): void
    {
        self::tpl('op_in_chain', '{% if x in user:roles %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_in_chain', [
            'x'    => 'admin',
            'user' => ['roles' => ['admin', 'editor']],
        ]));
    }

    public function testInComposesWithAnd(): void
    {
        self::tpl('op_in_and', '{% if x in items and y in items %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_in_and', [
            'x' => 'a', 'y' => 'b', 'items' => ['a', 'b'],
        ]));
    }

    // =========================================================================
    // is defined
    // =========================================================================

    public function testIsDefinedPresent(): void
    {
        self::tpl('op_defined_yes', '{% if name is defined %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_defined_yes', ['name' => 'x']));
    }

    public function testIsDefinedAbsentDoesNotThrow(): void
    {
        self::tpl('op_defined_no', '{% if nope is defined %}Y{% else %}N{% endif %}');
        $this->assertSame('N', self::render('op_defined_no'));
    }

    public function testIsDefinedTrueForExplicitNull(): void
    {
        // Present-but-null is DEFINED (the key exists), which is why the probe
        // uses array_key_exists() rather than isset().
        self::tpl('op_defined_null', '{% if user is defined %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_defined_null', ['user' => null]));
    }

    public function testIsNotDefined(): void
    {
        self::tpl('op_not_defined', '{% if nope is not defined %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_not_defined'));
    }

    public function testIsDefinedOnChain(): void
    {
        self::tpl('op_defined_chain', '{% if user:name is defined %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_defined_chain', ['user' => ['name' => 'Ada']]));
        $this->assertSame('N', self::render('op_defined_chain', ['user' => ['other' => 1]]));
    }

    public function testIsNotDefinedOnMissingChainKey(): void
    {
        self::tpl('op_not_defined_chain', '{% if user:other is not defined %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_not_defined_chain', ['user' => ['name' => 'Ada']]));
    }

    // =========================================================================
    // is null / is empty
    // =========================================================================

    public function testIsNullOnAbsentNameDoesNotThrow(): void
    {
        self::tpl('op_null_absent', '{% if nope is null %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_null_absent'));
    }

    public function testIsNullOnNullValue(): void
    {
        self::tpl('op_null_value', '{% if v is null %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_null_value', ['v' => null]));
        $this->assertSame('N', self::render('op_null_value', ['v' => 0]));
    }

    public function testIsNotNull(): void
    {
        self::tpl('op_not_null', '{% if name is not null %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_not_null', ['name' => 'x']));
    }

    public function testIsEmpty(): void
    {
        self::tpl('op_empty', '{% if items is empty %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_empty', ['items' => []]));
        $this->assertSame('N', self::render('op_empty', ['items' => [1]]));
    }

    public function testIsEmptyOnAbsentName(): void
    {
        self::tpl('op_empty_absent', '{% if nope is empty %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_empty_absent'));
    }

    public function testIsNotEmpty(): void
    {
        self::tpl('op_not_empty', '{% if items is not empty %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_not_empty', ['items' => [1]]));
    }

    // =========================================================================
    // String / numeric tests
    // =========================================================================

    public function testStartsWith(): void
    {
        self::tpl('op_starts', "{% if name starts with 'Jo' %}Y{% else %}N{% endif %}");
        $this->assertSame('Y', self::render('op_starts', ['name' => 'John']));
        $this->assertSame('N', self::render('op_starts', ['name' => 'Ada']));
    }

    public function testEndsWith(): void
    {
        self::tpl('op_ends', "{% if name ends with 'hn' %}Y{% else %}N{% endif %}");
        $this->assertSame('Y', self::render('op_ends', ['name' => 'John']));
    }

    public function testMatches(): void
    {
        self::tpl('op_matches', "{% if name matches '/^J/' %}Y{% else %}N{% endif %}");
        $this->assertSame('Y', self::render('op_matches', ['name' => 'John']));
        $this->assertSame('N', self::render('op_matches', ['name' => 'Ada']));
    }

    public function testMatchesIgnoresAnInvalidPattern(): void
    {
        self::tpl('op_matches_bad', "{{ 'x' matches '/[/' ? 'Y' : 'N' }}");
        $this->assertSame('N', self::render('op_matches_bad'));
    }

    public function testDivisibleBy(): void
    {
        self::tpl('op_div', '{% if 9 divisible by 3 %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_div'));

        self::tpl('op_div_no', '{% if 10 divisible by 3 %}Y{% else %}N{% endif %}');
        $this->assertSame('N', self::render('op_div_no'));
    }

    public function testDivisibleByZeroIsFalse(): void
    {
        self::tpl('op_div_zero', '{% if 9 divisible by 0 %}Y{% else %}N{% endif %}');
        $this->assertSame('N', self::render('op_div_zero'));
    }

    public function testSameAs(): void
    {
        self::tpl('op_same', '{% if a is same as(b) %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_same', ['a' => 1, 'b' => 1]));
        $this->assertSame('N', self::render('op_same', ['a' => 1, 'b' => '1']));
    }

    public function testIterable(): void
    {
        self::tpl('op_iterable', '{% if items is iterable %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_iterable', ['items' => [1]]));
        $this->assertSame('N', self::render('op_iterable', ['items' => 'str']));
    }

    public function testEvenAndOdd(): void
    {
        self::tpl('op_even', '{% if n is even %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_even', ['n' => 4]));
        $this->assertSame('N', self::render('op_even', ['n' => 3]));

        self::tpl('op_odd', '{% if n is odd %}Y{% else %}N{% endif %}');
        $this->assertSame('Y', self::render('op_odd', ['n' => 3]));
        $this->assertSame('N', self::render('op_odd', ['n' => 4]));
    }

    // =========================================================================
    // Composition and error reporting
    // =========================================================================

    public function testTestResultIsAnExpression(): void
    {
        self::tpl('op_ternary', '{{ 2 in [1, 2] ? "yes" : "no" }}');
        $this->assertSame('yes', self::render('op_ternary'));
    }

    public function testUnknownTestIsACompileError(): void
    {
        self::tpl('op_unknown', '{% if x is frobnicated %}Y{% endif %}');
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unknown test/');
        self::render('op_unknown', ['x' => 1]);
    }

    // =========================================================================
    // Collection functions
    // =========================================================================

    public function testRangeFunction(): void
    {
        self::tpl('fn_range', '{{ range(1, 5) |> join(",") }}');
        $this->assertSame('1,2,3,4,5', self::render('fn_range'));

        self::tpl('fn_range_step', '{{ range(0, 10, 5) |> join(",") }}');
        $this->assertSame('0,5,10', self::render('fn_range_step'));
    }

    public function testCycleFunction(): void
    {
        self::tpl('fn_cycle', '{{ cycle(["odd", "even"], 1) }}');
        $this->assertSame('even', self::render('fn_cycle'));

        // Wraps, and a negative position counts from the end.
        self::tpl('fn_cycle_wrap', '{{ cycle(["a", "b", "c"], 4) }}');
        $this->assertSame('b', self::render('fn_cycle_wrap'));

        self::tpl('fn_cycle_neg', '{{ cycle(["a", "b", "c"], -1) }}');
        $this->assertSame('c', self::render('fn_cycle_neg'));
    }

    public function testAttributeFunction(): void
    {
        self::tpl('fn_attr', "{{ attribute(user, 'name') }}");
        $this->assertSame('Ada', self::render('fn_attr', ['user' => ['name' => 'Ada']]));

        self::tpl('fn_attr_missing', "{{ attribute(user, 'other', 'n/a') }}");
        $this->assertSame('n/a', self::render('fn_attr_missing', ['user' => ['name' => 'Ada']]));
    }

    public function testAttributeReadsObjectProperty(): void
    {
        $obj = new class {
            public string $name = 'Grace';
        };

        self::tpl('fn_attr_obj', "{{ attribute(user, 'name') }}");
        $this->assertSame('Grace', self::render('fn_attr_obj', ['user' => $obj]));
    }
}
