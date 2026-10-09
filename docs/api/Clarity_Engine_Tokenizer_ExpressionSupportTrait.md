# Class: ExpressionSupportTrait

**Full name:** [Clarity\Engine\Tokenizer\ExpressionSupportTrait](../../src/Engine/Tokenizer/ExpressionSupportTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### isIdentifierStart() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionSupportTrait.php#L318)</small>

`public static function isIdentifierStart(string $ch): bool`

Whether the character (a single BYTE) can start an identifier.

Mirrors PHP's variable-name rule: a letter, an underscore, or a byte in
0x80-0xFF.  Uses `ord()` rather than ctype_alpha(), which is
locale-dependent and would disagree with the runtime on a non-C locale.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ch` | string | - |  |

**Return value**

- Type: `bool`


---

### isIdentifierChar() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionSupportTrait.php#L333)</small>

`public static function isIdentifierChar(string $ch): bool`

Whether the character (a single BYTE) can appear inside an identifier.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ch` | string | - |  |

**Return value**

- Type: `bool`


---

### isIdentifier() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionSupportTrait.php#L347)</small>

`public static function isIdentifier(string $name): bool`

Whether the whole string is one PHP variable name. Validate names with this,
which matches [`Tokenizer::IDENT_RE()`](Clarity_Engine_Tokenizer.md#ident_re).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`



---

[Back to the Index ⤴](README.md)
