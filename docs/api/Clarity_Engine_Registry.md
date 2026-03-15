# 🧩 Class: Registry

**Full name:** [Clarity\Engine\Registry](../../src/Engine/Registry.php)

Registry of filter and function callables for the Clarity template engine.

This class maintains the collection of built-in and user-defined filters and functions
available to templates. Filters transform values through the pipe operator (|>), while
functions are called directly in expressions.

User code may add additional filters via `addFilter()` and functions via `addFunction()`.

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
- `format(...$args)`          : sprintf-style string formatting
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
- `date_modify($modifier)`    : Apply date modifier (e.g. '+1 day'), return Unix timestamp

**Arrays & Collections**
- `first`                     : Get first element (works on arrays and strings)
- `last`                      : Get last element (works on arrays and strings)
- `keys`                      : Get array keys
- `values`                    : Get array values
- `length`                    : Count elements (arrays) or string length (mb_strlen)
- `slice($start [, $length])` : Extract portion (array_slice / mb_substr)
- `merge($other)`             : Merge arrays (array_merge)
- `sort`                      : Return sorted copy
- `reverse`                   : Reverse array or string (Unicode-aware)
- `shuffle`                   : Return shuffled copy
- `batch($size [, $fill])`    : Split into chunks, optionally padded

**Collection Operations (Lambda Support)**
- `map(lambda|filterRef)`     : Transform each element
  Usage: `{{ users |> map(u => u.name) }}` or `{{ items |> map("upper") }}`
- `filter [lambda|filterRef]` : Keep elements matching condition (returns re-indexed array)
  Usage: `{{ items |> filter(i => i.active) }}`
- `reduce(lambda|filterRef [, $initial])`: Reduce to single value
  Usage: `{{ numbers |> reduce(sum, value => sum + value, 0) }}`
  Note: Lambda receives explicit accumulator and current-element parameters

**Utility Filters**
- `json`                      : JSON encode (use with |> raw)
- `default($fallback)`        : Return fallback if value is empty/falsy
- `url_encode`                : URL-encode value (rawurlencode)
- `data_uri [$mimeType]`      : Generate base64-encoded data: URI
- `unicode`                   : Wrap in UnicodeString for advanced operations

Built-in Functions
------------------
- `context()`: Returns current template variables array
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

## 🚀 Public methods

### setDumpHandler() · [source](../../src/Engine/Registry.php#L333)

`public function setDumpHandler(Closure $fn): void`

Install context-aware dump/dd handlers produced by enableDebug().

Called internally — not part of the public engine API.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fn` | Closure | - |  |

**➡️ Return value**

- Type: void


---

### setDdHandler() · [source](../../src/Engine/Registry.php#L338)

`public function setDdHandler(Closure $fn): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fn` | Closure | - |  |

**➡️ Return value**

- Type: void


---

### __construct() · [source](../../src/Engine/Registry.php#L343)

`public function __construct(callable|null $includeRenderer = null): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$includeRenderer` | callable\|null | `null` |  |

**➡️ Return value**

- Type: mixed


---

### addFilter() · [source](../../src/Engine/Registry.php#L357)

`public function addFilter(string $name, callable $fn): static`

Register a user-defined filter.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable receiving ($value, ...$args). |

**➡️ Return value**

- Type: static


---

### hasFilter() · [source](../../src/Engine/Registry.php#L366)

`public function hasFilter(string $name): bool`

Check whether a named filter is registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### addInlineFilter() · [source](../../src/Engine/Registry.php#L385)

`public function addInlineFilter(string $name, array $definition): void`

Register an additional inline filter that is compiled directly into the
generated PHP (zero runtime call overhead).

The definition must follow the same structure as the built-in entries:
'php'      – PHP expression template with {1} for the piped value and
             {2}, {3}, … for each additional parameter.
'params'   – (optional) ordered list of parameter names.
'defaults' – (optional) map of paramName → PHP default expression.
'variadic' – (optional) true for variadic filters like 'format'.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates. |
| `$definition` | array | - |  |

**➡️ Return value**

- Type: void


---

### hasInlineFilter() · [source](../../src/Engine/Registry.php#L394)

`public function hasInlineFilter(string $name): bool`

Check whether a named inline filter is registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### getInlineFilter() · [source](../../src/Engine/Registry.php#L402)

`public function getInlineFilter(string $name): array|null`

Get the definition of a named inline filter.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: array|null


---

### registerInlineFilter() · [source](../../src/Engine/Registry.php#L413)

`public function registerInlineFilter(string $name): void`

Mark a filter name as a known inline filter (compiled to a PHP expression;
no callable is stored or invoked at runtime).  Modules that register
inline filters via Tokenizer::addInlineFilter() call this so that
hasFilter() returns true for the new name.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: void


---

### addService() · [source](../../src/Engine/Registry.php#L428)

`public function addService(string $name, mixed $service): static`

Store a non-callable service object under a named key so that compiled
template render bodies can access it via `$this->__fl['key']->method()`.

The key is conventionally prefixed with `__` to avoid collisions with
real filter names (e.g. `__locale`, `__translator`).

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Key under which the service is accessible in templates. |
| `$service` | mixed | - | Any value; not required to be callable. |

**➡️ Return value**

- Type: static


---

### hasService() · [source](../../src/Engine/Registry.php#L437)

`public function hasService(string $name): bool`

Check whether a named service is registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### getService() · [source](../../src/Engine/Registry.php#L447)

`public function getService(string $name): mixed`

Retrieve a named service.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: mixed

**⚠️ Throws**

- RuntimeException  if the service is not registered.


---

### allServices() · [source](../../src/Engine/Registry.php#L463)

`public function allServices(): array`

Get all registered filters as a name → callable/value map.

The returned array includes callable filters, inline-filter markers
(value `true`), and services registered via `addService()`.

**➡️ Return value**

- Type: array


---

### allFilters() · [source](../../src/Engine/Registry.php#L476)

`public function allFilters(): array`

Get all registered filters as a name → callable/value map.

The returned array includes callable filters, inline-filter markers
(value `true`), and services registered via `addService()`.

**➡️ Return value**

- Type: array


---

### addFunction() · [source](../../src/Engine/Registry.php#L488)

`public function addFunction(string $name, callable $fn): static`

Register a user-defined function.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'greet'). |
| `$fn` | callable | - | Callable receiving any positional arguments. |

**➡️ Return value**

- Type: static


---

### hasFunction() · [source](../../src/Engine/Registry.php#L497)

`public function hasFunction(string $name): bool`

Check whether a named function is registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### allFunctions() · [source](../../src/Engine/Registry.php#L507)

`public function allFunctions(): array`

Get all registered functions as a name → callable map.

**➡️ Return value**

- Type: array


---

### addBlock() · [source](../../src/Engine/Registry.php#L713)

`public function addBlock(string $keyword, callable $handler): static`

Registry of custom block / directive handlers for the Clarity compiler.

Modules register pairs of block keywords (e.g. `with_locale` / `endwith_locale`) whose compilation is delegated to user-supplied callables instead of being handled by the built-in match table in [`Compiler::compileBlock()`](Clarity_Engine_Compiler.md#compileblock).

Handler signature
-----------------
```php
function(
    string   $rest,        // everything after the keyword in the {% … %} tag
    string   $sourcePath,  // absolute path being compiled (for error messages)
    int      $tplLine,     // template line number (for error messages)
    callable $processExpr  // fn(string $clarityExpr): string — converts a Clarity expression to a PHP expression string
): string                  // compiled PHP statement(s) for this directive
```

Example registration (inside a Module::register() call):
```php
$engine->addBlock('with_locale', function(string $rest, string $path, int $line, callable $expr): string {
    $param = $expr(trim($rest));
    return "\$this->__fl['__locale']->push({$param});";
});
$engine->addBlock('endwith_locale', fn(...) => "\$this->__fl['__locale']->pop();");
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | Directive keyword (lowercase, e.g. 'with_locale'). |
| `$handler` | callable | - | See class docblock for expected signature. |

**➡️ Return value**

- Type: static


---

### hasBlock() · [source](../../src/Engine/Registry.php#L722)

`public function hasBlock(string $keyword): bool`

Check whether a handler is registered for the given keyword.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - |  |

**➡️ Return value**

- Type: bool


---

### compileBlock() · [source](../../src/Engine/Registry.php#L738)

`public function compileBlock(string $keyword, string $rest, string $sourcePath, int $tplLine, callable $processExpr): string`

Invoke the registered handler for $keyword and return compiled PHP.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | Directive keyword. |
| `$rest` | string | - | Raw text after the keyword inside {% … %}. |
| `$sourcePath` | string | - | Source file path (for error messages). |
| `$tplLine` | int | - | Template line number (for error messages). |
| `$processExpr` | callable | - | fn(string $clarityExpr): string converter. |

**➡️ Return value**

- Type: string
- Description: Compiled PHP statement(s).

**⚠️ Throws**

- [ClarityException](Clarity_ClarityException.md)  If the handler itself throws one.



---

[Back to the Index ⤴](README.md)
