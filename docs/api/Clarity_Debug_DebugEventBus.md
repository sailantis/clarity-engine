# Class: DebugEventBus

**Full name:** [Clarity\Debug\DebugEventBus](../../src/Debug/DebugEventBus.php)

Passes debug events to listeners and keeps emitted events in memory,
available through getEvents(). A listener is a DebugListener or any callable.

Only the most recent $maxEvents events are kept; older ones are dropped.

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DebugEventBus.php#L20)</small>

`public function __construct(int $maxEvents = 1000): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$maxEvents` | int | `1000` |  |

**Return value**

- Type: `mixed`


---

### subscribe() · <small>[🗎](../../src/Debug/DebugEventBus.php#L24)</small>

`public function subscribe(Clarity\Debug\DebugListener|callable $listener): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$listener` | [DebugListener](Clarity_Debug_DebugListener.md)\|callable | - |  |

**Return value**

- Type: `void`


---

### emit() · <small>[🗎](../../src/Debug/DebugEventBus.php#L29)</small>

`public function emit(string $type, array $payload = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$payload` | array | `[]` |  |

**Return value**

- Type: `void`


---

### getEvents() · <small>[🗎](../../src/Debug/DebugEventBus.php#L46)</small>

`public function getEvents(): array`

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
