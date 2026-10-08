<?php
namespace Clarity\Tests\Engine;

use Clarity\Engine\Policy;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;

/**
 * The built-in cast filters: `{{ x |> int }}`, `{{ int(x) }}`.
 *
 * A cast is a TOTAL function — `(int) 'dsfd'` is 0 — and that is the property
 * being pinned here, not a wart. It is what makes a cast usable on a value whose
 * type is only known at render time.
 *
 * The filters exist because the engine has no other way to state an intended
 * conversion, and because `strictTypes` makes the numeric built-ins reject a
 * numeric string on purpose (see `StrictTypesTest`). So both halves of that
 * decision are tested: this file tests the remedy, and `StrictTypesTest` tests
 * that the problem is still reported rather than papered over.
 *
 * All six names are inline templates, so each one must answer BOTH syntaxes and
 * compile without any runtime dispatch.
 */
class CastFilterTest extends BaseTestCase
{
    /** Compile $template under an explicit policy and render it. */
    private static function renderWith(Policy $policy, string $template, array $vars = []): string
    {
        $view = 'castf_' . \md5($template . \serialize($vars));
        self::tpl($view, $template);

        return TestClarityEngine::withPolicy($policy)->renderPartial($view, $vars);
    }

    // =========================================================================
    // Each cast, in both syntaxes
    // =========================================================================

    /**
     * The filter form and the call form are the same construct; only the slot
     * assignment differs. A pair that disagrees is the failure this catches.
     */
    private function assertBothForms(string $filterForm, string $callForm, array $vars, string $expected): void
    {
        $this->assertSame(
            $expected,
            self::renderWith(Policy::default(), $filterForm, $vars),
            "filter form: {$filterForm}"
        );
        $this->assertSame(
            $expected,
            self::renderWith(Policy::default(), $callForm, $vars),
            "call form: {$callForm}"
        );
    }

    public function testIntTruncatesTowardsZero(): void
    {
        $this->assertBothForms('{{ x |> int }}', '{{ int(x) }}', ['x' => '3.7'], '3');
    }

    public function testFloatKeepsTheFraction(): void
    {
        $this->assertBothForms('{{ x |> float }}', '{{ float(x) }}', ['x' => '3.7'], '3.7');
    }

    public function testStringStringifiesANumber(): void
    {
        $this->assertBothForms('{{ x |> string }}', '{{ string(x) }}', ['x' => 1.5], '1.5');
    }

    public function testBoolFollowsPhpTruthiness(): void
    {
        // `'0'` is the interesting one: a non-empty string that is nonetheless
        // false. It is the case a naive `!empty()` implementation would get wrong.
        $this->assertSame('1', self::renderWith(Policy::default(), '{{ x |> bool }}', ['x' => '1']));
        $this->assertSame('', self::renderWith(Policy::default(), '{{ x |> bool }}', ['x' => '0']));
        $this->assertSame('', self::renderWith(Policy::default(), '{{ x |> bool }}', ['x' => '']));
        $this->assertSame('1', self::renderWith(Policy::default(), '{{ x |> bool }}', ['x' => 'false']));
    }

    public function testArrayWrapsAScalar(): void
    {
        $this->assertSame('[1]', self::renderWith(Policy::default(), '{{ x |> array |> json }}', ['x' => 1]));
    }

    /**
     * A cast is total. `(int) 'dsfd'` is 0 — no throw, no diagnostic — which is
     * exactly what makes it usable on input of unknown type.
     */
    public function testCastsAreTotalOnNonNumericInput(): void
    {
        $this->assertSame('0', self::renderWith(Policy::default(), '{{ x |> int }}', ['x' => 'dsfd']));
        $this->assertSame('0', self::renderWith(Policy::default(), '{{ x |> float }}', ['x' => 'abc']));
        $this->assertSame('0', self::renderWith(Policy::default(), '{{ x |> int }}', ['x' => null]));
        $this->assertSame('1', self::renderWith(Policy::default(), '{{ x |> int }}', ['x' => true]));
        $this->assertSame(
            '42',
            self::renderWith(Policy::default(), '{{ x |> int }}', ['x' => '42abc']),
            'PHP reads a leading-number run, it does not reject the whole string'
        );
    }

    // =========================================================================
    // Composition
    // =========================================================================

    public function testCastsChainLikeAnyOtherFilter(): void
    {
        $this->assertSame('3', self::renderWith(Policy::default(), '{{ x |> int |> string }}', ['x' => '3.7']));
    }

    /**
     * `string` is the load-bearing cast: it is the way into the string filters
     * from a value that is not a string.
     */
    public function testStringUnlocksStringFiltersOnANumericValue(): void
    {
        $this->assertSame('3.14', self::renderWith(Policy::default(), '{{ x |> string |> trim }}', ['x' => 3.14]));
    }

    /**
     * The motivating case end to end. `round` takes an `int|float` and there is no
     * coercion across that boundary under `strictTypes`, so the cast is the way
     * through — and the cast is what makes the conversion visible in the template.
     */
    public function testCastMakesANumericFilterWorkOnANumericString(): void
    {
        $this->assertSame(
            '3.7',
            self::renderWith(Policy::default(), '{{ x |> float |> round(2) }}', ['x' => '3.7']),
            'cast first, then round'
        );
    }

    // =========================================================================
    // Policy
    // =========================================================================

    /**
     * The whole point: these work under the sandbox, with no PHP access and no
     * user registration. `restricted()` cannot even grant `strictTypes` away, so
     * a template there has no other route to a conversion.
     */
    public function testEveryCastWorksUnderTheRestrictedPolicy(): void
    {
        $restricted = Policy::restricted();

        $this->assertSame('3', self::renderWith($restricted, '{{ x |> int }}', ['x' => '3.7']));
        $this->assertSame('3.7', self::renderWith($restricted, '{{ x |> float }}', ['x' => '3.7']));
        $this->assertSame('3.7', self::renderWith($restricted, '{{ x |> string }}', ['x' => 3.7]));
        $this->assertSame('1', self::renderWith($restricted, '{{ x |> bool }}', ['x' => 'x']));
        $this->assertSame('[1]', self::renderWith($restricted, '{{ x |> array |> json }}', ['x' => 1]));
    }

    /**
     * A cast is a value operation, so the OUTPUT boundary still applies: the
     * engine wraps output in `htmlspecialchars((string)(…))` regardless of the
     * value's type. `strictTypes` keeps that cast deliberately — it is how a
     * number is printed at all.
     */
    public function testTheOutputBoundaryStillEscapes(): void
    {
        $this->assertSame(
            '&lt;b&gt;',
            self::renderWith(Policy::default(), '{{ x |> string }}', ['x' => '<b>'])
        );
    }

    // =========================================================================
    // Registry wiring
    // =========================================================================

    public function testEveryCastIsBOTHFilterableAndCallable(): void
    {
        $registry = TestClarityEngine::withPolicy(Policy::default())->getRegistry();

        foreach (['int', 'float', 'string', 'bool', 'array', 'object'] as $name) {
            $this->assertTrue($registry->hasFilter($name), "'{$name}' must be pipeable");
            $this->assertTrue($registry->hasCallable($name), "'{$name}' must be callable");
        }
    }

    public function testNoCastNeedsARuntimeCallable(): void
    {
        // Each cast is an inline `php` template, so it compiles to the cast itself.
        // A runtime entry would be dead weight the compiler never consults.
        $registry = TestClarityEngine::withPolicy(Policy::default())->getRegistry();

        $callables = new \ReflectionProperty($registry, 'callables');
        $callables->setAccessible(true);
        $table = $callables->getValue($registry);

        foreach (['int', 'float', 'string', 'bool', 'array', 'object'] as $name) {
            $this->assertArrayNotHasKey($name, $table, "'{$name}' must compile inline");
        }
    }

    /**
     * The `*val()` spellings are deliberately absent: they are reachable in open
     * mode as ordinary PHP functions, and registering them would duplicate the
     * casts under a second naming convention.
     */
    public function testTheValSpellingsAreNotFilters(): void
    {
        $registry = TestClarityEngine::withPolicy(Policy::default())->getRegistry();

        foreach (['intval', 'floatval', 'strval', 'boolval'] as $name) {
            $this->assertFalse($registry->hasFilter($name), "'{$name}' must not be a cast filter");
        }
    }
}
