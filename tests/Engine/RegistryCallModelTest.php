<?php
namespace Clarity\Tests\Engine;

use Clarity\Engine\Registry;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * Registry-level invariants for the unified call model.
 *
 * The registry keeps three independent tables: `$inlineFilters` (codegen),
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

        foreach (['context', 'include', 'dump', 'dd'] as $name) {
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

    public function testRuntimeTableCarriesEveryCallable(): void
    {
        // ONE table, so call-only names and both-form names are all present.
        $callables = TestEnvironment::registry()->allCallables();

        foreach ([
            // both filter and call form
            'length', 'len', 'json', 'keys', 'values', 'first', 'last',
            'map', 'filter', 'reduce', 'sort',
            // call-only
            'context', 'include', 'dump', 'dd',
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
        foreach (['context', 'include', 'dump', 'dd'] as $name) {
            $this->assertNotNull($registry->getCallable($name), "'{$name}' must be dispatchable");
            $this->assertFalse($registry->hasFilter($name), "'{$name}' must not be filterable");
        }
    }
}
