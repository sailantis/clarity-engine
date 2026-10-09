<?php
namespace Clarity\Localization;

/**
 * Interface for translation loaders.
 *
 * A loader supplies the flat key => message map for one domain and locale.
 * `TranslationModule` calls `load()` on every lookup, and calls it a second time
 * when the requested locale lacks the key and the fallback locale differs. The module
 * caches nothing, so expensive loaders should be wrapped in `RedisCachingLoader`.
 *
 * `FileTranslationLoader` reads PHP, JSON, and YAML catalogues and caches them as
 * PHP files. `RedisCachingLoader` caches the output of any loader in Redis.
 */
interface TranslationLoaderInterface
{
    /**
     * Load flat key => message pairs for a domain and locale.
     *
     * @return array<string,string> Empty when no messages exist.
     */
    public function load(string $domain, string $locale): array;
}
