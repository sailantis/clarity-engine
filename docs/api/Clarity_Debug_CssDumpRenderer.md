# Class: CssDumpRenderer

**Full name:** [Clarity\Debug\CssDumpRenderer](../../src/Debug/CssDumpRenderer.php)

Renders debug values as a CSS comment. Closing comment sequences in the JSON
payload are escaped, and tag delimiters are JSON-hex-encoded to protect the
surrounding `<style>` element.

## Public methods

### render() · <small>[🗎](../../src/Debug/CssDumpRenderer.php#L17)</small>

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
