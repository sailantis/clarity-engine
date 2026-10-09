<?php
namespace Clarity\Localization;

/**
 * Composite translation loader that chains multiple loaders together.
 *
 * The `ChainTranslationLoader` accepts multiple `TranslationLoaderInterface`
 * instances and queries each of them, in order, for a given domain and locale.
 * Every loader is called; there is no short-circuit. The results are merged, and
 * later loaders override earlier ones on key conflicts.
 *
 * This allows you to combine different loading strategies, such as a
 * `FileTranslationLoader` for disk-based translations and an `ArrayTranslationLoader`
 * for programmatically defined translations.
 */
class ChainTranslationLoader implements TranslationLoaderInterface
{
    /** @var TranslationLoaderInterface[] */
    private array $loaders;

    public function __construct(TranslationLoaderInterface ...$loaders)
    {
        $this->loaders = $loaders;
    }

    public function load(string $domain, string $locale): array
    {
        $result = [];
        foreach ($this->loaders as $loader) {
            $result = [...$result, ...$loader->load($domain, $locale)];
        }
        return $result;
    }
}
