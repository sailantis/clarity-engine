# 🧩 Class: TranslationLoader

**Full name:** [Clarity\Localization\TranslationLoader](../../src/Localization/TranslationLoader.php)

Ultra‑performanter Translation Loader

Erwartet Übersetzungsdateien als PHP Dateien, z. B. en_US.php:
<?php return ['greeting' => 'Hello, {name}!'];

## 🚀 Public methods

### __construct() · [source](../../src/Localization/TranslationLoader.php#L24)

`public function __construct(string|null $path, string $fallbackLocale = 'en_US'): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string\|null | - |  |
| `$fallbackLocale` | string | `'en_US'` |  |

**➡️ Return value**

- Type: mixed


---

### get() · [source](../../src/Localization/TranslationLoader.php#L37)

`public function get(string $locale, string $key, array $vars = []): string`

Schneller Lookup mit Fallback und Platzhalterersetzung.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - |  |
| `$key` | string | - |  |
| `$vars` | array | `[]` |  |

**➡️ Return value**

- Type: string


---

### plural() · [source](../../src/Localization/TranslationLoader.php#L65)

`public function plural(string $locale, string $key, int $count): string`

Pluralisierte Nachricht. Nutzt MessageFormatter wenn verfügbar und cached Formatter.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - |  |
| `$key` | string | - |  |
| `$count` | int | - |  |

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
