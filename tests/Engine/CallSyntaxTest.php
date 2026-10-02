<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use Clarity\Tests\TestEnvironment;

/**
 * The unified call model: a registered name works as a FILTER (pipe) and as a
 * FUNCTION (call) — one namespace, two signatures selected by syntax.
 *
 * For the ~30 non-divergent builtins the two forms are byte-identical
 * (`round(x, 2)` == `x |> round(2)`); only `date` and `join` diverge, and there
 * the call form mirrors the underlying PHP builtin (`date($format, $ts)`,
 * `implode($glue, $array)`).
 */
class CallSyntaxTest extends BaseTestCase
{
    private static function openEngine(array $config = []): TestClarityEngine
    {
        return new TestClarityEngine(\array_merge([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
            'policy'    => Policy::unrestricted(),
        ], $config));
    }

    // =========================================================================
    // Non-divergent names: call form == filter form
    // =========================================================================

    public function testCallFormMatchesFilterFormForRound(): void
    {
        self::tpl('cs_round_call', '{{ round(1.5, 2) }}');
        self::tpl('cs_round_pipe', '{{ 1.5 |> round(2) }}');
        $this->assertSame(self::render('cs_round_pipe'), self::render('cs_round_call'));
    }

    public function testCallFormForStringFilters(): void
    {
        self::tpl('cs_upper_call', "{{ upper('hi') }}");
        self::tpl('cs_trim_call', "{{ trim('  hi  ') }}");
        self::tpl('cs_abs_call', '{{ abs(-4) }}');

        $this->assertSame('HI', self::render('cs_upper_call'));
        $this->assertSame('hi', self::render('cs_trim_call'));
        $this->assertSame('4', self::render('cs_abs_call'));
    }

    public function testCallFormWithMultipleArgs(): void
    {
        self::tpl('cs_replace_call', "{{ replace('a-b', '-', '_') }}");
        self::tpl('cs_number_call', '{{ number(1234.5, 2) }}');
        self::tpl('cs_slice_call', "{{ slice('abcde', 1, 3) }}");

        $this->assertSame('a_b', self::render('cs_replace_call'));
        $this->assertSame(number_format(1234.5, 2), self::render('cs_number_call'));
        $this->assertSame('bcd', self::render('cs_slice_call'));
    }

    public function testCallFormUsesDefaultsWhenOmitted(): void
    {
        // number()'s `decimals` defaults to 2; slice()'s `length` defaults to null.
        self::tpl('cs_number_default', '{{ number(9.999) }}');
        self::tpl('cs_slice_default', "{{ slice('abcde', 2) }}");

        $this->assertSame('10.00', self::render('cs_number_default'));
        $this->assertSame('cde', self::render('cs_slice_default'));
    }

    public function testCallFormWithNamedArgs(): void
    {
        self::tpl('cs_number_named', '{{ number(9.876, decimals: 1) }}');
        $this->assertSame('9.9', self::render('cs_number_named'));
    }

    public function testCallFormIsCompiledInlineNotDispatched(): void
    {
        // An inline (php-templated) name must not reach the runtime filter array.
        self::tpl('cs_inline_probe', "{{ round(1.5, 2) }}");
        self::render('cs_inline_probe');

        $compiled = $this->compiledSource('cs_inline_probe');
        $this->assertStringNotContainsString("\$__c_fn['round']", $compiled);
    }

    /**
     * Structural invariant: for every name whose value param is unset
     * (`valueParam` null) the function form and the filter form are the SAME
     * computation — the piped value fills slot 1 and the call's first argument
     * does too. Checked behaviourally across a broad slice of the catalog.
     */
    public function testCallFormMatchesFilterFormAcrossNames(): void
    {
        $cases = [
            ['abs', '-3', '', ''],
            ['ceil', '1.2', '', ''],
            ['floor', '1.9', '', ''],
            ['round', '1.2345', '2', ''],
            ['upper', "'hi'", '', ''],
            ['lower', "'HI'", '', ''],
            ['capitalize', "'hELLO'", '', ''],
            ['title', "'hello world'", '', ''],
            ['trim', "'  hi  '", '', ''],
            ['nl2br', "'a\nb'", '', ''],
            ['number', '1234.5', '2', ''],
            ['slice', "'abcde'", '1, 3', ''],
            ['split', "'a,b,c'", "','", "|> join('|')"],
            ['replace', "'a-b'", "'-', '_'", ''],
            ['default', 'null', "'fb'", ''],
            ['empty', "''", "'fb'", ''],
            ['merge', 'a', 'b', "|> join(',')"],
            ['reverse', 'items', '', "|> join(',')"],
            ['truncate', "'abcdefghij'", '5, "..."', ''],
            ['url_encode', "'a b'", '', ''],
            ['striptags', "'<b>hi</b>'", "''", ''],
            ['unicode', "'abcde'", '1, 3', ''],
            ['sprintf', "'%s-%d'", "'x', 5", ''],
        ];
        $vars = ['a' => [1, 2], 'b' => [3], 'items' => [1, 2, 3]];

        foreach ($cases as $i => [$name, $value, $extra, $suffix]) {
            $pipeArg = $extra === '' ? '' : "($extra)";
            $callArg = $extra === '' ? '' : ", $extra";

            $pipeView = "cs_parity_{$i}_pipe";
            $callView = "cs_parity_{$i}_call";
            self::tpl($pipeView, "{{ $value |> $name$pipeArg $suffix }}");
            self::tpl($callView, "{{ $name($value$callArg) $suffix }}");

            $this->assertSame(
                self::render($pipeView, $vars),
                self::render($callView, $vars),
                "call form must match filter form for '$name'"
            );
        }
    }

    // =========================================================================
    // date — call form mirrors PHP: date($format, $timestamp)
    // =========================================================================

    public function testDateCallForm(): void
    {
        $ts = mktime(12, 0, 0, 6, 15, 2023);
        self::tpl('cs_date_call', '{{ date("Y", ts) }}');
        self::tpl('cs_date_pipe', '{{ ts |> date("Y") }}');

        $this->assertSame('2023', self::render('cs_date_call', ['ts' => $ts]));
        $this->assertSame(self::render('cs_date_pipe', ['ts' => $ts]), self::render('cs_date_call', ['ts' => $ts]));
    }

    /**
     * `date('Y-m-d')` with no value must default to NOW (via `\time()`), not to
     * 1970. A naive `null` default would map through `(int) strtotime('')` = 0.
     */
    public function testDateCallFormWithoutValueDefaultsToNow(): void
    {
        self::tpl('cs_date_now', '{{ date("Y-m-d") }}');

        $this->assertSame(\date('Y-m-d'), self::render('cs_date_now'));
    }

    public function testDatePipeWithoutArgumentDefaultsToNow(): void
    {
        self::tpl('cs_date_direct', '{{ ts |> date }}');
        $ts = mktime(0, 0, 0, 1, 1, 2021);

        $this->assertSame(\date('Y-m-d', $ts), self::render('cs_date_direct', ['ts' => $ts]));
    }

    // =========================================================================
    // join — call form mirrors PHP: join($glue, $array)
    // =========================================================================

    public function testJoinCallForm(): void
    {
        self::tpl('cs_join_call', "{{ join(', ', items) }}");
        self::tpl('cs_join_pipe', "{{ items |> join(', ') }}");

        $vars = ['items' => ['x', 'y', 'z']];
        $this->assertSame('x, y, z', self::render('cs_join_call', $vars));
        $this->assertSame(self::render('cs_join_pipe', $vars), self::render('cs_join_call', $vars));
    }

    public function testJoinCallFormWithNamedArgs(): void
    {
        self::tpl('cs_join_named', '{{ join(array: items, glue: "-") }}');
        $this->assertSame('x-y', self::render('cs_join_named', ['items' => ['x', 'y']]));
    }

    /**
     * `join(items)` binds its only argument to `glue` and leaves `array`
     * unsupplied. `array` has NO default on purpose, so this is a loud compile
     * error rather than a silent empty-separator join.
     */
    public function testJoinCallFormWithOnlyOneArgumentFailsLoudly(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/Missing required argument 'array'/");

        self::tpl('cs_join_one', '{{ join(items) }}');
        self::render('cs_join_one', ['items' => ['x', 'y']]);
    }

    // =========================================================================
    // Callable-only filters reachable under call syntax (sandbox)
    // =========================================================================

    public function testCallableFilterUnderCallSyntax(): void
    {
        self::tpl('cs_keys_call', "{{ keys(items) |> join(',') }}");
        self::tpl('cs_values_call', "{{ values(items) |> join(',') }}");
        self::tpl('cs_slug_call', "{{ slug('Hello World') }}");

        $this->assertSame('a,b', self::render('cs_keys_call', ['items' => ['a' => 1, 'b' => 2]]));
        $this->assertSame('1,2', self::render('cs_values_call', ['items' => ['a' => 1, 'b' => 2]]));
        $this->assertSame('hello-world', self::render('cs_slug_call'));
    }

    /**
     * `sort`/`shuffle` are registered as the SAME copy-returning callables in
     * both forms, so call syntax returns the sorted COPY (not PHP's in-place
     * bool-returning `\sort`).
     */
    public function testSortCallSyntaxReturnsSortedCopy(): void
    {
        TestEnvironment::engine()->addFilter('sort', static function (mixed $v): array {
            $arr = (array) $v;
            \sort($arr);
            return $arr;
        });
        self::tpl('cs_sort_call', "{{ sort(items) |> join(',') }}");
        $this->assertSame('1,2,3', self::render('cs_sort_call', ['items' => [3, 1, 2]]));
    }

    public function testJsonIsBothFilterAndFunction(): void
    {
        self::tpl('cs_json_call', '{{ json(items) |> raw }}');
        self::tpl('cs_json_pipe', '{{ items |> json |> raw }}');

        $vars = ['items' => ['a' => 1]];
        $this->assertSame('{"a":1}', self::render('cs_json_call', $vars));
        $this->assertSame(self::render('cs_json_pipe', $vars), self::render('cs_json_call', $vars));
    }

    // =========================================================================
    // Lambda-taking filters under call syntax: map(items, x => …)
    // =========================================================================

    public function testMapUnderCallSyntaxWithFilterReference(): void
    {
        self::tpl('cs_map_ref', '{{ map(items, "upper") |> join(",") }}');
        $this->assertSame('A,B', self::render('cs_map_ref', ['items' => ['a', 'b']]));
    }

    public function testMapUnderCallSyntaxWithLambda(): void
    {
        self::tpl('cs_map_lambda', '{{ map(items, x => x ~ "!") |> join(",") }}');
        $this->assertSame('a!,b!', self::render('cs_map_lambda', ['items' => ['a', 'b']]));
    }

    public function testFilterUnderCallSyntaxWithLambda(): void
    {
        self::tpl('cs_filter_lambda', '{{ filter(nums, x => x > 1) |> join(",") }}');
        $this->assertSame('2,3', self::render('cs_filter_lambda', ['nums' => [1, 2, 3]]));
    }

    public function testReduceUnderCallSyntaxWithFilterReference(): void
    {
        TestEnvironment::engine()->addFilter('sum2', static fn(mixed $c, mixed $i): mixed => $c + $i);
        self::tpl('cs_reduce_ref', '{{ reduce(nums, "sum2", 0) }}');
        $this->assertSame('6', self::render('cs_reduce_ref', ['nums' => [1, 2, 3]]));
    }

    public function testReduceUnderCallSyntaxWithTwoParamLambda(): void
    {
        self::tpl('cs_reduce_lambda', '{{ reduce(nums, c, i => c + i, 0) }}');
        $this->assertSame('6', self::render('cs_reduce_lambda', ['nums' => [1, 2, 3]]));
    }

    // =========================================================================
    // Inline filter references: an inline filter is CODEGEN, so referencing it
    // compiles a closure — for filter/reduce too, not just map
    // =========================================================================

    public function testFilterUnderCallSyntaxWithInlineReference(): void
    {
        // `filter` needs a PREDICATE, not a transformer: the inline filter tests
        // each element for truthiness and the ORIGINAL values pass through
        // unchanged. `length` of '' is 0 (falsy), so the empty element is dropped
        // while 'a' and 'bb' are kept verbatim.
        self::tpl('cs_filter_inline_ref', '{{ filter(items, "length") |> join(",") }}');
        $this->assertSame('a,bb', self::render('cs_filter_inline_ref', ['items' => ['a', '', 'bb']]));
    }

    public function testReduceRejectsUnaryInlineReference(): void
    {
        // `reduce` requires a binary callable (carry, item). An inline filter has
        // a single `{1}` slot that PHP binds the ACCUMULATOR to, so the element
        // would be ignored and reduce would return the initial value unchanged.
        // That silent no-op is rejected at compile time.
        self::tpl('cs_reduce_inline_ref', '{{ items |> reduce("upper", "") }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/needs a two-parameter lambda/');
        self::render('cs_reduce_inline_ref', ['items' => ['a', 'b']]);
    }

    public function testInlineReferenceIsCompiledNotDispatchedForMapAndFilter(): void
    {
        // An inline-only name has no runtime entry, so a registry lookup for it
        // would be dead code. Assert the closure form is emitted instead.
        // (`reduce` is excluded: a unary reference is rejected outright, above.)
        foreach ([
            'cs_inline_ref_map'    => '{{ map(items, "upper") |> join(",") }}',
            'cs_inline_ref_filter' => '{{ filter(items, "upper") |> join(",") }}',
        ] as $view => $src) {
            self::tpl($view, $src);
            self::render($view, ['items' => ['a', 'b']]);

            $compiled = $this->compiledSource($view);
            $this->assertStringNotContainsString(
                "\$__c_fn['upper']",
                $compiled,
                "{$view} must inline the reference, not look it up in the registry"
            );
        }
    }

    public function testCallableReferenceStillUsesTheRegistry(): void
    {
        // A callable filter lives in the runtime table, so it is dispatched —
        // inlining it would duplicate the callable in emitted code.
        self::tpl('cs_callable_ref', '{{ map(items, "slug") |> join(",") }}');
        $this->assertSame('a-b', self::render('cs_callable_ref', ['items' => ['a b']]));

        $this->assertStringContainsString(
            "\$__c_fn['slug']",
            $this->compiledSource('cs_callable_ref')
        );
    }

    // =========================================================================
    // Shadowing: a registered name wins over a same-named PHP builtin
    // =========================================================================

    public function testSortShadowsPhpSortInOpenMode(): void
    {
        // PHP's \sort returns bool and mutates by reference; the registered
        // `sort` returns the sorted copy. Call syntax must use the latter.
        self::tpl('cs_shadow_sort', "{{ sort(items) |> join(',') }}");
        $this->assertSame('1,2,3', self::openEngine()->renderPartial('cs_shadow_sort', ['items' => [3, 1, 2]]));
    }

    public function testNonRegisteredNameStillReachesPhpInOpenMode(): void
    {
        self::tpl('cs_passthrough', "{{ substr('abcdef', 1, 3) }}");
        $this->assertSame('bcd', self::openEngine()->renderPartial('cs_passthrough'));
    }

    public function testUnregisteredCallStillRejectedInSandbox(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/i');
        self::tpl('cs_sandbox_reject', "{{ substr('abcdef', 1, 3) }}");
        self::render('cs_sandbox_reject');
    }

    // =========================================================================
    // length / len — one alias, both signatures
    // =========================================================================

    public function testLengthWorksInBothForms(): void
    {
        self::tpl('cs_length_pipe', '{{ items |> length }}');
        self::tpl('cs_length_call', '{{ length(items) }}');
        self::tpl('cs_length_string', '{{ name |> length }}');

        $vars = ['items' => [1, 2, 3], 'name' => 'test'];
        $this->assertSame('3', self::render('cs_length_pipe', $vars));
        $this->assertSame('3', self::render('cs_length_call', $vars));
        $this->assertSame('4', self::render('cs_length_string', $vars));
    }

    public function testLenWorksInBothForms(): void
    {
        self::tpl('cs_len_pipe', '{{ items |> len }}');
        self::tpl('cs_len_call', '{{ len(items) }}');
        self::tpl('cs_len_string', '{{ name |> len }}');

        $vars = ['items' => [1, 2, 3], 'name' => 'test'];
        $this->assertSame('3', self::render('cs_len_pipe', $vars));
        $this->assertSame('3', self::render('cs_len_call', $vars));
        $this->assertSame('4', self::render('cs_len_string', $vars));
    }

    public function testLenAndLengthAreInterchangeable(): void
    {
        self::tpl('cs_len_alias_a', '{{ items |> len }}');
        self::tpl('cs_len_alias_b', '{{ items |> length }}');
        self::tpl('cs_len_alias_c', '{{ len(items) }}');
        self::tpl('cs_len_alias_d', '{{ length(items) }}');

        $vars  = ['items' => ['a', 'b', 'c', 'd', 'e']];
        $first = self::render('cs_len_alias_a', $vars);

        foreach (['cs_len_alias_b', 'cs_len_alias_c', 'cs_len_alias_d'] as $view) {
            $this->assertSame($first, self::render($view, $vars), "{$view} must match the len filter form");
        }
        $this->assertSame('5', $first);
    }

    public function testLenAliasInOpenModeToo(): void
    {
        // Open mode compiles a pipe against hasFilter(); before `len` was aliased
        // this threw "Unknown filter 'len'". Both forms must now work.
        self::tpl('cs_len_open_pipe', '{{ items |> len }}');
        self::tpl('cs_len_open_call', '{{ len(items) }}');

        $engine = $this->openEngine();
        $this->assertSame('3', $engine->renderPartial('cs_len_open_pipe', ['items' => [1, 2, 3]]));
        $this->assertSame('3', $engine->renderPartial('cs_len_open_call', ['items' => [1, 2, 3]]));
    }

    // =========================================================================
    // Call-only names must NOT be pipeable (both modes, clear error)
    // =========================================================================

    /**
     * @dataProvider callOnlyNames
     */
    public function testCallOnlyNameRejectedAsFilter(string $name): void
    {
        self::tpl("cs_pipe_callonly_{$name}", "{{ items |> {$name} }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/is a function, not a filter/');
        self::render("cs_pipe_callonly_{$name}", ['items' => [1, 2, 3]]);
    }

    /**
     * @dataProvider callOnlyNames
     */
    public function testCallOnlyNameRejectedAsFilterInOpenMode(string $name): void
    {
        self::tpl("cs_pipe_callonly_open_{$name}", "{{ items |> {$name} }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/is a function, not a filter/');
        $this->openEngine()->renderPartial("cs_pipe_callonly_open_{$name}", ['items' => [1, 2, 3]]);
    }

    public static function callOnlyNames(): array
    {
        return [
            'context' => ['context'],
            'include' => ['include'],
            'dd'      => ['dd'],
        ];
    }

    // =========================================================================
    // Filter references are checked at COMPILE time
    // =========================================================================

    /**
     * A quoted callable reference (`map(items, "…")`) must name a filterable
     * callable. This is the compile-time check that lets the runtime table be a
     * plain map instead of re-validating on every call.
     */
    public function testCallOnlyNameRejectedAsFilterReference(): void
    {
        self::tpl('cs_ref_callonly', '{{ map(items, "context") }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/is not a filter; it is a function/');
        self::render('cs_ref_callonly', ['items' => [1, 2]]);
    }

    /**
     * `dump` is filterable, so it is a legal callable reference. The reference
     * resolves to the pass-through probe, which the lambda invokes per element:
     * each element is rendered as a dump and still returned to `map`.
     */
    public function testDumpIsAcceptedAsFilterReference(): void
    {
        self::tpl('cs_ref_dump', '{{ map(items, "dump") |> length }}');
        $this->assertStringEndsWith('2', self::render('cs_ref_dump', ['items' => [1, 2]]));
    }

    /**
     * The reference form obeys the same rule as every other dump form: with
     * debug off it is the IDENTITY. A debug reference must never print into
     * production output.
     */
    public function testDumpReferenceIsTheIdentityWithoutDebug(): void
    {
        self::tpl('cs_ref_dump_prod', '{{ map(items, "dump") |> join(",") }}');

        $this->assertSame(
            'a,b',
            self::render('cs_ref_dump_prod', ['items' => ['a', 'b']])
        );
    }

    public function testUnknownNameRejectedAsFilterReference(): void
    {
        self::tpl('cs_ref_unknown', '{{ map(items, "no_such_filter") }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/is not a filter and no filter is registered/');
        self::render('cs_ref_unknown', ['items' => [1, 2]]);
    }

    public function testInlineFilterReferenceDispatchesThroughOneRegistry(): void
    {
        // `map` resolves an inline-only filter reference into a compiled closure,
        // so the emitted code carries no runtime lookup for the referenced name.
        self::tpl('cs_ref_inline', '{{ map(items, "upper") |> join(",") }}');
        $this->assertSame('A,B', self::render('cs_ref_inline', ['items' => ['a', 'b']]));

        $compiled = $this->compiledSource('cs_ref_inline');
        $this->assertStringNotContainsString("\$__c_fn['upper']", $compiled);
    }

    public function testCallableFilterReferenceDispatchesThroughOneRegistry(): void
    {
        // A callable filter reference (slug) is looked up in the ONE registry.
        self::tpl('cs_ref_callable', '{{ map(items, "slug") |> join(",") }}');
        $this->assertSame('a-b,c-d', self::render('cs_ref_callable', ['items' => ['a b', 'c d']]));

        $this->assertStringContainsString(
            "\$__c_fn['slug']",
            $this->compiledSource('cs_ref_callable')
        );
    }

    // =========================================================================
    // A lambda body that reaches the runtime registry must capture it
    // =========================================================================

    /**
     * The lambda closure captures $__c_va unconditionally. $__c_fn and $__c_sv
     * are render-frame LOCALS rather than parameters, so a body that uses them
     * must ALSO capture them — otherwise it fatals at render time with
     * "Variable $__c_fn is not defined".
     *
     * @dataProvider registryUsingLambdaBodies
     */
    public function testLambdaBodyReachingTheRegistryWorks(string $view, string $src, array $vars, string $expected): void
    {
        self::tpl($view, $src);
        $this->assertSame($expected, self::render($view, $vars));
    }

    public static function registryUsingLambdaBodies(): array
    {
        $items = ['items' => ['A B', 'C D']];

        return [
            // dispatch through a runtime callable (slug is a closure)
            'callable pipe' => ['cs_lam_callable', '{{ map(items, x => x |> slug) |> join(",") }}', $items, 'a-b,c-d'],
            // dispatch through a runtime callable (length is a closure)
            'length pipe' => ['cs_lam_length', '{{ map(items, x => x |> length) |> join(",") }}', $items, '3,3'],
            // call syntax inside the body
            'call syntax' => ['cs_lam_callsyn', '{{ map(items, x => len(x)) |> join(",") }}', $items, '3,3'],
            // filter/reduce bodies reaching the registry
            'filter body' => ['cs_lam_filter', '{{ filter(items, x => x |> length) |> join(",") }}', $items, 'A B,C D'],
            'reduce body' => ['cs_lam_reduce', '{{ reduce(items, c, i => c ~ (i |> length), "") }}', $items, '33'],
            // an inline-only body still works (compiles to a PHP expression)
            'inline body' => ['cs_lam_inline', '{{ map(items, x => x |> upper) |> join(",") }}', $items, 'A B,C D'],
        ];
    }

    public function testLambdaCaptureIsOnlyAddedWhenNeeded(): void
    {
        // An inline-only body needs no registry, so the closure must not capture
        // $__c_fn — keeping the emitted closure as small as possible.
        self::tpl('cs_lam_capture_min', '{{ map(items, x => x |> upper) |> join(",") }}');
        self::render('cs_lam_capture_min', ['items' => ['a']]);

        $compiled = $this->compiledSource('cs_lam_capture_min');
        $this->assertStringContainsString('use ($__c_va): mixed', $compiled);
        $this->assertStringNotContainsString('use ($__c_va, $__c_fn)', $compiled);
    }

    public function testLambdaCapturesRegistryWhenBodyNeedsIt(): void
    {
        self::tpl('cs_lam_capture_fn', '{{ map(items, x => x |> slug) |> join(",") }}');
        self::render('cs_lam_capture_fn', ['items' => ['a b']]);

        $compiled = $this->compiledSource('cs_lam_capture_fn');
        $this->assertStringContainsString(
            'use ($__c_va, $__c_fn): mixed',
            $compiled,
            'a body reaching the registry must capture $__c_fn'
        );
    }

    public function testSingleRegistryIsEmittedNotTwo(): void
    {
        // Regression guard for the unification: no emitted code may reference a
        // second callable table.
        self::tpl('cs_one_registry', '{{ items |> map("slug") |> join(",") }}');
        self::render('cs_one_registry', ['items' => ['a b']]);

        $this->assertStringNotContainsString('__c_fl', $this->compiledSource('cs_one_registry'));
    }
}
