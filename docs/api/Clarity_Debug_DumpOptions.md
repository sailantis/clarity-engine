# Class: DumpOptions

**Full name:** [Clarity\Debug\DumpOptions](../../src/Debug/DumpOptions.php)

Configuration options for the Clarity dump renderers.

Pass this to [`ClarityEngineTrait::setDebugMode()`](Clarity_ClarityEngineTrait.md#setdebugmode) to customise
how dump() and dd() display values.  Every option is set both by the
constructor and by a fluent method of the same name, so the two styles
compose and a value can be adjusted after the object exists:

```php
// Named arguments in one expression…
$engine->setDebugMode(new DumpOptions(
    maxDepth: 4,
    maskKeys: ['password', 'token'],
    showPanel: true,
));

// …or a chain of calls, on a fresh instance or a shared one:
$engine->setDebugMode((new DumpOptions())->maxDepth(4)->maskKeys(['password']));
$opts->showPanel();
```

A chain is MUTABLE: each method changes this instance and returns it, so
`$opts->maxDepth(4)` is visible to every holder of `$opts` — which is what
makes a `DumpOptions` handed to the engine earlier reconfigurable later.

## Public Properties

- `public` int `$maxDepth` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` int `$maxItems` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` array `$maskKeys` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` bool `$forceToTemplate` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` bool `$showPanel` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DumpOptions.php#L34)</small>

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

- Type: `mixed`


---

### create() · <small>[🗎](../../src/Debug/DumpOptions.php#L60)</small>

`public static function create(): self`

Create a new instance with default options.

**Return value**

- Type: `self`


---

### maxDepth() · <small>[🗎](../../src/Debug/DumpOptions.php#L68)</small>

`public function maxDepth(int $maxDepth): self`

Maximum nesting depth rendered before values are replaced by '…'.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxDepth` | int | - |  |

**Return value**

- Type: `self`


---

### maxItems() · <small>[🗎](../../src/Debug/DumpOptions.php#L77)</small>

`public function maxItems(int $maxItems): self`

Maximum number of array items shown at any one level.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxItems` | int | - |  |

**Return value**

- Type: `self`


---

### maskKeys() · <small>[🗎](../../src/Debug/DumpOptions.php#L89)</small>

`public function maskKeys(array $maskKeys): self`

Replace the mask-key list.  A key is hidden when its name contains one
of these substrings, case-insensitively.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maskKeys` | array | - |  |

**Return value**

- Type: `self`


---

### maskKey() · <small>[🗎](../../src/Debug/DumpOptions.php#L98)</small>

`public function maskKey(string $maskKey): self`

Add a key substring to mask, leaving the current list in place.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maskKey` | string | - |  |

**Return value**

- Type: `self`


---

### unmaskKey() · <small>[🗎](../../src/Debug/DumpOptions.php#L107)</small>

`public function unmaskKey(string $maskKey): self`

Stop masking a key substring, leaving the rest of the list in place.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maskKey` | string | - |  |

**Return value**

- Type: `self`


---

### forceToTemplate() · <small>[🗎](../../src/Debug/DumpOptions.php#L120)</small>

`public function forceToTemplate(bool $forceToTemplate = true): self`

CLI renderer: when true, return the value as a string (useful for dd()).

When false (default), write to STDERR and return ''.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$forceToTemplate` | bool | `true` |  |

**Return value**

- Type: `self`


---

### showPanel() · <small>[🗎](../../src/Debug/DumpOptions.php#L129)</small>

`public function showPanel(bool $showPanel = true): self`

Whether to render the HTML debug panel along with the output.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$showPanel` | bool | `true` |  |

**Return value**

- Type: `self`


---

### getMaxDepth() · <small>[🗎](../../src/Debug/DumpOptions.php#L135)</small>

`public function getMaxDepth(): int`

**Return value**

- Type: `int`


---

### getMaxItems() · <small>[🗎](../../src/Debug/DumpOptions.php#L140)</small>

`public function getMaxItems(): int`

**Return value**

- Type: `int`


---

### getMaskKeys() · <small>[🗎](../../src/Debug/DumpOptions.php#L148)</small>

`public function getMaskKeys(): array`

**Return value**

- Type: `array`


---

### getForceToTemplate() · <small>[🗎](../../src/Debug/DumpOptions.php#L153)</small>

`public function getForceToTemplate(): bool`

**Return value**

- Type: `bool`


---

### getShowPanel() · <small>[🗎](../../src/Debug/DumpOptions.php#L158)</small>

`public function getShowPanel(): bool`

**Return value**

- Type: `bool`



---

[Back to the Index ⤴](README.md)
