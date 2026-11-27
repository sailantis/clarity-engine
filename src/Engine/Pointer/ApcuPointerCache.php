<?php

namespace Clarity\Engine\Pointer;

/**
 * APCu-backed {@see PointerCache}.
 *
 * Keys are prefixed with a digest of the cache directory. Under FPM every worker
 * shares one APCu segment, so without the prefix two engines in one process — a
 * test harness plus the application, or two tenants — would read each other's
 * pointers for the same template name.
 *
 * Every failure is swallowed: APCu is an optimisation, and a fill or an eviction
 * that cannot happen only costs the next read. Nothing here may break a render.
 */
final class ApcuPointerCache implements PointerCache
{
    /** Whether the extension is usable; resolved once. */
    private static ?bool $available = null;

    private readonly string $prefix;

    /**
     * @param string $path The cache directory this cache belongs to (the namespace).
     */
    public function __construct(string $path)
    {
        $this->prefix = 'clarity.ptr.' . \hash('xxh3', $path) . '.';
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
}
