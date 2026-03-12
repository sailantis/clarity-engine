# 🔌 Interface: TranslationLoaderInterface

**Full name:** [Clarity\Localization\TranslationLoaderInterface](../../src/Localization/TranslationLoaderInterface.php)

Interface for translation loaders.

A translation loader is responsible for loading flat key → message maps for
a given domain and locale. The `TranslationModule` uses the loader to fetch
translations on demand, and caches them internally.

The `FileTranslationLoader` is provided as a convenient implementation that
supports multiple file formats (PHP, JSON, YAML) and caching via generated
PHP files.

## 🚀 Public methods

### load() · [source](../../src/Localization/TranslationLoaderInterface.php#L22)

`public function load(string $domain, string $locale): array`

Load flat key => message pairs for a domain+locale.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**➡️ Return value**

- Type: array



---

[Back to the Index ⤴](README.md)
