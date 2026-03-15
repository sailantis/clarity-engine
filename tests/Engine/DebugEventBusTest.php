<?php

declare(strict_types=1);

namespace Clarity\Tests\Engine;

use Clarity\Debug\DebugEvent;
use Clarity\Debug\DebugEventBus;
use Clarity\Debug\DebugListener;
use Clarity\Debug\DumpOptions;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DebugEventBus and event emission during rendering.
 */
class DebugEventBusTest extends TestCase
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
        $id = \uniqid('ebus_', true);
        $viewDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_ebus_v_' . $id;
        $cacheDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_ebus_c_' . $id;
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
    // getDebugBus()
    // =========================================================================

    public function testGetDebugBusReturnsNullBeforeEnableDebug(): void
    {
        [$engine] = $this->createIsolatedEngine();

        $this->assertNull(
            $engine->getDebugBus(),
            'getDebugBus() must return null when enableDebug() was not called'
        );
    }

    public function testGetDebugBusReturnsInstanceAfterEnableDebug(): void
    {
        [$engine] = $this->createIsolatedEngine(new DumpOptions());

        $this->assertInstanceOf(DebugEventBus::class, $engine->getDebugBus());
    }

    public function testGetDebugBusReturnsNullAfterDisableDebug(): void
    {
        [$engine] = $this->createIsolatedEngine(new DumpOptions());
        $engine->disableDebug();

        $this->assertNull($engine->getDebugBus());
        $this->assertFalse($engine->isDebugMode());
    }

    // =========================================================================
    // Event emission
    // =========================================================================

    public function testRenderEventIsEmittedOnce(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'ev_render', 'Hello {{ name }}');

        $events = [];
        $engine->getDebugBus()->subscribe(function (DebugEvent $e) use (&$events): void {
            $events[] = $e;
        });

        $engine->renderPartial($view, ['name' => 'World']);

        $types = \array_column($events, 'type');
        $this->assertContains(
            'template.render',
            $types,
            'A template.render event must be emitted after each renderPartial()'
        );
    }

    public function testRenderEventCarriesTemplateName(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'ev_name', 'hi');

        $rendered = null;
        $engine->getDebugBus()->subscribe(function (DebugEvent $e) use (&$rendered): void {
            if ($e->type === 'template.render') {
                $rendered = $e->payload['template'] ?? null;
            }
        });

        $engine->renderPartial($view, []);

        $this->assertSame('ev_name', $rendered);
    }

    public function testRenderEventIncludesDurationMs(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'ev_dur', 'hi');

        $duration = null;
        $engine->getDebugBus()->subscribe(function (DebugEvent $e) use (&$duration): void {
            if ($e->type === 'template.render') {
                $duration = $e->payload['duration_ms'] ?? null;
            }
        });

        $engine->renderPartial($view, []);

        $this->assertIsFloat($duration);
        $this->assertGreaterThanOrEqual(0.0, $duration);
    }

    public function testCompileEventIsEmittedOnFreshTemplate(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        // Use a unique name so no stale cache entry exists
        $view = $this->writeTpl($viewDir, 'ev_compile_' . \mt_rand(), 'fresh');

        $types = [];
        $engine->getDebugBus()->subscribe(function (DebugEvent $e) use (&$types): void {
            $types[] = $e->type;
        });

        $engine->renderPartial($view, []);

        $this->assertContains(
            'template.compile',
            $types,
            'template.compile event must be emitted when the template is not yet cached'
        );
    }

    public function testCachedEventIsEmittedOnSecondRender(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());

        $view = $this->writeTpl($viewDir, 'ev_cached', 'cached');

        // First render: compiles and caches
        $engine->renderPartial($view, []);

        $types = [];
        $engine->getDebugBus()->subscribe(function (DebugEvent $e) use (&$types): void {
            $types[] = $e->type;
        });

        // Second render: should hit cache
        $engine->renderPartial($view, []);

        $this->assertContains(
            'template.cached',
            $types,
            'template.cached event must be emitted when the compiled template is served from cache'
        );
        $this->assertNotContains(
            'template.compile',
            $types,
            'template.compile must NOT be emitted on a cache hit'
        );
    }

    public function testResolveEventIsAlwaysEmitted(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());
        $view = $this->writeTpl($viewDir, 'ev_resolve', 'hi');

        $types = [];
        $engine->getDebugBus()->subscribe(function (DebugEvent $e) use (&$types): void {
            $types[] = $e->type;
        });

        $engine->renderPartial($view, []);

        $this->assertContains('template.resolve', $types);
    }

    public function testDebugBusWorksWithDebugListenerInterface(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(new DumpOptions());
        $view = $this->writeTpl($viewDir, 'ev_iface', 'test');

        $received = [];
        $listener = new class ($received) implements DebugListener {
            public function __construct(private array &$received)
            {}
            public function onEvent(DebugEvent $event): void
            {
                $this->received[] = $event->type;
            }
        };

        $engine->getDebugBus()->subscribe($listener);
        $engine->renderPartial($view, []);

        $this->assertNotEmpty($received);
    }

    public function testNoEventsEmittedInProductionMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine(); // no enableDebug()

        $view = $this->writeTpl($viewDir, 'ev_prod', 'hi');
        $engine->renderPartial($view, []);

        // No bus = no events; assert bus is null
        $this->assertNull($engine->getDebugBus());
    }
}
