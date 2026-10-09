<?php
namespace Clarity\Template;

/**
 * Value object returned by a {@see TemplateLoader}.
 *
 * Carries three pieces of information:
 *  - **revision**: a cheap-to-obtain opaque scalar used for cache invalidation.
 *    File-based loaders use the unix mtime (int); memory-based loaders use an
 *    fnv1a64 hash of the source string.
 *  - **codeLoader**: a closure that fetches the actual source code only when
 *    the engine determines that compilation is necessary. On warm cache paths
 *    (cache is still fresh) getCode() is never called, avoiding unnecessary I/O.
 *  - **path**: the physical file the source was read from, or null.
 */
final class TemplateSource
{
    /**
     * @param int|string  $revision   Opaque revision token used for cache invalidation.
     *                                int    → mtime from a file-based loader.
     *                                string → hash('fnv1a64', $code) from a memory loader.
     * @param \Closure    $codeLoader Lazy loader returning the full raw template source string.
     * @param string|null $path       Physical file the source was read from, or null when
     *                                the loader has no file (e.g. ArrayLoader, StringLoader).
     *                                The compiler keeps it so errors can name the file after
     *                                the loader is no longer available.
     */
    public function __construct(
        public readonly int|string $revision,
        private readonly \Closure $codeLoader,
        public readonly ?string $path = null,
    ) {
    }

    /**
     * Return the raw template source code.
     *
     * Each call invokes the loader. The engine calls this only on the cold compile
     * path, at most once per compilation.
     */
    public function getCode(): string
    {
        return ($this->codeLoader)();
    }
}
