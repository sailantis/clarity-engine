# Class: TemplateSource

**Full name:** [Clarity\Template\TemplateSource](../../src/Template/TemplateSource.php)

Value object returned by a [`TemplateLoader`](Clarity_Template_TemplateLoader.md).

Carries three pieces of information:
- **revision**: a cheap-to-obtain opaque scalar used for cache invalidation.
  File-based loaders use the unix mtime (int); memory-based loaders use an
  fnv1a64 hash of the source string.
- **codeLoader**: a closure that fetches the actual source code only when
  the engine determines that compilation is necessary. On warm cache paths
  (cache is still fresh) getCode() is never called, avoiding unnecessary I/O.
- **path**: the physical file the source was read from, or null.

## Public Properties

- `public readonly` string|int `$revision` · <small>[🗎](../../src/Template/TemplateSource.php)</small>
- `public readonly` string|null `$path` · <small>[🗎](../../src/Template/TemplateSource.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Template/TemplateSource.php#L28)</small>

`public function __construct(string|int $revision, Closure $codeLoader, string|null $path = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$revision` | string\|int | - | Opaque revision token used for cache invalidation.<br>int    → mtime from a file-based loader.<br>string → hash('fnv1a64', $code) from a memory loader. |
| `$codeLoader` | Closure | - | Lazy loader returning the full raw template source string. |
| `$path` | string\|null | `null` | Physical file the source was read from, or null when<br>the loader has no file (e.g. ArrayLoader, StringLoader).<br>The compiler keeps it so errors can name the file after<br>the loader is no longer available. |

**Return value**

- Type: `mixed`


---

### getCode() · <small>[🗎](../../src/Template/TemplateSource.php#L41)</small>

`public function getCode(): string`

Return the raw template source code.

Each call invokes the loader. The engine calls this only on the cold compile
path, at most once per compilation.

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
