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
 * Custom loaders can read from databases, remote APIs, PHAR archives, and similar sources.
 */
interface TemplateLoader
{
    /**
     * Load a template by its logical name and return its source with revision metadata.
     *
     * The revision ({@see TemplateSource::$revision}) must be cheap to obtain, for example
     * a filemtime() call for file-based loaders. The source is fetched lazily through
     * {@see TemplateSource::getCode()}, and only when the engine needs to compile.
     *
     * @param string $name Logical template name, e.g. 'home', 'admin::dashboard',
     *                     'layouts/base'. Must not be empty.
     * @return TemplateSource|null The template source, or null if this loader does not provide the template.
     * @throws \RuntimeException If the name is invalid for this loader or the lookup fails, e.g. for an unknown domain.
     */
    public function load(string $name): ?TemplateSource;

    /**
     * Return the loaders wrapped by this loader.
     *
     * The engine uses this to traverse loader hierarchies, for example to apply setExtension()
     * to every FileLoader beneath a DomainRouterLoader.
     *
     * @return TemplateLoader[] The wrapped loaders, or an empty array for a leaf loader.
     */
    public function getSubLoaders(): array;
}
