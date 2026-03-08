# 🧩 Class: FunctionRegistry

**Full name:** [Clarity\Engine\FunctionRegistry](../../src/Engine/FunctionRegistry.php)

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
  Usage: `{{ numbers |> reduce(sum => sum + value, 0) }}`
  Note: Lambda receives accumulator as parameter, current element as implicit `value`

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

### __construct() · [source](../../src/Engine/FunctionRegistry.php#L116)

`public function __construct(callable|null $includeRenderer = null): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$includeRenderer` | callable\|null | `null` |  |

**➡️ Return value**

- Type: mixed


---

### addFilter() · [source](../../src/Engine/FunctionRegistry.php#L130)

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

### hasFilter() · [source](../../src/Engine/FunctionRegistry.php#L140)

`public function hasFilter(string $name): bool`

Check whether a named filter is registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### isCustomFilter() · [source](../../src/Engine/FunctionRegistry.php#L149)

`public function isCustomFilter(string $name): bool`

Returns true when $name was registered via addFilter() rather than a built-in.

Used by the compiler to decide whether to sandbox the return value.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### allFilters() · [source](../../src/Engine/FunctionRegistry.php#L159)

`public function allFilters(): array`

Get all registered filters as a name → callable map.

**➡️ Return value**

- Type: array


---

### addFunction() · [source](../../src/Engine/FunctionRegistry.php#L171)

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

### hasFunction() · [source](../../src/Engine/FunctionRegistry.php#L181)

`public function hasFunction(string $name): bool`

Check whether a named function is registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### isCustomFunction() · [source](../../src/Engine/FunctionRegistry.php#L190)

`public function isCustomFunction(string $name): bool`

Returns true when $name was registered via addFunction() rather than a built-in.

Used by the compiler to decide whether to sandbox the return value.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### allFunctions() · [source](../../src/Engine/FunctionRegistry.php#L200)

`public function allFunctions(): array`

Get all registered functions as a name → callable map.

**➡️ Return value**

- Type: array


---

### castToArray() · [source](../../src/Engine/FunctionRegistry.php#L479)

`public static function castToArray(mixed $value): mixed`

Recursively cast values to arrays so templates never receive live
objects and cannot call methods.

Precedence:
1. JsonSerializable → jsonSerialize() then recurse
2. Objects with toArray() → toArray() then recurse
3. Other objects → get_object_vars() then recurse
4. Arrays → recurse element by element
5. Scalars / null → pass through

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**➡️ Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
