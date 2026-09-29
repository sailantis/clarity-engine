# Class: SegmentScannerTrait

**Full name:** [Clarity\Engine\Tokenizer\SegmentScannerTrait](../../src/Engine/Tokenizer/SegmentScannerTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### tokenize() · <small>[🗎](../../src/Engine/Tokenizer/SegmentScannerTrait.php#L37)</small>

`public function tokenize(string $source): array`

Split a raw template source into an ordered array of segments.

Tag boundaries are located by a quote-aware, brace-depth-aware scanner
rather than a single flat regex. A closing delimiter may legitimately
appear inside a string literal (`{{ '}}' }}`) or next to a literal brace
(`{{ v }}}`, `{{ { a: 1 } }}`, `{{ user{k}}}`), none of which a naive
lazy match can handle.

Each element is:  ['type' => TEXT|OUTPUT|BLOCK, 'content' => string, 'line' => int]

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$source` | string | - | Raw template source. |

**Return value**

- Type: `array`

**Throws**

- [ClarityException](Clarity_ClarityException.md)  When a tag is opened and never closed. A stray
delimiter is almost always an authoring bug, so
it is reported rather than emitted as text.



---

[Back to the Index ⤴](README.md)
