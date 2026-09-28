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
}
