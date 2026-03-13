# 🧩 Class: FileLoader

**Full name:** [Clarity\Template\FileLoader](../../src/Template/FileLoader.php)

Filesystem-backed template loader.

Converts logical template names to absolute file paths using the same
resolution rules as the classic ClarityEngine::resolveView() method:

  'home'              → {basePath}/home{ext}
  'layouts/base'      → {basePath}/layouts/base{ext}
  'layouts.base'      → {basePath}/layouts/base{ext}   (dots → slashes)
  'admin::dashboard'  → {namespaces[admin]}/dashboard{ext}
  '/abs/path'         → /abs/path (Unix absolute, used as-is)
  'C:/abs/path'       → C:/abs/path (Windows absolute, used as-is)
  '\\server\share'    → \\server\share (UNC, used as-is)
  './partial'         → {basePath}/./partial{ext}

load() calls filemtime() eagerly (cheap metadata syscall) and defers
file_get_contents() until getCode() is called — zero I/O on warm cache paths.

## 📌 Public Constants

- **DEFAULT_EXTENSION** = `'.clarity.html'`

## 🚀 Public methods

### __construct() · [source](../../src/Template/FileLoader.php#L38)

`public function __construct(string $basePath, string|null $extension = null): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$basePath` | string | - | Base directory for template resolution. |
| `$extension` | string\|null | `null` | File extension with or without leading dot. |

**➡️ Return value**

- Type: mixed


---

### setExtension() · [source](../../src/Template/FileLoader.php#L57)

`public function setExtension(string $extension): static`

Set the view file extension for this instance.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$extension` | string | - | Extension with or without a leading dot. |

**➡️ Return value**

- Type: static


---

### getExtension() · [source](../../src/Template/FileLoader.php#L72)

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**➡️ Return value**

- Type: string
- Description: Extension including leading dot or empty string.


---

### setBasePath() · [source](../../src/Template/FileLoader.php#L83)

`public function setBasePath(string $path): static`

Set the base path for resolving relative template names.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**➡️ Return value**

- Type: static


---

### getBasePath() · [source](../../src/Template/FileLoader.php#L95)

`public function getBasePath(): string`

Get the currently configured base path for template resolution.

**➡️ Return value**

- Type: string
- Description: Base directory for templates.


---

### load() · [source](../../src/Template/FileLoader.php#L103)

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

### resolveName() · [source](../../src/Template/FileLoader.php#L129)

`public function resolveName(string $name): string`

Resolve a logical template name to an absolute filesystem path.

Public so it can be used for diagnostic/debugging purposes.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: string


---

### getSubLoaders() · [source](../../src/Template/FileLoader.php#L166)

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used by the engine to traverse loader hierarchies (e.g. DomainRouterLoader → FileLoader) and apply configuration changes like setExtension() to all relevant loaders.

**➡️ Return value**

- Type: array
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.



---

[Back to the Index ⤴](README.md)
