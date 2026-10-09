# Class: ExpressionCoreTrait

**Full name:** [Clarity\Engine\Tokenizer\ExpressionCoreTrait](../../src/Engine/Tokenizer/ExpressionCoreTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### setEscapeContext() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L23)</small>

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

### processExpression() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L42)</small>

`public function processExpression(string $expression): string`

Convert an output expression, `{{ ... }}`, to a PHP expression string.

The pipeline (`|>`) is processed first. The leftmost segment is the
expression, and each following segment is a filter call. Unless the
pipeline ends in `raw` (the filter form that disables escaping), the result
is escaped for the current context set by `setEscapeContext()`:
  - html: `htmlspecialchars()`
  - js:   `json_encode()` with HEX flags, safe for inline script
  - css:  cast to string, no escaping

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expression` | string | - | Raw expression from inside `{{ ... }}`. |

**Return value**

- Type: `string`
- Description: PHP expression (no leading `<?=` or trailing `?>`).


---

### processCondition() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L72)</small>

`public function processCondition(string $expression): string`

Convert a Clarity expression without pipeline — used for control
structure conditions (if, for, set) where auto-escape is meaningless.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expression` | string | - | Raw Clarity expression. |

**Return value**

- Type: `string`
- Description: PHP expression.


---

### processLvalue() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L96)</small>

`public function processLvalue(string $var): string`

Convert a Clarity variable chain to its PHP lvalue equivalent, for the
left-hand side of {% set var = ... %}.

Scope-aware by construction. In open mode the render scope is seeded into
locals, so `{% set a = … %}` compiles to a plain `$a = …`, and reads of `a`
use the same variable. In sandbox mode the target is `$__c_va['a']`. The
choice is made in the chain emitter, so it matches the read path.

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
3. Resolving function calls: a registered name, or a PHP function the policy
   allows, compiles. Any other name throws a ClarityException at compile
   time. Use the `|>` filter pipeline for filters.

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
