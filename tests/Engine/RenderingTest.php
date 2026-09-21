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

    public function testDotAccessOnArray(): void
    {
        self::tpl('dot', '{{ user.name }}');
        $result = self::render('dot', ['user' => ['name' => 'Alice']]);
        $this->assertSame('Alice', $result);
    }

    public function testNestedDotAccess(): void
    {
        self::tpl('nested', '{{ a.b.c }}');
        $result = self::render('nested', ['a' => ['b' => ['c' => 'deep']]]);
        $this->assertSame('deep', $result);
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
        self::tpl('array_literal_filter', '{{ [1, 2, user.id] |> json |> raw }}');
        $result = self::render('array_literal_filter', ['user' => ['id' => 3]]);
        $this->assertSame('[1,2,3]', $result);
    }

    public function testObjectLiteralCanBePassedToFilters(): void
    {
        self::tpl(
            'object_literal_filter',
            '{{ { foo: "bar", count: count, nested: { id: user.id }, items: [1, 2] } |> json |> raw }}'
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
            '{{ { user: { name: "Alice" } }.user.name ~ ":" ~ [10, 20, 30][1] }}'
        );

        $this->assertSame('Alice:20', self::render('literal_postfix_access'));
    }

    public function testSetCanStoreNestedLiteralCollections(): void
    {
        self::tpl(
            'set_literal_collection',
            '{% set payload = { meta: { total: count }, items: [1, 2, 3] } %}{{ payload.meta.total ~ ":" ~ payload.items[2] }}'
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

    public function testObjectCasting(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Charlie';
        self::tpl('obj', '{{ person.name }}');
        $result = self::render('obj', ['person' => $obj]);
        $this->assertSame('Charlie', $result);
    }

    public function testObjectWithToArray(): void
    {
        $obj = new class
        {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };
        self::tpl('toarray', '{{ item.key }}');
        $result = self::render('toarray', ['item' => $obj]);
        $this->assertSame('value', $result);
    }

    public function testJsonSerializableObjectCasting(): void
    {
        $obj = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['x' => 42];
            }
        };
        self::tpl('jsonser', '{{ data.x }}');
        $result = self::render('jsonser', ['data' => $obj]);
        $this->assertSame('42', $result);
    }

    /**
     * A self-returning jsonSerialize() used to make castToArray() recurse until
     * the memory limit was hit, because the object was re-tested against the
     * same branch. The (array) cast that guarded against that also exposed
     * non-public properties - see testJsonSerializableDoesNotLeakNonPublicState.
     */
    public function testSelfReturningJsonSerializableDoesNotRecurseForever(): void
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
        // Loop in (key, value) order so the private property's absence can be
        // asserted: dot access on a missing key throws instead.
        self::tpl('jsonser_self', '{% for key, value in data %}[{{ key }}={{ value }}]{% endfor %}');
        $result = self::render('jsonser_self', ['data' => $obj]);
        $this->assertSame('[name=ok]', $result);
    }

    /**
     * JsonSerializable is a wire-format contract and exposes nothing about
     * visibility, so it must not widen what a template can reach. `(array)`
     * did: it flattens private/protected props into mangled keys.
     */
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

    /**
     * A plain object of the same shape must produce the same result as the
     * JsonSerializable one, i.e. visibility rules may not depend on whether a
     * class happens to implement the interface.
     */
    public function testJsonSerializableMatchesPlainObjectVisibility(): void
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
     * toArray() declares an array return type; jsonSerialize() does not. An
     * object offering both must therefore resolve through toArray().
     */
    public function testToArrayWinsOverJsonSerializable(): void
    {
        $obj = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['winner' => 'jsonSerialize'];
            }

            public function toArray(): array
            {
                return ['winner' => 'toArray'];
            }
        };
        self::tpl('both_contracts', '{{ data.winner }}');
        $result = self::render('both_contracts', ['data' => $obj]);
        $this->assertSame('toArray', $result);
    }

    /**
     * A scalar returned from jsonSerialize() used to be wrapped in a
     * one-element array by the (array) cast.
     */
    public function testJsonSerializableReturningScalarIsNotWrapped(): void
    {
        $obj = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return 'plain-string';
            }
        };
        self::tpl('jsonser_scalar', '{{ data }}');
        $result = self::render('jsonser_scalar', ['data' => $obj]);
        $this->assertSame('plain-string', $result);
    }

    /**
     * method_exists() reports true for non-public methods, so a private
     * toArray() used to trigger "Call to private method".
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
     * DateTimeInterface is neither JsonSerializable nor Traversable and exposes
     * no public properties, so it used to cast to [] and make the date filter
     * render 1970-01-01.
     */
    public function testDateTimeInterfaceBecomesIsoString(): void
    {
        $dt = new \DateTime('2026-09-21 12:00:00+02:00');
        self::tpl('datetime', '{{ createdAt }}');
        $result = self::render('datetime', ['createdAt' => $dt]);
        $this->assertSame('2026-09-21T12:00:00+02:00', $result);
    }

    public function testDateTimeInterfaceWorksWithDateFilter(): void
    {
        $dt = new \DateTime('2026-09-21 12:00:00+02:00');
        self::tpl('datetime_filter', '{{ createdAt |> date("Y-m-d") }}');
        $result = self::render('datetime_filter', ['createdAt' => $dt]);
        $this->assertSame('2026-09-21', $result);
    }

    public function testDateTimeImmutableBecomesIsoString(): void
    {
        $dt = new \DateTimeImmutable('2026-09-21 12:00:00+02:00');
        self::tpl('datetime_immutable', '{{ createdAt }}');
        $result = self::render('datetime_immutable', ['createdAt' => $dt]);
        $this->assertSame('2026-09-21T12:00:00+02:00', $result);
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
     * The general object -> array rule stays dominant: an object with public
     * properties must not have them silently replaced by __toString().
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