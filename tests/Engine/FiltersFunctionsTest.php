<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Tokenizer;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

class FiltersFunctionsTest extends BaseTestCase
{
    // =========================================================================
    // Built-in Filters
    // =========================================================================

    public function testFilterUpper(): void
    {
        self::tpl('f_upper', '{{ name |> upper }}');
        $this->assertSame('ALICE', self::render('f_upper', ['name' => 'alice']));
    }

    public function testFilterLower(): void
    {
        self::tpl('f_lower', '{{ name |> lower }}');
        $this->assertSame('bob', self::render('f_lower', ['name' => 'BOB']));
    }

    public function testFilterTrim(): void
    {
        self::tpl('f_trim', '{{ name |> trim }}');
        $this->assertSame('trimmed', self::render('f_trim', ['name' => '  trimmed  ']));
    }

    public function testFilterLength(): void
    {
        self::tpl('f_length', '{{ items |> length }}');
        $this->assertSame('3', self::render('f_length', ['items' => [1, 2, 3]]));
    }

    public function testFilterLengthOnString(): void
    {
        self::tpl('f_strlen', '{{ name |> length }}');
        $this->assertSame('4', self::render('f_strlen', ['name' => 'test']));
    }

    public function testInlineLengthEvaluatesInputExpressionOnce(): void
    {
        $calls = 0;

        TestEnvironment::engine()->addFunction('next_value_for_length', function () use (&$calls): string {
            $calls++;
            return 'test';
        });

        self::tpl('f_length_once', '{{ next_value_for_length() |> length }}');

        $this->assertSame('4', self::render('f_length_once'));
        $this->assertSame(1, $calls);
    }

    public function testFilterNumber(): void
    {
        self::tpl('f_num', '{{ price |> number(2) }}');
        $this->assertSame(number_format(1234.567, 2), self::render('f_num', ['price' => 1234.567]));
    }

    public function testFilterNumberDefaultDecimals(): void
    {
        self::tpl('f_num2', '{{ price |> number }}');
        $this->assertSame('9.99', self::render('f_num2', ['price' => 9.99]));
    }

    public function testInlineFilterMissingRequiredArgumentThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/Missing required argument 'start' for filter 'slice'/");

        self::tpl('f_slice_missing_start', '{{ value |> slice }}');
        self::render('f_slice_missing_start', ['value' => 'hello']);
    }

    public function testInlineFilterTooManyArgumentsThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Filter \'upper\' received too many positional arguments/');

        self::tpl('f_upper_too_many_args', '{{ value |> upper(1) }}');
        self::render('f_upper_too_many_args', ['value' => 'hello']);
    }

    public function testFilterFormat(): void
    {
        self::tpl('f_format', '{{ fmt |> sprintf(name, count) }}');
        $this->assertSame('Hello Alice, 3', self::render('f_format', [
            'fmt' => 'Hello %s, %d',
            'name' => 'Alice',
            'count' => 3,
        ]));
    }

    public function testFilterJson(): void
    {
        self::tpl('f_json', '{{ data |> json |> raw }}');
        $result = self::render('f_json', ['data' => ['a' => 1]]);
        $this->assertSame('{"a":1}', $result);
    }

    public function testFilterDate(): void
    {
        self::tpl('f_date', '{{ ts |> date("Y") }}');
        $ts = mktime(12, 0, 0, 6, 15, 2023);
        $this->assertSame('2023', self::render('f_date', ['ts' => $ts]));
    }

    public function testFilterPipeline(): void
    {
        self::tpl('pipeline', '{{ name |> trim |> upper }}');
        $this->assertSame('ALICE', self::render('pipeline', ['name' => '  alice  ']));
    }

    // -- String filters -------------------------------------------------------

    public function testFilterCapitalize(): void
    {
        self::tpl('f_capitalize', '{{ v |> capitalize }}');
        $this->assertSame('Hello world', self::render('f_capitalize', ['v' => 'hello world']));
    }

    public function testFilterTitle(): void
    {
        self::tpl('f_title', '{{ v |> title }}');
        $this->assertSame('Hello World', self::render('f_title', ['v' => 'hello world']));
    }

    public function testFilterNl2br(): void
    {
        self::tpl('f_nl2br', '{{ v |> nl2br |> raw }}');
        $this->assertSame("a<br />\nb", self::render('f_nl2br', ['v' => "a\nb"]));
    }

    public function testFilterReplace(): void
    {
        self::tpl('f_replace', '{{ v |> replace("world", "earth") }}');
        $this->assertSame('hello earth', self::render('f_replace', ['v' => 'hello world']));
    }

    public function testFilterSplitJoin(): void
    {
        self::tpl('f_split_join', '{{ v |> split(",") |> join("-") }}');
        $this->assertSame('a-b-c', self::render('f_split_join', ['v' => 'a,b,c']));
    }

    public function testFilterSlug(): void
    {
        self::tpl('f_slug', '{{ v |> slug }}');
        $this->assertSame('hello-world', self::render('f_slug', ['v' => 'Hello World!']));
    }

    public function testFilterStriptags(): void
    {
        self::tpl('f_striptags', '{{ v |> striptags }}');
        $this->assertSame('bold', self::render('f_striptags', ['v' => '<b>bold</b>']));
    }

    // -- Number filters -------------------------------------------------------

    public function testFilterAbs(): void
    {
        self::tpl('f_abs', '{{ v |> abs }}');
        $this->assertSame('5', self::render('f_abs', ['v' => 5]));
        $this->assertSame('7', self::render('f_abs', ['v' => -7]));
    }

    public function testFilterRound(): void
    {
        self::tpl('f_round', '{{ v |> round(2) }}');
        $this->assertSame('3.57', self::render('f_round', ['v' => 3.567]));
    }

    public function testFilterRoundDefault(): void
    {
        self::tpl('f_round_default', '{{ v |> round }}');
        $this->assertSame('4', self::render('f_round_default', ['v' => 3.7]));
    }

    public function testFilterCeil(): void
    {
        self::tpl('f_ceil', '{{ v |> ceil }}');
        $this->assertSame('4', self::render('f_ceil', ['v' => 3.2]));
    }

    public function testFilterFloor(): void
    {
        self::tpl('f_floor', '{{ v |> floor }}');
        $this->assertSame('3', self::render('f_floor', ['v' => 3.9]));
    }

    public function testFilterDateCompilesInline(): void
    {
        $tokenizer = new Tokenizer();
        $tokenizer->setRegistry(TestEnvironment::registry());

        $compiled = $tokenizer->buildFilterCall('date("Y")', '$ts');

        $this->assertStringContainsString('\\date(', $compiled);
        $this->assertStringNotContainsString('$this->__fl[\'date\']', $compiled);
    }

    // -- Date filters ---------------------------------------------------------

    public function testFilterDateModify(): void
    {
        self::tpl('f_date_modify', '{{ ts |> date_modify("+1 day") |> date("Y-m-d") }}');
        $ts = mktime(12, 0, 0, 6, 14, 2023);
        $this->assertSame('2023-06-15', self::render('f_date_modify', ['ts' => $ts]));
    }

    // -- Array filters --------------------------------------------------------

    public function testFilterFirst(): void
    {
        self::tpl('f_first', '{{ items |> first }}');
        $this->assertSame('a', self::render('f_first', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterLast(): void
    {
        self::tpl('f_last', '{{ items |> last }}');
        $this->assertSame('c', self::render('f_last', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterKeys(): void
    {
        self::tpl('f_keys', '{{ map |> keys |> join(",") }}');
        $this->assertSame('x,y', self::render('f_keys', ['map' => ['x' => 1, 'y' => 2]]));
    }

    public function testFilterValues(): void
    {
        self::tpl('f_values', '{{ map |> values |> join(",") }}');
        $this->assertSame('1,2', self::render('f_values', ['map' => ['x' => 1, 'y' => 2]]));
    }

    public function testFilterMerge(): void
    {
        self::tpl('f_merge', '{{ a |> merge(b) |> join(",") }}');
        $this->assertSame('1,2,3,4', self::render('f_merge', ['a' => [1, 2], 'b' => [3, 4]]));
    }

    public function testFilterSort(): void
    {
        self::tpl('f_sort', '{{ items |> sort |> join(",") }}');
        $this->assertSame('1,2,3', self::render('f_sort', ['items' => [3, 1, 2]]));
    }

    public function testFilterReverseArray(): void
    {
        self::tpl('f_rev_arr', '{{ items |> reverse |> join(",") }}');
        $this->assertSame('c,b,a', self::render('f_rev_arr', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterReverseString(): void
    {
        self::tpl('f_rev_str', '{{ v |> reverse }}');
        $this->assertSame('cba', self::render('f_rev_str', ['v' => 'abc']));
    }

    public function testFilterShuffle(): void
    {
        self::tpl('f_shuffle', '{{ items |> shuffle |> sort |> join(",") }}');
        $this->assertSame('1,2,3', self::render('f_shuffle', ['items' => [3, 1, 2]]));
    }

    public function testFilterMap(): void
    {
        self::tpl('f_map', '{{ items |> map("upper") |> join(",") }}');
        $this->assertSame('A,B,C', self::render('f_map', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterMapCompilesInlineFilterReference(): void
    {
        $tokenizer = new Tokenizer();
        $tokenizer->setRegistry(TestEnvironment::registry());

        $compiled = $tokenizer->buildFilterCall('map("upper")', '$items');

        $this->assertStringContainsString('static fn(mixed $__val): mixed =>', $compiled);
        $this->assertStringContainsString('\\mb_strtoupper', $compiled);
        $this->assertStringNotContainsString('$this->__fl[\'upper\']', $compiled);
    }

    public function testFilterMapCompilesInlineUnicodeReference(): void
    {
        $tokenizer = new Tokenizer();
        $tokenizer->setRegistry(TestEnvironment::registry());

        $compiled = $tokenizer->buildFilterCall('map("unicode")', '$items');

        $this->assertStringContainsString('static fn(mixed $__val): mixed =>', $compiled);
        $this->assertStringContainsString('new \\Clarity\\Engine\\UnicodeString', $compiled);
        $this->assertStringNotContainsString('$this->__fl[\'unicode\']', $compiled);
    }

    public function testFilterFilter(): void
    {
        self::tpl('f_filter', '{{ items |> filter(item => item) |> join(",") }}');
        $this->assertSame('a,b', self::render('f_filter', ['items' => ['a', '', 'b', '']]));
    }

    public function testFilterReduce(): void
    {
        self::tpl('f_reduce', '{{ items |> reduce(carry, item => carry + item, 0) }}');
        $this->assertSame('10', self::render('f_reduce', ['items' => [1, 2, 3, 4]]));
    }

    public function testFilterBatch(): void
    {
        self::tpl('f_batch_len', '{{ items |> batch(2) |> length }}');
        $this->assertSame('2', self::render('f_batch_len', ['items' => [1, 2, 3, 4]]));
    }

    // -- Lambda expressions ---------------------------------------------------

    public function testLambdaMapFieldAccess(): void
    {
        self::tpl('lambda_map_field', '{{ users |> map(u => u.name) |> join(",") }}');
        $result = self::render('lambda_map_field', [
            'users' => [['name' => 'alice'], ['name' => 'bob'], ['name' => 'carol']],
        ]);
        $this->assertSame('alice,bob,carol', $result);
    }

    public function testLambdaMapWithFilterPipeline(): void
    {
        self::tpl('lambda_map_pipeline', '{{ items |> map(item => item |> upper) |> join(",") }}');
        $this->assertSame('HELLO,WORLD', self::render('lambda_map_pipeline', ['items' => ['hello', 'world']]));
    }

    public function testLambdaMapAccessesOuterVar(): void
    {
        self::tpl('lambda_outer', '{{ items |> map(item => item ~ suffix) |> join(",") }}');
        $this->assertSame('a!,b!,c!', self::render('lambda_outer', [
            'items' => ['a', 'b', 'c'],
            'suffix' => '!',
        ]));
    }

    public function testLambdaFilterByField(): void
    {
        self::tpl(
            'lambda_filter_field',
            '{{ items |> filter(item => item.active) |> map(item => item.label) |> join(",") }}'
        );
        $result = self::render('lambda_filter_field', [
            'items' => [
                ['active' => true, 'label' => 'A'],
                ['active' => false, 'label' => 'B'],
                ['active' => true, 'label' => 'C'],
            ],
        ]);
        $this->assertSame('A,C', $result);
    }

    public function testLambdaFilterByOuterVar(): void
    {
        self::tpl(
            'lambda_filter_outer',
            '{{ items |> filter(item => item.score >= threshold) |> map(item => item.name) |> join(",") }}'
        );
        $result = self::render('lambda_filter_outer', [
            'items' => [['name' => 'a', 'score' => 5], ['name' => 'b', 'score' => 3], ['name' => 'c', 'score' => 7]],
            'threshold' => 5,
        ]);
        $this->assertSame('a,c', $result);
    }

    public function testLambdaReduceSum(): void
    {
        self::tpl('lambda_reduce_sum', '{{ numbers |> reduce(carry, item => carry + item, 0) }}');
        $this->assertSame('10', self::render('lambda_reduce_sum', ['numbers' => [1, 2, 3, 4]]));
    }

    public function testLambdaReduceWithOuterVar(): void
    {
        self::tpl('lambda_reduce_outer', '{{ numbers |> reduce(carry, item => carry + item + bonus, 0) }}');
        $this->assertSame('14', self::render('lambda_reduce_outer', [
            'numbers' => [1, 2, 3, 4],
            'bonus' => 1,
        ]));
    }

    public function testReduceLambdaRequiresTwoParams(): void
    {
        $this->expectException(ClarityException::class);
        self::tpl('lambda_reduce_arity', '{{ numbers |> reduce(carry => carry + item, 0) }}');
        self::render('lambda_reduce_arity', ['numbers' => [1, 2, 3, 4]]);
    }

    public function testFilterReferenceMap(): void
    {
        self::tpl('filter_ref_map', '{{ items |> map("upper") |> join(",") }}');
        $this->assertSame('FOO,BAR', self::render('filter_ref_map', ['items' => ['foo', 'bar']]));
    }

    public function testFilterReferenceReduce(): void
    {
        TestEnvironment::engine()->addFilter('sum2', fn(mixed $carry, mixed $item): mixed => $carry + $item);
        self::tpl('filter_ref_reduce', '{{ numbers |> reduce("sum2", 0) }}');
        $this->assertSame('6', self::render('filter_ref_reduce', ['numbers' => [1, 2, 3]]));
    }

    public function testBareVariableCallableRejectedForMap(): void
    {
        $this->expectException(ClarityException::class);
        self::tpl('reject_map_var', '{{ items |> map(myFn) }}');
        self::render('reject_map_var', ['items' => [1, 2], 'myFn' => 'strtoupper']);
    }

    public function testBareVariableCallableRejectedForFilter(): void
    {
        $this->expectException(ClarityException::class);
        self::tpl('reject_filter_var', '{{ items |> filter(pred) }}');
        self::render('reject_filter_var', ['items' => [1, 2], 'pred' => 'is_int']);
    }

    public function testBareVariableCallableRejectedForReduce(): void
    {
        $this->expectException(ClarityException::class);
        self::tpl('reject_reduce_var', '{{ items |> reduce(fn, 0) }}');
        self::render('reject_reduce_var', ['items' => [1, 2], 'fn' => 'array_sum']);
    }

    public function testFilterBatchWithFill(): void
    {
        self::tpl('f_batch_fill', '{{ items |> batch(3, 0) |> last |> last }}');
        $this->assertSame('0', self::render('f_batch_fill', ['items' => [1, 2, 3, 4]]));
    }

    // -- Utility filters ------------------------------------------------------

    public function testFilterDataUri(): void
    {
        self::tpl('f_data_uri', '{{ v |> data_uri("text/plain") |> raw }}');
        $result = self::render('f_data_uri', ['v' => 'hello']);
        $this->assertSame('data:text/plain;base64,' . base64_encode('hello'), $result);
    }

    // =========================================================================
    // Custom Filters
    // =========================================================================

    public function testCustomFilter(): void
    {
        TestEnvironment::engine()->addFilter('shout', fn(string $v): string => strtoupper($v) . '!!!');
        self::tpl('custom', '{{ message |> shout }}');
        $this->assertSame('HELLO!!!', self::render('custom', ['message' => 'hello']));
    }

    public function testCustomFilterWithArgument(): void
    {
        TestEnvironment::engine()->addFilter('repeat', fn(string $v, int $n): string => str_repeat($v, $n));
        self::tpl('repeat', '{{ word |> repeat(3) }}');
        $this->assertSame('hahaha', self::render('repeat', ['word' => 'ha']));
    }

    // =========================================================================
    // Named Arguments for Filters
    // =========================================================================

    public function testNamedArgSingleBuiltin(): void
    {
        self::tpl('named_number', '{{ v |> number(decimals=2) }}');
        $this->assertSame(number_format(3.14159, 2), self::render('named_number', ['v' => 3.14159]));
    }

    public function testNamedArgCustomFilter(): void
    {
        TestEnvironment::engine()->addFilter('mult', fn(int $v, int $factor = 1): int => $v * $factor);
        self::tpl('named_custom', '{{ v |> mult(factor=3) }}');
        $this->assertSame('15', self::render('named_custom', ['v' => 5]));
    }

    public function testNamedArgSkipsToLaterParam(): void
    {
        self::tpl('named_slug', '{{ v |> slug(separator="_") }}');
        $this->assertSame('hello_world', self::render('named_slug', ['v' => 'Hello World']));
    }

    public function testNamedArgWithGapFilledByDefault(): void
    {
        self::tpl('named_slice_start', '{{ v |> slice(start=2) }}');
        $this->assertSame('cde', self::render('named_slice_start', ['v' => 'abcde']));
    }

    public function testNamedArgAndPositionalMixed(): void
    {
        TestEnvironment::engine()->addFilter('fmtnum', fn(mixed $v, int $dec = 2, string $sep = '.'): string =>
            number_format((float) $v, $dec, $sep));
        self::tpl('named_mixed', '{{ v |> fmtnum(3, sep:",") }}');
        $this->assertSame('3,142', self::render('named_mixed', ['v' => 3.14159]));
    }

    public function testNamedArgUnknownThrows(): void
    {
        $this->expectException(\Throwable::class);
        self::tpl('named_unknown', '{{ v |> number(decimalz:2) }}');
        self::render('named_unknown', ['v' => 1.5]);
    }

    public function testNamedArgPositionalAfterNamedThrows(): void
    {
        $this->expectException(ClarityException::class);
        TestEnvironment::engine()->addFilter('foo', fn(mixed $v, int $a = 1, int $b = 2): int => $v + $a + $b);
        self::tpl('named_positional_after', '{{ v |> foo(a:1, 2) }}');
        self::render('named_positional_after', ['v' => 0]);
    }

    public function testNamedArgPipelinePreserved(): void
    {
        self::tpl('named_pipeline', '{{ v |> trim |> number(decimals=1) }}');
        $this->assertSame(number_format(3.1, 1), self::render('named_pipeline', ['v' => ' 3.14159 ']));
    }

    // =========================================================================
    // Custom Functions
    // =========================================================================

    public function testCustomFunctionSimple(): void
    {
        TestEnvironment::engine()->addFunction('add', fn(int $a, int $b = 1): int => $a + $b);
        self::tpl('func_add', '{{ add(2, 3) }}');
        $this->assertSame('5', self::render('func_add'));
    }

    public function testCustomFunctionNamedArgs(): void
    {
        TestEnvironment::engine()->addFunction('concat', fn(string $a, string $b = ''): string => $a . $b);
        self::tpl('func_concat_named', '{{ concat(b:", world", a:"Hello") }}');
        $this->assertSame('Hello, world', self::render('func_concat_named'));
    }

    public function testCustomFunctionNamedArgWithDefault(): void
    {
        TestEnvironment::engine()->addFunction('incr', fn(int $a, int $inc = 1): int => $a + $inc);
        self::tpl('func_incr_default', '{{ incr(a:3) }}');
        $this->assertSame('4', self::render('func_incr_default'));
    }

    public function testBuiltInContextFunctionReturnsTemplateVars(): void
    {
        self::tpl('builtin_context', '{{ context() |> json |> raw }}');
        $this->assertSame('{"name":"Bob","count":2}', self::render('builtin_context', ['name' => 'Bob', 'count' => 2]));
    }

    public function testBuiltInContextRejectsArguments(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/context\(\) does not accept any arguments/');
        self::tpl('builtin_context_args', '{{ context(name) |> json |> raw }}');
        self::render('builtin_context_args', ['name' => 'Bob']);
    }
}
