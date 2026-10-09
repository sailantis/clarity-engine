<?php

declare(strict_types=1);

namespace Clarity\Tests\Engine;

use Clarity\Debug\CliDumpRenderer;
use Clarity\Debug\CssDumpRenderer;
use Clarity\Debug\DumpOptions;
use Clarity\Debug\HtmlDumpRenderer;
use Clarity\Debug\JsDumpRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dump renderers used by dump() and dd().
 */
class DumpRendererTest extends TestCase
{
    private function secretObject(): object
    {
        return new class {
            public string $name = 'visible-name';
            public string $password = 'hunter2';
            protected string $token = 'protected-token-value';
            private string $secret = 'private-secret-value';
        };
    }

    public function testHtmlRendersEmptyArrayAsBrackets(): void
    {
        $out = (new HtmlDumpRenderer())->render([], DumpOptions::create());

        $this->assertStringContainsString('<span class="cd-empty">[]</span>', $out);
        $this->assertStringNotContainsString('{}', $out);
    }

    public function testHtmlIncludesCssOnEveryRender(): void
    {
        $renderer = new HtmlDumpRenderer();
        $opts = DumpOptions::create();

        $this->assertStringContainsString('<style>', $renderer->render(['a' => 1], $opts));
        $this->assertStringContainsString('<style>', $renderer->render(['b' => 2], $opts));
    }

    public function testCliRendersEmptyArrayAsBrackets(): void
    {
        $out = (new CliDumpRenderer())->render([], DumpOptions::create()->forceToTemplate());

        $this->assertSame("[DUMP] []\n", $out);
    }

    public function testHtmlMasksObjectPropertiesIncludingNonPublic(): void
    {
        $out = (new HtmlDumpRenderer())->render($this->secretObject(), DumpOptions::create());

        $this->assertStringContainsString('visible-name', $out);
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('protected-token-value', $out);
        $this->assertStringNotContainsString('private-secret-value', $out);
    }

    public function testCliMasksObjectPropertiesIncludingNonPublic(): void
    {
        $out = (new CliDumpRenderer())->render(
            $this->secretObject(),
            DumpOptions::create()->forceToTemplate()
        );

        $this->assertStringContainsString('visible-name', $out);
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('protected-token-value', $out);
        $this->assertStringNotContainsString('private-secret-value', $out);
    }

    public function testJsMasksObjectPropertiesIncludingNonPublic(): void
    {
        $out = (new JsDumpRenderer())->render($this->secretObject(), DumpOptions::create());

        $this->assertStringContainsString('"password":"***"', $out);
        $this->assertStringContainsString('"token":"***"', $out);
        $this->assertStringContainsString('"secret":"***"', $out);
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('protected-token-value', $out);
        $this->assertStringNotContainsString('private-secret-value', $out);
    }

    public function testCssMasksObjectPropertiesIncludingNonPublic(): void
    {
        $out = (new CssDumpRenderer())->render($this->secretObject(), DumpOptions::create());

        $this->assertStringContainsString('"password":"***"', $out);
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('protected-token-value', $out);
        $this->assertStringNotContainsString('private-secret-value', $out);
    }

    public function testJsEscapesTagDelimiters(): void
    {
        $out = (new JsDumpRenderer())->render(['x' => '</script><b>'], DumpOptions::create());

        $this->assertStringNotContainsString('</script>', $out);
        $this->assertStringContainsString('\u003C/script\u003E', $out);
    }

    public function testColorIsDisabledForNonTerminalStream(): void
    {
        $method = new \ReflectionMethod(CliDumpRenderer::class, 'supportsColor');
        $stream = \fopen('php://memory', 'r');

        $this->assertFalse($method->invoke(null, $stream));

        \fclose($stream);
    }
}
