# Class: CompositeLoader

**Full name:** [Clarity\Template\CompositeLoader](../../src/Template/CompositeLoader.php)

TemplateLoader that tries multiple loaders in sequence until one returns a result.

Suited to layering several template sources, e.g. an ArrayLoader for dynamic templates
on top of a FileLoader for static templates.

```php
$loader = new CompositeLoader(
    new ArrayLoader(['dynamic' => '<p>{{ message }}</p>']),
    new FileLoader('/path/to/static/templates'),
);
$engine->setLoader($loader);

// Resolves to the ArrayLoader template
echo $engine->render('dynamic', ['message' => 'Hello!']);

// Resolves to /path/to/static/templates/home.clarity.html
echo $engine->render('home');
```

## Public methods

### __construct() · <small>[🗎](../../src/Template/CompositeLoader.php#L29)</small>

`public function __construct(Clarity\Template\TemplateLoader ...$loaders): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loaders` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - |  |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Template/CompositeLoader.php#L37)</small>

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

### getSubLoaders() · <small>[🗎](../../src/Template/CompositeLoader.php#L51)</small>

`public function getSubLoaders(): array`

Return the loaders wrapped by this loader.

The engine uses this to traverse loader hierarchies, for example to apply setExtension()
to every FileLoader beneath a DomainRouterLoader.

**Return value**

- Type: `array`
- Description: The wrapped loaders, or an empty array for a leaf loader.



---

[Back to the Index ⤴](README.md)
