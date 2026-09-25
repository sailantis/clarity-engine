<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

class RenderingTest extends BaseTestCase
{
    // =========================================================================
    // Variable Output
    // =========================================================================

    public function testSimpleVariable(): void
    {
        self::tpl('simple', 'Hello {{ name }}!');
        $this->assertSame('Hello World!', self::render('simple', ['name' => 'World']));
    }

    public function testAutoEscape(): void
    {
        self::tpl('escape', '{{ html }}');
        $result = self::render('escape', ['html' => '<script>alert(1)</script>']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
    }

    public function testRawFilterSuppressesEscape(): void
    {
        self::tpl('raw', '{{ html |> raw }}');
        $result = self::render('raw', ['html' => '<b>bold</b>']);
        $this->assertSame('<b>bold</b>', $result);
    }

    public function testDotAccessOnArrayIsRejected(): void
    {
        // STRICT: `.` is object property access. An ARRAY must be read with a
        // key (`user:name`) or an index (`user['name']`). Applying `.` to an
        // array is a PHP warning ("Attempt to read property on array") which
        // the engine maps to a located ClarityException.
        self::tpl('dot', '{{ user.name }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Cannot read property "name" on array/');
        self::render('dot', ['user' => ['name' => 'Alice']]);
    }

    public function testStaticKeyAccessOnArray(): void
    {
        self::tpl('colon', '{{ user:name }}');
        $this->assertSame('Alice', self::render('colon', ['user' => ['name' => 'Alice']]));
    }

    public function testNestedStaticKeyAccess(): void
    {
        self::tpl('nested', '{{ a:b:c }}');
        $this->assertSame('deep', self::render('nested', ['a' => ['b' => ['c' => 'deep']]]));
    }

    public function testNumericIndexAccess(): void
    {
        self::tpl('index', '{{ items[0] }}');
        $result = self::render('index', ['items' => ['first', 'second']]);
        $this->assertSame('first', $result);
    }

    public function testDynamicIndexAccess(): void
    {
        self::tpl('dynidx', '{{ items[idx] }}');
        $result = self::render('dynidx', ['items' => ['a', 'b', 'c'], 'idx' => 2]);
        $this->assertSame('c', $result);
    }

    public function testNestedDynamicIndexAccess(): void
    {
        self::tpl('nested_dynidx', '{{ items[indexes[i + 1]] }}');
        $result = self::render('nested_dynidx', [
            'items'   => ['x', 'y', 'z', 'w'],
            'indexes' => [0, 2, 3],
            'i'       => 1,
        ]);
        $this->assertSame('w', $result);
    }

    public function testStringLiteralOutput(): void
    {
        self::tpl('literal', '{{ "hello" }}');
        $this->assertSame('hello', self::render('literal'));
    }

    public function testStringLiteralWithEscapedQuoteAndPipelineToken(): void
    {
        self::tpl('literal_escaped_pipe', '{{ "a\"|>b" }}');
        $this->assertSame('a&quot;|&gt;b', self::render('literal_escaped_pipe'));
    }

    public function testNullCoalescing(): void
    {
        self::tpl('nullcoal', '{{ missing ?? "default" }}');
        $this->assertSame('default', self::render('nullcoal', []));
    }

    public function testConcatenation(): void
    {
        self::tpl('concat', '{{ first ~ " " ~ last }}');
        $result = self::render('concat', ['first' => 'John', 'last' => 'Doe']);
        $this->assertSame('John Doe', $result);
    }

    public function testArrayLiteralCanBePassedToFilters(): void
    {
        self::tpl('array_literal_filter', '{{ [1, 2, user:id] |> json |> raw }}');
        $result = self::render('array_literal_filter', ['user' => ['id' => 3]]);
        $this->assertSame('[1,2,3]', $result);
    }

    public function testObjectLiteralCanBePassedToFilters(): void
    {
        self::tpl(
            'object_literal_filter',
            '{{ { foo: "bar", count: count, nested: { id: user:id }, items: [1, 2] } |> json |> raw }}'
        );

        $result = self::render('object_literal_filter', [
            'count' => 3,
            'user'  => ['id' => 7],
        ]);

        $this->assertSame('{"foo":"bar","count":3,"nested":{"id":7},"items":[1,2]}', $result);
    }

    public function testLiteralCollectionsSupportPostfixAccess(): void
    {
        self::tpl(
            'literal_postfix_access',
            '{{ { user: { name: "Alice" } }:user:name ~ ":" ~ [10, 20, 30][1] }}'
        );

        $this->assertSame('Alice:20', self::render('literal_postfix_access'));
    }

    public function testSetCanStoreNestedLiteralCollections(): void
    {
        self::tpl(
            'set_literal_collection',
            '{% set payload = { meta: { total: count }, items: [1, 2, 3] } %}{{ payload:meta:total ~ ":" ~ payload:items[2] }}'
        );

        $this->assertSame('5:3', self::render('set_literal_collection', ['count' => 5]));
    }

    public function testArrayLiteralSupportsSpread(): void
    {
        self::tpl('array_spread', '{{ [1, ...items, 4] |> json |> raw }}');
        $this->assertSame('[1,2,3,4]', self::render('array_spread', ['items' => [2, 3]]));
    }

    public function testObjectLiteralSupportsSpread(): void
    {
        self::tpl('object_spread', '{{ { foo: "bar", ...payload, answer: 42 } |> json |> raw }}');
        $result = self::render('object_spread', ['payload' => ['name' => 'Azera']]);
        $this->assertSame('{"foo":"bar","name":"Azera","answer":42}', $result);
    }

    public function testSpreadOutsideCollectionThrows(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Spread operator is only allowed inside array and object literals/');
        self::tpl('invalid_spread', '{{ include("x", ...context()) }}');
        self::render('invalid_spread');
    }

    // =========================================================================
    // Whitespace / Literals
    // =========================================================================

    public function testStaticTextIsPassedThrough(): void
    {
        self::tpl('static', '<p>Hello, world!</p>');
        $this->assertSame('<p>Hello, world!</p>', self::render('static'));
    }

    public function testMultilineTemplate(): void
    {
        $tpl = "line1\nline2\n{{ value }}\nline4";
        self::tpl('multiline', $tpl);
        $this->assertSame("line1\nline2\nhello\nline4", self::render('multiline', ['value' => 'hello']));
    }

    // =========================================================================
    // Whitespace after a block tag
    //
    // A block or comment tag consumes ONE line break from the text that follows
    // it, so a directive alone on its own line does not emit a blank line. This
    // mirrors Twig's `%}\n?` / `#}\n?` and PHP's own close-tag rule, and it is
    // why Clarity no longer emits 2 of every 3 output lines as whitespace on the
    // competition benchmark page.
    //
    // NOTE: the literal close tag is deliberately NOT spelled out in this
    // comment. A close tag inside a one-line comment ends PHP mode, so writing
    // it here would turn the rest of this file into inline HTML and produce a
    // parse error dozens of lines away. (Block comments are not affected.)
    // =========================================================================

    public function testDirectiveAloneOnItsLineLeavesNoBlankLine(): void
    {
        $tpl = "X\n{% if true %}\nY\n{% endif %}\nZ";
        self::tpl('ws_block_alone', $tpl);
        $this->assertSame("X\nY\nZ", self::render('ws_block_alone'));
    }

    public function testLoopBodyHasNoBlankLinePerIteration(): void
    {
        // The inner loop is the case that produced ~1 blank line per item.
        //
        // Note the FOUR-space indent on the span: the two spaces before `{% for`
        // and the two before `<s>` MERGE, because the newline that separated
        // them is the one the rule removes. That is not an accident of this
        // implementation - it is exactly Twig's output for the same source.
        $tpl = "<div>\n  {% for j in 0 .. 2 %}\n  <s>{{ j }}</s>\n  {% endfor %}\n</div>";
        self::tpl('ws_loop_body', $tpl);
        $this->assertSame(
            "<div>\n    <s>0</s>\n    <s>1</s>\n    <s>2</s>\n  </div>",
            self::render('ws_loop_body')
        );
    }

    public function testCommentAloneOnItsLineLeavesNoBlankLine(): void
    {
        self::tpl('ws_comment_alone', "A\n{# note #}\nB");
        $this->assertSame("A\nB", self::render('ws_comment_alone'));
    }

    /**
     * The escape hatch: a space in front of the line break preserves it, which is
     * exactly how PHP's own close-tag rule and Twig's behave — so an author who
     * wants the blank line keeps it without needing an operator.
     */
    public function testSpaceBeforeTheLineBreakPreservesIt(): void
    {
        self::tpl('ws_spaced', "X\n{% if true %} \nY\n{% endif %}\nZ");
        $this->assertSame("X\n \nY\nZ", self::render('ws_spaced'));
    }

    /**
     * Only ONE break is removed: a deliberate blank line survives as one newline
     * rather than collapsing to nothing.
     */
    public function testOnlyOneLineBreakIsRemoved(): void
    {
        self::tpl('ws_two_breaks', "X\n{% if true %}\n\nY{% endif %}");
        $this->assertSame("X\n\nY", self::render('ws_two_breaks'));
    }

    /**
     * An output tag must NOT consume a line break. Twig's lexer has no `\n?` after
     * `}}` and Stempler emits a call rather than a tag boundary, so trimming here
     * would delete newlines the other engines keep — trading one divergence for
     * another. This is the boundary of the rule, so it is pinned.
     */
    public function testOutputTagDoesNotConsumeALineBreak(): void
    {
        self::tpl('ws_output_keeps', "A\n{{ 'X' }}\nB");
        $this->assertSame("A\nX\nB", self::render('ws_output_keeps', []));
    }

    /**
     * A CRLF template loses the whole CRLF, not just the LF — otherwise the left
     * over `\r` would itself be the blank line this rule exists to remove.
     * (Windows checkouts are CRLF, which is how the competition templates are.)
     */
    public function testCarriageReturnLineFeedIsRemovedWhole(): void
    {
        self::tpl('ws_crlf', "X\r\n{% if true %}\r\nY\r\n{% endif %}\r\nZ");
        $this->assertSame("X\r\nY\r\nZ", self::render('ws_crlf'));
    }

    /**
     * Spaces and tabs are NOT touched - only line breaks. So indentation survives
     * untouched, which is what keeps this from being a general whitespace
     * collapse. It is also the same escape hatch PHP's close-tag rule provides.
     */
    public function testSpacesAndTabsAfterATagArePreserved(): void
    {
        self::tpl('ws_spaces_kept', "A{% if true %}   \nB{% endif %}");
        $this->assertSame("A   \nB", self::render('ws_spaces_kept'));
    }

    // =========================================================================
    // Include / Extends / Block / Object casting
    // =========================================================================

    public function testInclude(): void
    {
        self::tpl('partials/greeting', 'Hi {{ name }}');
        self::tpl('main', '{% include "partials/greeting" %} there');
        $result = self::render('main', ['name' => 'Bob']);
        $this->assertSame('Hi Bob there', $result);
    }

    public function testStaticIncludeRecursionThrows(): void
    {
        self::tpl('partials/loop_a', 'A {% include "partials/loop_b" %}');
        self::tpl('partials/loop_b', 'B {% include "partials/loop_a" %}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Recursive static include detected/');
        self::render('partials/loop_a');
    }

    public function testDynamicIncludeFunctionRendersTemplateWithContext(): void
    {
        self::tpl('partials/card', '<b>{{ foo }}</b> {{ name }}');
        self::tpl('dynamic_include', '{{ include("partials/card", { foo: "bar", ...context() }) }}');

        $result = self::render('dynamic_include', ['name' => 'Bob']);
        $this->assertSame('<b>bar</b> Bob', $result);
    }

    public function testExtendsBlock(): void
    {
        self::tpl('layout', '<html>{% block content %}default{% endblock %}</html>');
        self::tpl('child', '{% extends "layout" %}{% block content %}Hello, {{ name }}!{% endblock %}');
        $result = self::render('child', ['name' => 'World']);
        $this->assertSame('<html>Hello, World!</html>', $result);
    }

    public function testObjectPropertyAccess(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Charlie';
        self::tpl('obj', '{{ person.name }}');
        $result = self::render('obj', ['person' => $obj]);
        $this->assertSame('Charlie', $result);
    }

    /**
     * A key access on an OBJECT is a compile-time mistake the runtime reports:
     * the strict syntax spells that read `item.key`. toArray() is a
     * CONTAINER-only contract (loops, keys, count), never an access path.
     */
    public function testKeyAccessOnObjectThrows(): void
    {
        $obj = new class
        {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };
        self::tpl('toarray', '{{ item:key }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Cannot use object of type/');
        self::render('toarray', ['item' => $obj]);
    }

    /**
     * A public property is a property, not a container entry: `data:prop`
     * (array-key read) cannot see it, while `data.prop` (property read) does.
     * That split is the whole point of the strict syntax; the array side goes
     * through iteration instead.
     */
    public function testPropertyReadDoesNotSeeIteratedEntries(): void
    {
        $obj = new class
        {
            public string $prop = 'from-property';
        };

        self::tpl('toarray_split', '{{ data.prop }}');
        $this->assertSame('from-property', self::render('toarray_split', ['data' => $obj]));
    }

    /**
     * JsonSerializable is NO LONGER consulted on the access path: with the
     * eager conversion gone, an object reaches the template as an object and
     * `data.x` is a property read. The interface keeps its meaning only for
     * container operations (iteration/keys/count).
     */
    public function testJsonSerializableIsNotUsedForPropertyAccess(): void
    {
        $obj = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['x' => 42];
            }
        };
        self::tpl('jsonser', '{{ data.x }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Property "x" is not defined|Cannot read property "x"/');
        self::render('jsonser', ['data' => $obj]);
    }

    public function testJsonSerializableEntriesAreVisibleToIteration(): void
    {
        $obj = new class implements \JsonSerializable
        {
            public string $name = 'ok';
            private string $secret = 'PRIVATE_LEAK';

            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };
        // Iteration goes through get_object_vars(), so only PUBLIC state is
        // reachable -- the private property must never appear.
        self::tpl('jsonser_self', '{% for key, value in data %}[{{ key }}={{ value }}]{% endfor %}');
        $result = self::render('jsonser_self', ['data' => $obj]);
        $this->assertSame('[name=ok]', $result);
    }

    public function testJsonSerializableDoesNotLeakNonPublicState(): void
    {
        $obj = new class implements \JsonSerializable
        {
            public string $owner = 'alice';
            private string $apiKey = 'sk-SECRET';
            protected string $internal = 'INTERNAL';

            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };
        self::tpl('jsonser_leak', '{% for key, value in data %}[{{ key }}={{ value }}]{% endfor %}');
        $result = self::render('jsonser_leak', ['data' => $obj]);
        $this->assertSame('[owner=alice]', $result);
    }

    public function testPlainObjectIterationMatchesJsonSerializableVisibility(): void
    {
        $plain = new class
        {
            public string $owner = 'alice';
            private string $apiKey = 'sk-SECRET';
            protected string $internal = 'INTERNAL';
        };
        self::tpl('plain_leak', '{% for key, value in data %}[{{ key }}={{ value }}]{% endfor %}');
        $result = self::render('plain_leak', ['data' => $plain]);
        $this->assertSame('[owner=alice]', $result);
    }

    /**
     * A plain object that is only JsonSerializable has NO string form, and the
     * engine no longer synthesises one by casting it. Rendering an object in an
     * output position is therefore an error -- a silent "Array" conversion is
     * exactly what the removed cast used to do.
     */
    public function testNonStringableObjectCannotBeRendered(): void
    {
        $obj = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return 'plain-string';
            }
        };
        self::tpl('jsonser_scalar', '{{ data }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/could not be converted to string/');
        self::render('jsonser_scalar', ['data' => $obj]);
    }

    /**
     * method_exists() reports true for non-public methods, so a private
     * toArray() must be ignored rather than triggering "Call to private method".
     */
    public function testPrivateToArrayIsIgnored(): void
    {
        $obj = new class
        {
            public string $name = 'from-props';

            private function toArray(): array
            {
                return ['name' => 'from-private-toArray'];
            }
        };
        self::tpl('private_toarray', '{{ data.name }}');
        $result = self::render('private_toarray', ['data' => $obj]);
        $this->assertSame('from-props', $result);
    }

    /**
     * A bare DateTime object is no longer stringified eagerly; the date filter
     * accepts it directly, which is what its own contract always claimed.
     */
    public function testDateTimeInterfaceWorksWithDateFilter(): void
    {
        $dt = new \DateTime('2026-09-21 12:00:00+02:00');
        self::tpl('datetime_filter', '{{ createdAt |> date("Y-m-d") }}');
        $result = self::render('datetime_filter', ['createdAt' => $dt]);
        $this->assertSame('2026-09-21', $result);
    }

    public function testDateTimeImmutableWorksWithDateFilter(): void
    {
        $dt = new \DateTimeImmutable('2026-09-21 12:00:00+02:00');
        self::tpl('datetime_immutable_filter', '{{ createdAt |> date("Y-m-d") }}');
        $result = self::render('datetime_immutable_filter', ['createdAt' => $dt]);
        $this->assertSame('2026-09-21', $result);
    }

    /**
     * A value object holding all state privately is otherwise invisible to
     * templates; __toString() is the only meaningful representation.
     */
    public function testStringableValueObjectUsesToString(): void
    {
        $obj = new class
        {
            private int $cents = 1234;

            public function __toString(): string
            {
                return \number_format($this->cents / 100, 2);
            }
        };
        self::tpl('stringable', '{{ price }}');
        $result = self::render('stringable', ['price' => $obj]);
        $this->assertSame('12.34', $result);
    }

    /**
     * A value object is a STRING for the container filters, not a container
     * with zero entries -- `length` of a money object is the length of its
     * string form, exactly as before the object->array conversion was removed.
     */
    public function testStringableValueObjectKeepsStringSemanticsForLength(): void
    {
        $obj = new class
        {
            private int $cents = 1234;

            public function __toString(): string
            {
                return '12.34';
            }
        };
        self::tpl('stringable_len', '{{ price |> length }}');
        $this->assertSame('5', self::render('stringable_len', ['price' => $obj]));
    }

    /**
     * An object with public properties is still read by its PROPERTY names; the
     * public state is never silently replaced by __toString().
     */
    public function testObjectWithPublicPropertiesIgnoresToString(): void
    {
        $obj = new class
        {
            public string $name = 'from-props';

            public function __toString(): string
            {
                return 'from-tostring';
            }
        };
        self::tpl('stringable_props', '{{ data.name }}');
        $result = self::render('stringable_props', ['data' => $obj]);
        $this->assertSame('from-props', $result);
    }

    // =========================================================================
    // Dynamic include (extended)
    // =========================================================================

    public function testDynamicIncludeAssignedViaSetRemainsUnescaped(): void
    {
        self::tpl('partials/inline_html', '<em>{{ name }}</em>');
        self::tpl('dynamic_include_set', '{% set content = include("partials/inline_html", context()) %}{{ content |> raw }}');

        $result = self::render('dynamic_include_set', ['name' => 'Bob']);
        $this->assertSame('<em>Bob</em>', $result);
    }

    public function testDynamicIncludeRemainsSafeAcrossNestedContext(): void
    {
        self::tpl('partials/inner_html', '<strong>{{ name }}</strong>');
        self::tpl('partials/outer_html', '{{ snippet |> raw }}');
        self::tpl(
            'dynamic_include_nested_context',
            '{% set snippet = include("partials/inner_html", context()) %}{{ include("partials/outer_html", context()) }}'
        );

        $result = self::render('dynamic_include_nested_context', ['name' => 'Bob']);
        $this->assertSame('<strong>Bob</strong>', $result);
    }

    public function testDynamicIncludeFunctionSupportsNamespacedTemplates(): void
    {
        $nsPath = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'namespaced';
        @mkdir($nsPath, 0755, true);
        file_put_contents($nsPath . DIRECTORY_SEPARATOR . 'badge.clarity.html', '<span>{{ label }}</span>');

        self::tpl('dynamic_include_ns', '{{ include("ui::badge", { label: "ok" }) }}');

        $engine         = TestEnvironment::engine();
        $originalLoader = $engine->getLoader();
        $engine->setLoader(new DomainRouterLoader(
            ['ui' => new FileLoader($nsPath)],
            new FileLoader(TestEnvironment::viewDir()),
        ));

        try {
            $this->assertSame('<span>ok</span>', self::render('dynamic_include_ns'));
        } finally {
            $engine->setLoader($originalLoader);
        }
    }

    public function testDynamicIncludeRecursionThrows(): void
    {
        self::tpl('dynamic_loop', '{{ include("dynamic_loop", context()) }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Recursive template rendering detected/');
        self::render('dynamic_loop');
    }

    // =========================================================================
    // Block fallback
    // =========================================================================

    public function testBlockFallback(): void
    {
        self::tpl('layout2', '[{% block title %}Default Title{% endblock %}]');
        self::tpl('child2', '{% extends "layout2" %}');
        $result = self::render('child2');
        $this->assertSame('[Default Title]', $result);
    }

    // =========================================================================
    // Engine namespace configuration
    // =========================================================================

    public function testNamespaceSupport(): void
    {
        $nsDir = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'ns';
        @mkdir($nsDir, 0755, true);
        file_put_contents($nsDir . DIRECTORY_SEPARATOR . 'hello.clarity.html', 'ns:{{ x }}');

        $engine         = TestEnvironment::engine();
        $originalLoader = $engine->getLoader();
        $engine->setLoader(new DomainRouterLoader(
            ['mns' => new FileLoader($nsDir)],
            new FileLoader(TestEnvironment::viewDir()),
        ));

        try {
            $result = $this->renderPartial('mns::hello', ['x' => '42']);
            $this->assertSame('ns:42', $result);
        } finally {
            $engine->setLoader($originalLoader);
        }
    }

    // =========================================================================
    // Context-aware escaping
    // =========================================================================

    public function testJsContextAutoDetectEncodesAsJson(): void
    {
        self::tpl('ctx_js_auto', '<script>var x = {{ name }};</script>');
        $result = self::render('ctx_js_auto', ['name' => 'O\'Reilly <b>']);
        // json_encode wraps strings in quotes and escapes < (HEX_TAG) and ' (HEX_APOS)
        $this->assertStringContainsString('var x = ', $result);
        $this->assertStringNotContainsString("O'Reilly", $result); // apostrophe escaped
        $this->assertStringNotContainsString('<b>', $result);      // < escaped
    }

    public function testHtmlContextAfterScriptClose(): void
    {
        self::tpl('ctx_html_after', '<script></script>{{ name }}');
        $result = self::render('ctx_html_after', ['name' => '<b>bold</b>']);
        $this->assertSame('<script></script>&lt;b&gt;bold&lt;/b&gt;', $result);
    }

    public function testContextHintJs(): void
    {
        self::tpl('ctx_hint_js', '{# @context js #}{{ name }}');
        $result = self::render('ctx_hint_js', ['name' => '<script>']);
        // json_encode with HEX_TAG encodes < and > as unicode escapes
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testContextHintHtmlRestores(): void
    {
        self::tpl('ctx_hint_restore', '{# @context js #}{# @context html #}{{ name }}');
        $result = self::render('ctx_hint_restore', ['name' => '<b>']);
        $this->assertSame('&lt;b&gt;', $result);
    }

    public function testCssContextRaw(): void
    {
        self::tpl('ctx_css', '<style>body { color: {{ color }}; }</style>');
        $result = self::render('ctx_css', ['color' => 'red']);
        $this->assertSame('<style>body { color: red; }</style>', $result);
    }
}