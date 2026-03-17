<?php
namespace Clarity\Engine;

/**
 * Read/write cache for Clarity compiled template classes.
 *
 * Each compiled template is stored as a single `.php` file.  The cache
 * filename is derived deterministically from `md5($templateName)` so lookups
 * never require reading the directory.
 *
 * Cache filename : md5($templateName).php
 * Class name     : __Clarity_<md5($templateName)>_<uniqid>   (versioned per compile)
 *
 * Versioned class names allow multiple compiled versions of the same template
 * to coexist in memory across recompilations — eliminating redeclaration
 * collisions in long-running processes (Swoole, RoadRunner, regular FPM alike).
 *
 * Each compiled file ends with `return '$className';` so that `require`-ing
 * it returns the exact class name without any file re-reading or regex.
 *
 * In-process class name registry
 * --------------------------------
 * `Cache::$classNames` maps templateName → loaded class name for the current
 * process.  This lets warm-path calls to `isFresh()` and `load()` operate
 * purely from memory (OPcache + static array) with zero file I/O.
 *
 * Compiled class static properties
 * ---------------------------------
 * $dependencies – array<string,int|string>  logicalName => revision
 * $sourceMap    – list<[phpLineStart, fileIndex, templateLine]>  ranges
 */
class Cache
{
    private string $path;

    /**
     * In-process registry: templateName → the class name that was loaded (via
     * require) in this PHP process.  Shared across all Cache instances.
     *
     * @var array<string, string>
     */
    private static array $classNames = [];

    public function __construct(string $path = '')
    {
        $this->path = $path !== ''
            ? rtrim($path, '/\\')
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_cache';
    }

    /**
     * Change the cache directory at runtime.
     */
    public function setPath(string $path): static
    {
        $this->path = rtrim($path, '/\\');
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    // -------------------------------------------------------------------------

    /**
     * Check whether a valid (non-stale) cached file exists for the given
     * logical template name.
     *
     * Freshness rules
     * ---------------
     * 1. A compiled class for this template name is known (either loaded in
     *    this process already, or loadable from the cache file).
     * 2. Every entry in the class's $dependencies still has the same revision
     *    as recorded at compile time, as determined by calling $revisionFor.
     *
     * On warm paths the class is already in memory; `readDeps()` reflects
     * `$dependencies` directly — zero file I/O from this method.
     *
     * @param string   $templateName  Logical template name (e.g. 'home', 'layouts/base').
     * @param callable $revisionFor   fn(string $name): int|string — returns the current
     *                                revision for a dependency name. The engine passes a
     *                                closure that calls the active TemplateLoader.
     */
    public function isFresh(string $templateName, callable $revisionFor): bool
    {
        $cacheFile = $this->cacheFilePath($templateName);

        if (!is_file($cacheFile)) {
            return false;
        }

        $deps = $this->readDeps($templateName);

        if ($deps === null) {
            // Could not parse deps → treat as stale
            return false;
        }

        foreach ($deps as $name => $revision) {
            try {
                if ($revisionFor($name) !== $revision) {
                    return false;
                }
            } catch (\Throwable) {
                // Loader cannot find dep (e.g. file deleted) → stale
                return false;
            }
        }

        return true;
    }

    /**
     * Return the class name for a loaded (or loadable) compiled template.
     *
     * If the class was already loaded in this process, returns from the in-
     * process registry with no I/O.  Otherwise requires the cache file (which
     * is OPcache-eligible) and registers the returned class name.
     *
     * @param string $templateName Logical template name.
     * @return class-string|null  Null if no cache file exists.
     */
    public function load(string $templateName): ?string
    {
        if (isset(self::$classNames[$templateName])) {
            return self::$classNames[$templateName];
        }

        $cacheFile = $this->cacheFilePath($templateName);
        if (!is_file($cacheFile)) {
            return null;
        }

        // require (not require_once) so the versioned class is always declared;
        // the file returns the class name as its last statement.
        $className = require $cacheFile;
        if (is_string($className) && $className !== '') {
            self::$classNames[$templateName] = $className;
            return $className;
        }

        return null;
    }

    /**
     * Write a compiled template to the cache, immediately require it, and
     * return the class name.
     *
     * Using `require` (not `require_once`) ensures the new versioned class is
     * declared even if an older compiled version of the same template is already
     * loaded in this process.
     *
     * @param string           $templateName Logical template name.
     * @param CompiledTemplate $compiled     Result from the compiler.
     * @return class-string The class name that is now live in memory.
     */
    public function writeAndLoad(string $templateName, CompiledTemplate $compiled): string
    {
        $cacheFile = $this->cacheFilePath($templateName);
        $dir = \dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $code = "<?php\n" . $compiled->code . "\n";

        $tmp = $cacheFile . '.tmp.' . \getmypid();
        if (\file_put_contents($tmp, $code, \LOCK_EX) !== false) {
            \rename($tmp, $cacheFile);
            \clearstatcache(true, $cacheFile);
            if (\function_exists('opcache_invalidate')) {
                @\opcache_invalidate($cacheFile, true);
            }
        }

        $className = require $cacheFile;
        self::$classNames[$templateName] = $className;

        return $className;
    }

    /**
     * Delete the cached file for the given template name (if it exists) and
     * remove it from the in-process registry.
     */
    public function invalidate(string $templateName): void
    {
        $file = $this->cacheFilePath($templateName);
        if (is_file($file)) {
            if (\function_exists('opcache_invalidate')) {
                @\opcache_invalidate($file, true);
            }
            @unlink($file);
            clearstatcache(true, $file);
        }
        unset(self::$classNames[$templateName]);
    }

    /**
     * Delete all cached files in the cache directory and clear the in-process
     * registry so stale class names do not prevent recompilation.
     */
    public function flush(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $hasOpcache = \function_exists('opcache_invalidate');
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            $path = $file->getPathname();
            if ($hasOpcache) {
                @\opcache_invalidate($path, true);
            }
            unlink($path);
        }
        self::$classNames = [];
    }

    // -------------------------------------------------------------------------

    /**
     * Return the base class-name prefix for a template name.
     *
     * Note: the actual in-memory class name includes a unique compile-time
     * suffix to prevent redeclaration collisions.  Use {@see getLoadedClassName()}
     * to obtain the real class name after a template has been loaded.
     */
    public function classNameFor(string $templateName): string
    {
        return '__Clarity_' . md5($templateName);
    }

    /**
     * Return the class name that is currently live in this process for the
     * given template name, or null if the template has not been loaded yet.
     */
    public function getLoadedClassName(string $templateName): ?string
    {
        return self::$classNames[$templateName] ?? null;
    }

    /**
     * Compute the cache file path for a given logical template name.
     *
     * Files are stored under a 2-character hex subdirectory derived from the
     * first two characters of the template name's MD5 hash.  This limits the
     * number of files per directory to at most 256 buckets × N templates,
     * keeping directory listings manageable even for large applications.
     *
     * Example:  md5('home') = 'b026...'  →  {cachePath}/b0/b026....php
     */
    public function cacheFilePath(string $templateName): string
    {
        $hash = md5($templateName);
        $bucket = \substr($hash, 0, 2);
        return $this->path . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . $hash . '.php';
    }

    /**
     * @return array<string,int|string>|null  [logicalName => revision] or null on failure.
     *
     * On warm paths (class already in memory via the static registry) this is
     * pure OPcache access — zero file I/O.  On cold paths the cache file is
     * required (OPcache-eligible) and the returned class name is registered.
     */
    private function readDeps(string $templateName): ?array
    {
        if (isset(self::$classNames[$templateName])) {
            $className = self::$classNames[$templateName];
        } else {
            $cacheFile = $this->cacheFilePath($templateName);
            if (!is_file($cacheFile)) {
                return [];
            }
            // require (not require_once) — the file returns the class name.
            $className = require $cacheFile;
            if (!is_string($className) || $className === '') {
                return null;
            }
            self::$classNames[$templateName] = $className;
        }

        try {
            $deps = $className::$dependencies;
            return \is_array($deps) ? $deps : null;
        } catch (\Error) {
            return null;
        }
    }

}