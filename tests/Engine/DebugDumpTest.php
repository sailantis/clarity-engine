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
    // setDebugMode() is the one debug switch (enableDebug() is an alias)
    // =========================================================================

    public function testSetDebugModeInstallsTheFullDebugExperience(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();
        $engine->setDebugMode(true); // the former "low-level toggle"

        $view = $this->writeTpl($viewDir, 'dump_toggle', '{{ dump(x) }}');
        $output = $engine->renderPartial($view, ['x' => 'world']);

        // The context-aware renderer, not a print_r fallback …
        $this->assertStringContainsString('clarity-dump', $output);
        $this->assertStringContainsString('world', $output);
        // … and the debug bus came with it.
        $this->assertNotNull($engine->getDebugBus());
    }

    public function testSetDebugModeMasksSecretsLikeEnableDebug(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();
        $engine->setDebugMode(true);

        $view = $this->writeTpl($viewDir, 'dump_toggle_mask', '{{ dump(user) }}');
        $output = $engine->renderPartial($view, ['user' => ['name' => 'Alice', 'password' => 's3cr3t']]);

        $this->assertStringContainsString('Alice', $output);
        $this->assertStringNotContainsString('s3cr3t', $output);
        $this->assertStringContainsString('***', $output);
    }

    public function testSetDebugModeAcceptsDumpOptions(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();
        $engine->setDebugMode(new DumpOptions(showPanel: true));

        $this->assertNotNull($engine->getDebugPanel());
    }

    public function testFalseTearsEverythingDown(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();
        $engine->setDebugMode(new DumpOptions(showPanel: true));
        $engine->setDebugMode(false);

        $this->assertFalse($engine->isDebugMode());
        $this->assertNull($engine->getDebugBus());
        $this->assertNull($engine->getDebugPanel());

        $view = $this->writeTpl($viewDir, 'dump_toggle_off', 'before[{{ dump(x) }}]after');
        $this->assertSame('before[]after', $engine->renderPartial($view, ['x' => 'secret']));
    }

    public function testTurningDebugOffRemovesTheDebugFormatters(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();
        $engine->enableDebug();           // installs the renderer + dd handler
        $engine->setDebugMode(false);     // …and this must take both away again

        // dump() must not keep the old renderer alive …
        $view = $this->writeTpl($viewDir, 'dump_off_dump', 'before[{{ dump(user) }}]after');
        $this->assertSame(
            'before[]after',
            $engine->renderPartial($view, ['user' => ['password' => 's3cr3t']])
        );

        // … and dd() must refuse rather than still dumping.
        $dd = $this->writeTpl($viewDir, 'dump_off_dd', '{{ dd(user) }}');
        $this->expectExceptionMessageMatches('/dd\(\) requires debug mode/');
        $engine->renderPartial($dd, ['user' => ['password' => 's3cr3t']]);
    }

    public function testDumpIsANoopWithoutDebugEvenThroughAReference(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(); // no debug

        $view = $this->writeTpl($viewDir, 'dump_ref_prod', '{{ map(items, "dump") |> join(",") }}');

        $this->assertSame('a,b', $engine->renderPartial($view, ['items' => ['a', 'b']]));
    }

    // =========================================================================
    // dump() as a filter (pass-through probe)
    // =========================================================================

    public function testDumpFilterPassesTheValueThrough(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'dump_pipe_through', '{{ items |> dump |> length }}');
        $output = $engine->renderPartial($view, ['items' => [1, 2, 3]]);

        // The dump is emitted, and the piped value still reaches `length`.
        $this->assertStringContainsString('clarity-dump', $output);
        $this->assertStringEndsWith('3', $output, 'the piped value must survive the dump step');
    }

    public function testDumpFilterRendersTheDumpedValue(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'dump_pipe_render', '{{ value |> dump |> length }}');
        $output = $engine->renderPartial($view, ['value' => ['foo' => 'bar']]);

        // The dump is emitted at the pipe position, and the value flows on.
        $this->assertStringContainsString('<details', $output);
        $this->assertStringContainsString('foo', $output);
        $this->assertStringEndsWith('1', $output);
    }

    public function testDumpFilterIsEliminatedInProduction(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(); // no enableDebug()

        $view = $this->writeTpl(
            $viewDir,
            'dump_pipe_prod',
            'before[{{ secret |> dump }}]after'
        );
        $output = $engine->renderPartial($view, ['secret' => 'top-secret-value']);

        $this->assertSame('before[top-secret-value]after', $output);
    }

    public function testDumpFilterEmitsJsCommentInScriptContext(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl(
            $viewDir,
            'dump_pipe_js',
            '<script>var n = 1;{{ x |> dump }}var m = 2;</script>'
        );
        $output = $engine->renderPartial($view, ['x' => ['k' => 'v']]);

        $this->assertStringContainsString('DEBUG_DUMP', $output);
        // The value itself is rendered in the JS context (json_encode), not
        // dropped or string-converted.
        $this->assertStringContainsString('{"k":"v"}', $output);
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

    public function testDdUsesAHandlerInstalledWithoutDebugMode(): void
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

        $this->assertTrue($handlerCalled, 'an installed dd handler must be used — dd() is never pruned');
    }

    public function testDdRefusesWithoutDebugMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(); // production mode, no handler

        $view = $this->writeTpl($viewDir, 'dd_prod_bare', '{{ dd(x) }}');

        // dd() is never pruned, so production must fail loudly rather than fall
        // back to an unmasked var_dump.
        $this->expectExceptionMessageMatches('/dd\(\) requires debug mode/');

        $engine->renderPartial($view, ['x' => 1]);
    }
}
