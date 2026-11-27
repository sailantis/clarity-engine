<?php
namespace Clarity\Engine;

/**
 * Cache for compiled Clarity template classes.
 *
 * Each template has a `.ptr` file naming its current body and an immutable,
 * content-addressed PHP body (`<hash>-<digest>.php`). Reusing bodies avoids
 * rewriting files and invalidating shared OPcache entries.
 *
 * Files remain the durable record; an optional {@see PointerCache} (APCu) caches
 * pointer reads. The shared `Cache::$classNames` registry maps template names to
 * loaded classes for warm lookups.
 *
 */
class Cache
{
    /**
     * Return the 32-character `xxh128` digest used in cache paths and class names.
     *
     * A shared hash keeps pointer, body and class names consistent for cache
     * lookup and pruning. It is intended for cache naming, not adversarial
     * collision resistance.
     */
    public static function templateHash(string $templateName): string
    {
        return \hash('xxh128', $templateName);
    }

    private string $path;

    /** Optional in-memory layer in front of pointer reads. */
    private ?PointerCache $pointerCache = null;

    /**
     * Seconds to reuse a positive freshness verdict before checking the filesystem.
     * The default, 0, checks on every render; a positive value bounds template
     * staleness. {@see invalidate()} and {@see flush()} clear verdicts immediately.
     */
    private int $revalidateFreq = 0;

    /**
     * Last filesystem freshness verdict per template, with the time it was
     * reached: name => [checkedAt (float), fresh (bool)].
     *
     * Only positive verdicts are reused. A stale verdict is never cached — the
     * recompile that follows must see the new state.
     *
     * @var array<string, array{0: float, 1: bool}>
     */
    private array $freshCheckedAt = [];

    /**
     * In-process registry: templateName → the class name that was loaded (via
     * require) in this PHP process.  Shared across all Cache instances.
     *
     * @var array<string, string>
     */
    private static array $classNames = [];

    /**
     * In-process registry: templateName → the file that was required, plus the
     * metadata needed to answer {@see isFresh()} without touching the disk.
     *
     * `isFresh()` runs on every render.  Keeping the verdict's inputs here means
     * a warm template costs one array lookup instead of a `require`, which is
     * what makes the content-addressed layout (an extra manifest file) free on
     * the hot path.
     *
     * @var array<string, array{class: string, file: string, version: int, deps: array<string, int|string>}>
     */
    private static array $loaded = [];

    /**
     * Shape version of the serialized pointer payload.
     *
     * Folded into every pointer, so a future change to the record is detected as
     * "no pointer" (recompile) rather than silently misread.
     */
    private const POINTER_FORMAT = 1;

    public function __construct(string $path = '')
    {
        $this->path = $path !== ''
            ? \rtrim($path, '/\\')
            : \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_cache';
    }

    /**
     * Change the cache directory at runtime.
     *
     * Clearing the in-process registries is not optional: they memoise "what is
     * live for this template name", and a different directory is a different
     * world — a pointer there can name a different class built from different
     * source. Leaving them populated made a newly registered directive appear to
     * be "already compiled", so the template was served from the old body.
     */
    public function setPath(string $path): static
    {
        $previous = $this->path;

        $this->path = \rtrim($path, '/\\');
        self::$classNames     = [];
        self::$loaded         = [];
        $this->freshCheckedAt = [];

        // Evict pointers from the old namespace before retargeting the layer.
        if ($this->pointerCache !== null) {
            PointerCache::evictDirectory($previous);
            $this->pointerCache = new PointerCache($this->path);
        }

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set how many seconds a freshness verdict may be reused for.
     *
     * See {@see $revalidateFreq}. 0 — the default — restores a per-render
     * filesystem check; a positive value bounds staleness by that many seconds.
     *
     * @param int $seconds Seconds; negative values are treated as 0.
     */
    public function setRevalidateFreq(int $seconds): static
    {
        $this->revalidateFreq = \max(0, $seconds);
        $this->freshCheckedAt = [];
        return $this;
    }

    /**
     * How many seconds a freshness verdict is reused for.
     */
    public function getRevalidateFreq(): int
    {
        return $this->revalidateFreq;
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
     * 2. The class was produced by the current compiler version. A change in
     *    Compiler::COMPILER_VERSION is a change in emitted semantics (e.g. the
     *    two-variable for loop binding (key, value) instead of (value, key)), so
     *    the template source may be unchanged while the compiled code is wrong.
     * 3. Every entry in the class's $dependencies still has the same revision
     *    as recorded at compile time, as determined by calling $revisionFor.
     *
     * On warm paths the class is already in memory; the static properties are
     * read directly. The only file access is the `is_file()` check before `load()`.
     *
     * @param string   $templateName  Logical template name (e.g. 'home', 'layouts/base').
     * @param callable $revisionFor   fn(string $name): int|string — returns the current
     *                                revision for a dependency name. The engine passes a
     *                                closure that calls the active TemplateLoader.
     */
    public function isFresh(string $templateName, callable $revisionFor): bool
    {
        $now = \microtime(true);

        if ($this->revalidateFreq > 0) {
            $last = $this->freshCheckedAt[$templateName] ?? null;
            if ($last !== null && $last[1] && ($now - $last[0]) < $this->revalidateFreq) {
                return true;
            }
        }

        $pointer = $this->readPointer($templateName);

        if ($pointer === null) {
            unset($this->freshCheckedAt[$templateName]);
            return false;
        }

        // Check if the emitted bytecode comes from the current compiler.
        if ($pointer['version'] !== Compiler::COMPILER_VERSION) {
            unset($this->freshCheckedAt[$templateName]);
            return false;
        }

        foreach ($pointer['deps'] as $name => $revision) {
            try {
                if ($revisionFor($name) !== $revision) {
                    unset($this->freshCheckedAt[$templateName]);
                    return false;
                }
            } catch (\Throwable) {
                // Loader cannot find dep (e.g. file deleted) → stale
                unset($this->freshCheckedAt[$templateName]);
                return false;
            }
        }

        $this->freshCheckedAt[$templateName] = [$now, true];

        return true;
    }

    /**
     * Return the class name for a loaded (or loadable) compiled template.
     *
     * If the template was loaded in this process already, returns from the
     * in-process registry with no I/O.  Otherwise reads the pointer, requires
     * the code file it names (which is content-addressed, and therefore
     * OPcache-eligible and never rewritten) and registers the result.
     *
     * @param string $templateName Logical template name.
     * @return class-string|null  Null if no cache file exists.
     */
    public function load(string $templateName): ?string
    {
        if (isset(self::$classNames[$templateName])) {
            return self::$classNames[$templateName];
        }

        $pointer = $this->readPointer($templateName);
        if ($pointer === null) {
            return null;
        }

        return $this->requireCodeFile($templateName, $pointer['class'], $pointer['file'], $pointer['version'], $pointer['deps']);
    }

    /**
     * Write a compiled template to the cache, immediately require it, and
     * return the class name.
     *
     * TWO FILES, ON PURPOSE.
     *
     * The code goes to a content-addressed path
     * (`<template-hash>-<digest>.php`, see {@see templateHash()}),
     * and the small pointer file at {@see cacheFilePath()} says which digest is
     * current.  That split is what removes the OPcache waste this cache used to
     * produce: discarding a cached op_array is what adds it to
     * `wasted_shared_memory`, so the fix is to never rewrite — and never
     * `opcache_invalidate()` — a file whose content is still wanted.
     *
     *  - Unchanged source recompiles to the same class name (the name is a
     *    digest of the emitted source), so the code file already exists and is
     *    left completely alone.  With a fixed path per template, the same
     *    recompile had to overwrite and invalidate it, discarding the op_array
     *    every live worker had just cached.
     *  - Changed source lands at a NEW path, so no cached entry is discarded:
     *    the new file is a new hash entry.  Old digests are simply unreferenced,
     *    and {@see prune()} deletes them.
     *
     * `require` (not `require_once`) is still correct for a genuinely new file,
     * and is skipped entirely when the class is already declared — which is the
     * stampede case: N workers converging on the same source all see the same
     * digest and only the first writes.
     *
     * @param string           $templateName Logical template name.
     * @param CompiledTemplate $compiled     Result from the compiler.
     * @return class-string The class name that is now live in memory.
     */
    public function writeAndLoad(string $templateName, CompiledTemplate $compiled): string
    {
        $className = $compiled->className;
        $codeFile  = $this->codeFilePath($templateName, $className);

        // The FILE is written whenever it is missing, even if the class is already
        // declared: the same template compiled into a different cache directory
        // (two engines, a test harness, a rotated dir) must still have its body on
        // disk, or error mapping points at a path that does not exist there.
        // `class_exists()` only lets us skip the `require` — writing is
        // idempotent and content-addressed, so it costs one `is_file()` when the
        // file is already there.
        $this->writeCodeFile($codeFile, "<?php\n" . $compiled->code . "\n");

        if (!\class_exists($className, false)) {
            require $codeFile;
        }

        $this->writePointer($templateName, $className, $compiled->dependencies);

        self::$classNames[$templateName] = $className;
        self::$loaded[$templateName]     = [
            'class'   => $className,
            'file'    => $codeFile,
            'version' => Compiler::COMPILER_VERSION,
            'deps'    => $compiled->dependencies,
        ];
        unset($this->freshCheckedAt[$templateName]);

        return $className;
    }

    /**
     * Atomically write a content-addressed file if it does not exist.
     *
     * Existing digest paths already contain the required content; rewriting them
     * would discard shared OPcache entries. A temporary file and atomic rename
     * prevent partial reads. New files are backdated past
     * `opcache.file_update_protection` so OPcache can cache them immediately.
     */
    private function writeCodeFile(string $file, string $code): void
    {
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Unable to create Clarity cache directory: {$dir}");
        }

        clearstatcache(true, $file);
        if (\is_file($file)) {
            return;
        }

        $tmp = $file . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $code, \LOCK_EX) === false) {
            @\unlink($tmp);

            // Another worker may have won the race between is_file() and here.
            clearstatcache(true, $file);
            if (\is_file($file)) {
                return;
            }

            throw new \RuntimeException("Unable to write Clarity cache file: {$file}");
        }

        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);
            clearstatcache(true, $file);
            if (\is_file($file)) {
                return;
            }

            throw new \RuntimeException("Unable to move Clarity cache file into place: {$file}");
        }

        clearstatcache(true, $file);
        @\touch($file, \time() - $this->opcacheProtectionWindow() - 1);
        clearstatcache(true, $file);
    }

    /**
     * Seconds OPcache refuses to cache a file for, after it is written.
     *
     * `opcache.file_update_protection` defaults to 2.  A file younger than that
     * is compiled but deliberately not cached, so a content-addressed file
     * written on the request that needed it would be recompiled by every worker
     * until the window passed.
     */
    private function opcacheProtectionWindow(): int
    {
        $value = @\ini_get('opcache.file_update_protection');

        return $value === false ? 2 : \max(0, (int) $value);
    }

    /**
     * The pointer file for a template: which content-addressed code file is
     * currently live.
     *
     * Kept at the historical path (`<bucket>/<hash>.php`) so existing
     * deployments and `cache:clear` need no changes, but it is now a small PHP
     * file that returns metadata. A compiled file written by an older Clarity
     * version returns a class-name string instead; {@see readPointer()} reports
     * that as "no pointer" and the template is simply recompiled.
     */
    public function cacheFilePath(string $templateName): string
    {
        return $this->pointerPath($templateName);
    }

    /**
     * Path of the content-addressed code file for a compiled template.
     *
     * The digest comes from the generated class name, which is itself derived
     * from the emitted source, so the path is a pure function of the file's
     * content.  A path can therefore never need rewriting: different content is a
     * different path.
     */
    public function codeFilePath(string $templateName, string $className): string
    {
        $digest = \substr($className, \strrpos($className, '_') + 1);
        $hash   = self::templateHash($templateName);

        return $this->path . \DIRECTORY_SEPARATOR . \substr($hash, 0, 2)
            . \DIRECTORY_SEPARATOR . $hash . '-' . $digest . '.php';
    }

    /** Path of the pointer file (one per logical template name). */
    private function pointerPath(string $templateName): string
    {
        $hash = self::templateHash($templateName);

        return $this->path . \DIRECTORY_SEPARATOR . \substr($hash, 0, 2) . \DIRECTORY_SEPARATOR . $hash . '.ptr';
    }

    /**
     * Install APCu based cache that sits in front of the pointer files.
     *
     * @param bool $enabled Whether to enable the pointer cache.
     */
    public function usePointerCache(bool $enabled = true): static
    {
        if (!$enabled || !PointerCache::isAvailable()) {
            $this->pointerCache = null;
            $this->freshCheckedAt = [];
            return $this;
        }

        $cache = new PointerCache($this->path);
        $this->pointerCache = $cache;
        $this->freshCheckedAt = [];

        return $this;
    }

    /**
     * Whether an in-memory layer is installed AND able to store anything.
     *
     * A configured-but-unusable backend (APCu compiled out, or `apc.enable_cli=0`
     * under CLI) reports false, because it cannot save a single read. Reporting
     * "enabled" there would hide a configuration mistake behind behaviour that
     * looks identical to working.
     */
    public function hasPointerCache(): bool
    {
        return $this->pointerCache !== null;
    }

    /**
     * Read the pointer for a template.
     *
     * The body's path is NOT stored: it is derived from (template name, class) by
     * {@see codeFilePath()}, so recording it would bake an absolute path into the
     * cache and stop the file being valid when the directory moved.
     *
     * @return array{class: string, file: string, version: int, deps: array<string, int|string>}|null
     *         Null when absent, unreadable, legacy, or pointing at a missing body.
     */
    private function readPointer(string $templateName): ?array
    {
        if (isset(self::$loaded[$templateName])) {
            return self::$loaded[$templateName];
        }

        $pointer = $this->pointerCache?->get($templateName)
            ?? $this->readPointerFile($templateName);

        if ($pointer === null) {
            return null;
        }

        // A miss in the memory layer repopulates it from the file, which is the
        // only durable record.
        if ($this->pointerCache !== null) {
            $this->pointerCache->put($templateName, $pointer);
        }

        $file = $this->codeFilePath($templateName, $pointer['class']);

        // A pointer whose body is gone is not usable. Reporting it as a miss makes
        // the caller recompile, which rewrites both files.
        \clearstatcache(true, $file);
        if (!\is_file($file)) {
            return null;
        }

        return self::$loaded[$templateName] = [
            'class'   => $pointer['class'],
            'file'    => $file,
            'version' => $pointer['version'],
            'deps'    => $pointer['deps'],
        ];
    }

    /**
     * Require a content-addressed code file and register the result.
     *
     * Skips the `require` when the class is already declared in this process,
     * which is the stampede case: many workers of the same master converge on
     * one digest.
     *
     * @param array<string, int|string> $deps
     * @return class-string|null
     */
    private function requireCodeFile(
        string $templateName,
        string $className,
        string $file,
        int $version,
        array $deps,
    ): ?string {
        if (!\is_file($file)) {
            unset($this->freshCheckedAt[$templateName]);
            return null;
        }

        if (!\class_exists($className, false)) {
            require $file;
        }

        if (!\class_exists($className, false)) {
            return null;
        }

        self::$classNames[$templateName] = $className;
        self::$loaded[$templateName]     = [
            'class'   => $className,
            'file'    => $file,
            'version' => $version,
            'deps'    => $deps,
        ];

        return $className;
    }

    /**
     * Read and validate the pointer file.
     *
     * FORMAT. `serialize()` of a small array, chosen on measurement: read+decode
     * is 39.3 us against 47.0 us for JSON on a 12-dependency pointer, it is fastest
     * at every size tested, and it round-trips bytes `json_encode()` rejects. It is
     * also ASCII and greppable.
     *
     * The record holds the class name, the compiler version and the dependency
     * revisions — NOT the body path, which is derived from (template, class). It
     * carries no timestamp: freshness comes from the dependency revisions, not
     * from a clock, so an unchanged template cannot look stale.
     *
     * `allowed_classes => false` makes a tampered payload arrive as
     * `__PHP_Incomplete_Class` instead of being instantiated, and the shape check
     * then rejects it. The pointer is written here, but it lives in a directory
     * other processes can write to, so it is treated as untrusted input.
     *
     * @return array{class: string, version: int, deps: array<string, int|string>}|null
     */
    private function readPointerFile(string $templateName): ?array
    {
        $file = $this->pointerPath($templateName);

        // The stat cache survives across requests in a long-lived worker, so an
        // `is_file()` that answered "yes" before the directory was wiped keeps
        // answering "yes". Without clearing it, the guard below would pass and the
        // read would emit E_WARNING — which the render path's error handler maps to
        // a template line, reporting a missing pointer as a template diagnostic.
        \clearstatcache(true, $file);

        if (!\is_file($file)) {
            return null;
        }

        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $data = @\unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($data) || ($data['format'] ?? null) !== self::POINTER_FORMAT) {
            return null;
        }

        $class   = $data['class'] ?? null;
        $version = $data['version'] ?? null;
        $deps    = $data['deps'] ?? null;

        if (!\is_string($class) || $class === '' || !\is_int($version) || !\is_array($deps)) {
            return null;
        }

        foreach ($deps as $name => $revision) {
            if (!\is_string($name) || (!\is_int($revision) && !\is_string($revision))) {
                return null;
            }
        }

        return ['class' => $class, 'version' => $version, 'deps' => $deps];
    }

    /**
     * Publish the pointer file, atomically — but only when it actually changed.
     *
     * A rewrite is pure cost, and the common case is a recompile that changed
     * nothing (a `revalidateFreq` miss, a dependency revision that did not alter
     * the emitted source, or a stampede converging on an existing digest). The
     * comparison is against the file's ACTUAL bytes: a remembered digest would
     * only prove "we last intended to write this", so a pointer damaged out of
     * band would match it and never be repaired.
     *
     * The write is temp-file + atomic rename, so a concurrent reader never sees a
     * partial record. It is NOT an OPcache concern any more: the pointer is data,
     * not code, so there is nothing to invalidate and no op_array to discard.
     */
    private function writePointer(string $templateName, string $className, array $deps): void
    {
        $raw = \serialize([
            'format'  => self::POINTER_FORMAT,
            'class'   => $className,
            'version' => Compiler::COMPILER_VERSION,
            'deps'    => $deps,
        ]);

        $file = $this->pointerPath($templateName);

        $this->pointerCache?->put($templateName, [
            'class'   => $className,
            'version' => Compiler::COMPILER_VERSION,
            'deps'    => $deps,
        ]);

        \clearstatcache(true, $file);
        if (\is_file($file) && @\file_get_contents($file) === $raw) {
            return;
        }

        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Unable to create Clarity cache directory: {$dir}");
        }

        $tmp = $file . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $raw, \LOCK_EX) === false) {
            @\unlink($tmp);
            throw new \RuntimeException("Unable to write Clarity pointer: {$file}");
        }

        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);
            throw new \RuntimeException("Unable to move Clarity pointer into place: {$file}");
        }

        \clearstatcache(true, $file);
    }

    /**
     * Delete the cached file for the given template name (if it exists) and
     * remove it from the in-process registry.
     *
     * Only the POINTER is removed.  The content-addressed code file is left in
     * place: it may still be referenced by a template whose source has not
     * changed, and {@see prune()} collects unreferenced digests.  Deleting it
     * here would also mean invalidating an op_array that other workers share,
     * which is the cost this layout exists to avoid.
     */
    public function invalidate(string $templateName): void
    {
        unset($this->freshCheckedAt[$templateName]);

        // Evict from the shared segment before the file goes, and via the STATIC
        // path: this method is routinely called by an instance that never installed
        // a layer (a CLI task, another SAPI), where `$this->pointerCache` is null. The
        // entry is not owned by an instance anyway — it lives in a segment every
        // worker shares — so a no-op here left a deleted pointer alive in APCu, and
        // the next process to read it resurrected it. See PointerCache's docblock.
        PointerCache::evict($this->path, $templateName);

        $file = $this->pointerPath($templateName);
        \clearstatcache(true, $file);
        if (\is_file($file)) {
            @\unlink($file);
            \clearstatcache(true, $file);
        }

        unset(self::$classNames[$templateName], self::$loaded[$templateName]);
    }

    /**
     * Delete all cached files in the cache directory and clear the in-process
     * registry so stale class names do not prevent recompilation.
     *
     * The APCu namespace for this directory goes with them. It has to: `flush()`
     * deletes every pointer file, so any surviving entry names a file that no longer
     * exists, and the next read would resurrect it. This is deliberately done before
     * the early return on a missing directory — an entry can outlive the directory it
     * was namespaced to.
     */
    public function flush(): void
    {
        PointerCache::evictDirectory($this->path);

        self::$classNames     = [];
        self::$loaded         = [];
        $this->freshCheckedAt = [];

        if (!is_dir($this->path)) {
            return;
        }
        $hasOpcache = \function_exists('opcache_invalidate');
        $iter       = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            if ($file->isDir()) {
                \rmdir($file->getPathname());
                continue;
            }

            $path = $file->getPathname();
            if ($hasOpcache) {
                @\opcache_invalidate($path, true);
            }
            \unlink($path);
        }
    }

    /**
     * Delete cache files that nothing references any more.
     *
     * Three kinds, deliberately separate because each is recognised differently:
     *
     *  1. **Superseded bodies** — `<hash>-<digest>.php` that no pointer names.
     *     Content addressing makes every recompile that changed something write a
     *     new file, so these accumulate.
     *  2. **Files from the previous layout** — `<md5(name)>.php`, which declared
     *     their class and returned its name. Every deployment predating pointers
     *     has these. Nothing in the new code looks at that path and no other rule
     *     here matches its shape, so without an explicit case they leak forever.
     *  3. **Pointers with no body** — the template recompiles over them, so they
     *     are left alone rather than deleted. Kept for the comment below because it
     *     is the one thing that looks like it should be cleaned and should not.
     *
     * Nothing needs to happen immediately: an unreferenced file is inert, and
     * deleting one mid-flight could invalidate an op_array a worker is about to
     * require. This therefore runs as a maintenance task (`cache:prune`).
     *
     * Deliberately conservative otherwise: a body is removed only when its name
     * matches the `<hash>-<digest>.php` shape AND no live pointer names it.
     * Anything unrecognised is left alone.
     *
     * @param int $olderThanSeconds Only remove files older than this. 0 = any age.
     * @return array{removed: int, kept: int, legacy: int}
     */
    public function prune(int $olderThanSeconds = 0): array
    {
        if (!\is_dir($this->path)) {
            return ['removed' => 0, 'kept' => 0, 'legacy' => 0];
        }

        $cutoff     = $olderThanSeconds > 0 ? \time() - $olderThanSeconds : null;
        $removed    = 0;
        $kept       = 0;
        $legacy     = 0;
        $referenced = [];

        foreach (new \DirectoryIterator($this->path) as $bucket) {
            if ($bucket->isDot() || !$bucket->isDir()) {
                continue;
            }

            foreach (new \DirectoryIterator($bucket->getPathname()) as $entry) {
                if ($entry->isDot() || !$entry->isFile() || !\str_ends_with($entry->getFilename(), '.ptr')) {
                    continue;
                }

                $hash = \substr($entry->getFilename(), 0, -4);

                $raw  = @\file_get_contents($entry->getPathname());
                $data = \is_string($raw) && $raw !== ''
                    ? @\unserialize($raw, ['allowed_classes' => false])
                    : null;

                if (!\is_array($data) || !\is_string($data['class'] ?? null)) {
                    continue; // unreadable: the template recompiles over it
                }

                $digest = \substr($data['class'], \strrpos($data['class'], '_') + 1);
                $referenced[$hash . '-' . $digest . '.php'] = true;
            }
        }

        // COMPILED FILES FROM THE PREVIOUS LAYOUT. Before pointers existed, a
        // template was one file at `<md5(name)>.php` that declared its class and
        // returned the name. Every existing deployment has these, and the new code
        // never looks at that path (it looks for `<hash>.ptr` and
        // `<hash>-<digest>.php`), so they are dead weight that no other rule here
        // would ever match. Removed by path shape, and only when no pointer of the
        // same name is live.
        foreach (new \DirectoryIterator($this->path) as $bucket) {
            if ($bucket->isDot() || !$bucket->isDir()) {
                continue;
            }

            foreach (new \DirectoryIterator($bucket->getPathname()) as $entry) {
                if ($entry->isDot() || !$entry->isFile()) {
                    continue;
                }

                $name = $entry->getFilename();
                if (\preg_match('/^[0-9a-f]{32}\.php$/', $name) !== 1) {
                    continue; // not the legacy single-file shape
                }

                $hash = \substr($name, 0, -4);

                // The guard is a pointer at the SAME hash, which means a template
                // whose name hashes there is live. Built from the hash directly:
                // pointerPath() takes a template NAME and would hash it again.
                $live = \is_file($bucket->getPathname() . \DIRECTORY_SEPARATOR . $hash . '.ptr');

                if ($live) {
                    continue;
                }

                if ($cutoff !== null && $entry->getMTime() > $cutoff) {
                    $kept++;
                    continue;
                }

                $path = $entry->getPathname();
                if (\function_exists('opcache_invalidate')) {
                    @\opcache_invalidate($path, true);
                }
                if (@\unlink($path)) {
                    $legacy++;
                }
            }
        }

        foreach (new \DirectoryIterator($this->path) as $bucket) {
            if ($bucket->isDot() || !$bucket->isDir()) {
                continue;
            }

            foreach (new \DirectoryIterator($bucket->getPathname()) as $entry) {
                if ($entry->isDot() || !$entry->isFile()) {
                    continue;
                }

                $name = $entry->getFilename();
                if (\preg_match('/^[0-9a-f]{32}-[0-9a-f]+\.php$/', $name) !== 1) {
                    continue;
                }

                if (isset($referenced[$name])) {
                    $kept++;
                    continue;
                }

                if ($cutoff !== null && $entry->getMTime() > $cutoff) {
                    $kept++;
                    continue;
                }

                $path = $entry->getPathname();
                if (\function_exists('opcache_invalidate')) {
                    @\opcache_invalidate($path, true);
                }
                if (@\unlink($path)) {
                    $removed++;
                }
            }
        }

        return ['removed' => $removed, 'kept' => $kept, 'legacy' => $legacy];
    }

    // -------------------------------------------------------------------------

    /**
     * Return the base class-name prefix for a template name.
     *
     * The full class name appends a digest of the emitted source, so it is
     * stable for unchanged source and different for changed source.  Use
     * {@see getLoadedClassName()} for the name that is actually live.
     */
    public function classNameFor(string $templateName): string
    {
        return '__Clarity_' . self::templateHash($templateName);
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
     * Physical path of the code file that is live for a template, or null.
     *
     * Error mapping compares a throwable's origin file against this: the compiled
     * body lives in a content-addressed file, not at {@see cacheFilePath()}
     * (which now holds only the pointer).
     */
    public function getLoadedCodeFile(string $templateName): ?string
    {
        $loaded = $this->readPointer($templateName);

        return $loaded['file'] ?? null;
    }
}