<?php

declare(strict_types=1);

namespace Clarity\Tests\Engine;

use Clarity\Debug\DumpOptions;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the fluent DumpOptions surface.
 *
 * The constructor sets every option, and a method of the same name adjusts it
 * in place afterwards — so an options object can be built either way, and a
 * shared one stays adjustable after the engine has been given it.
 */
class DumpOptionsTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            BaseTestCase::removeDir($dir);
        }
    }

    /** @return array{TestClarityEngine, string} */
    private function createIsolatedEngine(): array
    {
        $id = \uniqid('opts_', true);
        $viewDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_opts_v_' . $id;
        $cacheDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_opts_c_' . $id;
        \mkdir($viewDir, 0755, true);
        \mkdir($cacheDir, 0755, true);

        $this->tempDirs[] = $viewDir;
        $this->tempDirs[] = $cacheDir;

        $engine = new TestClarityEngine();
        $engine->setViewPath($viewDir)->setCachePath($cacheDir)->setExtension('clarity.html');

        return [$engine, $viewDir];
    }

    private function writeTpl(string $viewDir, string $name, string $content): string
    {
        \file_put_contents($viewDir . \DIRECTORY_SEPARATOR . $name . '.clarity.html', $content);
        return $name;
    }

    // =========================================================================
    // Defaults and constructor
    // =========================================================================

    public function testDefaultsAreExposedThroughGetters(): void
    {
        $opts = new DumpOptions();

        $this->assertSame(5, $opts->getMaxDepth());
        $this->assertSame(50, $opts->getMaxItems());
        $this->assertSame(
            ['password', 'token', 'secret', 'apikey', 'api_key'],
            $opts->getMaskKeys()
        );
        $this->assertFalse($opts->getForceToTemplate());
        $this->assertFalse($opts->getShowPanel());
    }

    public function testConstructorSetsEveryOption(): void
    {
        $opts = new DumpOptions(
            maxDepth: 2,
            maxItems: 3,
            maskKeys: ['pwd'],
            forceToTemplate: true,
            showPanel: true,
        );

        $this->assertSame(2, $opts->getMaxDepth());
        $this->assertSame(3, $opts->getMaxItems());
        $this->assertSame(['pwd'], $opts->getMaskKeys());
        $this->assertTrue($opts->getForceToTemplate());
        $this->assertTrue($opts->getShowPanel());
    }

    // =========================================================================
    // Fluent methods
    // =========================================================================

    public function testEverySetterReturnsTheSameInstanceAndAppliesInPlace(): void
    {
        $opts = new DumpOptions();

        $this->assertSame($opts, $opts->maxDepth(2));
        $this->assertSame($opts, $opts->maxItems(7));
        $this->assertSame($opts, $opts->maskKeys(['pwd']));
        $this->assertSame($opts, $opts->maskKey('pin'));
        $this->assertSame($opts, $opts->unmaskKey('pwd'));
        $this->assertSame($opts, $opts->forceToTemplate());
        $this->assertSame($opts, $opts->showPanel());

        $this->assertSame(2, $opts->getMaxDepth());
        $this->assertSame(7, $opts->getMaxItems());
        $this->assertSame(['pin'], $opts->getMaskKeys());
        $this->assertTrue($opts->getForceToTemplate());
        $this->assertTrue($opts->getShowPanel());
    }

    public function testBooleanSettersDefaultToTrueAndAcceptFalse(): void
    {
        $opts = (new DumpOptions())->showPanel()->forceToTemplate();
        $this->assertTrue($opts->getShowPanel());
        $this->assertTrue($opts->getForceToTemplate());

        $opts->showPanel(false)->forceToTemplate(false);
        $this->assertFalse($opts->getShowPanel());
        $this->assertFalse($opts->getForceToTemplate());
    }

    public function testMaskKeyAppendsAndUnmaskKeyRemovesOnlyThatEntry(): void
    {
        $opts = (new DumpOptions())->maskKey('pwd')->unmaskKey('password');

        $this->assertNotContains('password', $opts->getMaskKeys());
        $this->assertContains('token', $opts->getMaskKeys());
        $this->assertContains('pwd', $opts->getMaskKeys());
    }

    public function testUnmaskKeyForAnAbsentEntryChangesNothing(): void
    {
        $opts = new DumpOptions();
        $before = $opts->getMaskKeys();

        $opts->unmaskKey('not-there');

        $this->assertSame($before, $opts->getMaskKeys());
    }

    public function testMaskKeyListsAreAlwaysReindexed(): void
    {
        $opts = new DumpOptions(maskKeys: ['a' => 'pwd', 3 => 'pin']);

        $this->assertSame(['pwd', 'pin'], $opts->getMaskKeys());
        $this->assertSame(['x', 'y'], $opts->maskKeys(['k' => 'x', 9 => 'y'])->getMaskKeys());
    }

    // =========================================================================
    // Mutation after the engine has been given the options
    // =========================================================================

    public function testOptionsHandedToTheEngineStayAdjustable(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();
        $opts = new DumpOptions();
        $engine->setDebugMode($opts);

        $view = $this->writeTpl($viewDir, 'opts_late', '<script>{{ dump(data) }}</script>');

        // depth 5 (default): the nested array is rendered in full
        $deep = $engine->renderPartial($view, ['data' => ['nested' => ['password' => 'secret']]]);
        $this->assertStringContainsString('"nested":{"password":"***"}', \str_replace('\\/', '/', $deep));

        // the same instance, narrowed afterwards, governs the next render
        $opts->maxDepth(1)->maskKeys([]);
        $shallow = $engine->renderPartial($view, ['data' => ['nested' => ['password' => 'secret']]]);
        $this->assertStringContainsString('{"nested":"…"}', \str_replace('\\/', '/', $shallow));
    }

    public function testFluentChainCanBePassedStraightToSetDebugMode(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();

        $engine->setDebugMode((new DumpOptions())->showPanel()->maxDepth(1));

        $this->assertNotNull($engine->getDebugPanel());

        $view = $this->writeTpl($viewDir, 'opts_chain', '<script>{{ dump(data) }}</script>');
        $output = $engine->renderPartial($view, ['data' => ['nested' => ['x' => 1]]]);

        $this->assertStringContainsString('{"nested":"…"}', $output);
    }

    public function testPanelFollowsTheOptionAsItIsWhenTheEngineIsGivenIt(): void
    {
        [$engine] = $this->createIsolatedEngine();

        $engine->setDebugMode((new DumpOptions(showPanel: true))->showPanel(false));
        $this->assertNull($engine->getDebugPanel());

        $engine->setDebugMode((new DumpOptions())->showPanel());
        $this->assertNotNull($engine->getDebugPanel());
    }

    public function testUnmaskedKeyIsRenderedByTheHtmlRenderer(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();

        $engine->setDebugMode((new DumpOptions())->unmaskKey('password'));
        $view = $this->writeTpl($viewDir, 'opts_unmask', '{{ dump(data) }}');

        $output = $engine->renderPartial($view, ['data' => ['password' => 'visible']]);

        $this->assertStringContainsString('visible', $output);
        $this->assertStringNotContainsString('<span class="cd-masked">', $output);
    }

    public function testMaskedKeyStillMaskedAfterFluentChain(): void
    {
        [$engine, $viewDir] = $this->createIsolatedEngine();

        $engine->setDebugMode((new DumpOptions())->maskKey('pwd'));
        $view = $this->writeTpl($viewDir, 'opts_mask', '{{ dump(data) }}');

        $output = $engine->renderPartial($view, ['data' => ['pwd' => 'hidden', 'user' => 'admin']]);

        $this->assertStringContainsString('<span class="cd-masked">', $output);
        $this->assertStringNotContainsString('hidden', $output);
        $this->assertStringContainsString('admin', $output);
    }
}
