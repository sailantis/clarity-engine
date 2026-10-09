# Class: FileLoader

**Full name:** [Clarity\Template\FileLoader](../../src/Template/FileLoader.php)

Filesystem-backed template loader.

Converts logical template names to file paths under the configured base path.
Dots, slashes, and backslashes are interchangeable as directory separators. A name
cannot resolve outside the base path by its spelling (symbolic links inside the base
are not checked):

  'home'               → {basePath}/home{ext}
  'layouts/base'       → {basePath}/layouts/base{ext}
  'layouts.base'       → {basePath}/layouts/base{ext}   (same thing)
  'admin.user.profile' → {basePath}/admin/user/profile{ext}

Names are validated, not merely sanitized, because a name can come from outside
the application (for example a request parameter). Names that would resolve outside
the base path are rejected with a ClarityException, in two forms:

  - **Absolute paths** (leading `/`, a Windows drive, or a UNC share). A template
    name locates a template; it is not a file read.
  - **Parent references** (any name containing `..`, e.g. `../secret` or `a/../../b`).
    Templates are addressed downward from the base path. To read another tree,
    register it as a namespace with `addNamespace()`.

Empty segments (such as `a//b`) and control characters are also rejected.

`load()` calls filemtime() eagerly, a cheap metadata lookup, and defers
file_get_contents() until getCode(). When the compiled cache is fresh, getCode()
is never called, so no template content is read.

## Public Constants

- **DEFAULT_EXTENSION** = `'.clarity.html'`
- **OUTSIDE_BASE_MESSAGE** = `'resolves outside the view path'`

## Public methods

### __construct() · <small>[🗎](../../src/Template/FileLoader.php#L57)</small>

`public function __construct(string $basePath, string|null $extension = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$basePath` | string | - | Base directory for template resolution. |
| `$extension` | string\|null | `null` | File extension with or without leading dot. |

**Return value**

- Type: `mixed`


---

### setExtension() · <small>[🗎](../../src/Template/FileLoader.php#L77)</small>

`public function setExtension(string $extension): static`

Set the view file extension for this instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$extension` | string | - | Extension with or without a leading dot. An empty<br>string disables extension appending. |

**Return value**

- Type: `static`


---

### getExtension() · <small>[🗎](../../src/Template/FileLoader.php#L93)</small>

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**Return value**

- Type: `string`
- Description: Extension including leading dot or empty string.


---

### setBasePath() · <small>[🗎](../../src/Template/FileLoader.php#L104)</small>

`public function setBasePath(string $path): static`

Set the base path for resolving relative template names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**Return value**

- Type: `static`


---

### getBasePath() · <small>[🗎](../../src/Template/FileLoader.php#L116)</small>

`public function getBasePath(): string`

Get the currently configured base path for template resolution.

**Return value**

- Type: `string`
- Description: Base directory for templates.


---

### load() · <small>[🗎](../../src/Template/FileLoader.php#L124)</small>

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

### resolveName() · <small>[🗎](../../src/Template/FileLoader.php#L154)</small>

`public function resolveName(string $name): string`

Resolve a logical template name to a path under the base path.

Public for diagnostic use.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `string`

**Throws**

- [ClarityException](Clarity_ClarityException.md)  When the name is absolute, has an empty segment (including
one produced by a `..` reference), or contains a control character.


---

### getSubLoaders() · <small>[🗎](../../src/Template/FileLoader.php#L229)</small>

`public function getSubLoaders(): array`

Return the loaders wrapped by this loader.

The engine uses this to traverse loader hierarchies, for example to apply setExtension()
to every FileLoader beneath a DomainRouterLoader.

**Return value**

- Type: `array`
- Description: The wrapped loaders, or an empty array for a leaf loader.



---

[Back to the Index ⤴](README.md)
