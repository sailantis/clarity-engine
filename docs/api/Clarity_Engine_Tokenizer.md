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
TEXT     – raw HTML/text passed through verbatim
OUTPUT   – {{ expression }}, rendered (escaped by default)
BLOCK    – {% directive %}, control structures and directives
COMMENT  – {# comment #}, dropped from the output

Expression processing
---------------------
The tokenizer converts Clarity expression syntax to valid PHP so the
Compiler can embed it directly.  PHP itself validates the resulting
syntax when the compiled class file is first loaded, so we intentionally
do not perform a full grammar check here.

Conversions performed
• var-chains (foo.bar[x].baz) → $__c_va['foo']['bar'][$__c_va['x']]['baz']
• logical operators:  and → &&,  or → ||,  not → !
• bitwise operators:  bor → |,  band → &,  bxor → ^,  bnot → ~,  blsh → <<,  brsh → >>
• concat operator:    ~   → .
• all other tokens pass through unchanged (PHP validates them)

Pipeline (| or |>)
• Both | and |> act as the filter pipe operator (| is normalized to |> before processing)
• Each step after the pipe is a filter: name  or  name(arg1, arg2)
• Arguments are themselves processed as expressions
• Result: nested $__c_fn['name']($__c_fn['name']($expr, arg), …)

Named arguments
• Clarity uses `:` syntax: filter(precision: 2) or fn(from: "system")
• These are emitted directly as PHP named arguments: `precision: 2`, `from: 'system'`
• PHP validates parameter names and arity at runtime

## Public Constants

- **TEXT** = `1`
- **OUTPUT** = `2`
- **BLOCK** = `3`
- **COMMENT** = `4`
- **KEY_TYPE** = `0`
- **KEY_CONTENT** = `1`
- **KEY_LINE** = `2`

## Public methods

### setPrunedFunctions() · <small>[🗎](../../src/Engine/Tokenizer.php#L255)</small>

`public function setPrunedFunctions(array $names): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: `void`


---

### setContextInjectedFunctions() · <small>[🗎](../../src/Engine/Tokenizer.php#L261)</small>

`public function setContextInjectedFunctions(array $names): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: `void`


---

### setFilterProbes() · <small>[🗎](../../src/Engine/Tokenizer.php#L277)</small>

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

### __construct() · <small>[🗎](../../src/Engine/Tokenizer.php#L306)</small>

`public function __construct(): mixed`

Starts with [`Policy::restricted()`](Clarity_Engine_Policy.md#restricted). The engine applies its own policy
through `setPolicy()`.

**Return value**

- Type: `mixed`


---

### processArgumentList() · <small>[🗎](../../src/Engine/Tokenizer.php#L334)</small>

`public function processArgumentList(string $rest): array`

Split a directive argument list into compiled positional and named arguments.

This is the shared parser behind custom-directive handlers that receive a
list — see [`PairedDirectiveTrait::directiveProcessExpr()`](Clarity_Engine_Compiler_PairedDirectiveTrait.md#directiveprocessexpr),
where `$processExpr($rest, true)` resolves to this method.  The grammar is
the one the filter syntax already uses:

    [name: ] expr [, [name: ] expr ...]

An argument whose text starts with `name:` is NAMED; anything else is
POSITIONAL and keys by its numeric index.  A positional argument may NOT
follow a named one, matching filter calls (whose named arguments become PHP
named arguments and are therefore order-bound).

Both lists hold PHP expressions, compiled through `processCondition()`,
so a caller never re-implements the split or the named-argument rule.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$rest` | string | - |  |

**Return value**

- Type: `array`
- Description: <br>[positional PHP expressions, named PHP expressions]

**Throws**

- [ClarityException](Clarity_ClarityException.md)  On an empty argument, a duplicate or empty-handed
named argument, or a positional after a named one.


---

### setPolicy() · <small>[🗎](../../src/Engine/Tokenizer.php#L359)</small>

`public function setPolicy(Clarity\Engine\Policy $policy): void`

Set the policy that compile-time rule checks consult.

The policy's deny-list is copied into a flat map at call time, so the
function-call hot path can use a plain array lookup. Later changes to
the policy are not seen until setPolicy() is called again.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$policy` | [Policy](Clarity_Engine_Policy.md) | - |  |

**Return value**

- Type: `void`


---

### getPolicy() · <small>[🗎](../../src/Engine/Tokenizer.php#L370)</small>

`public function getPolicy(): Clarity\Engine\Policy`

**Return value**

- Type: [Policy](Clarity_Engine_Policy.md)


---

### setLocalRoots() · <small>[🗎](../../src/Engine/Tokenizer.php#L390)</small>

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

### setRegistry() · <small>[🗎](../../src/Engine/Tokenizer.php#L409)</small>

`public function setRegistry(Clarity\Engine\Registry $registry): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**Return value**

- Type: `void`


---

### setLocalVars() · <small>[🗎](../../src/Engine/Tokenizer.php#L423)</small>

`public function setLocalVars(array $localVars): void`

Update the compile-time local variable context.

Called by the Compiler when entering or exiting a loop scope so that
variable resolution inside the loop uses direct PHP local variables
(e.g. `$item`) rather than $__c_va['item'] array lookups.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$localVars` | array | - | templateVarName → PHP variable string |

**Return value**

- Type: `void`


---

### setDynamicBindings() · <small>[🗎](../../src/Engine/Tokenizer.php#L437)</small>

`public function setDynamicBindings(array $names): void`

Update the set of names bound to a PHP local that is not a `$__c_va`
entry — loop variables and macro parameters.  See `$dynamicBindings`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | array | - |  |

**Return value**

- Type: `void`


---

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

### buildFilterCall() · <small>[🗎](../../src/Engine/Tokenizer/FilterCompilerTrait.php#L230)</small>

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

### varChainToPhp() · <small>[🗎](../../src/Engine/Tokenizer/VarChainTrait.php#L703)</small>

`public function varChainToPhp(string $chain): string`

Convert a Clarity var-chain string to PHP.

The root is a `$__c_va[...]` lookup, or a PHP local in open mode (see
`rootPhp()`). The examples show the sandbox-mode output:

  foo           → $__c_va['foo']
  foo.bar       → $__c_va['foo']['bar']
  items[0]      → $__c_va['items'][0]
  items[index]  → $__c_va['items'][$__c_va['index']]
  a.b[c.d].e    → $__c_va['a']['b'][$__c_va['c']['d']]['e']

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$chain` | string | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
