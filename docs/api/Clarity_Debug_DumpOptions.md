# 🧩 Class: DumpOptions

**Full name:** [Clarity\Debug\DumpOptions](../../src/Debug/DumpOptions.php)

Configuration options for the Clarity dump renderers.

Pass this to enableDebug() to customise how dump() and dd() display values.

```php
$engine->enableDebug(new DumpOptions(
    maxDepth: 4,
    maskKeys: ['password', 'token'],
    showPanel: true,
));
```

## 🔐 Public Properties

- `public readonly` int `$maxDepth` · [source](../../src/Debug/DumpOptions.php)
- `public readonly` int `$maxItems` · [source](../../src/Debug/DumpOptions.php)
- `public readonly` array `$maskKeys` · [source](../../src/Debug/DumpOptions.php)
- `public readonly` bool `$forceToTemplate` · [source](../../src/Debug/DumpOptions.php)
- `public readonly` bool `$showPanel` · [source](../../src/Debug/DumpOptions.php)

## 🚀 Public methods

### __construct() · [source](../../src/Debug/DumpOptions.php#L22)

`public function __construct(int $maxDepth = 5, int $maxItems = 50, array $maskKeys = [], bool $forceToTemplate = false, bool $showPanel = false): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxDepth` | int | `5` |  |
| `$maxItems` | int | `50` |  |
| `$maskKeys` | array | `[]` |  |
| `$forceToTemplate` | bool | `false` |  |
| `$showPanel` | bool | `false` |  |

**➡️ Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
