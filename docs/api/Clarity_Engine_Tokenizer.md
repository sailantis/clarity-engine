# Class: Tokenizer

**Full name:** [Clarity\Engine\Tokenizer](../../src/Engine/Tokenizer.php)

Splits a Clarity template source into typed segments and processes
DSL expressions into PHP-ready strings.

Segment types (constants on this class)
----------------------------------------
TEXT        – raw HTML/text passed through verbatim
OUTPUT_TAG  – {{ expression }} – rendered (auto-escaped by default)
BLOCK_TAG   – {% directive %}  – control structures / directives

Expression processing
---------------------
The tokenizer converts Clarity expression syntax to valid PHP so the
Compiler can embed it directly.  PHP itself validates the resulting
syntax when the compiled class file is first loaded, so we intentionally
do not perform a full grammar check here.

Conversions performed
• var-chains (foo.bar[x].baz) → $__va['foo']['bar'][$__va['x']]['baz']
• logical operators:  and → &&,  or → ||,  not → !
• bitwise operators:  bor → |,  band → &,  bxor → ^,  bnot → ~,  blsh → <<,  brsh → >>
• concat operator:    ~   → .
• all other tokens pass through unchanged (PHP validates them)

Pipeline (| or |>)
• Both | and |> act as the filter pipe operator (| is normalized to |> before processing)
• Each step after the pipe is a filter: name  or  name(arg1, arg2)
• Arguments are themselves processed as expressions
• Result: nested $this->__fl['name']($this->__fl['name']($expr, arg), …)

Named arguments
• Clarity uses `=` syntax: filter(precision=2) or fn(from="system")
• These are emitted directly as PHP named arguments: `precision: 2`, `from: 'system'`
• PHP itself validates parameter names and arity at runtime — no reflection needed

## Public Constants

- **TEXT** = `1`
- **OUTPUT** = `2`
- **BLOCK** = `3`
- **COMMENT** = `4`
- **KEY_TYPE** = `0`
- **KEY_CONTENT** = `1`
- **KEY_LINE** = `2`

## Public methods

### setPrunedFunctions() · <small>[🗎](../../src/Engine/Tokenizer.php#L113)</small>

`public function setPrunedFunctions(array $names): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: void


---

### setContextInjectedFunctions() · <small>[🗎](../../src/Engine/Tokenizer.php#L119)</small>

`public function setContextInjectedFunctions(array $names): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: void


---

### setRegistry() · <small>[🗎](../../src/Engine/Tokenizer.php#L124)</small>

`public function setRegistry(Clarity\Engine\Registry $registry): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**Return value**

- Type: void


---

### setLocalVars() · <small>[🗎](../../src/Engine/Tokenizer.php#L138)</small>

`public function setLocalVars(array $localVars): void`

Update the compile-time local variable context.

Called by the Compiler when entering or exiting a loop scope so that
variable resolution inside the loop uses direct PHP local variables
($__lv_item_0) rather than $__va['item'] array lookups.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$localVars` | array | - | templateVarName → PHP variable string |

**Return value**

- Type: void


---

### tokenize() · <small>[🗎](../../src/Engine/Tokenizer.php#L167)</small>

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

- Type: array

**Throws**

- [ClarityException](Clarity_ClarityException.md)  When a tag is opened and never closed. A stray
delimiter is almost always an authoring bug, so
it is reported rather than emitted as text.


---

### setEscapeContext() · <small>[🗎](../../src/Engine/Tokenizer.php#L397)</small>

`public function setEscapeContext(string $context): void`

Set the output-escaping context for the next processExpression() call.

Called by the Compiler as it tracks the current position in the template.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$context` | string | - | 'html' | 'js' | 'css' |

**Return value**

- Type: void


---

### processExpression() · <small>[🗎](../../src/Engine/Tokenizer.php#L402)</small>

`public function processExpression(string $expression): string`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expression` | string | - |  |

**Return value**

- Type: string


---

### processCondition() · <small>[🗎](../../src/Engine/Tokenizer.php#L432)</small>

`public function processCondition(string $expression): string`

Convert a Clarity expression without pipeline — used for control
structure conditions (if, for, set) where auto-escape is meaningless.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expression` | string | - | Raw Clarity expression. |

**Return value**

- Type: string
- Description: PHP expression.


---

### processLvalue() · <small>[🗎](../../src/Engine/Tokenizer.php#L451)</small>

`public function processLvalue(string $var): string`

Convert a Clarity variable chain to its PHP $__va[...] equivalent.

Used for the left-hand side of {% set var = ... %}.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$var` | string | - | Clarity variable name (e.g. 'user.name', 'items[0]'). |

**Return value**

- Type: string
- Description: PHP lvalue (e.g. '$__va[\'user\'][\'name\']').


---

### convertVarsAndOps() · <small>[🗎](../../src/Engine/Tokenizer.php#L652)</small>

`public function convertVarsAndOps(string $expr): string`

Convert a Clarity expression (no pipeline) to PHP by:
1. Replacing var-chains with $__va[...] accesses
2. Replacing logical/string operators with PHP equivalents
3. Rejecting function-call syntax: any identifier followed by '(' throws
   a ClarityException at compile time — use the |> filter pipeline instead.

Strategy: tokenize the expression into atoms (quoted strings, numbers,
identifiers/var-chains, operators, punctuation) and process each atom.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expr` | string | - |  |

**Return value**

- Type: string


---

### varChainToPhp() · <small>[🗎](../../src/Engine/Tokenizer.php#L2053)</small>

`public function varChainToPhp(string $chain): string`

Convert a Clarity var-chain string to a PHP $__va[...] expression.

Supports:
foo           → $__va['foo']
foo.bar       → $__va['foo']['bar']
items[0]      → $__va['items'][0]
items[index]  → $__va['items'][$__va['index']]
a.b[c.d].e    → $__va['a']['b'][$__va['c']['d']]['e']

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$chain` | string | - |  |

**Return value**

- Type: string


---

### buildFilterCall() · <small>[🗎](../../src/Engine/Tokenizer.php#L2245)</small>

`public function buildFilterCall(string $filterSegment, string $phpValue): string`

Build a PHP filter call:  $this->__fl['name']($value, arg1, name2: arg2)

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

- Type: string
- Description: PHP call expression.



---

[Back to the Index ⤴](README.md)
