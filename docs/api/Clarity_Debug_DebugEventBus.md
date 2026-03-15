# 🧩 Class: DebugEventBus

**Full name:** [Clarity\Debug\DebugEventBus](../../src/Debug/DebugEventBus.php)

## 🚀 Public methods

### subscribe() · [source](../../src/Debug/DebugEventBus.php#L15)

`public function subscribe(Clarity\Debug\DebugListener|callable $listener): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$listener` | [DebugListener](Clarity_Debug_DebugListener.md)\|callable | - |  |

**➡️ Return value**

- Type: void


---

### emit() · [source](../../src/Debug/DebugEventBus.php#L20)

`public function emit(string $type, array $payload = []): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$payload` | array | `[]` |  |

**➡️ Return value**

- Type: void


---

### getEvents() · [source](../../src/Debug/DebugEventBus.php#L34)

`public function getEvents(): array`

**➡️ Return value**

- Type: array



---

[Back to the Index ⤴](README.md)
