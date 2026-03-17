# 🔌 Interface: DebugListener

**Full name:** [Clarity\Debug\DebugListener](../../src/Debug/DebugListener.php)

DebugListener is an interface for objects that want to receive debug events from the DebugEventBus.

Implement the onEvent method to handle incoming DebugEvent instances.

## 🚀 Public methods

### onEvent() · [source](../../src/Debug/DebugListener.php#L13)

`public function onEvent(Clarity\Debug\DebugEvent $event): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$event` | [DebugEvent](Clarity_Debug_DebugEvent.md) | - |  |

**➡️ Return value**

- Type: void



---

[Back to the Index ⤴](README.md)
