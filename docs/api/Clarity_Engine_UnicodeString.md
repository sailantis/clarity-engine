# Class: UnicodeString

**Full name:** [Clarity\Engine\UnicodeString](../../src/Engine/UnicodeString.php)

A UTF-8 string that can be accessed by character index and counted.

This is used internally by the Clarity DSL engine to support
character indexing and array access for strings with multibyte characters.

ArrayAccess: Get the character at the given index (0-based).

Example:
  $s = new UnicodeString("😿 Hello");
  echo $s[0]; // "😿"

Note: This class is immutable, so offsetSet and offsetUnset will throw exceptions.

## Public methods

### __construct() · <small>[🗎](../../src/Engine/UnicodeString.php#L24)</small>

`public function __construct(array|string $str, int $offset = 0, int|null $length = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$str` | array\|string | - |  |
| `$offset` | int | `0` |  |
| `$length` | int\|null | `null` |  |

**Return value**

- Type: mixed


---

### substring() · <small>[🗎](../../src/Engine/UnicodeString.php#L41)</small>

`public function substring(int $offset, int|null $length = null): static`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$offset` | int | - |  |
| `$length` | int\|null | `null` |  |

**Return value**

- Type: static


---

### toUpper() · <small>[🗎](../../src/Engine/UnicodeString.php#L46)</small>

`public function toUpper(): static`

**Return value**

- Type: static


---

### toLower() · <small>[🗎](../../src/Engine/UnicodeString.php#L51)</small>

`public function toLower(): static`

**Return value**

- Type: static


---

### offsetExists() · <small>[🗎](../../src/Engine/UnicodeString.php#L56)</small>

`public function offsetExists(mixed $offset): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$offset` | mixed | - |  |

**Return value**

- Type: bool


---

### offsetGet() · <small>[🗎](../../src/Engine/UnicodeString.php#L62)</small>

`public function offsetGet(mixed $offset): string`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$offset` | mixed | - |  |

**Return value**

- Type: string


---

### offsetSet() · <small>[🗎](../../src/Engine/UnicodeString.php#L71)</small>

`public function offsetSet(mixed $offset, mixed $value): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$offset` | mixed | - |  |
| `$value` | mixed | - |  |

**Return value**

- Type: void


---

### offsetUnset() · <small>[🗎](../../src/Engine/UnicodeString.php#L76)</small>

`public function offsetUnset(mixed $offset): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$offset` | mixed | - |  |

**Return value**

- Type: void


---

### __toString() · <small>[🗎](../../src/Engine/UnicodeString.php#L81)</small>

`public function __toString(): string`

**Return value**

- Type: string


---

### count() · <small>[🗎](../../src/Engine/UnicodeString.php#L86)</small>

`public function count(): int`

**Return value**

- Type: int


---

### jsonSerialize() · <small>[🗎](../../src/Engine/UnicodeString.php#L91)</small>

`public function jsonSerialize(): mixed`

**Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
