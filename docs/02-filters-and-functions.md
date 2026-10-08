# Filters and Functions Reference

Filters transform values; functions perform operations and return results. This
guide covers built-ins, lambdas and custom filters.

## One call model, two signatures

Registered filters can be used as a **filter** (piped) _or_ as a **function**
(called). Which signature applies is chosen by **syntax**. Functions registered
with `addFunction()` are call-only and cannot be used as filters:

```twig
{{ items |> join(', ') }}    {# filter form — the piped value is the subject #}
{{ join(', ', items) }}      {# function form — arguments in call order        #}
```

For most names, the piped value becomes the first argument:

```twig
{{ name |> trim }}            ==  {{ trim(name) }}
{{ price |> number(2) }}      ==  {{ number(price, 2) }}
{{ 3.14159 |> round(2) }}     ==  {{ round(3.14159, 2) }}
```

Two names have a **different argument order** in the two forms, because their
function form mirrors the underlying **PHP builtin** rather than the pipe:

| Name   | Filter form (value piped) | Function form (call order) | Mirrors                |
| ------ | ------------------------- | -------------------------- | ---------------------- |
| `date` | `ts \|> date('Y-m-d')`    | `date('Y-m-d', ts)`        | `date($format, $ts)`   |
| `join` | `items \|> join(', ')`    | `join(', ', items)`        | `implode($glue, $arr)` |

For these two, the value is the second argument, as in the corresponding PHP
function. For example, use `join(', ', items)`; `join(items)` has no array to join.

### Shadowing

A registered name **wins over a same-named PHP builtin in both modes**. With PHP access enabled, `{{ trim(x) }}` compiles to Clarity's `trim` filter, not to `\trim()`. Unregistered names reach PHP directly, if enabled (`{{ substr(s, 1, 3) }}`).

The notable case is `sort`/`shuffle`: PHP's `\sort` sorts in place and returns a
bool, neither of which is usable in a template. Clarity's `sort` returns the
sorted **copy**, for both syntaxes:

```twig
{% set sorted = items |> sort %}
{{ sort(items) | join(', ') }}
```

## Filter Pipeline

Filters transform a value before output. Both `|` and `|>` work as the filter pipe — they are interchangeable:

```twig
{{ userName | upper }}        {# Twig / Svelte style #}
{{ userName |> upper }}       {# PHP / Clarity fat-pipe style #}
{{ price | number(2) }}
{{ createdAt |> date('d.m.Y H:i') }}
```

> **`|` vs `||` vs `bor`**
>
> - `|` — always a filter pipe (even a single `|`)
> - `||` — logical OR (two pipes are never a filter pipe)
> - `bor` — bitwise OR keyword (use this when you need the bitwise `|` operator)

### Chaining Filters

Chain multiple filters together—each filter receives the output of the previous one:

```twig
{{ description | trim | upper }}
{{ tags | map(t => t:name) | join(', ') }}
{{ price | number(0) | replace('0', 'FREE') }}
```

### Filter Syntax

```twig
{{ value | filterName }}              {# No arguments #}
{{ value | filterName(arg1) }}        {# One argument #}
{{ value | filterName(arg1, arg2) }}  {# Multiple arguments #}
```

### Call Syntax

Any filterable name may also be called with parentheses — the piped value
becomes the first argument (or its declared `valueParam`, see above):

```twig
{{ trim(value) }}                       {# == value |> trim #}
{{ number(value, 2) }}                  {# == value |> number(2) #}
{{ replace(value, 'a', 'b') }}          {# == value |> replace('a', 'b') #}
{{ map(items, x => x:name) }}           {# == items |> map(x => x:name) #}
{{ date('Y-m-d', timestamp) }}          {# date form takes the format first #}
{{ join(', ', items) }}                 {# join form takes the glue first #}
```

Named arguments work in both forms:

```twig
{{ value |> number(decimals: 1) }}
{{ number(value, decimals: 1) }}
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

#### escape (alias: esc, e)

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
{{ article:title |> slug }}               {# "Hello World!" → "hello-world" #}
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
{{ tags |> map(t => t:name) |> join(', ') }}
```

As a function the glue comes first (mirroring PHP `implode`):

```twig
{{ join(', ', tags) }}        {# == tags |> join(', ') #}
{{ join(', ', map(tags, t => t:name)) }}
```

#### truncate(length, ellipsis?)

Truncate string to specified length:

```twig
{{ longText |> truncate(100) }} {# Truncate to 100 chars, adds '…' #}
{{ longText |> truncate(50, '...') }} {# Custom ellipsis #}
```

#### sprintf(...args)

`sprintf`-style string formatting. The value being formatted leads the
arguments (it is the format string):

```twig
{{ "Hello, %s! You have %d messages." |> sprintf(userName, messageCount) }}
{{ "Price: %.2f" |> sprintf(price) }}
```

**`format` is an alias.** Twig spells this filter `format` and also takes the
value first, so the two names are interchangeable:

```twig
{{ "Hello, %s!" |> format(name) }}     {# Twig spelling #}
{{ "Hello, %s!" |> sprintf(name) }}    {# PHP spelling  #}
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

As a function the format comes first (mirroring PHP `date`):

```twig
{{ date('Y-m-d', timestamp) }}   {# == timestamp |> date('Y-m-d') #}
{{ date('Y-m-d') }}              {# no value → defaults to now #}
```

Common format patterns:

- `Y-m-d` — 2026-03-08
- `d.m.Y` — 08.03.2026
- `F j, Y` — March 8, 2026
- `H:i:s` — 14:30:00
- `l, F j, Y` — Saturday, March 8, 2026

#### format_datetime(dateStyle?, timeStyle?, locale?, timezone?)

Format dates using `IntlDateFormatter`:

```twig
{{ timestamp |> format_datetime('long', 'short') }}
{# Output (en_US): "March 8, 2026 at 2:30 PM" #}
{{ timestamp |> format_datetime('full', 'none', 'de_DE', 'Europe/Berlin') }}
```

Styles: `none`, `short`, `medium`, `long`, `full`

> **Requires:** PHP `intl` extension. For more extensive locale-aware formatting (numbers, currencies, relative time, etc.) see the `IntlFormatModule` in [Advanced Topics](04-advanced-topics.md#modules).

#### date_modify(modifier, format='c')

Apply a date modification and return the result formatted with `format`. The
default is ISO 8601; pass a format to get a different shape directly.

```twig
{{ timestamp |> date_modify('+1 day', 'Y-m-d') }}
{{ timestamp |> date_modify('-1 month', 'F Y') }}
{{ timestamp |> date_modify('next Monday', 'l, F j') }}
```

The default is a full ISO 8601 string, and `date` parses it, so a chain still
works — but the two-argument form above is the shorter way to the same result:

```twig
{{ timestamp |> date_modify('+1 day') |> date('Y-m-d') }}
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
{# Extract names: "Alice, Bob, Charlie" #}
{{ users |> map(u => u:name) |> join(', ') }}
{# Double each: "2, 4, 6" #}
{{ numbers |> map(n => n * 2) |> join(', ') }}
{# Using filter reference #}
{{ tags |> map("upper") |> join(', ') }}
```

**Keys are preserved.** Mapping over an associative array keeps the original
keys; it does not flatten the array into a list.

```twig
{# {"a":"X","b":"Y"} #}
{{ {a: 'x', b: 'y'} |> map("upper") |> json }}
```

#### filter(callable?)

Filter elements (see [Lambda Expressions](#lambda-expressions)):

```twig
{# Only active users #}
{{ users |> filter(u => u:isActive) |> map(u => u:name) |> join(', ') }}
{# Numbers greater than 10 #}
{{ numbers |> filter(n => n > 10) |> join(', ') }}
{# Without callable: remove falsy values #}
{{ [0, 1, false, 2, '', 3] |> filter |> join(', ') }}
{# Output: "1, 2, 3" #}
```

**Keys are preserved, and the array is NOT re-indexed.** Values that fail the
predicate are removed; the surviving elements keep their original keys. Follow
with `|> values` when you want a zero-based list:

```twig
{{ {a: 'x', b: '', c: 'yy'} |> filter("length") |> json }}
{# Output: {"a":"x","c":"yy"} #}
{{ {a: 'x', b: '', c: 'yy'} |> filter("length") |> values |> json }}
{# Output: ["x","yy"] #}
```

The callable is a **predicate**: its return value is tested for truthiness and
the ORIGINAL element passes through — the element is not replaced by the
predicate's result. `|> map` is the transforming counterpart.

#### reduce(callable, initial?)

Reduce array to single value (see [Lambda Expressions](#lambda-expressions)):

```twig
{{ [1, 2, 3, 4] |> reduce(sum, value => sum + value, 0) }}
{# Output: 10 #}
{{ words |> reduce(acc, word => acc ~ ' ' ~ word) }}
{# Join with spaces #}
```

### General Purpose Filters

#### length / len

Count array elements or string length:

```twig
{{ items:length }} {# Property access also works #}
{{ items |> length }} {# Filter form #}
{{ length(items) }} {# Call form — same result #}
{{ "hello" |> length }} {# Output: 5 #}
```

`len` is an alias — all four spellings above work with it too.

#### slice(start, length?)

Extract portion of array or string:

```twig
{{ [1, 2, 3, 4, 5] |> slice(1, 3) |> join(', ') }}
{# Output: "2, 3, 4" #}
{{ "Hello World" |> slice(0, 5) }}
{# Output: "Hello" #}
{{ items |> slice(0, 10) }}
{# First 10 items #}
```

#### default(fallback)

Return fallback if value is `null` or not set:

```twig
{{ userName |> default('Guest') }}
{# Returns 'Guest' if userName is null/unset #}
{{ count |> default(0) }}
{# Returns 0 if count is null/unset #}
```

> **Note:** `default` uses `??` (null coalescing) and only triggers for `null` or missing keys.

#### empty(fallback)

Return fallback if value is empty or falsy:

```twig
{{ userName |> empty('Anonymous') }}
{# Returns 'Anonymous' if userName is "", 0, null, false #}
```

> **Note:** `empty` uses `?:` and triggers for any falsy value including empty strings and zero.

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

### Type Cast Filters

Convert a value's type. Each of the six casts is available as a filter
(`{{ x |> int }}`), as a call (`{{ int(x) }}`) and as a cast prefix
(`{{ (int) x }}`). See [Cast Syntax](01-template-syntax.md#cast-syntax).

```twig
{{ '3.7' |> int }}          {# 3        — truncates, does not round #}
{{ '3.7' |> float }}        {# 3.7                                  #}
{{ 1.5 |> string }}         {# '1.5'                                #}
{{ '0' |> bool }}           {# false    — PHP truthiness            #}
{{ 1 |> array }}            {# [1]      — a scalar is wrapped       #}
{{ 1 |> object }}           {# stdClass — a scalar is wrapped       #}
```

Casts never throw and never return `null`. A value that cannot be read as the
target type becomes that type's empty value.

| Value     | `int` | `float` | `string`  | `bool`  | `array`     | `object` (JSON)      |
| --------- | ----- | ------- | --------- | ------- | ----------- | -------------------- |
| `'3.7'`   | `3`   | `3.7`   | `'3.7'`   | `true`  | `['3.7']`   | `{"scalar":"3.7"}`   |
| `'42abc'` | `42`  | `42.0`  | `'42abc'` | `true`  | `['42abc']` | `{"scalar":"42abc"}` |
| `'dsfd'`  | `0`   | `0.0`   | `'dsfd'`  | `true`  | `['dsfd']`  | `{"scalar":"dsfd"}`  |
| `''`      | `0`   | `0.0`   | `''`      | `false` | `['']`      | `{"scalar":""}`      |
| `'0'`     | `0`   | `0.0`   | `'0'`     | `false` | `['0']`     | `{"scalar":"0"}`     |
| `null`    | `0`   | `0.0`   | `''`      | `false` | `[]`        | `{}`                 |
| `true`    | `1`   | `1.0`   | `'1'`     | `true`  | `[true]`    | `{"scalar":true}`    |
| `[1, 2]`  | `1`   | `1.0`   | `'Array'` | `true`  | `[1, 2]`    | `{"0":1,"1":2}`      |

> `'42abc'` is read up to its leading number, as in PHP. `'dsfd'` has no leading
> number, so it becomes `0`.

> `'0'` is a non-empty string that casts to `false`. Bool casts follow PHP
> truthiness, not emptiness checks. A naive `!empty()` check gets this case wrong.

> `object` produces a `stdClass`, which has no `__toString()`. Rendering it
> directly, as in `{{ x |> object }}`, throws. Pass it to a serializer first:
> `{{ x |> object |> json }}`. The table shows the JSON form for this reason.

#### Why casts are needed

The numeric filters `round`, `ceil`, `floor` and `abs` do not coerce their
input. Under `strictTypes` (on by default), a numeric string raises a
`TypeError`:

```twig
{{ '3.7' |> round(2) }}          {# TypeError under strictTypes #}
{{ '3.7' |> float |> round(2) }} {# 3.7 — the cast states the conversion #}
```

This is intended. A filter that accepts the wrong type and returns a plausible
result hides bugs, and `strictTypes` exists to prevent that. The one exception
is `number`, which still accepts a string because `number_format()` has no
`string` overload.

#### Cast Syntax

Each cast also has a PHP-style prefix, which fits inside a call's argument list:

```twig
{{ (int) x }}                    {# same as {{ x |> int }}  #}
{{ abs((int) '-4343') }}         {# 4343                    #}
```

The accepted type names and the parsing rules are in
[Cast Syntax](01-template-syntax.md#cast-syntax). The prefix and filter forms
accept the same six names, including `object`.

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
{{ users |> map(user => user:name) |> join(', ') }}
```

Transform values:

```twig
{{ numbers |> map(n => n * 2) |> join(', ') }}
```

Complex expressions:

```twig
{{ products
    |> map(p => p:name ~ ' ($' ~ (p:price |> number(2)) ~ ')')
    |> join(', ') }}
```

Access outer variables:

```twig
{% set prefix = 'Item: ' %}
{{ items |> map(item => prefix ~ item:name) |> join(', ') }}
```

### Filter Examples

Filter with condition:

```twig
{{ users |> filter(u => u:age >= 18) |> map(u => u:name) |> join(', ') }}
```

Multiple conditions:

```twig
{{ products |> filter(p => p:inStock and p:price < 100) }}
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
{{ cart:items
    |> reduce(total, item => total + (item:price * item:quantity), 0)
    |> number(2) }}
```

### Filter References

Use registered filter names as callbacks:

```twig
{# Apply 'upper' filter to each tag #}
{{ tags |> map("upper") |> join(', ') }}
{# Trim each name #}
{{ names |> map("trim") |> join(', ') }}
{# Works with custom filters too #}
{{ prices |> map("currency") |> join(', ') }}
```

Both `map` and `filter` accept a reference, but each expects a different kind of
callable — `map` a transformer, `filter` a **predicate**:

```twig
{# map: the return value REPLACES each element #}
{{ tags |> map("upper") |> join(', ') }}
{# ['a','b'] -> 'A, B' #}

{# filter: the return value is TESTED; the original element passes through #}
{{ items |> filter("length") |> join(', ') }}
{# ['a','','bb'] -> 'a,bb' ('' is falsy) #}
```

`reduce` requires a **binary** callback (accumulator, then element), which a
unary inline filter cannot express — so `reduce("upper", …)` is rejected at
compile time rather than silently returning the initial value. Write the
two-parameter lambda instead:

```twig
{{ numbers |> reduce(carry, item => carry + item, 0) }}
```

## Built-in Functions

Functions are called directly in expressions. Because filters and functions are
[one namespace](#one-call-model-two-signatures), every filter can also be called
this way; the names below are simply the ones whose _canonical_ use is a call.

### vars()

Get all current template variables:

```twig
{% set allVars = vars() %}
{{ allVars |> json |> raw }}
```

Useful for debugging or passing all context to an include:

```twig
{{ include("partial", vars()) }}
```

It reports what is actually in scope, not just the variables the view was
rendered with: inside a `{% for %}` or a macro body the loop variable or
parameter appears too, and a `{% set %}` is visible from the moment it runs.

### include(template, context?)

Dynamically render another template at runtime:

```twig
{{ include("partials/card", { title: "Hello", content: "World" }) }}
```

With dynamic template name:

```twig
{% for widget in widgets %}
    {{ include("widgets/" ~ widget:type, widget:data) }}
{% endfor %}
```

Merge current context:

```twig
{{ include("partials/user", { ..:vars(), showEmail: true }) }}
```

### json(...values)

Encode values as JSON:

```twig
{{ json(user:name, user:age) |> raw }} {# Output: ["John",30] #}
{{ json(data) |> raw }} {# Encode single value #}
```

### dump(...values)

Debug output, rendered by the context-aware renderer — an HTML tree in HTML, a
JS comment inside `<script>`, or a CSS comment inside `<style>` — with sensitive
array keys masked:

```twig
<pre>{{ dump(user, settings) }}</pre>
{# Useful for debugging #}
```

`dump` is also a **filter**. It emits the dumped value and passes the piped value
through unchanged, so it can sit in the middle of a pipeline:

```twig
{{ items |> filter(i => i:active) |> dump |> slice(0, 5) }}
```

It also works as a quoted callable reference:

```twig
{{ map(items, "dump") |> length }}
```

In production (`debug` off) **every** form is eliminated: the call is pruned to
`''` and the filter/reference forms collapse to the identity, so nothing is
dumped and nothing is added.

### isset(name)

Checks whether a name or property/index chain has a non-`null` value:

```twig
{{ isset(user:email) }}          {# true when the key is present and not null #}
{{ isset(user.profile.avatar) }} {# object property chain #}
{{ isset(items[0]) }}            {# array index #}
```

An absent name returns `false` without raising the “Variable … is not defined”
error produced by a strict read. This matches `name is defined`: a `null` value
is considered unset, consistent with `isset()` behavior.

`isset` is an inline function that must be called directly. The compiler must
inspect its operand as source code (a variable chain), so it cannot be piped:
`{{ name |> isset }}` is a compile-time error. The compiler validates the operand, so a non-chain such as `isset(1 + 1)` produces a template-located error instead of a PHP fatal in the compiled cache file.

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

### len(var)

An alias of [`length`](#length) — identical behavior in **both** syntaxes. `len` is provided because it reads naturally as a call:

```twig
{{ len(data) }}      {# function form #}
{{ data |> len }}    {# filter form #}
```

### range(low, high, step?)

An inclusive list of integers:

```twig
{{ range(1, 5) |> join(', ') }}        {# 1, 2, 3, 4, 5 #}
{{ range(0, 10, 5) |> join(', ') }}    {# 0, 5, 10       #}
```

A call-only function: the arguments are the subject, so there is no piped form.

### cycle(values, position)

The value at `position` modulo the list length — the usual way to alternate a
value inside a loop:

```twig
{{ cycle(['odd', 'even'], 1) }}         {# even #}
{{ cycle(['a', 'b', 'c'], 4) }}         {# b (wraps)    #}
{{ cycle(['a', 'b', 'c'], -1) }}        {# c (negative counts from the end) #}
```

### attribute(subject, name, default?)

A dynamic read that follows the same access model as `a.b` / `a:b`: an array key
for an array, a public property for an object. Useful when the name is itself a
variable.

```twig
{{ attribute(user, 'name') }}
{{ attribute(user, field, 'n/a') }}     {# fallback when missing #}
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
{{ currency(price, '$') }} {# Output: $ 12.50 #}
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
{{ article:body |> excerpt(50) }} {{ article:body |> excerpt(150, '…') }}
```

### Filter Accessing Template Context

Filters can access dependencies through closures:

```php
$config = ['dateFormat' => 'd.m.Y'];

$engine->addFilter('formatDate', function($timestamp) use ($config) {
    return date($config['dateFormat'], $timestamp);
});
```

> **Note:** A registered filter can also be called as a function. The piped value becomes the first argument, so `currency(price, '$')` is equivalent to `price |> currency('$')`. This applies to filters registered with `addFilter()`; functions registered with `addFunction()` cannot be used with filter syntax.

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

> **Note:** Functions registered with `addFunction()` cannot be used as filters.

### Inline Functions

`addFunction()` dispatches a callable at render time. When the operation is a
plain PHP expression, `addInlineFunction()` compiles it directly into the
generated template — no callable, no dispatch — while keeping the call-only
rule:

```php
$engine->addInlineFunction('present', [
    'php'    => 'isset({1})',
    'callGuard' => 'presence',
]);
```

```twig
{{ present(user:email) }}   {# compiles to isset($__c_va['user']['email']) #}
{{ user:email |> present }} {# compile error: it is a function, not a filter #}
```

The record has the same shape as an inline filter (`php`, `params`, `defaults`,
`variadic`, `valueParam`) plus two call-only members:

| Key         | Meaning                                                                                        |
| ----------- | ---------------------------------------------------------------------------------------------- |
| `filter`    | Always set to `false` by this method — the name is answered by call syntax only.               |
| `callGuard` | Optional validation of the first argument. `presence` accepts only a name or a chain over one. |

A `callGuard` runs on the **compiled** operand, so every access operator works:
`user:email`, `user.email` and `items[0]` are all legal `presence` operands,
while `present(1 + 1)` is refused at compile time with a located error (PHP's
own `isset()` would otherwise fail to parse the compiled file).

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

> **Note:** For inline filters, Clarity resolves and validates arguments while compiling the template.
> For runtime callables and PHP functions, named arguments are emitted as PHP 8 named arguments, so PHP applies its usual argument validation at runtime.
> Positional arguments must come before named ones.

## PHP Functions as Filters

When the `phpFunctions` rule is on, unregistered PHP functions can be used directly
as filters or function calls, subject to the function allowlist and deny list.
Registered filters and functions take precedence over same-named PHP functions.

```php
$engine->setPolicy(Policy::unrestricted());
```

As a filter, the piped value becomes the **first argument**:

```twig
{{ 'ab' |> strtoupper }}          {# \strtoupper($value) #}
{{ 'x' |> str_pad(3, '-') }}      {# \str_pad($value, 3, '-') #}
```

When the value does not belong first, a single `_` placeholder positions it:

```twig
{{ 'k' |> array_key_exists(_, m) }}   {# \array_key_exists($value, $m) #}
```

As a call, arguments are passed as written:

```twig
{{ strtoupper('ab') }}
{{ implode(',', items) }}
```

Use `denyFunctions()` to block PHP function calls made through template
expressions. It does not inspect calls inside raw `{% php %}` blocks, which are
governed by the `rawPhp` rule. See [The Policy API](09-policy-api.md) for details.

## Filter Reference Quick Table

| Filter                      | Purpose                | Example                                          |
| --------------------------- | ---------------------- | ------------------------------------------------ |
| `trim`                      | Remove whitespace      | `{{ text \|> trim }}`                            |
| `upper`                     | Uppercase              | `{{ name \|> upper }}`                           |
| `lower`                     | Lowercase              | `{{ email \|> lower }}`                          |
| `capitalize`                | Capitalize first char  | `{{ word \|> capitalize }}`                      |
| `title`                     | Title case             | `{{ heading \|> title }}`                        |
| `nl2br`                     | Newlines to `<br>`     | `{{ text \|> nl2br \|> raw }}`                   |
| `replace(s,r)`              | Replace occurrences    | `{{ text \|> replace('a','b') }}`                |
| `striptags`                 | Strip HTML tags        | `{{ html \|> striptags }}`                       |
| `slug`                      | URL-friendly slug      | `{{ title \|> slug }}`                           |
| `truncate(len)`             | Truncate string        | `{{ text \|> truncate(100) }}`                   |
| `sprintf(...args)`          | sprintf formatting     | `{{ "%s: %d" \|> sprintf(name, n) }}`            |
| `format` (alias)            | Alias of `sprintf`     | `{{ "%s: %d" \|> format(name, n) }}`             |
| `number(dec)`               | Format number          | `{{ price \|> number(2) }}`                      |
| `abs`                       | Absolute value         | `{{ n \|> abs }}`                                |
| `round(prec)`               | Round number           | `{{ n \|> round(2) }}`                           |
| `ceil` / `floor`            | Ceil/floor             | `{{ n \|> ceil }}`                               |
| `date(fmt)`                 | Format date            | `{{ time \|> date('Y-m-d') }}`                   |
| `date_modify(mod, fmt='c')` | Modify date            | `{{ time \|> date_modify('+1 day', 'Y-m-d') }}`  |
| `format_datetime`           | Locale-aware datetime  | `{{ time \|> format_datetime('long','short') }}` |
| `first`                     | First element/char     | `{{ items \|> first }}`                          |
| `last`                      | Last element/char      | `{{ items \|> last }}`                           |
| `keys`                      | Array keys             | `{{ obj \|> keys \|> join(', ') }}`              |
| `values`                    | Array values           | `{{ obj \|> values }}`                           |
| `join(glue)`                | Join array             | `{{ tags \|> join(', ') }}`                      |
| `split(delim)`              | Split string           | `{{ csv \|> split(',') }}`                       |
| `slice(start,len)`          | Extract portion        | `{{ items \|> slice(0, 10) }}`                   |
| `merge(other)`              | Merge arrays           | `{{ a \|> merge(b) }}`                           |
| `sort`                      | Sort array             | `{{ items \|> sort }}`                           |
| `reverse`                   | Reverse array/string   | `{{ items \|> reverse }}`                        |
| `shuffle`                   | Shuffle array          | `{{ items \|> shuffle }}`                        |
| `batch(size)`               | Split into chunks      | `{{ items \|> batch(3) }}`                       |
| `map(fn)`                   | Transform each element | `{{ items \|> map(i => i:name) }}`               |
| `filter(fn)`                | Filter elements        | `{{ items \|> filter(i => i:active) }}`          |
| `reduce(fn,init)`           | Reduce to single value | `{{ nums \|> reduce(s, v => s + v, 0) }}`        |
| `length`                    | Count/length           | `{{ items \|> length }}`                         |
| `len` (alias)               | Alias of `length`      | `{{ items \|> len }}`                            |
| `int`                       | Cast to int            | `{{ x \|> int }}`                                |
| `float`                     | Cast to float          | `{{ '3.7' \|> float \|> round(2) }}`             |
| `string`                    | Cast to string         | `{{ n \|> string \|> trim }}`                    |
| `bool`                      | Cast to bool           | `{{ x \|> bool }}`                               |
| `array`                     | Cast to array          | `{{ x \|> array }}`                              |
| `default(val)`              | Fallback for null      | `{{ name \|> default('Guest') }}`                |
| `empty(val)`                | Fallback for falsy     | `{{ name \|> empty('Anon') }}`                   |
| `json`                      | JSON encode            | `{{ data \|> json \|> raw }}`                    |
| `url_encode`                | URL-encode             | `{{ q \|> url_encode }}`                         |
| `data_uri(mime)`            | Base64 data URI        | `{{ img \|> data_uri('image/png') }}`            |
| `unicode`                   | Unicode string ops     | `{{ text \|> unicode \|> reverse }}`             |
| `escape` / `esc`            | HTML escape            | `{{ html \|> escape }}`                          |
| `raw`                       | Disable auto-escaping  | `{{ html \|> raw }}`                             |

> **Note:** Every name above is also callable with parentheses; the value that would be piped becomes the first argument, except `date` and `join`, whose call form mirrors PHP (see [One call model](#one-call-model-two-signatures)).  
> The opposite is not true: a few names are **call-only** because their first argument is not a piped value — `vars`, `include`, `dd` and the inline function `isset`.  
> Writing `{{ x |> vars }}` or `{{ x |> isset }}` is a compile error; call them instead (`{{ vars() }}`, `{{ isset(x) }}`).  
> `dump` is an exception: it is both callable and pipeable, because its filter form is a pass-through probe rather than a dispatch of the callable. All three `dump` forms — the call, the pipe step and the quoted reference — are eliminated in production.

## Next Steps

- **[Layout Inheritance](03-layout-inheritance.md)** — Create reusable layouts
- **[Advanced Topics](04-advanced-topics.md)** — Namespaces, caching, error handling
- **[Examples](examples/README.md)** — See filters in action
