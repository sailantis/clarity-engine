<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;

/**
 * `${expr}` (and its shorthand `$$name`): read the variable whose NAME is
 * produced by an expression.
 *
 * The lookup uses the SAME variable model as a literal `{{ name }}` — the render
 * scope plus loop locals — so `??` composes exactly as it does for a literal
 * name, and neither a superglobal nor an engine internal is reachable.  This
 * construct replaces the (removed) `expand` filter.
 */
class DynamicVariableTest extends BaseTestCase
{
    public function testBracedFormResolvesAnIndirectName(): void
    {
        self::tpl('dv_braced', '{{ ${which} }}');
        $this->assertSame('Alice', self::render('dv_braced', ['which' => 'name', 'name' => 'Alice']));
    }

    public function testDollarShorthandIsTheSameConstruct(): void
    {
        self::tpl('dv_shorthand', '{{ $$which }}');
        $this->assertSame('Alice', self::render('dv_shorthand', ['which' => 'name', 'name' => 'Alice']));
    }

    public function testWhitespaceInsideBraces(): void
    {
        self::tpl('dv_space', '{{ ${ which } }}');
        $this->assertSame('Alice', self::render('dv_space', ['which' => 'name', 'name' => 'Alice']));
    }

    public function testTheNameExpressionMayBeComputed(): void
    {
        // The name is an arbitrary expression, not just a variable.
        self::tpl('dv_expr', "{{ \${ 'a' ~ 'b' } }}");
        $this->assertSame('joined', self::render('dv_expr', ['ab' => 'joined']));
    }

    /**
     * Absence is strict, exactly like a literal `{{ name }}`.
     */
    public function testAbsentNameThrows(): void
    {
        self::tpl('dv_absent', '{{ ${missing} }}');

        $this->expectException(ClarityException::class);
        self::render('dv_absent', ['missing' => 'nope']);
    }

    public function testEmptyNameIsACompileError(): void
    {
        self::tpl('dv_empty', '{{ ${} }}');

        $this->expectException(ClarityException::class);
        self::render('dv_empty', []);
    }

    /**
     * The distinguishing property: `??` composes, because the lookup reads the
     * scope through a presence guard rather than raising.
     */
    public function testNullCoalescingSuppliesAFallbackForAnAbsentVariable(): void
    {
        self::tpl('dv_coalesce', '{{ ${missing} ?? "DEF" }}');
        $this->assertSame('DEF', self::render('dv_coalesce', ['missing' => 'nope']));
    }

    public function testNullCoalescingHandlesAnAbsentNameVariable(): void
    {
        // `ref` itself is absent: the whole expression still yields the fallback
        // instead of raising while the name is computed.
        self::tpl('dv_coalesce_name', '{{ ${ref} ?? "DEF" }}');
        $this->assertSame('DEF', self::render('dv_coalesce_name', []));
    }

    public function testAPresentNullValueStillReachesTheFallback(): void
    {
        // A PRESENT variable holding null is present (array_key_exists), so the
        // lookup yields null and `??` supplies the fallback — same as a literal.
        self::tpl('dv_null_val', '{{ ${which} ?? "DEF" }}');
        $this->assertSame('DEF', self::render('dv_null_val', ['which' => 'n', 'n' => null]));
    }

    public function testChainedAccessAppliesToTheLookedUpValue(): void
    {
        self::tpl('dv_chain', '{{ ${which}:len }}');
        $this->assertSame('7', self::render('dv_chain', ['which' => 'user', 'user' => ['len' => 7]]));
    }

    public function testLoopLocalsAreReachable(): void
    {
        self::tpl('dv_loop', '{% for item in items %}{{ ${which} }}{% endfor %}');
        $this->assertSame('a', self::render('dv_loop', ['items' => ['a'], 'which' => 'item']));
    }

    public function testLoopLocalsFallThroughToTheScope(): void
    {
        self::tpl('dv_loop_scope', '{% for item in items %}{{ ${which} }}{% endfor %}');
        $this->assertSame('Alice', self::render('dv_loop_scope', ['items' => [1], 'which' => 'outer', 'outer' => 'Alice']));
    }

    // =========================================================================
    // Security
    // =========================================================================

    public function testCannotReachASuperglobalInSandboxMode(): void
    {
        self::tpl('dv_super', '{{ ${which} }}');

        $this->expectException(ClarityException::class);
        $this->render('dv_super', ['which' => '_SERVER']);
    }

    public function testCannotReachAnEngineInternal(): void
    {
        // `__c_fn` names the callable registry but is not a scope entry.
        self::tpl('dv_internal', '{{ ${which} }}');

        $this->expectException(ClarityException::class);
        $this->render('dv_internal', ['which' => '__c_fn']);
    }

    /**
     * The lookup must never be emitted as a PHP dynamic variable.  Asserting on
     * the compiled source pins that: a dynamic dereference `\${$…}` would be a
     * change of security posture, not an implementation detail.
     */
    public function testEmitsAScopeReadNotAPhpDynamicVariable(): void
    {
        self::tpl('dv_source', '{{ ${which} }}');
        self::render('dv_source', ['which' => 'name', 'name' => 'x']);

        $compiled = $this->compiledSource('dv_source');
        $this->assertStringContainsString('array_key_exists', $compiled);
        $this->assertStringContainsString('$__c_va[$__c_tmp]', $compiled);
    }

    public function testSigilInsideAStringLiteralIsLiteralText(): void
    {
        // `"${which}"` is a string, not a lookup.  (The engine escapes the
        // dollar in double-quoted literals so PHP does not interpolate it.)
        self::tpl('dv_string', '{{ "${which}" }}');
        $this->assertSame('${which}', self::render('dv_string', ['which' => 'name']));
    }
}
