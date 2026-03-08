# Filters and Functions Reference

Filters transform values in templates, while functions perform operations and return results. This guide covers all built-in filters and functions, lambda expressions, and creating custom filters.

## Filter Pipeline

Filters transform a value before output using the `|>` operator:

```html
{{ userName |> upper }} {{ price |> number(2) }} {{ createdAt |> date('d.m.Y
H:i') }}
```

### Chaining Filters

Chain multiple filters together—each filter receives the output of the previous one:

```html
{{ description |> trim |> upper }} {{ tags |> map(t => t.name) |> join(', ') }}
{{ price |> number(0) |> replace('0', 'FREE') }}
```

### Filter Syntax

```html
{{ value |> filterName }} {# No arguments #} {{ value |> filterName(arg1) }} {#
One argument #} {{ value |> filterName(arg1, arg2) }} {# Multiple arguments #}
```

## Built-in Filters

### String / Text Filters

#### trim

Remove leading and trailing whitespace:

```html
{{ " hello " |> trim }} {# Output: "hello" #}
```

#### upper

Convert to uppercase (Unicode-aware):

```html
{{ "hello world" |> upper }} {# Output: "HELLO WORLD" #}
```

#### lower

Convert to lowercase (Unicode-aware):

```html
{{ "HELLO WORLD" |> lower }} {# Output: "hello world" #}
```

#### capitalize

First character uppercase, rest lowercase:

```html
{{ "hELLO wORLD" |> capitalize }} {# Output: "Hello world" #}
```

#### title

Title-case every word:

```html
{{ "hello world" |> title }} {# Output: "Hello World" #}
```

#### escape (alias: esc)

HTML-escape the value (same as auto-escaping):

```html
{{ userInput |> escape }} {# Manually escape if needed #}
```

> **Note:** All output is auto-escaped by default. Use this filter explicitly only when needed.

#### nl2br

Convert newlines to `<br>` tags:

```html
{{ description |> nl2br |> raw }} {# Converts \n to <br />
- use raw to output HTML #}
```

#### replace(search, replace)

Replace all occurrences:

```html
{{ "Hello World" |> replace('World', 'Clarity') }} {# Output: "Hello Clarity" #}
{{ phoneNumber |> replace('-', '') }} {# Remove dashes #}
```

#### split(delimiter, limit?)

Split string into array:

```html
{{ "apple,banana,cherry" |> split(',') |> join(' - ') }} {# Output: "apple -
banana - cherry" #} {{ text |> split(' ', 3) }} {# Limit to 3 parts #}
```

#### join(glue)

Join array elements into string:

```html
{{ ['apple', 'banana', 'cherry'] |> join(', ') }} {# Output: "apple, banana,
cherry" #} {{ tags |> map(t => t.name) |> join(', ') }}
```

#### truncate(length, ellipsis?)

Truncate string to specified length:

```html
{{ longText |> truncate(100) }} {# Truncate to 100 chars, adds '…' #} {{
longText |> truncate(50, '...') }} {# Custom ellipsis #}
```

#### format(...args)

sprintf-style formatting:

```html
{{ "Hello, %s! You have %d messages." |> format(userName, messageCount) }}
```

### Number Filters

#### number(decimals)

Format number with decimal places:

```html
{{ 1234.5678 |> number(2) }} {# Output: "1,234.57" #} {{ price |> number(0) }}
{# No decimals: "1,235" #}
```

#### abs

Absolute value:

```html
{{ -42 |> abs }} {# Output: 42 #}
```

#### round(precision?)

Round to specified decimal places:

```html
{{ 3.14159 |> round(2) }} {# Output: 3.14 #} {{ 3.7 |> round }} {# Output: 4.0
(default precision: 0) #}
```

### Date Filters

#### date(format?)

Format timestamps or date strings:

```html
{{ timestamp |> date('Y-m-d') }} {# Output: "2026-03-08" #} {{ timestamp |>
date('d.m.Y H:i:s') }} {# Output: "08.03.2026 14:30:00" #} {{ "2026-01-15" |>
date('F j, Y') }} {# Output: "January 15, 2026" #}
```

Common format patterns:

- `Y-m-d` — 2026-03-08
- `d.m.Y` — 08.03.2026
- `F j, Y` — March 8, 2026
- `H:i:s` — 14:30:00
- `l, F j, Y` — Saturday, March 8, 2026

#### format_datetime(dateStyle?, timeStyle?, locale?, timezone?)

Format dates using IntlDateFormatter:

```html
{{ timestamp |> format_datetime('long', 'short') }} {# Output depends on locale:
"March 8, 2026 at 2:30 PM" #} {{ timestamp |> format_datetime('full', 'none',
'de_DE', 'Europe/Berlin') }}
```

Styles: `none`, `short`, `medium`, `long`, `full`

#### date_modify(modifier)

Apply date modification and return new timestamp:

```html
{{ timestamp |> date_modify('+1 day') |> date('Y-m-d') }} {{ timestamp |>
date_modify('-1 month') |> date('F Y') }} {{ timestamp |> date_modify('next
Monday') |> date('l, F j') }}
```

### Array Filters

#### first

Get first element (or first character of string):

```html
{{ [1, 2, 3] |> first }} {# Output: 1 #} {{ "hello" |> first }} {# Output: "h"
#}
```

#### last

Get last element (or last character of string):

```html
{{ [1, 2, 3] |> last }} {# Output: 3 #} {{ "hello" |> last }} {# Output: "o" #}
```

#### keys

Get array keys:

```html
{{ {name: 'John', age: 30} |> keys |> join(', ') }} {# Output: "name, age" #}
```

#### merge(otherArray)

Merge arrays:

```html
{{ [1, 2] |> merge([3, 4]) |> join(', ') }} {# Output: "1, 2, 3, 4" #}
```

#### sort

Sort array values:

```html
{{ [3, 1, 2] |> sort |> join(', ') }} {# Output: "1, 2, 3" #}
```

#### reverse

Reverse array or string:

```html
{{ [1, 2, 3] |> reverse |> join(', ') }} {# Output: "3, 2, 1" #} {{ "hello" |>
reverse }} {# Output: "olleh" (Unicode-aware) #}
```

#### shuffle

Randomly shuffle array:

```html
{{ items |> shuffle }} {# Returns shuffled copy #}
```

#### batch(size, fill?)

Split array into chunks:

```html
{% set items = [1, 2, 3, 4, 5] %} {% for chunk in items |> batch(2) %}
<div>{{ chunk |> join(', ') }}</div>
{% endfor %} {# Outputs: "1, 2" "3, 4" "5" #} {# With fill #} {{ [1, 2, 3] |>
batch(2, 0) }} {# [[1, 2], [3, 0]] #}
```

#### map(callable)

Transform each element (see [Lambda Expressions](#lambda-expressions)):

```html
{{ users |> map(u => u.name) |> join(', ') }} {# Extract names: "Alice, Bob,
Charlie" #} {{ numbers |> map(n => n * 2) |> join(', ') }} {# Double each: "2,
4, 6" #} {{ tags |> map("upper") |> join(', ') }} {# Using filter reference #}
```

#### filter(callable?)

Filter elements (see [Lambda Expressions](#lambda-expressions)):

```html
{{ users |> filter(u => u.isActive) |> map(u => u.name) |> join(', ') }} {# Only
active users #} {{ numbers |> filter(n => n > 10) |> join(', ') }} {# Numbers
greater than 10 #} {# Without callable: remove falsy values #} {{ [0, 1, false,
2, '', 3] |> filter |> join(', ') }} {# Output: "1, 2, 3" #}
```

#### reduce(callable, initial?)

Reduce array to single value (see [Lambda Expressions](#lambda-expressions)):

```html
{{ [1, 2, 3, 4] |> reduce(sum => sum + value, 0) }} {# Output: 10 #} {{ words |>
reduce(acc => acc ~ ' ' ~ value) }} {# Join with spaces #}
```

### General Purpose Filters

#### length

Count array elements or string length:

```html
{{ items.length }} {# Property access also works #} {{ items |> length }} {#
Filter form #} {{ "hello" |> length }} {# Output: 5 #}
```

#### slice(start, length?)

Extract portion of array or string:

```html
{{ [1, 2, 3, 4, 5] |> slice(1, 3) |> join(', ') }} {# Output: "2, 3, 4" #} {{
"Hello World" |> slice(0, 5) }} {# Output: "Hello" #} {{ items |> slice(0, 10)
}} {# First 10 items #}
```

#### default(fallback)

Return fallback if value is falsy:

```html
{{ userName |> default('Guest') }} {# Output: "Guest" if userName is
empty/false/null #} {{ count |> default(0) }}
```

#### json

Encode as JSON:

```html
{{ data |> json |> raw }} {# Output: {"name":"John","age":30} #} {{ [1, 2, 3] |>
json |> raw }} {# Output: [1,2,3] #}
```

> **Note:** Use `|> raw` to output JSON as-is (not HTML-escaped).

### Utility Filters

#### url_encode

URL-encode string:

```html
<a href="/search?q={{ query |> url_encode }}">Search</a>
```

#### data_uri(mimeType?)

Convert to base64 data URI:

```html
<img src="{{ imageData |> data_uri('image/png') }}" />
```

#### unicode(start?, length?)

Wrap in UnicodeString for Unicode operations:

```html
{{ text |> unicode |> reverse }} {# Unicode-aware string reverse #}
```

## Lambda Expressions

Lambdas allow inline transformation logic for `map`, `filter`, and `reduce` filters.

### Lambda Syntax

```
parameter => expression
```

### Map Examples

Extract a field from objects:

```html
{{ users |> map(user => user.name) |> join(', ') }}
```

Transform values:

```html
{{ numbers |> map(n => n * 2) |> join(', ') }}
```

Complex expressions:

```html
{{ products |> map(p => p.name ~ ' ($' ~ (p.price |> number(2)) ~ ')') |>
join(', ') }}
```

Access outer variables:

```html
{% set prefix = 'Item: ' %} {{ items |> map(item => prefix ~ item.name) |>
join(', ') }}
```

### Filter Examples

Filter with condition:

```html
{{ users |> filter(u => u.age >= 18) |> map(u => u.name) |> join(', ') }}
```

Multiple conditions:

```html
{{ products |> filter(p => p.inStock and p.price < 100) }}
```

### Reduce Examples

Sum numbers:

```html
{{ numbers |> reduce(sum => sum + value, 0) }}
```

> **Note:** In `reduce`, the lambda parameter is the accumulator (e.g., `sum`), and the current element is always available as `value`.

Build a string:

```html
{{ words |> reduce(result => result ~ ' ' ~ value, '') }}
```

Calculate total price:

```html
{{ cart.items |> reduce(total => total + (value.price * value.quantity), 0) |>
number(2) }}
```

### Filter References

Use registered filter names as callbacks:

```html
{{ tags |> map("upper") |> join(', ') }} {# Apply 'upper' filter to each tag #}
{{ names |> map("trim") |> join(', ') }} {# Trim each name #} {# Works with
custom filters too #} {{ prices |> map("currency") |> join(', ') }}
```

> **Security:** Only registered Clarity filters can be referenced. Arbitrary PHP function names are rejected at compile time.

## Built-in Functions

Functions are called directly in expressions.

### context()

Get all current template variables:

```html
{% set allVars = context() %} {{ allVars |> json |> raw }}
```

Useful for debugging or passing all context to an include:

```html
{{ include("partial", context()) }}
```

### include(template, context?)

Dynamically render another template at runtime:

```html
{{ include("partials/card", { title: "Hello", content: "World" }) }}
```

With dynamic template name:

```html
{% for widget in widgets %} {{ include("widgets/" ~ widget.type, widget.data) }}
{% endfor %}
```

Merge current context:

```html
{{ include("partials/user", { ...context(), showEmail: true }) }}
```

### json(...values)

Encode values as JSON:

```html
{{ json(user.name, user.age) |> raw }} {# Output: ["John",30] #} {{ json(data)
|> raw }} {# Encode single value #}
```

### dump(...values)

Debug output (print_r):

```html
<pre>{{ dump(user, settings) }}</pre>
{# Useful for debugging #}
```

### keys(array)

Get array keys:

```html
{{ keys(data) |> join(', ') }}
```

### values(array)

Get array values (re-indexed):

```html
{{ values(data) |> join(', ') }}
```

## Custom Filters

Register custom filters in your PHP code:

### Simple Filter

```php
$engine->addFilter('currency', function($value, string $symbol = '€') {
    return $symbol . ' ' . number_format($value, 2);
});
```

Use in template:

```html
{{ price |> currency }} {# Output: € 12.50 #} {{ price |> currency('$') }} {#
Output: $ 12.50 #}
```

### Filter with Multiple Arguments

```php
$engine->addFilter('excerpt', function($text, int $length = 100, string $ellipsis = '...') {
    return mb_strlen($text) > $length
        ? mb_substr($text, 0, $length) . $ellipsis
        : $text;
});
```

Use in template:

```html
{{ article.body |> excerpt(50) }} {{ article.body |> excerpt(150, '…') }}
```

### Chainable Filter

Filters can be chained, so design them to work with various inputs:

```php
$engine->addFilter('highlight', function($text, string $query) {
    return str_replace(
        $query,
        '<mark>' . $query . '</mark>',
        $text
    );
});
```

Use in template:

```html
{{ description |> highlight(searchTerm) |> raw }}
```

### Filter Accessing Template Context

Filters can access dependencies through closures:

```php
$config = ['dateFormat' => 'd.m.Y'];

$engine->addFilter('formatDate', function($timestamp) use ($config) {
    return date($config['dateFormat'], $timestamp);
});
```

## Custom Functions

Register custom functions for use in expressions:

### Simple Function

```php
$engine->addFunction('asset', function(string $path) {
    return '/assets/' . ltrim($path, '/');
});
```

Use in template:

```html
<img src="{{ asset('images/logo.png') }}" />
<link rel="stylesheet" href="{{ asset('css/style.css') }}" />
```

### Function with Dependencies

```php
$assetVersion = '1.2.3';

$engine->addFunction('versionedAsset', function(string $path) use ($assetVersion) {
    return '/assets/' . ltrim($path, '/') . '?v=' . $assetVersion;
});
```

### Function Returning Arrays

```php
$engine->addFunction('range', function(int $start, int $end, int $step = 1) {
    return range($start, $end, $step);
});
```

Use in template:

```html
{% for i in range(1, 10) %}
<li>Item {{ i }}</li>
{% endfor %}
```

## Best Practices

### When to Use Filters vs. Functions

- **Filters:** Transform a value (pipe input → output)
- **Functions:** Generate or compute a value (return result)

```html
<!-- Filter: Transform existing value -->
{{ userName |> upper }}

<!-- Function: Generate new value -->
{{ asset('logo.png') }}
```

### Keep Logic Simple

Templates should focus on presentation. Complex business logic belongs in PHP:

❌ **Bad:**

```html
{{ items |> filter(i => i.price > 100 and i.category == 'electronics' and
i.stock > 0) |> map(i => i.name ~ ' - $' ~ i.price) }}
```

✅ **Good:**

```php
// In PHP
$expensiveElectronics = array_filter($items, fn($i) =>
    $i->price > 100 && $i->category === 'electronics' && $i->stock > 0
);
$engine->render('products', ['products' => $expensiveElectronics]);
```

```html
<!-- In template -->
{% for product in products %}
<li>{{ product.name }} - {{ product.price |> currency }}</li>
{% endfor %}
```

### Security with Custom Filters

- Always validate and sanitize inputs
- Be careful returning raw HTML (users might inject untrusted data)
- Prefer auto-escaping; only use `|> raw` when absolutely necessary

### Naming Conventions

- Use lowercase_with_underscores: `format_date`, `url_encode`
- Make names descriptive: `excerpt` not `exc`, `currency` not `cur`
- Avoid conflicts with built-in filters

## Filter Reference Quick Table

| Filter             | Purpose                | Example                                    |
| ------------------ | ---------------------- | ------------------------------------------ |
| `trim`             | Remove whitespace      | `{{ text \|> trim }}`                      |
| `upper`            | Uppercase              | `{{ name \|> upper }}`                     |
| `lower`            | Lowercase              | `{{ email \|> lower }}`                    |
| `capitalize`       | Capitalize first char  | `{{ word \|> capitalize }}`                |
| `title`            | Title case             | `{{ heading \|> title }}`                  |
| `truncate(len)`    | Truncate string        | `{{ text \|> truncate(100) }}`             |
| `number(dec)`      | Format number          | `{{ price \|> number(2) }}`                |
| `date(fmt)`        | Format date            | `{{ time \|> date('Y-m-d') }}`             |
| `first`            | First element/char     | `{{ items \|> first }}`                    |
| `last`             | Last element/char      | `{{ items \|> last }}`                     |
| `join(glue)`       | Join array             | `{{ tags \|> join(', ') }}`                |
| `split(delim)`     | Split string           | `{{ csv \|> split(',') }}`                 |
| `slice(start,len)` | Extract portion        | `{{ items \|> slice(0, 10) }}`             |
| `map(fn)`          | Transform each element | `{{ items \|> map(i => i.name) }}`         |
| `filter(fn)`       | Filter elements        | `{{ items \|> filter(i => i.active) }}`    |
| `reduce(fn,init)`  | Reduce to single value | `{{ nums \|> reduce(s => s + value, 0) }}` |
| `reverse`          | Reverse array/string   | `{{ items \|> reverse }}`                  |
| `sort`             | Sort array             | `{{ items \|> sort }}`                     |
| `length`           | Count/length           | `{{ items \|> length }}`                   |
| `default(val)`     | Fallback value         | `{{ name \|> default('Guest') }}`          |
| `json`             | JSON encode            | `{{ data \|> json \|> raw }}`              |
| `escape/esc`       | HTML escape            | `{{ html \|> escape }}`                    |
| `raw`              | Disable auto-escaping  | `{{ html \|> raw }}`                       |

## Next Steps

- **[Layout Inheritance](03-layout-inheritance.md)** — Create reusable layouts
- **[Advanced Topics](04-advanced-topics.md)** — Namespaces, caching, error handling
- **[Examples](../examples/README.md)** — See filters in action
