# Class: ExpressionCoreTrait

**Full name:** [Clarity\Engine\Tokenizer\ExpressionCoreTrait](../../src/Engine/Tokenizer/ExpressionCoreTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### setEscapeContext() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L38)</small>

`public function setEscapeContext(string $context): void`

Set the output-escaping context for the next processExpression() call.

Called by the Compiler as it tracks the current position in the template.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$context` | string | - | 'html' | 'js' | 'css' |

**Return value**

- Type: `void`


---

### processExpression() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L43)</small>

`public function processExpression(string $expression): string`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expression` | string | - |  |

**Return value**

- Type: `string`


---

### processCondition() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L73)</small>

`public function processCondition(string $expression): string`

Convert a Clarity expression without pipeline â€” used for control
structure conditions (if, for, set) where auto-escape is meaningless.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expression` | string | - | Raw Clarity expression. |

**Return value**

- Type: `string`
- Description: PHP expression.


---

### processLvalue() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L97)</small>

`public function processLvalue(string $var): string`

Convert a Clarity variable chain to its PHP lvalue equivalent, for the
left-hand side of {% set var = ... %}.

Scope-aware by construction: open mode seeds the render scope into locals,
so `{% set a = â€¦ %}` compiles to a plain `$a = â€¦` and both worlds read the
SAME slot.  Sandbox mode targets `$__c_va['a']` exactly as before.  The
choice lives in the chain emitter, so it cannot drift from the read path.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$var` | string | - | Clarity variable name (e.g. 'user.name', 'items[0]'). |

**Return value**

- Type: `string`
- Description: PHP lvalue (e.g. '$user', or '$__c_va[\'user\'][\'name\']').


---

### convertVarsAndOps() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L112)</small>

`public function convertVarsAndOps(string $expr): string`

Convert a Clarity expression (no pipeline) to PHP by:
1. Replacing var-chains with $__c_va[...] accesses
2. Replacing logical/string operators with PHP equivalents
3. Rejecting function-call syntax: any identifier followed by '(' throws
   a ClarityException at compile time â€” use the |> filter pipeline instead.

Strategy: tokenize the expression into atoms (quoted strings, numbers,
identifiers/var-chains, operators, punctuation) and process each atom.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expr` | string | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
