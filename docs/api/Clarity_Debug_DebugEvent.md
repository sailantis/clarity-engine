# 🧩 Class: DebugEvent

**Full name:** [Clarity\Debug\DebugEvent](../../src/Debug/DebugEvent.php)

DebugEvent represents a single debug event emitted on the DebugEventBus.

It contains a type, an optional payload, and a timestamp of when it was emitted.

## 🔐 Public Properties

- `public readonly` string `$type` · [source](../../src/Debug/DebugEvent.php)
- `public readonly` array `$payload` · [source](../../src/Debug/DebugEvent.php)
- `public readonly` float `$timestamp` · [source](../../src/Debug/DebugEvent.php)

## 🚀 Public methods

### __construct() · [source](../../src/Debug/DebugEvent.php#L13)

`public function __construct(string $type, array $payload, float $timestamp): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$payload` | array | - |  |
| `$timestamp` | float | - |  |

**➡️ Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
