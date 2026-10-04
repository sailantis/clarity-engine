# Class: CssDumpRenderer

**Full name:** [Clarity\Debug\CssDumpRenderer](../../src/Debug/CssDumpRenderer.php)

Renders debug values as a CSS comment: /* DEBUG_DUMP: {json} *\/

Closing comment sequences in the JSON are escaped, and tag delimiters are
encoded to protect the surrounding <style> element.

## Public methods

### render() · <small>[🗎](../../src/Debug/CssDumpRenderer.php#L15)</small>

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
