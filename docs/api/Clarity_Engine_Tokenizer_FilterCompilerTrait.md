# Class: FilterCompilerTrait

**Full name:** [Clarity\Engine\Tokenizer\FilterCompilerTrait](../../src/Engine/Tokenizer/FilterCompilerTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### splitArgumentList() · <small>[🗎](../../src/Engine/Tokenizer/FilterCompilerTrait.php#L100)</small>

`public function splitArgumentList(string $rest): array`

Split a directive or macro argument list into pieces that keep their
optional name: `[name: ] expr [, [name: ] expr ...]`.

This is the SPLIT half of `processArgumentList()`: it splits and
names the arguments but compiles nothing, which is what a caller needs
when the arguments are not PHP call arguments. A macro call binds each
piece to the PARAMETER of that name instead of passing it positionally
to PHP.

A piece's name is `null` for a positional argument. The rules are the
ones the tokenizer already applies to call arguments, so a named macro
argument behaves exactly like a named filter argument: positional
arguments cannot follow named ones, names must be unique, and an empty
argument (`a,,b`) is refused. Commas inside quoted strings or nested
`()`/`[]`/`}` do not split.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$rest` | string | - |  |

**Return value**

- Type: `array`

**Throws**

- [ClarityException](Clarity_ClarityException.md)  On an empty argument, a duplicate named argument,
or a positional argument after a named one.


---

### buildFilterCall() · <small>[🗎](../../src/Engine/Tokenizer/FilterCompilerTrait.php#L296)</small>

`public function buildFilterCall(string $filterSegment, string $phpValue): string`

Build a PHP filter call:  $__c_fn['name']($value, arg1, name2: arg2)

For map / filter / reduce the first argument must be either:
  - a lambda expression:  param => expression
  - a filter reference:   'filterName' or "filterName"
Bare variable names are rejected at compile time.

Named arguments (`identifier: expression`) are emitted directly as PHP named
arguments (`identifier: phpExpr`). PHP validates names and arity at runtime.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$filterSegment` | string | - | Clarity filter segment e.g. 'number(2)' or 'upper' |
| `$phpValue` | string | - | Already-converted PHP expression for the input value. |

**Return value**

- Type: `string`
- Description: PHP call expression.



---

[Back to the Index ⤴](README.md)
