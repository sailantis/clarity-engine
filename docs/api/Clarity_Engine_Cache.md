# 🧩 Class: Cache

**Full name:** [Clarity\Engine\Cache](../../src/Engine/Cache.php)

Read/write cache for Clarity compiled template classes.

Each compiled template is stored as a single `.php` file.  The cache
filename is derived deterministically from `md5($templateName)` so lookups
never require reading the directory.

Cache filename : md5($templateName).php
Class name     : __Clarity_<md5($templateName)>_<uniqid>   (versioned per compile)

Versioned class names allow multiple compiled versions of the same template
to coexist in memory across recompilations — eliminating redeclaration
collisions in long-running processes (Swoole, RoadRunner, regular FPM alike).

Each compiled file ends with `return '$className';` so that `require`-ing
it returns the exact class name without any file re-reading or regex.

In-process class name registry
--------------------------------
`Cache::$classNames` maps templateName → loaded class name for the current
process.  This lets warm-path calls to `isFresh()` and `load()` operate
purely from memory (OPcache + static array) with zero file I/O.

Compiled class static properties
---------------------------------
$dependencies – array<string,int|string>  logicalName => revision
$sourceMap    – list<[phpLineStart, fileIndex, templateLine]>  ranges

## 🚀 Public methods

### __construct() · [source](../../src/Engine/Cache.php#L44)

`public function __construct(string $path = ''): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | `''` |  |

**➡️ Return value**

- Type: mixed


---

### setPath() · [source](../../src/Engine/Cache.php#L54)

`public function setPath(string $path): static`

Change the cache directory at runtime.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**➡️ Return value**

- Type: static


---

### getPath() · [source](../../src/Engine/Cache.php#L60)

`public function getPath(): string`

**➡️ Return value**

- Type: string


---

### isFresh() · [source](../../src/Engine/Cache.php#L86)

`public function isFresh(string $templateName, callable $revisionFor): bool`

Check whether a valid (non-stale) cached file exists for the given
logical template name.

Freshness rules
---------------
1. A compiled class for this template name is known (either loaded in
   this process already, or loadable from the cache file).
2. Every entry in the class's $dependencies still has the same revision
   as recorded at compile time, as determined by calling $revisionFor.

On warm paths the class is already in memory; `readDeps()` reflects
`$dependencies` directly — zero file I/O from this method.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name (e.g. 'home', 'layouts/base'). |
| `$revisionFor` | callable | - | fn(string $name): int|string — returns the current<br>revision for a dependency name. The engine passes a<br>closure that calls the active TemplateLoader. |

**➡️ Return value**

- Type: bool


---

### load() · [source](../../src/Engine/Cache.php#L125)

`public function load(string $templateName): string|null`

Return the class name for a loaded (or loadable) compiled template.

If the class was already loaded in this process, returns from the in-
process registry with no I/O.  Otherwise requires the cache file (which
is OPcache-eligible) and registers the returned class name.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name. |

**➡️ Return value**

- Type: string|null
- Description: Null if no cache file exists.


---

### writeAndLoad() · [source](../../src/Engine/Cache.php#L159)

`public function writeAndLoad(string $templateName, Clarity\Engine\CompiledTemplate $compiled): string`

Write a compiled template to the cache, immediately require it, and
return the class name.

Using `require` (not `require_once`) ensures the new versioned class is
declared even if an older compiled version of the same template is already
loaded in this process.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name. |
| `$compiled` | [CompiledTemplate](Clarity_Engine_CompiledTemplate.md) | - | Result from the compiler. |

**➡️ Return value**

- Type: string
- Description: The class name that is now live in memory.


---

### invalidate() · [source](../../src/Engine/Cache.php#L188)

`public function invalidate(string $templateName): void`

Delete the cached file for the given template name (if it exists) and
remove it from the in-process registry.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**➡️ Return value**

- Type: void


---

### flush() · [source](../../src/Engine/Cache.php#L205)

`public function flush(): void`

Delete all cached files in the cache directory and clear the in-process
registry so stale class names do not prevent recompilation.

**➡️ Return value**

- Type: void


---

### classNameFor() · [source](../../src/Engine/Cache.php#L239)

`public function classNameFor(string $templateName): string`

Return the base class-name prefix for a template name.

Note: the actual in-memory class name includes a unique compile-time
suffix to prevent redeclaration collisions.  Use `getLoadedClassName()`
to obtain the real class name after a template has been loaded.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**➡️ Return value**

- Type: string


---

### getLoadedClassName() · [source](../../src/Engine/Cache.php#L248)

`public function getLoadedClassName(string $templateName): string|null`

Return the class name that is currently live in this process for the
given template name, or null if the template has not been loaded yet.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**➡️ Return value**

- Type: string|null


---

### cacheFilePath() · [source](../../src/Engine/Cache.php#L263)

`public function cacheFilePath(string $templateName): string`

Compute the cache file path for a given logical template name.

Files are stored under a 2-character hex subdirectory derived from the
first two characters of the template name's MD5 hash.  This limits the
number of files per directory to at most 256 buckets × N templates,
keeping directory listings manageable even for large applications.

Example:  md5('home') = 'b026...'  →  {cachePath}/b0/b026....php

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
