<?php
namespace Clarity\Template;

/**
 * Abstraction over template sources.
 *
 * A loader translates a logical template name (e.g. 'home', 'admin::dashboard',
 * 'layouts/base') into a {@see TemplateSource} containing the revision metadata
 * and, lazily, the raw source code.
 *
 * Implementations:
 *  - {@see FileLoader}   — reads from the filesystem (default)
 *  - {@see ArrayLoader}  — serves templates from an in-memory array
 *  - {@see StringLoader} — wraps a single hardcoded template string
 *
 * Custom loaders may source templates from databases, remote APIs, PHAR archives, etc.
 */
interface TemplateLoader
{
    /**
     * Load a template by its logical name and return source with revision metadata.
     *
     * The revision ({@see TemplateSource::$revision}) must be available immediately
     * with minimal I/O (e.g. a filemtime() call for file-based loaders); the actual
     * template source could be fetched lazily via {@see TemplateSource::getCode()}
     * only when the engine determines compilation is needed.
     *
     * @param string $name Logical template name, e.g. 'home', 'admin::dashboard',
     *                     'layouts/base'. Must not be empty.
     * @throws \RuntimeException If the template cannot be found or loaded.
     */
    public function load(string $name): TemplateSource;

    /**
     * Check whether a template with the given logical name is available.
     *
     * Must not read template content — only confirm availability using cheap
     * metadata operations (e.g. is_file(), array key check).
     *
     * @param string $name Logical template name.
     */
    public function exists(string $name): bool;
}
