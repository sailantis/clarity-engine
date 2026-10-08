<?php

namespace Clarity\Localization;

/**
 * In-memory translation loader backed by a plain PHP array.
 *
 * Useful for unit tests, translations shipped as code rather than as files, and
 * overlaying a small set of programmatic overrides onto a
 * {@see FileTranslationLoader} through a {@see ChainTranslationLoader}:
 *
 * ```php
 * $loader = new ArrayTranslationLoader([
 *     'messages' => [
 *         'de_DE' => ['greeting' => 'Hallo', 'nav' => ['home' => 'Startseite']],
 *         'en_US' => ['greeting' => 'Hello'],
 *     ],
 * ]);
 * $engine->addModule(new TranslationModule(['loader' => $loader]));
 * ```
 *
 * Keys may be nested; they are flattened with dot separators, so the `nav` entry
 * above resolves as `{{ "nav.home" |> t }}`. No file I/O takes place, and a
 * missing domain or locale yields an empty array rather than an error, which is
 * how the module distinguishes "nothing here" from "look elsewhere".
 *
 * Flattening happens once, in the constructor, and `load()` returns the map as
 * stored — a lookup is an array read and nothing else. A loader that flattened
 * on every `load()` would have to un-flatten on every write, which is the same
 * work twice and makes a single-key write rewrite its whole branch.
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
