<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Tokenizer;
use Clarity\Tests\BaseTestCase;

/**
 * Hostile-source corpus for the tag scanner in {@see Tokenizer::tokenize()}.
 *
 * The scanner replaced a flat lazy regex whose closer was a possessive `\}++`
 * hack. That hack made a closing delimiter inside a string literal impossible
 * (`{{ '}}' }}`), stole a literal brace after a tag (`{{ v }}}` -> content
 * `v }`), and had no notion of brace nesting. These tests pin every case the
 * scanner must get right, because every template in every dependent repo is
 * lexed by this method.
 *
 * Segments are asserted by (type, content) pairs; the count is always pinned so
 * a stray empty text segment cannot slip through unnoticed.
 */
class TagScannerTest extends BaseTestCase
{
    private const TEXT = Tokenizer::TEXT;
    private const OUTPUT = Tokenizer::OUTPUT;
    private const BLOCK = Tokenizer::BLOCK;
    private const COMMENT = Tokenizer::COMMENT;

    /**
     * @return array<int, array{int, string}>
     */
    private function segments(string $source): array
    {
        $out = [];
        foreach ((new Tokenizer())->tokenize($source) as $seg) {
            $out[] = [$seg[Tokenizer::KEY_TYPE], $seg[Tokenizer::KEY_CONTENT]];
        }
        return $out;
    }

    /**
     * @param array<int, array{int, string}> $expected
     */
    private function assertSegments(array $expected, string $source, string $why = ''): void
    {
        $this->assertSame($expected, $this->segments($source), $why);
    }

    // =========================================================================
    // Baseline
    // =========================================================================

    public function testSimpleOutputTag(): void
    {
        $this->assertSegments([
            [self::TEXT, 'Hello '],
            [self::OUTPUT, 'name'],
            [self::TEXT, '!'],
        ], 'Hello {{ name }}!');
    }

    public function testNoTagReturnsSingleTrimmedText(): void
    {
        $this->assertSegments([[self::TEXT, 'plain text']], "  plain text  ");
    }

    public function testEmptySourceReturnsEmptyText(): void
    {
        $this->assertSegments([[self::TEXT, '']], '');
    }

    public function testAdjacentTags(): void
    {
        $this->assertSegments([
            [self::OUTPUT, 'a'],
            [self::OUTPUT, 'b'],
        ], '{{ a }}{{ b }}');
    }

    // =========================================================================
    // The four cases the old regex got wrong
    // =========================================================================

    public function testCloserInsideStringLiteralIsSkipped(): void
    {
        $this->assertSegments(
            [[self::OUTPUT, "'}}'"]],
            "{{ '}}' }}",
            'a }} inside a string literal must not close the tag'
        );
    }

    public function testCloserInsideDoubleQuotedLiteralIsSkipped(): void
    {
        $this->assertSegments([[self::OUTPUT, '"a}}b"']], '{{ "a}}b" }}');
    }

    public function testCloserInsidePercentLiteralIsSkipped(): void
    {
        $this->assertSegments([[self::BLOCK, 'set x = "a%}b"']], '{% set x = "a%}b" %}');
    }

    public function testEscapedQuoteDoesNotEndStringLiteral(): void
    {
        $this->assertSegments([[self::OUTPUT, "'a\\'b}}'"]], "{{ 'a\\'b}}' }}");
    }

    public function testLiteralBraceAfterTagBecomesText(): void
    {
        $this->assertSegments(
            [[self::OUTPUT, 'v'], [self::TEXT, '}']],
            '{{ v }}}',
            'the third brace is literal text, not part of the tag'
        );
    }

    // =========================================================================
    // Brace nesting
    // =========================================================================

    public function testObjectLiteralInOutputTag(): void
    {
        $this->assertSegments([[self::OUTPUT, '{ a: 1 }']], '{{ { a: 1 } }}');
    }

    public function testObjectLiteralWithNoSpaceBeforeCloser(): void
    {
        $this->assertSegments([[self::OUTPUT, '{a:1}']], '{{ {a:1} }}');
    }

    public function testDynamicPropertyAccessNoSpaceBeforeCloser(): void
    {
        $this->assertSegments(
            [[self::OUTPUT, 'user{k}']],
            '{{ user{k}}}',
            'the closer must be balanced against the dynamic access brace'
        );
    }

    public function testDynamicPropertyAccessWithSpace(): void
    {
        $this->assertSegments([[self::OUTPUT, 'user{k}']], '{{ user{k} }}');
    }

    public function testNestedObjectLiteralBraces(): void
    {
        $this->assertSegments([[self::OUTPUT, '{ a: { b: 1 } }']], '{{ { a: { b: 1 } } }}');
    }

    public function testBlockTagWithObjectLiteral(): void
    {
        $this->assertSegments([[self::BLOCK, 'set x = { a: 1 }']], '{% set x = { a: 1 } %}');
    }

    public function testArrayLiteralIsNotBraceDepth(): void
    {
        // Square brackets do not affect brace depth; the tag closes normally.
        $this->assertSegments([[self::OUTPUT, 'items[0]']], '{{ items[0] }}');
    }

    // =========================================================================
    // Comment and whitespace-control tags
    // =========================================================================

    public function testCommentTagIgnoresBracesAndPercentSigns(): void
    {
        $this->assertSegments(
            [[self::COMMENT, 'comment with }} and %} inside']],
            '{# comment with }} and %} inside #}'
        );
    }

    public function testWhitespaceControlIsPreservedInContent(): void
    {
        $this->assertSegments([[self::BLOCK, '- if x -']], '{%- if x -%}');
    }

    public function testCommentTagContentIsPreserved(): void
    {
        $this->assertSegments([[self::COMMENT, 'hello']], '{# hello #}');
    }

    // =========================================================================
    // Text is not string-scanned
    // =========================================================================

    public function testApostropheInTextDoesNotStartAStringLiteral(): void
    {
        $this->assertSegments(
            [[self::TEXT, "it's "], [self::OUTPUT, 'x']],
            "it's {{ x }}",
            'only the inside of a tag is string-scanned'
        );
    }

    public function testSingleBraceInTextIsNotATag(): void
    {
        $this->assertSegments([[self::TEXT, 'function f() { return 1; }']], 'function f() { return 1; }');
    }

    public function testSpacedBracesInTextAreNotATag(): void
    {
        $this->assertSegments([[self::TEXT, '{ { not a tag } }']], '{ { not a tag } }');
    }

    // =========================================================================
    // Multi-line and line numbers
    // =========================================================================

    public function testMultilineTagContentIsTrimmed(): void
    {
        $segments = (new Tokenizer())->tokenize("{{\n  user.name\n}}");
        $this->assertCount(1, $segments);
        $this->assertSame(self::OUTPUT, $segments[0][Tokenizer::KEY_TYPE]);
        $this->assertSame('user.name', $segments[0][Tokenizer::KEY_CONTENT]);
    }

    public function testLineNumbersFollowMultilineTextAndTags(): void
    {
        $source   = "a\n{{ \n x \n }}\nb";
        $segments = (new Tokenizer())->tokenize($source);

        // TEXT segments are preserved verbatim (only tag content is trimmed);
        // what matters here is the LINE each segment is attributed to.
        $this->assertSame(
            [[self::TEXT, "a\n", 1], [self::OUTPUT, 'x', 2], [self::TEXT, "\nb", 4]],
            array_map(
                static fn(array $s): array =>
                    [$s[Tokenizer::KEY_TYPE], $s[Tokenizer::KEY_CONTENT], $s[Tokenizer::KEY_LINE]],
                $segments
            )
        );
    }

    // =========================================================================
    // Unclosed tags
    // =========================================================================

    public function testUnclosedOutputTagThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unclosed output tag/');
        (new Tokenizer())->tokenize('hello {{ name');
    }

    public function testUnclosedBlockTagThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unclosed block tag/');
        (new Tokenizer())->tokenize('{% if x');
    }

    public function testUnclosedCommentTagThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unclosed comment tag/');
        (new Tokenizer())->tokenize('{# hello');
    }

    public function testUnclosedTagReportsTheTemplateLine(): void
    {
        try {
            (new Tokenizer())->tokenize("line one\nline two\n{{ name");
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame(3, $e->templateLine);
            $this->assertStringContainsString('template line 3', $e->getMessage());
        }
    }

    public function testUnbalancedBraceInsideTagIsUnclosed(): void
    {
        // The single `{` opens a depth that is never closed, so no balanced
        // closer exists and the tag is reported rather than silently truncated.
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unclosed output tag/');
        (new Tokenizer())->tokenize('{{ user{ }}');
    }
}