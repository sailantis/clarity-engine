# Class: FileTranslationLoader

**Full name:** [Clarity\Localization\FileTranslationLoader](../../src/Localization/FileTranslationLoader.php)

File-based translation loader with support for PHP, JSON, and YAML formats.

This loader looks for translation files in a specified directory following the
naming convention `{domain}.{locale}.{ext}` (e.g. `messages.de_DE.yaml`).

Supported formats:
  - YAML: flat or nested key → message mappings (nested keys flattened to dot notation).
  - JSON: flat or nested key → message mappings (nested keys flattened to dot notation).
  - PHP: flat or nested key → message mappings (nested keys flattened to dot notation).

Only the first existing file for a domain and locale is loaded, in the order
`.yaml`, `.yml`, `.json`, `.php`.

Each source file is compiled to a PHP cache file. The cache is reused while it is
at least as new as its source and is regenerated when the source file is newer.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/FileTranslationLoader.php#L30)</small>

`public function __construct(string $translationsPath, string|null $cachePath = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$translationsPath` | string | - | Directory containing the translation files. |
| `$cachePath` | string\|null | `null` | Directory for generated caches. Defaults to<br>`sys_get_temp_dir()/clarity_translations/<md5 of translationsPath>`. |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Localization/FileTranslationLoader.php#L46)</small>

`public function load(string $domain, string $locale): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`

**Throws**

- RuntimeException  If a source file is unreadable or yields no message table.
- JsonException  If a JSON source file is malformed.



---

[Back to the Index ⤴](README.md)
