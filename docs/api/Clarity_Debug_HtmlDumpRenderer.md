# Class: HtmlDumpRenderer

**Full name:** [Clarity\Debug\HtmlDumpRenderer](../../src/Debug/HtmlDumpRenderer.php)

Renders debug values as a collapsible HTML tree using <details>/<summary>.

Each array is labelled with its type and size: "object (n)" for a non-empty
associative array, showing key: value entries, and "array (n)" for a
sequential array. Empty arrays render as []. Objects are shown by their
properties, like associative arrays. The top-level array is expanded and
nested arrays are collapsed. Scalar values are HTML-escaped. Values under
masked keys or property names are shown as ***.

Every render includes the inline <style> block, so each dump is
self-contained.

## Public methods

### render() · <small>[🗎](../../src/Debug/HtmlDumpRenderer.php#L24)</small>

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
