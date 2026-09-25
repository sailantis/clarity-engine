# Class: DebugEventBus

**Full name:** [Clarity\Debug\DebugEventBus](../../src/Debug/DebugEventBus.php)

DebugEventBus is a simple event bus for emitting and subscribing to debug events.

It allows listeners to receive events with a type, payload, and timestamp.

## Public methods

### subscribe() · <small>[🗎](../../src/Debug/DebugEventBus.php#L19)</small>

`public function subscribe(Clarity\Debug\DebugListener|callable $listener): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$listener` | [DebugListener](Clarity_Debug_DebugListener.md)\|callable | - |  |

**Return value**

- Type: void


---

### emit() · <small>[🗎](../../src/Debug/DebugEventBus.php#L24)</small>

`public function emit(string $type, array $payload = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$payload` | array | `[]` |  |

**Return value**

- Type: void


---

### getEvents() · <small>[🗎](../../src/Debug/DebugEventBus.php#L38)</small>

`public function getEvents(): array`

**Return value**

- Type: array



---

[Back to the Index ⤴](README.md)
