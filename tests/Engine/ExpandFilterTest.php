<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * The `expand` filter: resolve the ARRIVING VALUE as a variable name.
 *
 * The distinguishing property is that it composes anywhere in a pipeline, which
 * is why it is a runtime lookup rather than a compiler rewrite of the subject's
 * name: `{{ text |> rot13 |> expand }}` expands the TRANSFORMED text, and a
 * name-rewriting intrinsic could not express that at all.
 *
 * Nullability is opt-in and mirrors `?.`: absent names throw, and
 * `expand(optional: true)` returns null so a following `??` can supply the
 * fallback. A `fallback` argument is the eager alternative — it decides the
 * value at the absent branch instead of handing `null` on to `??`.
 */
class ExpandFilterTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A transform between the value and expand, so the pipeline-position
        // property can be tested rather than only the direct call.
        TestEnvironment::engine()->addFilter('rot13', static fn(mixed $v): string => \str_rot13((string) $v));
    }

    public function testExpandResolvesAnIndirectName(): void
    {
        self::tpl('ex_direct', '{{ which |> expand }}');
        $this->assertSame('Alice', self::render('ex_direct', ['which' => 'name', 'name' => 'Alice']));
    }

    /**
     * The value is a NAME, not a value to echo: an absent name still throws, so
     * `{{ literal |> expand }}` is not a no-op that returns its input.
     */
    public function testExpandTreatsTheValueAsANameNotAsAPassthrough(): void
    {
        self::tpl('ex_value', '{{ literal |> expand }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Undefined/');
        $this->render('ex_value', ['literal' => 'hello']);
    }

    /**
     * The reason expand takes the ARRIVING value rather than the subject's name:
     * the name it resolves may be produced by an earlier filter.
     */
    public function testExpandWorksAfterAnotherFilter(): void
    {
        // rot13('anzr') === 'name'
        self::tpl('ex_after', '{{ indirect |> rot13 |> expand }}');
        $this->assertSame('Bob', self::render('ex_after', ['indirect' => 'anzr', 'name' => 'Bob']));
    }

    public function testExpandRejectsANonNameKey(): void
    {
        self::tpl('ex_bad_key', '{{ which |> expand }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/variable/');
        $this->render('ex_bad_key', ['which' => 3.5]);
    }

    /**
     * `fallback` and `optional` are the ONLY named arguments: anything else is a
     * compile error rather than a silently ignored key.
     */
    public function testExpandRejectsAnUnknownNamedArgument(): void
    {
        self::tpl('ex_bad_arg', '{{ which |> expand(strict: true) }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unknown named argument/');
        $this->render('ex_bad_arg', ['which' => 'nope']);
    }

    /**
     * `optional` is resolved at COMPILE time (it chooses the absent branch), so
     * a runtime expression cannot be honoured and is reported rather than
     * silently read as false.
     */
    public function testExpandRejectsANonLiteralOptional(): void
    {
        self::tpl('ex_bad_opt', '{{ which |> expand(optional: flag) }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/literal true or false/');
        $this->render('ex_bad_opt', ['which' => 'nope', 'flag' => true]);
    }

    public function testExpandRejectsTooManyPositionalArguments(): void
    {
        self::tpl('ex_arity', "{{ which |> expand('a', 'b') }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/too many positional arguments/');
        $this->render('ex_arity', ['which' => 'nope']);
    }

    public function testExpandReadsAPresentValue(): void
    {
        self::tpl('ex_present', '{{ which |> expand }}');
        $this->assertSame('0', self::render('ex_present', ['which' => 'zero', 'zero' => 0]));
    }

    public function testExpandDoesNotEmitALocalsMapOutsideALoop(): void
    {
        self::tpl('ex_no_locals', '{{ which |> expand }}');
        self::render('ex_no_locals', ['which' => 'name', 'name' => 'x']);

        // The locals map is `[ 'name' => $phpVar ]`; outside a loop there is
        // nothing to carry, so passing one would be a wasted array literal.
        $this->assertStringNotContainsString("'item' =>", $this->compiledSource('ex_no_locals'));
    }

    /**
     * Inside a loop the iteration variable is a PHP local (`$item`), not a scope
     * entry, so the name is not reachable through $__va. The locals map is what
     * makes `expand` find it, and the emitted read is the direct `${$__tmp}`
     * variable rather than a scope lookup.
     */
    public function testExpandResolvesALoopVariableInsideALoop(): void
    {
        self::tpl('ex_loop_var', '{% for item in items %}{{ name |> expand }}{% endfor %}');

        $this->assertSame('a', self::render('ex_loop_var', ['items' => ['a'], 'name' => 'item']));
    }

    /**
     * A name that is NOT a loop local must still fall through to the render
     * scope from inside the loop: the locals map narrows the search, it does not
     * replace it.
     */
    public function testExpandFallsBackToTheScopeInsideALoop(): void
    {
        self::tpl('ex_loop_scope', '{% for item in items %}{{ name |> expand }}{% endfor %}');

        $this->assertSame('Alice', self::render('ex_loop_scope', ['items' => [1], 'name' => 'outer', 'outer' => 'Alice']));
    }

    public function testExpandEmitsALocalsMapInsideALoop(): void
    {
        self::tpl('ex_locals', '{% for item in items %}{{ name |> expand }}{% endfor %}');
        self::render('ex_locals', ['items' => ['a'], 'name' => 'item']);

        $this->assertStringContainsString(
            "'item' => 1",
            $this->compiledSource('ex_locals'),
            'a loop local must be carried in the locals map'
        );
    }

    /**
     * The fallback is what makes expand usable where a name may legitimately be
     * absent: `expand(true)` in the docs is the positional form.
     */
    public function testExpandPositionalFallbackIsUsedWhenTheNameIsAbsent(): void
    {
        self::tpl('ex_fallback', "{{ which |> expand('none') }}");

        $this->assertSame('none', self::render('ex_fallback', ['which' => 'missing']));
    }

    public function testExpandNamedFallbackIsUsedWhenTheNameIsAbsent(): void
    {
        self::tpl('ex_named_fb', "{{ which |> expand(fallback='none') }}");

        $this->assertSame('none', self::render('ex_named_fb', ['which' => 'missing']));
    }

    public function testExpandFallbackIsIgnoredWhenTheNameIsPresent(): void
    {
        self::tpl('ex_fb_present', "{{ which |> expand('none') }}");

        $this->assertSame('Alice', self::render('ex_fb_present', ['which' => 'name', 'name' => 'Alice']));
    }

    /**
     * The documented optional form: the absent branch yields null, which `??`
     * then turns into the fallback. This is what makes `expand` compose with
     * later pipeline steps rather than deciding the value itself.
     */
    public function testExpandOptionalComposesWithNullCoalescing(): void
    {
        self::tpl('ex_opt_coalesce', "{{ which |> expand(optional: true) ?? 'none' }}");
        $this->assertSame('none', self::render('ex_opt_coalesce', ['which' => 'missing']));

        self::tpl('ex_opt_coalesce_present', "{{ which |> expand(optional: true) ?? 'none' }}");
        $this->assertSame('Alice', self::render('ex_opt_coalesce_present', ['which' => 'name', 'name' => 'Alice']));
    }

    /** Without `??`, an absent optional name renders empty rather than throwing. */
    public function testExpandOptionalWithoutCoalesceRendersEmpty(): void
    {
        self::tpl('ex_opt_alone', '{{ which |> expand(optional: true) }}');

        $this->assertSame('', self::render('ex_opt_alone', ['which' => 'missing']));
    }

    /**
     * `optional: false` is the strict default spelled out, so it keeps the
     * throw — `??` cannot rescue it, because an exception is not a null return.
     */
    public function testExpandOptionalFalseKeepsTheStrictThrow(): void
    {
        self::tpl('ex_opt_false', "{{ which |> expand(optional: false) ?? 'none' }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Undefined/');
        $this->render('ex_opt_false', ['which' => 'missing']);
    }

    /** An eager `fallback` wins: the absent branch never yields null for `??`. */
    public function testExpandFallbackTakesPrecedenceOverOptional(): void
    {
        self::tpl('ex_fb_opt', "{{ which |> expand(optional: true, fallback: 'fb') ?? 'none' }}");

        $this->assertSame('fb', self::render('ex_fb_opt', ['which' => 'missing']));
    }

    /** Return the compiled PHP source of a rendered template. */
    private function compiledSource(string $view): string
    {
        $cache = new \ReflectionProperty(TestEnvironment::engine(), 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue(TestEnvironment::engine())->getLoadedClassName($view);
        $this->assertIsString($className, 'the template must be compiled and loaded');

        return (string) file_get_contents((new \ReflectionClass($className))->getFileName());
    }
}