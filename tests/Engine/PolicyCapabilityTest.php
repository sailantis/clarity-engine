<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;

/**
 * Each capability, compiled — the policy's effect on what a template can reach.
 *
 * The point of splitting the old boolean is that a grant is INDEPENDENT: turning
 * on `methodCalls` must not turn on `rawPhp`, and an allowlist must not turn on
 * anything at all beyond the names it lists.  Every test below therefore grants
 * one thing and asserts the neighbours are still refused.
 */
class PolicyCapabilityTest extends BaseTestCase
{
    private static function engine(Policy $policy): TestClarityEngine
    {
        return TestClarityEngine::withPolicy($policy);
    }

    private static function makeObject(): object
    {
        return new class
        {
            public string $label = 'L';
            public function name(): string
            {
                return 'Alice';
            }
            public function greet(string $who): string
            {
                return 'Hi ' . $who;
            }
            public function self(): self
            {
                return $this;
            }
        };
    }

    // =========================================================================
    // rawPhp
    // =========================================================================

    public function testRawPhpIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_php_off', "{% php echo 'x'; %}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'rawPhp' capability/");
        self::engine(Policy::custom()->allowCapability('methodCalls'))->renderPartial('pc_php_off');
    }

    public function testRawPhpIsGrantedByItsOwnCapability(): void
    {
        self::tpl('pc_php_on', "{% php echo 'x'; %}");

        $this->assertSame('x', self::engine(Policy::custom()->allowCapability('rawPhp'))->renderPartial('pc_php_on'));
    }

    // =========================================================================
    // methodCalls
    // =========================================================================

    public function testMethodCallIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_mc_off', '{{ $obj->name() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Method calls are not allowed/');
        self::engine(Policy::custom()->allowCapability('rawPhp'))
            ->renderPartial('pc_mc_off', ['obj' => self::makeObject()]);
    }

    public function testMethodCallIsGrantedByItsOwnCapability(): void
    {
        self::tpl('pc_mc_on', "{{ \$obj->greet('Bob') }}");

        $this->assertSame(
            'Hi Bob',
            self::engine(Policy::custom()->allowCapability('methodCalls'))
                ->renderPartial('pc_mc_on', ['obj' => self::makeObject()])
        );
    }

    // =========================================================================
    // newExpressions
    // =========================================================================

    public function testNewIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_new_off', '{{ new DateTime("2020-01-02") }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'newExpressions' capability/");
        self::engine(Policy::trusted())->renderPartial('pc_new_off');
    }

    public function testNewIsGrantedByItsOwnCapability(): void
    {
        self::tpl('pc_new_on', '{{ new DateTime("2020-01-02") |> date("Y") }}');

        $this->assertSame(
            '2020',
            self::engine(Policy::custom()->allowCapability('newExpressions'))->renderPartial('pc_new_on')
        );
    }

    public function testNewAcceptsAFullyQualifiedName(): void
    {
        // The name is compiled to a fully qualified form, so a leading separator
        // is not a second spelling that happens to work — it is the same one.
        self::tpl('pc_new_fqn', '{{ new \DateTime("2020-01-02") |> date("Y-m-d") }}');

        $this->assertSame(
            '2020-01-02',
            self::engine(Policy::custom()->allowCapability('newExpressions'))->renderPartial('pc_new_fqn')
        );
    }

    public function testNewArgumentsAreCompiledAsClarityExpressions(): void
    {
        // `rows` must resolve through the scope, not as a PHP constant.
        self::tpl('pc_new_args', '{% if new ArrayObject(rows) |> length > 1 %}Y{% else %}N{% endif %}');

        $this->assertSame(
            'Y',
            self::engine(Policy::custom()->allowCapability('newExpressions'))
                ->renderPartial('pc_new_args', ['rows' => [1, 2, 3]])
        );
    }

    // =========================================================================
    // staticCalls
    // =========================================================================

    public function testStaticCallIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_st_off', '{{ DateTime::ATOM }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'staticCalls' capability/");
        self::engine(Policy::custom()->allowCapability('newExpressions'))->renderPartial('pc_st_off');
    }

    public function testStaticMethodCallAndConstantBothWork(): void
    {
        self::tpl('pc_st_method', '{{ DateTime::createFromFormat("Y-m-d", "2020-01-02") |> date("Y") }}');
        self::tpl('pc_st_const', '{{ DateTime::class }}');

        $engine = self::engine(Policy::custom()->allowCapability('staticCalls'));
        $this->assertSame('2020', $engine->renderPartial('pc_st_method'));
        $this->assertSame('DateTime', $engine->renderPartial('pc_st_const'));
    }

    public function testStaticCallOnANamespacedClass(): void
    {
        self::tpl('pc_st_ns', '{{ \Clarity\Tests\Engine\PolicyCapabilityFixture::label() }}');

        $this->assertSame(
            'fixture',
            self::engine(Policy::custom()->allowCapability('staticCalls'))->renderPartial('pc_st_ns')
        );
    }

    // =========================================================================
    // instanceof — grammar, available in every policy
    // =========================================================================

    public function testInstanceofTakesAClassNameAndWorksInEveryPolicy(): void
    {
        self::tpl('pc_io', '{% if obj instanceof DateTime %}Y{% else %}N{% endif %}');

        // The default policy: no capability needed, because instanceof does not
        // reach anything the scope did not already hold.
        $engine = self::engine(Policy::sandboxed());
        $this->assertSame('Y', $engine->renderPartial('pc_io', ['obj' => new \DateTime()]));
        $this->assertSame('N', $engine->renderPartial('pc_io', ['obj' => 5]));
    }

    public function testABareClassnameOutsideAConstructIsRefused(): void
    {
        // `Foo\Bar` is a name the operator loop cannot emit, and it is not a
        // scope read either — so it is reported rather than silently compiled.
        self::tpl('pc_bare_ns', '{{ Foo\Bar }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/A PHP class name .* is not allowed here/');
        self::engine(Policy::open())->renderPartial('pc_bare_ns');
    }

    // =========================================================================
    // superglobals
    // =========================================================================

    public function testSuperglobalsAreRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_sg_off', '{{ _SERVER["PHP_SELF"] }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/_SERVER.* is not defined in this context/');
        self::engine(Policy::custom()->allowCapability('phpVariables'))
            ->renderPartial('pc_sg_off');
    }

    public function testSuperglobalsAreGrantedByTheirOwnCapability(): void
    {
        // Note the scope is NOT seeded: the read is PHP's `$_SERVER` because the
        // capability says so, not because a local happens to exist.
        self::tpl('pc_sg_on', '{{ _SERVER["PHP_SELF"] |> length > 0 ? "yes" : "no" }}');

        $this->assertSame(
            'yes',
            self::engine(Policy::custom()->allowCapability('superglobals'))->renderPartial('pc_sg_on')
        );
    }

    public function testASuperglobalReadIsNotAScopeRead(): void
    {
        // A scope entry spelled `_SERVER` must not shadow PHP's own variable once
        // the capability is granted: the capability is what the name means.
        self::tpl('pc_sg_shadow', '{{ _SERVER["marker"] }}');

        $engine = self::engine(Policy::custom()->allowCapability('superglobals'));
        try {
            $engine->renderPartial('pc_sg_shadow', ['_SERVER' => ['marker' => 'from-scope']]);
            $this->fail('a granted superglobal must not resolve to the render scope');
        } catch (ClarityException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testOnlyKnownSuperglobalsAreTreatedAsSuperglobals(): void
    {
        // `_SERVERX` is an ordinary scope name even with the capability on.
        self::tpl('pc_sg_near', '{{ _SERVERX }}');

        $this->assertSame(
            'scope',
            self::engine(Policy::custom()->allowCapability('superglobals'))
                ->renderPartial('pc_sg_near', ['_SERVERX' => 'scope'])
        );
    }

    // =========================================================================
    // variableVariables
    // =========================================================================

    public function testVariableVariablesAreOnByDefault(): void
    {
        self::tpl('pc_vv_on', '{{ $$name }}');

        $this->assertSame(
            'v',
            self::engine(Policy::sandboxed())->renderPartial('pc_vv_on', ['name' => 'x', 'x' => 'v'])
        );
    }

    // =========================================================================
    // phpVariables
    // =========================================================================

    public function testScopeIsNotSeededWithoutPhpVariables(): void
    {
        // The capability is about the SEEDING, not about a read: a template can
        // still read its scope through the `$__c_va` form.
        self::tpl('pc_pv_off', '{{ title }}');

        $this->assertSame(
            'T',
            self::engine(Policy::sandboxed())->renderPartial('pc_pv_off', ['title' => 'T'])
        );
    }

    public function testPhpVariablesMakesALocalAndTheScopeTheSameName(): void
    {
        self::tpl('pc_pv_on', "{% php \$t = 'from-php'; %}{{ t }}");

        $this->assertSame(
            'from-php',
            self::engine(Policy::custom()->allowCapability('phpVariables')->allowCapability('rawPhp'))
                ->renderPartial('pc_pv_on')
        );
    }
}
