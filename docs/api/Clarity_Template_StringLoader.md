# 🧩 Class: StringLoader

**Full name:** [Clarity\Template\StringLoader](../../src/Template/StringLoader.php)

Single-template loader that wraps one hardcoded template string.

Useful for rendering one dynamically-built or user-supplied template
without touching the filesystem.

```php
$loader = new StringLoader('dynamic', '<p>{{ message }}</p>');
$engine->setLoader($loader);
echo $engine->render('dynamic', ['message' => 'Hello!']);
```

## 🚀 Public methods

### __construct() · [source](../../src/Template/StringLoader.php#L25)

`public function __construct(string $name, string $code): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name used to reference this template. |
| `$code` | string | - | Raw template source. |

**➡️ Return value**

- Type: mixed


---

### load() · [source](../../src/Template/StringLoader.php#L36)

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

### getSubLoaders() · [source](../../src/Template/StringLoader.php#L51)

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used by the engine to traverse loader hierarchies (e.g. DomainRouterLoader → FileLoader) and apply configuration changes like setExtension() to all relevant loaders.

**➡️ Return value**

- Type: array
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.


---

### update() · [source](../../src/Template/StringLoader.php#L61)

`public function update(string $code): static`

Replace the template source.

The revision changes automatically so the next render triggers recompilation.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |

**➡️ Return value**

- Type: static



---

[Back to the Index ⤴](README.md)
