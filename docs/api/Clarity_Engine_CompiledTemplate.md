# 🧩 Class: CompiledTemplate

**Full name:** [Clarity\Engine\CompiledTemplate](../../src/Engine/CompiledTemplate.php)

Value object produced by the Clarity Compiler for a single template file.

## 🔐 Public Properties

- `public readonly` string `$className` · [source](../../src/Engine/CompiledTemplate.php)
- `public readonly` string `$code` · [source](../../src/Engine/CompiledTemplate.php)
- `public readonly` array `$sourceMap` · [source](../../src/Engine/CompiledTemplate.php)
- `public readonly` array `$dependencies` · [source](../../src/Engine/CompiledTemplate.php)
- `public readonly` array `$sourceFiles` · [source](../../src/Engine/CompiledTemplate.php)

## 🚀 Public methods

### __construct() · [source](../../src/Engine/CompiledTemplate.php#L24)

`public function __construct(string $className, string $code, array $sourceMap, array $dependencies, array $sourceFiles = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$className` | string | - | Generated class name (e.g. __Clarity_f1f1fde8ef8cc7825f199f1b7bf3ad0e). |
| `$code` | string | - | Full PHP source of the compiled file. |
| `$sourceMap` | array | - | [phpLine, fileIndex, templateLine] mapping. |
| `$dependencies` | array | - | [absolutePath => mtime] for cache invalidation. |
| `$sourceFiles` | array | `[]` | Unique source file paths (parallel to $sourceMap file indices). |

**➡️ Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
