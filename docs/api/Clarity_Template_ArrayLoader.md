# Class: ArrayLoader

**Full name:** [Clarity\Template\ArrayLoader](../../src/Template/ArrayLoader.php)

In-memory template loader backed by a plain PHP array.

Suited to unit tests, generated templates, and small applications that define
all templates in code rather than on the filesystem.

The cache revision is the fnv1a64 hash of the source, computed via
hash('fnv1a64', $code). The loader performs no file I/O.

```php
$loader = new ArrayLoader([
    'home'         => '<h1>Hello {{ name }}</h1>',
    'layouts.base' => '<!DOCTYPE html><body>{% block content %}{% endblock %}</body>',
]);
$engine->setLoader($loader);
```

## Public methods

### __construct() · <small>[🗎](../../src/Template/ArrayLoader.php#L29)</small>

`public function __construct(array $templates = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templates` | array | `[]` | Map of logical name → raw template source. |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Template/ArrayLoader.php#L37)</small>

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

### getSubLoaders() · <small>[🗎](../../src/Template/ArrayLoader.php#L52)</small>

`public function getSubLoaders(): array`

Return the loaders wrapped by this loader.

The engine uses this to traverse loader hierarchies, for example to apply setExtension()
to every FileLoader beneath a DomainRouterLoader.

**Return value**

- Type: `array`
- Description: The wrapped loaders, or an empty array for a leaf loader.


---

### set() · <small>[🗎](../../src/Template/ArrayLoader.php#L63)</small>

`public function set(string $name, string $code): static`

Add or replace a template definition.

The revision is computed from the source on each load, so the next render
recompiles the template if its source has changed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |
| `$code` | string | - |  |

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
