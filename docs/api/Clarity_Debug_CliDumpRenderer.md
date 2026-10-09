# Class: CliDumpRenderer

**Full name:** [Clarity\Debug\CliDumpRenderer](../../src/Debug/CliDumpRenderer.php)

Renders debug values as an indented tree for the terminal.

By default, dump() writes the tree to STDERR and returns ''. With
DumpOptions::forceToTemplate(true), render() returns the text instead.
renderForced() always returns the text; dd() uses it and writes the result to
STDERR (see [`DebugRuntime::dumpAndDie()`](Clarity_Debug_DebugRuntime.md#dumpanddie)).

Associative arrays and objects are shown as {key: value} and sequential
arrays as [item, item], one item per line. Sensitive keys and property names
are replaced with ***. ANSI colors are used when the stream the output goes
to supports them: VT100 on Windows, a TTY elsewhere.

## Public methods

### render() · <small>[🗎](../../src/Debug/CliDumpRenderer.php#L24)</small>

`public function render(mixed $value, Clarity\Debug\DumpOptions $opts): string`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**Return value**

- Type: `string`


---

### renderForced() · <small>[🗎](../../src/Debug/CliDumpRenderer.php#L41)</small>

`public function renderForced(mixed $value, Clarity\Debug\DumpOptions $opts): string`

Returns the rendered text whatever forceToTemplate is set to. dd() uses this.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
