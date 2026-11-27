<?php

namespace Clarity\Engine;

/**
 * APCu-backed.
 *
 * Keys are prefixed with a digest of the cache directory. Under FPM every worker
 * shares one APCu segment, so without the prefix two engines in one process - a
 * test harness plus the application, or two tenants - would read each other's
 * pointers for the same template name.
 *
 * Every failure is swallowed: APCu is an optimisation, and a fill or an eviction
 * that cannot happen only costs the next read. Nothing here may break a render.
 */
final class PointerCache
{

    /** Whether the extension is usable; resolved once. */
    private static ?bool $available = null;

    private readonly string $prefix;

    /**
     * @param string $path The cache directory this cache belongs to (the namespace).
     */
    public function __construct(string $path)
    {
        $this->prefix = self::prefixFor($path);
    }

    /** The APCu key prefix for a cache directory. */
    private static function prefixFor(string $path): string
    {
        return 'clarity.ptr.' . \hash('xxh3', \rtrim($path, '/\\')) . '.';
    }

    /** Whether APCu can be used in this process at all. */
    public static function isAvailable(): bool
    {
        return self::$available ??= \function_exists('apcu_fetch')
            && (bool) \ini_get('apc.enabled')
            && (\PHP_SAPI !== 'cli' || (bool) \ini_get('apc.enable_cli'));
    }

    public function get(string $templateName): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        try {
            $hit = \apcu_fetch($this->prefix . $templateName, $ok);

            return $ok && \is_array($hit) ? $hit : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function put(string $templateName, array $pointer): void
    {
        if (!self::isAvailable()) {
            return;
        }

        try {
            \apcu_store($this->prefix . $templateName, $pointer);
        } catch (\Throwable) {
            // a failed fill only costs the next read
        }
    }

    public function forget(string $templateName): void
    {
        if (!self::isAvailable()) {
            return;
        }

        try {
            \apcu_delete($this->prefix . $templateName);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Remove a template pointer directly from the shared APCu segment.
     *
     * Static so callers can evict entries even without a cache instance.
     *
     * @param string $path         Cache directory namespace.
     * @param string $templateName Logical template name.
     */
    public static function evict(string $path, string $templateName): void
    {
        if (!self::isAvailable()) {
            return;
        }

        try {
            \apcu_delete(self::prefixFor($path) . $templateName);
        } catch (\Throwable) {
            // an entry that cannot be removed still costs only the next read
        }
    }

    /**
     * Remove all APCu pointers for a cache directory after `Cache::flush()`.
     *
     * The directory-specific prefix preserves isolation between caches.
     *
     * @param string $path Cache directory to clear.
     */
    public static function evictDirectory(string $path): void
    {
        if (!self::isAvailable() || !\class_exists(\APCUIterator::class)) {
            return;
        }

        try {
            $iterator = new \APCUIterator(
                '/^' . \preg_quote(self::prefixFor($path), '/') . '/',
                \APC_ITER_KEY,
            );

            $keys = [];

            foreach ($iterator as $item) {
                if (\is_array($item) && \is_string($item['key'] ?? null)) {
                    $keys[] = $item['key'];
                }
            }

            if ($keys !== []) {
                \apcu_delete($keys);
            }
        } catch (\Throwable) {
            // the files are gone either way; a surviving entry costs a regeneration
        }
    }
}
