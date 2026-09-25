<?php

namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Engine\Cache;
use Clarity\Template\ArrayLoader;
use Clarity\Tests\BaseTestCase;

/**
 * The compiled scaffold must hand back the output-buffer LEVEL it received.
 *
 * render() opens one buffer of its own and closes it on both paths, but a
 * template may legitimately open more: capturing a block with a custom
 * directive that emits `ob_start()` is the documented way to do it. If such a
 * template then throws, the catch block has to release every buffer clarity is
 * responsible for — and must not reach past them into the caller's.
 *
 * These tests therefore measure ob_get_level() across a render, with the caller
 * owning a buffer of its own, so that "released too few" (a leak) and "released
 * too many" (a theft) are both distinguishable from correct.
 */
class OutputBufferTest extends BaseTestCase
{
    private string $cacheDir = '';

    /**
     * ob_get_level() as PHPUnit left it. PHPUnit holds a buffer of its own while
     * a test runs and flags the test as risky if the level moves, so tearDown
     * must unwind to THIS level and not to zero — one of these tests deliberately
     * leaves a caller-owned buffer open.
     */
    private int $baseLevel = 0;

    protected function setUp(): void
    {
        $this->baseLevel = ob_get_level();
        $this->cacheDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_ob_' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Never let a failing assertion also leak a buffer into the next test.
        while (ob_get_level() > $this->baseLevel) {
            if (!@ob_end_clean()) {
                break;
            }
        }
        self::removeDir($this->cacheDir);
    }

    /**
     * @param array<string,string>    $templates  logical name => template source
     * @param array<string,callable>  $filters    filter name => implementation
     * @param array<string,callable>  $directives directive name => PHP emitter
     */
    private function engine(array $templates, array $filters = [], array $directives = []): ClarityEngine
    {
        $engine = new ClarityEngine();
        $engine->setLoader(new ArrayLoader($templates));
        $engine->setCachePath($this->cacheDir);

        foreach ($filters as $name => $fn) {
            $engine->addFilter($name, $fn);
        }
        foreach ($directives as $name => $fn) {
            $engine->addDirective($name, $fn);
        }

        return $engine;
    }

    /** Directive pair that buffers a block and echoes it back. */
    private function captureDirectives(): array
    {
        return [
            'capture'    => static fn(): string => 'ob_start();',
            'endcapture' => static fn(): string => 'echo ob_get_clean();',
        ];
    }

    /** A filter that always throws, so a render fails inside the render body. */
    private function explodingFilter(): callable
    {
        return static function ($value): void {
            throw new \RuntimeException('boom');
        };
    }

    private function compiledFileFor(string $view): string
    {
        $file = (new Cache($this->cacheDir))->cacheFilePath($view);
        $this->assertFileExists($file, 'the template must have been compiled to disk');

        return $file;
    }

    // =========================================================================
    // Successful renders
    // =========================================================================

    public function testSuccessfulRenderLeavesNoBufferOpen(): void
    {
        $engine = $this->engine(['ok' => 'plain body']);

        $before = ob_get_level();
        self::assertSame('plain body', $engine->render('ok', []));
        $this->assertSame($before, ob_get_level(), 'a successful render must not shift the buffer level');
    }

    public function testSuccessfulRenderWithCaptureDirectiveLeavesNoBufferOpen(): void
    {
        $engine = $this->engine(
            ['cap' => '{% capture %}inner{% endcapture %}tail'],
            [],
            $this->captureDirectives()
        );

        $before = ob_get_level();
        self::assertSame('innertail', $engine->render('cap', []));
        $this->assertSame($before, ob_get_level(), 'a balanced capture directive must close its own buffer');
    }

    // =========================================================================
    // A throwing template releases everything clarity opened
    // =========================================================================

    public function testThrowingTemplateReleasesItsOwnBuffer(): void
    {
        $engine = $this->engine(
            ['boom' => '{{ value |> explode }}'],
            ['explode' => $this->explodingFilter()]
        );

        $before = ob_get_level();
        try {
            $engine->render('boom', ['value' => 'x']);
            $this->fail('the render was expected to throw');
        } catch (ClarityException) {}

        $this->assertSame($before, ob_get_level(), 'a throwing template must release its own buffer');
    }

    /**
     * The regression this scaffold guard exists for.
     *
     * The template stacks a buffer of its own (the capture directive) and then
     * throws while it is still open. A single `ob_end_clean()` unwinds exactly
     * one level — clarity's — and strands the template's buffer, with its
     * partial output, above the caller's for the rest of the process.
     */
    public function testThrowingTemplateReleasesBuffersItStackedItself(): void
    {
        $engine = $this->engine(
            ['nested' => '{% capture %}{{ value |> explode }}{% endcapture %}'],
            ['explode' => $this->explodingFilter()],
            $this->captureDirectives()
        );

        $before = ob_get_level();
        try {
            $engine->render('nested', ['value' => 'x']);
            $this->fail('the render was expected to throw');
        } catch (ClarityException) {}

        $this->assertSame(
            $before,
            ob_get_level(),
            'every buffer the template opened must be released, not just the innermost'
        );
    }

    // =========================================================================
    // ...and does not reach past them into the caller's buffers
    // =========================================================================

    /**
     * The guard must unwind DOWN TO the level captured after ob_start(), not
     * "until the stack is empty". A caller may hold buffers of its own — an
     * outer view engine, a framework response buffer — and swallowing one would
     * discard output the caller never handed to clarity.
     */
    public function testCallersBufferSurvivesAThrowingTemplate(): void
    {
        $engine = $this->engine(
            ['nested' => '{% capture %}{{ value |> explode }}{% endcapture %}'],
            ['explode' => $this->explodingFilter()],
            $this->captureDirectives()
        );

        ob_start();
        echo 'CALLER-SENTINEL';

        // Level is measured RELATIVE to the caller's own buffer: PHPUnit (and
        // any surrounding framework) may already hold buffers of its own, so an
        // absolute ob_get_level() would assert something about the harness.
        $callerLevel = ob_get_level();

        try {
            $engine->render('nested', ['value' => 'x']);
            $this->fail('the render was expected to throw');
        } catch (ClarityException) {}

        $this->assertSame($callerLevel, ob_get_level(), 'the caller\'s own buffer must still be open');
        $this->assertStringContainsString(
            'CALLER-SENTINEL',
            (string) ob_get_contents(),
            'the caller\'s buffered output must not be discarded'
        );
    }

    // =========================================================================
    // Emitted shape
    // =========================================================================

    /**
     * The capture has to happen AFTER ob_start() — that is what bounds the drain
     * to clarity's buffer and everything the template stacked on it. Moving it
     * above ob_start() would make the drain reach the caller's buffer instead,
     * which is a silent data-loss regression rather than a visible failure.
     */
    public function testEmittedScaffoldCapturesLevelAfterOpeningItsBuffer(): void
    {
        $engine = $this->engine(['scaffold' => 'body']);
        $engine->render('scaffold', []);

        $src = (string) file_get_contents($this->compiledFileFor('scaffold'));

        $openPos    = strpos($src, 'ob_start();');
        $capturePos = strpos($src, '$__obLevel = ob_get_level();');

        $this->assertNotFalse($openPos, 'the scaffold must open a buffer');
        $this->assertNotFalse($capturePos, 'the scaffold must capture the buffer level');
        $this->assertGreaterThan(
            $openPos,
            $capturePos,
            'the level must be captured after ob_start(), or the drain would reach the caller\'s buffer'
        );
    }

    public function testEmittedScaffoldDrainsInALoopNotASingleCall(): void
    {
        $engine = $this->engine(['scaffold2' => 'body']);
        $engine->render('scaffold2', []);

        $src = (string) file_get_contents($this->compiledFileFor('scaffold2'));

        $this->assertStringContainsString(
            'while (ob_get_level() >= $__obLevel) {',
            $src,
            'the catch block must drain to the captured level, not call ob_end_clean() once'
        );
    }
}