# Class: ArrayTranslationLoader

**Full name:** [Clarity\Localization\ArrayTranslationLoader](../../src/Localization/ArrayTranslationLoader.php)

In-memory translation loader backed by a plain PHP array.

Useful for unit tests, for translations shipped as code, and for overriding
selected messages of a [`FileTranslationLoader`](Clarity_Localization_FileTranslationLoader.md) through a
[`ChainTranslationLoader`](Clarity_Localization_ChainTranslationLoader.md):

```php
$loader = new ChainTranslationLoader(
    new FileTranslationLoader($translationsPath),
    new ArrayTranslationLoader([
        'messages' => [
            'de_DE' => ['greeting' => 'Hallo', 'nav' => ['home' => 'Startseite']],
        ],
    ]),
);
$engine->addModule(new TranslationModule(['loader' => $loader]));
```

Nested keys are flattened with dot separators, so the `nav` entry above resolves
as `{{ "nav.home" |> t }}`. No file I/O takes place. A missing domain or locale
yields an empty array rather than an error.

Nested tables are flattened once, in the constructor. `load()` returns the stored
map, so each lookup is a plain array read.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/ArrayTranslationLoader.php#L46)</small>

`public function __construct(array $messages = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$messages` | array | `[]` | `[domain][locale] → message table`, each table flat or nested. |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Localization/ArrayTranslationLoader.php#L55)</small>

`public function load(string $domain, string $locale): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`


---

### set() · <small>[🗎](../../src/Localization/ArrayTranslationLoader.php#L73)</small>

`public function set(string $domain, string $locale, string $key, string $message): static`

Add or replace a single message.

A dotted `$key` is the message key itself, not a path: it writes exactly
one entry and leaves every other key alone, so setting `nav.home` does not
disturb a separate `nav` message.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - | Domain to write into. |
| `$locale` | string | - | Locale to write into. |
| `$key` | string | - | Message key. |
| `$message` | string | - | Message text. |

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
