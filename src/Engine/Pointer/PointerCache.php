<?php

namespace Clarity\Engine\Pointer;

/**
 * Optional in-memory cache for compiled-template pointers.
 *
 * Pointer files remain the durable record and are always written by
 * {@see \Clarity\Engine\Cache}; cache misses fall back to those files. An APCu
 * hit takes about 0.04 µs compared with 35–104 µs for a file read. Under FPM,
 * workers share APCu; its data survives reloads and is cleared on full restart.
 */
interface PointerCache
{
    /**
     * A pointer previously cached for this template, or null on a miss.
     *
     * A miss is normal and must never be treated as "this template is not
     * compiled" — the caller falls back to the pointer file.
     *
     * @return array{class: string, version: int, deps: array<string, int|string>}|null
     */
    public function get(string $templateName): ?array;

    /**
     * Remember a pointer.
     *
     * @param array{class: string, version: int, deps: array<string, int|string>} $pointer
     */
    public function put(string $templateName, array $pointer): void;

    /** Forget one template's pointer. */
    public function forget(string $templateName): void;
}
