# 🔌 Interface: TemplateLoader

**Full name:** [Clarity\Template\TemplateLoader](../../src/Template/TemplateLoader.php)

Abstraction over template sources.

A loader translates a logical template name (e.g. 'home', 'admin::dashboard',
'layouts/base') into a [`TemplateSource`](Clarity_Template_TemplateSource.md) containing the revision metadata
and, lazily, the raw source code.

Implementations:
 - [`FileLoader`](Clarity_Template_FileLoader.md)   — reads from the filesystem (default)
 - [`ArrayLoader`](Clarity_Template_ArrayLoader.md)  — serves templates from an in-memory array
 - [`StringLoader`](Clarity_Template_StringLoader.md) — wraps a single hardcoded template string

Custom loaders may source templates from databases, remote APIs, PHAR archives, etc.

## 🚀 Public methods

### load() · [source](../../src/Template/TemplateLoader.php#L29)

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

### getSubLoaders() · [source](../../src/Template/TemplateLoader.php#L38)

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used by the engine to traverse loader hierarchies (e.g. DomainRouterLoader → FileLoader) and apply configuration changes like setExtension() to all relevant loaders.

**➡️ Return value**

- Type: array
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.



---

[Back to the Index ⤴](README.md)
