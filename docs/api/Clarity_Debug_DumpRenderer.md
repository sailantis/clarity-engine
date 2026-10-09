# Interface: DumpRenderer

**Full name:** [Clarity\Debug\DumpRenderer](../../src/Debug/DumpRenderer.php)

Renders a value as debug output.

Implementations may have side effects: the CLI renderer writes to STDERR.
render() returns the string to insert into the template output, or '' when
the output was written directly to STDERR.

## Public methods

### render() · <small>[🗎](../../src/Debug/DumpRenderer.php#L16)</small>

`public function render(mixed $value, Clarity\Debug\DumpOptions $opts): string`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
