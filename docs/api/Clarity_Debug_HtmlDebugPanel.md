# 🧩 Class: HtmlDebugPanel

**Full name:** [Clarity\Debug\HtmlDebugPanel](../../src/Debug/HtmlDebugPanel.php)

Collects DebugEvents and renders a self-contained floating HTML panel
appended to the page bottom-right corner.

Register it via enableDebug(new DumpOptions(showPanel: true)) or subscribe
it manually to a DebugEventBus and call getHtml() after rendering.

## 🚀 Public methods

### __construct() · [source](../../src/Debug/HtmlDebugPanel.php#L21)

`public function __construct(): mixed`

**➡️ Return value**

- Type: mixed


---

### onEvent() · [source](../../src/Debug/HtmlDebugPanel.php#L26)

`public function onEvent(Clarity\Debug\DebugEvent $event): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$event` | [DebugEvent](Clarity_Debug_DebugEvent.md) | - |  |

**➡️ Return value**

- Type: void


---

### getHtml() · [source](../../src/Debug/HtmlDebugPanel.php#L31)

`public function getHtml(): string`

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
