# Class: ArrayTranslationLoader

**Full name:** [Clarity\Localization\ArrayTranslationLoader](../../src/Localization/ArrayTranslationLoader.php)

In-memory translation loader backed by a plain PHP array.

Useful for unit tests, translations shipped as code rather than as files, and
overlaying a small set of programmatic overrides onto a
[`FileTranslationLoader`](Clarity_Localization_FileTranslationLoader.md) through a [`ChainTranslationLoader`](Clarity_Localization_ChainTranslationLoader.md):

```php
$loader = new ArrayTranslationLoader([
    'messages' => [
        'de_DE' => ['greeting' => 'Hallo', 'nav' => ['home' => 'Startseite']],
        'en_US' => ['greeting' => 'Hello'],
    ],
]);
$engine->addModule(new TranslationModule(['loader' => $loader]));
```

Keys may be nested; they are flattened with dot separators, so the `nav` entry
above resolves as `{{ "nav.home" |> t }}`. No file I/O takes place, and a
missing domain or locale yields an empty array rather than an error, which is
how the module distinguishes "nothing here" from "look elsewhere".

Flattening happens once, in the constructor, and `load()` returns the map as
stored — a lookup is an array read and nothing else. A loader that flattened
on every `load()` would have to un-flatten on every write, which is the same
work twice and makes a single-key write rewrite its whole branch.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/ArrayTranslationLoader.php#L47)</small>

`public function __construct(array $messages = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$messages` | array | `[]` | `[domain][locale] → message table`, each table flat or nested. |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Localization/ArrayTranslationLoader.php#L56)</small>

`public function load(string $domain, string $locale): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`


---

### set() · <small>[🗎](../../src/Localization/ArrayTranslationLoader.php#L74)</small>

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
