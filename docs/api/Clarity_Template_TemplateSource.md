# Class: TemplateSource

**Full name:** [Clarity\Template\TemplateSource](../../src/Template/TemplateSource.php)

Value object returned by a [`TemplateLoader`](Clarity_Template_TemplateLoader.md).

Carries two pieces of information:
- **revision**: a cheap-to-obtain opaque scalar used for cache invalidation.
  File-based loaders use the unix mtime (int); memory-based loaders use an
  fnv1a64 hash of the source string (string via hash('fnv1a64', $code)).
- **codeLoader**: a closure that fetches the actual source code only when
  the engine determines that compilation is necessary.  On warm cache paths
  (cache is still fresh) getCode() is never called, avoiding unnecessary I/O.

## Public Properties

- `public readonly` string|int `$revision` · <small>[🗎](../../src/Template/TemplateSource.php)</small>
- `public readonly` string|null `$path` · <small>[🗎](../../src/Template/TemplateSource.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Template/TemplateSource.php#L25)</small>

`public function __construct(string|int $revision, Closure $codeLoader, string|null $path = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$revision` | string\|int | - | Opaque revision token used for cache invalidation.<br>int  → mtime from a file-based loader.<br>string → hash('fnv1a64', $code) from a memory loader. |
| `$codeLoader` | Closure | - | Lazy loader; called at most once per compile by the engine.<br>Must return the full raw template source string. |
| `$path` | string\|null | `null` | Physical file this source was read from, when it was read from one; null for a loader with no file to name ([`ArrayLoader`](Clarity_Template_ArrayLoader.md), [`StringLoader`](Clarity_Template_StringLoader.md), a database loader).  The LOADER is the only layer that knows this, which is why it travels with the source rather than being re-derived: the compiler bakes it into the compiled class so an error can point an editor at the file even after the loader is gone. |

**Return value**

- Type: `mixed`


---

### getCode() · <small>[🗎](../../src/Template/TemplateSource.php#L38)</small>

`public function getCode(): string`

Return the raw template source code.

The closure is invoked on every call, but in practice the engine calls
getCode() at most once per compilation cycle (cold-path only).

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
