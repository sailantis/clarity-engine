<?php
namespace Clarity\Localization;

/**
 * Interface for translation loaders.
 *
 * A translation loader is responsible for loading flat key → message maps for
 * a given domain and locale. The `TranslationModule` uses the loader to fetch
 * translations on demand, and caches them internally.
 *
 * The `FileTranslationLoader` is provided as a convenient implementation that
 * supports multiple file formats (PHP, JSON, YAML) and caching via generated
 * PHP files.
 */
interface TranslationLoaderInterface
{
    /**
     * Load flat key => message pairs for a domain+locale.
     *
     * @return array<string,string>
     */
    public function load(string $domain, string $locale): array;
}
