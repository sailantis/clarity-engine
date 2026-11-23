# Class: Cache

**Full name:** [Clarity\Engine\Cache](../../src/Engine/Cache.php)

Cache for compiled Clarity template classes.

Each template is stored in `md5($templateName).php`. Every compile gets a
versioned class name, allowing old and new versions to coexist in long-running
processes. Requiring a compiled file returns its class name.

The shared `Cache::$classNames` registry maps template names to loaded classes.
`isFresh()` still checks that the cache file exists.

Compiled classes define `$dependencies` (logical names to revisions),
`$sourceMap` (packed ranges; see [`SourceMap::decode()`](Clarity_Engine_SourceMap.md#decode)), and
`$compilerVersion`.

## Public methods

### __construct() · <small>[🗎](../../src/Engine/Cache.php#L48)</small>

`public function __construct(string $path = ''): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | `''` |  |

**Return value**

- Type: `mixed`


---

### setPath() · <small>[🗎](../../src/Engine/Cache.php#L58)</small>

`public function setPath(string $path): static`

Change the cache directory at runtime.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**Return value**

- Type: `static`


---

### getPath() · <small>[🗎](../../src/Engine/Cache.php#L64)</small>

`public function getPath(): string`

**Return value**

- Type: `string`


---

### setRevalidateFreq() · <small>[🗎](../../src/Engine/Cache.php#L77)</small>

`public function setRevalidateFreq(int $seconds): static`

Set how many seconds a freshness verdict may be reused for.

See `$revalidateFreq`. 0 — the default — restores a per-render
filesystem check; a positive value bounds staleness by that many seconds.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$seconds` | int | - | Seconds; negative values are treated as 0. |

**Return value**

- Type: `static`


---

### getRevalidateFreq() · <small>[🗎](../../src/Engine/Cache.php#L87)</small>

`public function getRevalidateFreq(): int`

How many seconds a freshness verdict is reused for.

**Return value**

- Type: `int`


---

### isFresh() · <small>[🗎](../../src/Engine/Cache.php#L117)</small>

`public function isFresh(string $templateName, callable $revisionFor): bool`

Check whether a valid (non-stale) cached file exists for the given
logical template name.

Freshness rules
---------------
1. A compiled class for this template name is known (either loaded in
   this process already, or loadable from the cache file).
2. The class was produced by the current compiler version. A change in
   Compiler::COMPILER_VERSION is a change in emitted semantics (e.g. the
   two-variable for loop binding (key, value) instead of (value, key)), so
   the template source may be unchanged while the compiled code is wrong.
3. Every entry in the class's $dependencies still has the same revision
   as recorded at compile time, as determined by calling $revisionFor.

On warm paths the class is already in memory; the static properties are
read directly. The only file access is the `is_file()` check before `load()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name (e.g. 'home', 'layouts/base'). |
| `$revisionFor` | callable | - | fn(string $name): int|string — returns the current<br>revision for a dependency name. The engine passes a<br>closure that calls the active TemplateLoader. |

**Return value**

- Type: `bool`


---

### load() · <small>[🗎](../../src/Engine/Cache.php#L184)</small>

`public function load(string $templateName): string|null`

Return the class name for a loaded (or loadable) compiled template.

If the class was already loaded in this process, returns from the in-
process registry with no I/O.  Otherwise requires the cache file (which
is OPcache-eligible) and registers the returned class name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name. |

**Return value**

- Type: `string`|`null`
- Description: Null if no cache file exists.


---

### writeAndLoad() · <small>[🗎](../../src/Engine/Cache.php#L218)</small>

`public function writeAndLoad(string $templateName, Clarity\Engine\CompiledTemplate $compiled): string`

Write a compiled template to the cache, immediately require it, and
return the class name.

Using `require` (not `require_once`) ensures the new versioned class is
declared even if an older compiled version of the same template is already
loaded in this process.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name. |
| `$compiled` | [CompiledTemplate](Clarity_Engine_CompiledTemplate.md) | - | Result from the compiler. |

**Return value**

- Type: `string`
- Description: The class name that is now live in memory.


---

### invalidate() · <small>[🗎](../../src/Engine/Cache.php#L247)</small>

`public function invalidate(string $templateName): void`

Delete the cached file for the given template name (if it exists) and
remove it from the in-process registry.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**Return value**

- Type: `void`


---

### flush() · <small>[🗎](../../src/Engine/Cache.php#L266)</small>

`public function flush(): void`

Delete all cached files in the cache directory and clear the in-process
registry so stale class names do not prevent recompilation.

**Return value**

- Type: `void`


---

### classNameFor() · <small>[🗎](../../src/Engine/Cache.php#L300)</small>

`public function classNameFor(string $templateName): string`

Return the base class-name prefix for a template name.

Note: the actual in-memory class name includes a unique compile-time suffix to prevent redeclaration collisions.
Use `getLoadedClassName()` to obtain the real class name after a template has been loaded.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**Return value**

- Type: `string`


---

### getLoadedClassName() · <small>[🗎](../../src/Engine/Cache.php#L309)</small>

`public function getLoadedClassName(string $templateName): string|null`

Return the class name that is currently live in this process for the
given template name, or null if the template has not been loaded yet.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**Return value**

- Type: `string`|`null`


---

### cacheFilePath() · <small>[🗎](../../src/Engine/Cache.php#L324)</small>

`public function cacheFilePath(string $templateName): string`

Compute the cache file path for a given logical template name.

Files are stored under a 2-character hex subdirectory derived from the
first two characters of the template name's MD5 hash.  This limits the
number of files per directory to at most 256 buckets × N templates,
keeping directory listings manageable even for large applications.

Example:  md5('home') = 'b026...'  →  {cachePath}/b0/b026....php

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
