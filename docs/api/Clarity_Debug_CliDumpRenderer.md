# 🧩 Class: CliDumpRenderer

**Full name:** [Clarity\Debug\CliDumpRenderer](../../src/Debug/CliDumpRenderer.php)

Renders debug values as an ANSI-colored (or plain-text) tree on STDERR.

By default, output goes to STDERR (pipeline-safe: does not corrupt stdout).
Set DumpOptions::$forceToTemplate = true to receive the string instead.

Associative arrays → {key: value}, sequential arrays → [item, …].
Sensitive keys are replaced with ***.

## 🚀 Public methods

### render() · [source](../../src/Debug/CliDumpRenderer.php#L18)

`public function render(mixed $value, Clarity\Debug\DumpOptions $opts): string`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**➡️ Return value**

- Type: string


---

### renderForced() · [source](../../src/Debug/CliDumpRenderer.php#L34)

`public function renderForced(mixed $value, Clarity\Debug\DumpOptions $opts): string`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**➡️ Return value**

- Type: string



---

[Back to the Index ⤴](README.md)
