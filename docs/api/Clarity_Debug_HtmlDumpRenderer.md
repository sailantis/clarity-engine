# 🧩 Class: HtmlDumpRenderer

**Full name:** [Clarity\Debug\HtmlDumpRenderer](../../src/Debug/HtmlDumpRenderer.php)

Renders debug values as a collapsible HTML tree using <details>/<summary>.

Associative arrays are displayed using object notation {key: value}.
Sequential arrays are displayed as lists [item, …].
All scalar output is HTML-escaped.  Sensitive keys are masked.
A minimal inline <style> block is injected once per page.

## 🚀 Public methods

### render() · [source](../../src/Debug/HtmlDumpRenderer.php#L20)

`public function render(mixed $value, Clarity\Debug\DumpOptions $opts): string`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
