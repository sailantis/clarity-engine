<?php

namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use Clarity\Engine\Policy;

/**
 * The `escapeHtml` rule, and `escape` as a MARKER rather than a plain filter.
 *
 * `raw` and `escape` are the two halves of one switch: `raw` cancels the automatic
 * wrapper, `escape` performs its work and cancels it, so a value is escaped
 * exactly once either way. The marker is meaningful only in output position —
 * in `{% set %}` and `{% if %}` escaping remains a value transform.
 */
final class EscapePolicyTest extends BaseTestCase
{
    private static function engineWith(Policy $policy): TestClarityEngine
    {
        return TestClarityEngine::withPolicy($policy);
    }

    // =========================================================================
    // escapeHtml
    // =========================================================================

    public function testHtmlIsEscapedByDefault(): void
    {
        self::tpl('esc_default', '{{ x }}');
        $this->assertSame('&lt;b&gt;', self::render('esc_default', ['x' => '<b>']));
    }

    public function testDenyingEscapeHtmlEmitsTheBareValue(): void
    {
        self::tpl('esc_off', '{{ x }}');

        $engine = self::engineWith(Policy::default()->denyRule('escapeHtml'));
        $this->assertSame('<b>', $engine->renderPartial('esc_off', ['x' => '<b>']));
    }

    /**
     * The rule disables only the AUTOMATIC wrapper. `|> escape` is the opt-in, and
     * it is what makes the Volt idiom — bare by default, `|e` where it matters —
     * expressible in Clarity.
     */
    public function testEscapeRemainsTheOptInWhenEscapeHtmlIsDenied(): void
    {
        self::tpl('esc_off_marker', '{{ x }}|{{ x |> e }}');

        $engine = self::engineWith(Policy::default()->denyRule('escapeHtml'));

        $this->assertSame('<b>|&lt;b&gt;', $engine->renderPartial('esc_off_marker', ['x' => '<b>']));
    }

    public function testDenyingEscapeHtmlStillCastsNonStrings(): void
    {
        self::tpl('esc_off_cast', '[{{ n }}]');

        $engine = self::engineWith(Policy::default()->denyRule('escapeHtml'));
        $this->assertSame('[42]', $engine->renderPartial('esc_off_cast', ['n' => 42]));
    }

    /**
     * `js` context is not an escaping preference: `json_encode` is what makes the
     * output valid JavaScript at all. Turning `escapeHtml` off must not touch it,
     * or `var n = {{ n }};` would emit `12345,` and an unquoted string would break
     * the script.
     */
    public function testDenyingEscapeHtmlLeavesTheJsContextEncoded(): void
    {
        self::tpl('esc_off_js', '<script>var a = "{{ x }}";</script>');

        $engine = self::engineWith(Policy::default()->denyRule('escapeHtml'));
        $out    = $engine->renderPartial('esc_off_js', ['x' => 'a"; alert(1); //']);

        $this->assertStringContainsString('\\u0022', $out, 'the quote must stay escaped in JS context');
        $this->assertStringNotContainsString('alert(1); //";', $out, 'the string must not be closed early');
    }

    public function testCssContextIsUnchangedByTheRule(): void
    {
        self::tpl('esc_css', '<style>a { color: {{ x }} }</style>');

        $this->assertSame(
            $this->engineWith(Policy::default())->renderPartial('esc_css', ['x' => 'red']),
            $this->engineWith(Policy::default()->denyRule('escapeHtml'))->renderPartial('esc_css', ['x' => 'red'])
        );
    }

    // =========================================================================
    // `escape` as a marker
    // =========================================================================

    /**
     * The bug this fixes: `escape` used to compile as an ordinary filter, so auto-
     * escaping wrapped its result and `<b>` became `&amp;lt;b&amp;gt;`.
     */
    public function testEscapeEscapesExactlyOnce(): void
    {
        self::tpl('esc_once', '{{ x |> escape }}');

        $this->assertSame('&lt;b&gt;', self::render('esc_once', ['x' => '<b>']));
    }

    public function testEscapeAliasesAreMarkersToo(): void
    {
        self::tpl('esc_alias_e', '{{ x |> e }}');
        self::tpl('esc_alias_esc', '{{ x |> esc }}');

        $this->assertSame('&lt;b&gt;', self::render('esc_alias_e', ['x' => '<b>']));
        $this->assertSame('&lt;b&gt;', self::render('esc_alias_esc', ['x' => '<b>']));
    }

    /**
     * All three spellings must be interchangeable with the marker behaviour, not just
     * with the filter definition — the aliases share a Registry record, but the marker
     * branch in buildFilterCall() matches on the NAME, so a spelling it does not list
     * would silently fall back to double-escaping.
     */
    public function testAllThreeSpellingsEscapeIdentically(): void
    {
        foreach (['escape', 'esc', 'e'] as $name) {
            self::tpl('esc_spelling_' . $name, '{{ x |> ' . $name . ' }}');
        }

        $expected = '&lt;b&amp;&gt;';
        foreach (['escape', 'esc', 'e'] as $name) {
            $this->assertSame(
                $expected,
                self::render('esc_spelling_' . $name, ['x' => '<b&>']),
                "'{$name}' must behave exactly like the canonical 'escape'"
            );
        }
    }

    public function testEscapeFollowedByAFilterStillEscapesOnce(): void
    {
        self::tpl('esc_then_upper', '{{ x |> escape |> upper }}');

        $this->assertSame('&LT;B&GT;', self::render('esc_then_upper', ['x' => '<b>']));
    }

    /**
     * In `js` context the marker must route through the context's own encoding, not
     * HTML escaping: HTML-escaping turns `"` into `&quot;`, which JavaScript decodes
     * back to a quote, so a payload would still close the string.
     */
    public function testEscapeInScriptContextUsesJsonEncoding(): void
    {
        self::tpl('esc_js_marker', '<script>var a = "{{ x |> e }}";</script>');

        $out = self::render('esc_js_marker', ['x' => 'a"; alert(1); //']);

        $this->assertStringContainsString('\\u0022', $out);
        $this->assertStringNotContainsString('&quot;', $out, 'HTML escaping is not valid JS escaping');
    }

    public function testEscapeInScriptContextMatchesAutoEscaping(): void
    {
        self::tpl('esc_js_auto', '<script>var a = "{{ x }}";</script>');
        self::tpl('esc_js_marker2', '<script>var a = "{{ x |> e }}";</script>');

        $vars = ['x' => 'he said "hi"'];
        $this->assertSame(
            self::render('esc_js_auto', $vars),
            self::render('esc_js_marker2', $vars)
        );
    }

    public function testRawStillCancelsEscaping(): void
    {
        self::tpl('esc_raw', '{{ x |> raw }}');

        $this->assertSame('<b>', self::render('esc_raw', ['x' => '<b>']));
    }

    /**
     * Outside output position `escape` is an ordinary filter: a condition compares
     * values, and a `{% set %}` stores one. Treating it as a marker there would
     * escape the stored value and then escape it again on output.
     */
    public function testEscapeInConditionRemainsAValueTransform(): void
    {
        self::tpl('esc_cond', '{% if x |> e == "&lt;b&gt;" %}match{% else %}no{% endif %}');

        $this->assertSame('match', self::render('esc_cond', ['x' => '<b>']));
    }

    public function testEscapeInSetRemainsAValueTransform(): void
    {
        self::tpl('esc_set', '{% set s = x |> e %}[{{ s |> raw }}]');

        $this->assertSame('[&lt;b&gt;]', self::render('esc_set', ['x' => '<b>']));
    }

    /**
     * Argument position is not output position, so a pipeline nested in a filter
     * argument must not inherit the marker: doing so would cancel the wrapper for
     * the whole tag and emit the outer value unescaped.
     */
    public function testEscapeInAFilterArgumentDoesNotCancelTheWrapper(): void
    {
        self::tpl('esc_arg', '{{ y |> default(x |> e) }}');

        // `y` is present, so the argument is never evaluated; the tag is escaped once.
        $this->assertSame('&lt;i&gt;', self::render('esc_arg', ['x' => '<b>', 'y' => '<i>']));
    }

    public function testEscapeInAFilterArgumentEvaluatesAsAValueTransform(): void
    {
        self::tpl('esc_arg2', '{{ y |> default(x |> e) |> raw }}');

        // Absent `y` -> the argument is used, and it compiled as a plain filter.
        $this->assertSame('&lt;b&gt;', self::render('esc_arg2', ['x' => '<b>']));
    }

    public function testEscapeWithAnArgumentIsRefused(): void
    {
        self::tpl('esc_arg_rejected', "{{ x |> escape('html') }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/takes no arguments/');
        self::render('esc_arg_rejected', ['x' => '<b>']);
    }

    // =========================================================================
    // Policy integration
    // =========================================================================

    public function testEscapeHtmlIsNotPartOfTheReachQuestion(): void
    {
        $policy = Policy::restricted()->denyRule('escapeHtml');

        $this->assertFalse($policy->escapeHtml());
        $this->assertTrue($policy->isSandboxed(), 'a format rule must not unlock PHP');
    }

    public function testEscapeHtmlChangesTheDigest(): void
    {
        $this->assertNotSame(
            Policy::restricted()->digest(),
            Policy::restricted()->denyRule('escapeHtml')->digest()
        );
    }

    public function testEscapeHtmlRoundTripsThroughTheArrayForm(): void
    {
        $policy = Policy::fromArray(['rules' => ['escapeHtml' => false]]);

        $this->assertFalse($policy->escapeHtml());
        $this->assertFalse($policy->toArray()['rules']['escapeHtml']);
    }

    /**
     * A policy array written before these rules existed must keep the safe
     * defaults. `fromArray()` starts from `restricted()`, so an omitted rule keeps
     * its preset value rather than defaulting to the array's zero value — the
     * difference between an old config still escaping and silently emitting raw
     * HTML.
     */
    public function testAnOlderPolicyArrayKeepsTheSafeDefaultsForNewRules(): void
    {
        $policy = Policy::fromArray(['rules' => ['methodCalls' => true]]);

        $this->assertTrue($policy->escapeHtml());
        $this->assertTrue($policy->strictVariables());
        $this->assertTrue($policy->strictTypes());
    }

    public function testEscapeIsIdempotentAcrossRepeatedMarkers(): void
    {
        self::tpl('esc_twice', '{{ x |> escape |> escape }}');

        $this->assertSame('&lt;b&gt;', self::render('esc_twice', ['x' => '<b>']));
    }

    /**
     * The first marker in a chain decides the mode, so `raw` followed by `escape`
     * stays raw rather than the two silently overriding each other by position.
     */
    public function testTheFirstMarkerInAChainDecidesTheMode(): void
    {
        self::tpl('esc_raw_then_e', '{{ x |> raw |> escape }}');

        $this->assertSame('<b>', self::render('esc_raw_then_e', ['x' => '<b>']));
    }

    public function testEscapeThenRawAlsoKeepsTheFirstMarker(): void
    {
        self::tpl('esc_e_then_raw', '{{ x |> escape |> raw }}');

        // The first marker escaped and cancelled the wrapper; `raw` then has no
        // wrapper left to suppress, so the escaped value stands.
        $this->assertSame('&lt;b&gt;', self::render('esc_e_then_raw', ['x' => '<b>']));
    }

    public function testIncludeMarkupStaysUnescapedUnderTheDefaultPolicy(): void
    {
        self::tpl('esc_inc_child', '<b>{{ name }}</b>');
        self::tpl('esc_inc_parent', '{{ include("esc_inc_child", { name: "Ada" }) }}');

        $this->assertSame('<b>Ada</b>', self::render('esc_inc_parent'));
    }
}
