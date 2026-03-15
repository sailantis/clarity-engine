<?php

declare(strict_types=1);

namespace Clarity\Tests\Engine;

use Clarity\Debug\DumpOptions;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the HTML debug panel injected into render() output.
 */
class DebugPanelTest extends TestCase
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
    // Helper
    // -------------------------------------------------------------------------

    /** @return array{TestClarityEngine, string} */
    private function createIsolatedEngine(?DumpOptions $opts = null): array
    {
        $id = \uniqid('panel_', true);
        $viewDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_panel_v_' . $id;
        $cacheDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_panel_c_' . $id;
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
    // Panel absent in production
    // =========================================================================

    public function testPanelAbsentInProductionMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(); // no enableDebug()

        $view = $this->writeTpl($viewDir, 'panel_prod', '<p>hello</p>');
        $output = $engine->render($view);

        $this->assertStringNotContainsString(
            'clarity-debug-panel',
            $output,
            'No debug panel should appear in production mode'
        );
    }

    public function testPanelAbsentWhenShowPanelFalse(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions(showPanel: false));

        $view = $this->writeTpl($viewDir, 'panel_off', '<p>hello</p>');
        $output = $engine->render($view);

        $this->assertStringNotContainsString(
            'clarity-debug-panel',
            $output,
            'No panel when showPanel is false'
        );
    }

    public function testGetDebugPanelReturnsNullWhenShowPanelFalse(): void
    {
        [$engine] = $this->createIsolatedEngine(new DumpOptions(showPanel: false));

        $this->assertNull($engine->getDebugPanel());
    }

    // =========================================================================
    // Panel injected in debug mode with showPanel: true
    // =========================================================================

    public function testPanelInjectedIntoOutputWhenShowPanelTrue(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions(showPanel: true));

        $view = $this->writeTpl($viewDir, 'panel_on', '<p>content</p>');
        $output = $engine->render($view);

        $this->assertStringContainsString(
            'clarity-debug-panel',
            $output,
            'HTML debug panel must be appended to render() output when showPanel is true'
        );
    }

    public function testPanelContainsEventInfo(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions(showPanel: true));

        $view = $this->writeTpl($viewDir, 'panel_events', 'hi');
        $output = $engine->render($view);

        // Panel must show at least one event (template.render or template.compile)
        $this->assertMatchesRegularExpression(
            '/template\.(render|compile|cached)/',
            $output,
            'Debug panel must display emitted event types'
        );
    }

    public function testPanelIsOnlyAppendedByRenderNotRenderPartial(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions(showPanel: true));

        $view = $this->writeTpl($viewDir, 'panel_partial', 'partial');

        // renderPartial() should NOT append the panel
        $partial = $engine->renderPartial($view);
        $this->assertStringNotContainsString(
            'clarity-debug-panel',
            $partial,
            'renderPartial() must not inject the debug panel'
        );

        // render() should append it
        $full = $engine->render($view);
        $this->assertStringContainsString('clarity-debug-panel', $full);
    }

    public function testPanelContentIsNotDoubleInjectedOnSecondRender(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions(showPanel: true));

        $view = $this->writeTpl($viewDir, 'panel_double', 'hello');

        $output1 = $engine->render($view);
        $output2 = $engine->render($view);

        // Count the div-id attribute only (to avoid counting the CSS selector)
        $count1 = \substr_count($output1, 'id="clarity-debug-panel"');
        $count2 = \substr_count($output2, 'id="clarity-debug-panel"');

        $this->assertSame(1, $count1, 'Panel must appear exactly once per render() call');
        $this->assertSame(1, $count2, 'Panel must appear exactly once per render() call');
    }

    public function testGetDebugPanelReturnsInstanceWhenShowPanelTrue(): void
    {
        [$engine] = $this->createIsolatedEngine(new DumpOptions(showPanel: true));

        $this->assertNotNull($engine->getDebugPanel());
    }

    public function testDisableDebugRemovesPanel(): void
    {
        [$engine] = $this->createIsolatedEngine(new DumpOptions(showPanel: true));
        $this->assertNotNull($engine->getDebugPanel());

        $engine->disableDebug();
        $this->assertNull($engine->getDebugPanel());
    }
}
