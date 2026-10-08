# Interface: TranslationLoaderInterface

**Full name:** [Clarity\Localization\TranslationLoaderInterface](../../src/Localization/TranslationLoaderInterface.php)

Interface for translation loaders.

A translation loader is responsible for loading flat key → message maps for
a given domain and locale. The `TranslationModule` uses the loader to fetch
translations on demand, once per lookup. Caching is the loader's concern:
`FileTranslationLoader` compiles source files to PHP,
`RedisCachingLoader` caches any loader in Redis.

The `FileTranslationLoader` is provided as a convenient implementation that
supports multiple file formats (PHP, JSON, YAML) and caching via generated
PHP files.

## Public methods

### load() · <small>[🗎](../../src/Localization/TranslationLoaderInterface.php#L24)</small>

`public function load(string $domain, string $locale): array`

Load flat key => message pairs for a domain+locale.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
