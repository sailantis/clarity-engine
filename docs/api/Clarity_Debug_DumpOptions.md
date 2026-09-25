# Class: DumpOptions

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

## Public Properties

- `public readonly` int `$maxDepth` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public readonly` int `$maxItems` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public readonly` array `$maskKeys` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public readonly` bool `$forceToTemplate` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public readonly` bool `$showPanel` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DumpOptions.php#L22)</small>

`public function __construct(int $maxDepth = 5, int $maxItems = 50, array $maskKeys = [], bool $forceToTemplate = false, bool $showPanel = false): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxDepth` | int | `5` |  |
| `$maxItems` | int | `50` |  |
| `$maskKeys` | array | `[]` |  |
| `$forceToTemplate` | bool | `false` |  |
| `$showPanel` | bool | `false` |  |

**Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
