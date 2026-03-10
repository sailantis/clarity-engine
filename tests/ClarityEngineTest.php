<?php
namespace Clarity\Tests;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Engine\Cache;
use Clarity\Engine\Registry;
use Clarity\Engine\Tokenizer;
use PHPUnit\Framework\TestCase;

class TestClarityEngine extends ClarityEngine
{
    public function getRegistry(): Registry
    {
        return $this->registry;
    }
}

class ClarityEngineTest extends TestCase
{
    private static string $viewDir;
    private static string $cacheDir;
    private static ClarityEngine $engine;

    private static Registry $registry;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        echo sys_get_temp_dir(), "\n";
        $testId = 'static'; //bin2hex(random_bytes(4));
        self::$viewDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_views_' . $testId;
        self::$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_cache_' . $testId;
        @mkdir(self::$viewDir, 0755, true);
        @mkdir(self::$cacheDir, 0755, true);

        self::$engine = new TestClarityEngine();
        self::$engine
            ->setViewPath(self::$viewDir)
            ->setCachePath(self::$cacheDir)
            ->setExtension('clarity.html');
        self::$registry = self::$engine->getRegistry();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDir(self::$viewDir);
        self::removeDir(self::$cacheDir);
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Write a template file and return the view name relative to viewDir. */
    protected static function tpl(string $name, string $content): string
    {
        $path = self::$viewDir . DIRECTORY_SEPARATOR . $name . '.clarity.html';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (is_file($path)) {
            if (file_get_contents($path) === $content) {
                // No need to rewrite identical content — preserves cache validity.
                return $name;
            }
        }
        file_put_contents($path, $content);
        return $name;
    }

    protected static function render(string $view, array $vars = []): string
    {
        return self::$engine->renderPartial($view, $vars);
    }

    /**
     * Return the source path exactly as resolveView() would produce it — using
     * the '/' separator — so that MD5-based cache keys match.
     */
    protected static function normalizedSourcePath(string $view): string
    {
        return self::$viewDir . '/' . $view . '.clarity.html';
    }

    protected static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // Variable Output
    // =========================================================================

    public function testSimpleVariable(): void
    {
        self::tpl('simple', 'Hello {{ name }}!');
        $this->assertSame('Hello World!', self::render('simple', ['name' => 'World']));
    }

    public function testAutoEscape(): void
    {
        self::tpl('escape', '{{ html }}');
        $result = self::render('escape', ['html' => '<script>alert(1)</script>']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
    }

    public function testRawFilterSuppressesEscape(): void
    {
        self::tpl('raw', '{{ html |> raw }}');
        $result = self::render('raw', ['html' => '<b>bold</b>']);
        $this->assertSame('<b>bold</b>', $result);
    }

    public function testDotAccessOnArray(): void
    {
        self::tpl('dot', '{{ user.name }}');
        $result = self::render('dot', ['user' => ['name' => 'Alice']]);
        $this->assertSame('Alice', $result);
    }

    public function testNestedDotAccess(): void
    {
        self::tpl('nested', '{{ a.b.c }}');
        $result = self::render('nested', ['a' => ['b' => ['c' => 'deep']]]);
        $this->assertSame('deep', $result);
    }

    public function testNumericIndexAccess(): void
    {
        self::tpl('index', '{{ items[0] }}');
        $result = self::render('index', ['items' => ['first', 'second']]);
        $this->assertSame('first', $result);
    }

    public function testDynamicIndexAccess(): void
    {
        self::tpl('dynidx', '{{ items[idx] }}');
        $result = self::render('dynidx', ['items' => ['a', 'b', 'c'], 'idx' => 2]);
        $this->assertSame('c', $result);
    }

    public function testNestedDynamicIndexAccess(): void
    {
        self::tpl('nested_dynidx', '{{ items[indexes[i + 1]] }}');
        $result = self::render('nested_dynidx', [
            'items' => ['x', 'y', 'z', 'w'],
            'indexes' => [0, 2, 3],
            'i' => 1,
        ]);
        $this->assertSame('w', $result);
    }

    public function testStringLiteralOutput(): void
    {
        self::tpl('literal', '{{ "hello" }}');
        $this->assertSame('hello', self::render('literal'));
    }

    public function testStringLiteralWithEscapedQuoteAndPipelineToken(): void
    {
        self::tpl('literal_escaped_pipe', '{{ "a\"|>b" }}');
        $this->assertSame('a&quot;|&gt;b', self::render('literal_escaped_pipe'));
    }

    public function testNullCoalescing(): void
    {
        self::tpl('nullcoal', '{{ missing ?? "default" }}');
        $this->assertSame('default', self::render('nullcoal', []));
    }

    public function testConcatenation(): void
    {
        self::tpl('concat', '{{ first ~ " " ~ last }}');
        $result = self::render('concat', ['first' => 'John', 'last' => 'Doe']);
        $this->assertSame('John Doe', $result);
    }

    public function testArrayLiteralCanBePassedToFilters(): void
    {
        self::tpl('array_literal_filter', '{{ [1, 2, user.id] |> json |> raw }}');
        $result = self::render('array_literal_filter', ['user' => ['id' => 3]]);
        $this->assertSame('[1,2,3]', $result);
    }

    public function testObjectLiteralCanBePassedToFilters(): void
    {
        self::tpl(
            'object_literal_filter',
            '{{ { foo: "bar", count: count, nested: { id: user.id }, items: [1, 2] } |> json |> raw }}'
        );

        $result = self::render('object_literal_filter', [
            'count' => 3,
            'user' => ['id' => 7],
        ]);

        $this->assertSame('{"foo":"bar","count":3,"nested":{"id":7},"items":[1,2]}', $result);
    }

    public function testLiteralCollectionsSupportPostfixAccess(): void
    {
        self::tpl(
            'literal_postfix_access',
            '{{ { user: { name: "Alice" } }.user.name ~ ":" ~ [10, 20, 30][1] }}'
        );

        $this->assertSame('Alice:20', self::render('literal_postfix_access'));
    }

    public function testSetCanStoreNestedLiteralCollections(): void
    {
        self::tpl(
            'set_literal_collection',
            '{% set payload = { meta: { total: count }, items: [1, 2, 3] } %}{{ payload.meta.total ~ ":" ~ payload.items[2] }}'
        );

        $this->assertSame('5:3', self::render('set_literal_collection', ['count' => 5]));
    }

    public function testArrayLiteralSupportsSpread(): void
    {
        self::tpl('array_spread', '{{ [1, ...items, 4] |> json |> raw }}');
        $this->assertSame('[1,2,3,4]', self::render('array_spread', ['items' => [2, 3]]));
    }

    public function testObjectLiteralSupportsSpread(): void
    {
        self::tpl('object_spread', '{{ { foo: "bar", ...payload, answer: 42 } |> json |> raw }}');
        $result = self::render('object_spread', ['payload' => ['name' => 'Merlin']]);
        $this->assertSame('{"foo":"bar","name":"Merlin","answer":42}', $result);
    }

    public function testSpreadOutsideCollectionThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Spread operator is only allowed inside array and object literals/');
        self::tpl('invalid_spread', '{{ include("x", ...context()) }}');
        self::render('invalid_spread');
    }

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

        self::$engine->addFunction('next_value_for_length', function () use (&$calls): string {
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
        // number_format includes thousands separator
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
        self::tpl('f_format', '{{ fmt |> format(name, count) }}');
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
        $tokenizer->setRegistry(self::$registry);

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
        // Quoted map references can resolve to built-in inline filters as well.
        self::tpl('f_map', '{{ items |> map("upper") |> join(",") }}');
        $this->assertSame('A,B,C', self::render('f_map', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterMapCompilesInlineFilterReference(): void
    {
        $tokenizer = new Tokenizer();
        $tokenizer->setRegistry(self::$registry);

        $compiled = $tokenizer->buildFilterCall('map("upper")', '$items');

        $this->assertStringContainsString('static fn(mixed $__val): mixed =>', $compiled);
        $this->assertStringContainsString('\\mb_strtoupper', $compiled);
        $this->assertStringNotContainsString('$this->__fl[\'upper\']', $compiled);
    }

    public function testFilterMapCompilesInlineUnicodeReference(): void
    {
        $tokenizer = new Tokenizer();
        $tokenizer->setRegistry(self::$registry);

        $compiled = $tokenizer->buildFilterCall('map("unicode")', '$items');

        $this->assertStringContainsString('static fn(mixed $__val): mixed =>', $compiled);
        $this->assertStringContainsString('new \\Clarity\\Engine\\UnicodeString', $compiled);
        $this->assertStringNotContainsString('$this->__fl[\'unicode\']', $compiled);
    }

    public function testFilterFilter(): void
    {
        // Lambda: keep only truthy (non-empty) items.
        self::tpl('f_filter', '{{ items |> filter(item => item) |> join(",") }}');
        $this->assertSame('a,b', self::render('f_filter', ['items' => ['a', '', 'b', '']]));
    }

    public function testFilterReduce(): void
    {
        // Reduce lambda uses explicit accumulator and item parameters.
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
        // Lambda extracts a nested field from each array element.
        self::tpl('lambda_map_field', '{{ users |> map(u => u.name) |> join(",") }}');
        $result = self::render('lambda_map_field', [
            'users' => [['name' => 'alice'], ['name' => 'bob'], ['name' => 'carol']],
        ]);
        $this->assertSame('alice,bob,carol', $result);
    }

    public function testLambdaMapWithFilterPipeline(): void
    {
        // Lambda body can itself use the |> filter pipeline.
        self::tpl('lambda_map_pipeline', '{{ items |> map(item => item |> upper) |> join(",") }}');
        $this->assertSame('HELLO,WORLD', self::render('lambda_map_pipeline', ['items' => ['hello', 'world']]));
    }

    public function testLambdaMapAccessesOuterVar(): void
    {
        // Lambda closes over outer template variables via $vars capture.
        self::tpl('lambda_outer', '{{ items |> map(item => item ~ suffix) |> join(",") }}');
        $this->assertSame('a!,b!,c!', self::render('lambda_outer', [
            'items' => ['a', 'b', 'c'],
            'suffix' => '!',
        ]));
    }

    public function testLambdaFilterByField(): void
    {
        // Lambda keeps only active items, then extracts labels.
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
        // Lambda condition references an outer template variable.
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
        // Reduce uses explicit accumulator and item parameters.
        self::tpl('lambda_reduce_sum', '{{ numbers |> reduce(carry, item => carry + item, 0) }}');
        $this->assertSame('10', self::render('lambda_reduce_sum', ['numbers' => [1, 2, 3, 4]]));
    }

    public function testLambdaReduceWithOuterVar(): void
    {
        // Reduce lambda accesses an outer template variable.
        self::tpl('lambda_reduce_outer', '{{ numbers |> reduce(carry, item => carry + item + bonus, 0) }}');
        // 4 elements, each adds value + bonus(=1), sums (1+1)+(2+1)+(3+1)+(4+1) = 14
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
        // A quoted string resolves to a registered Clarity filter as callable.
        self::tpl('filter_ref_map', '{{ items |> map("upper") |> join(",") }}');
        $this->assertSame('FOO,BAR', self::render('filter_ref_map', ['items' => ['foo', 'bar']]));
    }

    public function testFilterReferenceReduce(): void
    {
        // Register a custom 'sum2' filter and use it as a reference inside reduce.
        self::$engine->addFilter('sum2', fn(mixed $carry, mixed $item): mixed => $carry + $item);
        self::tpl('filter_ref_reduce', '{{ numbers |> reduce("sum2", 0) }}');
        $this->assertSame('6', self::render('filter_ref_reduce', ['numbers' => [1, 2, 3]]));
    }

    public function testBareVariableCallableRejectedForMap(): void
    {
        // Passing a bare variable name as callable must be rejected at compile time.
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
        self::$engine->addFilter('shout', fn(string $v): string => strtoupper($v) . '!!!');
        self::tpl('custom', '{{ message |> shout }}');
        $this->assertSame('HELLO!!!', self::render('custom', ['message' => 'hello']));
    }

    public function testCustomFilterWithArgument(): void
    {
        self::$engine->addFilter('repeat', fn(string $v, int $n): string => str_repeat($v, $n));
        self::tpl('repeat', '{{ word |> repeat(3) }}');
        $this->assertSame('hahaha', self::render('repeat', ['word' => 'ha']));
    }

    // =========================================================================
    // Named Arguments for Filters
    // =========================================================================

    public function testNamedArgSingleBuiltin(): void
    {
        // number(decimals=2) — named single arg, same result as positional
        self::tpl('named_number', '{{ v |> number(decimals=2) }}');
        $this->assertSame(number_format(3.14159, 2), self::render('named_number', ['v' => 3.14159]));
    }

    public function testNamedArgCustomFilter(): void
    {
        // Custom filter with named arg
        self::$engine->addFilter('mult', fn(int $v, int $factor = 1): int => $v * $factor);
        self::tpl('named_custom', '{{ v |> mult(factor=3) }}');
        $this->assertSame('15', self::render('named_custom', ['v' => 5]));
    }

    public function testNamedArgSkipsToLaterParam(): void
    {
        // slug(separator=…) — skip first optional param 'separator' which is already
        // at position 0 (after $value), so this is equivalent to slug('_')
        self::tpl('named_slug', '{{ v |> slug(separator="_") }}');
        $this->assertSame('hello_world', self::render('named_slug', ['v' => 'Hello World']));
    }

    public function testNamedArgWithGapFilledByDefault(): void
    {
        // slice has params ($start, $length=null). Use only 'length' → start defaults to 0.
        // Actually let's use 'number' with a non-first named param.
        // number($decimals=2), only one extra param so no gap possible there.
        // Use slice: slice(start=2) — length gets its default (null = no limit)
        self::tpl('named_slice_start', '{{ v |> slice(start=2) }}');
        $this->assertSame('cde', self::render('named_slice_start', ['v' => 'abcde']));
    }

    public function testNamedArgAndPositionalMixed(): void
    {
        // Positional first, then named for remaining
        self::$engine->addFilter('fmtnum', fn(mixed $v, int $dec = 2, string $sep = '.'): string =>
            number_format((float) $v, $dec, $sep));
        self::tpl('named_mixed', '{{ v |> fmtnum(3, sep:",") }}');
        $this->assertSame('3,142', self::render('named_mixed', ['v' => 3.14159]));
    }

    public function testNamedArgUnknownThrows(): void
    {
        // 'decimals' is the correct name; 'decimalz' is a typo.
        // With the reflection-free approach, PHP validates named arg names at
        // runtime (Error), not at compile time (ClarityException).
        $this->expectException(\Throwable::class);
        self::tpl('named_unknown', '{{ v |> number(decimalz:2) }}');
        self::render('named_unknown', ['v' => 1.5]);
    }

    public function testNamedArgPositionalAfterNamedThrows(): void
    {
        $this->expectException(ClarityException::class);
        // Positional after named is caught at compile time as a ClarityException
        // (avoids generating syntactically invalid PHP).
        self::$engine->addFilter('foo', fn(mixed $v, int $a = 1, int $b = 2): int => $v + $a + $b);
        self::tpl('named_positional_after', '{{ v |> foo(a:1, 2) }}');
        self::render('named_positional_after', ['v' => 0]);
    }

    public function testNamedArgPipelinePreserved(): void
    {
        // Named args work in a pipeline alongside other filters
        self::tpl('named_pipeline', '{{ v |> trim |> number(decimals=1) }}');
        $this->assertSame(number_format(3.1, 1), self::render('named_pipeline', ['v' => ' 3.14159 ']));
    }

    // =========================================================================
    // Custom Functions
    // =========================================================================

    public function testCustomFunctionSimple(): void
    {
        self::$engine->addFunction('add', fn(int $a, int $b = 1): int => $a + $b);
        self::tpl('func_add', '{{ add(2, 3) }}');
        $this->assertSame('5', self::render('func_add'));
    }

    public function testCustomFunctionNamedArgs(): void
    {
        self::$engine->addFunction('concat', fn(string $a, string $b = ''): string => $a . $b);
        self::tpl('func_concat_named', '{{ concat(b:", world", a:"Hello") }}');
        $this->assertSame('Hello, world', self::render('func_concat_named'));
    }

    public function testCustomFunctionNamedArgWithDefault(): void
    {
        self::$engine->addFunction('incr', fn(int $a, int $inc = 1): int => $a + $inc);
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

    // =========================================================================
    // Control Flow – if / elseif / else / endif
    // =========================================================================

    public function testIfTrue(): void
    {
        self::tpl('if_true', '{% if show %}yes{% endif %}');
        $this->assertSame('yes', self::render('if_true', ['show' => true]));
    }

    public function testIfFalse(): void
    {
        self::tpl('if_false', '{% if show %}yes{% endif %}');
        $this->assertSame('', self::render('if_false', ['show' => false]));
    }

    public function testIfElse(): void
    {
        self::tpl('if_else', '{% if flag %}A{% else %}B{% endif %}');
        $this->assertSame('A', self::render('if_else', ['flag' => true]));
        $this->assertSame('B', self::render('if_else', ['flag' => false]));
    }

    public function testElseif(): void
    {
        $tpl = '{% if x == 1 %}one{% elseif x == 2 %}two{% else %}other{% endif %}';
        self::tpl('elseif', $tpl);
        $this->assertSame('one', self::render('elseif', ['x' => 1]));
        $this->assertSame('two', self::render('elseif', ['x' => 2]));
        $this->assertSame('other', self::render('elseif', ['x' => 9]));
    }

    public function testLogicalOperatorsAndOr(): void
    {
        self::tpl('logic', '{% if a and b %}yes{% else %}no{% endif %}');
        $this->assertSame('yes', self::render('logic', ['a' => true, 'b' => true]));
        $this->assertSame('no', self::render('logic', ['a' => true, 'b' => false]));
    }

    public function testLogicalNot(): void
    {
        self::tpl('not', '{% if not flag %}off{% else %}on{% endif %}');
        $this->assertSame('off', self::render('not', ['flag' => false]));
    }

    // =========================================================================
    // Control Flow – for / endfor
    // =========================================================================

    public function testForLoop(): void
    {
        self::tpl('for', '{% for item in list %}{{ item }},{% endfor %}');
        $this->assertSame('a,b,c,', self::render('for', ['list' => ['a', 'b', 'c']]));
    }

    public function testForLoopEmpty(): void
    {
        self::tpl('for_empty', '{% for item in list %}{{ item }}{% endfor %}none');
        $this->assertSame('none', self::render('for_empty', ['list' => []]));
    }

    public function testNestedForLoop(): void
    {
        $tpl = '{% for row in rows %}{% for cell in row %}{{ cell }}{% endfor %}|{% endfor %}';
        self::tpl('nested_for', $tpl);
        $result = self::render('nested_for', ['rows' => [['a', 'b'], ['c', 'd']]]);
        $this->assertSame('ab|cd|', $result);
    }

    public function testForLoopIndexWithStyle(): void
    {
        // {% for item, idx in list %} exposes the key as idx
        self::tpl('for_idx_with', '{% for item, idx in list %}{{ idx }}:{{ item }},{% endfor %}');
        $this->assertSame('0:a,1:b,2:c,', self::render('for_idx_with', ['list' => ['a', 'b', 'c']]));
    }

    public function testForLoopIndexWithStyleAssocArray(): void
    {
        // Works with associative arrays; idx holds the string key
        self::tpl('for_idx_with_assoc', '{% for v, k in map %}{{ k }}={{ v }},{% endfor %}');
        $this->assertSame('x=1,y=2,', self::render('for_idx_with_assoc', ['map' => ['x' => 1, 'y' => 2]]));
    }

    public function testForLoopIndexNestedNoCollision(): void
    {
        // Nested loops with independent index variables must not interfere
        $tpl = '{% for outer, oi in rows %}{% for inner, ii in outer %}{{ oi }}.{{ ii }}:{{ inner }},{% endfor %}{% endfor %}';
        self::tpl('for_nested_idx', $tpl);
        $result = self::render('for_nested_idx', ['rows' => [['a', 'b'], ['c']]]);
        $this->assertSame('0.0:a,0.1:b,1.0:c,', $result);
    }

    // =========================================================================
    // Control Flow – range loops ({% for i in start..end %})
    // =========================================================================

    public function testRangeExclusive(): void
    {
        // 1..5 → 1, 2, 3, 4  (exclusive upper bound)
        self::tpl('range_excl', '{% for i in 1..5 %}{{ i }},{% endfor %}');
        $this->assertSame('1,2,3,4,', self::render('range_excl'));
    }

    public function testRangeInclusive(): void
    {
        // 1...5 → 1, 2, 3, 4, 5  (inclusive upper bound)
        self::tpl('range_incl', '{% for i in 1...5 %}{{ i }},{% endfor %}');
        $this->assertSame('1,2,3,4,5,', self::render('range_incl'));
    }

    public function testRangeWithStep(): void
    {
        // 1..10 step 3 → 1, 4, 7  (exclusive, step 3)
        self::tpl('range_step', '{% for i in 1..10 step 3 %}{{ i }},{% endfor %}');
        $this->assertSame('1,4,7,', self::render('range_step'));
    }

    public function testRangeInclusiveWithStep(): void
    {
        // 0...8 step 4 → 0, 4, 8  (inclusive, step 4)
        self::tpl('range_incl_step', '{% for i in 0...8 step 4 %}{{ i }},{% endfor %}');
        $this->assertSame('0,4,8,', self::render('range_incl_step'));
    }

    public function testRangeFromVariables(): void
    {
        // start and end come from template variables
        self::tpl('range_vars', '{% for i in start...end %}{{ i }},{% endfor %}');
        $this->assertSame('3,4,5,', self::render('range_vars', ['start' => 3, 'end' => 5]));
    }

    public function testRangeStepFromVariable(): void
    {
        // step also comes from a template variable
        self::tpl('range_step_var', '{% for i in 0..10 step s %}{{ i }},{% endfor %}');
        $this->assertSame('0,5,', self::render('range_step_var', ['s' => 5]));
    }

    public function testRangeZeroBased(): void
    {
        // Common pattern: 0-based index
        self::tpl('range_zero', '{% for i in 0..3 %}{{ i }},{% endfor %}');
        $this->assertSame('0,1,2,', self::render('range_zero'));
    }

    public function testNestedRangeLoop(): void
    {
        // Nested range loops; endfor must close matching loop type
        $tpl = "{% for r in 1...2 %}\n{% for c in 1...2 %}{{ r }}{{ c }},{% endfor %}\n{% endfor %}";
        self::tpl('range_nested', $tpl);
        $this->assertSame('11,12,21,22,', self::render('range_nested'));
    }

    public function testMixedRangeAndForeach(): void
    {
        // A range loop nested inside a foreach and vice-versa
        $tpl = '{% for item in list %}{% for i in 1...2 %}{{ item }}{{ i }},{% endfor %}{% endfor %}';
        self::tpl('range_mixed', $tpl);
        $this->assertSame('a1,a2,b1,b2,', self::render('range_mixed', ['list' => ['a', 'b']]));
    }

    public function testRangeZeroStepThrows(): void
    {
        self::tpl('range_zero_step', '{% for i in 1..5 step s %}{{ i }}{% endfor %}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/step cannot be zero/');
        self::render('range_zero_step', ['s' => 0]);
    }

    public function testRangeWrongDirectionThrows(): void
    {
        // Step is positive, but start > end with exclusive bound → step moves away from end
        self::tpl('range_bad_dir', '{% for i in 10..1 %}{{ i }}{% endfor %}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/infinite loop/');
        self::render('range_bad_dir');
    }

    public function testRangeNegativeStepWrongDirectionThrows(): void
    {
        // Step is negative, but start < end → step moves away from end
        self::tpl('range_neg_bad', '{% for i in 1...10 step s %}{{ i }}{% endfor %}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/infinite loop/');
        self::render('range_neg_bad', ['s' => -1]);
    }

    // =========================================================================
    // Set directive
    // =========================================================================

    public function testSetDirective(): void
    {
        self::tpl('set', '{% set greeting = "hi" %}{{ greeting }}');
        $this->assertSame('hi', self::render('set'));
    }

    public function testSetFromVariable(): void
    {
        self::tpl('set_var', '{% set x = count %}double={{ x }}');
        $this->assertSame('double=5', self::render('set_var', ['count' => 5]));
    }

    // =========================================================================
    // Include
    // =========================================================================

    public function testInclude(): void
    {
        self::tpl('partials/greeting', 'Hi {{ name }}');
        self::tpl('main', '{% include "partials/greeting" %} there');
        $result = self::render('main', ['name' => 'Bob']);
        $this->assertSame('Hi Bob there', $result);
    }

    public function testStaticIncludeRecursionThrows(): void
    {
        self::tpl('partials/loop_a', 'A {% include "partials/loop_b" %}');
        self::tpl('partials/loop_b', 'B {% include "partials/loop_a" %}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Recursive static include detected/');
        self::render('partials/loop_a');
    }

    public function testDynamicIncludeFunctionRendersTemplateWithContext(): void
    {
        self::tpl('partials/card', '<b>{{ foo }}</b> {{ name }}');
        self::tpl('dynamic_include', '{{ include("partials/card", { foo: "bar", ...context() }) }}');

        $result = self::render('dynamic_include', ['name' => 'Bob']);
        $this->assertSame('<b>bar</b> Bob', $result);
    }

    public function testDynamicIncludeAssignedViaSetRemainsUnescaped(): void
    {
        self::tpl('partials/inline_html', '<em>{{ name }}</em>');
        self::tpl('dynamic_include_set', '{% set content = include("partials/inline_html", context()) %}{{ content |> raw }}');

        $result = self::render('dynamic_include_set', ['name' => 'Bob']);
        $this->assertSame('<em>Bob</em>', $result);
    }

    public function testDynamicIncludeRemainsSafeAcrossNestedContext(): void
    {
        self::tpl('partials/inner_html', '<strong>{{ name }}</strong>');
        self::tpl('partials/outer_html', '{{ snippet |> raw }}');
        self::tpl(
            'dynamic_include_nested_context',
            '{% set snippet = include("partials/inner_html", context()) %}{{ include("partials/outer_html", context()) }}'
        );

        $result = self::render('dynamic_include_nested_context', ['name' => 'Bob']);
        $this->assertSame('<strong>Bob</strong>', $result);
    }

    public function testDynamicIncludeFunctionSupportsNamespacedTemplates(): void
    {
        $nsPath = self::$viewDir . DIRECTORY_SEPARATOR . 'namespaced';
        @mkdir($nsPath, 0755, true);
        file_put_contents($nsPath . DIRECTORY_SEPARATOR . 'badge.clarity.html', '<span>{{ label }}</span>');
        self::$engine->addNamespace('ui', $nsPath);

        self::tpl('dynamic_include_ns', '{{ include("ui::badge", { label: "ok" }) }}');
        $this->assertSame('<span>ok</span>', self::render('dynamic_include_ns'));
    }

    public function testDynamicIncludeRecursionThrows(): void
    {
        self::tpl('dynamic_loop', '{{ include("dynamic_loop", context()) }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Recursive template rendering detected/');
        self::render('dynamic_loop');
    }

    // =========================================================================
    // Extends / Block
    // =========================================================================

    public function testExtendsBlock(): void
    {
        self::tpl('layout', '<html>{% block content %}default{% endblock %}</html>');
        self::tpl('child', '{% extends "layout" %}{% block content %}Hello, {{ name }}!{% endblock %}');
        $result = self::render('child', ['name' => 'World']);
        $this->assertSame('<html>Hello, World!</html>', $result);
    }

    public function testBlockFallback(): void
    {
        self::tpl('layout2', '[{% block title %}Default Title{% endblock %}]');
        self::tpl('child2', '{% extends "layout2" %}');
        $result = self::render('child2');
        $this->assertSame('[Default Title]', $result);
    }

    // =========================================================================
    // Object Casting
    // =========================================================================

    public function testObjectCasting(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Charlie';
        self::tpl('obj', '{{ person.name }}');
        $result = self::render('obj', ['person' => $obj]);
        $this->assertSame('Charlie', $result);
    }

    public function testObjectWithToArray(): void
    {
        $obj = new class {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };
        self::tpl('toarray', '{{ item.key }}');
        $result = self::render('toarray', ['item' => $obj]);
        $this->assertSame('value', $result);
    }

    public function testJsonSerializableObjectCasting(): void
    {
        $obj = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['x' => 42];
            }
        };
        self::tpl('jsonser', '{{ data.x }}');
        $result = self::render('jsonser', ['data' => $obj]);
        $this->assertSame('42', $result);
    }

    // =========================================================================
    // Cache Behavior
    // =========================================================================

    public function testCacheHitProducesSameOutput(): void
    {
        self::tpl('cached', 'Value={{ val }}');
        $first = self::render('cached', ['val' => 'A']);
        $second = self::render('cached', ['val' => 'A']);
        $this->assertSame($first, $second);
    }

    public function testCacheFileIsCreated(): void
    {
        self::tpl('cachefile', 'hi');
        self::render('cachefile');

        // resolveView() uses forward-slash; normalise so MD5 matches engine output
        $sourcePath = $this->normalizedSourcePath('cachefile');
        $cache = new Cache(self::$cacheDir);
        $this->assertTrue($cache->isFresh($sourcePath), 'Expected a fresh cache entry after first render');
    }

    public function testInlineFilterCompilesWithoutRuntimeRegistryLookup(): void
    {
        self::tpl('inline_upper_cache', '{{ name |> upper }}');
        self::render('inline_upper_cache', ['name' => 'alice']);

        $sourcePath = $this->normalizedSourcePath('inline_upper_cache');
        $cache = new Cache(self::$cacheDir);
        $compiled = file_get_contents($cache->cacheFilePath($sourcePath));

        $this->assertIsString($compiled);
        $this->assertStringContainsString('mb_strtoupper', $compiled);
        $this->assertStringNotContainsString("__fl['upper']", $compiled);
    }

    public function testCacheInvalidatedOnTemplateChange(): void
    {
        // After a template file changes, isFresh() must report stale and a
        // re-render within the same PHP process must produce the updated output.
        // Versioned class names (unique per compile) make this safe: the old
        // class stays in memory under its old name while the new class is
        // declared under a fresh name — no redeclaration collision.

        // Write the file unconditionally with a known past mtime so any
        // pre-existing cache entry from a previous run is guaranteed stale —
        // avoiding a timing collision where the stored dep-mtime accidentally
        // matches the freshly-written file's mtime.
        $file = self::$viewDir . DIRECTORY_SEPARATOR . 'changing.clarity.html';
        file_put_contents($file, 'first');
        touch($file, time() - 100);

        $sourcePath = $this->normalizedSourcePath('changing');
        $cache = new Cache(self::$cacheDir);
        // Discard any compiled class (and its stale class-name registry entry)
        // that a previous run may have left behind.
        $cache->invalidate($sourcePath);

        $this->assertSame('first', self::render('changing'));

        // Confirm it starts fresh
        $this->assertTrue($cache->isFresh($sourcePath));

        // Overwrite the template with new content and bump mtime
        $file = self::$viewDir . DIRECTORY_SEPARATOR . 'changing.clarity.html';
        file_put_contents($file, 'second');
        touch($file, filemtime($file) + 2);

        // Cache must now report stale
        $this->assertFalse($cache->isFresh($sourcePath));

        // Re-rendering in the same process must pick up the new content
        $this->assertSame('second', self::render('changing'));
    }

    public function testClassNameForIsDeterministic(): void
    {
        $cache = new Cache(self::$cacheDir);
        $path = '/some/template.clarity.html';
        $this->assertSame($cache->classNameFor($path), $cache->classNameFor($path));
        $this->assertSame('__Clarity_' . md5($path), $cache->classNameFor($path));
    }

    public function testClassNameForIsUniquePerPath(): void
    {
        $cache = new Cache(self::$cacheDir);
        $this->assertNotSame(
            $cache->classNameFor('/a/template.clarity.html'),
            $cache->classNameFor('/b/template.clarity.html')
        );
    }

    public function testCacheIsFreshAfterFirstRender(): void
    {
        self::tpl('freshtest', 'ok');
        self::render('freshtest');

        // resolveView() joins with '/' so the MD5 must be computed on that path
        $sourcePath = $this->normalizedSourcePath('freshtest');
        $cache = new Cache(self::$cacheDir);
        $this->assertTrue($cache->isFresh($sourcePath));
    }

    // =========================================================================
    // Security – function call prevention
    // =========================================================================

    public function testFunctionCallInOutputTagThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_output', "{{ system('id') }}");
        self::render('sec_output');
    }

    public function testFunctionCallInSetDirectiveThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_set', "{% set x = system('id') %}{{ x }}");
        self::render('sec_set');
    }

    public function testFunctionCallInIfConditionThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_if', "{% if system('id') %}yes{% endif %}");
        self::render('sec_if');
    }

    public function testFunctionCallInRangeBoundThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_range', "{% for i in system ('id')...10 %}{{ i }}{% endfor %}");
        self::render('sec_range');
    }

    public function testFunctionCallInFilterArgumentThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_filter_arg', "{{ name |> substr(system('id'), 1) }}");
        self::render('sec_filter_arg');
    }

    // =========================================================================
    // Error Mapping
    // =========================================================================

    public function testClarityExceptionCarriesTemplateLine(): void
    {
        // Use an undefined filter; the engine should throw ClarityException.
        self::tpl('broken', '{{ name |> nonExistentFilter }}');

        // Clean up any dangling output buffers the engine may leave on exception
        $obLevel = ob_get_level();
        try {
            self::render('broken', ['name' => 'x']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertInstanceOf(ClarityException::class, $e);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }
    }

    public function testSyntaxErrorInExpressionIsMappedToClarityException(): void
    {
        // A dangling operator produces invalid PHP in the compiled cache file,
        // triggering a ParseError at require-time before the class is loaded.
        $this->tpl('syntax_err', "static line\n{{ message + }}\nstatic line");

        $obLevel = ob_get_level();
        try {
            self::render('syntax_err', ['message' => 'hello']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            // The exception must be a ClarityException (not a raw ParseError).
            $this->assertInstanceOf(ClarityException::class, $e);
            // It must point at the .clarity.html source file.
            $this->assertStringContainsString('syntax_err', $e->templateFile);
            // The message must mention the underlying syntax problem.
            $this->assertStringContainsString('syntax', strtolower($e->getMessage()));
            // The previous exception must be the original ParseError.
            $this->assertInstanceOf(\ParseError::class, $e->getPrevious());
            // Line 2 of the template contains the broken {{ … }} expression.
            $this->assertSame(2, $e->templateLine);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }
    }

    // =========================================================================
    // Whitespace / Literals
    // =========================================================================

    public function testStaticTextIsPassedThrough(): void
    {
        self::tpl('static', '<p>Hello, world!</p>');
        $this->assertSame('<p>Hello, world!</p>', self::render('static'));
    }

    public function testMultilineTemplate(): void
    {
        $tpl = "line1\nline2\n{{ value }}\nline4";
        self::tpl('multiline', $tpl);
        $this->assertSame("line1\nline2\nhello\nline4", self::render('multiline', ['value' => 'hello']));
    }

    // =========================================================================
    // Engine Configuration
    // =========================================================================

    public function testNamespaceSupport(): void
    {
        $nsDir = self::$viewDir . DIRECTORY_SEPARATOR . 'ns';
        @mkdir($nsDir, 0755, true);
        file_put_contents($nsDir . DIRECTORY_SEPARATOR . 'hello.clarity.html', 'ns:{{ x }}');
        self::$engine->addNamespace('mns', $nsDir);

        $result = $this->render('mns::hello', ['x' => '42']);
        $this->assertSame('ns:42', $result);
    }

    /**
     * Uses a private, isolated cache directory so that flushing does not
     * destroy the shared cache files that all other tests rely on across runs.
     */
    public function testFlushCacheRemovesCachedFiles(): void
    {
        $isolatedCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_flush_isolated';
        @mkdir($isolatedCache, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath($isolatedCache);

        self::tpl('flush_me', 'hi');
        $engine->renderPartial('flush_me');
        $engine->flushCache();

        $files = glob($isolatedCache . DIRECTORY_SEPARATOR . '*.php');
        $this->assertEmpty($files);

        // Clean up
        @rmdir($isolatedCache);
    }

    // =========================================================================
    // Module system
    // =========================================================================

    public function testUseCallsModuleRegister(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        $registered = false;
        $module = new class ($registered) implements \Clarity\Module {
            public function __construct(private bool &$flag)
            {}
            public function register(ClarityEngine $e): void
            {
                $this->flag = true; }
        };

        $this->assertFalse($registered);
        $engine->use($module);
        $this->assertTrue($registered);
    }

    public function testUseIsFluent(): void
    {
        $engine = new ClarityEngine();
        $module = new class implements \Clarity\Module {
            public function register(ClarityEngine $e): void
            {
            }
        };
        $result = $engine->use($module);
        $this->assertSame($engine, $result);
    }

    public function testModuleCanRegisterFilter(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        $module = new class implements \Clarity\Module {
            public function register(ClarityEngine $e): void
            {
                $e->addFilter('shout', fn(string $v): string => strtoupper($v) . '!!!');
            }
        };
        $engine->use($module);

        self::tpl('mod_filter', '{{ word |> shout }}');
        $result = $engine->renderPartial('mod_filter', ['word' => 'hello']);
        $this->assertSame('HELLO!!!', $result);
    }

    // =========================================================================
    // Custom block directives (BlockRegistry)
    // =========================================================================

    public function testAddBlockRegistersCustomDirective(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);
        $engine->addBlock('noop', fn(string $r, string $p, int $l, callable $e): string => '/* noop */');
        $engine->addBlock('endnoop', fn(string $r, string $p, int $l, callable $e): string => '/* endnoop */');

        self::tpl('block_noop', '{% noop %}inner{% endnoop %}');
        $result = $engine->renderPartial('block_noop');
        $this->assertSame('inner', $result);
    }

    public function testUnknownDirectiveStillThrows(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/Unknown directive 'totally_unknown'/");
        self::tpl('bad_directive', '{% totally_unknown %}');
        $engine->renderPartial('bad_directive');
    }

    public function testBlockHandlerCanProcessExpression(): void
    {
        // A custom block that wraps its content in a tag determined by an expression
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        $engine->addBlock('tag', function (string $rest, string $path, int $line, callable $expr): string {
            $phpTag = $expr($rest);    // converts Clarity expression → PHP
            return "ob_start(); \$__tag = {$phpTag};";
        });
        $engine->addBlock(
            'endtag',
            fn(string $r, string $p, int $l, callable $e): string =>
            'echo "<" . htmlspecialchars((string)$__tag) . ">" . ob_get_clean() . "</" . htmlspecialchars((string)$__tag) . ">";'
        );

        self::tpl('block_expr', '{% tag tagName %}hello{% endtag %}');
        $result = $engine->renderPartial('block_expr', ['tagName' => 'span']);
        $this->assertSame('<span>hello</span>', $result);
    }

    // =========================================================================
    // Inline filter registration (addInlineFilter)
    // =========================================================================

    public function testAddInlineFilterCompilesAtCompileTime(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        // Register an inline filter — it is baked into the generated PHP,
        // not called at runtime via $this->__fl.
        $engine->addInlineFilter('bang', [
            'php' => '((string) {1}) . "!"',
        ]);

        self::tpl('inline_bang', '{{ word |> bang }}');
        $result = $engine->renderPartial('inline_bang', ['word' => 'hello']);
        $this->assertSame('hello!', $result);
    }

    public function testAddInlineFilterWithParamsAndDefaults(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        $engine->addInlineFilter('repeat_str', [
            'php' => '\str_repeat((string) {1}, {2})',
            'params' => ['times'],
            'defaults' => ['times' => '2'],
        ]);

        self::tpl('inline_repeat', '{{ ch |> repeat_str }}:{{ ch |> repeat_str(4) }}');
        $result = $engine->renderPartial('inline_repeat', ['ch' => 'ab']);
        $this->assertSame('abab:abababab', $result);
    }

    // =========================================================================
    // Filter service (addFilterService)
    // =========================================================================

    public function testAddFilterServiceExposedInTemplate(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        // An "inline" filter whose PHP template calls a method on a service object
        $counter = new class {
            public int $n = 0;
            public function next(): int
            {
                return ++$this->n;
            }
        };
        $engine->addService('counter', $counter);
        $engine->addInlineFilter('counted', [
            'php' => '((string) {1}) . "#" . $__sv[\'counter\']->next()',
        ]);

        self::tpl('svc_filter', '{{ a |> counted }}:{{ b |> counted }}');
        $result = $engine->renderPartial('svc_filter', ['a' => 'x', 'b' => 'y']);
        $this->assertSame('x#1:y#2', $result);
    }

    // =========================================================================
    // Localization module — ClarityLocale (unit)
    // =========================================================================

    public function testClarityLocalePushPop(): void
    {
        $locale = new \Clarity\Localization\LocaleStack('en_US');
        $this->assertSame('en_US', $locale->current());

        $locale->push('de_DE');
        $this->assertSame('de_DE', $locale->current());

        $locale->push('fr_FR');
        $this->assertSame('fr_FR', $locale->current());

        $locale->pop();
        $this->assertSame('de_DE', $locale->current());

        $locale->pop();
        $this->assertSame('en_US', $locale->current());
    }

    public function testClarityLocaleIgnoresNullAndEmptyPush(): void
    {
        $locale = new \Clarity\Localization\LocaleStack('en_US');
        $locale->push(null);
        $locale->push('');
        $this->assertSame('en_US', $locale->current());
    }

    public function testClarityLocalePopOnEmptyStackIsNoOp(): void
    {
        $locale = new \Clarity\Localization\LocaleStack('en_US');
        $locale->pop(); // should not throw
        $this->assertSame('en_US', $locale->current());
    }

    // =========================================================================
    // Localization module — TranslationLoader (unit)
    // =========================================================================

    public function testTranslationLoaderSimpleGet(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_translations_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/en_US.php', '<?php return ' . \var_export(['greeting' => 'Hello, {name}!'], true) . ';');

        $loader = new \Clarity\Localization\TranslationLoader($dir, 'en_US');
        $result = $loader->get('en_US', 'greeting', ['name' => 'Alice']);
        $this->assertSame('Hello, Alice!', $result);

        @unlink($dir . '/en_US.php');
        @rmdir($dir);
    }

    public function testTranslationLoaderFallsBackToFallbackLocale(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_translations_fallback_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/en_US.php', '<?php return ' . \var_export(['save' => 'Save'], true) . ';');
        // de_DE.php does NOT have 'save'

        $loader = new \Clarity\Localization\TranslationLoader($dir, 'en_US');
        $result = $loader->get('de_DE', 'save');
        $this->assertSame('Save', $result);

        @unlink($dir . '/en_US.php');
        @rmdir($dir);
    }

    public function testTranslationLoaderFallsBackToKeyWhenMissing(): void
    {
        $loader = new \Clarity\Localization\TranslationLoader(null, 'en_US');
        $result = $loader->get('de_DE', 'some.missing.key');
        $this->assertSame('some.missing.key', $result);
    }

    // =========================================================================
    // Localization module — ClarityLocalizationModule (integration)
    // =========================================================================

    private function makeLocaleEngine(?string $translationsDir = null): ClarityEngine
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);
        $engine->use(new \Clarity\Localization\LocalizationModule([
            'locale' => 'en_US',
            'fallback_locale' => 'en_US',
            'translations_path' => $translationsDir,
        ]));
        return $engine;
    }

    public function testTFilterSimpleTranslation(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_lmod_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/en_US.php', '<?php return ' . \var_export(['hello' => 'Hello World'], true) . ';');

        $engine = $this->makeLocaleEngine($dir);
        self::tpl('lmod_t_simple', '{{ "hello" |> t }}');
        $result = $engine->renderPartial('lmod_t_simple');
        $this->assertSame('Hello World', $result);

        @unlink($dir . '/en_US.php');
        @rmdir($dir);
    }

    public function testTFilterFallsBackToKeyWhenNoTranslation(): void
    {
        $engine = $this->makeLocaleEngine(null);
        self::tpl('lmod_t_missing', '{{ "missing.key" |> t }}');
        $result = $engine->renderPartial('lmod_t_missing');
        $this->assertSame('missing.key', $result);
    }

    public function testCurrencyFilter(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = $this->makeLocaleEngine(null);
        self::tpl('lmod_currency', '{{ price |> currency("USD", "en_US") }}');
        $result = $engine->renderPartial('lmod_currency', ['price' => 1234.56]);
        $this->assertStringContainsString('1,234.56', $result);
    }

    public function testWithLocaleBlockChangesLocale(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);

        $module = new \Clarity\Localization\LocalizationModule(['locale' => 'en_US']);
        $engine->use($module);

        // Outside the block, locale = en_US; inside, locale = de_DE
        self::tpl('lmod_with_locale', '{{ 1234.56 |> currency("EUR", "en_US") }}|{% with_locale "de_DE" %}{{ 1234.56 |> currency("EUR", "de_DE") }}{% endwith_locale %}');
        $result = $engine->renderPartial('lmod_with_locale');

        // en_US EUR format includes € and uses comma as thousands separator
        [$outside, $inside] = explode('|', $result, 2);
        $this->assertStringContainsString('1,234.56', $outside);
        $this->assertStringContainsString('1.234,56', $inside);
    }

    public function testWithLocaleBlockRestoresLocaleAfter(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);
        $engine->use(new \Clarity\Localization\LocalizationModule(['locale' => 'en_US']));

        self::tpl(
            'lmod_locale_restore',
            '{% with_locale "de_DE" %}inner{% endwith_locale %}{{ 1234.56 |> currency("EUR", "en_US") }}'
        );
        $result = $engine->renderPartial('lmod_locale_restore');
        $this->assertStringContainsString('1,234.56', $result);
    }

    public function testWithLocaleRequiresArgument(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);
        $engine->use(new \Clarity\Localization\LocalizationModule(['locale' => 'en_US']));

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'with_locale' requires/");
        self::tpl('lmod_no_arg', '{% with_locale %}oops{% endwith_locale %}');
        $engine->renderPartial('lmod_no_arg');
    }

    public function testWithLocaleAcceptsVariableExpression(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(self::$viewDir)->setCachePath(self::$cacheDir);
        $engine->use(new \Clarity\Localization\LocalizationModule(['locale' => 'en_US']));

        self::tpl('lmod_var_locale', '{% with_locale userLocale %}{{ 1234.56 |> currency("EUR", "de_DE") }}{% endwith_locale %}');
        $result = $engine->renderPartial('lmod_var_locale', ['userLocale' => 'de_DE']);
        $this->assertStringContainsString('1.234,56', $result);
    }
}
