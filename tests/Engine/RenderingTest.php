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
        self::tpl('invalid_spread', '{{ include("x", ...vars()) }}');
        self::render('invalid_spread');
    }

    // =========================================================================
    // Object literals: `{}` is a MAP, `(object) {}` is an OBJECT LITERAL
    // =========================================================================

    /**
     * A brace literal is an associative ARRAY. That is not incidental: `:`, the
     * array-key operator, is what reads it, and `include()`/`merge()`/`...` all
     * need an array. `(object)` states the other reading, and inside that
     * literal a nested brace literal is another object — so `.` reaches every
     * level without a cast per level.
     */
    public function testObjectLiteralNestsThroughEveryDepth(): void
    {
        self::tpl(
            'object_literal_nested',
            '{% set user = (object) { name: "Alice", address: { city: "Rome", geo: { lat: 1 } } } %}'
                . '{{ user.name }}|{{ user.address.city }}|{{ user.address.geo.lat }}'
        );

        $this->assertSame('Alice|Rome|1', self::render('object_literal_nested'));
    }

    public function testObjectLiteralSerializesAsAnObjectAtEveryDepth(): void
    {
        self::tpl(
            'object_literal_json',
            '{% set user = (object) { name: "Alice", address: { city: "Rome" } } %}{{ user |> json |> raw }}'
        );

        $this->assertSame('{"name":"Alice","address":{"city":"Rome"}}', self::render('object_literal_json'));
    }

    /**
     * The object reading is LEXICAL, so it stops at `[`: an array element states
     * that it holds a value, and a map inside it stays a map.
     */
    public function testArrayElementsInsideAnObjectLiteralStayArrays(): void
    {
        self::tpl(
            'object_literal_array_value',
            '{% set u = (object) { tags: ["a", "b"], meta: { n: 1 } } %}'
                . '{{ u.tags[1] }}|{{ u.meta.n }}|{{ u.tags |> json |> raw }}'
        );

        $this->assertSame('b|1|["a","b"]', self::render('object_literal_array_value'));
    }

    /**
     * A nested literal inside a LARGER expression is not the whole value, so it
     * keeps its ordinary (array) reading: the object rule is about what the
     * literal IS, not about how it is spelled.
     */
    public function testANestedLiteralInsideALargerExpressionStaysAMap(): void
    {
        self::tpl(
            'object_literal_in_expression',
            '{% set u = (object) { a: flag ? { b: 1 } : {} } %}{{ u.a |> json |> raw }}'
        );

        $this->assertSame('{"b":1}', self::render('object_literal_in_expression', ['flag' => true]));
    }

    /**
     * The regression that matters most: plain `{}` must be untouched. Every
     * consumer that needs an array — `:`, `include()`, spread — keeps working,
     * and `.` keeps failing on it, which is the documented strictness.
     */
    public function testPlainBraceLiteralIsStillAnArray(): void
    {
        self::tpl(
            'map_unchanged',
            '{% set m = { a: { b: 1 } } %}{{ m:a:b }}|{{ m |> json |> raw }}'
        );

        $this->assertSame('1|{"a":{"b":1}}', self::render('map_unchanged'));
    }

    public function testPropertyAccessOnAPlainBraceLiteralStillThrows(): void
    {
        self::tpl('map_dot_throws', '{% set m = { a: { b: 1 } } %}{{ m.a.b }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Cannot read property "a" on array/');
        self::render('map_dot_throws');
    }

    /**
     * The cast FILTER is a conversion, not a literal: `value |> object` is PHP's
     * shallow `(object)` and stays that way, so nothing that already round-trips
     * through it changes meaning.
     */
    public function testTheObjectFilterStaysAShallowConversion(): void
    {
        self::tpl(
            'object_filter_shallow',
            '{% set u = { a: { b: 1 } } |> object %}{{ u |> json |> raw }}'
        );

        $this->assertSame('{"a":{"b":1}}', self::render('object_filter_shallow'));
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
        self::tpl('dynamic_include', '{{ include("partials/card", { foo: "bar", ...vars() }) }}');

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
        self::tpl('dynamic_include_set', '{% set content = include("partials/inline_html", vars()) %}{{ content |> raw }}');

        $result = self::render('dynamic_include_set', ['name' => 'Bob']);
        $this->assertSame('<em>Bob</em>', $result);
    }

    public function testDynamicIncludeRemainsSafeAcrossNestedContext(): void
    {
        self::tpl('partials/inner_html', '<strong>{{ name }}</strong>');
        self::tpl('partials/outer_html', '{{ snippet |> raw }}');
        self::tpl(
            'dynamic_include_nested_context',
            '{% set snippet = include("partials/inner_html", vars()) %}{{ include("partials/outer_html", vars()) }}'
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
        self::tpl('dynamic_loop', '{{ include("dynamic_loop", vars()) }}');

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

    /**
     * `js` context emits escaped string CONTENT, so the template supplies the
     * quotes: `var name = "{{ user.name }}";`.
     *
     * There is exactly one output form. A rule that varied with the position
     * would have to infer JavaScript lexical state from template text, and a
     * misjudgement there silently changes what is rendered — which is how
     * `var tiers = {{ tiers }};` became invalid JavaScript that no editor would
     * accept. The quote position is now used only to ACCEPT or REJECT.
     */
    public function testJsContextEmitsEscapedContentForQuotedOutput(): void
    {
        self::tpl('ctx_js_dq', '<script>var x = "{{ name }}";</script>');

        $this->assertSame(
            '<script>var x = "O\u0027Reilly \u003Cb\u003E";</script>',
            self::render('ctx_js_dq', ['name' => 'O\'Reilly <b>']),
            'exactly one pair of quotes, and the content escaped',
        );
    }

    public function testJsContextAcceptsSingleQuotes(): void
    {
        self::tpl('ctx_js_sq', "<script>var x = '{{ name }}';</script>");

        $this->assertSame(
            "<script>var x = 'O\u0027Reilly \u003Cb\u003E';</script>",
            self::render('ctx_js_sq', ['name' => 'O\'Reilly <b>']),
        );
    }

    public function testJsContextAcceptsAnExpressionMidLiteral(): void
    {
        self::tpl('ctx_js_mid', '<script>var x = "before {{ name }} after";</script>');

        $this->assertSame(
            '<script>var x = "before O\u0027Reilly \u003Cb\u003E after";</script>',
            self::render('ctx_js_mid', ['name' => 'O\'Reilly <b>']),
        );
    }

    /**
     * An unquoted tag sits in a value position, so it is emitted as a complete
     * JSON literal. A string value therefore arrives already quoted — `{{ name }}`
     * renders `"Ada"` — which is what makes `var x = {{ name }};` valid JavaScript.
     */
    public function testJsContextEmitsAJsonLiteralForAnUnquotedTag(): void
    {
        self::tpl('ctx_js_bare', '<script>var x = {{ name }};</script>');

        $this->assertSame(
            '<script>var x = "Ada";</script>',
            self::render('ctx_js_bare', ['name' => 'Ada']),
        );
    }

    /**
     * A number and a boolean need no coercion: `json_encode(3)` is `3` and
     * `json_encode(true)` is `true`, which is the case the one-form contract made
     * awkward and the reason this second form exists.
     */
    public function testJsContextUnquotedTagPreservesJsonTypes(): void
    {
        self::tpl(
            'ctx_js_types',
            '<script>var n = {{ n }}; var b = {{ flag }}; var z = {{ none }};</script>',
        );

        $this->assertSame(
            '<script>var n = 3; var b = true; var z = null;</script>',
            self::render('ctx_js_types', ['n' => 3, 'flag' => true, 'none' => null]),
        );
    }

    public function testJsContextEmitsAJsonLiteralForAnUnquotedPayload(): void
    {
        self::tpl('ctx_js_bare_arr', '<script>var x = {{ items }};</script>');

        $this->assertSame(
            '<script>var x = ["a","b"];</script>',
            self::render('ctx_js_bare_arr', ['items' => ['a', 'b']]),
        );
    }

    /**
     * A string containing quotes and angle brackets must survive both forms — the
     * JSON literal because it is JSON, and a quoted tag because the content is
     * hex-escaped. Both hex-escape, so the two forms agree on a string value; they
     * differ only in whether the surrounding quotes are part of the output.
     */
    public function testJsContextBothFormsEscapeTheSameValue(): void
    {
        self::tpl(
            'ctx_js_both_forms',
            '<script>var a = {{ v }}; var b = "{{ v }}";</script>',
        );

        $escaped = 'O\u0027Reilly \u0022x\u0022 \u003C\/script\u003E';

        $this->assertSame(
            '<script>var a = "' . $escaped . '"; var b = "' . $escaped . '";</script>',
            self::render('ctx_js_both_forms', ['v' => 'O\'Reilly "x" </script>']),
        );
    }

    /**
     * A JSON payload takes the `raw` route, which bypasses escaping entirely:
     * `JSON.parse('{{ items |> json |> raw }}')`.
     */
    public function testJsContextJsonPayloadViaRaw(): void
    {
        self::tpl('ctx_js_json', "<script>var x = JSON.parse('{{ items |> json |> raw }}');</script>");

        $this->assertSame(
            '<script>var x = JSON.parse(\'["a","b"]\');</script>',
            self::render('ctx_js_json', ['items' => ['a', 'b']]),
        );
    }

    /**
     * A number arrives as a string and is coerced where the template says so.
     * This is the deliberate cost of having one output form.
     */
    public function testJsContextNumbersAndBooleansAreCoercedByTheTemplate(): void
    {
        self::tpl(
            'ctx_js_coerce',
            "<script>var n = Number('{{ n }}'); var b = ('{{ flag }}' === '1');</script>",
        );

        $this->assertSame(
            "<script>var n = Number('12'); var b = ('1' === '1');</script>",
            self::render('ctx_js_coerce', ['n' => 12, 'flag' => true]),
        );
    }

    /**
     * Only the MATCHING delimiter closes a string, which is what lets these render
     * as one literal instead of flipping the state mid-value.
     */
    public function testJsContextTreatsAQuoteInsideAStringAsText(): void
    {
        self::tpl('ctx_js_inner_apos', '<script>var a = "it\'s {{ v }}";</script>');
        $this->assertSame(
            '<script>var a = "it\'s hello world";</script>',
            self::render('ctx_js_inner_apos', ['v' => 'hello world']),
        );

        self::tpl('ctx_js_inner_dq', '<script>var a = \'say "hi" {{ v }}\';</script>');
        $this->assertSame(
            '<script>var a = \'say "hi" hello world\';</script>',
            self::render('ctx_js_inner_dq', ['v' => 'hello world']),
        );
    }

    /**
     * A quote that is NOT a delimiter must not be mistaken for one, or the check
     * would reject perfectly good templates.
     *
     * @dataProvider provideQuotesThatDoNotOpenAString
     */
    public function testJsContextSeesThroughQuotesThatAreNotDelimiters(
        string $name,
        string $template,
        string $expected,
    ): void {
        // A distinct template name per row: the compiled class is cached by name,
        // so reusing one would silently assert against the first row's output.
        self::tpl('ctx_js_notdelim_' . $name, $template);

        $this->assertSame(
            $expected,
            self::render('ctx_js_notdelim_' . $name, ['v' => 'hi']),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideQuotesThatDoNotOpenAString(): array
    {
        // Single-line templates only: a nowdoc heredoc picks up the file's line
        // endings, which would make the expected string platform-dependent.
        return [
            'apostrophe in a line comment'  => [
                'line_comment',
                '<script>// don\'t touch: var a = "{{ v }}";</script>',
                '<script>// don\'t touch: var a = "hi";</script>',
            ],
            'apostrophe in a block comment' => [
                'block_comment',
                "<script>/* don't */ var a = \"{{ v }}\";</script>",
                "<script>/* don't */ var a = \"hi\";</script>",
            ],
            'quote inside a regex literal'  => [
                'regex',
                '<script>var re = /"/; var a = "{{ v }}";</script>',
                '<script>var re = /"/; var a = "hi";</script>',
            ],
            'apostrophe in a template str'  => [
                'backtick',
                "<script>var t = `it's`; var a = \"{{ v }}\";</script>",
                "<script>var t = `it's`; var a = \"hi\";</script>",
            ],
        ];
    }

    /**
     * A string literal may begin after a keyword, where the preceding character is
     * a letter rather than an operator.
     */
    public function testJsContextRecognisesAStringAfterAKeyword(): void
    {
        self::tpl('ctx_js_return', '<script>function f(){ return "{{ v }}"; }</script>');

        $this->assertSame(
            '<script>function f(){ return "hi"; }</script>',
            self::render('ctx_js_return', ['v' => 'hi']),
        );
    }

    public function testJsContextRecognisesAStringInAnObjectKey(): void
    {
        self::tpl('ctx_js_objkey', '<script>var o = {"k": "{{ v }}"};</script>');

        $this->assertSame(
            '<script>var o = {"k": "hi"};</script>',
            self::render('ctx_js_objkey', ['v' => 'hi']),
        );
    }

    public function testJsContextStateDoesNotLeakBetweenScripts(): void
    {
        self::tpl(
            'ctx_js_leak',
            '<script>var a = "{{ v }}";</script><p>{{ v }}</p><script>var b = "{{ v }}";</script>',
        );

        $this->assertSame(
            '<script>var a = "hi";</script><p>hi</p><script>var b = "hi";</script>',
            self::render('ctx_js_leak', ['v' => 'hi']),
        );
    }

    public function testJsContextUnterminatedQuoteOnAPreviousLineIsForgotten(): void
    {
        // A raw line break cannot appear in a JS string literal. Without that rule
        // one stray apostrophe in a comment would reject every later expression.
        self::tpl('ctx_js_line', "<script>// it's fine\nvar a = \"{{ v }}\";</script>");

        $this->assertSame(
            "<script>// it's fine\nvar a = \"hi\";</script>",
            self::render('ctx_js_line', ['v' => 'hi']),
        );
    }

    /**
     * A quote inside a comment or a regex literal must not be mistaken for an
     * opening string delimiter.
     *
     * This is the security-relevant direction of the check. A phantom string
     * opened here swallows the rest of the line, so an unquoted tag is read as
     * quoted and its value is emitted as bare content — a stray quote in a
     * comment would turn a template author's mistake into script injection from
     * data the template does not control.
     *
     * The assertion is on the OUTCOME, not on the form: the payload must arrive as
     * an inert string and must never appear as executable code.
     *
     * @dataProvider provideQuotesThatMustNotOpenAString
     */
    public function testJsContextNeverEmitsAValueAsCodeAfterAQuoteInACommentOrRegex(
        string $name,
        string $template,
    ): void {
        self::tpl('ctx_js_phantom_' . $name, $template);

        $out = self::render('ctx_js_phantom_' . $name, ['v' => ';alert(1);']);

        $this->assertStringNotContainsString(
            'x = ;alert(1);',
            $out,
            'the payload must not be spliced in as bare code',
        );
        $this->assertStringContainsString(
            'x = ";alert(1);";',
            $out,
            'the payload must arrive as a quoted, inert JSON string',
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideQuotesThatMustNotOpenAString(): array
    {
        return [
            // One quote, closed never: a quote-counting scan keeps the string open
            // to the end of the line.
            'odd quote in a block comment' => [
                'block_comment',
                "<script>/* ' */ var x = {{ v }};</script>",
            ],
            'odd quote in a regex class'   => [
                'regex_class',
                "<script>var r = /['/]/; var x = {{ v }};</script>",
            ],
            'odd quote in a line comment'  => [
                'line_comment',
                "<script>// ('\nvar x = {{ v }};</script>",
            ],
        ];
    }

    /**
     * The scan must know where `<script>` begins and ends by itself. Trusting the
     * compiler's escaping context was not enough: the context only changes when
     * the LAST boundary in a segment differs from the current one, so
     * `</script><script>` inside a single segment leaves it unchanged and no reset
     * happens. A string left open before the `</script>` then made the tag in the
     * next script read as string content — so the value was emitted bare.
     */
    public function testJsContextFollowsScriptBoundariesWithinOneSegment(): void
    {
        self::tpl(
            'ctx_js_boundary',
            '<script>var a = "x;</script><script>var b = {{ v }};</script>',
        );

        $this->assertSame(
            '<script>var a = "x;</script><script>var b = ";alert(1);";</script>',
            self::render('ctx_js_boundary', ['v' => ';alert(1);']),
        );
    }

    /**
     * A tag inside a regular expression or a template literal has no sane output
     * form, so both are rejected — and the error points at the template.
     *
     * @dataProvider provideScriptPositionsWithoutAnOutputForm
     */
    public function testJsContextRejectsPositionsWithoutAnOutputForm(
        string $name,
        string $template,
        string $expectedMessage,
    ): void {
        self::tpl('ctx_js_noform_' . $name, $template);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        self::render('ctx_js_noform_' . $name, ['v' => 'x']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideScriptPositionsWithoutAnOutputForm(): array
    {
        return [
            'inside a regex literal'      => [
                'regex',
                '<script>var r = /{{ v }}/;</script>',
                '/cannot be used inside a JavaScript regular expression/',
            ],
            'inside a template literal'   => [
                'template',
                '<script>var t = `{{ v }}`;</script>',
                '/cannot be used directly inside a JavaScript template\s+literal/',
            ],
        ];
    }

    /**
     * `${ … }` returns to ordinary JavaScript, so a tag there is a value again and
     * takes the JSON-literal form. A template literal's text is also opaque, so the
     * quotes, braces and `//` in it cannot confuse the scan.
     */
    public function testJsContextAllowsATagInsideATemplateLiteralExpression(): void
    {
        self::tpl(
            'ctx_js_tpl_expr',
            '<script>var t = `a ${ {{ n }} } b`; var u = `x // " \' { } ${ {{ n }} }`;</script>',
        );

        $this->assertSame(
            '<script>var t = `a ${ 3 } b`; var u = `x // " \' { } ${ 3 }`;</script>',
            self::render('ctx_js_tpl_expr', ['n' => 3]),
        );
    }

    /**
     * An unquoted tag in a line comment keeps the string-content form: a comment is
     * inert, so either form is safe and changing it would only churn output.
     */
    public function testJsContextTreatsACommentAsInert(): void
    {
        self::tpl('ctx_js_cmt_form', "<script>// note: {{ v }}\nvar a = {{ v }};</script>");

        $this->assertSame(
            "<script>// note: hi\nvar a = \"hi\";</script>",
            self::render('ctx_js_cmt_form', ['v' => 'hi']),
        );
    }

    /**
     * A template that ends inside `<script>` must not affect the next template:
     * the compiler and its tokenizer are reused across compilations.
     */
    public function testJsContextScriptPositionDoesNotLeakBetweenTemplates(): void
    {
        self::tpl('ctx_js_leak_src', '<script>var a = "{{ v }}";</script>');

        $this->assertSame(
            '<script>var a = "hi";</script>',
            self::render('ctx_js_leak_src', ['v' => 'hi']),
        );

        // Compiled after a template that finished inside a script, so a naive
        // "sticky" scan would treat this markup tag as script text.
        self::tpl('ctx_js_leak_dst', '<p>{{ v }}</p>');

        $this->assertSame('<p>hi</p>', self::render('ctx_js_leak_dst', ['v' => 'hi']));
    }

    public function testCssContextRaw(): void
    {
        self::tpl('ctx_css', '<style>body { color: {{ color }}; }</style>');
        $result = self::render('ctx_css', ['color' => 'red']);
        $this->assertSame('<style>body { color: red; }</style>', $result);
    }
}
