# 🧩 Class: JsDumpRenderer

**Full name:** [Clarity\Debug\JsDumpRenderer](../../src/Debug/JsDumpRenderer.php)

Renders debug values as a JS comment: ;/* DEBUG_DUMP: {json} *\/

The output is valid JavaScript in any statement position and does not
interfere with surrounding script logic.  Sensitive keys are masked in the
JSON payload.  Any '*\/' sequence inside the JSON is escaped to '*\\\/' to
prevent comment injection.

## 🚀 Public methods

### render() · [source](../../src/Debug/JsDumpRenderer.php#L17)

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
