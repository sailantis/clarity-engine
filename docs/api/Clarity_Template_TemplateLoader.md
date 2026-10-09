# Interface: TemplateLoader

**Full name:** [Clarity\Template\TemplateLoader](../../src/Template/TemplateLoader.php)

Abstraction over template sources.

A loader translates a logical template name (e.g. 'home', 'admin::dashboard',
'layouts/base') into a [`TemplateSource`](Clarity_Template_TemplateSource.md) containing the revision metadata
and, lazily, the raw source code.

Implementations:
 - [`FileLoader`](Clarity_Template_FileLoader.md)   — reads from the filesystem (default)
 - [`ArrayLoader`](Clarity_Template_ArrayLoader.md)  — serves templates from an in-memory array
 - [`StringLoader`](Clarity_Template_StringLoader.md) — wraps a single hardcoded template string

Custom loaders can read from databases, remote APIs, PHAR archives, and similar sources.

## Public methods

### load() · <small>[🗎](../../src/Template/TemplateLoader.php#L32)</small>

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

### getSubLoaders() · <small>[🗎](../../src/Template/TemplateLoader.php#L42)</small>

`public function getSubLoaders(): array`

Return the loaders wrapped by this loader.

The engine uses this to traverse loader hierarchies, for example to apply setExtension()
to every FileLoader beneath a DomainRouterLoader.

**Return value**

- Type: `array`
- Description: The wrapped loaders, or an empty array for a leaf loader.



---

[Back to the Index ⤴](README.md)
