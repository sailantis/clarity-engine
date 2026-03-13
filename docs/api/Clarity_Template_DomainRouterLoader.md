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

### addDomainLoader() · [source](../../src/Template/DomainRouterLoader.php#L48)

`public function addDomainLoader(string $domain, Clarity\Template\TemplateLoader $loader): void`

Add or replace a domain loader at runtime.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - | Domain prefix to route (e.g. "app"). |
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - | Loader to handle templates for this domain. |

**➡️ Return value**

- Type: void


---

### setFallbackLoader() · [source](../../src/Template/DomainRouterLoader.php#L58)

`public function setFallbackLoader(Clarity\Template\TemplateLoader|null $loader): void`

Set or replace the fallback loader for templates without a domain prefix.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md)\|null | - | Loader to handle templates without a domain, or null to disable. |

**➡️ Return value**

- Type: void


---

### getDomainLoaders() · [source](../../src/Template/DomainRouterLoader.php#L68)

`public function getDomainLoaders(): array`

Get the currently configured domain loaders.

**➡️ Return value**

- Type: array
- Description: Associative array of domain => loader mappings.


---

### getFallbackLoader() · [source](../../src/Template/DomainRouterLoader.php#L78)

`public function getFallbackLoader(): Clarity\Template\TemplateLoader|null`

Get the currently configured fallback loader.

**➡️ Return value**

- Type: [TemplateLoader](Clarity_Template_TemplateLoader.md)|null
- Description: The fallback loader, or null if none is set.


---

### load() · [source](../../src/Template/DomainRouterLoader.php#L86)

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

### getSubLoaders() · [source](../../src/Template/DomainRouterLoader.php#L107)

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used by the engine to traverse loader hierarchies (e.g. DomainRouterLoader → FileLoader) and apply configuration changes like setExtension() to all relevant loaders.

**➡️ Return value**

- Type: array
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.



---

[Back to the Index ⤴](README.md)
