<?php
namespace Clarity\Tests\Engine;

use Clarity\Template\ArrayLoader;
use Clarity\Template\CompositeLoader;
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;
use PHPUnit\Framework\TestCase;

class TemplateLoaderArchitectureTest extends TestCase
{
    public function testResolveNameCacheIsScopedPerLoaderInstance(): void
    {
        $first = new FileLoader('/views/a');
        $second = new FileLoader('/views/b');

        $this->assertSame('/views/a/home.clarity.html', $first->resolveName('home'));
        $this->assertSame('/views/b/home.clarity.html', $second->resolveName('home'));
    }

    public function testResolveNameCacheInvalidatesOnBasePathChange(): void
    {
        $loader = new FileLoader('/views/a');

        $this->assertSame('/views/a/home.clarity.html', $loader->resolveName('home'));

        $loader->setBasePath('/views/b');

        $this->assertSame('/views/b/home.clarity.html', $loader->resolveName('home'));
    }

    public function testResolveNameCacheInvalidatesOnExtensionChange(): void
    {
        $loader = new FileLoader('/views/a');

        $this->assertSame('/views/a/home.clarity.html', $loader->resolveName('home'));

        $loader->setExtension('twig');

        $this->assertSame('/views/a/home.twig', $loader->resolveName('home'));
    }

    public function testDomainRouterLoaderExposesSubLoaders(): void
    {
        $adminLoader = new FileLoader('/views/admin');
        $baseLoader = new FileLoader('/views/base');
        $router = new DomainRouterLoader(['admin' => $adminLoader], $baseLoader);

        $this->assertContains($adminLoader, $router->getSubLoaders());
        $this->assertContains($baseLoader,  $router->getSubLoaders());
    }

    public function testDomainRouterLoaderThrowsOnUnknownDomain(): void
    {
        $router = new DomainRouterLoader(['app' => new FileLoader('/views/app')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Unknown template domain 'unknown'/");
        $router->load('unknown::page');
    }

    public function testCompositeLoaderTriesLoadersInOrder(): void
    {
        $first = new ArrayLoader(['home' => 'from-first']);
        $second = new ArrayLoader(['home' => 'from-second', 'about' => 'from-second']);
        $composite = new CompositeLoader($first, $second);

        // 'home' is found in first — first loader wins
        $this->assertSame('from-first', $composite->load('home')?->getCode());
        // 'about' is not in first — falls through to second
        $this->assertSame('from-second', $composite->load('about')?->getCode());
        // unknown name — all loaders miss, returns null
        $this->assertNull($composite->load('unknown'));
    }

    /**
     * A template edited by another process must report its NEW revision.
     *
     * This is the worker case, and the one that matters for deployment. FPM is
     * fine either way — PHP's stat cache dies with the request — but a Volucer or
     * RoadRunner process renders many requests. `filemtime()` never re-reads a
     * path it has already stat()ed, and a deploy writes templates from a
     * DIFFERENT process, so PHP has no reason to drop the entry: on PHP 8.2 the
     * loader keeps returning the first render's mtime for the life of the worker.
     *
     * The loader MUST run in a child process. In the test process itself the
     * staleness cannot be observed portably: PHP 8.3+ drops the stat cache for a
     * file the process modified, and nearly any in-process filesystem call
     * (`fopen`, `unlink`, `mkdir`, even `shell_exec`) drops it wholesale — either
     * of which makes the assertion pass with or without a fix. A separate,
     * long-lived process is also exactly what the bug is about, and PHP 8.3+
     * invalidating the entry on its own is incidental rather than guaranteed.
     */
    public function testLoadSeesAnEditMadeByAnotherProcess(): void
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_loader_mtime_' . \getmypid();
        @\mkdir($dir, 0755, true);

        \file_put_contents($dir . \DIRECTORY_SEPARATOR . 'page.clarity.html', 'first');
        \touch($dir . \DIRECTORY_SEPARATOR . 'page.clarity.html', \time() - 1000);

        $autoload = \realpath(__DIR__ . '/../../vendor/autoload.php');
        $this->assertIsString($autoload, 'vendor/autoload.php must exist');

        // A worker: report the first revision, wait, then report it again.
        // The wait is a bare usleep() on purpose — a polling `is_file()` would
        // stat another path and evict the template's single-entry stat cache,
        // hiding the very staleness this asserts.
        $worker = $dir . \DIRECTORY_SEPARATOR . 'worker.php';
        \file_put_contents($worker, \sprintf(
            '<?php require %s;'
                . ' $loader = new \Clarity\Template\FileLoader($argv[1], \'clarity.html\');'
                . ' echo $loader->load(\'page\')->revision, " ";'
                . ' \usleep((int) $argv[2]);'
                . ' echo $loader->load(\'page\')->revision;',
            \var_export($autoload, true)
        ));

        $proc = \proc_open(
            [\PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'opcache.enable=0',
                $worker, $dir, '2000000'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'could not start the worker process');

        // Let the worker record its first revision, then "deploy".
        \usleep(700_000);
        \touch($dir . \DIRECTORY_SEPARATOR . 'page.clarity.html', \time() + 500);

        $out = \stream_get_contents($pipes[1]);
        \proc_close($proc);
        self::removeTree($dir);

        $revisions = \array_values(\array_filter(\explode(' ', \trim((string) $out)), 'strlen'));
        $this->assertCount(2, $revisions, 'the worker must have rendered twice, got: ' . $out);
        $this->assertNotSame(
            $revisions[0],
            $revisions[1],
            'a worker must not keep reporting the cached mtime of an edited template'
        );
    }

    private static function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $entry;
            \is_dir($path) ? self::removeTree($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}