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

## 🚀 Public methods

### __construct() · [source](../../src/Template/FileLoader.php#L36)

`public function __construct(string $basePath, string $extension = '.clarity.html', array $namespaces = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$basePath` | string | - | Base directory for template resolution. |
| `$extension` | string | `'.clarity.html'` | File extension with or without leading dot. |
| `$namespaces` | array | `[]` | Namespace alias → base path map. |

**➡️ Return value**

- Type: mixed


---

### setExtension() · [source](../../src/Template/FileLoader.php#L52)

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**➡️ Return value**

- Type: static


---

### getExtension() · [source](../../src/Template/FileLoader.php#L67)

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**➡️ Return value**

- Type: string
- Description: Extension including leading dot or empty string.


---

### addNamespace() · [source](../../src/Template/FileLoader.php#L81)

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Views can be referenced using the syntax "namespace::view.name".

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**➡️ Return value**

- Type: static


---

### getNamespaces() · [source](../../src/Template/FileLoader.php#L93)

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**➡️ Return value**

- Type: array
- Description: Associative array of namespace => path mappings.


---

### setBasePath() · [source](../../src/Template/FileLoader.php#L105)

`public function setBasePath(string $path): static`

Set the base path for resolving relative template names.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**➡️ Return value**

- Type: static


---

### getBasePath() · [source](../../src/Template/FileLoader.php#L117)

`public function getBasePath(): string`

Get the currently configured base path for template resolution.

**➡️ Return value**

- Type: string
- Description: Base directory for templates.


---

### exists() · [source](../../src/Template/FileLoader.php#L122)

`public function exists(string $name): bool`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### load() · [source](../../src/Template/FileLoader.php#L127)

`public function load(string $name): Clarity\Template\TemplateSource`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: [TemplateSource](Clarity_Template_TemplateSource.md)


---

### resolveName() · [source](../../src/Template/FileLoader.php#L153)

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

[Back to the Index ⤴](README.md)
