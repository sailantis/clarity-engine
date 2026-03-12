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

### load() · [source](../../src/Template/TemplateLoader.php#L32)

`public function load(string $name): Clarity\Template\TemplateSource`

Load a template by its logical name and return source with revision metadata.

The revision ({@see \TemplateSource::$revision}) must be available immediately
with minimal I/O (e.g. a filemtime() call for file-based loaders); the actual
template source could be fetched lazily via [`TemplateSource::getCode()`](Clarity_Template_TemplateSource.md#getcode)
only when the engine determines compilation is needed.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name, e.g. 'home', 'admin::dashboard',<br>'layouts/base'. Must not be empty. |

**➡️ Return value**

- Type: [TemplateSource](Clarity_Template_TemplateSource.md)

**⚠️ Throws**

- RuntimeException  If the template cannot be found or loaded.


---

### exists() · [source](../../src/Template/TemplateLoader.php#L42)

`public function exists(string $name): bool`

Check whether a template with the given logical name is available.

Must not read template content — only confirm availability using cheap
metadata operations (e.g. is_file(), array key check).

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name. |

**➡️ Return value**

- Type: bool



---

[Back to the Index ⤴](README.md)
