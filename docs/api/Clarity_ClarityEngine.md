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

$engine = new ClarityEngine([
   'viewPath' => __DIR__ . '/templates',
   'cachePath' => __DIR__ . '/cache',
]);
# or configure via setters:
$engine = ClarityEngine::create()
   ->setViewPath(__DIR__ . '/templates')
   ->setCachePath(__DIR__ . '/cache');

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

### __construct() · [source](../../src/ClarityEngine.php#L125)

`public function __construct(array $config = []): mixed`

Create a new ClarityEngine instance.

This constructor accepts a single configuration array. Common keys:
- `vars`: array of initial variables available to all views
- `viewPath`: base path for views
- `extension`: file extension (with or without leading dot)
- `layout`: default layout name or null
- `namespaces`: associative array of namespace => path
- `cachePath`: path to compiled template cache (applied after init)
- `debug`: bool to enable debug mode

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | Configuration options for the engine. |

**➡️ Return value**

- Type: mixed


---

### create() · [source](../../src/ClarityEngine.php#L161)

`public static function create(array $config = []): self`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` |  |

**➡️ Return value**

- Type: self


---

### setExtension() · [source](../../src/ClarityEngine.php#L172)

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**➡️ Return value**

- Type: static


---

### getExtension() · [source](../../src/ClarityEngine.php#L186)

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**➡️ Return value**

- Type: string
- Description: Extension including leading dot or empty string.


---

### addNamespace() · [source](../../src/ClarityEngine.php#L200)

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

### getNamespaces() · [source](../../src/ClarityEngine.php#L211)

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**➡️ Return value**

- Type: array
- Description: Associative array of namespace => path mappings.


---

### setViewPath() · [source](../../src/ClarityEngine.php#L223)

`public function setViewPath(string $path): static`

Set the base path for resolving relative view names.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for views. |

**➡️ Return value**

- Type: static


---

### getViewPath() · [source](../../src/ClarityEngine.php#L234)

`public function getViewPath(): string`

Get the currently configured base path for view resolution.

**➡️ Return value**

- Type: string
- Description: Base directory for views.


---

### setLayout() · [source](../../src/ClarityEngine.php#L248)

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

### getLayout() · [source](../../src/ClarityEngine.php#L259)

`public function getLayout(): string|null`

Get the currently configured layout view name.

**➡️ Return value**

- Type: string|null
- Description: Layout name or null when none set.


---

### setVar() · [source](../../src/ClarityEngine.php#L271)

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

### setVars() · [source](../../src/ClarityEngine.php#L285)

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

### setDebugMode() · [source](../../src/ClarityEngine.php#L37)

`public function setDebugMode(bool $debug): static`

Enable or disable debug mode.

In debug mode, compiled templates include additional runtime assertions
(e.g. range-loop safety checks) and the compiled class records
`$debugCompiled = true`.  When this flag changes, any cached template
compiled under the opposite mode is automatically recompiled on next use.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | bool | - | True to enable, false to disable. |

**➡️ Return value**

- Type: static


---

### isDebugMode() · [source](../../src/ClarityEngine.php#L46)

`public function isDebugMode(): bool`

Return whether debug mode is currently enabled.

**➡️ Return value**

- Type: bool


---

### use() · [source](../../src/ClarityEngine.php#L68)

`public function use(Clarity\ModuleInterface $module): static`

Register a module, granting it access to this engine instance so it can
self-register filters, functions, services, and block directives.

Modules are the recommended way to bundle related features (e.g. a full
localization set with filters, a locale stack, and `with_locale` blocks).

```php
$engine->use(new \Clarity\LocalizationModule([
    'locale'            => 'de_DE',
    'translations_path' => __DIR__ . '/locales',
]));
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$module` | [ModuleInterface](Clarity_ModuleInterface.md) | - | Module to register. |

**➡️ Return value**

- Type: static


---

### addInlineFilter() · [source](../../src/ClarityEngine.php#L96)

`public function addInlineFilter(string $name, array $definition): static`

Register an inline filter definition that is compiled directly into the
generated PHP render body (zero runtime call overhead).

The definition must follow the same format as the built-in inline filters:
```php
$engine->addInlineFilter('my_upper', [
    'php' => '\mb_strtoupper((string) {1})',
]);
$engine->addInlineFilter('my_substr', [
    'php' => '\mb_substr((string) {1}, {2}, {3})',
    'params' => ['start', 'length'],
    'defaults' => ['length' => null],
]);
```
Template placeholders: `{1}` for the piped value, `{2}`, `{3}`, … for
additional parameters are declared in `params`.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name. |
| `$definition` | array | - |  |

**➡️ Return value**

- Type: static


---

### addBlock() · [source](../../src/ClarityEngine.php#L121)

`public function addBlock(string $keyword, callable $handler): static`

Register a handler for a custom block directive (e.g. `with_locale`).

The handler is a callable that receives the raw text after the keyword,
source path and line for error messages, and a `$processExpr` callable
that converts a Clarity expression string to a PHP expression string.
It must return a PHP statement string.

```php
$engine->addBlock('with_locale', function(string $rest, string $path, int $line, callable $expr): string {
    return "\$__sv['locale']->push({$expr(trim($rest))});"
});
$engine->addBlock('endwith_locale', fn(...) => "\$__sv['locale']->pop();");
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | The directive keyword in lowercase (e.g. 'with_locale'). |
| `$handler` | callable | - | See [`Registry`](Clarity_Engine_Registry.md) for the expected signature. |

**➡️ Return value**

- Type: static


---

### addService() · [source](../../src/ClarityEngine.php#L139)

`public function addService(string $name, mixed $service): static`

Store a non-callable service object in the registry so that
compiled template render bodies can access it via `$__sv['key']`.

This is primarily used by modules that need shared mutable state (e.g. a
locale stack) accessible both from closures that close over the object
*and* from inline filter PHP templates using `$__sv['key']->method()`.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Key under which the service is accessible. |
| `$service` | mixed | - | Service value (not required to be callable). |

**➡️ Return value**

- Type: static


---

### hasService() · [source](../../src/ClarityEngine.php#L148)

`public function hasService(string $name): bool`

Return true if a service with the given key has been registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### getService() · [source](../../src/ClarityEngine.php#L158)

`public function getService(string $name): mixed`

Retrieve a previously registered service.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: mixed

**⚠️ Throws**

- RuntimeException  if no service with that name exists.


---

### addFilter() · [source](../../src/ClarityEngine.php#L212)

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
- Arrays: `join`, `length`, `first`, `last`, `keys`, `values`, `map`, `filter`, `reduce`
- Dates: `date`, `date_modify`, `format_datetime`
- Other: `json`, `default`, `unicode`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable with signature: fn($value, ...$args): mixed |

**➡️ Return value**

- Type: static
- Description: Fluent interface


---

### addFunction() · [source](../../src/ClarityEngine.php#L228)

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

### setCachePath() · [source](../../src/ClarityEngine.php#L240)

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path to the cache directory. |

**➡️ Return value**

- Type: static


---

### getCachePath() · [source](../../src/ClarityEngine.php#L251)

`public function getCachePath(): string`

Get the currently configured cache directory.

**➡️ Return value**

- Type: string
- Description: Absolute path to the cache directory.


---

### flushCache() · [source](../../src/ClarityEngine.php#L261)

`public function flushCache(): static`

Flush all cached compiled templates.

**➡️ Return value**

- Type: static


---

### render() · [source](../../src/ClarityEngine.php#L311)

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

### renderPartial() · [source](../../src/ClarityEngine.php#L329)

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

### renderLayout() · [source](../../src/ClarityEngine.php#L359)

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

### castToArray() · [source](../../src/ClarityEngine.php#L716)

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
