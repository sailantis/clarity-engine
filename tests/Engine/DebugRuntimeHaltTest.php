<?php

declare(strict_types=1);

namespace Clarity\Tests\Engine;

use Clarity\Debug\DebugRuntime;
use Clarity\Debug\DumpHaltException;
use Clarity\Debug\DumpOptions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for dd() in halt mode, where it throws DumpHaltException instead of exiting.
 */
class DebugRuntimeHaltTest extends TestCase
{
    private function haltedDump(string $ctx, array $args, ?DumpOptions $opts = null): DumpHaltException
    {
        $runtime = new DebugRuntime($opts ?? DumpOptions::create()->haltWithException());

        try {
            $runtime->dumpAndDie($ctx, $args);
        } catch (DumpHaltException $e) {
            return $e;
        }

        $this->fail('dd() did not throw DumpHaltException in halt mode.');
    }

    public function testHaltModeThrowsHtmlDump(): void
    {
        $e = $this->haltedDump('html', ['value']);

        $this->assertSame('html', $e->context);
        $this->assertStringContainsString('<div class="clarity-dump">', $e->output);
        $this->assertStringContainsString('value', $e->output);
    }

    public function testHaltModeKeepsJsContext(): void
    {
        $e = $this->haltedDump('js', ['value']);

        $this->assertSame('js', $e->context);
        $this->assertStringContainsString('DEBUG_DUMP', $e->output);
    }

    public function testHaltModeKeepsCssContext(): void
    {
        $e = $this->haltedDump('css', ['value']);

        $this->assertSame('css', $e->context);
        $this->assertStringContainsString('DEBUG_DUMP', $e->output);
    }

    public function testHaltModeNormalizesUnknownContextToHtml(): void
    {
        $e = $this->haltedDump('text', ['value']);

        $this->assertSame('html', $e->context);
    }

    public function testHaltModeMasksSensitiveKeys(): void
    {
        $e = $this->haltedDump('html', [['password' => 'hunter2', 'name' => 'visible']]);

        $this->assertStringContainsString('visible', $e->output);
        $this->assertStringNotContainsString('hunter2', $e->output);
    }

    public function testHaltWithExceptionDefaultsToOff(): void
    {
        $this->assertFalse(DumpOptions::create()->getHaltWithException());
        $this->assertFalse((new DumpOptions())->getHaltWithException());
    }

    public function testHaltWithExceptionCanBeSetByConstructorAndFluently(): void
    {
        $this->assertTrue((new DumpOptions(haltWithException: true))->getHaltWithException());
        $this->assertTrue(DumpOptions::create()->haltWithException()->getHaltWithException());
        $this->assertFalse(DumpOptions::create()->haltWithException(false)->getHaltWithException());
    }

    public function testGetDebugOptionsIsNullWhenDebugModeIsOff(): void
    {
        $engine = new \Clarity\ClarityEngine();

        $this->assertNull($engine->getDebugOptions());
    }

    public function testGetDebugOptionsReturnsTheSameOptionsAcrossCalls(): void
    {
        $engine = new \Clarity\ClarityEngine();
        $engine->setDebugMode(true);

        $engine->getDebugOptions()->haltWithException(true);

        $this->assertTrue($engine->getDebugOptions()->getHaltWithException());
    }
}
