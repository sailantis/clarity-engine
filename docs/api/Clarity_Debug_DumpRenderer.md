# 🔌 Interface: DumpRenderer

**Full name:** [Clarity\Debug\DumpRenderer](../../src/Debug/DumpRenderer.php)

## 🚀 Public methods

### render() · [source](../../src/Debug/DumpRenderer.php#L18)

`public function render(mixed $value, Clarity\Debug\DumpOptions $opts): string`

Render $value for display.

May have side effects (e.g. writing to STDERR for the CLI renderer).
Returns a string to be concatenated into the template output; returns ''
when the output was sent directly to STDERR.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
