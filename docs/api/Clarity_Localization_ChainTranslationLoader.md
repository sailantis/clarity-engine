# 🧩 Class: ChainTranslationLoader

**Full name:** [Clarity\Localization\ChainTranslationLoader](../../src/Localization/ChainTranslationLoader.php)

Composite translation loader that chains multiple loaders together.

The `ChainTranslationLoader` accepts multiple `TranslationLoaderInterface`
instances and queries them in order when loading translations for a
given domain and locale. The results from all loaders are merged, with
later loaders overriding earlier ones in case of key conflicts.

This allows you to combine different loading strategies, such as a
`FileTranslationLoader` for disk-based translations and an `ArrayTranslationLoader`
for programmatically defined translations.

## 🚀 Public methods

### __construct() · [source](../../src/Localization/ChainTranslationLoader.php#L21)

`public function __construct(Clarity\Localization\TranslationLoaderInterface ...$loaders): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loaders` | [TranslationLoaderInterface](Clarity_Localization_TranslationLoaderInterface.md) | - |  |

**➡️ Return value**

- Type: mixed


---

### load() · [source](../../src/Localization/ChainTranslationLoader.php#L26)

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
