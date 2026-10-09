<?php

namespace Clarity\Localization;

/**
 * In-memory translation loader backed by a plain PHP array.
 *
 * Useful for unit tests, for translations shipped as code, and for overriding
 * selected messages of a {@see FileTranslationLoader} through a
 * {@see ChainTranslationLoader}:
 *
 * ```php
 * $loader = new ChainTranslationLoader(
 *     new FileTranslationLoader($translationsPath),
 *     new ArrayTranslationLoader([
 *         'messages' => [
 *             'de_DE' => ['greeting' => 'Hallo', 'nav' => ['home' => 'Startseite']],
 *         ],
 *     ]),
 * );
 * $engine->addModule(new TranslationModule(['loader' => $loader]));
 * ```
 *
 * Nested keys are flattened with dot separators, so the `nav` entry above resolves
 * as `{{ "nav.home" |> t }}`. No file I/O takes place. A missing domain or locale
 * yields an empty array rather than an error.
 *
 * Nested tables are flattened once, in the constructor. `load()` returns the stored
 * map, so each lookup is a plain array read.
 */
final class ArrayTranslationLoader implements TranslationLoaderInterface
{
    use CatalogNormalizationTrait;

    /**
     * Flat catalogues, keyed `[domain][locale][message key]`.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $messages = [];

    /**
     * @param array<string, array<string, array<string, mixed>>> $messages
     *        `[domain][locale] → message table`, each table flat or nested.
     */
    public function __construct(array $messages = [])
    {
        foreach ($messages as $domain => $locales) {
            foreach ($locales as $locale => $table) {
                $this->messages[$domain][$locale] = $this->flattenCatalog($table);
            }
        }
    }

    public function load(string $domain, string $locale): array
    {
        return $this->messages[$domain][$locale] ?? [];
    }

    /**
     * Add or replace a single message.
     *
     * A dotted `$key` is the message key itself, not a path: it writes exactly
     * one entry and leaves every other key alone, so setting `nav.home` does not
     * disturb a separate `nav` message.
     *
     * @param string $domain  Domain to write into.
     * @param string $locale  Locale to write into.
     * @param string $key     Message key.
     * @param string $message Message text.
     * @return $this
     */
    public function set(string $domain, string $locale, string $key, string $message): static
    {
        $this->messages[$domain][$locale][$key] = $message;

        return $this;
    }
}
