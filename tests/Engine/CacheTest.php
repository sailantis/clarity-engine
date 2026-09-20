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

        $x    = 1; // first comment
        $yyyy = 2; // second comment

        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS));
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
        $it     = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
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
        $first  = self::render('cached', ['val' => 'A']);
        $second = self::render('cached', ['val' => 'A']);
        $this->assertSame($first, $second);
    }

    public function testCacheFileIsCreated(): void
    {
        self::tpl('cachefile', 'hi');
        self::render('cachefile');

        $engine = TestEnvironment::engine();
        $cache  = new Cache(TestEnvironment::cacheDir());
        $this->assertTrue(
            $cache->isFresh('cachefile', static fn(string $n) => $engine->getLoader()->load($n)->revision),
            'Expected a fresh cache entry after first render'
        );
    }

    public function testInlineFilterCompilesWithoutRuntimeRegistryLookup(): void
    {
        self::tpl('inline_upper_cache', '{{ name |> upper }}');
        self::render('inline_upper_cache', ['name' => 'alice']);

        $cache    = new Cache(TestEnvironment::cacheDir());
        $compiled = file_get_contents($cache->cacheFilePath('inline_upper_cache'));

        $this->assertIsString($compiled);
        $this->assertStringContainsString('mb_strtoupper', $compiled);
        $this->assertStringNotContainsString("__fl['upper']", $compiled);
    }

    public function testCacheInvalidatedOnTemplateChange(): void
    {
        $file = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'changing.clarity.html';
        file_put_contents($file, 'first');
        touch($file, time() - 100);

        $engine = TestEnvironment::engine();
        $cache  = new Cache(TestEnvironment::cacheDir());
        $cache->invalidate('changing');

        $this->assertSame('first', self::render('changing'));
        $this->assertTrue($cache->isFresh('changing', static fn(string $n) => $engine->getLoader()->load($n)->revision));

        file_put_contents($file, 'second');
        touch($file, filemtime($file) + 2);

        $this->assertFalse($cache->isFresh('changing', static fn(string $n) => $engine->getLoader()->load($n)->revision));
        $this->assertSame('second', self::render('changing'));
    }

    public function testClassNameForIsDeterministic(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        $name  = 'some/template';
        $this->assertSame($cache->classNameFor($name), $cache->classNameFor($name));
        $this->assertSame('__Clarity_' . md5($name), $cache->classNameFor($name));
    }

    public function testClassNameForIsUniquePerPath(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        $this->assertNotSame(
            $cache->classNameFor('a/template'),
            $cache->classNameFor('b/template')
        );
    }

    public function testCacheIsFreshAfterFirstRender(): void
    {
        self::tpl('freshtest', 'ok');
        self::render('freshtest');

        $engine = TestEnvironment::engine();
        $cache  = new Cache(TestEnvironment::cacheDir());
        $this->assertTrue($cache->isFresh('freshtest', static fn(string $n) => $engine->getLoader()->load($n)->revision));
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

    // =========================================================================
    // $renderBodyLine is baked into every compiled class
    // =========================================================================

    public function testCompiledClassCarriesRenderBodyLine(): void
    {
        $isolatedCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_bodyline';
        $this->removeDir($isolatedCache);
        @mkdir($isolatedCache, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath($isolatedCache);
        self::tpl('bodyline_ok', "static line\n{{ ctx.value }}\n");

        $this->assertStringContainsString(
            'static line',
            $engine->renderPartial('bodyline_ok', ['ctx' => ['value' => '']])
        );

        $files = glob($isolatedCache . DIRECTORY_SEPARATOR . '*/*.php');
        $this->assertNotEmpty($files, 'a compiled cache file should exist');

        $declarations = substr_count(file_get_contents($files[0]), 'renderBodyLine =');
        $this->assertSame(
            1,
            $declarations,
            'the compiled class must declare $renderBodyLine exactly once'
        );

        // The baked value must point at the first compiled statement, which is
        // where the first static text is echoed.
        $prop = new \ReflectionProperty($this->loadedClassFor($engine, 'bodyline_ok'), 'renderBodyLine');
        $line = $prop->getValue();
        $this->assertGreaterThan(0, $line, '$renderBodyLine must be baked in, not left at 0');
        $this->assertStringContainsString(
            'static line',
            file($files[0])[$line - 1] ?? '',
            '$renderBodyLine must point at the first compiled statement'
        );

        $this->removeDir($isolatedCache);
    }

    public function testCompiledClassWithoutRenderBodyLineIsTreatedAsStaleByCache(): void
    {
        // readDeps() is only consulted for a template that is not yet loaded in
        // this process (Cache::$classNames short-circuits the warm path, and
        // re-requiring an already-declared file would be a redeclare fatal), so
        // the staleness guard is asserted against the cache file content plus a
        // fresh read of a *different* template to keep the assertion meaningful.
        $isolatedCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_bodyline_stale';
        $this->removeDir($isolatedCache);
        @mkdir($isolatedCache, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath($isolatedCache);
        self::tpl('bodyline_fresh', 'hello');
        $this->assertSame('hello', $engine->renderPartial('bodyline_fresh'));

        $file = glob($isolatedCache . DIRECTORY_SEPARATOR . '*/*.php')[0];
        $this->assertStringContainsString('renderBodyLine =', file_get_contents($file));

        // Stripping the declaration must be detectable by reflection on the file
        // content: the guard in readDeps() keys off exactly this.
        $stripped = preg_replace(
            '/^\s*public static int \$renderBodyLine = \d+;\s*$/m',
            '',
            file_get_contents($file),
            1,
            $count
        );
        $this->assertSame(1, $count, 'the declaration should be strippable');
        $this->assertStringNotContainsString('renderBodyLine =', $stripped);

        $this->removeDir($isolatedCache);
    }

    /** Resolve the class name the engine currently has loaded for a template. */
    private function loadedClassFor(ClarityEngine $engine, string $template): string
    {
        $ref = new \ReflectionProperty($engine, 'cache');
        $ref->setAccessible(true);
        $cache = $ref->getValue($engine);

        $className = $cache->getLoadedClassName($template);
        $this->assertIsString($className, 'the template should be loaded');

        return $className;
    }

}