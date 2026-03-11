<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
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
            'items' => ['x', 'y', 'z', 'w'],
            'indexes' => [0, 2, 3],
            'i' => 1,
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
            'user' => ['id' => 7],
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
        $result = self::render('object_spread', ['payload' => ['name' => 'Merlin']]);
        $this->assertSame('{"foo":"bar","name":"Merlin","answer":42}', $result);
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
        $obj = new class {
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
        $obj = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['x' => 42];
            }
        };
        self::tpl('jsonser', '{{ data.x }}');
        $result = self::render('jsonser', ['data' => $obj]);
        $this->assertSame('42', $result);
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
        TestEnvironment::engine()->addNamespace('ui', $nsPath);

        self::tpl('dynamic_include_ns', '{{ include("ui::badge", { label: "ok" }) }}');
        $this->assertSame('<span>ok</span>', self::render('dynamic_include_ns'));
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
        TestEnvironment::engine()->addNamespace('mns', $nsDir);

        $result = $this->renderPartial('mns::hello', ['x' => '42']);
        $this->assertSame('ns:42', $result);
    }
}
