<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\Engine\Cache;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

class CacheTest extends BaseTestCase
{
    public function testCacheFileCreatedOnRender(): void
    {
        $cacheDir = TestEnvironment::cacheDir();
        $this->removeDir($cacheDir);
        self::tpl('cache1', 'ok');

        // render will compile and write cache
        $out = self::render('cache1');
        $this->assertSame('ok', $out);

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $files[] = $f->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'Expected at least one cache file to be written');
    }

    public function testFlushCacheRemovesFiles(): void
    {
        $cacheDir = TestEnvironment::cacheDir();
        $this->removeDir($cacheDir);
        self::tpl('cache2', 'v');
        self::render('cache2');

        $this->assertNotEmpty(glob($cacheDir . '/*/*'), 'cache should contain files');

        TestEnvironment::engine()->flushCache();

        $exists = false;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $exists = true;
            break;
        }

        $this->assertFalse($exists, 'cache directory should be empty after flush');
    }

    // =========================================================================
    // Cache correctness
    // =========================================================================

    public function testCacheHitProducesSameOutput(): void
    {
        self::tpl('cached', 'Value={{ val }}');
        $first = self::render('cached', ['val' => 'A']);
        $second = self::render('cached', ['val' => 'A']);
        $this->assertSame($first, $second);
    }

    public function testCacheFileIsCreated(): void
    {
        self::tpl('cachefile', 'hi');
        self::render('cachefile');

        $sourcePath = self::normalizedSourcePath('cachefile');
        $cache = new Cache(TestEnvironment::cacheDir());
        $this->assertTrue($cache->isFresh($sourcePath), 'Expected a fresh cache entry after first render');
    }

    public function testInlineFilterCompilesWithoutRuntimeRegistryLookup(): void
    {
        self::tpl('inline_upper_cache', '{{ name |> upper }}');
        self::render('inline_upper_cache', ['name' => 'alice']);

        $sourcePath = self::normalizedSourcePath('inline_upper_cache');
        $cache = new Cache(TestEnvironment::cacheDir());
        $compiled = file_get_contents($cache->cacheFilePath($sourcePath));

        $this->assertIsString($compiled);
        $this->assertStringContainsString('mb_strtoupper', $compiled);
        $this->assertStringNotContainsString("__fl['upper']", $compiled);
    }

    public function testCacheInvalidatedOnTemplateChange(): void
    {
        $file = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'changing.clarity.html';
        file_put_contents($file, 'first');
        touch($file, time() - 100);

        $sourcePath = self::normalizedSourcePath('changing');
        $cache = new Cache(TestEnvironment::cacheDir());
        $cache->invalidate($sourcePath);

        $this->assertSame('first', self::render('changing'));
        $this->assertTrue($cache->isFresh($sourcePath));

        file_put_contents($file, 'second');
        touch($file, filemtime($file) + 2);

        $this->assertFalse($cache->isFresh($sourcePath));
        $this->assertSame('second', self::render('changing'));
    }

    public function testClassNameForIsDeterministic(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        $path = '/some/template.clarity.html';
        $this->assertSame($cache->classNameFor($path), $cache->classNameFor($path));
        $this->assertSame('__Clarity_' . md5($path), $cache->classNameFor($path));
    }

    public function testClassNameForIsUniquePerPath(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        $this->assertNotSame(
            $cache->classNameFor('/a/template.clarity.html'),
            $cache->classNameFor('/b/template.clarity.html')
        );
    }

    public function testCacheIsFreshAfterFirstRender(): void
    {
        self::tpl('freshtest', 'ok');
        self::render('freshtest');

        $sourcePath = self::normalizedSourcePath('freshtest');
        $cache = new Cache(TestEnvironment::cacheDir());
        $this->assertTrue($cache->isFresh($sourcePath));
    }

    /**
     * Uses a private, isolated cache directory so that flushing does not
     * destroy the shared cache files that all other tests rely on across runs.
     */
    public function testFlushCacheRemovesCachedFiles(): void
    {
        $isolatedCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_flush_isolated';
        @mkdir($isolatedCache, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath($isolatedCache);

        self::tpl('flush_me', 'hi');
        $engine->renderPartial('flush_me');
        $engine->flushCache();

        $files = glob($isolatedCache . DIRECTORY_SEPARATOR . '*.php');
        $this->assertEmpty($files);

        @rmdir($isolatedCache);
    }

}
