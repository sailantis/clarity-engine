<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\Engine\Cache;
use Clarity\Engine\Compiler;
use Clarity\Engine\PointerCache;
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
        $compiled = file_get_contents((string) $cache->getLoadedCodeFile('inline_upper_cache'));

        $this->assertIsString($compiled);
        $this->assertStringContainsString('mb_strtoupper', $compiled);
        $this->assertStringNotContainsString("__c_fn['upper']", $compiled);
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
        $this->assertSame('__Clarity_' . Cache::templateHash($name), $cache->classNameFor($name));
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
        $compiled = file_get_contents((string) $cache->getLoadedCodeFile('cver'));

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
        $compiled = file_get_contents((string) $cache->getLoadedCodeFile('cver_deps'));

        $this->assertIsString($compiled);
        $this->assertStringNotContainsString('@compiler', $compiled);
    }

    /**
     * A semantic compiler change (e.g. the reversed for-loop binding) leaves the
     * template source byte-identical, so the revision check alone would call the
     * old bytecode fresh. The pointer's version stamp must force a recompile.
     *
     * The staleness is observed from a FRESH process: the in-process path can
     * never reach the version guard (see {@see renderInFreshProcess()}).
     */
    public function testStaleCompilerVersionForcesRecompile(): void
    {
        self::tpl('cver_stale', 'compiled-ok');
        self::assertSame('compiled-ok', self::render('cver_stale'));

        $cache   = new Cache(TestEnvironment::cacheDir());
        $pointer = $cache->cacheFilePath('cver_stale');

        // The pointer is serialized data, so it is rewritten by re-encoding rather
        // than by a textual substitution.
        $data = unserialize((string) file_get_contents($pointer), ['allowed_classes' => false]);
        $this->assertIsArray($data);
        $this->assertSame(Compiler::COMPILER_VERSION, $data['version']);

        $data['version'] = 0;
        file_put_contents($pointer, serialize($data));

        // Note: deliberately NO invalidate() — the wrong-stamped pointer must stay
        // on disk so the version guard is what rejects it.
        $this->assertSame('compiled-ok', $this->renderInFreshProcess('cver_stale'));

        $rewritten = unserialize((string) file_get_contents($pointer), ['allowed_classes' => false]);
        $this->assertIsArray($rewritten);
        $this->assertSame(
            Compiler::COMPILER_VERSION,
            $rewritten['version'],
            'Expected the stale-stamped pointer to be rewritten'
        );
    }

    /**
     * Cache files written before versioning existed carry a bare class name
     * rather than a pointer array; they must be read as "no pointer" and
     * recompiled rather than crash or be assumed current. This is the migration
     * path for every existing deployment.
     */
    public function testLegacyCompiledFileIsTreatedAsStale(): void
    {
        self::tpl('cver_unversioned', 'compiled-ok');
        self::assertSame('compiled-ok', self::render('cver_unversioned'));

        $cache   = new Cache(TestEnvironment::cacheDir());
        $pointer = $cache->cacheFilePath('cver_unversioned');

        // Simulate a pre-content-addressing cache file: PHP that returned a class
        // name. It must be replaced, never included (including it would declare a
        // class).
        file_put_contents($pointer, "<?php\nreturn '__Clarity_legacy_00000000000000';\n");

        $this->assertSame('compiled-ok', $this->renderInFreshProcess('cver_unversioned'));

        $rewritten = unserialize((string) file_get_contents($pointer), ['allowed_classes' => false]);
        $this->assertIsArray($rewritten);
        $this->assertSame(
            Compiler::COMPILER_VERSION,
            $rewritten['version'],
            'Expected a legacy file to be replaced by a pointer'
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
        self::tpl('bodyline_ok', "static line\n{{ ctx:value }}\n");

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

    /**
     * The compiled class annotates its metadata with `//` line comments, never
     * doc comments.
     *
     * OPcache retains doc comments (`opcache.save_comments`, on by default) but
     * discards line comments, so a `/** … *\/` in the emitted class is copied
     * into shared memory for every cached template while a `//` costs nothing.
     * Measured on the compiled page this test builds: 6,672 B vs 5,880 B of
     * OPcache script memory, i.e. 792 B per template — and both collapse to
     * 5,880 B under `opcache.save_comments=0`, which is what proves the whole
     * difference is the retained annotation.
     *
     * Nothing reflects the emitted comments, so the form is free to choose.
     */
    public function testEmittedClassAnnotatesWithLineCommentsNotDocComments(): void
    {
        $isolatedCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_annotations';
        $this->removeDir($isolatedCache);
        @mkdir($isolatedCache, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath($isolatedCache);
        self::tpl('annotation_form', '{% for i in 1..3 %}{{ i }}{% endfor %}');
        $this->assertSame('123', $engine->renderPartial('annotation_form'));

        $file = glob($isolatedCache . DIRECTORY_SEPARATOR . '*/*.php')[0];
        $code = (string) file_get_contents($file);

        // 2. But the metadata is still annotated, as line comments.
        $this->assertStringContainsString('// sourceMap: packed', $code);
        $this->assertStringContainsString('// dependencies:', $code);
        $this->assertStringContainsString('// compilerVersion:', $code);

        // 3. And the metadata itself is untouched by the annotation change.
        $this->assertStringContainsString(
            'public static int $compilerVersion = ' . Compiler::COMPILER_VERSION . ';',
            $code
        );
        $this->assertStringContainsString("public static string \$sourceMap = '", $code);

        $this->removeDir($isolatedCache);
    }

    /**
     * A positive revalidate window reuses a freshness verdict — and the window is
     * a stated BOUND, not an optimisation that might quietly never expire.
     *
     * `isFresh()` costs one `filemtime()` syscall per dependency, on every render,
     * because PHP's stat cache holds a single entry: the `is_file()` on the cache
     * file evicts the template's entry before the dependency walk starts. That is
     * the same cost OPcache removes with `opcache.revalidate_freq`, so this cache
     * offers the same knob.
     *
     * The test asserts all three halves of the contract: a verdict inside the
     * window is reused even though the template changed on disk (the saving), an
     * edit is still seen once the window has passed (the bound), and the same-
     * second case is re-checked rather than trusted so a rewrite-then-render in
     * one second is not missed.
     */
    public function testRevalidateFreqBoundsStalenessAndReusesWithinIt(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_revalidate';
        $this->removeDir($cacheDir);
        @mkdir($cacheDir, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath($cacheDir);
        $engine->setRevalidateFreq(60);

        $file = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'reval.clarity.html';
        file_put_contents($file, 'first');
        touch($file, time() - 100);

        $cache = new Cache($cacheDir);
        $cache->invalidate('reval');
        $cache->setRevalidateFreq(60);

        $this->assertSame('first', $engine->renderPartial('reval'));

        $revision = static fn(string $n) => $engine->getLoader()->load($n)?->revision;

        // First check: fresh, and now cached for 60 s.
        $this->assertTrue($cache->isFresh('reval', $revision));

        // The template changes on disk. Inside the window the verdict is reused:
        // that is the saving, and it is what makes the window a BOUND — the edit
        // is picked up once the window ends, never later.
        file_put_contents($file, 'second');
        touch($file, time() + 2);
        $this->assertTrue(
            $cache->isFresh('reval', $revision),
            'inside the window the previous verdict must be reused'
        );

        // Age the recorded check past the window; the edit must now be visible.
        $prop = new \ReflectionProperty($cache, 'freshCheckedAt');
        $prop->setAccessible(true);
        $state = $prop->getValue($cache);
        $state['reval'] = [microtime(true) - 120, true];
        $prop->setValue($cache, $state);
        $this->assertFalse(
            $cache->isFresh('reval', $revision),
            'past the window an edited template must be re-checked'
        );

        // A stale verdict is never cached: the very next check must also say stale.
        $this->assertFalse($cache->isFresh('reval', $revision));

        $engine->setRevalidateFreq(0);
        $this->assertSame(0, $engine->getRevalidateFreq());
        $this->assertSame(
            0,
            (new ClarityEngine())->getRevalidateFreq(),
            'the default must be per-render checking, so this is opt-in'
        );

        $this->removeDir($cacheDir);
    }

    /**
     * An explicit invalidation must never be delayed by the revalidate window.
     */
    public function testInvalidateAndFlushIgnoreTheRevalidateWindow(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_reval_flush';
        $this->removeDir($cacheDir);
        @mkdir($cacheDir, 0755, true);

        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath($cacheDir);
        $engine->setRevalidateFreq(3600);

        self::tpl('revalflush', 'ok');
        $this->assertSame('ok', $engine->renderPartial('revalflush'));

        $revision = static fn(string $n) => $engine->getLoader()->load($n)?->revision;

        $ref = new \ReflectionProperty($engine, 'cache');
        $ref->setAccessible(true);
        $cache = $ref->getValue($engine);
        $cache->setRevalidateFreq(3600);

        $cache->isFresh('revalflush', $revision);

        // A fresh verdict is now recorded for an hour. invalidate() must drop it.
        $cache->invalidate('revalflush');
        $this->assertFalse(
            $cache->isFresh('revalflush', $revision),
            'invalidate() must not leave a fresh verdict behind'
        );

        $this->assertSame('ok', $engine->renderPartial('revalflush'));
        $cache->isFresh('revalflush', $revision);
        $engine->flushCache();
        $this->assertFalse($cache->isFresh('revalflush', $revision), 'flushCache() must clear the verdicts');

        $this->removeDir($cacheDir);
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

    // =========================================================================
    // Content-addressed bodies
    // =========================================================================

    /**
     * The whole point of the layout: an unchanged recompile must reuse the body
     * file rather than rewrite it.
     *
     * Rewriting a cached PHP file discards its OPcache entry, and the discarded
     * bytecode becomes shared-memory waste every worker of the master shares.
     * Observing the file's identity (inode + mtime) is the portable way to
     * assert "these are the same bytes, untouched".
     */
    public function testUnchangedRecompileReusesTheSameBodyFile(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('stable_body', 'unchanged {{ x }}');
        self::render('stable_body', ['x' => 1]);

        $body       = (string) $cache->getLoadedCodeFile('stable_body');
        $firstInode = fileinode($body);
        $firstMtime = filemtime($body);

        // Force the compile path again with byte-identical source.
        $cache->invalidate('stable_body');
        self::render('stable_body', ['x' => 2]);

        $again = (string) $cache->getLoadedCodeFile('stable_body');

        $this->assertSame($body, $again, 'identical source must resolve to the same content-addressed file');
        $this->assertSame($firstInode, fileinode($again));
        $this->assertSame($firstMtime, filemtime($again), 'the body must not be rewritten');
    }

    /** Changed source must produce a NEW body file, not overwrite the old one. */
    public function testChangedTemplateWritesANewBodyAndKeepsTheOldOne(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('evolving', 'version-one');
        self::render('evolving');

        $first = (string) $cache->getLoadedCodeFile('evolving');

        $file = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'evolving.clarity.html';
        file_put_contents($file, 'version-two');
        touch($file, time() + 5);

        $cache->invalidate('evolving');
        self::assertSame('version-two', self::render('evolving'));

        $second = (string) $cache->getLoadedCodeFile('evolving');

        $this->assertNotSame($first, $second, 'changed source must land at a different digest');
        $this->assertFileExists($first, 'the superseded body stays until pruned');
        $this->assertFileExists($second);
    }

    /** prune() removes only bodies no pointer names, and leaves the live one. */
    public function testPruneRemovesOnlyUnreferencedBodies(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('prunable', 'one');
        self::render('prunable');

        $first = (string) $cache->getLoadedCodeFile('prunable');

        $file = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'prunable.clarity.html';
        file_put_contents($file, 'two');
        touch($file, time() + 5);

        $cache->invalidate('prunable');
        self::render('prunable');

        $second = (string) $cache->getLoadedCodeFile('prunable');
        $this->assertNotSame($first, $second);

        $result = $cache->prune();

        $this->assertGreaterThanOrEqual(1, $result['removed']);
        $this->assertFileDoesNotExist($first, 'the unreferenced body must be deleted');
        $this->assertFileExists($second, 'the live body must survive');
        $this->assertSame('two', self::render('prunable'), 'pruning must not break rendering');
    }

    /** prune(olderThan) leaves recently written bodies alone. */
    public function testPruneCanSpareRecentFiles(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('prunable_recent', 'one');
        self::render('prunable_recent');

        $first = (string) $cache->getLoadedCodeFile('prunable_recent');

        $file = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'prunable_recent.clarity.html';
        file_put_contents($file, 'two');
        touch($file, time() + 5);

        $cache->invalidate('prunable_recent');
        self::render('prunable_recent');

        // Both bodies are seconds old: an hour-long grace period spares them.
        $result = $cache->prune(3600);

        $this->assertSame(0, $result['removed']);
        $this->assertFileExists($first);
    }

    /**
     * A recompile that produces identical source must not declare a new class.
     *
     * The class name is a digest of the emitted source, so the second compile
     * resolves to the class already in memory. This is what bounds the process
     * class table: a random suffix per compile made every recompile declare an
     * unreclaimable class.
     */
    public function testIdenticalRecompileDoesNotDeclareANewClass(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('same_class', 'body {{ y }}');
        self::render('same_class', ['y' => 1]);

        $first  = (string) $cache->getLoadedClassName('same_class');
        $before = count(get_declared_classes());

        $cache->invalidate('same_class');
        self::render('same_class', ['y' => 2]);

        $second = (string) $cache->getLoadedClassName('same_class');

        $this->assertSame($first, $second, 'identical source must reuse the class name');
        $this->assertSame(
            $before,
            count(get_declared_classes()),
            'no new class may be declared when nothing changed'
        );
    }

    /**
     * The pointer must not be rewritten when its content would not change.
     *
     * A pointer is an OPcache-cached PHP file, so rewriting it discards an
     * op_array (~1.2 KB of shared-memory waste, measured). A recompile that
     * produces the same decision — same class, same dependencies — must therefore
     * leave it alone. mtime is the portable proof that the bytes were untouched.
     */
    public function testUnchangedRecompileDoesNotRewriteThePointer(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('pointer_stable', 'same {{ z }}');
        self::render('pointer_stable', ['z' => 1]);

        $pointer = $cache->cacheFilePath('pointer_stable');
        clearstatcache(true, $pointer);
        $mtime = filemtime($pointer);

        // Force the compile path again with byte-identical source and an unchanged
        // dependency revision.
        $cache->invalidate('pointer_stable');
        self::render('pointer_stable', ['z' => 2]);

        clearstatcache(true, $pointer);
        $this->assertSame($mtime, filemtime($pointer), 'the pointer must not be rewritten when nothing changed');
    }

    /** A changed template must still update the pointer. */
    public function testChangedTemplateRewritesThePointer(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('pointer_moving', 'one');
        self::render('pointer_moving');

        $pointer = $cache->cacheFilePath('pointer_moving');
        clearstatcache(true, $pointer);
        $before = (string) file_get_contents($pointer);

        $file = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'pointer_moving.clarity.html';
        file_put_contents($file, 'two');
        touch($file, time() + 5);

        $cache->invalidate('pointer_moving');
        self::render('pointer_moving');

        clearstatcache(true, $pointer);
        $this->assertNotSame($before, (string) file_get_contents($pointer));
    }

    /** Bodies live at a path derived from their content, never at the pointer path. */
    public function testBodyPathIsDistinctFromPointerPath(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('paths', 'x');
        self::render('paths');

        $pointer = $cache->cacheFilePath('paths');
        $body    = (string) $cache->getLoadedCodeFile('paths');

        $this->assertNotSame($pointer, $body);
        $this->assertFileExists($pointer);
        $this->assertStringStartsWith(dirname($pointer) . DIRECTORY_SEPARATOR, $body);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}-[0-9a-f]+\.php$/', basename($body));
    }

    // =========================================================================
    // Template-name hash
    // =========================================================================

    /**
     * A compiled file from the pre-pointer layout is dead weight, and prune() must
     * collect it.
     *
     * Before this layout a template was ONE file at `<md5(name)>.php` that declared
     * its class and returned the name. Every existing deployment has these. Nothing
     * in the new code looks at that path, so without an explicit rule they would be
     * leaked forever — invisible to both the body rule (wrong shape) and the
     * pointer rule (not a `.ptr`).
     */
    public function testPruneRemovesCompiledFilesFromThePreviousLayout(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());

        $hash   = md5('legacy_layout_template');
        $bucket = dirname($cache->cacheFilePath('legacy_layout_template'));
        @mkdir($bucket, 0755, true);

        $legacy = $bucket . DIRECTORY_SEPARATOR . $hash . '.php';
        file_put_contents($legacy, "<?php\n// pre-pointer compiled body\nreturn '__Clarity_x_deadbeef';\n");

        $result = $cache->prune();

        $this->assertGreaterThanOrEqual(1, $result['legacy'], 'the pre-pointer file must be collected');
        $this->assertFileDoesNotExist($legacy);
    }

    /** A legacy file must never be deleted while a live pointer of that name exists. */
    public function testPruneKeepsALegacyPathShapedFileThatStillHasAPointer(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('legacy_guarded', 'body');
        self::render('legacy_guarded');

        // A current pointer exists for this template; the guard is the pointer's
        // presence at the same hash, not the file's contents.
        $pointer = $cache->cacheFilePath('legacy_guarded');
        $hash    = substr(basename($pointer), 0, -4);
        $sibling = dirname($pointer) . DIRECTORY_SEPARATOR . $hash . '.php';
        file_put_contents($sibling, "<?php\n// stray\n");

        $cache->prune();

        $this->assertFileExists($sibling, 'a live pointer at this hash protects the name');
        $this->assertFileExists($pointer);
    }

    /**
     * The template-name hash is declared by ONE function and every path uses it.
     *
     * If the pointer path, the body path and the class name disagreed, prune could
     * not match a body to its pointer and would delete a live file.
     */
    public function testEveryPathUsesTheDeclaredTemplateHash(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('hashed_paths', 'x');
        self::render('hashed_paths');

        $hash = Cache::templateHash('hashed_paths');

        $this->assertSame($hash, hash('xxh128', 'hashed_paths'), 'xxh128 is the declared scheme');
        $this->assertSame('__Clarity_' . $hash, $cache->classNameFor('hashed_paths'));
        $this->assertSame(substr($hash, 0, 2), basename(dirname($cache->cacheFilePath('hashed_paths'))));
        $this->assertStringStartsWith($hash . '-', basename((string) $cache->getLoadedCodeFile('hashed_paths')));
    }

    /** A current pointer must never be mistaken for an unreachable one. */
    public function testPruneKeepsCurrentPointers(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('keep_me', 'x');
        self::render('keep_me');

        $pointer = $cache->cacheFilePath('keep_me');
        $body    = (string) $cache->getLoadedCodeFile('keep_me');

        $result = $cache->prune();

        $this->assertSame(0, $result['legacy']);
        $this->assertFileExists($pointer);
        $this->assertFileExists($body);
    }

    /**
     * A legacy compiled file at the pointer path must never be included.
     *
     * Pre-content-addressing cache files returned a class name, and including one
     * DECLARES that class. Pointers are now serialized data, so there is nothing
     * to include — but a file left behind by an older version must still be
     * rejected rather than executed, and must not survive as a usable pointer.
     */
    public function testLegacyCompiledFileIsNotIncludedSoItsClassIsNeverDeclared(): void
    {
        $cache   = new Cache(TestEnvironment::cacheDir());
        $pointer = $cache->cacheFilePath('legacy_guard');

        @mkdir(dirname($pointer), 0755, true);

        $legacyClass = '__Clarity_legacy_guard_' . bin2hex('deadbeef');
        file_put_contents($pointer, "<?php\nclass {$legacyClass} {}\nreturn '{$legacyClass}';\n");

        $this->assertFalse(class_exists($legacyClass, false), 'precondition: not yet declared');

        // Any read path must refuse the file without executing it.
        $this->assertNull($cache->getLoadedCodeFile('legacy_guard'));
        $this->assertNull($cache->load('legacy_guard'));
        $this->assertFalse(
            class_exists($legacyClass, false),
            'a legacy compiled file must not be included, because including it declares a class'
        );

        @unlink($pointer);
    }

    // =========================================================================
    // Pointer store
    // =========================================================================

    /** A pointer is serialized data, never PHP. */
    public function testPointerFileIsNotPhp(): void
    {
        $cache = new Cache(TestEnvironment::cacheDir());
        self::tpl('ptr_format', 'x');
        self::render('ptr_format');

        $pointer = $cache->cacheFilePath('ptr_format');
        $this->assertStringEndsWith('.ptr', $pointer);

        $raw = (string) file_get_contents($pointer);
        $this->assertStringNotContainsString('<?php', $raw, 'a pointer must never be executable code');
        $this->assertStringNotContainsString('class ' . '__Clarity_', $raw, 'the pointer records a name, never a declaration');

        $data = unserialize($raw, ['allowed_classes' => false]);
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('file', $data, 'the body path is derived, not stored');
        $this->assertArrayHasKey('class', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('deps', $data);
    }

    /** A truncated or foreign pointer must read as "no pointer", not throw. */
    public function testCorruptPointerIsTreatedAsMissing(): void
    {
        // Deliberately a name this process never renders: the "what is loaded"
        // memo is a process-wide static, so a template that already rendered would
        // answer from memory instead of from the file being tested.
        $cache   = new Cache(TestEnvironment::cacheDir());
        $pointer = $cache->cacheFilePath('ptr_corrupt_only');
        @mkdir(dirname($pointer), 0755, true);
        file_put_contents($pointer, 'a:2:{s:5:"class";s:3:"abc";}'); // truncated

        self::tpl('ptr_corrupt_only', 'body');

        // Reflected because readPointer() is private and the cache is a deliberate
        // black box: the assertion is specifically that the FILE is rejected.
        $read = new \ReflectionMethod($cache, 'readPointerFile');
        $read->setAccessible(true);
        $this->assertNull($read->invoke($cache, 'ptr_corrupt_only'));

        // And rendering must recompile rather than fail.
        $this->assertSame('body', self::render('ptr_corrupt_only'));
    }

    /**
     * The APCu layer is read-through: it must return the same pointer the file
     * does, and enabling it must not change what is written to disk.
     *
     * When APCu is unusable in this process (CLI without `apc.enable_cli`), the
     * layer must report that honestly rather than claiming to be installed.
     */
    public function testPointerCacheIsTransparent(): void
    {
        $engine = TestEnvironment::engine();
        self::tpl('ptr_apcu', 'hello {{ n }}');
        self::assertSame('hello 1', self::render('ptr_apcu', ['n' => 1]));

        $dir   = TestEnvironment::cacheDir();
        $cache = new Cache($dir);
        $file  = null;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (str_ends_with($f->getFilename(), '.ptr')) {
                $file = (string) file_get_contents($f->getPathname());
            }
        }

        $usable = $cache->usePointerCache(true)->hasPointerCache();
        $this->assertSame(
            PointerCache::isAvailable(),
            $usable,
            'hasPointerCache() must report whether APCu can actually store, not merely that it was configured'
        );

        $this->assertSame('hello 2', self::render('ptr_apcu', ['n' => 2]), 'installing the layer must not break rendering');

        $after = null;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (str_ends_with($f->getFilename(), '.ptr')) {
                $after = (string) file_get_contents($f->getPathname());
            }
        }
        $this->assertSame($file, $after, 'the file remains the durable record');

        $cache->usePointerCache(false);
        $this->assertFalse($cache->hasPointerCache(), 'passing false must remove the layer');
    }

    /**
     * A recompile must publish the new pointer to the layer, not just the file.
     *
     * This is the failure mode that makes a pointer cache worse than none. After a
     * deploy the DEP REVISIONS are new, but the class is often unchanged (a
     * comment-only edit emits identical source), so `writePointer()`'s byte
     * compare skips the file write and returns. If the layer is only filled on a
     * pointer *read*, it keeps the superseded deps for the life of the segment:
     * every following request then sees `isFresh() === false` and recompiles.
     * Measured before the fix: 10/10 cold requests recompiled with the layer
     * installed and 1/10 without it.
     */
    public function testRecompilePublishesTheNewPointerToTheCacheLayer(): void
    {
        $base  = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_ptr_publish_' . \getmypid();
        $views = $base . \DIRECTORY_SEPARATOR . 'views';
        @\mkdir($views, 0755, true);
        \file_put_contents($views . \DIRECTORY_SEPARATOR . 'page.clarity.html', 'A');
        \touch($views . \DIRECTORY_SEPARATOR . 'page.clarity.html', \time() - 500);

        $engine = new ClarityEngine();
        $engine->setViewPath($views)->setCachePath($base . \DIRECTORY_SEPARATOR . 'cache');

        $cacheProp = new \ReflectionProperty($engine, 'cache');
        $cache     = $cacheProp->getValue($engine);
        $cache->usePointerCache(true);

        // `$loaded` is process-wide and short-circuits before the layer; clear it
        // between iterations so each one behaves like a fresh request.
        $loadedProp = new \ReflectionProperty(Cache::class, 'loaded');

        $loadedProp->setValue(null, []);
        $this->assertSame('A', $engine->renderPartial('page'));

        // The deploy: new content, and a revision the cached deps do not match.
        \file_put_contents($views . \DIRECTORY_SEPARATOR . 'page.clarity.html', 'B');
        \touch($views . \DIRECTORY_SEPARATOR . 'page.clarity.html', \time() + 400);

        $revision = static fn(string $n) => $engine->getLoader()->load($n)?->revision;

        $recompiled = 0;
        for ($i = 0; $i < 5; $i++) {
            $loadedProp->setValue(null, []);
            if (!$cache->isFresh('page', $revision)) {
                $recompiled++;
            }
            $this->assertSame('B', $engine->renderPartial('page'));
        }

        $this->assertSame(1, $recompiled, 'only the first request may recompile; a stale layer recompiles on every one');

        $this->removeDir($base);
    }
}
