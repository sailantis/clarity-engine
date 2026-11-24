<?php

namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use Clarity\Engine\Policy;

/**
 * The `strictVariables` rule.
 *
 * Three tiers, decided at RENDER time by the error handler rather than by emitted
 * code, because PHP already yields null for an absent read and raises a diagnostic
 * alongside it:
 *
 * | policy            | absent name / key / property | wrong operator   |
 * | ----------------- | ---------------------------- | ---------------- |
 * | granted (default) | ClarityException             | ClarityException |
 * | denied            | warning, render continues    | ClarityException |
 *
 * Warning rather than silence is deliberate: Twig's lenient mode returns null
 * silently, so a typo hides forever. Latte, Blade and Stempler leave PHP's warning
 * in place, and so does this — with the template line attached.
 */
final class StrictVariablesTest extends BaseTestCase
{
    private static function lax(): TestClarityEngine
    {
        return TestClarityEngine::withPolicy(Policy::default()->denyRule('strictVariables'));
    }

    /**
     * Render with a NON-THROWING diagnostic handler installed.
     *
     * PHPUnit converts every warning to a test failure, and Clarity chains to the
     * handler installed before its own. Installing one that records the message and
     * returns true therefore captures exactly what the render reports while letting
     * it finish — which is the behaviour under test.
     *
     * @return array{0: string, 1: list<string>} [output, diagnostics]
     */
    private function renderCapturing(TestClarityEngine $engine, string $view, array $vars = []): array
    {
        $seen = [];
        set_error_handler(static function (int $no, string $msg) use (&$seen): bool {
            $seen[] = $msg;
            return true;
        });

        try {
            $out = $engine->renderPartial($view, $vars);
        } finally {
            restore_error_handler();
        }

        return [$out, $seen];
    }

    // =========================================================================
    // Strict (default)
    // =========================================================================

    public function testAnAbsentNameThrowsByDefault(): void
    {
        self::tpl('sv_strict_root', '{{ missing }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/missing/');
        self::render('sv_strict_root');
    }

    public function testAnAbsentNameInAConditionThrowsByDefault(): void
    {
        self::tpl('sv_strict_if', '{% if missing %}y{% else %}n{% endif %}');

        $this->expectException(ClarityException::class);
        self::render('sv_strict_if');
    }

    public function testAMissingKeyThrowsByDefault(): void
    {
        self::tpl('sv_strict_key', '{{ user:nickname }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/nickname/');
        self::render('sv_strict_key', ['user' => ['name' => 'Ada']]);
    }

    public function testAMissingMemberThrowsByDefault(): void
    {
        self::tpl('sv_strict_member', '{{ user.nickname }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/nickname/');
        self::render('sv_strict_member', ['user' => (object) ['name' => 'Ada']]);
    }

    // =========================================================================
    // Lax — output
    // =========================================================================

    public function testAnAbsentNameRendersEmptyAndWarns(): void
    {
        self::tpl('sv_lax_root', '[{{ missing }}]');

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_root');

        $this->assertSame('[]', $out);
        $this->assertCount(1, $diags);
        $this->assertStringContainsString('missing', $diags[0]);
        $this->assertStringContainsString('sv_lax_root', $diags[0], 'the warning must name the template');
        $this->assertStringContainsString(':1', $diags[0], 'the warning must carry the template line');
    }

    public function testAPresentNameIsUnaffected(): void
    {
        self::tpl('sv_lax_present', '[{{ name }}]');

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_present', ['name' => 'Ada']);

        $this->assertSame('[Ada]', $out);
        $this->assertSame([], $diags);
    }

    public function testANullValueStaysDistinguishableFromAbsence(): void
    {
        self::tpl('sv_lax_null', '{% if name is defined %}defined{% else %}undefined{% endif %}');

        $engine = self::lax();

        [$out, $diags] = $this->renderCapturing($engine, 'sv_lax_null', ['name' => null]);
        $this->assertSame('undefined', $out);
        $this->assertSame([], $diags, 'a present-but-null value is not an absence');

        [$out] = $this->renderCapturing($engine, 'sv_lax_null', ['name' => 'Ada']);
        $this->assertSame('defined', $out);
    }

    // =========================================================================
    // Lax — the reason the rule exists: conditions and loops
    // =========================================================================

    public function testAnAbsentNameIsFalsyInACondition(): void
    {
        self::tpl('sv_lax_if', '{% if missing %}y{% else %}n{% endif %}');

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_if');

        $this->assertSame('n', $out);
        $this->assertCount(1, $diags);
    }

    public function testAPresentTruthyNameStillTakesTheBranch(): void
    {
        self::tpl('sv_lax_if_true', '{% if flag %}y{% else %}n{% endif %}');

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_if_true', ['flag' => true]);

        $this->assertSame('y', $out);
        $this->assertSame([], $diags);
    }

    public function testAnAbsentNameIsNegatableInACondition(): void
    {
        self::tpl('sv_lax_not', '{% if not missing %}none{% endif %}');

        [$out] = $this->renderCapturing(self::lax(), 'sv_lax_not');

        $this->assertSame('none', $out);
    }

    public function testAnAbsentListYieldsNoIterations(): void
    {
        self::tpl('sv_lax_for', '{% for i in missing %}[{{ i }}]{% endfor %}done');

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_for');

        $this->assertSame('done', $out);
        $this->assertNotEmpty($diags, 'an absent list is reported, not silently skipped');
        $this->assertStringContainsString('foreach', implode(' ', $diags));
    }

    public function testANullListYieldsNoIterations(): void
    {
        self::tpl('sv_lax_for_null', '{% for i in items %}[{{ i }}]{% endfor %}done');

        [$out] = $this->renderCapturing(self::lax(), 'sv_lax_for_null', ['items' => null]);

        $this->assertSame('done', $out);
    }

    public function testAPresentListStillIterates(): void
    {
        self::tpl('sv_lax_for_ok', '{% for i in items %}[{{ i }}]{% endfor %}');

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_for_ok', ['items' => [1, 2]]);

        $this->assertSame('[1][2]', $out);
        $this->assertSame([], $diags);
    }

    /**
     * A chain as a loop subject is an absence too (`things` is not on `user`), so it
     * iterates zero times under the lax rule rather than throwing.
     */
    public function testAMissingMemberInALoopSubjectIsTolerated(): void
    {
        self::tpl('sv_lax_for_chain', '{% for i in user:things %}[{{ i }}]{% endfor %}done');

        [$out, $diags] = $this->renderCapturing(
            self::lax(),
            'sv_lax_for_chain',
            ['user' => ['name' => 'Ada']]
        );

        $this->assertSame('done', $out);
        $this->assertNotEmpty($diags);
    }

    // =========================================================================
    // Lax — member reads are tolerant too, like every other engine
    // =========================================================================

    public function testAMissingPropertyRendersEmptyAndWarns(): void
    {
        self::tpl('sv_lax_member', '[{{ user.nickname }}]');

        [$out, $diags] = $this->renderCapturing(
            self::lax(),
            'sv_lax_member',
            ['user' => (object) ['name' => 'Ada']]
        );

        $this->assertSame('[]', $out);
        $this->assertCount(1, $diags);
        $this->assertStringContainsString('nickname', $diags[0]);
    }

    public function testAMissingKeyRendersEmptyAndWarns(): void
    {
        self::tpl('sv_lax_key', '[{{ user:nickname }}]');

        [$out, $diags] = $this->renderCapturing(
            self::lax(),
            'sv_lax_key',
            ['user' => ['name' => 'Ada']]
        );

        $this->assertSame('[]', $out);
        $this->assertCount(1, $diags);
    }

    /**
     * The case that motivates member tolerance: the natural way to ask "has a
     * nickname?" must work, exactly as `{% if user %}` does one level up.
     */
    public function testAMissingPropertyIsFalsyInACondition(): void
    {
        self::tpl('sv_lax_if_member', '{% if user.nickname %}y{% else %}n{% endif %}');

        [$out] = $this->renderCapturing(
            self::lax(),
            'sv_lax_if_member',
            ['user' => (object) ['name' => 'Ada']]
        );

        $this->assertSame('n', $out);
    }

    /**
     * A chain step over null is the same absence one level deeper:
     * `user.address.city` where `address` is absent.
     */
    public function testAChainOverAnAbsentIntermediateRendersEmpty(): void
    {
        self::tpl('sv_lax_deep', '[{{ user.address.city }}]');

        [$out, $diags] = $this->renderCapturing(
            self::lax(),
            'sv_lax_deep',
            ['user' => (object) ['name' => 'Ada']]
        );

        $this->assertSame('[]', $out);
        $this->assertNotEmpty($diags);
    }

    // =========================================================================
    // Lax — a WRONG OPERATOR still throws
    // =========================================================================

    /**
     * `user.name` over an ARRAY is not an absence, it is the wrong accessor. PHP
     * words it differently (`Attempt to read property "name" on array`), which is
     * what lets the handler keep it an error while tolerating absence.
     */
    public function testAPropertyReadOnAnArrayStillThrows(): void
    {
        self::tpl('sv_lax_prop_on_array', '{{ user.name }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Cannot read property/');
        self::lax()->renderPartial('sv_lax_prop_on_array', ['user' => ['name' => 'Ada']]);
    }

    public function testAnArrayIndexOnAnObjectStillThrows(): void
    {
        self::tpl('sv_lax_array_on_object', '{{ user[name] }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Cannot use object of type/');
        self::lax()->renderPartial('sv_lax_array_on_object', ['user' => (object) ['name' => 'Ada'], 'name' => 'x']);
    }

    public function testAWrongOperatorStillThrowsInStrictModeToo(): void
    {
        self::tpl('sv_strict_wrong_op', '{{ user.name }}');

        $this->expectException(ClarityException::class);
        self::render('sv_strict_wrong_op', ['user' => ['name' => 'Ada']]);
    }

    // =========================================================================
    // Explicit tolerance still works
    // =========================================================================

    public function testCoalesceStillSuppliesAFallback(): void
    {
        self::tpl('sv_lax_coalesce', "{{ missing ?? 'fb' }}");

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_coalesce');

        $this->assertSame('fb', $out);
        $this->assertSame([], $diags, 'an explicit ?? is intentional and must not warn');
    }

    public function testDefaultsStillApply(): void
    {
        self::tpl('sv_lax_default', "{{ missing |> default('fb') }}");

        // `default` compiles to `??`, so this is quiet even under the strict rule.
        $this->assertSame('fb', self::render('sv_lax_default'));
    }

    // =========================================================================
    // Assignment targets are untouched
    // =========================================================================

    public function testSetOfABareNameStillAssigns(): void
    {
        self::tpl('sv_lax_set', '{% set local = 5 %}{{ local }}');

        $this->assertSame('5', self::lax()->renderPartial('sv_lax_set'));
    }

    public function testSetOfAKeyTargetCreatesTheIntermediate(): void
    {
        self::tpl('sv_lax_set_key', '{% set data:value = 7 %}{{ data:value }}');

        // PHP auto-vivifies the missing intermediate, in both policies.
        $this->assertSame('7', self::lax()->renderPartial('sv_lax_set_key', ['data' => []]));
        $this->assertSame('7', self::render('sv_lax_set_key', ['data' => []]));
    }

    public function testSetOfAnIndexTargetStillAssigns(): void
    {
        self::tpl('sv_lax_set_index', '{% set items[0] = 3 %}{{ items[0] }}');

        $this->assertSame('3', self::lax()->renderPartial('sv_lax_set_index', ['items' => [9]]));
    }

    public function testAnAbsentIndexVariableInAnAssignmentTargetIsTolerated(): void
    {
        self::tpl('sv_lax_set_dyn_index', '{% set items[key] = 3 %}ok');

        [$out, $diags] = $this->renderCapturing(self::lax(), 'sv_lax_set_dyn_index', ['items' => []]);

        $this->assertSame('ok', $out);
        $this->assertNotEmpty($diags, 'the absent index is reported');
    }

    // =========================================================================
    // Open mode parity
    // =========================================================================

    /**
     * `Policy::trusted()` grants `phpVariables`, so the render scope is seeded into
     * PHP locals and a root reads `$name` rather than `$__c_va['name']`. PHP reports
     * an absent local the same way, so the contract is identical.
     */
    public function testOpenModeMatchesSandboxModeUnderLax(): void
    {
        self::tpl('sv_open_if', '{% if missing %}y{% else %}n{% endif %}');
        self::tpl('sv_open_root', '[{{ missing }}]');

        $open = TestClarityEngine::withPolicy(
            Policy::trusted()->denyRule('strictVariables')
        );

        [$out] = $this->renderCapturing($open, 'sv_open_if');
        $this->assertSame('n', $out);

        [$out] = $this->renderCapturing($open, 'sv_open_root');
        $this->assertSame('[]', $out);

        [$out] = $this->renderCapturing(self::lax(), 'sv_open_if');
        $this->assertSame('n', $out);
    }

    public function testOpenModeStillThrowsByDefault(): void
    {
        self::tpl('sv_open_strict', '{{ missing }}');

        $open = TestClarityEngine::withPolicy(Policy::trusted());

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/missing/');
        $open->renderPartial('sv_open_strict');
    }

    // =========================================================================
    // Policy integration
    // =========================================================================

    public function testStrictVariablesIsNotPartOfTheReachQuestion(): void
    {
        $policy = Policy::restricted()->denyRule('strictVariables');

        $this->assertFalse($policy->strictVariables());
        $this->assertTrue($policy->isSandboxed(), 'a strictness rule must not unlock PHP');
    }

    public function testStrictVariablesChangesTheDigest(): void
    {
        $this->assertNotSame(
            Policy::restricted()->digest(),
            Policy::restricted()->denyRule('strictVariables')->digest()
        );
    }

    public function testStrictVariablesRoundTripsThroughTheArrayForm(): void
    {
        $policy = Policy::fromArray(['rules' => ['strictVariables' => false]]);

        $this->assertFalse($policy->strictVariables());
        $this->assertFalse($policy->toArray()['rules']['strictVariables']);
    }
}
