# Class: StringLoader

**Full name:** [Clarity\Template\StringLoader](../../src/Template/StringLoader.php)

Single-template loader that wraps one template string.

Suited to rendering one dynamically built or user-supplied template
without filesystem access.

```php
$loader = new StringLoader('dynamic', '<p>{{ message }}</p>');
$engine->setLoader($loader);
echo $engine->render('dynamic', ['message' => 'Hello!']);
```

## Public methods

### __construct() · <small>[🗎](../../src/Template/StringLoader.php#L25)</small>

`public function __construct(string $name, string $code): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name used to reference this template. |
| `$code` | string | - | Raw template source. |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Template/StringLoader.php#L36)</small>

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

### getSubLoaders() · <small>[🗎](../../src/Template/StringLoader.php#L51)</small>

`public function getSubLoaders(): array`

Return the loaders wrapped by this loader.

The engine uses this to traverse loader hierarchies, for example to apply setExtension()
to every FileLoader beneath a DomainRouterLoader.

**Return value**

- Type: `array`
- Description: The wrapped loaders, or an empty array for a leaf loader.


---

### update() · <small>[🗎](../../src/Template/StringLoader.php#L61)</small>

`public function update(string $code): static`

Replace the template source.

The revision is recomputed, so the next render recompiles the template if the source changed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
