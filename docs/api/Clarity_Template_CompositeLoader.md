# 🧩 Class: CompositeLoader

**Full name:** [Clarity\Template\CompositeLoader](../../src/Template/CompositeLoader.php)

TemplateLoader that tries multiple loaders in sequence until one returns a result.

Useful for layering multiple sources of templates, e.g. an ArrayLoader for dynamic templates
on top of a FilesystemLoader for static templates.

```php
$loader = new CompositeLoader(
    new ArrayLoader(['dynamic' => '<p>{{ message }}</p>']),
    new FilesystemLoader('/path/to/static/templates'),
);
$engine->setLoader($loader);

// Resolves to the ArrayLoader template
echo $engine->render('dynamic', ['message' => 'Hello!']);

// Resolves to /path/to/static/templates/home.html
echo $engine->render('home');
```

## 🚀 Public methods

### __construct() · [source](../../src/Template/CompositeLoader.php#L29)

`public function __construct(Clarity\Template\TemplateLoader ...$loaders): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loaders` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - |  |

**➡️ Return value**

- Type: mixed


---

### load() · [source](../../src/Template/CompositeLoader.php#L37)

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

### getSubLoaders() · [source](../../src/Template/CompositeLoader.php#L51)

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used by the engine to traverse loader hierarchies (e.g. DomainRouterLoader → FileLoader) and apply configuration changes like setExtension() to all relevant loaders.

**➡️ Return value**

- Type: array
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.



---

[Back to the Index ⤴](README.md)
