<?php
namespace Clarity\Tests\Engine;

use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * A filter segment may be followed by an operator, e.g. `length > 1` or
 * `length == 0`. `??` is included so a filter whose result may be null can be
 * given a fallback: `{{ items |> length ?? 0 }}`.
 *
 * The null-coalescing form binds to the PRECEDING filter, because
 * {@see \Clarity\Engine\Tokenizer::buildFilterCall()} inserts the incoming value
 * inside the call's parentheses and appends the trailing operator to the call.
 * The grouping tests below pin exactly that, because a refactor that changed it
 * would still produce plausible output for simple pipelines.
 */
class FilterTrailingOperatorTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A filter that always yields null — the only way a trailing `??` can
        // observe a difference, since the engine throws on a missing variable
        // before the filter ever runs.
        TestEnvironment::engine()->addFilter('nullish', static fn(mixed $v): mixed => null);
        TestEnvironment::engine()->addFilter('wrap', static fn(mixed $v): string => '[' . $v . ']');
    }

    // =========================================================================
    // `??` as a trailing operator
    // =========================================================================

    public function testTrailingNullCoalescingReplacesNullResult(): void
    {
        self::tpl('tr_null', "{{ 'x' |> nullish ?? 'fb' }}");
        $this->assertSame('fb', self::render('tr_null'));
    }

    public function testTrailingNullCoalescingKeepsNonNullResult(): void
    {
        self::tpl('tr_keep', "{{ 'x' |> wrap ?? 'fb' }}");
        $this->assertSame('[x]', self::render('tr_keep'));
    }

    public function testTrailingNullCoalescingOnBuiltinLength(): void
    {
        self::tpl('tr_length', '{{ items |> length ?? 0 }}');
        $this->assertSame('3', self::render('tr_length', ['items' => [1, 2, 3]]));
    }

    public function testTrailingNullCoalescingWithArguments(): void
    {
        self::tpl('tr_args', "{{ 'x' |> nullish(1, 2) ?? 'fb' }}");
        $this->assertSame('fb', self::render('tr_args'));
    }

    public function testTrailingNullCoalescingWithExpressionFallback(): void
    {
        self::tpl('tr_expr', "{{ 'x' |> nullish ?? fallback }}");
        $this->assertSame('fromvar', self::render('tr_expr', ['fallback' => 'fromvar']));
    }

    public function testTrailingNullCoalescingChains(): void
    {
        self::tpl('tr_chain', "{{ 'x' |> nullish ?? nullish2 ?? 'fb' }}");
        $this->assertSame('fb', self::render('tr_chain'));
    }

    // =========================================================================
    // The comparison operators still work (regression guard)
    // =========================================================================

    public function testTrailingComparisonStillWorks(): void
    {
        self::tpl('tr_cmp', '{% if items |> length > 1 %}many{% endif %}');
        $this->assertSame('many', self::render('tr_cmp', ['items' => [1, 2, 3]]));
        $this->assertSame('', self::render('tr_cmp', ['items' => [1]]));
    }

    public function testTrailingEqualityStillWorks(): void
    {
        self::tpl('tr_eq', '{% if items |> length == 0 %}empty{% endif %}');
        $this->assertSame('empty', self::render('tr_eq', ['items' => []]));
        $this->assertSame('', self::render('tr_eq', ['items' => [1]]));
    }

    // =========================================================================
    // Grouping — the trailing operator binds to the PRECEDING filter
    // =========================================================================

    public function testTrailingCoalescingBindsToThePrecedingFilter(): void
    {
        // nullish('x') ?? 'fb' is evaluated first, then wrap() receives 'fb'.
        self::tpl('tr_group1', "{{ 'x' |> nullish ?? 'fb' |> wrap }}");
        $this->assertSame('[fb]', self::render('tr_group1'));
    }

    public function testTrailingCoalescingAfterTheLastFilterBindsToIt(): void
    {
        // wrap(null) yields '[]', which is not null, so the fallback is unused.
        self::tpl('tr_group2', "{{ 'x' |> nullish |> wrap ?? 'fb' }}");
        $this->assertSame('[]', self::render('tr_group2'));
    }

    public function testGroupingIsVisibleInTheEmittedPhp(): void
    {
        self::tpl('tr_group_php', "{{ 'x' |> nullish ?? 'fb' |> wrap }}");
        self::render('tr_group_php');

        $body = $this->compiledBody('tr_group_php');

        // `wrap(nullish('x') ?? 'fb')` — the fallback is INSIDE the outer call,
        // not appended after it.
        $this->assertStringContainsString(
            "\$__fl['wrap'](\$__fl['nullish']('x') ?? 'fb')",
            $body,
            'the trailing ?? must be grouped inside the following filter call'
        );
    }

    /** Return the compiled render body for a template name. */
    private function compiledBody(string $view): string
    {
        $cache = new \ReflectionProperty(TestEnvironment::engine(), 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue(TestEnvironment::engine())->getLoadedClassName($view);
        $this->assertIsString($className, 'the template must be compiled and loaded');

        $file  = (new \ReflectionClass($className))->getFileName();
        $src   = (string) file_get_contents($file);
        $start = strpos($src, 'try {');
        $end   = strpos($src, 'return (string) ob_get_clean();');
        $this->assertNotFalse($start, 'the compiled class must contain the render body');
        $this->assertNotFalse($end, 'the compiled class must contain the render body');

        return substr($src, $start, $end - $start);
    }
}
