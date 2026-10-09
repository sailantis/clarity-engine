# Class: SegmentScannerTrait

**Full name:** [Clarity\Engine\Tokenizer\SegmentScannerTrait](../../src/Engine/Tokenizer/SegmentScannerTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### tokenize() · <small>[🗎](../../src/Engine/Tokenizer/SegmentScannerTrait.php#L34)</small>

`public function tokenize(string $source): array`

Split a raw template source into an ordered array of segments.

Tag boundaries are located by a quote-aware, brace-depth-aware scanner
rather than a single flat regex. A closing delimiter can appear inside a
string literal (`{{ '}}' }}`), or a brace in the expression can contain
one (`{{ user{k}}}` closes after `user{k}`, not after `user{k`).

Each element is an array keyed by the KEY_TYPE, KEY_CONTENT and KEY_LINE
constants. The type is TEXT, OUTPUT, BLOCK or COMMENT. The line is the
1-based line where the segment starts.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$source` | string | - | Raw template source. |

**Return value**

- Type: `array`

**Throws**

- [ClarityException](Clarity_ClarityException.md)  When a tag is opened and never closed. Stray
closing delimiters in text are emitted as text.



---

[Back to the Index ⤴](README.md)
