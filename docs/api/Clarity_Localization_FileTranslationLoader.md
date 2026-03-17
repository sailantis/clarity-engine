# 🧩 Class: FileTranslationLoader

**Full name:** [Clarity\Localization\FileTranslationLoader](../../src/Localization/FileTranslationLoader.php)

File-based translation loader with support for PHP, JSON, and YAML formats.

This loader looks for translation files in a specified directory following the
naming convention `{domain}.{locale}.{ext}` (e.g. `messages.de_DE.yaml`).

Supported formats:
  - YAML: flat or nested key → message mappings (nested keys flattened to dot notation).
  - JSON: flat or nested key → message mappings (nested keys flattened to dot notation).
  - PHP: flat or nested key → message mappings (nested keys flattened to dot notation).

For all files, the loader generates a cached PHP file containing
the parsed translations for faster subsequent loading. The cache is automatically invalidated when the source file changes.

## 🚀 Public methods

### __construct() · [source](../../src/Localization/FileTranslationLoader.php#L20)

`public function __construct(string $translationsPath, string|null $cachePath = null): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$translationsPath` | string | - |  |
| `$cachePath` | string\|null | `null` |  |

**➡️ Return value**

- Type: mixed


---

### load() · [source](../../src/Localization/FileTranslationLoader.php#L29)

`public function load(string $domain, string $locale): array`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**➡️ Return value**

- Type: array



---

[Back to the Index ⤴](README.md)
