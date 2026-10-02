# Class: FileLoader

**Full name:** [Clarity\Template\FileLoader](../../src/Template/FileLoader.php)

Filesystem-backed template loader.

Converts logical template names to file paths UNDER the configured base path.
Dots and slashes are interchangeable as directory separators, and the result
can never leave the base path:

  'home'               → {basePath}/home{ext}
  'layouts/base'       → {basePath}/layouts/base{ext}
  'layouts.base'       → {basePath}/layouts/base{ext}   (same thing)
  'admin.user.profile' → {basePath}/admin/user/profile{ext}
  'admin::dashboard'   → {namespaces[admin]}/dashboard{ext}

Names are validated rather than merely sanitized, because a template name can
originate OUTSIDE the application: `render()` is often handed a name derived
from a request, and a template may be stored in a database. Two classes of
name are therefore rejected outright, with a ClarityException:

  - **Absolute paths** (leading `/`, a Windows drive, or a UNC share). A
    template name locates a template; it is not a general-purpose file read.
    This was previously accepted for convenience, which made a template
    name — and a host that forwards user input into one — an arbitrary file
    reader.
  - **Parent references** (any `.` or `..` segment, e.g. `../secret` or
    `a/../../b`). A template is addressed from the base path downward.
    Reaching a sibling tree is what namespaces (`addNamespace()`) are for,
    and an explicit namespace is visible in the configuration rather than
    buried in a template.

`load()` calls filemtime() eagerly (cheap metadata syscall) and defers
file_get_contents() until getCode() is called — zero I/O on warm cache paths.

## Public Constants

- **DEFAULT_EXTENSION** = `'.clarity.html'`
- **OUTSIDE_BASE_MESSAGE** = `'resolves outside the view path'`

## Public methods

### __construct() · <small>[🗎](../../src/Template/FileLoader.php#L62)</small>

`public function __construct(string $basePath, string|null $extension = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$basePath` | string | - | Base directory for template resolution. |
| `$extension` | string\|null | `null` | File extension with or without leading dot. |

**Return value**

- Type: `mixed`


---

### setExtension() · <small>[🗎](../../src/Template/FileLoader.php#L81)</small>

`public function setExtension(string $extension): static`

Set the view file extension for this instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$extension` | string | - | Extension with or without a leading dot. |

**Return value**

- Type: `static`


---

### getExtension() · <small>[🗎](../../src/Template/FileLoader.php#L99)</small>

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**Return value**

- Type: `string`
- Description: Extension including leading dot or empty string.


---

### setBasePath() · <small>[🗎](../../src/Template/FileLoader.php#L110)</small>

`public function setBasePath(string $path): static`

Set the base path for resolving relative template names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**Return value**

- Type: `static`


---

### getBasePath() · <small>[🗎](../../src/Template/FileLoader.php#L122)</small>

`public function getBasePath(): string`

Get the currently configured base path for template resolution.

**Return value**

- Type: `string`
- Description: Base directory for templates.


---

### load() · <small>[🗎](../../src/Template/FileLoader.php#L130)</small>

`public function load(string $name): Clarity\Template\TemplateSource|null`

Load a template by its logical name and return source with revision metadata.

The revision ({@see \TemplateSource::$revision}) must be available immediately with minimal I/O (e.g. a filemtime() call for file-based loaders); the actual template source could be fetched lazily via [`TemplateSource::getCode()`](Clarity_Template_TemplateSource.md#getcode) only when the engine determines compilation is needed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name, e.g. 'home', 'admin::dashboard',<br>'layouts/base'. Must not be empty. |

**Return value**

- Type: [TemplateSource](Clarity_Template_TemplateSource.md)|`null`

**Throws**

- RuntimeException  If the template cannot be found or loaded.


---

### resolveName() · <small>[🗎](../../src/Template/FileLoader.php#L161)</small>

`public function resolveName(string $name): string`

Resolve a logical template name to a path under the base path.

Public so it can be used for diagnostic/debugging purposes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `string`

**Throws**

- [ClarityException](Clarity_ClarityException.md)  When the name is absolute or contains a `.`/`..`
segment, i.e. when it would resolve outside the
base path.


---

### getSubLoaders() · <small>[🗎](../../src/Template/FileLoader.php#L250)</small>

`public function getSubLoaders(): array`

Return the list of loaders wrapped by this loader, if any.

Used by the engine to traverse loader hierarchies (e.g. DomainRouterLoader → FileLoader) and apply configuration changes like setExtension() to all relevant loaders.

**Return value**

- Type: `array`
- Description: List of loaders wrapped by this loader, or an empty array if this loader is not a wrapper.



---

[Back to the Index ⤴](README.md)
