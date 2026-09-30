<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;

/**
 * The policy object itself: presets, capabilities, allowlists, digests.
 *
 * These are pure-object tests — nothing is compiled — because the properties
 * that matter here are ones the compiler depends on and cannot repair: that two
 * allowlists in a different order fingerprint the same, that a change to any
 * single entry fingerprints differently, and that a round trip through config
 * survives.
 */
class PolicyTest extends BaseTestCase
{
    // =========================================================================
    // Presets
    // =========================================================================

    public function testSandboxedIsTheDefaultShape(): void
    {
        $policy = Policy::sandboxed();

        $this->assertSame([
            'rawPhp'            => false,
            'methodCalls'       => false,
            'superglobals'      => false,
            'phpVariables'      => false,
            'variableVariables' => true,
            'newExpressions'    => false,
            'staticCalls'       => false,
        ], $policy->capabilities());
    }

    public function testOpenGrantsEveryCapabilityAndNoAllowlist(): void
    {
        $policy = Policy::open();

        foreach (Policy::CAPABILITIES as $capability) {
            $this->assertTrue($policy->allows($capability), "{$capability} must be on");
        }
        $this->assertFalse($policy->restrictsFunctions());
        $this->assertFalse($policy->restrictsFilters());
    }

    public function testTrustedGrantsEverythingExceptObjectConstruction(): void
    {
        $policy = Policy::trusted();

        $this->assertTrue($policy->allows('rawPhp'));
        $this->assertTrue($policy->allows('methodCalls'));
        $this->assertTrue($policy->allows('superglobals'));
        $this->assertTrue($policy->allows('phpVariables'));

        // Constructing an arbitrary class is a different order of trust from
        // calling a method on an object the application already passed in.
        $this->assertFalse($policy->allows('newExpressions'));
        $this->assertFalse($policy->allows('staticCalls'));
    }

    public function testAnEngineWithNoConfigurationIsSandboxed(): void
    {
        $engine = new TestClarityEngine([
            'viewPath'  => \Clarity\Tests\TestEnvironment::viewDir(),
            'cachePath' => \Clarity\Tests\TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
        ]);

        $this->assertTrue($engine->isSandboxed());
        $this->assertSame(Policy::sandboxed()->digest(), $engine->getPolicy()->digest());
    }

    public function testTheConfiguredPolicyActuallyReachesTheEngine(): void
    {
        // The constructor used to install the default AFTER reading the config,
        // which silently discarded whatever was configured.
        $engine = TestClarityEngine::withPolicy(Policy::open());

        $this->assertTrue($engine->getPolicy()->isOpen());
        $this->assertFalse($engine->isSandboxed());
    }

    // =========================================================================
    // Capabilities
    // =========================================================================

    public function testCapabilitiesAreFluentAndAccumulate(): void
    {
        $policy = Policy::custom()
            ->allowCapability('methodCalls')
            ->allowCapability('superglobals');

        $this->assertTrue($policy->allows('methodCalls'));
        $this->assertTrue($policy->allows('superglobals'));
        $this->assertFalse($policy->allows('rawPhp'));
    }

    public function testDenyWinsOverAllow(): void
    {
        $policy = Policy::open()->denyCapability('rawPhp');

        $this->assertFalse($policy->allows('rawPhp'));
        $this->assertTrue($policy->allows('methodCalls'));
    }

    public function testAnUnknownCapabilityIsRefused(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unknown policy capability/');
        Policy::custom()->allowCapability('teleportation');
    }

    // =========================================================================
    // Allowlists
    // =========================================================================

    public function testAnEmptyAllowlistIsNotARestriction(): void
    {
        // The rule that makes Policy::open() the engine's old PHP mode exactly.
        $this->assertFalse(Policy::open()->restrictsFunctions());
        $this->assertTrue(Policy::open()->allowsFunction('anything_at_all'));
        $this->assertTrue(Policy::sandboxed()->allowsFunction('anything_at_all'));
    }

    public function testANonEmptyAllowlistIsTheCompleteSet(): void
    {
        $policy = Policy::sandboxed()->allowFunctions('strtoupper', 'count');

        $this->assertTrue($policy->restrictsFunctions());
        $this->assertTrue($policy->allowsFunction('strtoupper'));
        $this->assertTrue($policy->allowsFunction('count'));
        $this->assertFalse($policy->allowsFunction('strrev'));
    }

    public function testFunctionNamesAreCaseInsensitiveAndNamespaceAware(): void
    {
        $policy = Policy::sandboxed()->allowFunctions('Strtoupper');

        $this->assertTrue($policy->allowsFunction('strtoupper'));
        $this->assertTrue($policy->allowsFunction('\\strtoupper'));
        $this->assertTrue($policy->allowsFunction('STRTOUPPER'));
    }

    public function testFiltersHaveTheirOwnAllowlist(): void
    {
        $policy = Policy::sandboxed()->allowFilters('markdown');

        $this->assertTrue($policy->allowsFilter('markdown'));
        $this->assertFalse($policy->allowsFilter('markdown_extra'));
        // The function list is untouched by a filter grant.
        $this->assertFalse($policy->restrictsFunctions());
    }

    public function testDenyWinsOverAnAllowlistEntry(): void
    {
        $policy = Policy::sandboxed()
            ->allowFunctions('strrev')
            ->denyFunctions('strrev');

        $this->assertTrue($policy->deniesFunction('strrev'));
        $this->assertSame(['strrev'], $policy->deniedFunctions());
    }

    public function testAListedFunctionCountsAsReachingPhp(): void
    {
        // Otherwise allowFunctions() would be a grant that grants nothing.
        $this->assertTrue(Policy::sandboxed()->allowFunctions('count')->allowsPhp());
        $this->assertTrue(Policy::sandboxed()->allowFilters('markdown')->allowsPhp());
        $this->assertFalse(Policy::sandboxed()->allowsPhp());
    }

    // =========================================================================
    // Config round trip
    // =========================================================================

    public function testArrayFormRoundTrips(): void
    {
        $policy = Policy::custom()
            ->allowCapability('methodCalls')
            ->allowFunctions('strtoupper', 'count')
            ->allowFilters('markdown')
            ->denyFunctions('exec');

        $restored = Policy::fromArray($policy->toArray());

        $this->assertSame($policy->digest(), $restored->digest());
        $this->assertSame($policy->toArray(), $restored->toArray());
    }

    public function testFromArrayStartsFromTheSandboxedPreset(): void
    {
        $policy = Policy::fromArray(['capabilities' => ['rawPhp' => true]]);

        $this->assertTrue($policy->allows('rawPhp'));
        $this->assertTrue($policy->allows('variableVariables'));
        $this->assertFalse($policy->allows('methodCalls'));
    }

    public function testFromArrayRefusesUnknownKeys(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unknown policy key/');
        Policy::fromArray(['capabilites' => []]);
    }

    public function testFromArrayRefusesUnknownCapabilities(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unknown policy capability/');
        Policy::fromArray(['capabilities' => ['teleportation' => true]]);
    }

    public function testAnEngineAcceptsTheArrayForm(): void
    {
        $engine = TestClarityEngine::withPolicy(['capabilities' => ['rawPhp' => true]]);

        $this->assertTrue($engine->getPolicy()->allows('rawPhp'));
    }

    // =========================================================================
    // Digest
    // =========================================================================

    public function testDigestIgnoresInsertionOrder(): void
    {
        $a = Policy::custom()->allowFunctions('b', 'a', 'c');
        $b = Policy::custom()->allowFunctions('c', 'a', 'b');

        $this->assertSame($a->digest(), $b->digest());
    }

    public function testDigestChangesWhenAnySingleEntryChanges(): void
    {
        // The digest is what recompiles a template, so a change it cannot see is
        // a change the cache would serve stale.
        $base = Policy::custom()->allowFunctions('a', 'b');

        $this->assertNotSame($base->digest(), Policy::custom()->allowFunctions('a', 'b', 'c')->digest());
        $this->assertNotSame($base->digest(), Policy::custom()->allowFunctions('a', 'z')->digest());
        $this->assertNotSame($base->digest(), Policy::custom()->allowCapability('methodCalls')->digest());
        $this->assertNotSame($base->digest(), Policy::custom()->allowFunctions('a', 'b')->denyFunctions('a')->digest());
        $this->assertNotSame($base->digest(), Policy::sandboxed()->digest());
    }

    public function testEveryCapabilityContributesToTheDigest(): void
    {
        // One assertion per capability, so a forgotten field in digest() fails
        // here rather than as a stale compiled template in production.
        $seen = [];
        foreach (Policy::CAPABILITIES as $capability) {
            $flipped = Policy::open()->denyCapability($capability);
            $this->assertNotSame(
                Policy::open()->digest(),
                $flipped->digest(),
                "flipping '{$capability}' must change the digest"
            );
            $seen[$flipped->digest()] = true;
        }
        $this->assertCount(\count(Policy::CAPABILITIES), $seen);
    }

    public function testTheDigestIsShortAndOpaque(): void
    {
        $digest = Policy::open()->digest();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $digest);
    }

    // =========================================================================
    // Coarse questions
    // =========================================================================

    public function testIsOpenAndIsSandboxedAreOppositeCoarseAnswers(): void
    {
        $this->assertTrue(Policy::open()->isOpen());
        $this->assertFalse(Policy::open()->isSandboxed());

        $this->assertTrue(Policy::sandboxed()->isSandboxed());
        $this->assertFalse(Policy::sandboxed()->isOpen());

        $this->assertFalse(Policy::trusted()->isOpen());
        $this->assertFalse(Policy::trusted()->isSandboxed());
    }

    public function testAnAllowlistMakesAPolicyNotOpen(): void
    {
        // `isOpen()` means "the engine's old PHP mode", so a policy that lists
        // three functions is not it — even though every capability is on.
        $this->assertFalse(Policy::open()->allowFunctions('count')->isOpen());
        $this->assertFalse(Policy::open()->denyFunctions('exec')->isOpen());
    }

    public function testVariableVariablesAloneDoesNotCountAsReachingPhp(): void
    {
        // It resolves against the engine's own scope, so turning it off makes a
        // template no less able to run PHP.
        $this->assertFalse(Policy::sandboxed()->allowsPhp());
        $this->assertFalse(Policy::custom()->denyCapability('variableVariables')->allowsPhp());
    }

    // =========================================================================
    // The digest drives recompilation
    // =========================================================================

    public function testTheCompiledTemplateRecordsThePolicyDigest(): void
    {
        self::tpl('policy_mark', '{{ name }}');
        $engine = TestClarityEngine::withPolicy(Policy::custom()->allowFunctions('count'));
        $engine->renderPartial('policy_mark', ['name' => 'x']);

        $className = self::loadedClassName($engine, 'policy_mark');
        $this->assertSame(
            Policy::custom()->allowFunctions('count')->digest(),
            $className::$policyDigest
        );
    }

    public function testChangingAnAllowlistRecompilesACachedTemplate(): void
    {
        // The cache keys on template SOURCE, so without the digest a policy change
        // would be served the class compiled under the old policy.  An allowlist
        // change is the case a `COMPILER_VERSION` bump cannot express.
        self::tpl('policy_allow', "{{ 'abc' |> strrev }}");

        $restricted = TestClarityEngine::withPolicy(Policy::sandboxed()->allowFilters('strrev'));
        $this->assertSame('cba', $restricted->renderPartial('policy_allow'));

        // Same cache directory, same template, a policy that no longer allows it.
        $narrower = TestClarityEngine::withPolicy(Policy::sandboxed());
        try {
            $narrower->renderPartial('policy_allow');
            $this->fail('the cached class must not be reused under a different policy');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('not registered', $e->getMessage());
        }

        // And widening it again picks the new policy up too.
        $wider = TestClarityEngine::withPolicy(Policy::open());
        $this->assertSame('cba', $wider->renderPartial('policy_allow'));
    }

    public function testChangingACapabilityRecompilesACachedTemplate(): void
    {
        self::tpl('policy_cap', '{{ $obj->name() }}');
        $obj = new class
        {
            public function name(): string
            {
                return 'Alice';
            }
        };

        $without = TestClarityEngine::withPolicy(Policy::sandboxed());
        try {
            $without->renderPartial('policy_cap', ['obj' => $obj]);
            $this->fail('method calls must be refused under the default policy');
        } catch (ClarityException) {
            $this->addToAssertionCount(1);
        }

        $with = TestClarityEngine::withPolicy(Policy::custom()->allowCapability('methodCalls'));
        $this->assertSame('Alice', $with->renderPartial('policy_cap', ['obj' => $obj]));
    }

    /**
     * The class the engine compiled and loaded for $view.
     *
     * @return class-string
     */
    private static function loadedClassName(TestClarityEngine $engine, string $view): string
    {
        $prop = new \ReflectionProperty($engine, 'cache');
        $prop->setAccessible(true);

        /** @var \Clarity\Engine\Cache $cache */
        $cache = $prop->getValue($engine);
        $name  = $cache->getLoadedClassName($view);

        self::assertIsString($name, 'the template must be compiled and loaded');

        return $name;
    }

    // =========================================================================
    // The old predicates are gone
    // =========================================================================

    public function testTheSandboxSetterIsGone(): void
    {
        $this->assertFalse(
            \method_exists(\Clarity\ClarityEngine::class, 'setSandboxMode'),
            'setSandboxMode() is replaced by setPolicy(); it must not survive as a second way to say one thing'
        );
        $this->assertFalse(\method_exists(\Clarity\ClarityEngine::class, 'setDeniedFunctions'));
        $this->assertFalse(\method_exists(\Clarity\ClarityEngine::class, 'getDeniedFunctions'));
    }
}
