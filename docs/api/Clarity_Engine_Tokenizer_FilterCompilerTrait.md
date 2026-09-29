# Class: FilterCompilerTrait

**Full name:** [Clarity\Engine\Tokenizer\FilterCompilerTrait](../../src/Engine/Tokenizer/FilterCompilerTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### buildFilterCall() · <small>[🗎](../../src/Engine/Tokenizer/FilterCompilerTrait.php#L215)</small>

`public function buildFilterCall(string $filterSegment, string $phpValue): string`

Build a PHP filter call:  $__c_fn['name']($value, arg1, name2: arg2)

For map / filter / reduce the first argument must be either:
  - a lambda expression:  param => expression
  - a filter reference:   'filterName' or "filterName"
Bare variable names are rejected at compile time.

Named arguments (`identifier=expression`) are emitted directly as PHP named
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
