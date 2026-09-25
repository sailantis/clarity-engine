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
 * `expand(true)` / `expand(optional: true)` returns null so a fallback can
 * follow.
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

    public function testExpandRejectsAnUnknownArgument(): void
    {
        self::tpl('ex_bad_arg', '{{ which |> expand(fallback="x") }}');

        $result = $this->render('ex_bad_arg', ['which' => 'nope']);
        $this->assertStringContainsString('x', $result);
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

        $cache = new \ReflectionProperty(\Clarity\Tests\TestEnvironment::engine(), 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue(\Clarity\Tests\TestEnvironment::engine())->getLoadedClassName('ex_no_locals');
        $src       = (string) file_get_contents((new \ReflectionClass($className))->getFileName());

        // The locals map is `[ 'name' => $phpVar ]`; outside a loop there is
        // nothing to carry, so passing one would be a wasted array literal.
        $this->assertStringNotContainsString("'item' =>", $src);
    }
}