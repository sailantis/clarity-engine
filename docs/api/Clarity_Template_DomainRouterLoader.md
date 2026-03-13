# 🧩 Class: DomainRouterLoader

**Full name:** [Clarity\Template\DomainRouterLoader](../../src/Template/DomainRouterLoader.php)

TemplateLoader that dispatches to different loaders based on a domain prefix in the template name.

The template name is expected to be in the format "domain::localName".  The loader looks up the
domain in its map and forwards the load request to the corresponding loader with the localName.

If no "::" is present, the entire name is treated as localName and passed to a fallback loader if configured.

```php
$loader = new DomainRouterLoader([
    'app' => new FilesystemLoader('/path/to/app/templates'),
    'lib' => new FilesystemLoader('/path/to/lib/templates'),
], fallback: new FilesystemLoader('/path/to/default/templates'));
$engine->setLoader($loader);

// Resolves to /path/to/app/templates/home.html
echo $engine->render('app::home');

// Resolves to /path/to/lib/templates/widget.html
echo $engine->render('lib::widget');

// Resolves to /path/to/default/templates/other.html
echo $engine->render('other');
```

## 🚀 Public methods

### __construct() · [source](../../src/Template/DomainRouterLoader.php#L36)

`public function __construct(array $domainLoaders, Clarity\Template\TemplateLoader|null $fallback = null): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domainLoaders` | array | - |  |
| `$fallback` | [TemplateLoader](Clarity_Template_TemplateLoader.md)\|null | `null` |  |

**➡️ Return value**

- Type: mixed


---

### load() · [source](../../src/Template/DomainRouterLoader.php#L48)

`public function load(string $name): Clarity\Template\TemplateSource|null`

Load a template by its logical name and return source with revision metadata.

The revision ({@see \TemplateSource::$revision}) must be available immediately with minimal I/O (e.g. a filemtime() call for file-based loaders); the actual template source could be fetched lazily via [`TemplateSource::getCode()`](Clarity_Template_TemplateSource.md#getcode) only when the engine determines compilation is needed.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name, e.g. 'home', 'admin::dashboard',<br>'layouts/base'. Must not be empty. |

**➡️ Return value**

- Type: [TemplateSource](Clarity_Template_TemplateSource.md)|null

**⚠️ Throws**

- RuntimeException  If the template cannot be found or loaded.


---

### getSubLoaders() · [source](../../src/Template/DomainRouterLoader.php#L69)

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used for introspection and debugging; not used by the engine itself.

**➡️ Return value**

- Type: array
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.



---

[Back to the Index ⤴](README.md)
