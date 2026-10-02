# Class: Tokenizer

**Full name:** [Clarity\Engine\Tokenizer](../../src/Engine/Tokenizer.php)

Splits a Clarity template source into typed segments and processes
DSL expressions into PHP-ready strings.

Architecture
------------
This class holds the public API, the constants and the per-compilation state;
the behaviour is composed from the traits in `Clarity\Engine\Tokenizer\`
(scanner, expression loop, var-chain parser/emitter, filter & callable
compiler, operator tests, …).  See CONTRIBUTING.md for the trait map.

Segment types (constants on this class)
----------------------------------------
TEXT        â€“ raw HTML/text passed through verbatim
OUTPUT_TAG  â€“ {{ expression }} â€“ rendered (auto-escaped by default)
BLOCK_TAG   â€“ {% directive %}  â€“ control structures / directives

Expression processing
---------------------
The tokenizer converts Clarity expression syntax to valid PHP so the
Compiler can embed it directly.  PHP itself validates the resulting
syntax when the compiled class file is first loaded, so we intentionally
do not perform a full grammar check here.

Conversions performed
â€¢ var-chains (foo.bar[x].baz) â†’ $__c_va['foo']['bar'][$__c_va['x']]['baz']
â€¢ logical operators:  and â†’ &&,  or â†’ ||,  not â†’ !
â€¢ bitwise operators:  bor â†’ |,  band â†’ &,  bxor â†’ ^,  bnot â†’ ~,  blsh â†’ <<,  brsh â†’ >>
â€¢ concat operator:    ~   â†’ .
â€¢ all other tokens pass through unchanged (PHP validates them)

Pipeline (| or |>)
â€¢ Both | and |> act as the filter pipe operator (| is normalized to |> before processing)
â€¢ Each step after the pipe is a filter: name  or  name(arg1, arg2)
â€¢ Arguments are themselves processed as expressions
â€¢ Result: nested $__c_fn['name']($__c_fn['name']($expr, arg), â€¦)

Named arguments
â€¢ Clarity uses `=` syntax: filter(precision=2) or fn(from="system")
â€¢ These are emitted directly as PHP named arguments: `precision: 2`, `from: 'system'`
â€¢ PHP itself validates parameter names and arity at runtime â€” no reflection needed

## Public Constants

- **TEXT** = `1`
- **OUTPUT** = `2`
- **BLOCK** = `3`
- **COMMENT** = `4`
- **KEY_TYPE** = `0`
- **KEY_CONTENT** = `1`
- **KEY_LINE** = `2`

## Public methods

### setPrunedFunctions() · <small>[🗎](../../src/Engine/Tokenizer.php#L240)</small>

`public function setPrunedFunctions(array $names): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: `void`


---

### setContextInjectedFunctions() · <small>[🗎](../../src/Engine/Tokenizer.php#L246)</small>

`public function setContextInjectedFunctions(array $names): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: `void`


---

### setFilterProbes() · <small>[🗎](../../src/Engine/Tokenizer.php#L262)</small>

`public function setFilterProbes(array $names): void`

Declare the names whose FILTER form (`{{ x |> name }}`) is a pass-through
debug probe: the piped value is dumped to the debug renderer and then
returned unchanged, so a trailing step still sees the original value.

The compiler emits `$__c_sv['<service>'](...)` for these instead of
dispatching `$__c_fn['<name>']`, which is why the probe survives a
user-registered template function of the same name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - | name => service key |

**Return value**

- Type: `void`


---

### __construct() · <small>[🗎](../../src/Engine/Tokenizer.php#L292)</small>

`public function __construct(): mixed`

Built from the engine's policy before any compilation.  A Tokenizer that
was handed no policy compiles as [`Policy::restricted()`](Clarity_Engine_Policy.md#restricted), so the default
is safe even for a hand-built tokenizer.

**Return value**

- Type: `mixed`


---

### setPolicy() · <small>[🗎](../../src/Engine/Tokenizer.php#L304)</small>

`public function setPolicy(Clarity\Engine\Policy $policy): void`

Set the policy every capability question is answered from.

Also mirrors the deny-list into the flat map the call sites read, so the
policy stays the single source of truth while the hot paths keep a plain
array lookup.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$policy` | [Policy](Clarity_Engine_Policy.md) | - |  |

**Return value**

- Type: `void`


---

### getPolicy() · <small>[🗎](../../src/Engine/Tokenizer.php#L315)</small>

`public function getPolicy(): Clarity\Engine\Policy`

**Return value**

- Type: [Policy](Clarity_Engine_Policy.md)


---

### setLocalRoots() · <small>[🗎](../../src/Engine/Tokenizer.php#L336)</small>

`public function setLocalRoots(bool $enabled): void`

Open mode only: emit chain roots as PHP locals (see `$localRoots`).

The Compiler enables this together with the `extract()` seeding, so a
compiler that seeds no locals never emits a local read.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$enabled` | bool | - |  |

**Return value**

- Type: `void`


---

### setDeniedFunctions() · <small>[🗎](../../src/Engine/Tokenizer.php#L348)</small>

`public function setDeniedFunctions(array $names): void`

Replace the open-mode function guardrails.  Keys are lowercase function
names; empty (the default) allows every PHP function.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: `void`


---

### setRegistry() · <small>[🗎](../../src/Engine/Tokenizer.php#L367)</small>

`public function setRegistry(Clarity\Engine\Registry $registry): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**Return value**

- Type: `void`


---

### setLocalVars() · <small>[🗎](../../src/Engine/Tokenizer.php#L381)</small>

`public function setLocalVars(array $localVars): void`

Update the compile-time local variable context.

Called by the Compiler when entering or exiting a loop scope so that
variable resolution inside the loop uses direct PHP local variables
(e.g. `$item`) rather than $__c_va['item'] array lookups.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$localVars` | array | - | templateVarName â†’ PHP variable string |

**Return value**

- Type: `void`


---

### tokenize() · <small>[🗎](../../src/Engine/Tokenizer/SegmentScannerTrait.php#L34)</small>

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

### setEscapeContext() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L35)</small>

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

### processExpression() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L40)</small>

`public function processExpression(string $expression): string`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expression` | string | - |  |

**Return value**

- Type: `string`


---

### processCondition() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L70)</small>

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

### processLvalue() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L94)</small>

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

### convertVarsAndOps() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionCoreTrait.php#L109)</small>

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

### isIdentifier() · <small>[🗎](../../src/Engine/Tokenizer/ExpressionSupportTrait.php#L350)</small>

`public static function isIdentifier(string $name): bool`

Whether the whole string is one PHP variable name.

Callers that VALIDATE a name (rather than scan for one) must use this so
their accepted set can never drift from what the scanner above will
tokenize back out.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### buildFilterCall() · <small>[🗎](../../src/Engine/Tokenizer/FilterCompilerTrait.php#L212)</small>

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

### varChainToPhp() · <small>[🗎](../../src/Engine/Tokenizer/VarChainTrait.php#L692)</small>

`public function varChainToPhp(string $chain): string`

Convert a Clarity var-chain string to a PHP $__c_va[...] expression.

Supports:
foo           â†’ $__c_va['foo']
foo.bar       â†’ $__c_va['foo']['bar']
items[0]      â†’ $__c_va['items'][0]
items[index]  â†’ $__c_va['items'][$__c_va['index']]
a.b[c.d].e    â†’ $__c_va['a']['b'][$__c_va['c']['d']]['e']

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$chain` | string | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
