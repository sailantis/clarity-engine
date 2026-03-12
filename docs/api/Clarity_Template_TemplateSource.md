# 🧩 Class: TemplateSource

**Full name:** [Clarity\Template\TemplateSource](../../src/Template/TemplateSource.php)

Value object returned by a [`TemplateLoader`](Clarity_Template_TemplateLoader.md).

Carries two pieces of information:
- **revision**: a cheap-to-obtain opaque scalar used for cache invalidation.
  File-based loaders use the unix mtime (int); memory-based loaders use an
  fnv1a64 hash of the source string (string via hash('fnv1a64', $code)).
- **codeLoader**: a closure that fetches the actual source code only when
  the engine determines that compilation is necessary.  On warm cache paths
  (cache is still fresh) getCode() is never called, avoiding unnecessary I/O.

## 🔐 Public Properties

- `public readonly` string|int `$revision` · [source](../../src/Template/TemplateSource.php)

## 🚀 Public methods

### __construct() · [source](../../src/Template/TemplateSource.php#L24)

`public function __construct(string|int $revision, Closure $codeLoader): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$revision` | string\|int | - | Opaque revision token used for cache invalidation.<br>int  → mtime from a file-based loader.<br>string → hash('fnv1a64', $code) from a memory loader. |
| `$codeLoader` | Closure | - | Lazy loader; called at most once per compile by the engine.<br>Must return the full raw template source string. |

**➡️ Return value**

- Type: mixed


---

### getCode() · [source](../../src/Template/TemplateSource.php#L36)

`public function getCode(): string`

Return the raw template source code.

The closure is invoked on every call, but in practice the engine calls
getCode() at most once per compilation cycle (cold-path only).

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
