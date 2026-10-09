# Class: ChainTranslationLoader

**Full name:** [Clarity\Localization\ChainTranslationLoader](../../src/Localization/ChainTranslationLoader.php)

Composite translation loader that chains multiple loaders together.

The `ChainTranslationLoader` accepts multiple `TranslationLoaderInterface`
instances and queries each of them, in order, for a given domain and locale.
Every loader is called; there is no short-circuit. The results are merged, and
later loaders override earlier ones on key conflicts.

This allows you to combine different loading strategies, such as a
`FileTranslationLoader` for disk-based translations and an `ArrayTranslationLoader`
for programmatically defined translations.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/ChainTranslationLoader.php#L21)</small>

`public function __construct(Clarity\Localization\TranslationLoaderInterface ...$loaders): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loaders` | [TranslationLoaderInterface](Clarity_Localization_TranslationLoaderInterface.md) | - |  |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Localization/ChainTranslationLoader.php#L26)</small>

`public function load(string $domain, string $locale): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
