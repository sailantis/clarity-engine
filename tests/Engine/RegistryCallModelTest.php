<?php
namespace Clarity\Tests\Engine;

use Clarity\Engine\Registry;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * Registry-level invariants for the unified call model.
 *
 * The registry keeps three independent tables: `$inlineDefinitions` (codegen),
 * `$filters` (the pipeable set) and `$callables` (the runtime name → callable
 * map handed to templates as `$__c_fn`). Whether a name may be PIPED is a
 * *compile-time* question — see {@see CallSyntaxTest} for the behavioural guard
 * (a call-only name used as a filter is rejected at compile time), and
 * {@see RegistryConsistencyTest} for the structural agreement between the
 * tables. The tests below cover the callable table itself.
 */
class RegistryCallModelTest extends BaseTestCase
{
    public function testLengthAndLenAreBothFilterableAndCallable(): void
    {
        $registry = TestEnvironment::registry();

        foreach (['length', 'len'] as $name) {
            $this->assertTrue($registry->hasFilter($name), "'{$name}' must be filterable");
            $this->assertTrue($registry->hasCallable($name), "'{$name}' must be callable");
            $this->assertTrue($registry->hasFunction($name), "'{$name}' must be callable via call syntax");
        }
    }

    public function testLenAliasesLength(): void
    {
        // Same callable instance — an alias, not a parallel entry that can drift.
        $registry = TestEnvironment::registry();
        $this->assertSame($registry->getCallable('length'), $registry->getCallable('len'));
    }

    public function testCallOnlyNamesAreNotFilterable(): void
    {
        $registry = TestEnvironment::registry();

        foreach (['vars', 'include', 'dd'] as $name) {
            $this->assertFalse(
                $registry->hasFilter($name),
                "'{$name}' is call-only; it must not be filterable"
            );
            $this->assertTrue(
                $registry->hasCallable($name),
                "'{$name}' must still be callable"
            );
        }
    }

    /**
     * `isset` is the one CALL-ONLY INLINE function: it has codegen but no
     * callable, and its record sets `filter => false`, so it is reachable as
     * `isset(x)` and refused as `x |> isset`. It is the shape the two older
     * tables cannot express — callable without being a runtime callable, and
     * call-only without being in the pipeable set.
     */
    public function testIsSetIsACallOnlyInlineFunction(): void
    {
        $registry = TestEnvironment::registry();

        $this->assertTrue($registry->hasFunction('isset'), "'isset' must be callable");
        $this->assertTrue($registry->hasCallable('isset'), "'isset' must be reachable by call syntax");
        $this->assertTrue($registry->hasInlineFilter('isset'), "'isset' has codegen");
        $this->assertTrue($registry->isInlineFunction('isset'), "'isset' is a call-only inline function");
        $this->assertFalse($registry->hasFilter('isset'), "'isset' must NOT be filterable");
        $this->assertNull($registry->getCallable('isset'), "'isset' must not have a runtime callable");
        $this->assertArrayNotHasKey(
            'isset',
            $registry->allCallables(),
            "'isset' compiles inline and must not be in the runtime table"
        );
    }

    public function testAddInlineFunctionRegistersCallOnlyCodegen(): void
    {
        $registry = new Registry();

        $registry->addInlineFunction('probe_fn', ['php' => 'probe({1})']);

        $this->assertTrue($registry->hasCallable('probe_fn'));
        $this->assertTrue($registry->isInlineFunction('probe_fn'));
        $this->assertFalse($registry->hasFilter('probe_fn'), 'an inline function is not a filter');
        $this->assertArrayNotHasKey('probe_fn', $registry->allCallables());
    }

    public function testAddInlineFunctionOverridesAFilterRegistration(): void
    {
        $registry = new Registry();

        $registry->addInlineFilter('probe_toggle', ['php' => '({1})']);
        $this->assertTrue($registry->hasFilter('probe_toggle'));

        $registry->addInlineFunction('probe_toggle', ['php' => '({1})']);

        $this->assertFalse($registry->hasFilter('probe_toggle'));
        $this->assertTrue($registry->isInlineFunction('probe_toggle'));
    }

    /**
     * `dump` is the ONE debug name that is also filterable: its filter form is a
     * pass-through probe rather than a dispatch of the callable, which is why it
     * can accept a piped value at all.
     */
    public function testDumpIsBothFilterableAndCallable(): void
    {
        $registry = TestEnvironment::registry();

        $this->assertTrue($registry->hasFilter('dump'), "'dump' must be filterable");
        $this->assertTrue($registry->hasCallable('dump'), "'dump' must be callable");
        $this->assertTrue($registry->hasFunction('dump'), "'dump' must be callable via call syntax");
    }

    public function testRuntimeTableCarriesEveryCallable(): void
    {
        // ONE table, so call-only names and both-form names are all present.
        $callables = TestEnvironment::registry()->allCallables();

        foreach ([
            // both filter and call form
            'length', 'len', 'json', 'keys', 'values', 'first', 'last',
            'map', 'filter', 'reduce', 'sort', 'dump',
            // call-only
            'vars', 'include', 'dd',
        ] as $name) {
            $this->assertArrayHasKey($name, $callables, "'{$name}' must be in the runtime callable table");
        }
    }

    public function testInlineOnlyNamesAreNotInTheRuntimeTable(): void
    {
        // A `php`-templated name with no callable compiles inline and needs no
        // runtime entry at all (e.g. `upper`, `truncate`, `abs`).
        $callables = TestEnvironment::registry()->allCallables();

        foreach (['upper', 'truncate', 'abs', 'round'] as $name) {
            $this->assertArrayNotHasKey(
                $name,
                $callables,
                "'{$name}' compiles inline and must not need a runtime entry"
            );
        }
    }

    /**
     * `allCallables()` returns the SOURCE table directly — there is no copy, no
     * filter pass and no rebuild, so a registration is visible immediately and
     * repeated reads are identical.
     */
    public function testFilterRegisteredAfterFirstReadIsVisible(): void
    {
        // A private registry: these tests REGISTER, so they must not touch the
        // shared one (it would leak into every other test and make them
        // order-dependent).
        $registry = new Registry();

        $registry->allCallables();
        $this->assertArrayNotHasKey('late_filter_probe', $registry->allCallables());

        $registry->addFilter('late_filter_probe', static fn(mixed $v): string => "probe:{$v}");

        $this->assertArrayHasKey(
            'late_filter_probe',
            $registry->allCallables(),
            'a filter registered after the table was first read must appear'
        );
    }

    public function testFunctionRegisteredAfterFirstReadIsVisible(): void
    {
        $registry = new Registry();

        $registry->allCallables();
        $registry->addFunction('late_fn_probe', static fn(): string => 'ok');

        $this->assertArrayHasKey(
            'late_fn_probe',
            $registry->allCallables(),
            'a function registered after the table was first read must appear'
        );
    }

    public function testCallableTableIsStableAcrossReads(): void
    {
        $registry = new Registry();

        $this->assertSame(
            \array_keys($registry->allCallables()),
            \array_keys($registry->allCallables()),
            'repeated reads must return the same table'
        );
    }

    /**
     * The callable table and the compile-time tables are SEPARATE sources.
     * A runtime-callable name is listed in `$filters` but has no `php` template;
     * an inline-only name has `php` and no callable. Neither is derived from the
     * other, so neither can drift.
     */
    public function testCallablesAndInlineTemplatesAreIndependentSources(): void
    {
        $registry = new Registry();

        // Runtime-callable filter: callable yes, inline template no.
        $this->assertNotNull($registry->getCallable('slug'));
        $this->assertNull($registry->getInlineFilter('slug'));

        // Inline-only filter: inline template yes, callable no.
        $this->assertNull($registry->getCallable('upper'));
        $this->assertNotNull($registry->getInlineFilter('upper'));

        // Both forms: `json` is the one built-in carrying an inline template
        // AND a runtime callable.
        $this->assertNotNull($registry->getCallable('json'));
        $this->assertNotNull($registry->getInlineFilter('json'));
    }

    /**
     * `addInlineFilter()` adds a compile-time template; it must NOT create a
     * runtime callable (there is nothing to dispatch).
     */
    public function testAddInlineFilterDoesNotCreateACallable(): void
    {
        $registry = new Registry();

        $registry->addInlineFilter('inline_probe', ['php' => '({1} . "!")']);

        $this->assertTrue($registry->hasFilter('inline_probe'), 'the name is filterable');
        $this->assertArrayNotHasKey(
            'inline_probe',
            $registry->allCallables(),
            'an inline template has no runtime callable to dispatch'
        );
    }

    public function testFilterReferenceRequiresAFilterableCallable(): void
    {
        $registry = new Registry();

        // The call-only builtins are dispatchable but not usable as filters.
        foreach (['vars', 'include', 'dd'] as $name) {
            $this->assertNotNull($registry->getCallable($name), "'{$name}' must be dispatchable");
            $this->assertFalse($registry->hasFilter($name), "'{$name}' must not be filterable");
        }

        // `dump` is the exception: it is dispatchable AND filterable.
        $this->assertNotNull($registry->getCallable('dump'));
        $this->assertTrue($registry->hasFilter('dump'));
    }
}
