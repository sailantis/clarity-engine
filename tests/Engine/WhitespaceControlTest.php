<?php
namespace Clarity\Tests\Engine;

use Clarity\Tests\BaseTestCase;

/**
 * Twig-style whitespace control: a `-` glued to a tag delimiter suppresses the
 * whitespace on that side of the tag.
 *
 *   {%- ... %}   trim the whitespace BEFORE the tag
 *   {% ... -%}   trim the whitespace AFTER the tag
 *   {{- x -}}    the same on an output tag
 *   {#- c -#}    and on a comment
 *
 * Only whitespace is removed (spaces, tabs, newlines); visible text is not.
 */
class WhitespaceControlTest extends BaseTestCase
{
    public function testNoControlKeepsWhitespace(): void
    {
        self::tpl('ws_none', "A\n  {% if true %}  B  {% endif %}\nC");
        $this->assertSame("A\n    B  C", self::render('ws_none'));
    }

    public function testTrimLeft(): void
    {
        self::tpl('ws_left', "A\n  {%- if true %}B{% endif %}\nC");
        $this->assertSame('ABC', self::render('ws_left'));
    }

    public function testTrimRight(): void
    {
        self::tpl('ws_right', "A\n  {% if true -%}  B{% endif %}\nC");
        $this->assertSame("A\n  BC", self::render('ws_right'));
    }

    public function testTrimBoth(): void
    {
        self::tpl('ws_both', "A\n  {%- if true -%}  B  {%- endif -%}\nC");
        $this->assertSame('ABC', self::render('ws_both'));
    }

    public function testOutputTagTrim(): void
    {
        self::tpl('ws_output', 'X  {{- name -}}  Y');
        $this->assertSame('XNY', self::render('ws_output', ['name' => 'N']));
    }

    public function testCommentTagTrim(): void
    {
        self::tpl('ws_comment', 'X  {#- c -#}  Y');
        $this->assertSame('XY', self::render('ws_comment'));
    }

    public function testTrimDoesNotConsumeAVisibleCharacter(): void
    {
        // The `-` only eats whitespace; the surrounding words survive.
        self::tpl('ws_visible', 'one {{- "" -}} two');
        $this->assertSame('onetwo', self::render('ws_visible'));
    }

    public function testTrimInsideLoop(): void
    {
        self::tpl('ws_loop', '{% for i in 1..3 %}  {{- i -}}  {% endfor %}');
        $this->assertSame('123', self::render('ws_loop'));
    }

    public function testAdjacentTagsDoNotOverConsume(): void
    {
        // The right-trim of the first tag and the left-trim of the second must
        // not swallow the assignment between them.
        self::tpl('ws_adjacent', "{% set a = 'x' -%}\n{%- if true %}{{ a }}{% endif %}");
        $this->assertSame('x', self::render('ws_adjacent'));
    }

    // =========================================================================
    // The gap between two tags on one line
    //
    // A run of whitespace-only text between two tags is a word gap when the
    // line already carries content, and layout only when it starts the line.
    // Dropping it (which the tokenizer used to do for every whitespace-only
    // run) glued values together: `{{ a }} {{ b }}` rendered as `AB`.
    // =========================================================================

    public function testSpaceBetweenOutputTagsIsKept(): void
    {
        self::tpl('ws_out_gap', '<div class="d-flex flex-column {{ a }} {{ b }}">');
        $this->assertSame(
            '<div class="d-flex flex-column one two">',
            self::render('ws_out_gap', ['a' => 'one', 'b' => 'two'])
        );
    }

    /** A collapsed gap keeps exactly one space however wide it was. */
    public function testWideGapBetweenOutputTagsCollapsesToOneSpace(): void
    {
        self::tpl('ws_out_gap_wide', '{{ a }}   {{ b }}');
        $this->assertSame('A B', self::render('ws_out_gap_wide', ['a' => 'A', 'b' => 'B']));
    }

    /**
     * A line break between two output tags is a word gap too. Rendering it as
     * nothing would glue words that the author wrote on separate lines - which
     * is how an inline element split over two lines behaves in HTML.
     */
    public function testLineBreakBetweenOutputTagsBecomesOneSpace(): void
    {
        self::tpl('ws_out_gap_nl', "{{ a }}\n{{ b }}");
        $this->assertSame('A B', self::render('ws_out_gap_nl', ['a' => 'A', 'b' => 'B']));

        self::tpl('ws_out_gap_indent', "{{ a }}\n    {{ b }}");
        $this->assertSame('A B', self::render('ws_out_gap_indent', ['a' => 'A', 'b' => 'B']));
    }

    /** Gluing the tags is still the way to get no gap at all. */
    public function testAdjacentOutputTagsWithoutGapStayGlued(): void
    {
        self::tpl('ws_out_glued', '{{ a }}{{ b }}');
        $this->assertSame('AB', self::render('ws_out_glued', ['a' => 'A', 'b' => 'B']));
    }

    /**
     * A gap that ends a line followed by a directive is layout: the directive
     * line disappears and the value after it starts the next line. This is what
     * keeps a directive-only line from leaving a blank line behind.
     */
    public function testGapAfterOutputBeforeBlockIsStillDropped(): void
    {
        self::tpl('ws_out_block_gap', "{{ a }}\n{% if true %}Z{% endif %}");
        $this->assertSame('AZ', self::render('ws_out_block_gap', ['a' => 'A']));
    }

    /**
     * A gap between two directives that sits at the start of a line is layout
     * too, so an `{% extends %}`/`{% block %}` preamble does not gain spaces.
     */
    public function testGapBetweenDirectivesAtLineStartIsDropped(): void
    {
        self::tpl('ws_blk_gap_start', "{% set a = 1 %} {{ a }}");
        $this->assertSame('1', self::render('ws_blk_gap_start'));
    }

    public function testTrimMarkersStillWinOverTheGapRule(): void
    {
        self::tpl('ws_out_gap_trim', '{{ a -}} {{- b }}');
        $this->assertSame('AB', self::render('ws_out_gap_trim', ['a' => 'A', 'b' => 'B']));
    }

    // =========================================================================
    // The same gap, with a directive or a comment as one of the neighbours
    //
    // The rule is about the position of the run, not about the tag types: any
    // whitespace-only run that follows content on the line is a word gap. The
    // output/output pair above is just the case where a line break is a gap too.
    // =========================================================================

    /** A value, then a conditional attribute: the gap between them survives. */
    public function testGapBetweenOutputAndConditionalBlock(): void
    {
        self::tpl('ws_out_blk_out', '<i class="{{ a }} {% if c %}{{ b }}{% endif %}">');
        $this->assertSame(
            '<i class="x y">',
            self::render('ws_out_blk_out', ['a' => 'x', 'b' => 'y', 'c' => true])
        );
    }

    /** A conditional class, then a value - the leading-space idiom. */
    public function testGapBetweenConditionalBlockAndOutput(): void
    {
        self::tpl('ws_blk_out', '<i class="{% if c %}sm{% endif %} {{ b }}">');
        $this->assertSame('<i class="sm y">', self::render('ws_blk_out', ['b' => 'y', 'c' => true]));
    }

    /**
     * Two conditional attributes next to each other. Dropping the gap here
     * produced invalid HTML - `<div class="x"id="y">` - not just a wrong string.
     */
    public function testGapBetweenTwoConditionalAttributes(): void
    {
        self::tpl('ws_blk_blk_attr', '<div {% if a %}class="x"{% endif %} {% if b %}id="y"{% endif %}>');
        $this->assertSame(
            '<div class="x" id="y">',
            self::render('ws_blk_blk_attr', ['a' => true, 'b' => true])
        );
    }

    /** A comment between two values separates them like any other tag. */
    public function testGapAroundACommentBetweenOutputs(): void
    {
        self::tpl('ws_cmt_between', '<i class="{{ a }} {# separator #} {{ b }}">');
        $this->assertSame('<i class="x y">', self::render('ws_cmt_between', ['a' => 'x', 'b' => 'y']));
    }

    /** A gap inside a loop body after the loop's own value is a gap per iteration. */
    public function testGapAfterAnOutputInsideALoop(): void
    {
        self::tpl('ws_loop_gap', '<i class="{% for v in vs %}{{ v }} {% endfor %}">');
        $this->assertSame('<i class="a b ">', self::render('ws_loop_gap', ['vs' => ['a', 'b']]));
    }
}
