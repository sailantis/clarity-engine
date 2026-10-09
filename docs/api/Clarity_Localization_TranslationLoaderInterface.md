# Interface: TranslationLoaderInterface

**Full name:** [Clarity\Localization\TranslationLoaderInterface](../../src/Localization/TranslationLoaderInterface.php)

Interface for translation loaders.

A loader supplies the flat key => message map for one domain and locale.
`TranslationModule` calls `load()` on every lookup, and calls it a second time
when the requested locale lacks the key and the fallback locale differs. The module
caches nothing, so expensive loaders should be wrapped in `RedisCachingLoader`.

`FileTranslationLoader` reads PHP, JSON, and YAML catalogues and caches them as
PHP files. `RedisCachingLoader` caches the output of any loader in Redis.

## Public methods

### load() · <small>[🗎](../../src/Localization/TranslationLoaderInterface.php#L22)</small>

`public function load(string $domain, string $locale): array`

Load flat key => message pairs for a domain and locale.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`
- Description: Empty when no messages exist.



---

[Back to the Index ⤴](README.md)
