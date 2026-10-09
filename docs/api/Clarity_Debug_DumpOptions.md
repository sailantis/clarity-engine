# Class: DumpOptions

**Full name:** [Clarity\Debug\DumpOptions](../../src/Debug/DumpOptions.php)

Options for the dump renderers used by dump() and dd().

Pass an instance to [`ClarityEngineTrait::setDebugMode()`](Clarity_ClarityEngineTrait.md#setdebugmode). Each
option can be set through the constructor or through a fluent method of the
same name, so both styles can be combined:

```php
// Named arguments
$engine->setDebugMode(new DumpOptions(
    maxDepth: 4,
    maskKeys: ['password', 'token'],
    showPanel: true,
));

// Method chain
$engine->setDebugMode((new DumpOptions())->maxDepth(4)->maskKeys(['password']));
```

Each fluent method changes the instance and returns it. Changes to maxDepth,
maxItems, maskKeys, forceToTemplate and haltWithException therefore apply to
the engine after the DumpOptions was passed in. showPanel is read only when
debug is enabled, so call setDebugMode() again to change it.

## Public Properties

- `public` int `$maxDepth` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` int `$maxItems` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` array `$maskKeys` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` bool `$forceToTemplate` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` bool `$showPanel` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>
- `public` bool `$haltWithException` · <small>[🗎](../../src/Debug/DumpOptions.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DumpOptions.php#L33)</small>

`public function __construct(int $maxDepth = 5, int $maxItems = 50, array $maskKeys = [], bool $forceToTemplate = false, bool $showPanel = false, bool $haltWithException = false): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxDepth` | int | `5` |  |
| `$maxItems` | int | `50` |  |
| `$maskKeys` | array | `[]` |  |
| `$forceToTemplate` | bool | `false` |  |
| `$showPanel` | bool | `false` |  |
| `$haltWithException` | bool | `false` |  |

**Return value**

- Type: `mixed`


---

### create() · <small>[🗎](../../src/Debug/DumpOptions.php#L63)</small>

`public static function create(): self`

Creates an instance with default options.

**Return value**

- Type: `self`


---

### maxDepth() · <small>[🗎](../../src/Debug/DumpOptions.php#L71)</small>

`public function maxDepth(int $maxDepth): self`

Sets the maximum nesting depth rendered.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxDepth` | int | - |  |

**Return value**

- Type: `self`


---

### maxItems() · <small>[🗎](../../src/Debug/DumpOptions.php#L80)</small>

`public function maxItems(int $maxItems): self`

Sets the maximum number of array items shown per level.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxItems` | int | - |  |

**Return value**

- Type: `self`


---

### maskKeys() · <small>[🗎](../../src/Debug/DumpOptions.php#L92)</small>

`public function maskKeys(array $maskKeys): self`

Replaces the mask list. A key is masked when its name contains one of
these substrings, case-insensitively. Array keys and object property names are checked.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maskKeys` | array | - |  |

**Return value**

- Type: `self`


---

### maskKey() · <small>[🗎](../../src/Debug/DumpOptions.php#L101)</small>

`public function maskKey(string $maskKey): self`

Adds a substring to the mask list.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maskKey` | string | - |  |

**Return value**

- Type: `self`


---

### unmaskKey() · <small>[🗎](../../src/Debug/DumpOptions.php#L110)</small>

`public function unmaskKey(string $maskKey): self`

Removes a substring from the mask list.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maskKey` | string | - |  |

**Return value**

- Type: `self`


---

### forceToTemplate() · <small>[🗎](../../src/Debug/DumpOptions.php#L124)</small>

`public function forceToTemplate(bool $forceToTemplate = true): self`

CLI only. When true, dump() returns the rendered string instead of
writing it to STDERR. When false (default), dump() writes to STDERR and
returns ''. dd() always writes to STDERR and ignores this option.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$forceToTemplate` | bool | `true` |  |

**Return value**

- Type: `self`


---

### showPanel() · <small>[🗎](../../src/Debug/DumpOptions.php#L133)</small>

`public function showPanel(bool $showPanel = true): self`

Shows or hides the HTML debug panel. Takes effect when debug is enabled.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$showPanel` | bool | `true` |  |

**Return value**

- Type: `self`


---

### haltWithException() · <small>[🗎](../../src/Debug/DumpOptions.php#L146)</small>

`public function haltWithException(bool $haltWithException = true): self`

dd() only. When true, dd() throws a [`DumpHaltException`](Clarity_Debug_DumpHaltException.md) with the
rendered dump instead of calling exit(1). Use it in hosts that keep the
PHP process alive between requests, such as RoadRunner, so that one dd()
ends one request and not the worker. The exception is thrown on every
SAPI, including the CLI.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$haltWithException` | bool | `true` |  |

**Return value**

- Type: `self`


---

### getMaxDepth() · <small>[🗎](../../src/Debug/DumpOptions.php#L152)</small>

`public function getMaxDepth(): int`

**Return value**

- Type: `int`


---

### getMaxItems() · <small>[🗎](../../src/Debug/DumpOptions.php#L157)</small>

`public function getMaxItems(): int`

**Return value**

- Type: `int`


---

### getMaskKeys() · <small>[🗎](../../src/Debug/DumpOptions.php#L165)</small>

`public function getMaskKeys(): array`

**Return value**

- Type: `array`


---

### getForceToTemplate() · <small>[🗎](../../src/Debug/DumpOptions.php#L170)</small>

`public function getForceToTemplate(): bool`

**Return value**

- Type: `bool`


---

### getShowPanel() · <small>[🗎](../../src/Debug/DumpOptions.php#L175)</small>

`public function getShowPanel(): bool`

**Return value**

- Type: `bool`


---

### getHaltWithException() · <small>[🗎](../../src/Debug/DumpOptions.php#L180)</small>

`public function getHaltWithException(): bool`

**Return value**

- Type: `bool`



---

[Back to the Index ⤴](README.md)
