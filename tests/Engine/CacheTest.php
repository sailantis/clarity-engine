<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\Engine\Cache;
use Clarity\Engine\Compiler;
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

    // =========================================================================
    // Compiler-version invalidation
    // =========================================================================

    public function testCompiledFileRecordsCompilerVersion(): void
    {
        self::tpl('cver', 'ok');
        self::render('cver');

        $cache    = new Cache(TestEnvironment::cacheDir());
        $compiled = file_get_contents($cache->cacheFilePath('cver'));

        $this->assertIsString($compiled);
        $this->assertStringContainsString(
            'public static int $compilerVersion = ' . Compiler::COMPILER_VERSION . ';',
            $compiled
        );
    }

    public function testCompiledFileHasNoCompilerKeyInDependencies(): void
    {
        // $dependencies must stay a clean logicalName => revision map; the
        // version travels in its own typed property, so no synthetic key may
        // appear anywhere in the emitted source.
        self::tpl('cver_deps', 'ok');
        self::render('cver_deps');

        $cache    = new Cache(TestEnvironment::cacheDir());
        $compiled = file_get_contents($cache->cacheFilePath('cver_deps'));

        $this->assertIsString($compiled);
        $this->assertStringNotContainsString('@compiler', $compiled);
    }

    /**
     * A semantic compiler change (e.g. the reversed for-loop binding) leaves the
     * template source byte-identical, so the revision check alone would call the
     * old bytecode fresh. The stamped version must force a recompile.
     *
     * The staleness is observed from a FRESH process: the in-process path can
     * never reach the version guard (see {@see renderInFreshProcess()}).
     */
    public function testStaleCompilerVersionForcesRecompile(): void
    {
        self::tpl('cver_stale', 'compiled-ok');
        self::assertSame('compiled-ok', self::render('cver_stale'));

        $cache = new Cache(TestEnvironment::cacheDir());
        $file  = $cache->cacheFilePath('cver_stale');

        $src = file_get_contents($file);
        $this->assertIsString($src);
        $mutated = str_replace(
            'public static int $compilerVersion = ' . Compiler::COMPILER_VERSION . ';',
            'public static int $compilerVersion = 0;',
            $src,
            $count
        );
        $this->assertSame(1, $count, 'the version stamp must appear exactly once');
        $this->assertStringContainsString('compilerVersion = 0;', $mutated);
        file_put_contents($file, $mutated);

        // Note: deliberately NO invalidate() — the wrong-stamped file must stay
        // on disk so the version guard is what rejects it.
        $this->assertSame('compiled-ok', $this->renderInFreshProcess('cver_stale'));

        $this->assertStringContainsString(
            'public static int $compilerVersion = ' . Compiler::COMPILER_VERSION . ';',
            (string) file_get_contents($file),
            'Expected the stale-stamped file to be recompiled'
        );
    }

    /**
     * Cache files written before versioning existed carry no property at all;
     * they must be read as version 0 (stale) rather than crash or be assumed
     * current. This is the migration path for every existing deployment.
     */
    public function testUnversionedCompiledFileIsTreatedAsStale(): void
    {
        self::tpl('cver_unversioned', 'compiled-ok');
        self::assertSame('compiled-ok', self::render('cver_unversioned'));

        $cache = new Cache(TestEnvironment::cacheDir());
        $file  = $cache->cacheFilePath('cver_unversioned');

        $src = file_get_contents($file);
        $this->assertIsString($src);
        $stripped = str_replace(
            'public static int $compilerVersion = ' . Compiler::COMPILER_VERSION . ';',
            '',
            $src,
            $count
        );
        $this->assertSame(1, $count, 'the version stamp must appear exactly once');
        $this->assertStringNotContainsString('public static int $compilerVersion', $stripped);
        file_put_contents($file, $stripped);

        $this->assertSame('compiled-ok', $this->renderInFreshProcess('cver_unversioned'));

        $this->assertStringContainsString(
            'public static int $compilerVersion = ' . Compiler::COMPILER_VERSION . ';',
            (string) file_get_contents($file),
            'Expected an unversioned file to be recompiled'
        );
    }

    /**
     * Render a template in a completely fresh PHP process.
     *
     * Cache freshness cannot be observed in-process:
     *  - `Cache::$classNames` (static) plus OPcache short-circuit the read, and
     *  - `Cache::invalidate()` deletes the file *and* clears the registry, so any
     *    later in-process render recompiles unconditionally and never consults
     *    `isFresh()` at all.
     *
     * A child process is therefore the only faithful simulation of "the next real
     * request against the cache file currently on disk". OPcache is disabled in
     * the child so a cached script can never mask a mutant on disk.
     */
    private function renderInFreshProcess(string $templateName): string
    {
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        $this->assertIsString($autoload, 'vendor/autoload.php must exist');

        $bootstrap = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '_clarity_fresh_render.php';
        file_put_contents($bootstrap,
            "<?php\n"
                . 'require_once ' . var_export($autoload, true) . ";\n"
                . "\$engine = new \\Clarity\\ClarityEngine();\n"
                . "\$engine->setViewPath(\$argv[1])->setCachePath(\$argv[2])->setExtension('clarity.html');\n"
                . "echo \$engine->renderPartial(\$argv[3]);\n"
        );

        $cmd = \sprintf(
            '%s -d opcache.enable_cli=0 -d opcache.enable=0 %s %s %s %s 2>&1',
            \escapeshellarg(PHP_BINARY),
            \escapeshellarg($bootstrap),
            \escapeshellarg(TestEnvironment::viewDir()),
            \escapeshellarg(TestEnvironment::cacheDir()),
            \escapeshellarg($templateName)
        );

        $output = \shell_exec($cmd);
        @unlink($bootstrap);

        $this->assertIsString($output, 'the fresh process produced no output');

        return $output;
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
        // NOTE: no staleness guard currently keys off $renderBodyLine. The
        // compiler always emits the declaration (the heredoc contains
        // `public static int $renderBodyLine = 0;` and compile() rewrites its
        // value), so a *missing* declaration can only come from a hand-tampered
        // or pre-feature file. The engine tolerates that at error-mapping time
        // (staticPropertyOrDefault() → 0 → maps nothing) instead of forcing a
        // recompile. This test therefore only pins the shape of the emitted
        // declaration, not a cache guard — do not write docs claiming otherwise.
        $isolatedCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_bodyline_stale';
        $this->removeDir($isolatedCache);
        @mkdir($isolatedCache, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath($isolatedCache);
        self::tpl('bodyline_fresh', 'hello');
        $this->assertSame('hello', $engine->renderPartial('bodyline_fresh'));

        $file = glob($isolatedCache . DIRECTORY_SEPARATOR . '*/*.php')[0];
        $this->assertStringContainsString('renderBodyLine =', file_get_contents($file));

        // Stripping the declaration from the emitted class must be detectable at
        // the text level. (Nothing in Cache.php keys off it — this only proves the
        // declaration is a single, strippable occurrence; see the note above.)
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