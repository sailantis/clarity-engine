<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

class ControlFlowTest extends BaseTestCase
{
    public function testIfElseBasic(): void
    {
        self::tpl('if_basic', '{% if flag %}yes{% else %}no{% endif %}');
        $this->assertSame('yes', self::render('if_basic', ['flag' => true]));
        $this->assertSame('no', self::render('if_basic', ['flag' => false]));
    }

    public function testIfElseIf(): void
    {
        self::tpl('if_elseif', '{% if a > 5 %}gt{% elseif a > 2 %}mid{% else %}low{% endif %}');
        $this->assertSame('gt', self::render('if_elseif', ['a' => 6]));
        $this->assertSame('mid', self::render('if_elseif', ['a' => 4]));
        $this->assertSame('low', self::render('if_elseif', ['a' => 1]));
    }

    public function testIfGroupedPipelineComparison(): void
    {
        self::tpl('if_grouped_pipeline', '{% if (devices |> length) > 0 %}yes{% else %}no{% endif %}');
        $this->assertSame('yes', self::render('if_grouped_pipeline', ['devices' => ['a']]));
        $this->assertSame('no', self::render('if_grouped_pipeline', ['devices' => []]));
    }

    public function testIfUngroupedPipelineComparison(): void
    {
        self::tpl('if_ungrouped_gt', '{% if items |> length > 1 %}many{% else %}few{% endif %}');
        $this->assertSame('many', self::render('if_ungrouped_gt', ['items' => [1, 2]]));
        $this->assertSame('few', self::render('if_ungrouped_gt', ['items' => [1]]));

        self::tpl('if_ungrouped_eq', '{% if items |> length == 1 %}one{% else %}other{% endif %}');
        $this->assertSame('one', self::render('if_ungrouped_eq', ['items' => ['x']]));
        $this->assertSame('other', self::render('if_ungrouped_eq', ['items' => ['x', 'y']]));

        self::tpl('if_ungrouped_eq0', '{% if items |> length == 0 %}empty{% else %}full{% endif %}');
        $this->assertSame('empty', self::render('if_ungrouped_eq0', ['items' => []]));
        $this->assertSame('full', self::render('if_ungrouped_eq0', ['items' => [1]]));
    }

    public function testForLoopSimple(): void
    {
        self::tpl('for_simple', '{% for i in items %}{{ i }}-{% endfor %}');
        $this->assertSame('1-2-3-', self::render('for_simple', ['items' => [1, 2, 3]]));
    }

    public function testForLoopWithIndex(): void
    {
        self::tpl('for_index', '{% for idx, item in items %}{{ idx }}:{{ item }},{% endfor %}');
        $this->assertSame('0:10,1:20,', self::render('for_index', ['items' => [10, 20]]));
    }

    /**
     * The two-variable form is (key, value) — Twig order. Reversing it must fail
     * loudly rather than silently swapping the bindings.
     */
    public function testForLoopTwoVariableOrderIsKeyThenValue(): void
    {
        self::tpl('for_order', '{% for k, v in map %}{{ k }}={{ v }},{% endfor %}');
        $this->assertSame('x=1,y=2,', self::render('for_order', ['map' => ['x' => 1, 'y' => 2]]));
    }

    public function testForLoopTwoVariableOrderOnIndexedArray(): void
    {
        self::tpl('for_order_idx', '{% for k, v in items %}{{ k }}:{{ v }},{% endfor %}');
        $this->assertSame('0:a,1:b,', self::render('for_order_idx', ['items' => ['a', 'b']]));
    }

    public function testForLoopVariableAsArrayIndex(): void
    {
        // Loop variable used as an array index must resolve to the local PHP variable,
        // not to $__c_va['name']. This was a bug where platformLabels[platform] inside
        // a for-loop compiled to $__c_va['platformLabels'][$__c_va['platform']] instead of
        // $__c_va['platformLabels'][$platform].
        self::tpl(
            'for_array_index',
            '{% for key in keys %}{{ labels[key] }}-{% endfor %}'
        );
        $this->assertSame('Alpha-Beta-', self::render('for_array_index', [
            'keys'   => ['a', 'b'],
            'labels' => ['a' => 'Alpha', 'b' => 'Beta'],
        ]));
    }

    public function testForLoopVariableAsArrayIndexWithNullCoalesce(): void
    {
        // Loop variable in array access with null coalescing
        self::tpl(
            'for_array_index_coalesce',
            '{% for key in keys %}{{ labels[key] ?? "N/A" }},{% endfor %}'
        );
        $this->assertSame('Alpha,N/A,', self::render('for_array_index_coalesce', [
            'keys'   => ['a', 'missing'],
            'labels' => ['a' => 'Alpha'],
        ]));
    }

    public function testForLoopElse(): void
    {
        // For-else is implemented via an if-check surrounding the loop
        self::tpl('for_else', '{% if items %}{% for i in items %}x{% endfor %}{% else %}empty{% endif %}');
        $this->assertSame('x', self::render('for_else', ['items' => [1]]));
        $this->assertSame('empty', self::render('for_else', ['items' => []]));
    }

    public function testRangeLoop(): void
    {
        self::tpl('range', '{% for i in 1..3 %}{{ i }}{% endfor %}');
        $this->assertSame('123', self::render('range'));
    }

    public function testSetDirective(): void
    {
        self::tpl('set_simple', '{% set x = 5 %}{{ x }}');
        $this->assertSame('5', self::render('set_simple'));
    }

    public function testIncludePartial(): void
    {
        self::tpl('_part', 'part:{{ val }}');
        self::tpl('include_test', '<main>{% include "_part" %}</main>');
        $this->assertSame('<main>part:42</main>', self::render('include_test', ['val' => 42]));
    }

    public function testStaticIncludeCanRegisterMacrosForLaterUse(): void
    {
        self::tpl(
            'macro_library',
            '{% macro badge(text) %}<span class="badge">{{ text }}</span>{% endmacro %}'
        );
        self::tpl(
            'include_macro_library',
            '{% include "macro_library" %}{% call badge(label) %}'
        );

        $this->assertSame(
            '<span class="badge">Ready</span>',
            self::render('include_macro_library', ['label' => 'Ready'])
        );
    }

    public function testExtendsLayout(): void
    {
        self::tpl('layout', 'header-{% block content %}{% endblock %}-footer');
        self::tpl('page', '{% extends "layout" %}{% block content %}content{% endblock %}');
        $this->assertSame('header-content-footer', self::render('page'));
    }

    public function testExtendsCarriesLeadingSetIntoParentBlock(): void
    {
        self::tpl(
            'layout_with_title',
            '<title>{% block title %}{{ sectionTitle }}{% endblock %}</title><main>{% block content %}{% endblock %}</main>'
        );
        self::tpl(
            'page_with_title',
            '{% extends "layout_with_title" %}' .
                "\n{% set sectionTitle = \"The PHP IDE Extension\" %}" .
                "\n{% block content %}body{% endblock %}"
        );

        $this->assertSame(
            '<title>The PHP IDE Extension</title><main>body</main>',
            self::render('page_with_title')
        );
    }

    public function testNestedExtendsApplyLeadingSetAfterParentPreamble(): void
    {
        self::tpl(
            'base_meta',
            '{% set sectionTitle = "Base" %}<title>{% block title %}{{ sectionTitle }}{% endblock %}</title>{% block body %}{% endblock %}'
        );
        self::tpl(
            'section_meta',
            '{% extends "base_meta" %}' .
                '{% set sectionTitle = "Section" %}' .
                '{% block body %}[{% block page %}section-body{% endblock %}]{% endblock %}'
        );
        self::tpl(
            'page_meta_nested',
            '{% extends "section_meta" %}' .
                '{% set sectionTitle = "Child" %}' .
                '{% block page %}content{% endblock %}'
        );

        $this->assertSame('<title>Child</title>[content]', self::render('page_meta_nested'));
    }

    public function testExtendsStillIgnoresRenderedChildContentOutsideBlocks(): void
    {
        self::tpl('layout_ignore_text', '[{% block content %}{% endblock %}]');
        self::tpl(
            'page_ignore_text',
            '{% extends "layout_ignore_text" %}' .
                '{% set sectionTitle = "Ignored" %}' .
                '<p>IGNORED</p>' .
                '{% block content %}body{% endblock %}'
        );

        $this->assertSame('[body]', self::render('page_ignore_text'));
    }

    public function testNestedBlocksOverride(): void
    {
        self::tpl('axc_base', 'A{% block main %}B{% endblock %}C');
        self::tpl('axc_child', '{% extends "axc_base" %}{% block main %}X{% endblock %}');
        $this->assertSame('AXC', self::render('axc_child'));
    }

    public function testChildBlockCanAppendParentContent(): void
    {
        self::tpl('layout_parent_append', '[{% block title %}Base{% endblock %}]');
        self::tpl(
            'page_parent_append',
            '{% extends "layout_parent_append" %}{% block title %}{% parent %} / Child{% endblock %}'
        );

        $this->assertSame('[Base / Child]', self::render('page_parent_append'));
    }

    public function testChildBlockCanWrapParentContentMultipleTimes(): void
    {
        self::tpl('layout_parent_wrap', '[{% block body %}core{% endblock %}]');
        self::tpl(
            'page_parent_wrap',
            '{% extends "layout_parent_wrap" %}{% block body %}<before>{% parent %}|{% parent %}</before>{% endblock %}'
        );

        $this->assertSame('[<before>core|core</before>]', self::render('page_parent_wrap'));
    }

    public function testParentPlaceholderUsesImmediateParentBlockContent(): void
    {
        self::tpl('base_parent_chain', '{% block body %}{% endblock %}');
        self::tpl(
            'section_parent_chain',
            '{% extends "base_parent_chain" %}{% block body %}[{% block page %}Section{% endblock %}]{% endblock %}'
        );
        self::tpl(
            'page_parent_chain',
            '{% extends "section_parent_chain" %}{% block page %}Page[{% parent %}]{% endblock %}'
        );

        $this->assertSame('[Page[Section]]', self::render('page_parent_chain'));
    }

    public function testParentPlaceholderOutsideOverrideThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/only valid inside an overriding child block/i');
        self::tpl('invalid_parent_placeholder', '{% block title %}{% parent %}{% endblock %}');
        self::render('invalid_parent_placeholder');
    }

    public function testBareParentPlaceholderAppendsParentContent(): void
    {
        self::tpl('layout_bare_parent', '[{% block title %}Base{% endblock %}]');
        self::tpl(
            'page_bare_parent',
            '{% extends "layout_bare_parent" %}{% block title %}{% parent %} / Child{% endblock %}'
        );

        $this->assertSame('[Base / Child]', self::render('page_bare_parent'));
    }

    public function testBareParentPlaceholderSupportsMultipleUses(): void
    {
        self::tpl('layout_bare_wrap', '[{% block body %}core{% endblock %}]');
        self::tpl(
            'page_bare_wrap',
            '{% extends "layout_bare_wrap" %}{% block body %}<b>{% parent %}|{% parent %}</b>{% endblock %}'
        );

        $this->assertSame('[<b>core|core</b>]', self::render('page_bare_wrap'));
    }

    public function testBareParentPlaceholderOutsideOverrideThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/only valid inside an overriding child block/i');
        self::tpl('invalid_bare_parent_placeholder', '{% block title %}{% parent %}{% endblock %}');
        self::render('invalid_bare_parent_placeholder');
    }

    public function testThreeLevelExtendsChildOverridesBlockDefinedInMid(): void
    {
        self::tpl('three_root', '<main>{% block content %}{% endblock %}</main>');
        self::tpl(
            'three_mid',
            '{% extends "three_root" %}{% block content %}MID-DEFAULT{% endblock %}'
        );
        self::tpl(
            'three_leaf',
            '{% extends "three_mid" %}{% block content %}LEAF-CONTENT{% endblock %}'
        );

        $this->assertSame('<main>LEAF-CONTENT</main>', self::render('three_leaf'));
    }

    public function testThreeLevelExtendsChildOverridesNestedBlock(): void
    {
        self::tpl('nested_root', '<main>{% block content %}{% endblock %}</main>');
        self::tpl(
            'nested_mid',
            '{% extends "nested_root" %}' .
                '{% block content %}<section>{% block body %}default-body{% endblock %}</section>{% endblock %}'
        );
        self::tpl(
            'nested_leaf',
            '{% extends "nested_mid" %}{% block body %}LEAF-BODY{% endblock %}'
        );

        $this->assertSame(
            '<main><section>LEAF-BODY</section></main>',
            self::render('nested_leaf')
        );
    }

    public function testThreeLevelExtendsLeafOverridesEmptyMidBlock(): void
    {
        self::tpl('empty_mid_root', '<main>{% block content %}{% endblock %}</main>');
        self::tpl(
            'empty_mid_mid',
            '{% extends "empty_mid_root" %}{% set pageClass = "legal" %}' .
                '{% block content %}{% endblock %}'
        );
        self::tpl(
            'empty_mid_leaf',
            '{% extends "empty_mid_mid" %}{% set sectionTitle = "Contact" %}' .
                '{% block content %}<h2>Contact us</h2>{% endblock %}'
        );

        $this->assertSame(
            '<main><h2>Contact us</h2></main>',
            self::render('empty_mid_leaf')
        );
    }

    public function testInvalidForLoopThrows(): void
    {
        $this->expectException(ClarityException::class);
        self::tpl('bad_for', "{{ for(i in null) }}x{{ endfor }}");
        self::render('bad_for');
    }

    // =========================================================================
    // If / elseif / else (extended)
    // =========================================================================

    public function testIfTrue(): void
    {
        self::tpl('if_true', '{% if show %}yes{% endif %}');
        $this->assertSame('yes', self::render('if_true', ['show' => true]));
    }

    public function testIfFalse(): void
    {
        self::tpl('if_false', '{% if show %}yes{% endif %}');
        $this->assertSame('', self::render('if_false', ['show' => false]));
    }

    public function testIfElse(): void
    {
        self::tpl('if_else', '{% if flag %}A{% else %}B{% endif %}');
        $this->assertSame('A', self::render('if_else', ['flag' => true]));
        $this->assertSame('B', self::render('if_else', ['flag' => false]));
    }

    public function testElseif(): void
    {
        $tpl = '{% if x == 1 %}one{% elseif x == 2 %}two{% else %}other{% endif %}';
        self::tpl('elseif', $tpl);
        $this->assertSame('one', self::render('elseif', ['x' => 1]));
        $this->assertSame('two', self::render('elseif', ['x' => 2]));
        $this->assertSame('other', self::render('elseif', ['x' => 9]));
    }

    public function testLogicalOperatorsAndOr(): void
    {
        self::tpl('logic', '{% if a and b %}yes{% else %}no{% endif %}');
        $this->assertSame('yes', self::render('logic', ['a' => true, 'b' => true]));
        $this->assertSame('no', self::render('logic', ['a' => true, 'b' => false]));
    }

    public function testLogicalNot(): void
    {
        self::tpl('not', '{% if not flag %}off{% else %}on{% endif %}');
        $this->assertSame('off', self::render('not', ['flag' => false]));
    }

    public function testBitwiseOr(): void
    {
        self::tpl('bor', '{{ a bor b }}');
        $this->assertSame('7', self::render('bor', ['a' => 5, 'b' => 3]));
    }

    public function testBitwiseAnd(): void
    {
        self::tpl('band', '{{ a band b }}');
        $this->assertSame('1', self::render('band', ['a' => 5, 'b' => 3]));
    }

    public function testBitwiseXor(): void
    {
        self::tpl('bxor', '{{ a bxor b }}');
        $this->assertSame('6', self::render('bxor', ['a' => 5, 'b' => 3]));
    }

    public function testBitwiseNot(): void
    {
        self::tpl('bnot', '{{ bnot a }}');
        $this->assertSame('-6', self::render('bnot', ['a' => 5]));
    }

    public function testBitwiseShiftLeft(): void
    {
        self::tpl('blsh', '{{ a blsh b }}');
        $this->assertSame('20', self::render('blsh', ['a' => 5, 'b' => 2]));
    }

    public function testBitwiseShiftRight(): void
    {
        self::tpl('brsh', '{{ a brsh b }}');
        $this->assertSame('5', self::render('brsh', ['a' => 20, 'b' => 2]));
    }

    public function testBitwiseShiftBindsAgainstOtherBitwiseKeywords(): void
    {
        // The shift keywords are operand boundaries too, so `blsh` must not be
        // swallowed by the adjacent `band`: this is (flags << 2) & 255.
        self::tpl('blsh_band', '{{ flags blsh 2 band 255 }}');
        $this->assertSame('224', self::render('blsh_band', ['flags' => 0x12345678]));
    }

    // =========================================================================
    // For loops (extended)
    // =========================================================================

    public function testForLoop(): void
    {
        self::tpl('for', '{% for item in list %}{{ item }},{% endfor %}');
        $this->assertSame('a,b,c,', self::render('for', ['list' => ['a', 'b', 'c']]));
    }

    public function testForLoopEmpty(): void
    {
        self::tpl('for_empty', '{% for item in list %}{{ item }}{% endfor %}none');
        $this->assertSame('none', self::render('for_empty', ['list' => []]));
    }

    public function testNestedForLoop(): void
    {
        $tpl = '{% for row in rows %}{% for cell in row %}{{ cell }}{% endfor %}|{% endfor %}';
        self::tpl('nested_for', $tpl);
        $result = self::render('nested_for', ['rows' => [['a', 'b'], ['c', 'd']]]);
        $this->assertSame('ab|cd|', $result);
    }

    public function testForLoopIndexWithStyle(): void
    {
        self::tpl('for_idx_with', '{% for idx, item in list %}{{ idx }}:{{ item }},{% endfor %}');
        $this->assertSame('0:a,1:b,2:c,', self::render('for_idx_with', ['list' => ['a', 'b', 'c']]));
    }

    public function testForLoopIndexWithStyleAssocArray(): void
    {
        self::tpl('for_idx_with_assoc', '{% for k, v in map %}{{ k }}={{ v }},{% endfor %}');
        $this->assertSame('x=1,y=2,', self::render('for_idx_with_assoc', ['map' => ['x' => 1, 'y' => 2]]));
    }

    public function testForLoopIndexNestedNoCollision(): void
    {
        $tpl = '{% for oi, outer in rows %}{% for ii, inner in outer %}{{ oi }}.{{ ii }}:{{ inner }},{% endfor %}{% endfor %}';
        self::tpl('for_nested_idx', $tpl);
        $result = self::render('for_nested_idx', ['rows' => [['a', 'b'], ['c']]]);
        $this->assertSame('0.0:a,0.1:b,1.0:c,', $result);
    }

    // =========================================================================
    // Range loops
    // =========================================================================

    public function testRangeExclusive(): void
    {
        self::tpl('range_excl', '{% for i in 1...5 %}{{ i }},{% endfor %}');
        $this->assertSame('1,2,3,4,', self::render('range_excl'));
    }

    public function testRangeInclusive(): void
    {
        self::tpl('range_incl', '{% for i in 1..5 %}{{ i }},{% endfor %}');
        $this->assertSame('1,2,3,4,5,', self::render('range_incl'));
    }

    public function testRangeWithStep(): void
    {
        self::tpl('range_step', '{% for i in 1...10 step 3 %}{{ i }},{% endfor %}');
        $this->assertSame('1,4,7,', self::render('range_step'));
    }

    public function testRangeInclusiveWithStep(): void
    {
        self::tpl('range_incl_step', '{% for i in 0..8 step 4 %}{{ i }},{% endfor %}');
        $this->assertSame('0,4,8,', self::render('range_incl_step'));
    }

    public function testRangeFromVariables(): void
    {
        self::tpl('range_vars', '{% for i in start..end %}{{ i }},{% endfor %}');
        $this->assertSame('3,4,5,', self::render('range_vars', ['start' => 3, 'end' => 5]));
    }

    public function testRangeStepFromVariable(): void
    {
        self::tpl('range_step_var', '{% for i in 0...10 step s %}{{ i }},{% endfor %}');
        $this->assertSame('0,5,', self::render('range_step_var', ['s' => 5]));
    }

    public function testRangeZeroBased(): void
    {
        self::tpl('range_zero', '{% for i in 0...3 %}{{ i }},{% endfor %}');
        $this->assertSame('0,1,2,', self::render('range_zero'));
    }

    public function testNestedRangeLoop(): void
    {
        $tpl = "{% for r in 1..2 %}\n{% for c in 1..2 %}{{ r }}{{ c }},{% endfor %}\n{% endfor %}";
        self::tpl('range_nested', $tpl);
        $this->assertSame('11,12,21,22,', self::render('range_nested'));
    }

    public function testMixedRangeAndForeach(): void
    {
        $tpl = '{% for item in list %}{% for i in 1..2 %}{{ item }}{{ i }},{% endfor %}{% endfor %}';
        self::tpl('range_mixed', $tpl);
        $this->assertSame('a1,a2,b1,b2,', self::render('range_mixed', ['list' => ['a', 'b']]));
    }

    public function testRangeZeroStepThrows(): void
    {
        self::tpl('range_zero_step', '{% for i in 1..5 step s %}{{ i }}{% endfor %}');
        TestEnvironment::engine()->setDebugMode(true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/step cannot be zero/');
            self::render('range_zero_step', ['s' => 0]);
        } finally {
            TestEnvironment::engine()->setDebugMode(false);
        }
    }

    public function testRangeWrongDirectionThrows(): void
    {
        self::tpl('range_bad_dir', '{% for i in 10..1 %}{{ i }}{% endfor %}');
        TestEnvironment::engine()->setDebugMode(true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/infinite loop/');
            self::render('range_bad_dir');
        } finally {
            TestEnvironment::engine()->setDebugMode(false);
        }
    }

    public function testRangeNegativeStepWrongDirectionThrows(): void
    {
        self::tpl('range_neg_bad', '{% for i in 1...10 step s %}{{ i }}{% endfor %}');
        TestEnvironment::engine()->setDebugMode(true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/infinite loop/');
            self::render('range_neg_bad', ['s' => -1]);
        } finally {
            TestEnvironment::engine()->setDebugMode(false);
        }
    }

    // =========================================================================
    // Emitted loop shape
    //
    // The bounds are inlineable when they are numeric literals, which is the
    // common case and removes three temporary-variable reads per iteration.
    // These tests read the COMPILED body, because the rendered output is
    // identical either way and so cannot pin the optimisation. A refactor that
    // silently reintroduces the hoisted temps would otherwise go unnoticed and
    // quietly cost ~2.4% on every loop.
    // =========================================================================

    /** Return the compiled render body for a template name. */
    private function compiledBody(string $view): string
    {
        $cache = new \ReflectionProperty(TestEnvironment::engine(), 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue(TestEnvironment::engine())->getLoadedClassName($view);
        $this->assertIsString($className, 'the template must be compiled and loaded');

        $file  = (new \ReflectionClass($className))->getFileName();
        $src   = (string) file_get_contents($file);
        $start = strpos($src, 'try {');
        $end   = strpos($src, 'return (string) ob_get_clean();');
        $this->assertNotFalse($start, 'the compiled class must contain the render body');
        $this->assertNotFalse($end, 'the compiled class must contain the render body');

        return substr($src, $start, $end - $start);
    }

    public function testLiteralRangeLoopInlinesItsBounds(): void
    {
        self::tpl('inline_range_lit', '{% for j in 0...10 %}{{ j }}{% endfor %}');
        $this->assertSame('0123456789', self::render('inline_range_lit'));

        $body = $this->compiledBody('inline_range_lit');

        $this->assertStringContainsString(
            'for ($j = 0; $j < 10; $j += 1):',
            $body,
            'a fully-literal range must compile to a plain loop with the bounds inlined'
        );
        $this->assertStringNotContainsString(
            '$__rb',
            $body,
            'no range bound temporary may be emitted when every bound is a literal'
        );
        $this->assertStringNotContainsString('$__re', $body);
        $this->assertStringNotContainsString('$__rs', $body);
    }

    public function testInclusiveLiteralRangeLoopInlinesItsBounds(): void
    {
        self::tpl('inline_range_incl', '{% for j in 0..9 %}{{ j }}{% endfor %}');
        $this->assertSame('0123456789', self::render('inline_range_incl'));

        $body = $this->compiledBody('inline_range_incl');
        $this->assertStringContainsString('for ($j = 0; $j <= 9; $j += 1):', $body);
        $this->assertStringNotContainsString('$__rb', $body);
    }

    public function testLiteralRangeLoopWithLiteralStepInlinesItsBounds(): void
    {
        self::tpl('inline_range_step', '{% for j in 1...10 step 3 %}{{ j }}{% endfor %}');
        $this->assertSame('147', self::render('inline_range_step'));

        $body = $this->compiledBody('inline_range_step');
        $this->assertStringContainsString('for ($j = 1; $j < 10; $j += 3):', $body);
        $this->assertStringNotContainsString('$__rb', $body);
        $this->assertStringNotContainsString('$__rs', $body);
    }

    /**
     * Inlining must not change what an empty or single-iteration range does.
     */
    public function testInlinedRangeBoundaries(): void
    {
        self::tpl('inline_range_empty', '{% for j in 0...0 %}[{{ j }}]{% endfor %}');
        $this->assertSame('', self::render('inline_range_empty'));

        self::tpl('inline_range_one', '{% for j in 0...1 %}[{{ j }}]{% endfor %}');
        $this->assertSame('[0]', self::render('inline_range_one'));

        self::tpl('inline_range_incl_one', '{% for j in 3..3 %}[{{ j }}]{% endfor %}');
        $this->assertSame('[3]', self::render('inline_range_incl_one'));
    }

    /**
     * A negative literal is still a literal: it arrives as `-1`, and inlining it
     * must not be mistaken for an operator expression.
     */
    public function testNegativeLiteralBoundsAreInlined(): void
    {
        self::tpl('inline_range_neg', '{% for j in -2..0 %}[{{ j }}]{% endfor %}');
        $this->assertSame('[-2][-1][0]', self::render('inline_range_neg'));

        $body = $this->compiledBody('inline_range_neg');
        $this->assertStringContainsString('for ($j = -2; $j <= 0; $j += 1):', $body);
        $this->assertStringNotContainsString('$__rb', $body);
    }

    // =========================================================================
    // Set directive (extended)
    // =========================================================================

    public function testSetFromVariable(): void
    {
        self::tpl('set_var', '{% set x = count %}double={{ x }}');
        $this->assertSame('double=5', self::render('set_var', ['count' => 5]));
    }

    // =========================================================================
    // Macros
    // =========================================================================

    public function testMacroBasic(): void
    {
        self::tpl(
            'macro_basic',
            '{% macro greet(name) %}Hello {{ name }}!{% endmacro %}' .
                '{% call greet(user) %}'
        );
        $this->assertSame('Hello World!', self::render('macro_basic', ['user' => 'World']));
    }

    public function testMacroMultipleParams(): void
    {
        self::tpl(
            'macro_multi',
            '{% macro field(label, value) %}<label>{{ label }}: {{ value }}</label>{% endmacro %}' .
                '{% call field(name, email) %}'
        );
        $result = self::render('macro_multi', ['name' => 'Name', 'email' => 'test@example.com']);
        $this->assertSame('<label>Name: test@example.com</label>', $result);
    }

    public function testMacroCalledMultipleTimes(): void
    {
        self::tpl(
            'macro_repeat',
            '{% macro item(label) %}<li>{{ label }}</li>{% endmacro %}' .
                '{% call item(a) %}{% call item(b) %}'
        );
        $result = self::render('macro_repeat', ['a' => 'First', 'b' => 'Second']);
        $this->assertSame('<li>First</li><li>Second</li>', $result);
    }

    public function testMacroParamIsolatedFromOuterScope(): void
    {
        // The macro param 'x' must not leak after the macro ends.
        // After the macro call, {{ x }} should resolve from $__c_va['x'].
        self::tpl(
            'macro_isolate',
            '{% macro show(x) %}[{{ x }}]{% endmacro %}' .
                '{% call show(a) %}{{ x }}'
        );
        $result = self::render('macro_isolate', ['a' => 'macro', 'x' => 'outer']);
        $this->assertSame('[macro]outer', $result);
    }

    public function testMacroWithLoopVar(): void
    {
        self::tpl(
            'macro_loop',
            '{% macro row(item) %}<tr>{{ item }}</tr>{% endmacro %}' .
                '{% for row in rows %}{% call row(row) %}{% endfor %}'
        );
        $result = self::render('macro_loop', ['rows' => ['a', 'b', 'c']]);
        $this->assertSame('<tr>a</tr><tr>b</tr><tr>c</tr>', $result);
    }

    public function testMacroUndefinedThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/undefined macro/i');
        self::tpl('macro_undef', '{% call missing(x) %}');
        self::render('macro_undef', ['x' => 'v']);
    }

    public function testMacroArgCountMismatchThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/expects 2 argument/i');
        self::tpl(
            'macro_arity',
            '{% macro field(label, value) %}{{ label }}{{ value }}{% endmacro %}' .
                '{% call field(only_one) %}'
        );
        self::render('macro_arity', ['only_one' => 'x']);
    }

    public function testMacroCallsAnotherMacro(): void
    {
        self::tpl(
            'macro_chain',
            '{% macro a(x) %}<div>{% call b(x) %}</div>{% endmacro %}' .
                '{% macro b(y) %}<span>{{ y }}</span>{% endmacro %}' .
                '{% call a(val) %}'
        );
        $this->assertSame('<div><span>hello</span></div>', self::render('macro_chain', ['val' => 'hello']));
    }

    public function testMacroDirectRecursionThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cycle/i');
        self::tpl(
            'macro_self_recurse',
            '{% macro loop(x) %}{% call loop(x) %}{% endmacro %}' .
                '{% call loop(val) %}'
        );
        self::render('macro_self_recurse', ['val' => 1]);
    }

    public function testMacroIndirectRecursionThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cycle/i');
        self::tpl(
            'macro_indirect_recurse',
            '{% macro a(x) %}{% call b(x) %}{% endmacro %}' .
                '{% macro b(y) %}{% call a(y) %}{% endmacro %}' .
                '{% call a(val) %}'
        );
        self::render('macro_indirect_recurse', ['val' => 1]);
    }

    public function testMacroDefinitionIsStrippedAndTheCallTagLeavesNoWhitespace(): void
    {
        // The definition is removed from the source, so it must not emit the
        // whitespace around itself -- which is what keeps a library of
        // definitions from opening a page with a run of blank lines.
        self::tpl(
            'macro_strip',
            "a\n{% macro card(t) %}<i>{{ t }}</i>{% endmacro %}\n{% call card('x') %}\nb"
        );

        $this->assertSame("a\n<i>x</i>b", self::render('macro_strip'));
    }

    public function testMacroDefinitionAcceptsWhitespaceControl(): void
    {
        self::tpl(
            'macro_trim',
            "x {%- macro tag(v) -%}<b>{{ v }}</b>{%- endmacro -%} {% call tag('t') %}"
        );

        $this->assertSame('x<b>t</b>', self::render('macro_trim'));
    }

    public function testMacroDefinitionAndCallAreCaseSensitive(): void
    {
        // Keyword dispatch lowercases, but a MACRO NAME is an identifier: `Card`
        // and `card` are two different macros, so a call must not fold.
        self::tpl(
            'macro_case',
            '{% macro Card(t) %}<big>{{ t }}</big>{% endmacro %}{% call Card("v") %}'
        );

        $this->assertSame('<big>v</big>', self::render('macro_case'));
    }

    public function testBareMacroNameWithoutCallSuggestsTheCallTag(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/did you mean \{% call card\(/i');
        self::tpl(
            'macro_bare_call',
            '{% macro card(t) %}{{ t }}{% endmacro %}{% card("v") %}'
        );
        self::render('macro_bare_call');
    }

    public function testMacroNameThatIsADirectiveKeywordIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/is a directive keyword/i');
        self::tpl('macro_reserved_name', '{% macro if(x) %}{{ x }}{% endmacro %}{% call if(1) %}');
        self::render('macro_reserved_name');
    }

    public function testMacroParameterThatPhpCannotBindIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/macro parameter name 'this'/i");
        self::tpl('macro_reserved_param', '{% macro m(this) %}{{ this }}{% endmacro %}{% call m(1) %}');
        self::render('macro_reserved_param');
    }

    public function testUnclosedMacroDefinitionIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/unclosed '\{% macro %\}'/i");
        self::tpl('macro_unclosed', '{% macro m(x) %}{{ x }}');
        self::render('macro_unclosed');
    }

    public function testMalformedCallTagIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/invalid macro call syntax/i');
        self::tpl('macro_malformed_call', '{% macro m() %}x{% endmacro %}{% call m( %}');
        self::render('macro_malformed_call');
    }


    public function testMacroWithNoParameters(): void
    {
        self::tpl(
            'macro_no_params',
            '{% macro spacer() %}<hr>{% endmacro %}|{% call spacer() %}|'
        );

        $this->assertSame('|<hr>|', self::render('macro_no_params'));
    }

    public function testMacroCalledInsideAnIfBranch(): void
    {
        self::tpl(
            'macro_in_if',
            '{% macro chip(t) %}<span>{{ t }}</span>{% endmacro %}' .
                '{% if show %}{% call chip(label) %}{% endif %}'
        );

        $this->assertSame('<span>yes</span>', self::render('macro_in_if', ['show' => true, 'label' => 'yes']));
        $this->assertSame('', self::render('macro_in_if', ['show' => false, 'label' => 'yes']));
    }
    public function testNestedMacroDefinitionIsRejected(): void
    {
        // A definition inside another definition's body would read as a private
        // helper, but macro names are not scoped: it would be registered and
        // callable exactly like a top-level one.
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/Nested macro definitions are not supported: define 'inner'/i");
        self::tpl(
            'macro_nested',
            '{% macro outer() %}{% macro inner() %}<i>{% endmacro %}{% call outer() %}'
        );
        self::render('macro_nested');
    }

    public function testUnclosedMacroTagIsReportedWhenNothingClosesIt(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/unclosed '\{% macro %\}'/i");
        self::tpl('macro_unclosed_only', '{% macro a(x) %}{{ x }}');
        self::render('macro_unclosed_only');
    }    public function testEmptyMacroTagIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/empty .*macro.*it needs a name/i');
        self::tpl('macro_empty', '{% macro %}x{% endmacro %}');
        self::render('macro_empty');
    }

    public function testStrayEndmacroIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/without a matching/i');
        self::tpl('macro_stray_end', 'hi{% endmacro %}');
        self::render('macro_stray_end');
    }
    public function testAtPrefixedMacroDefinitionIsRejectedWithARewriteHint(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/spelled '\{% macro name\(params\) %\}'/i");
        self::tpl('legacy_macro_def', '{% macro @greet(name) %}Hi {{ name }}{% endmacro %}');
        self::render('legacy_macro_def');
    }

    public function testAtPrefixedMacroCallIsAnUnknownDirective(): void
    {
        // The `@`-call scanner is gone: a macro is invoked with `{% call %}`, so
        // there is no longer a dedicated message for this spelling.
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unknown directive/i');
        self::tpl('legacy_macro_call', '{% @greet("x") %}');
        self::render('legacy_macro_call');
    }
}
