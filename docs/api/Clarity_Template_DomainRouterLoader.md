# Class: DomainRouterLoader

**Full name:** [Clarity\Template\DomainRouterLoader](../../src/Template/DomainRouterLoader.php)

TemplateLoader that dispatches to different loaders based on a domain prefix in the template name.

The template name is expected to be in the format "domain::localName". The loader looks up the
domain in its map and forwards the load request to the corresponding loader with the localName.

If no "::" is present, the entire name is treated as localName and passed to a fallback loader if configured.
Without a fallback, such a name resolves to null.

An unregistered domain throws a RuntimeException.

```php
$loader = new DomainRouterLoader([
    'app' => new FileLoader('/path/to/app/templates'),
    'lib' => new FileLoader('/path/to/lib/templates'),
], fallback: new FileLoader('/path/to/default/templates'));
$engine->setLoader($loader);

// Resolves to /path/to/app/templates/home.clarity.html
echo $engine->render('app::home');

// Resolves to /path/to/lib/templates/widget.clarity.html
echo $engine->render('lib::widget');

// Resolves to /path/to/default/templates/other.clarity.html
echo $engine->render('other');
```

## Public methods

### __construct() · <small>[🗎](../../src/Template/DomainRouterLoader.php#L39)</small>

`public function __construct(array $domainLoaders, Clarity\Template\TemplateLoader|null $fallback = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domainLoaders` | array | - |  |
| `$fallback` | [TemplateLoader](Clarity_Template_TemplateLoader.md)\|null | `null` |  |

**Return value**

- Type: `mixed`


---

### addDomainLoader() · <small>[🗎](../../src/Template/DomainRouterLoader.php#L51)</small>

`public function addDomainLoader(string $domain, Clarity\Template\TemplateLoader $loader): void`

Add or replace a domain loader at runtime.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - | Domain prefix to route (e.g. "app"). |
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - | Loader to handle templates for this domain. |

**Return value**

- Type: `void`


---

### setFallbackLoader() · <small>[🗎](../../src/Template/DomainRouterLoader.php#L61)</small>

`public function setFallbackLoader(Clarity\Template\TemplateLoader|null $loader): void`

Set or replace the fallback loader for templates without a domain prefix.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md)\|null | - | Loader to handle templates without a domain, or null to disable. |

**Return value**

- Type: `void`


---

### getDomainLoaders() · <small>[🗎](../../src/Template/DomainRouterLoader.php#L71)</small>

`public function getDomainLoaders(): array`

Get the currently configured domain loaders.

**Return value**

- Type: `array`
- Description: Associative array of domain => loader mappings.


---

### getFallbackLoader() · <small>[🗎](../../src/Template/DomainRouterLoader.php#L81)</small>

`public function getFallbackLoader(): Clarity\Template\TemplateLoader|null`

Get the currently configured fallback loader.

**Return value**

- Type: [TemplateLoader](Clarity_Template_TemplateLoader.md)|`null`
- Description: The fallback loader, or null if none is set.


---

### load() · <small>[🗎](../../src/Template/DomainRouterLoader.php#L89)</small>

`public function load(string $name): Clarity\Template\TemplateSource|null`

Load a template by its logical name and return its source with revision metadata.

The revision ({@see \TemplateSource::$revision}) must be cheap to obtain, for example
a filemtime() call for file-based loaders. The source is fetched lazily through
[`TemplateSource::getCode()`](Clarity_Template_TemplateSource.md#getcode), and only when the engine needs to compile.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name, e.g. 'home', 'admin::dashboard',<br>'layouts/base'. Must not be empty. |

**Return value**

- Type: [TemplateSource](Clarity_Template_TemplateSource.md)|`null`
- Description: The template source, or null if this loader does not provide the template.

**Throws**

- RuntimeException  If the name is invalid for this loader or the lookup fails, e.g. for an unknown domain.


---

### getSubLoaders() · <small>[🗎](../../src/Template/DomainRouterLoader.php#L110)</small>

`public function getSubLoaders(): array`

Return the loaders wrapped by this loader.

The engine uses this to traverse loader hierarchies, for example to apply setExtension()
to every FileLoader beneath a DomainRouterLoader.

**Return value**

- Type: `array`
- Description: The wrapped loaders, or an empty array for a leaf loader.



---

[Back to the Index ⤴](README.md)
