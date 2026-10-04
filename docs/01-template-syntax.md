# Template Syntax Reference

This guide covers all template syntax features in Clarity, including output expressions, directives, operators, and control structures.

## Syntax Overview

Clarity uses two primary delimiters:

| Syntax      | Purpose                                       |
| ----------- | --------------------------------------------- |
| `{{ ... }}` | Output expressions (print values)             |
| `{% ... %}` | Directives (control flow, logic, inheritance) |
| `{# ... #}` | Comments (not rendered in output)             |

### Whitespace Control

A `-` glued to a delimiter suppresses the whitespace on that side of the tag:
`{%-` trims the whitespace before the tag, `-%}` trims the whitespace after it.
Only whitespace (spaces, tabs, newlines) is removed — visible text is not.

```twig
{% for item in items %}
  <li>{{ item }}</li>
{% endfor %}
```

Without control, each directive line leaves a blank line behind. Adding the
markers collapses the loop to the markup alone:

```twig
{% for item in items -%}
  <li>{{ item }}</li>
{%- endfor %}
```

The same markers work on output tags (`{{- x -}}`) and comments (`{#- c -#}`).

## Output Expressions

### Basic Output

Use double curly braces to output a value:

```twig
<p>{{ message }}</p>
<h1>{{ pageTitle }}</h1>
```

### Auto-Escaping

**All output is automatically HTML-escaped**:

```twig
{{ userInput }}
<!-- If userInput = "<script>alert('xss')</script>" -->
<!-- Outputs: &lt;script&gt;alert('xss')&lt;/script&gt; -->
```

To output raw HTML, use the `raw` filter:

```twig
{{ trustedHtml |> raw }}
```

### Variable Access

Access is **strict and compile-time typed**: the operator you write states what
the value IS, and the engine emits exactly that read. There is no conversion of
objects to arrays before rendering, so reading an object's state costs one
property access and nothing else.

| Syntax                        | Meaning                      | Emits                           |
| ----------------------------- | ---------------------------- | ------------------------------- |
| `a.b.c`                       | **object property** (static) | `$vars['a']->b->c`              |
| `a{expr}`                     | object property (dynamic)    | `$vars['a']->{<expr>}`          |
| `items[expr]`                 | **array index**              | `$vars['items'][<expr>]`        |
| `a:b:c`                       | **array key** (static)       | `$vars['a']['b']['c']`          |
| `$a->b`                       | PHP-style alias for `.`      | `$vars['a']->b`                 |
| `$a->{<expr>}`                | PHP-style alias for `.`      | `$vars['a']->{<expr>}`          |
| `a?.b` `a?[i]` `a?{k}` `a?:k` | optional **receiver**        | `isset(…) ? … : null` / `…?->b` |

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

Emission differs per side because the operators differ in what they accept:

| Expression | Emits                                                    |
| ---------- | -------------------------------------------------------- |
| `a?:b`     | `(isset($vars['a']) ? $vars['a']['b'] : null)`           |
| `a?.b`     | `(isset($vars['a']) ? $vars['a']->b : null)`             |
| `a?.b?.c`  | `(isset($vars['a']) ? $vars['a']->b : null)?->c`         |
| `a?:b?:c`  | a `=== null` test that binds the receiver to a temporary |

Both sides use the shortest form that tolerates an absent receiver.

> **Array-side note.** The array guard is a ternary, and a branch is evaluated
> before an outer operator sees it. `items?[9] ?? 'fb'` therefore cannot suppress
> a missing INDEX (the branch reads it first). Coalesce a STRICT read instead:
> `items[9] ?? 'fb'`. The object side has no such limit, because `?->` composes
> with a following `??`.

#### The dollar sigil

Clarity accepts PHP variable syntax directly within templates. This allows you to use PHP-style property access and array indexing with the `$` sigil.

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

#### `${expr}` — dynamic variable access

`${expr}` reads the variable whose **name** is produced by an expression — the
dynamic spelling of an ordinary variable access. `$$name` is the same construct
(shorthand for `${name}`).

```twig
{{ ${which} }}                {# the value of the variable named by {{ which }} #}
{{ $$which }}                 {# identical shorthand #}
{{ ${ 'a' ~ 'b' } }}          {# the name may be computed #}
{{ ${ref} ?? 'none' }}        {# absent name: fall back, like {{ name ?? 'x' }} #}
{{ ${which}.title }}          {# chained access applies to the looked-up value #}
```

``${expr}` reads from the render scope and loop locals in every policy. An absent
name throws unless you provide a `?? fallback`. Dynamic names cannot access
superglobals or engine internals.

> Inside a string literal the `$` is literal text: `{{ "${which}" }}` renders
> `${which}`, not a lookup.

#### Iteration and container filters

Objects iterate their **public properties**, so `{% for %}` works over an object
as well as an array. `|> length`, `|> keys`, `|> values`, `|> first`, `|> last`
and `|> reverse` accept a container (array, `Traversable`, `Countable`, or an
object exposing a public `toArray()`). A value object with no public state and a
`__toString()` keeps STRING semantics, so `|> length` counts its characters.

## Policy-Controlled PHP Access

Policies determine which PHP constructs templates can use; they do not change
the template syntax. Registered filters and functions still take precedence.
See [The Policy API](09-policy-api.md) for the available rules and presets.

### PHP functions as calls

These require the `phpFunctions` rule:

```twig
{{ strtoupper('ab') }}          {# \strtoupper('ab') #}
{{ implode(',', items) }}       {# \implode(',', $__c_va['items']) #}
```

### PHP functions as filters

When the `phpFunctions` rule is on, an unregistered pipe step resolves to a PHP
function of the same name. The piped value becomes the **first argument**:

```twig
{{ 'ab' |> strtoupper }}              {# \strtoupper($value) #}
{{ 'x' |> str_pad(3, '-') }}          {# \str_pad($value, 3, '-') #}
```

When the value does not belong first, a single `_` placeholder positions it:

```twig
{{ 'k' |> array_key_exists(_, m) }}   {# \array_key_exists($value, $m) #}
```

`_` may appear at most once as an argument in a PHP-function filter call when
the `phpFunctions` rule is on; elsewhere `_` keeps its meaning as an ordinary
variable.

### Method calls

A method call needs the `methodCalls` rule. Both styles work and compile to the same PHP — a template written in dot syntax works as good as one written in PHP-style syntax.

```twig
{{ user.name() }}                {# static method, dot syntax #}
{{ user?.name() }}               {# nullsafe #}
{{ obj{m}() }}                   {# dynamic method, brace syntax #}

{{ $user->name() }}              {# static method, PHP-style #}
{{ $user?->name() }}             {# nullsafe #}
{{ $user->{$method}() }}         {# dynamic method, PHP-style #}
```

Other calls stay rejected under every policy, because they are not method calls:

```twig
{{ $fn() }}                      {# ERROR: a call on the root value #}
{{ arr:greet() }}                {# ERROR: a call on a key read, not a member #}
```

Variable-driven calls are not allowed under any policy, and array-driven calls are also rejected.

### Raw PHP tags

One spelling gives a trusted template the full power of PHP — one statement, or
one fragment of a control structure, per tag:

```twig
{% php $total = 0; %}
{% php foreach ($items as $item) : %}
    {% php $total += $item['qty']; %}
{% php endforeach %}
{% php echo $total; %}
```

Splitting a control structure across tags is what lets PHP structure wrap
template markup:

```twig
{% php if ($items) : %}
    <ul>
    {% php foreach ($items as $item) : %}
        <li>{{ $item['name'] }}</li>
    {% php endforeach %}
    </ul>
{% php else : %}
    <p>No items.</p>
{% php endif %}
```

A body may also span lines, for a run of statements in one tag:

```twig
{% php
$total = 0;
foreach ($items as $item) { $total += $item['qty']; }
echo $total;
%}
```

> The closing delimiter is matched non-greedily, so a literal `%}` inside a body
> ends the tag early — spell it `'%' . '}'` when that exact sequence is needed.

### Function guardrails

When the policy allows PHP access, PHP function calls from template expressions
are available. Deny specific names with `denyFunctions()`; it does not inspect
calls inside raw `{% php %}` blocks:

```php
$engine->setPolicy(Policy::unrestricted()->denyFunctions('exec', 'system'));
```

Changing the list invalidates compiled templates whose policy digest no longer
matches.

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

#### Empty Sequences: `{% else %}`

A loop body may be followed by an `{% else %}` branch, rendered when the
sequence is empty. This mirrors Twig and removes the need to count the items
before the loop:

```twig
<ul>
  {% for user in users %}
  <li>{{ user.name }}</li>
  {% else %}
  <li class="empty">No users yet.</li>
  {% endfor %}
</ul>
```

It works for every loop form — over arrays, over mappings with `for k, v`, and
over ranges:

```twig
{% for k, v in settings %}{{ k }}={{ v }}{% else %}no settings{% endfor %}

{% for i in 1..0 %}never{% else %}empty range{% endfor %}
```

Two rules follow from how Twig scopes loops:

- The loop variable does **not** exist in the else branch. `{{ user }}` there
  resolves to a template variable of that name, if one exists, rather than to
  the loop's last value.
- A loop takes one `{% else %}`. A second one — or an `{% elseif %}` — is a
  compile error.

> **Nesting:** `{% else %}` belongs to the innermost open construct _at the same
> nesting level_. So in `{% for %}…{% if %}…{% else %}…{% endif %}…{% else %}…{% endfor %}`
> the first `{% else %}` is the `if`'s and the second is the loop's. Conversely,
> in `{% if %}{% for %}…{% endfor %}{% else %}{% endif %}` the `{% else %}`
> closes the `if`, not the loop.

#### Plain `{% else %}` for Emptiness

Because the else branch runs only when nothing iterated, it is also the
direct way to say "render the fallback":

```twig
{% for item in items %}{{ item }}{% else %}Nothing to show.{% endfor %}
```

### Macros

Macros are reusable template fragments defined once and called multiple times within a template.

#### Defining a Macro

```twig
{% macro card(title, body) %}
<div class="card">
  <h3>{{ title }}</h3>
  <p>{{ body }}</p>
</div>
{% endmacro %}
```

#### Calling a Macro

Use `{% call macroName(arg1, arg2) %}` to invoke a macro:

```twig
{% call card("Welcome", "Hello from Clarity!") %}
{% call card(article.title, article.excerpt) %}
```

#### Macro Rules

- Define macros with `{% macro name(param1, param2) %}...{% endmacro %}`.
- Call macros with `{% call name(arg1, arg2) %}`.
- The definition itself renders nothing; the call expands at its own position.
- Macros expand **at compile time** and accept expressions available at the call site.
- Macro names are identifiers, so they are case-sensitive and may not be a
  directive keyword (`if`, `for`, `set`, `block`, `include`, …).
- Recursive calls fail compilation.
- Macros become available after their definition in the template or a static include. Independently rendered templates do not share them.
- A macro may **not** be defined inside another macro's body. Macro names are not
  scoped, so a nested definition would be callable from anywhere in the template
  despite looking private — define it at the top level, or in an included macro
  library, instead.

> **Note:** `{% parent %}`, which inlines a parent block's content in an overriding child block ([Layout Inheritance](./03-layout-inheritance.md#parent-block-fallback)), is a **directive**, not a macro — it takes no arguments and is not defined with `{% macro %}`.

> **Note:** Macros used to be defined as `{% macro @name %}` and called as
> `{% @name(args) %}`, and `{% parent %}` used to accept an `{% @parent %}`
> spelling. Those forms are removed. A `{% macro @name %}` is refused with a
> message naming the replacement; an `{% @name(args) %}` or `{% @parent %}` tag is
> simply an unknown directive.

#### Multi-Parameter Example

```twig
{% macro avatar(name, size, href) %}
<a href="{{ href }}" class="avatar avatar--{{ size }}">
  <span>{{ name |> upper |> slice(0, 2) }}</span>
</a>
{% endmacro %}

{% for user in team %}
  {% call avatar(user.name, "md", "/users/" ~ user.id) %}
{% endfor %}
```

### Variable Assignment

Set variables for reuse:

```twig
{% set total = items.length %}
{% set fullName = user.firstName ~ ' ' ~ user.lastName %}
{% set discount = price * 0.1 %}

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
{% extends "layouts/main" %}

{% block title %}About Us{% endblock %}
{% block content %}
  <h2>About Our Company</h2>
  <p>We are awesome!</p>
{% endblock %}
```

Blocks not overridden in the child will use the parent's default content.

#### Parent Block Fallback

Inside an overriding child block, use `{% parent %}` to inline the parent block's content at that exact position during compilation:

```twig
{% extends "layouts/main" %}

{% block title %}
  Admin | {% parent %}
{% endblock %}
```

If the parent block contains `My Website`, the compiled result is `Admin | My Website`.

Rules:

- Use `{% parent %}` only inside a child block that overrides a parent block.
- It resolves at compile time and may appear more than once in a block.
- In multi-level inheritance, it refers to the **immediate** parent block.

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
{% include "admin::sidebar" %}
{% include "emails::header" %}
```

See [Advanced Topics](04-advanced-topics.md#named-namespaces-addnamespace) for namespace configuration.

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

### Tests

Twig-style tests read as words and compile to registered callables, so they work
under every policy. They can be used anywhere a boolean is expected.

```twig
{# membership — value in a list, substring in a string, key in a mapping #}
{% if 2 in [1, 2, 3] %}…{% endif %}
{% if 'ell' in word %}…{% endif %}
{% if role in user:roles %}…{% endif %}
{% if x not in items %}…{% endif %}

{# string tests #}
{% if name starts with 'Jo' %}…{% endif %}
{% if name ends with 'hn' %}…{% endif %}
{% if name matches '/^J.*n$/' %}…{% endif %}

{# numeric / identity tests #}
{% if n is even %}…{% endif %}
{% if n is odd %}…{% endif %}
{% if n divisible by 3 %}…{% endif %}
{% if a is same as(b) %}…{% endif %}
{% if value is iterable %}…{% endif %}
```

The **absence-tolerant** tests answer without reading their operand, so they are
safe on a name that was never passed to the template:

```twig
{% if name is defined %}…{% endif %}
{% if name is not defined %}…{% endif %}
{% if nickname is null %}…{% endif %}
{% if items is empty %}…{% endif %}
{% if items is not empty %}…{% endif %}
```

**`is defined` asks whether a name holds a value other than `null`.** A name
that was passed as `null` is reported as **not** defined, because the probe is
PHP's `isset()`. Use `is null` to ask about the value instead; the two together
still separate all three states:

| `user`     | `user is defined` | `user is null` |
| ---------- | ----------------- | -------------- |
| absent     | `false`           | `true`         |
| `null`     | `false`           | `true`         |
| `'x'`, `0` | `true`            | `false`        |

A test is an ordinary expression, so it composes with `and`, `or`, `not`, the
ternary, and filters:

```twig
{% if role in user:roles and user is not null %}…{% endif %}
{{ 2 in [1, 2] ? 'yes' : 'no' }}
{{ (name |> lower) is defined ? 'set' : 'unset' }}
```

> The tests `starts with`, `ends with`, `divisible by` and `same as` are two
> words. An unrecognised word after `is` (`x is frobnicated`) is a compile error.

### Bitwise Operators

Use the keyword forms to avoid conflict with the `|` filter-pipe operator:

```twig
{{ flags bor mask }}    {# bitwise OR  — flags | mask  #}
{{ flags band mask }}   {# bitwise AND — flags & mask  #}
{{ flags bxor mask }}   {# bitwise XOR — flags ^ mask  #}
{{ bnot flags }}        {# bitwise NOT — ~flags        #}
{{ flags blsh 2 }}      {# bitwise left shift — flags << 2  #}
{{ flags brsh 2 }}      {# bitwise right shift — flags >> 2  #}
```

| Operator | PHP equivalent | Description         |
| -------- | -------------- | ------------------- |
| `bor`    | `\|`           | Bitwise OR          |
| `band`   | `&`            | Bitwise AND         |
| `bxor`   | `^`            | Bitwise XOR         |
| `bnot`   | `~`            | Bitwise NOT         |
| `blsh`   | `<<`           | Bitwise left shift  |
| `brsh`   | `>>`           | Bitwise right shift |

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

#### vars()

Returns all current template variables:

```twig
{% set allVars = vars() %} {{ allVars |> json }}
```

#### include()

Dynamically render another template at runtime:

```twig
{{ include("partials/card", { title: "Hello", ...vars() }) }}
{{ include(templateName, variables) }}
```

Unlike the `{% include %}` directive, this function:

- Renders at runtime
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

`@context` annotations in comments set the auto-escaping mode for following
expressions. Clarity also detects context inside `<script>` and `<style>` tags;
use an annotation to override the detected mode:

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
{% for widget in dashboard.widgets %}
  {{ include("widgets/" ~ widget.type, {
    title: widget.title, data: widget.data, config: widget.config }) }}
{% endfor %}
```

## Next Steps

- **[Filters and Functions](02-filters-and-functions.md)** — Learn how to transform data
- **[Layout Inheritance](03-layout-inheritance.md)** — Master template reuse patterns
- **[Examples](examples/README.md)** — See complete working examples

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
| `{% else %}` (inside a loop)                 | Runs when the loop is empty |
| `{% endfor %}`                               | End loop                    |
| `{% set variable = value %}`                 | Variable assignment         |
| `{% extends "template" %}`                   | Inherit from layout         |
| `{% block name %}...{% endblock %}`          | Define/override block       |
| `{% include "template" %}`                   | Include another template    |
| `{% macro name(params) %}...{% endmacro %}` | Define a reusable macro     |
| `{% call name(args) %}`                      | Call a macro                |
| `{% parent %}`                              | Inline parent block content |
| `{# comment #}`                              | Template comment            |
| `{# @context js\|css\|html #}`               | Switch escaping context     |

### Operator Summary

| Category      | Operators                                                      |
| ------------- | -------------------------------------------------------------- |
| Comparison    | `==` `!=` `<` `>` `<=` `>=`                                    |
| Logical       | `and` `or` `not`                                               |
| Tests         | `in` `not in` `is defined` `is null` `is empty` `is iterable`  |
|               | `is even` `is odd` `starts with` `ends with` `matches`         |
|               | `divisible by` `same as`                                       |
| Arithmetic    | `+` `-` `*` `/` `%`                                            |
| Bitwise       | `band` `bor` `bxor` `bnot` `blsh` `brsh` `&` `^` `~` `<<` `>>` |
| String        | `~` (concatenation)                                            |
| Ternary       | `condition ? true : false`                                     |
| Null coalesce | `??`                                                           |
| Spread        | `...` (in arrays/objects)                                      |
