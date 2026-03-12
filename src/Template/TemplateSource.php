<?php
namespace Clarity\Template;

/**
 * Value object returned by a {@see TemplateLoader}.
 *
 * Carries two pieces of information:
 *  - **revision**: a cheap-to-obtain opaque scalar used for cache invalidation.
 *    File-based loaders use the unix mtime (int); memory-based loaders use an
 *    fnv1a64 hash of the source string (string via hash('fnv1a64', $code)).
 *  - **codeLoader**: a closure that fetches the actual source code only when
 *    the engine determines that compilation is necessary.  On warm cache paths
 *    (cache is still fresh) getCode() is never called, avoiding unnecessary I/O.
 */
final class TemplateSource
{
    /**
     * @param int|string $revision   Opaque revision token used for cache invalidation.
     *                               int  → mtime from a file-based loader.
     *                               string → hash('fnv1a64', $code) from a memory loader.
     * @param \Closure   $codeLoader Lazy loader; called at most once per compile by the engine.
     *                               Must return the full raw template source string.
     */
    public function __construct(
        public readonly int|string $revision,
        private readonly \Closure $codeLoader,
    ) {
    }

    /**
     * Return the raw template source code.
     *
     * The closure is invoked on every call, but in practice the engine calls
     * getCode() at most once per compilation cycle (cold-path only).
     */
    public function getCode(): string
    {
        return ($this->codeLoader)();
    }
}
