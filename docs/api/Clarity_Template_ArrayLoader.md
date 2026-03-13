# 🧩 Class: ArrayLoader

**Full name:** [Clarity\Template\ArrayLoader](../../src/Template/ArrayLoader.php)

In-memory template loader backed by a plain PHP array.

Ideal for unit testing, dynamic/generated templates, and small applications
that keep all templates in code rather than on the filesystem.

Cache revision is derived from the source string via hash('fnv1a64', $code).
No file I/O takes place at any point.

```php
$loader = new ArrayLoader([
    'home'         => '<h1>Hello {{ name }}</h1>',
    'layouts.base' => '<!DOCTYPE html><body>{% block content %}{% endblock %}</body>',
]);
$engine->setLoader($loader);
```

## 🚀 Public methods

### __construct() · [source](../../src/Template/ArrayLoader.php#L29)

`public function __construct(array $templates = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templates` | array | `[]` | Map of logical name → raw template source. |

**➡️ Return value**

- Type: mixed


---

### load() · [source](../../src/Template/ArrayLoader.php#L37)

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

### getSubLoaders() · [source](../../src/Template/ArrayLoader.php#L52)

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used by the engine to traverse loader hierarchies (e.g. DomainRouterLoader → FileLoader) and apply configuration changes like setExtension() to all relevant loaders.

**➡️ Return value**

- Type: array
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.


---

### set() · [source](../../src/Template/ArrayLoader.php#L63)

`public function set(string $name, string $code): static`

Add or replace a template definition.

The cache for the template will be invalidated on the next render because
the fnv1a64 revision of the new code will differ from the stored revision.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |
| `$code` | string | - |  |

**➡️ Return value**

- Type: static



---

[Back to the Index ⤴](README.md)
