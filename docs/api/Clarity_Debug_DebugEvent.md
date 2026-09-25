# Class: DebugEvent

**Full name:** [Clarity\Debug\DebugEvent](../../src/Debug/DebugEvent.php)

DebugEvent represents a single debug event emitted on the DebugEventBus.

It contains a type, an optional payload, and a timestamp of when it was emitted.

## Public Properties

- `public readonly` string `$type` · <small>[🗎](../../src/Debug/DebugEvent.php)</small>
- `public readonly` array `$payload` · <small>[🗎](../../src/Debug/DebugEvent.php)</small>
- `public readonly` float `$timestamp` · <small>[🗎](../../src/Debug/DebugEvent.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DebugEvent.php#L13)</small>

`public function __construct(string $type, array $payload, float $timestamp): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$payload` | array | - |  |
| `$timestamp` | float | - |  |

**Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
