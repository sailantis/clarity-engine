# 🧩 Class: Cache

**Full name:** [Clarity\Engine\Cache](../../src/Engine/Cache.php)

Read/write cache for Clarity compiled template classes.

Each compiled template is stored as a single `.php` file.  The cache
filename is derived deterministically from `md5($sourcePath)` so lookups
never require reading the directory.

Cache filename : md5($sourcePath).php
Class name     : __Clarity_<md5($sourcePath)>_<uniqid>   (versioned per compile)

Versioned class names allow multiple compiled versions of the same template
to coexist in memory across recompilations — eliminating redeclaration
collisions in long-running processes (Swoole, RoadRunner, regular FPM alike).

Each compiled file ends with `return '$className';` so that `require`-ing
it returns the exact class name without any file re-reading or regex.

In-process class name registry
--------------------------------
`Cache::$classNames` maps sourcePath → loaded class name for the current
process.  This lets warm-path calls to `isFresh()` and `load()` operate
purely from memory (OPcache + static array) with zero file I/O.

Compiled class static properties
---------------------------------
$dependencies – array<string,int>  absolutePath => mtime
$sourceMap    – list<[phpLineStart, templateFile, templateLine]>  ranges

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

### isFresh() · [source](../../src/Engine/Cache.php#L83)

`public function isFresh(string $sourcePath): bool`

Check whether a valid (non-stale) cached file exists for the given
source template path.

Freshness rules
---------------
1. A compiled class for this source path is known (either from a previous
   load in this process, or by loading the cache file now).
2. Every file listed in the class's $dependencies still has the same mtime
   as recorded at compile time (covers layouts and partials too).

On warm paths the class is already in memory; `readDeps()` reflects
`$dependencies` directly — zero file I/O.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - | Absolute path to the source .clarity.html file. |

**➡️ Return value**

- Type: bool


---

### load() · [source](../../src/Engine/Cache.php#L117)

`public function load(string $sourcePath): string|null`

Return the class name for a loaded (or loadable) compiled template.

If the class was already loaded in this process, returns from the in-
process registry with no I/O.  Otherwise requires the cache file (which
is OPcache-eligible) and registers the returned class name.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - | Absolute path to the source template. |

**➡️ Return value**

- Type: string|null
- Description: Null if no cache file exists.


---

### writeAndLoad() · [source](../../src/Engine/Cache.php#L151)

`public function writeAndLoad(string $sourcePath, Clarity\Engine\CompiledTemplate $compiled): string`

Write a compiled template to the cache, immediately require it, and
return the class name.

Using `require` (not `require_once`) ensures the new versioned class is
declared even if an older compiled version of the same file is already
loaded in this process.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - | Absolute source template path. |
| `$compiled` | [CompiledTemplate](Clarity_Engine_CompiledTemplate.md) | - | Result from the compiler. |

**➡️ Return value**

- Type: string
- Description: The class name that is now live in memory.


---

### invalidate() · [source](../../src/Engine/Cache.php#L176)

`public function invalidate(string $sourcePath): void`

Delete the cached file for the given source path (if it exists) and
remove it from the in-process registry.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - |  |

**➡️ Return value**

- Type: void


---

### flush() · [source](../../src/Engine/Cache.php#L193)

`public function flush(): void`

Delete all cached files in the cache directory and clear the in-process
registry so stale class names do not prevent recompilation.

**➡️ Return value**

- Type: void


---

### classNameFor() · [source](../../src/Engine/Cache.php#L227)

`public function classNameFor(string $sourcePath): string`

Return the base class-name prefix for a source path.

Note: the actual in-memory class name includes a unique compile-time
suffix to prevent redeclaration collisions.  Use `getLoadedClassName()`
to obtain the real class name after a template has been loaded.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - |  |

**➡️ Return value**

- Type: string


---

### getLoadedClassName() · [source](../../src/Engine/Cache.php#L236)

`public function getLoadedClassName(string $sourcePath): string|null`

Return the class name that is currently live in this process for the
given source path, or null if the template has not been loaded yet.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - |  |

**➡️ Return value**

- Type: string|null


---

### cacheFilePath() · [source](../../src/Engine/Cache.php#L251)

`public function cacheFilePath(string $sourcePath): string`

Compute the cache file path for a given source path.

Files are stored under a 2-character hex subdirectory derived from the
first two characters of the source path's MD5 hash.  This limits the
number of files per directory to at most 256 buckets × N templates,
keeping directory listings manageable even for large applications.

Example:  md5('/var/www/views/home/index.clarity.html') = 'a3f…'
          → {cachePath}/a3/a3f….php

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - |  |

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
