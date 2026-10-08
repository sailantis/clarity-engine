# Class: Registry

**Full name:** [Clarity\Engine\Registry](../../src/Engine/Registry.php)

Registry of named callables for the Clarity template engine.

The registry answers THREE independent questions, each with its own table:

  - `$inlineDefinitions` — HOW does the name compile? (a `php` codegen
    template, or nothing)
  - `$filters`       — IS the name pipeable? (`value |> name`)
  - `$callables`     — what do we invoke at RUNTIME? (the `$__c_fn` table)

A name is reachable through the pipe operator (`|>`) and/or through call
syntax `name(...)`; a single name may be both, and the dispatch is chosen by
SYNTAX, not by runtime type. Whether a name may be piped is a COMPILE-TIME
decision, so it lives in data (`$filters` + the inline templates) and the
runtime never re-checks it.

User code registers filters via `addFilter()`, functions via
`addFunction()`, compiled filter templates via `addInlineFilter()`,
and compiled call-only functions via `addInlineFunction()`.

Built-in Filters Catalog
-------------------------

**String / Text Manipulation**
- `trim`                      : Remove leading/trailing whitespace
- `upper`                     : Convert to uppercase (mb_strtoupper)
- `lower`                     : Convert to lowercase (mb_strtolower)
- `capitalize`                : First character uppercase, rest lowercase
- `title`                     : Title-case every word
- `nl2br`                     : Insert <br> tags before newlines (use with |> raw)
- `replace($search, $replace)`: String replacement (str_replace)
- `split($delimiter [, $limit])`: Split string into array (explode)
- `join($glue)`               : Join array elements to string (implode)
- `slug [$separator='-']`     : Generate URL-friendly slug
- `striptags [$allowed]`      : Strip HTML/PHP tags
- `truncate($length [, $ellipsis='…'])`: Truncate string to length
- `sprintf(...$args)`         : sprintf-style string formatting (alias: `format`)
- `escape` (alias: `esc`)     : HTML-escape (htmlspecialchars) — rarely needed, auto-escaping enabled
- `raw`                       : Disable auto-escaping for this output (DANGEROUS with user input)

**Numbers**
- `number($decimals=2)`       : Format number with decimal places (number_format)
- `abs`                       : Absolute value
- `round [$precision=0]`      : Round to decimal places
- `ceil`                      : Round up to nearest integer
- `floor`                     : Round down to nearest integer

**Dates & Times**
- `date [$format='Y-m-d']`    : Format timestamp/DateTimeInterface/date string
  (DateTimeInterface values reach this filter directly; the filter reads
  their timestamp without converting them)
- `date_modify($modifier, $format='c')` : Apply a date modifier (e.g. '+1 day'),
                                         return the result formatted with `$format`

**Arrays & Collections**
- `first`                     : Get first element (works on arrays and strings)
- `last`                      : Get last element (works on arrays and strings)
- `keys`                      : Get array keys
- `values`                    : Get array values
- `length` (alias: `len`)     : Count elements (arrays) or string length (mb_strlen)
- `slice($start [, $length])` : Extract portion (array_slice / mb_substr)
- `merge($other)`             : Merge arrays (array_merge)
- `sort`                      : Return sorted copy
- `reverse`                   : Reverse array or string (Unicode-aware)
- `shuffle`                   : Return shuffled copy
- `batch($size [, $fill])`    : Split into chunks, optionally padded

**Collection Operations (Lambda Support)**
- `map(lambda|filterRef)`     : Transform each element
  Usage: `{{ users |> map(u => u.name) }}` or `{{ items |> map("upper") }}`
- `filter [lambda|filterRef]` : Keep elements matching condition (returns a new array)
  Usage: `{{ items |> filter(i => i.active) }}`
  Note: `array_filter` PRESERVES keys — it does not reindex. Follow with
  `|> values` if you need a list.
- `reduce(lambda|filterRef [, $initial])`: Reduce to single value
  Usage: `{{ numbers |> reduce(sum, value => sum + value, 0) }}`
  Note: Lambda receives explicit accumulator and current-element parameters

**Utility Filters**
- `json`                      : JSON encode (use with |> raw)
- `default($fallback)`        : Return fallback if value is empty/falsy
- `url_encode`                : URL-encode value (rawurlencode)
- `data_uri [$mimeType]`      : Generate base64-encoded data: URI
- `unicode`                   : Wrap in UnicodeString for advanced operations

Dynamic variable access is spelled `${expr}` / `$$name` in the template
syntax (see docs); it is not a filter.

Built-in Functions
------------------
- `vars()`: Returns current template variables array
- `context()`: Deprecated alias of `vars()`
- `include($view [, $context])`: Render another template dynamically

Custom Filter Examples
----------------------
```php
// Currency formatting
$registry->addFilter('currency', function($amount, string $symbol = '€') {
    return $symbol . ' ' . number_format($amount, 2);
});

// Smart excerpt with word boundary
$registry->addFilter('excerpt', function($text, int $maxLength = 150) {
    if (mb_strlen($text) <= $maxLength) return $text;
    $truncated = mb_substr($text, 0, $maxLength);
    $lastSpace = mb_strrpos($truncated, ' ');
    return mb_substr($truncated, 0, $lastSpace) . '…';
});
```

Template usage:
```twig
{{ price |> currency('$') }}  {# Output: $ 123.45 #}
{{ article.body |> excerpt(200) }}
```

## Public Constants

- **DEFAULT_DENIED_FUNCTIONS** = `[]`

## Public methods

### setDumpHandler() · <small>[🗎](../../src/Engine/Registry.php#L374)</small>

`public function setDumpHandler(Closure|null $fn): void`

Install context-aware dump/dd handlers, produced by the engine's debug
runtime.  Called internally — not part of the public engine API.

The registry does NOT ship a default for either name.  Debug output is
engine state (it owns the renderers and the DumpOptions), so with no
handler installed `dump()` is a no-op and `dd()` is an explicit error,
rather than a second, renderer-less formatter whose output would ignore
masking.  See [`DebugRuntime`](Clarity_Debug_DebugRuntime.md).

Passing null restores that neutral state; it is what disabling debug does.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fn` | Closure\|null | - |  |

**Return value**

- Type: `void`


---

### setDdHandler() · <small>[🗎](../../src/Engine/Registry.php#L379)</small>

`public function setDdHandler(Closure|null $fn): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fn` | Closure\|null | - |  |

**Return value**

- Type: `void`


---

### __construct() · <small>[🗎](../../src/Engine/Registry.php#L384)</small>

`public function __construct(callable|null $includeRenderer = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$includeRenderer` | callable\|null | `null` |  |

**Return value**

- Type: `mixed`


---

### hasFilter() · <small>[🗎](../../src/Engine/Registry.php#L890)</small>

`public function hasFilter(string $name): bool`

Check whether a named filter is registered — i.e. may be used with `|>`.

True when the name has a runtime-backed filter declaration in
`$filters`, or an inline template in `$inlineDefinitions` whose
`filter` flag is not false. A runtime callable alone is NOT enough, and
neither is a CALL-ONLY inline function (`isset`): `context` and `include`
are call-only builtins whose first argument is not a piped value, so they
must not become filterable just by sharing the callable table; `isset` is
call-only for the same reason (see `addInlineFunction()`).

`dump` is pipeable even though its callable is not value-first: it is
declared in `$filters`, and the compiler emits a pass-through probe
for it instead of dispatching the callable with the piped value.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### addFilter() · <small>[🗎](../../src/Engine/Registry.php#L918)</small>

`public function addFilter(string $name, callable $fn): static`

Register a callable as a user-defined filter.

The callable receives ($value, ...$args). It is reachable in the filter
form (`value |> name`); call syntax is not enabled unless the name is also
registered as a function.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable receiving ($value, ...$args). |

**Return value**

- Type: `static`


---

### addInlineFilter() · <small>[🗎](../../src/Engine/Registry.php#L944)</small>

`public function addInlineFilter(string $name, array $definition): void`

Register an additional inline filter that is compiled directly into the
generated PHP (zero runtime call overhead).

The definition must follow the same structure as the built-in records:
  'php'        – PHP expression template with {1} for the piped value and
                 {2}, {3}, … for each additional parameter.
  'params'     – (optional) ordered list of parameter names.
  'defaults'   – (optional) map of paramName → PHP default expression.
  'variadic'   – (optional) true for variadic filters like 'sprintf'.
  'valueParam' – (optional) parameter that receives the piped value.

Registering an inline template makes the name BOTH pipeable and callable
(the same template backs both forms), so nothing else has to be declared.
For a template that must stay call-only, use `addInlineFunction()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates. |
| `$definition` | array | - |  |

**Return value**

- Type: `void`


---

### addInlineFunction() · <small>[🗎](../../src/Engine/Registry.php#L971)</small>

`public function addInlineFunction(string $name, array $definition): void`

Register an inline FUNCTION that is compiled directly into the generated
PHP but can NOT be used with the pipe operator.

`addInlineFunction()` is `addInlineFilter()` with the `filter` flag turned
off: the same `{1}`-templated codegen backs `name(...)`, while
`value |> name` is refused at compile time with the same message a
call-only runtime function gets (“… is a function, not a filter”).

It exists for forms whose argument is not a value to be transformed but a
piece of SOURCE the compiler must see, so there is no meaningful piped
form: `isset(x)` is a presence probe on a variable chain, and piping into
it would always be asking whether an expression exists.

The `php` template is emitted verbatim around its compiled arguments, so a
definition takes on the same restrictions as the PHP construct it mirrors —
`isset()` accepts only a variable or a chain over one, and a template whose
FIRST argument is an expression is a compile-time error, not a PHP fatal.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates. |
| `$definition` | array | - |  |

**Return value**

- Type: `void`


---

### hasInlineFilter() · <small>[🗎](../../src/Engine/Registry.php#L983)</small>

`public function hasInlineFilter(string $name): bool`

Check whether a named inline (compile-time) template is registered.

This asks whether the name has ANY codegen record, filter or call-only
function alike. Use `hasFilter()` to ask whether it may be piped.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### isInlineFunction() · <small>[🗎](../../src/Engine/Registry.php#L992)</small>

`public function isInlineFunction(string $name): bool`

Check whether a named inline template is a CALL-ONLY inline function —
callable but not pipeable (registered via `addInlineFunction()`).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### getInlineFilter() · <small>[🗎](../../src/Engine/Registry.php#L1005)</small>

`public function getInlineFilter(string $name): array|null`

Get the compile-time definition of a named inline template.

Only `php`-templated names are returned; a purely callable filter has no
codegen template and yields null. Call-only inline functions are included:
the compiler needs the record to compile their call form.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `array`|`null`


---

### addService() · <small>[🗎](../../src/Engine/Registry.php#L1021)</small>

`public function addService(string $name, mixed $service): static`

Store a non-callable service object under a named key so that compiled
template render bodies can access it via `$__c_sv['key']->method()` or
`$this->services['key']->method()`.

The key is conventionally prefixed with `__` to avoid collisions with
real filter names (e.g. `__locale`, `__translator`).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Key under which the service is accessible in templates. |
| `$service` | mixed | - | Any value; not required to be callable. |

**Return value**

- Type: `static`


---

### hasService() · <small>[🗎](../../src/Engine/Registry.php#L1030)</small>

`public function hasService(string $name): bool`

Check whether a named service is registered.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### getService() · <small>[🗎](../../src/Engine/Registry.php#L1040)</small>

`public function getService(string $name): mixed`

Retrieve a named service.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `mixed`

**Throws**

- RuntimeException  if the service is not registered.


---

### allServices() · <small>[🗎](../../src/Engine/Registry.php#L1056)</small>

`public function allServices(): array`

Get all registered filters as a name → callable/value map.

The returned array includes callable filters, inline-filter markers
(value `true`), and services registered via `addService()`.

**Return value**

- Type: `array`


---

### allCallables() · <small>[🗎](../../src/Engine/Registry.php#L1083)</small>

`public function allCallables(): array`

Get every registered callable as a name → callable map.

This is the ONE runtime table: compiled templates receive it as
`$__c_fn` and dispatch BOTH the pipe form and the call form through it
(`$__c_fn['slug'](…)`). There is no runtime filter table, because whether
a name may be piped is a *compile-time* decision (`$filters` +
`$inlineDefinitions`) enforced by the compiler — the runtime does not
need to re-check it.

Every registration that must be dispatched at runtime is present
regardless of filterability, so `context`, `include` and `dd` (call-only)
and `json`, `dump` (both forms) are all included. There is no
filtering or rebuilding step: `$callables` IS the table, so this
returns it directly and costs nothing.

The engine rebinds the `dump`/`dd` entries to the debug formatter before
handing the table to a template — see
[`ClarityEngine::runtimeCallables()`](Clarity_ClarityEngine.md#runtimecallables).

**Return value**

- Type: `array`


---

### addFunction() · <small>[🗎](../../src/Engine/Registry.php#L1098)</small>

`public function addFunction(string $name, callable $fn): static`

Register a user-defined function.

The callable receives any positional arguments. The name becomes callable
in templates via `name(...)`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'greet'). |
| `$fn` | callable | - | Callable receiving any positional arguments. |

**Return value**

- Type: `static`


---

### hasFunction() · <small>[🗎](../../src/Engine/Registry.php#L1111)</small>

`public function hasFunction(string $name): bool`

Check whether a named function (call syntax) is registered.

Any name with something to call — a runtime callable, or an inline `php`
template (which compiles to the call itself), call-only or not — is
callable.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### hasCallable() · <small>[🗎](../../src/Engine/Registry.php#L1119)</small>

`public function hasCallable(string $name): bool`

Check whether a name can be invoked under call syntax `name(...)`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### getCallable() · <small>[🗎](../../src/Engine/Registry.php#L1128)</small>

`public function getCallable(string $name): callable|null`

Resolve the PHP callable for a name at call sites, or null when the name
is inline-only (the caller then derives the call inline from `php`).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `callable`|`null`


---

### addDirective() · <small>[🗎](../../src/Engine/Registry.php#L1199)</small>

`public function addDirective(string $keyword, callable $handler, Clarity\Engine\Directive|null $directive = null): static`

Registry of custom directive handlers for the Clarity compiler.

Modules register directive keywords (e.g. `with_locale`) whose compilation is delegated to user-supplied callables instead of being handled by the built-in match table in [`Compiler::compileBlock()`](Clarity_Engine_Compiler.md#compileblock).

Handler signature
-----------------
```php
function(
    string           $rest,        // everything after the keyword in the {% … %} tag
    TemplateLocation $at,          // where the tag sits: name, line and file
    callable         $processExpr  // fn(string $clarityExpr, bool $asList = false): mixed — Clarity expression(s) to PHP
): string                          // compiled PHP statement(s) for this directive
```

`$at` gives the handler what it needs to raise a located
[`ClarityException`](Clarity_ClarityException.md) without being handed anything else:

```php
$engine->addDirective('with_locale', function (string $rest, TemplateLocation $at, callable $processExpr): string {
    if (\trim($rest) === '') {
        throw new ClarityException("'with_locale' requires a locale argument", $at);
    }
    return "\$__c_sv['locale']->push({$processExpr(\trim($rest))});";
});
```

Pass `$at` straight to the exception's second parameter: it carries the
logical name, the line, and — when the active loader is file-backed — the
physical path. A bare `throw new ClarityException('…')` is located too, but
only the compiler can discover where; handing `$at` through keeps the
exception complete at the point it was thrown.

Example registration (inside a Module::register() call):
```php
$engine->addDirective('with_locale', function(string $rest, TemplateLocation $at, callable $processExpr): string {
    $param = $processExpr(trim($rest));
    return "\$__c_sv['locale']->push({$param});";
});
$engine->addDirective('endwith_locale', fn(...) => "\$__c_sv['locale']->pop();");
```

Paired (block) directives
-------------------------
A directive that wraps a body declares its parts with a [`Directive`](Clarity_Engine_Directive.md)
value, whose factory name states the role:
```php
$engine->addDirective('cache',      $openHandler,   Directive::opens('endcache', 'cache_else'));
$engine->addDirective('cache_else', $branchHandler, Directive::branches('cache'));
$engine->addDirective('endcache',   $closeHandler,  Directive::closes('cache'));
$engine->addDirective('cache_ctrl', $leafHandler,   Directive::inside('cache'));
```
The opener is the single source of truth for the structure; each member's
`branches()`/`closes()` ASSERTS a role that must match what the opener
declared, so "registered the closer but forgot the opener" is caught at the
start of every compile.  `inside()` is a separate, weaker claim — the tag is
an ordinary leaf that may only appear directly within its owner.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | Directive keyword (lowercase, e.g. 'with_locale'). |
| `$handler` | callable | - | See class docblock for expected signature. |
| `$directive` | [Directive](Clarity_Engine_Directive.md)\|null | `null` | Omit for an ordinary directive; otherwise one of [`Directive::opens()`](Clarity_Engine_Directive.md#opens),<br>[`Directive::branches()`](Clarity_Engine_Directive.md#branches), [`Directive::closes()`](Clarity_Engine_Directive.md#closes), [`Directive::inside()`](Clarity_Engine_Directive.md#inside). |

**Return value**

- Type: `static`

**Throws**

- [ClarityException](Clarity_ClarityException.md)  On an invalid keyword, a built-in keyword, or a
contradictory or self-referential declaration.


---

### assertPairingConsistency() · <small>[🗎](../../src/Engine/Registry.php#L1382)</small>

`public function assertPairingConsistency(): void`

Rebuild the reverse pairing index and assert every declaration is coherent.

Called at the start of every compile: registration order is not fixed, so a
close tag may be registered before the opener that declares it, and only a
pass over the finished tables can catch a missing handler or a contradiction.

**Return value**

- Type: `void`

**Throws**

- [ClarityException](Clarity_ClarityException.md)  On a missing member handler, a member declared by
two openers, a member that is also an opener, or a
member/containment claim the owner does not honour.


---

### isDirectiveOpener() · <small>[🗎](../../src/Engine/Registry.php#L1503)</small>

`public function isDirectiveOpener(string $keyword): bool`

Whether `$keyword` opens a paired construct (i.e. declares member tags).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `bool`


---

### getDirectiveCloseKeyword() · <small>[🗎](../../src/Engine/Registry.php#L1511)</small>

`public function getDirectiveCloseKeyword(string $keyword): string|null`

The close-tag keyword of the construct `$keyword` opens, or null.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `string`|`null`


---

### getDirectiveBranchKeywords() · <small>[🗎](../../src/Engine/Registry.php#L1527)</small>

`public function getDirectiveBranchKeywords(string $keyword): array`

Branch keywords declared by the construct `$keyword` opens.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `array`


---

### getDirectiveOwner() · <small>[🗎](../../src/Engine/Registry.php#L1543)</small>

`public function getDirectiveOwner(string $keyword): string|null`

The construct a close/branch tag belongs to, or null when `$keyword` is an
ordinary, unpaired directive.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `string`|`null`


---

### getDirectiveContainmentOwner() · <small>[🗎](../../src/Engine/Registry.php#L1555)</small>

`public function getDirectiveContainmentOwner(string $keyword): string|null`

The opener a containment-only directive must appear inside, or null.

Distinct from `getDirectiveOwner()`: a containment tag is not part of
the construct (it closes nothing), it is merely valid only while that owner
is open somewhere around it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `string`|`null`


---

### isDirectiveClose() · <small>[🗎](../../src/Engine/Registry.php#L1563)</small>

`public function isDirectiveClose(string $keyword): bool`

Whether `$keyword` is the close tag of its construct (vs a branch tag).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `bool`


---

### isDirectiveBranch() · <small>[🗎](../../src/Engine/Registry.php#L1573)</small>

`public function isDirectiveBranch(string $keyword): bool`

Whether `$keyword` is a branch tag of its construct.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `bool`


---

### hasDirective() · <small>[🗎](../../src/Engine/Registry.php#L1583)</small>

`public function hasDirective(string $keyword): bool`

Check whether a handler is registered for the given keyword.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**Return value**

- Type: `bool`


---

### compileDirective() · <small>[🗎](../../src/Engine/Registry.php#L1599)</small>

`public function compileDirective(string $keyword, string $rest, Clarity\Template\TemplateLocation $at, callable $processExpr): string`

Invoke the registered handler for $keyword and return compiled PHP.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | Directive keyword. |
| `$rest` | string | - | Raw text after the keyword inside {% … %}. |
| `$at` | [TemplateLocation](Clarity_Template_TemplateLocation.md) | - | Where the tag sits — for error messages, and<br>to throw with. |
| `$processExpr` | callable | - | fn(string $clarityExpr, bool $asList = false): mixed converter. |

**Return value**

- Type: `string`
- Description: Compiled PHP statement(s).

**Throws**

- [ClarityException](Clarity_ClarityException.md)  If the handler itself throws one.



---

[Back to the Index ⤴](README.md)
