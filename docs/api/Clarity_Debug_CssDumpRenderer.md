# Class: CssDumpRenderer

**Full name:** [Clarity\Debug\CssDumpRenderer](../../src/Debug/CssDumpRenderer.php)

Renders debug values as a CSS block comment: /* DEBUG_DUMP: {json} *\/

Sensitive keys are replaced with '***', and values nested deeper than
maxDepth with '…'. The comment-closing sequence in the JSON is escaped by
inserting a backslash before the slash. '<' and '>' are written as \u003C
and \u003E, so the output cannot close a surrounding <style> element.

## Public methods

### render() · <small>[🗎](../../src/Debug/CssDumpRenderer.php#L19)</small>

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
