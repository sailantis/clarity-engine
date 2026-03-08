# 🧩 Class: ClarityEngine

**Full name:** [Clarity\ClarityEngine](../../src/ClarityEngine.php)

Clarity Template Engine

A fast, secure, and expressive PHP template engine that compiles `.clarity.html`
templates into cached PHP classes. Templates execute in a sandboxed environment
with NO access to arbitrary PHP — they can only use variables passed to render()
and registered filters/functions.

Key Features
------------
- **Compiled & Cached**: Templates compile to PHP classes, leveraging OPcache for performance
- **Secure Sandbox**: No arbitrary PHP execution, strict variable access control
- **Auto-escaping**: Built-in XSS protection with automatic HTML escaping
- **Template Inheritance**: Reusable layouts via extends/blocks
- **Filter Pipeline**: Transform data with chainable filters (|>)
- **Unicode Support**: Full multibyte string handling with NFC normalization

Basic Usage
-----------
```php
use Clarity\ClarityEngine;

$engine = new ClarityEngine();
$engine->setViewPath(__DIR__ . '/templates');
$engine->setCachePath(__DIR__ . '/cache');

// Register a custom filter
$engine->addFilter('currency', fn($v, string $symbol = '€') =>
    $symbol . ' ' . number_format($v, 2)
);

// Render a template
echo $engine->render('welcome', [
    'user' => ['name' => 'John'],
    'balance' => 1234.56
]);
```

Template Syntax
---------------
```twig
{# Output with auto-escaping #}
<h1>Hello, {{ user.name }}!</h1>

{# Filters transform values #}
<p>Balance: {{ balance |> currency('$') }}</p>

{# Control flow #}
{% if user.isActive %}
  <span>Active</span>
{% endif %}

{# Loops #}
{% for item in items %}
  <li>{{ item.name }}</li>
{% endfor %}
```

Template Inheritance
--------------------
```twig
{# layouts/base.clarity.html #}
<!DOCTYPE html>
<html>
  <head><title>{% block title %}Default{% endblock %}</title></head>
  <body>{% block content %}{% endblock %}</body>
</html>

{# pages/home.clarity.html #}
{% extends "layouts/base" %}
{% block title %}Home{% endblock %}
{% block content %}<h1>Welcome!</h1>{% endblock %}
```

Configuration
-------------
- Default template extension: `.clarity.html` (override with setExtension())
- Default cache location: `sys_get_temp_dir()/clarity_cache` (set with setCachePath())
- Cache auto-invalidation: Templates recompile when source files change
- Namespace support: Organize templates with named directories

Security
--------
Templates are sandboxed and cannot:
- Access PHP variables directly ($var forbidden)
- Call arbitrary PHP functions (use filters instead)
- Execute arbitrary code (no eval, backticks, etc.)
- Call methods on objects (objects converted to arrays)

## 🚀 Public methods

### __construct() · [source](../../src/ClarityEngine.php#L119)

`public function __construct(array $vars = []): mixed`

Create a new ViewEngine instance.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | `[]` | Initial variables available to all views. |

**➡️ Return value**

- Type: mixed


---

### setExtension() · [source](../../src/ClarityEngine.php#L136)

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**➡️ Return value**

- Type: static


---

### getExtension() · [source](../../src/ClarityEngine.php#L150)

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**➡️ Return value**

- Type: string
- Description: Extension including leading dot or empty string.


---

### addNamespace() · [source](../../src/ClarityEngine.php#L164)

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Views can be referenced using the syntax "namespace::view.name".

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**➡️ Return value**

- Type: static


---

### getNamespaces() · [source](../../src/ClarityEngine.php#L175)

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**➡️ Return value**

- Type: array
- Description: Associative array of namespace => path mappings.


---

### setViewPath() · [source](../../src/ClarityEngine.php#L187)

`public function setViewPath(string $path): static`

Set the base path for resolving relative view names.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for views. |

**➡️ Return value**

- Type: static


---

### getViewPath() · [source](../../src/ClarityEngine.php#L198)

`public function getViewPath(): string`

Get the currently configured base path for view resolution.

**➡️ Return value**

- Type: string
- Description: Base directory for views.


---

### setLayout() · [source](../../src/ClarityEngine.php#L212)

`public function setLayout(string|null $layout): static`

Set the layout template name to be used when calling `render()`.

The layout will receive a `content` variable containing the
rendered view output.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string\|null | - | Layout view name or null to disable. |

**➡️ Return value**

- Type: static


---

### getLayout() · [source](../../src/ClarityEngine.php#L223)

`public function getLayout(): string|null`

Get the currently configured layout view name.

**➡️ Return value**

- Type: string|null
- Description: Layout name or null when none set.


---

### setVar() · [source](../../src/ClarityEngine.php#L235)

`public function setVar(string $name, mixed $value): static`

Set a single view variable.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Variable name available inside templates. |
| `$value` | mixed | - | Value assigned to the variable. |

**➡️ Return value**

- Type: static


---

### setVars() · [source](../../src/ClarityEngine.php#L249)

`public function setVars(array $vars): static`

Merge multiple variables into the view's variable set.

Later values override earlier ones for the same keys.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | - | Associative array of variables. |

**➡️ Return value**

- Type: static


---

### addFilter() · [source](../../src/ClarityEngine.php#L304)

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Filters transform a piped value and are invoked in templates using pipe syntax:
- Simple filter: `{{ value |> filterName }}`
- Filter with arguments: `{{ value |> filterName(arg1, arg2) }}`
- Chained filters: `{{ value |> filter1 |> filter2 |> filter3 }}`

Filters receive the piped value as the first parameter, followed by any arguments
specified in the template.

**Example: Currency filter**
```php
$engine->addFilter('currency', function($amount, string $symbol = '€') {
    return $symbol . ' ' . number_format($amount, 2);
});
```

Template usage:
```twig
{{ price |> currency }}       {# Output: € 99.99 #}
{{ price |> currency('$') }}  {# Output: $ 99.99 #}
```

**Example: Excerpt filter**
```php
$engine->addFilter('excerpt', function($text, int $length = 100) {
    return mb_strlen($text) > $length
        ? mb_substr($text, 0, $length) . '…'
        : $text;
});
```

Template usage:
```twig
{{ article.body |> excerpt(150) }}
```

**Built-in filters:**
- Text: `upper`, `lower`, `trim`, `truncate`, `escape`, `raw`
- Numbers: `number`, `abs`, `round`, `ceil`, `floor`
- Arrays: `join`, `length`, `first`, `last`, `map`, `filter`, `reduce`
- Dates: `date`
- Other: `json`, `default`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable with signature: fn($value, ...$args): mixed |

**➡️ Return value**

- Type: static
- Description: Fluent interface


---

### addFunction() · [source](../../src/ClarityEngine.php#L320)

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Functions are called directly in templates, e.g. `{{ name(arg) }}`.
This is distinct from filters, which transform a piped value.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**➡️ Return value**

- Type: static


---

### setCachePath() · [source](../../src/ClarityEngine.php#L332)

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path to the cache directory. |

**➡️ Return value**

- Type: static


---

### getCachePath() · [source](../../src/ClarityEngine.php#L343)

`public function getCachePath(): string`

Get the currently configured cache directory.

**➡️ Return value**

- Type: string
- Description: Absolute path to the cache directory.


---

### flushCache() · [source](../../src/ClarityEngine.php#L353)

`public function flushCache(): static`

Flush all cached compiled templates.

**➡️ Return value**

- Type: static


---

### render() · [source](../../src/ClarityEngine.php#L403)

`public function render(string $view, array $vars = []): string`

Render a view template and return the result as a string.

If a layout is configured via setLayout(), the view is first rendered and then
wrapped in the layout. The layout receives the rendered content in the `content`
variable.

Templates are automatically compiled to cached PHP classes. The cache is
automatically invalidated when source files change.

**Basic rendering:**
```php
$html = $engine->render('welcome', [
    'user' => ['name' => 'John', 'email' => 'john@example.com'],
    'title' => 'Welcome Page'
]);
```

**With layout:**
```php
$engine->setLayout('layouts/main');
$html = $engine->render('pages/dashboard', [
    'stats' => $dashboardStats
]);
// The layout receives 'content' variable with rendered 'pages/dashboard'
```

**Without layout (override):**
```php
$engine->setLayout(null); // Temporarily disable layout
$partial = $engine->render('partials/widget', ['data' => $widgetData]);
```

**Namespaced templates:**
```php
$engine->addNamespace('admin', __DIR__ . '/admin_templates');
$html = $engine->render('admin::dashboard', $data);
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to render. Can include namespace prefix (e.g. 'admin::dashboard'). |
| `$vars` | array | `[]` | Variables to pass to the template. Objects are automatically converted to arrays. |

**➡️ Return value**

- Type: string
- Description: Rendered HTML/output.

**⚠️ Throws**

- [ClarityException](Clarity_ClarityException.md)  If template not found or compilation fails.


---

### renderPartial() · [source](../../src/ClarityEngine.php#L421)

`public function renderPartial(string $view, array $vars = []): string`

Render a partial view (without applying a layout) and return the output.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to resolve and render. |
| `$vars` | array | `[]` | Variables for this render call. |

**➡️ Return value**

- Type: string
- Description: Rendered HTML/output.


---

### renderLayout() · [source](../../src/ClarityEngine.php#L451)

`public function renderLayout(string $layout, string $content, array $vars = []): string`

Render a layout template wrapping provided content.

The layout receives the rendered view in the `content` variable.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string | - | Layout view name. |
| `$content` | string | - | Previously rendered content. |
| `$vars` | array | `[]` | Additional variables to pass to the layout. |

**➡️ Return value**

- Type: string
- Description: Rendered layout output.



---

[Back to the Index ⤴](README.md)
