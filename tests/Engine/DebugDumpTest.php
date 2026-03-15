<?php

declare(strict_types=1);

namespace Clarity\Tests\Engine;

use Clarity\Debug\DumpOptions;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for dump() and dd() debug functions.
 *
 * Each test creates its own isolated engine + temp dirs to avoid
 * interference from cached templates compiled under a different debug mode.
 */
class DebugDumpTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            BaseTestCase::removeDir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{TestClarityEngine, string} */
    private function createIsolatedEngine(?DumpOptions $opts = null): array
    {
        $id = \uniqid('dbg_', true);
        $viewDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_dbg_v_' . $id;
        $cacheDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_dbg_c_' . $id;
        \mkdir($viewDir, 0755, true);
        \mkdir($cacheDir, 0755, true);

        $this->tempDirs[] = $viewDir;
        $this->tempDirs[] = $cacheDir;

        $engine = new TestClarityEngine();
        $engine->setViewPath($viewDir)->setCachePath($cacheDir)->setExtension('clarity.html');

        if ($opts !== null) {
            $engine->enableDebug($opts);
        }

        return [$engine, $viewDir];
    }

    private function writeTpl(string $viewDir, string $name, string $content): string
    {
        \file_put_contents($viewDir . \DIRECTORY_SEPARATOR . $name . '.clarity.html', $content);
        return $name;
    }

    // =========================================================================
    // dump() in production mode
    // =========================================================================

    public function testDumpIsPrunedToEmptyStringInProductionMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(); // no enableDebug()

        $view = $this->writeTpl($viewDir, 'dump_prod', '{{ dump(x) }}');
        $output = $engine->renderPartial($view, ['x' => 'hello']);

        $this->assertSame('', $output, 'dump() should compile to empty string in production');
    }

    public function testDumpDoesNotLeakDataInProduction(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();

        $view = $this->writeTpl(
            $viewDir,
            'dump_noleak',
            'before{{ dump(secret) }}after'
        );
        $output = $engine->renderPartial($view, ['secret' => 'top-secret-value']);

        $this->assertSame('beforeafter', $output);
        $this->assertStringNotContainsString('top-secret-value', $output);
    }

    // =========================================================================
    // dump() in debug mode (HTML context)
    // =========================================================================

    public function testDumpRendersHtmlDetailsInDebugMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'dump_debug', '{{ dump(value) }}');
        $output = $engine->renderPartial($view, ['value' => ['foo' => 'bar']]);

        $this->assertStringContainsString('<details', $output, 'dump() should render <details> tree in debug mode');
        $this->assertStringContainsString('foo', $output);
        $this->assertStringContainsString('bar', $output);
    }

    public function testDumpRendersScalarInDebugMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'dump_scalar', '{{ dump(value) }}');
        $output = $engine->renderPartial($view, ['value' => 'hello world']);

        $this->assertStringContainsString('hello world', $output);
    }

    // =========================================================================
    // dump() in JS context
    // =========================================================================

    public function testDumpRendersJsCommentInScriptContext(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl(
            $viewDir,
            'dump_js',
            '<script>var x = 1;{{ dump(data) }}</script>'
        );
        $output = $engine->renderPartial($view, ['data' => ['key' => 'val']]);

        $this->assertStringContainsString(
            ';/* DEBUG_DUMP:',
            $output,
            'dump() should emit JS comment in <script> context'
        );
    }

    public function testDumpEscapesCommentClosingInJsContext(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl(
            $viewDir,
            'dump_js_escape',
            '<script>{{ dump(data) }}</script>'
        );
        // value contains '*/' which would break the JS comment
        $output = $engine->renderPartial($view, ['data' => 'inject */ alert(1)']);

        $this->assertStringNotContainsString(
            '*/ alert(1)',
            $output,
            'dump() must escape */ in JS comment output'
        );
    }

    // =========================================================================
    // Sensitive key masking
    // =========================================================================

    public function testDumpMasksSensitiveKeysInHtml(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl(
            $viewDir,
            'dump_mask',
            '{{ dump(user) }}'
        );
        $output = $engine->renderPartial($view, [
            'user' => ['name' => 'Alice', 'password' => 's3cr3t'],
        ]);

        $this->assertStringContainsString('Alice', $output);
        $this->assertStringNotContainsString(
            's3cr3t',
            $output,
            'password key should be masked'
        );
        $this->assertStringContainsString('***', $output);
    }

    // =========================================================================
    // Fallback dump (setDebugMode(true) without enableDebug)
    // =========================================================================

    public function testDumpFallbackWithoutEnableDebug(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();
        $engine->setDebugMode(true); // low-level toggle only — no renderers

        $view = $this->writeTpl($viewDir, 'dump_fallback', '{{ dump(x) }}');
        $output = $engine->renderPartial($view, ['x' => 'world']);

        $this->assertStringContainsString(
            '<pre',
            $output,
            'Fallback dump() should output <pre> block'
        );
        $this->assertStringContainsString('world', $output);
    }

    // =========================================================================
    // dd()
    // =========================================================================

    public function testDdCallsHandlerWithContext(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();

        // Install a throwing handler to avoid calling exit(1) in tests
        $engine->getRegistry()->setDdHandler(function (string $ctx, mixed ...$args): never {
            throw new \RuntimeException('dd_ctx:' . $ctx . ',val:' . ($args[0] ?? ''));
        });

        $view = $this->writeTpl($viewDir, 'dd_test', '{{ dd(x) }}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dd_ctx:html/');

        $engine->renderPartial($view, ['x' => 'test']);
    }

    public function testDdInJsContextPassesJsContextArg(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();

        $captured = null;
        $engine->getRegistry()->setDdHandler(function (string $ctx, mixed ...$args) use (&$captured): never {
            $captured = $ctx;
            throw new \RuntimeException('dd called');
        });

        $view = $this->writeTpl($viewDir, 'dd_js', '<script>{{ dd(x) }}</script>');

        try {
            $engine->renderPartial($view, ['x' => 'value']);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('js', $captured, 'dd() in <script> should pass "js" context');
    }

    public function testDdIsAlwaysContextInjectedInProductionMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(); // production mode

        $handlerCalled = false;
        $engine->getRegistry()->setDdHandler(function (string $ctx, mixed ...$args) use (&$handlerCalled): never {
            $handlerCalled = true;
            throw new \RuntimeException('dd handler invoked');
        });

        $view = $this->writeTpl($viewDir, 'dd_prod', '{{ dd(x) }}');

        try {
            $engine->renderPartial($view, ['x' => 1]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertTrue($handlerCalled, 'dd() handler must be called even in production mode');
    }
}
