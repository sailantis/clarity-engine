# Class: HtmlDebugPanel

**Full name:** [Clarity\Debug\HtmlDebugPanel](../../src/Debug/HtmlDebugPanel.php)

Collects DebugEvents and renders a floating HTML panel, fixed to the
bottom-right corner of the page.

The engine adds the panel when debug mode is enabled with
setDebugMode(new DumpOptions(showPanel: true)). Alternatively, subscribe it
to a DebugEventBus yourself and call getHtml() after rendering.

## Public methods

### __construct() · <small>[🗎](../../src/Debug/HtmlDebugPanel.php#L23)</small>

`public function __construct(): mixed`

**Return value**

- Type: `mixed`


---

### onEvent() · <small>[🗎](../../src/Debug/HtmlDebugPanel.php#L28)</small>

`public function onEvent(Clarity\Debug\DebugEvent $event): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$event` | [DebugEvent](Clarity_Debug_DebugEvent.md) | - |  |

**Return value**

- Type: `void`


---

### getHtml() · <small>[🗎](../../src/Debug/HtmlDebugPanel.php#L33)</small>

`public function getHtml(): string`

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
