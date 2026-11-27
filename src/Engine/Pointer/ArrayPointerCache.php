<?php

namespace Clarity\Engine\Pointer;

/**
 * In-memory array-backed {@see PointerCache}.
 *
 * This cache stores pointers in a local array, making it suitable for persistent worker processes.
 */
final class ArrayPointerCache implements PointerCache
{
    private readonly string $prefix;

    private array $cache = [];

    /**
     * @param string $path The cache directory this cache belongs to (the namespace).
     */
    public function __construct(string $path)
    {
        $this->prefix = $path . DIRECTORY_SEPARATOR;
    }

    public function get(string $templateName): ?array
    {
        return $this->cache[$this->prefix . $templateName] ?? null;
    }

    public function put(string $templateName, array $pointer): void
    {
        $this->cache[$this->prefix . $templateName] = $pointer;
    }

    public function forget(string $templateName): void
    {
        unset($this->cache[$this->prefix . $templateName]);
    }
}
