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

```html
<p>{{ message }}</p>
<h1>{{ pageTitle }}</h1>
```

### Auto-Escaping

**All output is automatically HTML-escaped** for security:

```html
{{ userInput }}
<!-- If userInput = "<script>alert('xss')</script>" -->
<!-- Outputs: &lt;script&gt;alert('xss')&lt;/script&gt; -->
```

To output raw HTML (use with caution!), use the `raw` filter:

```html
{{ trustedHtml |> raw }}
```

> **Security Warning:** Only use `raw` with trusted content. Never use it with user input.

### Variable Access

Access variables using dot notation or bracket notation:

```html
<!-- Simple variable -->
{{ name }}

<!-- Object/array property -->
{{ user.name }} {{ user.email }}

<!-- Nested access -->
{{ order.customer.address.city }}

<!-- Array index -->
{{ items[0] }} {{ items[index] }}

<!-- Complex access -->
{{ users[0].profile.avatar }} {{ data.items[currentIndex].title }}
```

**Not allowed:** Direct PHP variable syntax (`$name`) is forbidden.

## Directives

Directives use `{% ... %}` syntax for control flow and template structure.

### Conditional Statements

#### If / Else / Elseif

```html
{% if user.isActive %}
<span class="badge active">Active</span>
{% elseif user.isPending %}
<span class="badge pending">Pending</span>
{% else %}
<span class="badge inactive">Inactive</span>
{% endif %}
```

Single condition:

```html
{% if stock > 0 %}
<button>Add to Cart</button>
{% endif %}
```

### Loops

#### For Loop

Iterate over arrays:

```html
<ul>
  {% for item in items %}
  <li>{{ item.name }} - {{ item.price |> number(2) }}</li>
  {% endfor %}
</ul>
```

#### Loop with Index

Access the loop index (0-based):

```html
{% for product in products %}
<div>Item #{{ loop.index }}: {{ product.name }}</div>
{% endfor %}
```

Available loop variables:

| Variable      | Description                 |
| ------------- | --------------------------- |
| `loop.index`  | Current iteration (0-based) |
| `loop.index1` | Current iteration (1-based) |
| `loop.first`  | True if first iteration     |
| `loop.last`   | True if last iteration      |
| `loop.length` | Total number of items       |

Example using loop variables:

```html
<ul>
  {% for user in users %}
  <li class="{{ loop.first ? 'first' : '' }} {{ loop.last ? 'last' : '' }}">
    {{ loop.index1 }}. {{ user.name }}
  </li>
  {% endfor %}
</ul>
```

#### Range Loops

**Exclusive range** (doesn't include end):

```html
{% for i in 1..10 %} {{ i }} {# Outputs: 1 2 3 4 5 6 7 8 9 #} {% endfor %}
```

**Inclusive range** (includes end):

```html
{% for i in 1...10 %} {{ i }} {# Outputs: 1 2 3 4 5 6 7 8 9 10 #} {% endfor %}
```

**Range with step:**

```html
{% for i in 0...100 step 10 %} {{ i }} {# Outputs: 0 10 20 30 40 50 60 70 80 90
100 #} {% endfor %}
```

**Dynamic ranges:**

```html
{% for i in start...end step increment %} {{ i }} {% endfor %}
```

### Variable Assignment

Set variables for reuse:

```html
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

```html
{% extends "layouts/main" %}
```

Must be the first directive in the template (before any output).

#### Blocks

Define overridable sections:

**Parent template** (`layouts/main.clarity.html`):

```html
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

```html
{% extends "layouts/main" %} {% block title %}About Us{% endblock %} {% block
content %}
<h2>About Our Company</h2>
<p>We are awesome!</p>
{% endblock %}
```

Blocks not overridden in the child will use the parent's default content.

### Includes

#### Static Include

Include another template, sharing the current variable scope:

```html
{% include "partials/header" %}

<main>
  <!-- page content -->
</main>

{% include "partials/footer" %}
```

Included templates are inlined at compile time.

#### Include with Namespaces

```html
{% include "admin::sidebar" %} {% include "emails::header" %}
```

See [Advanced Topics](04-advanced-topics.md#named-namespaces) for namespace configuration.

## Operators

### Comparison Operators

```html
{% if age >= 18 %} {% if status == 'active' %} {% if count != 0 %} {% if price <
100 %} {% if score > 50 %} {% if rating <= 5 %}
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

```html
{% if user.isActive and user.role == 'admin' %} {% if status == 'pending' or
status == 'review' %} {% if not user.isBlocked %}
```

| Operator | Description |
| -------- | ----------- |
| `and`    | Logical AND |
| `or`     | Logical OR  |
| `not`    | Logical NOT |

### Arithmetic Operators

```html
{% set total = price + tax %} {% set discount = price * 0.1 %} {% set remaining
= total - paid %} {% set perItem = total / count %} {% set remainder = total %
10 %}
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

```html
{% set fullName = firstName ~ ' ' ~ lastName %} {% set greeting = 'Hello, ' ~
user.name ~ '!' %}

<p>{{ 'Total: ' ~ total ~ ' items' }}</p>
```

### Ternary Operator

```html
{{ user.isActive ? 'Active' : 'Inactive' }} {{ stock > 0 ? 'In Stock' : 'Out of
Stock' }} {{ age >= 18 ? 'Adult' : 'Minor' }}
```

Syntax: `condition ? valueIfTrue : valueIfFalse`

### Null Coalescing

```html
{{ user.nickname ?? user.name }} {{ customTitle ?? defaultTitle }}
```

Returns the right value if the left is null or undefined.

## Expressions

### Literal Values

```html
{{ 42 }} {{ 3.14 }} {{ true }} {{ false }} {{ null }} {{ "string literal" }} {{
'single quotes' }}
```

### Collection Literals

**Arrays:**

```html
{% set numbers = [1, 2, 3, 4, 5] %} {% set mixed = [true, "text", 42, user.name]
%}
```

**Objects:**

```html
{% set person = { name: "John", age: 30, active: true } %} {% set data = { id:
item.id, title: item.title } %}
```

**Spread operator** in collections:

```html
{% set extended = [1, 2, ...moreNumbers, 99] %} {% set merged = { foo: "bar",
...otherData } %}
```

### Built-in Functions

#### context()

Returns all current template variables:

```html
{% set allVars = context() %} {{ allVars |> json |> raw }}
```

#### include()

Dynamically render another template at runtime:

```html
{{ include("partials/card", { title: "Hello", ...context() }) }} {{
include(templateName, variables) }}
```

Unlike the `{% include %}` directive, this function:

- Renders at runtime (not compile time)
- Can use dynamic template names
- Returns the rendered markup directly
- Accepts custom context variables

Example:

```html
{% for componentType in components %} {{ include("components/" ~ componentType,
{ data: item }) }} {% endfor %}
```

See [Filters and Functions](02-filters-and-functions.md) for custom functions.

## Comments

Comments are removed during compilation and don't appear in output:

```html
{# This is a comment #} {# Multi-line comment Useful for documentation #} {#
TODO: Add pagination here #}
```

## Complex Examples

### Nested Loops

```html
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

```html
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

```html
{% set userData = { fullName: user.firstName ~ ' ' ~ user.lastName, age:
user.birthYear ? (2026 - user.birthYear) : null, isAdult: user.birthYear and
(2026 - user.birthYear) >= 18 } %}

<p>Name: {{ userData.fullName }}</p>
{% if userData.isAdult %}
<p>Age: {{ userData.age }}</p>
{% endif %}
```

### Dynamic Includes

```html
{% for widget in dashboard.widgets %} {{ include("widgets/" ~ widget.type, {
title: widget.title, data: widget.data, config: widget.config }) }} {% endfor %}
```

## What's Not Allowed

Clarity is sandboxed for security. The following are **not permitted**:

❌ Direct PHP variables:

```html
{{ $variable }} {# ERROR #}
```

❌ Arbitrary PHP function calls:

```html
{{ strtoupper(name) }} {# ERROR #}
```

❌ Method calls on objects:

```html
{{ user.getName() }} {# ERROR #}
```

✅ Instead, use filters:

```html
{{ name |> upper }} {# CORRECT #}
```

❌ PHP statements or semicolons:

```html
{{ $x = 5; }} {# ERROR #}
```

✅ Instead, use `{% set %}`:

```html
{% set x = 5 %} {# CORRECT #}
```

## Next Steps

- **[Filters and Functions](02-filters-and-functions.md)** — Learn how to transform data
- **[Layout Inheritance](03-layout-inheritance.md)** — Master template reuse patterns
- **[Examples](../examples/README.md)** — See complete working examples

## Quick Reference

### Directive Summary

| Directive                           | Purpose                  |
| ----------------------------------- | ------------------------ |
| `{% if condition %}`                | Conditional rendering    |
| `{% elseif condition %}`            | Alternative condition    |
| `{% else %}`                        | Fallback case            |
| `{% endif %}`                       | End conditional          |
| `{% for item in array %}`           | Loop over array          |
| `{% for i in start...end %}`        | Range loop               |
| `{% endfor %}`                      | End loop                 |
| `{% set variable = value %}`        | Variable assignment      |
| `{% extends "template" %}`          | Inherit from layout      |
| `{% block name %}...{% endblock %}` | Define/override block    |
| `{% include "template" %}`          | Include another template |
| `{# comment #}`                     | Template comment         |

### Operator Summary

| Category      | Operators                   |
| ------------- | --------------------------- |
| Comparison    | `==` `!=` `<` `>` `<=` `>=` |
| Logical       | `and` `or` `not`            |
| Arithmetic    | `+` `-` `*` `/` `%`         |
| String        | `~` (concatenation)         |
| Ternary       | `condition ? true : false`  |
| Null coalesce | `??`                        |
| Spread        | `...` (in arrays/objects)   |
