# Class: CompiledTemplate

**Full name:** [Clarity\Engine\CompiledTemplate](../../src/Engine/CompiledTemplate.php)

Value object produced by the Clarity Compiler for a single template.

## Public Properties

- `public readonly` string `$className` · <small>[🗎](../../src/Engine/CompiledTemplate.php)</small>
- `public readonly` string `$code` · <small>[🗎](../../src/Engine/CompiledTemplate.php)</small>
- `public readonly` array `$sourceMap` · <small>[🗎](../../src/Engine/CompiledTemplate.php)</small>
- `public readonly` array `$dependencies` · <small>[🗎](../../src/Engine/CompiledTemplate.php)</small>
- `public readonly` array `$sourceFiles` · <small>[🗎](../../src/Engine/CompiledTemplate.php)</small>
- `public readonly` int `$renderBodyLine` · <small>[🗎](../../src/Engine/CompiledTemplate.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Engine/CompiledTemplate.php#L30)</small>

`public function __construct(string $className, string $code, array $sourceMap, array $dependencies, array $sourceFiles = [], int $renderBodyLine = 0): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$className` | string | - | Generated class name (e.g. __Clarity_f1f1fde8ef8cc7825f199f1b7bf3ad0e). |
| `$code` | string | - | Full PHP source of the compiled file. |
| `$sourceMap` | array | - | [phpLine, fileIndex, templateLine] mapping. |
| `$dependencies` | array | - | [logicalName => revision] for cache invalidation. |
| `$sourceFiles` | array | `[]` | Unique logical template names (parallel to $sourceMap file indices). |
| `$renderBodyLine` | int | `0` | First line of the compiled render body. |

**Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
