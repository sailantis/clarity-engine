# Class: JsDumpRenderer

**Full name:** [Clarity\Debug\JsDumpRenderer](../../src/Debug/JsDumpRenderer.php)

Renders debug values as a JavaScript block comment: ;/* DEBUG_DUMP: {json} *\/

The output starts with an empty statement (;) followed by the comment, so it
can be placed where a JavaScript statement is allowed. Sensitive keys are
replaced with '***', and values nested deeper than maxDepth with '…'. The
comment-closing sequence in the JSON is escaped by inserting a backslash
before the slash, so it cannot end the comment early. '<' and '>' are
written as \u003C and \u003E, so the output cannot close a surrounding
<script> element.

## Public methods

### render() · <small>[🗎](../../src/Debug/JsDumpRenderer.php#L22)</small>

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
