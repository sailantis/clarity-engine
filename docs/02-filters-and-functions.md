# Filters and Functions Reference

Filters transform values in templates, while functions perform operations and return results. This guide covers all built-in filters and functions, lambda expressions, and creating custom filters.

## Filter Pipeline

Filters transform a value before output using the `|>` operator:

```twig
{{ userName |> upper }}
{{ price |> number(2) }}
{{ createdAt |> date('d.m.Y H:i') }}
```

### Chaining Filters

Chain multiple filters together—each filter receives the output of the previous one:

```twig
{{ description |> trim |> upper }}
{{ tags |> map(t => t.name) |> join(', ') }}
{{ price |> number(0) |> replace('0', 'FREE') }}
```

### Filter Syntax

```twig
{{ value |> filterName }} {# No arguments #}
{{ value |> filterName(arg1) }} {# One argument #}
{{ value |> filterName(arg1, arg2) }} {# Multiple arguments #}
```

## Built-in Filters

### String / Text Filters

#### trim

Remove leading and trailing whitespace:

```twig
{{ " hello " |> trim }} {# Output: "hello" #}
```

#### upper

Convert to uppercase (Unicode-aware):

```twig
{{ "hello world" |> upper }} {# Output: "HELLO WORLD" #}
```

#### lower

Convert to lowercase (Unicode-aware):

```twig
{{ "HELLO WORLD" |> lower }} {# Output: "hello world" #}
```

#### capitalize

First character uppercase, rest lowercase:

```twig
{{ "hELLO wORLD" |> capitalize }} {# Output: "Hello world" #}
```

#### title

Title-case every word:

```twig
{{ "hello world" |> title }} {# Output: "Hello World" #}
```

#### escape (alias: esc)

HTML-escape the value (same as auto-escaping):

```twig
{{ userInput |> escape }} {# Manually escape if needed #}
```

> **Note:** All output is auto-escaped by default. Use this filter explicitly only when needed.

#### nl2br

Convert newlines to `<br>` tags:

```twig
{{ description |> nl2br |> raw }} {# Converts \n to <br />
- use raw to output HTML #}
```

#### replace(search, replace)

Replace all occurrences:

```twig
{{ "Hello World" |> replace('World', 'Clarity') }} {# Output: "Hello Clarity" #}
{{ phoneNumber |> replace('-', '') }} {# Remove dashes #}
```

#### striptags(allowedTags?)

Strip HTML and PHP tags from a string. Optionally specify tags to keep:

```twig
{{ htmlContent |> striptags }}
{{ htmlContent |> striptags('<b><i><strong>') }} {# Keep some tags #}
```

#### slug(separator?)

Generate a URL-friendly slug from a string (lowercase, dashes, no special characters):

```twig
{{ article.title |> slug }}               {# "Hello World!" → "hello-world" #}
{{ name |> slug(separator:'_') }}          {# "Hello World" → "hello_world" #}
```

Transliterates Unicode characters to ASCII when `iconv` or `intl` is available.

#### split(delimiter, limit?)

Split string into array:

```twig
{{ "apple,banana,cherry" |> split(',') |> join(' - ') }} {# Output: "apple - banana - cherry" #}
{{ text |> split(' ', 3) }} {# Limit to 3 parts #}
```

#### join(glue)

Join array elements into string:

```twig
{{ ['apple', 'banana', 'cherry'] |> join(', ') }} {# Output: "apple, banana, cherry" #}
{{ tags |> map(t => t.name) |> join(', ') }}
```

#### truncate(length, ellipsis?)

Truncate string to specified length:

```twig
{{ longText |> truncate(100) }} {# Truncate to 100 chars, adds '…' #}
{{ longText |> truncate(50, '...') }} {# Custom ellipsis #}
```

#### sprintf(...args)

`sprintf`-style string formatting:

```twig
{{ "Hello, %s! You have %d messages." |> sprintf(userName, messageCount) }}
{{ "Price: %.2f" |> sprintf(price) }}
```

### Number Filters

#### number(decimals)

Format number with decimal places:

```twig
{{ 1234.5678 |> number(2) }} {# Output: "1,234.57" #}
{{ price |> number(0) }} {# No decimals: "1,235" #}
```

#### abs

Absolute value:

```twig
{{ -42 |> abs }} {# Output: 42 #}
```

#### round(precision?)

Round to specified decimal places:

```twig
{{ 3.14159 |> round(2) }} {# Output: 3.14 #}
{{ 3.7 |> round }} {# Output: 4.0 (default precision: 0) #}
```

### Date Filters

#### date(format?)

Format timestamps or date strings:

```twig
{{ timestamp |> date('Y-m-d') }} {# Output: "2026-03-08" #}
{{ timestamp |> date('d.m.Y H:i:s') }} {# Output: "08.03.2026 14:30:00" #}
{{ "2026-01-15" |> date('F j, Y') }} {# Output: "January 15, 2026" #}
```

Common format patterns:

- `Y-m-d` — 2026-03-08
- `d.m.Y` — 08.03.2026
- `F j, Y` — March 8, 2026
- `H:i:s` — 14:30:00
- `l, F j, Y` — Saturday, March 8, 2026

#### format_datetime(dateStyle?, timeStyle?, locale?, timezone?)

Format dates using `IntlDateFormatter` (requires the PHP `intl` extension):

```twig
{{ timestamp |> format_datetime('long', 'short') }}
{# Output (en_US): "March 8, 2026 at 2:30 PM" #}
{{ timestamp |> format_datetime('full', 'none', 'de_DE', 'Europe/Berlin') }}
```

Styles: `none`, `short`, `medium`, `long`, `full`

> **Requires:** PHP `intl` extension. For more extensive locale-aware formatting (numbers, currencies, relative time, etc.) see the `IntlFormatModule` in [Advanced Topics](04-advanced-topics.md#modules).

#### date_modify(modifier)

Apply date modification and return new timestamp:

```twig
{{ timestamp |> date_modify('+1 day') |> date('Y-m-d') }}
{{ timestamp |> date_modify('-1 month') |> date('F Y') }}
{{ timestamp |> date_modify('next Monday') |> date('l, F j') }}
```

### Array Filters

#### first

Get first element (or first character of string):

```twig
{{ [1, 2, 3] |> first }} {# Output: 1 #}
{{ "hello" |> first }} {# Output: "h" #}
```

#### last

Get last element (or last character of string):

```twig
{{ [1, 2, 3] |> last }} {# Output: 3 #}
{{ "hello" |> last }} {# Output: "o" #}
```

#### keys

Get array keys:

```twig
{{ {name: 'John', age: 30} |> keys |> join(', ') }} {# Output: "name, age" #}
```

#### merge(otherArray)

Merge arrays:

```twig
{{ [1, 2] |> merge([3, 4]) |> join(', ') }} {# Output: "1, 2, 3, 4" #}
```

#### sort

Sort array values:

```twig
{{ [3, 1, 2] |> sort |> join(', ') }} {# Output: "1, 2, 3" #}
```

#### reverse

Reverse array or string:

```twig
{{ [1, 2, 3] |> reverse |> join(', ') }} {# Output: "3, 2, 1" #}
{{ "hello" |> reverse }} {# Output: "olleh" (Unicode-aware) #}
```

#### shuffle

Randomly shuffle array:

```twig
{{ items |> shuffle }} {# Returns shuffled copy #}
```

#### batch(size, fill?)

Split array into chunks:

```twig
{% set items = [1, 2, 3, 4, 5] %}
{% for chunk in items |> batch(2) %}
<div>{{ chunk |> join(', ') }}</div>
{% endfor %}
{# Outputs: "1, 2" "3, 4" "5" #}
{# With fill #}
{{ [1, 2, 3] |> batch(2, 0) }} {# [[1, 2], [3, 0]] #}
```

#### map(callable)

Transform each element (see [Lambda Expressions](#lambda-expressions)):

```twig
{{ users |> map(u => u.name) |> join(', ') }} {# Extract names: "Alice, Bob, Charlie" #}
{{ numbers |> map(n => n * 2) |> join(', ') }} {# Double each: "2, 4, 6" #}
{{ tags |> map("upper") |> join(', ') }} {# Using filter reference #}
```

#### filter(callable?)

Filter elements (see [Lambda Expressions](#lambda-expressions)):

```twig
{{ users |> filter(u => u.isActive) |> map(u => u.name) |> join(', ') }} {# Only active users #}
{{ numbers |> filter(n => n > 10) |> join(', ') }} {# Numbers greater than 10 #}
{# Without callable: remove falsy values #}
{{ [0, 1, false, 2, '', 3] |> filter |> join(', ') }} {# Output: "1, 2, 3" #}
```

#### reduce(callable, initial?)

Reduce array to single value (see [Lambda Expressions](#lambda-expressions)):

```twig
{{ [1, 2, 3, 4] |> reduce(sum, value => sum + value, 0) }} {# Output: 10 #}
{{ words |> reduce(acc, word => acc ~ ' ' ~ word) }} {# Join with spaces #}
```

### General Purpose Filters

#### length

Count array elements or string length:

```twig
{{ items.length }} {# Property access also works #}
{{ items |> length }} {# Filter form #} {{ "hello" |> length }} {# Output: 5 #}
```

#### slice(start, length?)

Extract portion of array or string:

```twig
{{ [1, 2, 3, 4, 5] |> slice(1, 3) |> join(', ') }} {# Output: "2, 3, 4" #}
{{ "Hello World" |> slice(0, 5) }} {# Output: "Hello" #}
{{ items |> slice(0, 10) }} {# First 10 items #}
```

#### default(fallback)

Return fallback if value is `null` or not set (uses `??`):

```twig
{{ userName |> default('Guest') }} {# Returns 'Guest' if userName is null/unset #}
{{ count |> default(0) }}
```

#### empty(fallback)

Return fallback if value is empty or falsy (uses `?:`):

```twig
{{ userName |> empty('Anonymous') }} {# Returns 'Anonymous' if userName is "", 0, null, false #}
```

> **Note:** `default` uses `??` (null coalescing) and only triggers for `null` or missing keys. `empty` uses `?:` and triggers for any falsy value including empty strings and zero.

#### json

Encode as JSON:

```twig
{{ data |> json |> raw }} {# Output: {"name":"John","age":30} #}
{{ [1, 2, 3] |> json |> raw }} {# Output: [1,2,3] #}
```

> **Note:** Use `|> raw` to output JSON as-is (not HTML-escaped).

### Utility Filters

#### url_encode

URL-encode string:

```twig
<a href="/search?q={{ query |> url_encode }}">Search</a>
```

#### data_uri(mimeType?)

Convert to base64 data URI:

```twig
<img src="{{ imageData |> data_uri('image/png') }}" />
```

#### unicode(start?, length?)

Wrap in UnicodeString for Unicode operations:

```twig
{{ text |> unicode |> reverse }} {# Unicode-aware string reverse #}
```

## Lambda Expressions

Lambdas allow inline transformation logic for `map`, `filter`, and `reduce` filters.

### Lambda Syntax

```
parameter => expression
```

For `reduce`, declare both the accumulator and current item:

```
accumulator, item => expression
```

### Map Examples

Extract a field from objects:

```twig
{{ users |> map(user => user.name) |> join(', ') }}
```

Transform values:

```twig
{{ numbers |> map(n => n * 2) |> join(', ') }}
```

Complex expressions:

```twig
{{ products |> map(p => p.name ~ ' ($' ~ (p.price |> number(2)) ~ ')') |> join(', ') }}
```

Access outer variables:

```twig
{% set prefix = 'Item: ' %} {{ items |> map(item => prefix ~ item.name) |> join(', ') }}
```

### Filter Examples

Filter with condition:

```twig
{{ users |> filter(u => u.age >= 18) |> map(u => u.name) |> join(', ') }}
```

Multiple conditions:

```twig
{{ products |> filter(p => p.inStock and p.price < 100) }}
```

### Reduce Examples

Sum numbers:

```twig
{{ numbers |> reduce(sum, value => sum + value, 0) }}
```

> **Note:** In `reduce`, the lambda must declare both the accumulator (e.g., `sum`) and the current element (e.g., `value`).

Build a string:

```twig
{{ words |> reduce(result, word => result ~ ' ' ~ word, '') }}
```

Calculate total price:

```twig
{{ cart.items |> reduce(total, item => total + (item.price * item.quantity), 0) |> number(2) }}
```

### Filter References

Use registered filter names as callbacks:

```twig
{{ tags |> map("upper") |> join(', ') }} {# Apply 'upper' filter to each tag #}
{{ names |> map("trim") |> join(', ') }} {# Trim each name #}
{# Works with custom filters too #}
{{ prices |> map("currency") |> join(', ') }}
```

> **Security:** Only registered Clarity filters can be referenced. Arbitrary PHP function names are rejected at compile time.

## Built-in Functions

Functions are called directly in expressions.

### context()

Get all current template variables:

```twig
{% set allVars = context() %}
{{ allVars |> json |> raw }}
```

Useful for debugging or passing all context to an include:

```twig
{{ include("partial", context()) }}
```

### include(template, context?)

Dynamically render another template at runtime:

```twig
{{ include("partials/card", { title: "Hello", content: "World" }) }}
```

With dynamic template name:

```twig
{% for widget in widgets %}
    {{ include("widgets/" ~ widget.type, widget.data) }}
{% endfor %}
```

Merge current context:

```twig
{{ include("partials/user", { ...context(), showEmail: true }) }}
```

### json(...values)

Encode values as JSON:

```twig
{{ json(user.name, user.age) |> raw }} {# Output: ["John",30] #}
{{ json(data) |> raw }} {# Encode single value #}
```

### dump(...values)

Debug output (print_r):

```twig
<pre>{{ dump(user, settings) }}</pre>
{# Useful for debugging #}
```

### keys(array)

Get array keys:

```twig
{{ keys(data) |> join(', ') }}
```

### values(array)

Get array values (re-indexed):

```twig
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

```twig
{{ price |> currency }} {# Output: € 12.50 #}
{{ price |> currency('$') }} {# Output: $ 12.50 #}
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

```twig
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

```twig
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

```twig
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

```twig
{% for i in range(1, 10) %}
<li>Item {{ i }}</li>
{% endfor %}
```

## Best Practices

### When to Use Filters vs. Functions

- **Filters:** Transform a value (pipe input → output)
- **Functions:** Generate or compute a value (return result)

```twig
<!-- Filter: Transform existing value -->
{{ userName |> upper }}

<!-- Function: Generate new value -->
{{ asset('logo.png') }}
```

### Keep Logic Simple

Templates should focus on presentation. Complex business logic belongs in PHP:

❌ **Bad:**

```twig
{{ items |> filter(i => i.price > 100 and i.category == 'electronics' and i.stock > 0) |> map(i => i.name ~ ' - $' ~ i.price) }}
```

✅ **Good:**

```php
// In PHP
$expensiveElectronics = array_filter($items, fn($i) =>
    $i->price > 100 && $i->category === 'electronics' && $i->stock > 0
);
$engine->render('products', ['products' => $expensiveElectronics]);
```

```twig
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

## Named Arguments

Clarity filters accept named arguments using the `param:value` syntax. This is especially useful for filters with multiple optional parameters:

```twig
{{ text |> truncate(length:50) }}
{{ "Hello World" |> slug(separator:"_") }}
{{ n |> round(precision:2) }}
```

Named arguments can be combined with positional ones:

```twig
{{ text |> truncate(100, ellipsis:"...") }}
```

> **Note:** Named arguments use `:` in Clarity syntax and are emitted as PHP 8 named arguments. PHP validates parameter names and arity at runtime. Positional arguments must come before named ones.

## Filter Reference Quick Table

| Filter             | Purpose                | Example                                                  |
| ------------------ | ---------------------- | -------------------------------------------------------- |
| `trim`             | Remove whitespace      | `{{ text \|> trim }}`                                    |
| `upper`            | Uppercase              | `{{ name \|> upper }}`                                   |
| `lower`            | Lowercase              | `{{ email \|> lower }}`                                  |
| `capitalize`       | Capitalize first char  | `{{ word \|> capitalize }}`                              |
| `title`            | Title case             | `{{ heading \|> title }}`                                |
| `nl2br`            | Newlines to `<br>`     | `{{ text \|> nl2br \|> raw }}`                           |
| `replace(s,r)`     | Replace occurrences    | `{{ text \|> replace('a','b') }}`                        |
| `striptags`        | Strip HTML tags        | `{{ html \|> striptags }}`                               |
| `slug`             | URL-friendly slug      | `{{ title \|> slug }}`                                   |
| `truncate(len)`    | Truncate string        | `{{ text \|> truncate(100) }}`                           |
| `sprintf(...args)` | sprintf formatting     | `{{ "%s: %d" \|> sprintf(name, n) }}`                    |
| `number(dec)`      | Format number          | `{{ price \|> number(2) }}`                              |
| `abs`              | Absolute value         | `{{ n \|> abs }}`                                        |
| `round(prec)`      | Round number           | `{{ n \|> round(2) }}`                                   |
| `ceil` / `floor`   | Ceil/floor             | `{{ n \|> ceil }}`                                       |
| `date(fmt)`        | Format date            | `{{ time \|> date('Y-m-d') }}`                           |
| `date_modify(mod)` | Modify date            | `{{ time \|> date_modify('+1 day') \|> date('Y-m-d') }}` |
| `format_datetime`  | Locale-aware datetime  | `{{ time \|> format_datetime('long','short') }}`         |
| `first`            | First element/char     | `{{ items \|> first }}`                                  |
| `last`             | Last element/char      | `{{ items \|> last }}`                                   |
| `keys`             | Array keys             | `{{ obj \|> keys \|> join(', ') }}`                      |
| `values`           | Array values           | `{{ obj \|> values }}`                                   |
| `join(glue)`       | Join array             | `{{ tags \|> join(', ') }}`                              |
| `split(delim)`     | Split string           | `{{ csv \|> split(',') }}`                               |
| `slice(start,len)` | Extract portion        | `{{ items \|> slice(0, 10) }}`                           |
| `merge(other)`     | Merge arrays           | `{{ a \|> merge(b) }}`                                   |
| `sort`             | Sort array             | `{{ items \|> sort }}`                                   |
| `reverse`          | Reverse array/string   | `{{ items \|> reverse }}`                                |
| `shuffle`          | Shuffle array          | `{{ items \|> shuffle }}`                                |
| `batch(size)`      | Split into chunks      | `{{ items \|> batch(3) }}`                               |
| `map(fn)`          | Transform each element | `{{ items \|> map(i => i.name) }}`                       |
| `filter(fn)`       | Filter elements        | `{{ items \|> filter(i => i.active) }}`                  |
| `reduce(fn,init)`  | Reduce to single value | `{{ nums \|> reduce(s, v => s + v, 0) }}`                |
| `length`           | Count/length           | `{{ items \|> length }}`                                 |
| `default(val)`     | Fallback for null      | `{{ name \|> default('Guest') }}`                        |
| `empty(val)`       | Fallback for falsy     | `{{ name \|> empty('Anon') }}`                           |
| `json`             | JSON encode            | `{{ data \|> json \|> raw }}`                            |
| `url_encode`       | URL-encode             | `{{ q \|> url_encode }}`                                 |
| `data_uri(mime)`   | Base64 data URI        | `{{ img \|> data_uri('image/png') }}`                    |
| `unicode`          | Unicode string ops     | `{{ text \|> unicode \|> reverse }}`                     |
| `escape` / `esc`   | HTML escape            | `{{ html \|> escape }}`                                  |
| `raw`              | Disable auto-escaping  | `{{ html \|> raw }}`                                     |

## Next Steps

- **[Layout Inheritance](03-layout-inheritance.md)** — Create reusable layouts
- **[Advanced Topics](04-advanced-topics.md)** — Namespaces, caching, error handling
- **[Examples](examples/README.md)** — See filters in action
