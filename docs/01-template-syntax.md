# Template Syntax Reference

This guide covers all template syntax features in Clarity, including output expressions, directives, operators, and control structures.

## Syntax Overview

Clarity uses two primary delimiters:

| Syntax      | Purpose                                       |
| ----------- | --------------------------------------------- |
| `{{ ... }}` | Output expressions (print values)             |
| `{% ... %}` | Directives (control flow, logic, inheritance) |
| `{# ... #}` | Comments (not rendered in output)             |

## Output Expressions

### Basic Output

Use double curly braces to output a value:

```twig
<p>{{ message }}</p>
<h1>{{ pageTitle }}</h1>
```

### Auto-Escaping

**All output is automatically HTML-escaped** for security:

```twig
{{ userInput }}
<!-- If userInput = "<script>alert('xss')</script>" -->
<!-- Outputs: &lt;script&gt;alert('xss')&lt;/script&gt; -->
```

To output raw HTML (use with caution!), use the `raw` filter:

```twig
{{ trustedHtml |> raw }}
```

> **Security Warning:** Only use `raw` with trusted content. Never use it with user input.

### Variable Access

Access is **strict and compile-time typed**: the operator you write states what
the value IS, and the engine emits exactly that read. There is no conversion of
objects to arrays before rendering, so reading an object's state costs one
property access and nothing else.

| Syntax                        | Meaning                                  | Emits                           |
| ----------------------------- | ---------------------------------------- | ------------------------------- |
| `a.b.c`                       | **object property** (static)             | `$vars['a']->b->c`              |
| `a{expr}`                     | object property (dynamic)                | `$vars['a']->{$exprPhp}`        |
| `items[expr]`                 | **array index**                          | `$vars['items'][$exprPhp]`      |
| `a:b:c`                       | **array key** (static)                   | `$vars['a']['b']['c']`          |
| `$a.b` / `$a->b`              | PHP-style alias for `.` (sigil required) | `$vars['a']->b`                 |
| `a?.b` `a?[i]` `a?{k}` `a?:k` | optional **receiver**                    | `isset(…) ? … : null` / `…?->b` |

```twig
<!-- Object properties -->
{{ user.name }} {{ user.address.city }}

<!-- Array keys -->
{{ config:version }} {{ item:meta:title }}

<!-- Array index (dynamic) -->
{{ items[0] }} {{ items[index] }}

<!-- Dynamic property -->
{{ user{fieldName} }}

<!-- Mixed: array of objects -->
{{ users[0].profile.avatar }} {{ data:items[currentIndex].title }}
```

#### Strictness

Applying the wrong operator is an error, not a silent `null`:

```twig
{% set user = { name: "Alice" } %}   {# an ARRAY #}
{{ user.name }}     {# ERROR: Cannot read property "name" on array #}
{{ user:name }}     {# CORRECT #}
```

A missing key or property raises `ClarityException` naming the template and line.
**Deliberately absent** is `null`, so a present-but-null value is never confused
with a missing one: an absent key throws, while a key holding `null` returns
`null`.

#### Optional access

`?` marks the value BEFORE it as possibly absent or `null`. Everything after the
`?` is still **strict**, so a typo in the member is still an error:

```twig
{{ user?.name }}          {# fine when `user` is absent/null #}
{{ items?[0] }}           {# fine when `items` is absent/null #}
{{ user?.address?.city }} {# fine when `user` OR `address` is absent/null #}
```

The rule is **`?` guards the LEFT side, never the right one**:

| Expression          | `user` absent/null | `user` present, `name` missing |
| ------------------- | ------------------ | ------------------------------ |
| `user.name`         | ERROR              | ERROR                          |
| `user?.name`        | `null`             | **ERROR**                      |
| `user.name ?? 'x'`  | ERROR              | `'x'`                          |
| `user?.name ?? 'x'` | `'x'`              | `'x'`                          |

Use `?` when the **receiver** may be missing, and `??` when the **member** may be.
That separation is deliberate: if `?` also swallowed a missing member, a typo
would render as an empty string instead of failing — the exact silence the strict
access design exists to prevent.

```twig
{{ user?.nickname }}                  {# '' when user absent; ERROR on a typo #}
{{ user?.nickname ?? 'anonymous' }}   {# 'anonymous' when user is absent OR nickname missing #}
{{ settings?:theme ?? 'light' }}      {# array side, same rule #}
```

Emission differs per side because the operators differ in what they accept:

| Expression | Emits                                                    |
| ---------- | -------------------------------------------------------- |
| `a?:b`     | `(isset($vars['a']) ? $vars['a']['b'] : null)`           |
| `a?.b`     | `(isset($vars['a']) ? $vars['a']->b : null)`             |
| `a?.b?.c`  | `(isset($vars['a']) ? $vars['a']->b : null)?->c`         |
| `a?:b?:c`  | a `=== null` test that binds the receiver to a temporary |

Both sides use the shortest form that tolerates an absent receiver, and a bare
root gets `isset()` on both sides so an absent ROOT behaves the same way. Neither
form re-embeds its receiver, so a chain of N optional segments stays a linear
expression rather than growing exponentially — a property the test suite pins,
because an exponential emission is behaviourally invisible and only shows up in
what has to be parsed and cached on every request.

> **Array-side note.** The array guard is a ternary, and a branch is evaluated
> before an outer operator sees it. `items?[9] ?? 'fb'` therefore cannot suppress
> a missing INDEX (the branch reads it first). Coalesce a STRICT read instead:
> `items[9] ?? 'fb'` supplies the fallback with no warning. The object side has no
> such limit, because `?->` composes with a following `??`.

An absent ROOT behaves the same on both sides — `{{ missing?.name }}` renders
empty — while the strict form reports it:

```twig
{{ missing?.name }}    {# empty — no error #}
{{ missing.name }}     {# ERROR: Variable "missing" is not defined #}
```

#### The dollar sigil

`->` is PHP-style property access and **requires** the `$` sigil, so raw PHP
syntax can never be emitted from an unsigiled expression:

```twig
{{ $user->name }}    {# legal, identical to {{ user.name }} #}
{{ user->name }}     {# COMPILE ERROR #}
```

#### `:` and the ternary

`:` continues a chain when it is followed by a key. Whitespace on either side is
allowed, so all four of these read the same key:

```twig
{{ config:version }}
{{ config : version }}
{{ config: version }}
{{ config :version }}
```

The exception is a ternary, which also uses `:`. Once a `?` has opened a branch,
a colon only counts as a key when it is glued on **both** sides. That single rule
is what separates the two without forbidding whitespace anywhere:

```twig
{{ cond ? "yes" : "no" }}   {# ternary #}
{{ cond ? "yes": "no" }}    {# ternary #}
{{ cond ? "yes" :"no" }}    {# ternary #}
{{ cond ? a:b : c }}        {# key read in the branch, then the separator #}
```

Two spellings are errors rather than ambiguity, because they differ from a valid
form by one space:

```twig
{{ user ? : "fallback" }}   {# WRONG: empty then-branch (PHP's ?: shorthand) #}
{{ user?:nickname }}        {# CORRECT: optional key read #}
```

Named arguments are unaffected, because `name: expr` is consumed as an argument
before expression compilation.

#### Nested conditions

Parenthesise a nested ternary and it nests to any depth:

```twig
{{ cond ? (foo ? bar : blubb) : blobb }}
{{ a ? (b ? (c ? d : e) : f) : g }}
```

A nested ternary in the THEN branch may omit the parentheses
(`a ? b ? c : d : e`). One in the ELSE branch may **not**, because PHP rejects
`a ? b : c ? d : e` outright rather than choosing an associativity:

```twig
{{ a ? b : (c ? d : e) }}   {# CORRECT: parenthesised else-branch #}
{{ a ? b : c ? d : e }}     {# COMPILE ERROR: use the form above #}
```

The same applies to an if/else chain written as one expression — use the
parenthesised form, or a `{% if %}` / `{% elseif %}` block.

#### Whitespace and line breaks

Whitespace around a chain operator is not significant, so a long chain may wrap:

```twig
{{ user.
   address.
   city }}

{{ config:
   version }}
```

`.` is **always** property access, never string concatenation, so a chain has
exactly one reading. Concatenation is `~`:

```twig
{{ firstName ~ ' ' ~ lastName }}
```

An operator with no member after it (`.`, `->`) is a compile error. Optional
access is the one operator that must stay glued, because a spaced `?` is a
ternary: `user?:nick` reads a key, while `user ? x : y` is a condition.

#### `loop`

Inside a loop, `loop` is an object with the current iteration's metadata:
`index`, `index0`, `first`, `last`, `revindex`, `revindex0`, `total`, `length`.

```twig
{% for item in items %}
    <li class="{{ loop.first ? 'first' : '' }}">{{ loop.index }}/{{ loop.total }}</li>
{% endfor %}
```

#### `expand`

`|> expand` treats the arriving value as a variable NAME and looks it up in the
render scope. Because the value is what is expanded, it composes anywhere in a
pipeline:

```twig
{{ name |> expand }}                           {# value of {{ name }} is a variable name #}
{{ key |> rot13 |> expand }}                   {# expand the TRANSFORMED value #}
{{ name |> expand(optional: true) ?? 'none' }} {# optional: absent name yields null #}
{{ name |> expand(fallback: 'none') }}         {# eager fallback for an absent name #}
```

Absent names throw by default, consistent with every other access. Pass
`optional: true` to make an absent name yield `null` instead, so a following
`??` (or `|> default(...)`) supplies the value. `optional` must be a literal
`true`/`false`, because which branch is emitted is decided at compile time.

`fallback:` (or a single positional argument) is the eager alternative: it
decides the value directly and needs no `??`. Both spellings are the author's
opt-out from the strict contract.

> `??` suppresses a `null` RETURN only — it cannot catch the strict throw, so
> `{{ x |> expand ?? 'none' }}` still errors when the name is absent. Write
> `expand(optional: true) ?? 'none'` or `expand('none')`.

#### Iteration and container filters

Objects iterate their **public properties**, so `{% for %}` works over an object
as well as an array. `|> length`, `|> keys`, `|> values`, `|> first`, `|> last`
and `|> reverse` accept a container (array, `Traversable`, `Countable`, or an
object exposing a public `toArray()`). A value object with no public state and a
`__toString()` keeps STRING semantics, so `|> length` counts its characters.

**Not allowed:** bare `->` without a sigil, method calls (`a.b()`), and direct
PHP variable access (`$name` on its own).

## Directives

Directives use `{% ... %}` syntax for control flow and template structure.

### Conditional Statements

#### If / Else / Elseif

```twig
{% if user.isActive %}
<span class="badge active">Active</span>
{% elseif user.isPending %}
<span class="badge pending">Pending</span>
{% else %}
<span class="badge inactive">Inactive</span>
{% endif %}
```

Single condition:

```twig
{% if stock > 0 %}
<button>Add to Cart</button>
{% endif %}
```

### Loops

#### For Loop

Iterate over arrays:

```twig
<ul>
  {% for item in items %}
  <li>{{ item.name }} - {{ item.price |> number(2) }}</li>
  {% endfor %}
</ul>
```

#### Loop with Key Variable

Access both key and value in a single loop using the two-variable syntax. The **first** name is the key and the **second** is the value — the same order as Twig's `{% for key, user in users %}`:

```twig
{# Indexed array: index, value #}
{% for idx, item in items %}
<li>{{ idx }}: {{ item.name }}</li>
{% endfor %}

{# Associative array: key, value #}
{% for k, v in settings %}
<p>{{ k }}: {{ v }}</p>
{% endfor %}
```

This compiles directly to PHP's `foreach ($items as $idx => $item)`.

#### Range Loops

**Inclusive range** (includes end):

```twig
{% for i in 1..10 %}
  {{ i }} {# Outputs: 1 2 3 4 5 6 7 8 9 10 #}
{% endfor %}
```

**Exclusive range** (doesn't include end):

```twig
{% for i in 1...10 %}
  {{ i }} {# Outputs: 1 2 3 4 5 6 7 8 9 #}
{% endfor %}
```

**Range with step:**

```twig
{% for i in 0..100 step 10 %}
  {{ i }} {# Outputs: 0 10 20 30 40 50 60 70 80 90 100 #}
{% endfor %}
```

**Dynamic ranges:**

```twig
{% for i in start..end step increment %}
  {{ i }}
{% endfor %}
```

### Macros

Macros are reusable template fragments defined once and called multiple times within a template.

#### Defining a Macro

```twig
{% macro @card(title, body) %}
<div class="card">
  <h3>{{ title }}</h3>
  <p>{{ body }}</p>
</div>
{% endmacro %}
```

#### Calling a Macro

Use `{% @macroName(arg1, arg2) %}` to invoke a macro:

```twig
{% @card("Welcome", "Hello from Clarity!") %}
{% @card(article.title, article.excerpt) %}
```

#### Macro Rules

- Macros are defined with `{% macro @name(param1, param2) %}...{% endmacro %}`
- Macro names are prefixed with `@` and must not conflict with template variables
- Macros are expanded **inline at compile time** — zero runtime overhead
- Parameters can reference any expression available at the call site
- Macros cannot call themselves recursively (cycle detection throws a compile error)
- Macros are scoped to the current compile pass: macros defined in the template or in static includes become available after they are encountered, but independently rendered templates do not share macros

> **Note:** Clarity also uses the `@...` notation for some compile-time snippets that are internal macros. The main example is `{% @parent %}` inside overriding child blocks.

#### Multi-Parameter Example

```twig
{% macro @avatar(name, size, href) %}
<a href="{{ href }}" class="avatar avatar--{{ size }}">
  <span>{{ name |> upper |> slice(0, 2) }}</span>
</a>
{% endmacro %}

{% for user in team %}
  {% @avatar(user.name, "md", "/users/" ~ user.id) %}
{% endfor %}
```

### Variable Assignment

Set variables for reuse:

```twig
{% set total = items.length %} {% set fullName = user.firstName ~ ' ' ~
user.lastName %} {% set discount = price * 0.1 %}

<p>Total items: {{ total }}</p>
<p>Customer: {{ fullName }}</p>
<p>You save: {{ discount |> number(2) }}</p>
```

Assigned variables are scoped to the current template and blocks.

### Template Inheritance

#### Extends

Extend a parent layout:

```twig
{% extends "layouts/main" %}
```

Must be the first directive in the template (before any output).

#### Blocks

Define overridable sections:

**Parent template** (`layouts/main.clarity.html`):

```twig
<!DOCTYPE html>
<html>
  <head>
    <title>{% block title %}Default Title{% endblock %}</title>
    {% block head %}{% endblock %}
  </head>
  <body>
    <header>
      {% block header %}
      <h1>My Site</h1>
      {% endblock %}
    </header>

    <main>{% block content %}{% endblock %}</main>

    <footer>
      {% block footer %}
      <p>&copy; 2026</p>
      {% endblock %}
    </footer>
  </body>
</html>
```

**Child template** (`pages/about.clarity.html`):

```twig
{% extends "layouts/main" %} {% block title %}About Us{% endblock %} {% block
content %}
<h2>About Our Company</h2>
<p>We are awesome!</p>
{% endblock %}
```

Blocks not overridden in the child will use the parent's default content.

#### Parent Block Fallback

Inside an overriding child block, use `{% @parent %}` to inline the parent block's content at that exact position during compilation:

```twig
{% extends "layouts/main" %}

{% block title %}
Admin | {% @parent %}
{% endblock %}
```

If the parent block contains `My Website`, the compiled result is `Admin | My Website`.

Rules:

- `{% @parent %}` is only valid inside a child block that overrides a parent block
- It is resolved at compile time, so it adds no runtime inheritance lookup
- You can use it more than once in the same block to repeat the parent content
- It refers to the **immediate** parent block in multi-level inheritance chains

### Includes

#### Static Include

Include another template, sharing the current variable scope:

```twig
{% include "partials/header" %}

<main>
  <!-- page content -->
</main>

{% include "partials/footer" %}
```

Included templates are inlined at compile time.

> **Tip:** Static includes can act as macro libraries. If an included template defines macros, include it before calling those macros in the parent template.

#### Include with Namespaces

```twig
{% include "admin::sidebar" %} {% include "emails::header" %}
```

See [Advanced Topics](04-advanced-topics.md#named-namespaces) for namespace configuration.

## Operators

### Comparison Operators

```twig
{% if age >= 18 %}
{% if status == 'active' %}
{% if count != 0 %}
{% if price < 100 %}
{% if score > 50 %}
{% if rating <= 5 %}
```

| Operator | Description              |
| -------- | ------------------------ |
| `==`     | Equal to                 |
| `!=`     | Not equal to             |
| `<`      | Less than                |
| `>`      | Greater than             |
| `<=`     | Less than or equal to    |
| `>=`     | Greater than or equal to |

### Logical Operators

```twig
{% if user.isActive and user.role == 'admin' %}
{% if status == 'pending' or status == 'review' %}
{% if not user.isBlocked %}
```

| Operator | Description |
| -------- | ----------- |
| `and`    | Logical AND |
| `or`     | Logical OR  |
| `not`    | Logical NOT |

> **Note:** The symbols `&&`, `||`, and `!` are also accepted (they pass straight through to PHP).

### Bitwise Operators

Use the keyword forms to avoid conflict with the `|` filter-pipe operator:

```twig
{{ flags bor mask }}    {# bitwise OR  — flags | mask  #}
{{ flags band mask }}   {# bitwise AND — flags & mask  #}
{{ flags bxor mask }}   {# bitwise XOR — flags ^ mask  #}
{{ bnot flags }}        {# bitwise NOT — ~flags        #}
```

| Operator | PHP equivalent | Description |
| -------- | -------------- | ----------- |
| `bor`    | `\|`           | Bitwise OR  |
| `band`   | `&`            | Bitwise AND |
| `bxor`   | `^`            | Bitwise XOR |
| `bnot`   | `~`            | Bitwise NOT |

### Arithmetic Operators

```twig
{% set total = price + tax %}
{% set discount = price * 0.1 %}
{% set remaining = total - paid %}
{% set perItem = total / count %}
{% set remainder = total % 10 %}
```

| Operator | Description    |
| -------- | -------------- |
| `+`      | Addition       |
| `-`      | Subtraction    |
| `*`      | Multiplication |
| `/`      | Division       |
| `%`      | Modulo         |

### String Concatenation

Use the `~` operator:

```twig
{% set fullName = firstName ~ ' ' ~ lastName %}
{% set greeting = 'Hello, ' ~ user.name ~ '!' %}

<p>{{ 'Total: ' ~ total ~ ' items' }}</p>
```

### Filter Pipe Operator

Both `|` and `|>` pipe a value through a filter. They are fully interchangeable:

```twig
{{ name | upper }}         {# Twig / Svelte style #}
{{ name |> upper }}        {# Clarity fat-pipe style #}
```

Whenever you need bitwise OR, use the `bor` keyword instead of `|` (see [Bitwise Operators](#bitwise-operators) above).

`||` (double pipe) is always logical OR and is never treated as a filter pipe.

### Ternary Operator

```twig
{{ user.isActive ? 'Active' : 'Inactive' }}
{{ stock > 0 ? 'In Stock' : 'Out of Stock' }}
{{ age >= 18 ? 'Adult' : 'Minor' }}
```

Syntax: `condition ? valueIfTrue : valueIfFalse`

Conditions nest, but a nested ternary in the else-branch must be parenthesised —
PHP rejects `a ? b : c ? d : e` outright. See
[Nested conditions](#nested-conditions).

```twig
{{ cond ? (foo ? bar : blubb) : blobb }}
```

### Null Coalescing

```twig
{{ user.nickname ?? user.name }}
{{ customTitle ?? defaultTitle }}
```

Returns the right value if the left is null or undefined.

## Expressions

### Literal Values

```twig
{{ 42 }}
{{ 3.14 }}
{{ true }}
{{ false }}
{{ null }}
{{ "string literal" }}
{{ 'single quotes' }}
```

### Collection Literals

**Arrays:**

```twig
{% set numbers = [1, 2, 3, 4, 5] %}
{% set mixed = [true, "text", 42, user.name] %}
```

**Objects:**

```twig
{% set person = { name: "John", age: 30, active: true } %}
{% set data = { id: item.id, title: item.title } %}
```

**Spread operator** in collections:

```twig
{% set extended = [1, 2, ...moreNumbers, 99] %}
{% set merged = { foo: "bar", ...otherData } %}
```

### Built-in Functions

#### context()

Returns all current template variables:

```twig
{% set allVars = context() %} {{ allVars |> json |> raw }}
```

#### include()

Dynamically render another template at runtime:

```twig
{{ include("partials/card", { title: "Hello", ...context() }) }}
{{ include(templateName, variables) }}
```

Unlike the `{% include %}` directive, this function:

- Renders at runtime (not compile time)
- Can use dynamic template names
- Returns the rendered markup directly
- Accepts custom context variables

Example:

```twig
{% for componentType in components %}
{{ include("components/" ~ componentType, { data: item }) }}
{% endfor %}
```

See [Filters and Functions](02-filters-and-functions.md) for custom functions.

## Comments

Comments are removed during compilation and don't appear in output:

```twig
{# This is a comment #}
{# Multi-line comment
   Useful for documentation #}
{# TODO: Add pagination here #}
```

### Context Hints

Special `@context` annotations inside comments instruct the compiler to switch the auto-escaping mode for all output expressions that follow. Clarity also auto-detects context when scanning `<script>` and `<style>` tags, but you can override it explicitly:

```twig
{# @context js #}
  var userData = {{ user |> json }};   {# JSON-encodes and hex-escapes for JS safety #}
{# @context html #}
  <p>{{ message }}</p>                 {# Back to standard HTML escaping #}
```

Available contexts:

| Context | Escaping applied to output expressions                 |
| ------- | ------------------------------------------------------ |
| `html`  | `htmlspecialchars()` (default)                         |
| `js`    | `json_encode()` with hex escaping (safe for inline JS) |
| `css`   | Cast to `(string)` — no HTML escaping                  |

> **Tip:** Clarity automatically switches context when it encounters `<script>` or `<style>` tags in the template. Use `{# @context … #}` when the auto-detection isn't sufficient (e.g. inside a string literal that contains a tag-like pattern).

## Complex Examples

### Nested Loops

```twig
<table>
  {% for category in categories %}
  <tr>
    <th>{{ category.name }}</th>
    <td>
      <ul>
        {% for product in category.products %}
        <li>{{ product.name }} - {{ product.price |> number(2) }}</li>
        {% endfor %}
      </ul>
    </td>
  </tr>
  {% endfor %}
</table>
```

### Conditional Rendering with Loops

```twig
{% if users.length > 0 %}
<ul>
  {% for user in users %} {% if user.isActive %}
  <li class="active">{{ user.name }}</li>
  {% endif %} {% endfor %}
</ul>
{% else %}
<p>No active users found.</p>
{% endif %}
```

### Complex Variable Assignment

```twig
{% set userData = {
  fullName: user.firstName ~ ' ' ~ user.lastName,
  age: user.birthYear ? (2026 - user.birthYear) : null,
  isAdult: user.birthYear and (2026 - user.birthYear) >= 18
} %}

<p>Name: {{ userData.fullName }}</p>
{% if userData.isAdult %}
<p>Age: {{ userData.age }}</p>
{% endif %}
```

### Dynamic Includes

```twig
{% for widget in dashboard.widgets %} {{ include("widgets/" ~ widget.type, {
title: widget.title, data: widget.data, config: widget.config }) }} {% endfor %}
```

## What's Not Allowed

Clarity is sandboxed for security. The following are **not permitted**:

Direct PHP variables:

```twig
{{ $variable }} {# ERROR #}
```

Arbitrary PHP function calls:

```twig
{{ strtoupper(name) }} {# ERROR #}
```

Method calls on objects:

```twig
{{ user.getName() }} {# ERROR #}
```

Instead, use filters:

```twig
{{ name |> upper }} {# CORRECT #}
```

PHP statements or semicolons:

```twig
{{ $x = 5; }} {# ERROR #}
```

Instead, use `{% set %}`:

```twig
{% set x = 5 %} {# CORRECT #}
```

## Next Steps

- **[Filters and Functions](02-filters-and-functions.md)** — Learn how to transform data
- **[Layout Inheritance](03-layout-inheritance.md)** — Master template reuse patterns
- **[Examples](../examples/README.md)** — See complete working examples

## Quick Reference

### Directive Summary

| Directive                                    | Purpose                     |
| -------------------------------------------- | --------------------------- |
| `{% if condition %}`                         | Conditional rendering       |
| `{% elseif condition %}`                     | Alternative condition       |
| `{% else %}`                                 | Fallback case               |
| `{% endif %}`                                | End conditional             |
| `{% for item in array %}`                    | Loop over array             |
| `{% for key, value in array %}`              | Loop with key variable      |
| `{% for i in start..end %}`                  | Range loop (inclusive end)  |
| `{% for i in start...end %}`                 | Range loop (exclusive end)  |
| `{% endfor %}`                               | End loop                    |
| `{% set variable = value %}`                 | Variable assignment         |
| `{% extends "template" %}`                   | Inherit from layout         |
| `{% block name %}...{% endblock %}`          | Define/override block       |
| `{% include "template" %}`                   | Include another template    |
| `{% macro @name(params) %}...{% endmacro %}` | Define a reusable macro     |
| `{% @name(args) %}`                          | Call a macro                |
| `{% @parent %}`                              | Inline parent block content |
| `{# comment #}`                              | Template comment            |
| `{# @context js\|css\|html #}`               | Switch escaping context     |

### Operator Summary

| Category      | Operators                                                      |
| ------------- | -------------------------------------------------------------- |
| Comparison    | `==` `!=` `<` `>` `<=` `>=`                                    |
| Logical       | `and` `or` `not`                                               |
| Arithmetic    | `+` `-` `*` `/` `%`                                            |
| Bitwise       | `band` `bor` `bxor` `bnot` `blsh` `brsh` `&` `^` `~` `<<` `>>` |
| String        | `~` (concatenation)                                            |
| Ternary       | `condition ? true : false`                                     |
| Null coalesce | `??`                                                           |
| Spread        | `...` (in arrays/objects)                                      |
