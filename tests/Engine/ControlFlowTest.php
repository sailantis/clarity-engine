<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;

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

    public function testForLoopSimple(): void
    {
        self::tpl('for_simple', '{% for i in items %}{{ i }}-{% endfor %}');
        $this->assertSame('1-2-3-', self::render('for_simple', ['items' => [1, 2, 3]]));
    }

    public function testForLoopWithIndex(): void
    {
        self::tpl('for_index', '{% for item, idx in items %}{{ idx }}:{{ item }},{% endfor %}');
        $this->assertSame('0:10,1:20,', self::render('for_index', ['items' => [10, 20]]));
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

    public function testExtendsLayout(): void
    {
        self::tpl('layout', 'header-{% block content %}{% endblock %}-footer');
        self::tpl('page', '{% extends "layout" %}{% block content %}content{% endblock %}');
        $this->assertSame('header-content-footer', self::render('page'));
    }

    public function testNestedBlocksOverride(): void
    {
        self::tpl('axc_base', 'A{% block main %}B{% endblock %}C');
        self::tpl('axc_child', '{% extends "axc_base" %}{% block main %}X{% endblock %}');
        $this->assertSame('AXC', self::render('axc_child'));
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
        self::tpl('for_idx_with', '{% for item, idx in list %}{{ idx }}:{{ item }},{% endfor %}');
        $this->assertSame('0:a,1:b,2:c,', self::render('for_idx_with', ['list' => ['a', 'b', 'c']]));
    }

    public function testForLoopIndexWithStyleAssocArray(): void
    {
        self::tpl('for_idx_with_assoc', '{% for v, k in map %}{{ k }}={{ v }},{% endfor %}');
        $this->assertSame('x=1,y=2,', self::render('for_idx_with_assoc', ['map' => ['x' => 1, 'y' => 2]]));
    }

    public function testForLoopIndexNestedNoCollision(): void
    {
        $tpl = '{% for outer, oi in rows %}{% for inner, ii in outer %}{{ oi }}.{{ ii }}:{{ inner }},{% endfor %}{% endfor %}';
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
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/step cannot be zero/');
        self::render('range_zero_step', ['s' => 0]);
    }

    public function testRangeWrongDirectionThrows(): void
    {
        self::tpl('range_bad_dir', '{% for i in 10..1 %}{{ i }}{% endfor %}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/infinite loop/');
        self::render('range_bad_dir');
    }

    public function testRangeNegativeStepWrongDirectionThrows(): void
    {
        self::tpl('range_neg_bad', '{% for i in 1...10 step s %}{{ i }}{% endfor %}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/infinite loop/');
        self::render('range_neg_bad', ['s' => -1]);
    }

    // =========================================================================
    // Set directive (extended)
    // =========================================================================

    public function testSetFromVariable(): void
    {
        self::tpl('set_var', '{% set x = count %}double={{ x }}');
        $this->assertSame('double=5', self::render('set_var', ['count' => 5]));
    }
}
