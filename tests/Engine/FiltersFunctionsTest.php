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
            'fmt'   => 'Hello %s, %d',
            'name'  => 'Alice',
            'count' => 3,
        ]));
    }

    /**
     * `format` is an alias of `sprintf`, kept for Twig parity — Twig's variadic
     * formatter is spelled `format` and also takes the value first, so the two
     * names are behaviourally identical.
     */
    public function testFilterFormatAlias(): void
    {
        self::tpl('f_format_alias', '{{ fmt |> format(name, count) }}');
        $this->assertSame('Hello Alice, 3', self::render('f_format_alias', [
            'fmt'   => 'Hello %s, %d',
            'name'  => 'Alice',
            'count' => 3,
        ]));
    }

    public function testFilterFormatAndSprintfAreInterchangeable(): void
    {
        self::tpl('f_fmt_pipe', '{{ fmt |> format(name, count) }}');
        self::tpl('f_sprintf_pipe', '{{ fmt |> sprintf(name, count) }}');

        $vars = ['fmt' => '%s/%d', 'name' => 'x', 'count' => 7];
        $this->assertSame('x/7', self::render('f_fmt_pipe', $vars));
        $this->assertSame(self::render('f_fmt_pipe', $vars), self::render('f_sprintf_pipe', $vars));
    }

    public function testFilterFormatAliasIsInline(): void
    {
        // The alias compiles to the SAME inline PHP as `sprintf`, so it must not
        // reach the runtime registry.
        self::tpl('f_format_alias_inline', '{{ fmt |> format(name) }}');
        self::render('f_format_alias_inline', ['fmt' => '%s', 'name' => 'x']);

        $compiled = $this->compiledSource('f_format_alias_inline');

        $this->assertStringContainsString('\sprintf', $compiled);
        $this->assertStringNotContainsString("\$__c_fn['format']", $compiled);
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

    /**
     * The default ellipsis must be the actual ellipsis character, not the
     * literal escape sequence `\u{2026}`. The default is emitted verbatim into
     * the compiled PHP, so it is written as a double-quoted literal — a
     * single-quoted one would pass seven literal characters straight through.
     */
    public function testFilterTruncateDefaultEllipsis(): void
    {
        self::tpl('f_truncate_default', "{{ v |> truncate(5) }}");
        $this->assertSame('abcde…', self::render('f_truncate_default', ['v' => 'abcdefghij']));
    }

    public function testFilterTruncateWithoutTruncationIsUntouched(): void
    {
        self::tpl('f_truncate_short', '{{ v |> truncate(20) }}');
        $this->assertSame('short', self::render('f_truncate_short', ['v' => 'short']));
    }

    public function testFilterTruncateCustomEllipsis(): void
    {
        self::tpl('f_truncate_custom', "{{ v |> truncate(5, '...') }}");
        $this->assertSame('abcde...', self::render('f_truncate_custom', ['v' => 'abcdefghij']));
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
        $this->assertStringNotContainsString('$this->__c_fn[\'date\']', $compiled);
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

        $this->assertStringContainsString('static fn(mixed $__c_val): mixed =>', $compiled);
        $this->assertStringContainsString('\\mb_strtoupper', $compiled);
        $this->assertStringNotContainsString('$this->__c_fn[\'upper\']', $compiled);
    }

    public function testFilterFilter(): void
    {
        self::tpl('f_filter', '{{ items |> filter(item => item) |> join(",") }}');
        $this->assertSame('a,b', self::render('f_filter', ['items' => ['a', '', 'b', '']]));
    }

    // -- Key preservation -----------------------------------------------------
    //
    // `array_map` with a single array and `array_filter` both PRESERVE keys.
    // These pin that contract: a hand-rolled loop using `$out[] = …` would
    // silently reindex and destroy associative structures, and these tests are
    // what would catch it.

    public function testMapPreservesAssociativeKeys(): void
    {
        self::tpl('f_map_keys', '{{ data |> map("upper") |> json |> raw }}');
        $this->assertSame(
            '{"a":"X","b":"Y"}',
            self::render('f_map_keys', ['data' => ['a' => 'x', 'b' => 'y']])
        );
    }

    public function testMapPreservesLambdaResultKeys(): void
    {
        self::tpl('f_map_keys_lam', '{{ data |> map(v => v ~ "!") |> json |> raw }}');
        $this->assertSame(
            '{"a":"x!","b":"y!"}',
            self::render('f_map_keys_lam', ['data' => ['a' => 'x', 'b' => 'y']])
        );
    }

    public function testFilterPreservesKeysAndDoesNotReindex(): void
    {
        self::tpl('f_filter_keys', '{{ data |> filter("length") |> json |> raw }}');
        $this->assertSame(
            '{"a":"x","c":"yy"}',
            self::render('f_filter_keys', ['data' => ['a' => 'x', 'b' => '', 'c' => 'yy']]),
            'filter must drop failing elements WITHOUT reindexing the survivors'
        );
    }

    public function testFilterFollowedByValuesReindexes(): void
    {
        // `values` is the documented escape hatch for a zero-based list.
        self::tpl('f_filter_values', '{{ data |> filter("length") |> values |> json |> raw }}');
        $this->assertSame(
            '["x","yy"]',
            self::render('f_filter_values', ['data' => ['a' => 'x', 'b' => '', 'c' => 'yy']])
        );
    }

    public function testFilterPredicateDoesNotReplaceTheElement(): void
    {
        // The callable is a PREDICATE: its result is tested and the ORIGINAL
        // element passes through. `trim` yields a truthy string for ' a ', but
        // the element is kept verbatim — not replaced by the trimmed value.
        self::tpl('f_filter_predicate', '{{ data |> filter("trim") |> join(",") }}');
        $this->assertSame(' a ,b', self::render('f_filter_predicate', ['data' => [' a ', 'b']]));
    }

    public function testMapOnAnEmptyArrayYieldsAnEmptyArray(): void
    {
        self::tpl('f_map_empty', '{{ data |> map("upper") |> json |> raw }}');
        $this->assertSame('[]', self::render('f_map_empty', ['data' => []]));
    }

    public function testReduceOnAnEmptyArrayReturnsTheInitialValue(): void
    {
        self::tpl('f_reduce_empty', '{{ data |> reduce(c, i => c + i, 42) }}');
        $this->assertSame('42', self::render('f_reduce_empty', ['data' => []]));
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
        self::tpl('lambda_map_field', '{{ users |> map(u => u:name) |> join(",") }}');
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
            'items'  => ['a', 'b', 'c'],
            'suffix' => '!',
        ]));
    }

    public function testLambdaFilterByField(): void
    {
        self::tpl(
            'lambda_filter_field',
            '{{ items |> filter(item => item:active) |> map(item => item:label) |> join(",") }}'
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
            '{{ items |> filter(item => item:score >= threshold) |> map(item => item:name) |> join(",") }}'
        );
        $result = self::render('lambda_filter_outer', [
            'items'     => [['name' => 'a', 'score' => 5], ['name' => 'b', 'score' => 3], ['name' => 'c', 'score' => 7]],
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
            'bonus'   => 1,
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
        // `trim` yields a numeric STRING, which reaches `number(decimals=1)` under
        // the default strict policy. It works because `number` casts its value with
        // `(float)`: `number_format()` takes neither a string nor a null, so it is
        // the one built-in that cannot rely on weak mode.
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

    public function testBuiltInVarsFunctionReturnsTemplateVars(): void
    {
        self::tpl('builtin_vars', '{{ vars() |> json |> raw }}');
        $this->assertSame('{"name":"Bob","count":2}', self::render('builtin_vars', ['name' => 'Bob', 'count' => 2]));
    }

    public function testBuiltInVarsRejectsArguments(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/vars\(\) does not accept any arguments/');
        self::tpl('builtin_vars_args', '{{ vars(name) |> json |> raw }}');
        self::render('builtin_vars_args', ['name' => 'Bob']);
    }

    public function testBuiltInVarsAliasRejectsArgumentsUnderItsOwnName(): void
    {
        // The error names the function that was actually written, so the message
        // points at the line the author has to change.
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/vars\(\) does not accept any arguments/');
        self::tpl('builtin_vars_args', '{{ vars(name) |> json |> raw }}');
        self::render('builtin_vars_args', ['name' => 'Bob']);
    }

    public function testVarsSeesTheLoopVariableItIsCalledInside(): void
    {
        // A `{% for %}` variable is a PHP local, not a `$__c_va` entry — nothing
        // writes it back into the scope array. A snapshot that only read
        // `$__c_va` would silently omit it, which is the trap this covers.
        self::tpl('vars_in_for', '{% for user in users %}{{ vars() |> json |> raw }};{% endfor %}');
        $this->assertSame(
            '{"users":["a","b"],"user":"a"};{"users":["a","b"],"user":"b"};',
            self::render('vars_in_for', ['users' => ['a', 'b']])
        );
    }

    public function testVarsSeesBothLoopVariablesOfTheKeyValueForm(): void
    {
        // `vars():i` cannot be chained (a call result is not a chain root), so the
        // snapshot is bound first — the idiom the docs already use for `vars()`.
        self::tpl(
            'vars_in_for_kv',
            '{% for i, user in users %}{% set v = vars() %}{{ v:i }}-{{ v:user }};{% endfor %}'
        );
        $this->assertSame('0-a;1-b;', self::render('vars_in_for_kv', ['users' => ['a', 'b']]));
    }

    public function testVarsSeesTheRangeLoopVariable(): void
    {
        self::tpl('vars_in_range', '{% for i in 1..2 %}{% set v = vars() %}{{ v:i }}{% endfor %}');
        $this->assertSame('12', self::render('vars_in_range'));
    }

    public function testVarsSeesAMacroParameter(): void
    {
        // A macro parameter is bound to `$__c_m_<name>`, so gathering it by
        // spelling `'$' . $name` would read an undefined variable and render an
        // empty label. This pins the mapped local, not the name.
        self::tpl(
            'vars_in_macro',
            '{% macro badge(label) %}{% set v = vars() %}{{ v:label }}{% endmacro %}{% call badge("new") %}'
        );
        $this->assertSame('new', self::render('vars_in_macro'));
    }

    public function testVarsAfterTheLoopDoesNotLeakTheLoopBinding(): void
    {
        // The loop local survives the loop at RUNTIME (nothing unsets it), but the
        // compiler has restored its compile scope by then, so a later vars() is
        // emitted as a plain scope read and reports nothing extra. The visibility
        // is therefore compile-time scoped, exactly like a normal variable read.
        self::tpl('vars_after_for', '{% for user in users %}{% endfor %}{{ vars() |> json |> raw }}');
        $this->assertSame('{"users":["a","b"]}', self::render('vars_after_for', ['users' => ['a', 'b']]));
    }

    public function testVarsDoesNotDisturbSelfReferentialSet(): void
    {
        // vars() must not write into the scope array: a `{% set %}` that reads
        // itself would otherwise assign into a copy the snapshot had replaced,
        // and every later read would see the stale value.
        self::tpl(
            'vars_then_set_self',
            '{% set n = vars() |> length %}{{ n }}{% set n = n + 1 %}{{ n }}{% set v = vars() %}{{ v:n }}'
        );
        // 233: at its own right-hand side `n` is not yet bound (2 vars), then the
        // increment reads the value the assignment just stored (3), and the last
        // snapshot sees it too. A snapshot that wrote into the scope array would
        // make the second read see the stale copy instead.
        $this->assertSame('233', self::render('vars_then_set_self', ['a' => 1, 'b' => 2]));
    }

    public function testVarsStillSeesAVariableSetInsideALoop(): void
    {
        // A `{% set %}` target is not a loop-local: it writes THROUGH the scope
        // array in sandbox mode, so it needs no gathering and is visible at once.
        self::tpl(
            'vars_set_in_for',
            '{% for i in 1..1 %}{% set total = 5 %}{% set v = vars() %}{{ v:total }}{{ v:i }}{% endfor %}'
        );
        $this->assertSame('51', self::render('vars_set_in_for'));
    }

    public function testVarsSnapshotInsideAnIncludeSpreadsIntoAnObject(): void
    {
        self::tpl('partials/badge', '<b>{{ title }}</b> {{ name }}');
        self::tpl('vars_include', '{{ include("partials/badge", { title: "Hi", ...vars() }) }}');
        $this->assertSame('<b>Hi</b> Bob', self::render('vars_include', ['name' => 'Bob']));
    }

    // =========================================================================
    // Bare | as filter pipe (Twig/Svelte compat syntax)
    // =========================================================================

    public function testBarePipeActsAsFilterPipe(): void
    {
        self::tpl('bare_pipe_basic', '{{ name | upper }}');
        $this->assertSame('ALICE', self::render('bare_pipe_basic', ['name' => 'alice']));
    }

    public function testBarePipeChained(): void
    {
        self::tpl('bare_pipe_chain', '{{ name | upper | trim }}');
        $this->assertSame('  BOB  ' === '  BOB  ' ? 'BOB' : 'BOB', self::render('bare_pipe_chain', ['name' => '  bob  ']));
    }

    public function testBarePipeAndFatPipeCoexist(): void
    {
        // Both syntaxes work; mixing them in the same expression is fine.
        self::tpl('bare_pipe_mix', '{{ name | upper |> trim }}');
        $this->assertSame('ALICE', self::render('bare_pipe_mix', ['name' => ' alice ']));
    }

    public function testBarePipeDoesNotAffectLogicalOr(): void
    {
        self::tpl('bare_pipe_logical_or', '{% if a or b %}yes{% else %}no{% endif %}');
        $this->assertSame('yes', self::render('bare_pipe_logical_or', ['a' => false, 'b' => true]));
    }

    public function testDoublePipeLogicalOrUnchanged(): void
    {
        // || must never be treated as two filter pipes
        self::tpl('double_pipe_or', '{% if a || b %}yes{% else %}no{% endif %}');
        $this->assertSame('yes', self::render('double_pipe_or', ['a' => false, 'b' => true]));
        $this->assertSame('no', self::render('double_pipe_or', ['a' => false, 'b' => false]));
    }

    public function testBitwiseOrKeywordUnchangedWithBarePipe(): void
    {
        // bor still produces bitwise OR even when | acts as filter pipe
        self::tpl('bor_with_bare_pipe', '{{ a bor b }}');
        $this->assertSame('7', self::render('bor_with_bare_pipe', ['a' => 5, 'b' => 3]));
    }

    public function testBarePipeInCondition(): void
    {
        // The filter result must be grouped: (items | length) > 0
        // because "length > 0" alone is not a valid filter name.
        self::tpl('bare_pipe_condition', '{% if (items | length) > 0 %}yes{% else %}no{% endif %}');
        $this->assertSame('yes', self::render('bare_pipe_condition', ['items' => ['a', 'b']]));
        $this->assertSame('no', self::render('bare_pipe_condition', ['items' => []]));
    }

    public function testBarePipeInsideParenthesesIsNotSplit(): void
    {
        // A | inside a function argument (depth > 0) must not be treated as a pipe
        TestEnvironment::engine()->addFunction('choose', fn($a, $b) => $a ?: $b);
        self::tpl('bare_pipe_depth', '{{ choose(x, y) | upper }}');
        $this->assertSame('HELLO', self::render('bare_pipe_depth', ['x' => 'hello', 'y' => 'world']));
    }

    // =========================================================================
    // No casts in the built-in filters — what weak mode does now
    // =========================================================================
    //
    // The inline-filter templates no longer cast their input, so the coercion a
    // template observes is PHP's own (weak mode) rather than the engine's.
    // `strictTypes` is on by default, so "weak mode" is now something a test
    // opts into — these use `weakEngine()` to pin what that mode still does.

    public function testAnIntegerStillCoercesIntoAStringFilter(): void
    {
        // `upper` is `\mb_strtoupper({1})` now; weak mode coerces the int itself.
        self::tpl('nocast_upper_int', '{{ 42 |> upper }}');
        $this->assertSame('42', self::weakEngine()->renderPartial('nocast_upper_int'));
    }

    public function testAnIntegerIntoAStringFilterThrowsUnderTheDefaultPolicy(): void
    {
        // The counterpart: with `strictTypes` on — the default — weak mode's
        // coercion is what a template no longer gets. `mb_strtoupper()` wants a
        // string and says so.
        self::tpl('nocast_upper_int_strict', '{{ 42 |> upper }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/must be of type string, int given/');
        self::render('nocast_upper_int_strict');
    }

    public function testANullStillRendersEmptyThroughAStringFilter(): void
    {
        // The one place weak mode does NOT fully reproduce the old cast:
        // `(string) null` was a silent '', and `mb_strtoupper(null)` is a
        // DEPRECATION. The value is unchanged — still '' — and that is what this
        // pins.
        //
        // The diagnostic is the point of removing the cast, and it is raised inside
        // the compiled body, so PHP reports it the way PHP reports any deprecation:
        // by the application's `error_reporting` / `display_errors` settings. The
        // engine's handler deliberately excludes deprecations, so Clarity does not
        // swallow it and does not turn it into a `ClarityException` either — it
        // belongs to the app's error handling. Deprecations are disabled here only
        // so the notice PHP prints (display_errors is on under CLI) does not land
        // in the assertion; production runs with them off.
        self::tpl('nocast_upper_null', '{{ null |> upper }}');

        $saved = \error_reporting(\E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED);
        try {
            $output = self::weakEngine()->renderPartial('nocast_upper_null');
        } finally {
            \error_reporting($saved);
        }

        $this->assertSame('', $output);
    }

    public function testANullThroughAStringFilterThrowsUnderTheDefaultPolicy(): void
    {
        // And the case that bites real templates: a missing or null value arriving
        // at a string filter used to be a deprecation and an empty string; it is
        // now a mapped ClarityException. Pinned because it is the most likely
        // breakage the default flip causes in an existing application.
        self::tpl('nocast_upper_null_strict', '{{ null |> upper }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/must be of type string, null given/');
        self::render('nocast_upper_null_strict');
    }

    public function testJoinStillAcceptsAnArray(): void
    {
        self::tpl('nocast_join_array', "{{ items |> join(',') }}");
        $this->assertSame('1,2', self::render('nocast_join_array', ['items' => [1, 2]]));
    }

    public function testJoinNoLongerWrapsAScalar(): void
    {
        // `(array)` was never a coercion — PHP has no scalar→array coercion in
        // either mode — so removing it changes the DEFAULT mode: a scalar that
        // used to become a one-element array now raises. This is the breaking
        // half of the cast removal, pinned so it is a decision and not a
        // surprise.
        self::tpl('nocast_join_scalar', "{{ 42 |> join(',') }}");

        $this->expectException(ClarityException::class);
        $this->render('nocast_join_scalar');
    }

    public function testNumberIsTheOneFilterThatMustCastItsValue(): void
    {
        // `number_format()` takes a float — verified, not assumed — so a numeric
        // string reaching it is a type error under the strict default, and `number`
        // would be unusable in a pipeline whose preceding step yields a string
        // (`{{ v |> trim |> number(1) }}`). It therefore keeps an explicit
        // `(float)` cast, and this pins both halves: it works under the default,
        // and it works in weak mode, which is *different* from every other
        // built-in — they coerce in weak mode and throw in strict.
        self::tpl('nocast_number_string', "{{ ' 3.14 ' |> trim |> number(1) }}");

        $this->assertSame('3.1', self::render('nocast_number_string'));
        $this->assertSame('3.1', self::weakEngine()->renderPartial('nocast_number_string'));
    }

    public function testANumericStringIntoARoundThrowsUnderTheDefaultPolicy(): void
    {
        // The third row of the "why strict by default" table in the policy manual:
        // `round`, `ceil` and `floor` take `int|float`, so a numeric string from a
        // form field or query parameter — which weak mode happily formats — is a
        // type error under the default. This is the mistake the default exists to
        // surface: a value that looked right and was not.
        self::tpl('nocast_round_string', "{{ ' 3.14 ' |> round(1) }}");

        $this->assertSame('3.1', self::weakEngine()->renderPartial('nocast_round_string'));

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/round\(\): Argument #1 .*string given/');
        self::render('nocast_round_string');
    }

    // -- date_modify: the format argument ------------------------------------

    public function testDateModifyDefaultsToIso8601(): void
    {
        self::tpl('date_modify_default', "{{ ts |> date_modify('+1 day') }}");
        $ts = mktime(12, 0, 0, 6, 14, 2023);
        $this->assertSame('2023-06-15T12:00:00+00:00', self::render('date_modify_default', ['ts' => $ts]));
    }

    public function testDateModifyAcceptsAFormatArgument(): void
    {
        // The direct form, which replaces `… |> date_modify('+1 day') |> date('Y-m-d')`
        // as the idiom. The chain still works (see testFilterDateModify) because
        // `date` parses the ISO string the default produces.
        self::tpl('date_modify_format', "{{ ts |> date_modify('+1 day', 'Y-m-d') }}");
        $ts = mktime(12, 0, 0, 6, 14, 2023);
        $this->assertSame('2023-06-15', self::render('date_modify_format', ['ts' => $ts]));
    }
}
