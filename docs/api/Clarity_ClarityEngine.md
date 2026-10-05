# Class: ClarityEngine

**Full name:** [Clarity\ClarityEngine](../../src/ClarityEngine.php)

Clarity Template Engine

A fast, secure, and expressive PHP template engine for `.clarity.html` templates.
Templates are sandboxed by default — they can only use variables passed to
render() and registered filters/functions — or run in PHP mode (sandbox disabled)
with the full power of PHP. Both modes deliver maximum performance.

Key Features
------------
- **Compiled & Cached**: Templates compile to PHP classes, leveraging OPcache for performance
- **Secure Sandbox**: No arbitrary PHP execution, strict variable access control
- **Opt-In PHP Mode**: A policy can grant templates the full power of PHP
  (any function, method calls, raw `{% php %}` tags)
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
Templates are sandboxed by default and cannot:
- Access PHP variables directly ($var forbidden)
- Call arbitrary PHP functions (use filters instead)
- Execute arbitrary code (no eval, backticks, etc.)
- Call methods on objects

What a template may reach is decided by a [`Policy`](Clarity_Engine_Policy.md): a set
of rules plus two allowlists, resolved entirely at compile time.  An
application that needs one PHP function grants it without giving up the
sandbox (see Policy::default()); granting the rules that reach PHP at
all (rawPhp, phpVariables, methodCalls) is equivalent to executing arbitrary
PHP and is intended for templates written by trusted authors only.

## Public methods

### __construct() · <small>[🗎](../../src/ClarityEngine.php#L139)</small>

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
- `policy`: what templates may reach — a [`Policy`](Clarity_Engine_Policy.md) or
  the array form it accepts. Sandboxed by default; `Policy::unrestricted()`
  is the full-power PHP mode.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | Configuration options for the engine. |

**Return value**

- Type: `mixed`


---

### create() · <small>[🗎](../../src/ClarityEngine.php#L183)</small>

`public static function create(array $config = []): self`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` |  |

**Return value**

- Type: `self`


---

### setLayout() · <small>[🗎](../../src/ClarityEngine.php#L197)</small>

`public function setLayout(string|null $layout): static`

Set the layout template name to be used when calling `render()`.

The layout will receive a `content` variable containing the
rendered view output.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string\|null | - | Layout view name or null to disable. |

**Return value**

- Type: `static`


---

### getLayout() · <small>[🗎](../../src/ClarityEngine.php#L208)</small>

`public function getLayout(): string|null`

Get the currently configured layout view name.

**Return value**

- Type: `string`|`null`
- Description: Layout name or null when none set.


---

### setVar() · <small>[🗎](../../src/ClarityEngine.php#L220)</small>

`public function setVar(string $name, mixed $value): static`

Set a single view variable.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Variable name available inside templates. |
| `$value` | mixed | - | Value assigned to the variable. |

**Return value**

- Type: `static`


---

### setVars() · <small>[🗎](../../src/ClarityEngine.php#L234)</small>

`public function setVars(array $vars): static`

Merge multiple variables into the view's variable set.

Later values override earlier ones for the same keys.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | - | Associative array of variables. |

**Return value**

- Type: `static`


---

### setDebugMode() · <small>[🗎](../../src/ClarityEngineTrait.php#L83)</small>

`public function setDebugMode(Clarity\Debug\DumpOptions|bool|null $debug = true): static`

Turn debug mode on or off — the single debug switch.

```php
$engine->setDebugMode(true);                       // full debug, defaults
$engine->setDebugMode(new DumpOptions(maxDepth: 3));
$engine->setDebugMode(false);                      // production
```

Turning it ON installs the whole debug experience in one step, and
turning it OFF removes all of it:

- compiler-level runtime assertions (range-loop safety checks);
- `dump()` rendered by the context-aware renderers — an HTML tree in HTML,
  a `;/* DEBUG_DUMP *\/` comment in JS, and a `/* DEBUG_DUMP *\/` comment
  in CSS — with sensitive keys masked;
- `{{ x |> dump }}`, which dumps the piped value at the pipe position and
  still yields it (`{{ x |> dump |> length }}` measures x);
- a [`DebugEventBus`](Clarity_Debug_DebugEventBus.md) emitting `template.resolve`, `template.compile`
  and `template.render`;
- the HTML debug panel, when `DumpOptions::$showPanel` is set.

Passing [`DumpOptions`](Clarity_Debug_DumpOptions.md) is shorthand for "on, with these options" —
`$debug instanceof DumpOptions` and `$debug === null` both mean "on".
`$debug === false` is exactly `disableDebug()`.

`dd()` is the one exception: it is never pruned, so the registry refuses
it while debug is off instead of dumping raw, unmasked values.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | [DumpOptions](Clarity_Debug_DumpOptions.md)\|bool\|null | `true` | True/options to enable, false to disable. |

**Return value**

- Type: `static`


---

### isDebugMode() · <small>[🗎](../../src/ClarityEngineTrait.php#L132)</small>

`public function isDebugMode(): bool`

Return whether debug mode is currently enabled.

**Return value**

- Type: `bool`


---

### setPolicy() · <small>[🗎](../../src/ClarityEngineTrait.php#L159)</small>

`public function setPolicy(Clarity\Engine\Policy|array $policy): static`

Set what compiled templates are allowed to reach.

A policy is a set of rules plus two allowlists; see
[`Policy`](Clarity_Engine_Policy.md).  Start from a preset and change what you
mean to change:

```php
$engine->setPolicy(Policy::unrestricted());
$engine->setPolicy(Policy::default()
    ->allowRule('methodCalls')
    ->allowFunctions('strtoupper', 'count'));
```

SECURITY: a policy that grants `rawPhp`, `phpVariables` or
`methodCalls` is equivalent to executing arbitrary PHP and is intended for
templates written by trusted authors only.  Templates compiled under one
policy are automatically recompiled under another.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$policy` | [Policy](Clarity_Engine_Policy.md)\|array | - | A policy. |

**Return value**

- Type: `static`


---

### getPolicy() · <small>[🗎](../../src/ClarityEngineTrait.php#L176)</small>

`public function getPolicy(): Clarity\Engine\Policy`

The policy templates are currently compiled under.

Always a real object: a freshly built engine answers with
[`Policy::restricted()`](Clarity_Engine_Policy.md#restricted).  Use it for coarse questions rather than
keeping a second flag that could disagree with it — `getPolicy()->isSandboxed()`
answers what the old `isSandboxed()` answered.

**Return value**

- Type: [Policy](Clarity_Engine_Policy.md)


---

### isSandboxed() · <small>[🗎](../../src/ClarityEngineTrait.php#L187)</small>

`public function isSandboxed(): bool`

Whether the current policy lets templates reach PHP at all.

Kept because it reads better than `getPolicy()->allowsPhp()` at a call site
that only wants the coarse answer.

**Return value**

- Type: `bool`


---

### enableDebug() · <small>[🗎](../../src/ClarityEngineTrait.php#L208)</small>

`public function enableDebug(Clarity\Debug\DumpOptions|null $opts = null): static`

Enable full debug mode.

**Deprecated**: Use {@see \setDebugMode()} — the two debug entry points have
            been unified, and `setDebugMode(true)` (or passing
            {@see \DumpOptions}) now installs exactly what this method did.
            Kept as an alias so existing code keeps working.

```php
$engine->enableDebug();   // default options
$engine->enableDebug(new DumpOptions(showPanel: true, maxDepth: 4));
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md)\|null | `null` | Customise depth, masking, panel, etc. |

**Return value**

- Type: `static`


---

### disableDebug() · <small>[🗎](../../src/ClarityEngineTrait.php#L221)</small>

`public function disableDebug(): static`

Disable debug mode and tear down everything it installed: the event bus,
the panel, and the registry's dump/dd handlers.

**Deprecated**: Use {@see setDebugMode(false)} instead.

**Return value**

- Type: `static`


---

### getDebugBus() · <small>[🗎](../../src/ClarityEngineTrait.php#L229)</small>

`public function getDebugBus(): Clarity\Debug\DebugEventBus|null`

Return the active DebugEventBus, or null when debug mode is off.

**Return value**

- Type: [DebugEventBus](Clarity_Debug_DebugEventBus.md)|`null`


---

### getDebugPanel() · <small>[🗎](../../src/ClarityEngineTrait.php#L237)</small>

`public function getDebugPanel(): Clarity\Debug\HtmlDebugPanel|null`

Return the active HtmlDebugPanel, or null when disabled.

**Return value**

- Type: [HtmlDebugPanel](Clarity_Debug_HtmlDebugPanel.md)|`null`


---

### setViewPath() · <small>[🗎](../../src/ClarityEngineTrait.php#L248)</small>

`public function setViewPath(string $path): static`

Set the base path for resolving relative template names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**Return value**

- Type: `static`


---

### getViewPath() · <small>[🗎](../../src/ClarityEngineTrait.php#L274)</small>

`public function getViewPath(): string`

Get the currently configured base path for view resolution.

**Return value**

- Type: `string`
- Description: Base directory for views.


---

### setExtension() · <small>[🗎](../../src/ClarityEngineTrait.php#L285)</small>

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**Return value**

- Type: `static`


---

### getExtension() · <small>[🗎](../../src/ClarityEngineTrait.php#L302)</small>

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**Return value**

- Type: `string`
- Description: Extension including leading dot or empty string.


---

### addNamespace() · <small>[🗎](../../src/ClarityEngineTrait.php#L316)</small>

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Views can be referenced using the syntax "namespace::view.name".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**Return value**

- Type: `static`


---

### getNamespaces() · <small>[🗎](../../src/ClarityEngineTrait.php#L343)</small>

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**Return value**

- Type: `array`
- Description: Associative array of namespace => path mappings.


---

### use() · <small>[🗎](../../src/ClarityEngineTrait.php#L365)</small>

`public function use(Clarity\ModuleInterface $module): static`

Register a module, granting it access to this engine instance so it can
self-register filters, functions, services, and directives.

Modules are the recommended way to bundle related features (e.g. a full
localization set with filters, a locale stack, and `with_locale` directives).

```php
$engine->use(new \Clarity\LocalizationModule([
    'locale'            => 'de_DE',
    'translations_path' => __DIR__ . '/locales',
]));
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$module` | [ModuleInterface](Clarity_ModuleInterface.md) | - | Module to register. |

**Return value**

- Type: `static`


---

### addInlineFilter() · <small>[🗎](../../src/ClarityEngineTrait.php#L393)</small>

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

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name. |
| `$definition` | array | - |  |

**Return value**

- Type: `static`


---

### addDirective() · <small>[🗎](../../src/ClarityEngineTrait.php#L438)</small>

`public function addDirective(string $keyword, callable $handler, Clarity\Engine\Directive|null $directive = null): static`

Register a handler for a custom directive (e.g. `with_locale`).

The handler is a callable that receives the raw text after the keyword, a
[`TemplateLocation`](Clarity_Template_TemplateLocation.md) for error messages, and a `$processExpr` callable
that converts a Clarity expression string to a PHP expression string.
It must return a PHP statement string.

```php
$engine->addDirective('with_locale', function(string $rest, TemplateLocation $at, callable $expr): string {
    return "\$__c_sv['locale']->push({$expr(trim($rest))});"
});
$engine->addDirective('endwith_locale', fn(...) => "\$__c_sv['locale']->pop();");
```

Paired directives
-----------------
A directive that wraps a body declares its parts with a [`Directive`](Clarity_Engine_Directive.md)
whose factory name states the role:
```php
$engine->addDirective('cache',      $openHandler,   Directive::opens('endcache', 'cache_else'));
$engine->addDirective('cache_else', $branchHandler, Directive::branches('cache'));
$engine->addDirective('endcache',   $closeHandler,  Directive::closes('cache'));
```
The compiler then rejects an unclosed `{% cache %}`, a stray `{% endcache %}`,
a close that crosses another construct, and a branch tag used outside its
construct — all at compile time, naming the template and line.

A leaf that may only appear within a construct says so with `inside()`, which
asserts no structure — the tag stays an ordinary directive:
```php
$engine->addDirective('cache_control', $leafHandler, Directive::inside('cache'));
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | The directive keyword in lowercase (e.g. 'with_locale'). |
| `$handler` | callable | - | See [`Registry`](Clarity_Engine_Registry.md) for the expected signature. |
| `$directive` | [Directive](Clarity_Engine_Directive.md)\|null | `null` | Omit for an ordinary directive; see [`Directive`](Clarity_Engine_Directive.md). |

**Return value**

- Type: `static`


---

### addService() · <small>[🗎](../../src/ClarityEngineTrait.php#L456)</small>

`public function addService(string $name, mixed $service): static`

Store a service object in the registry so that compiled template render
bodies can access it via `$__c_sv['key']`.

This is primarily used by modules that need shared mutable state (e.g. a
locale stack) accessible both from closures that close over the object
*and* from inline filter PHP templates using `$__c_sv['key']->method()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Key under which the service is accessible. |
| `$service` | mixed | - | Service value, can be of any type. |

**Return value**

- Type: `static`


---

### hasService() · <small>[🗎](../../src/ClarityEngineTrait.php#L465)</small>

`public function hasService(string $name): bool`

Return true if a service with the given key has been registered.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### getService() · <small>[🗎](../../src/ClarityEngineTrait.php#L475)</small>

`public function getService(string $name): mixed`

Retrieve a previously registered service.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `mixed`

**Throws**

- RuntimeException  if no service with that name exists.


---

### addFilter() · <small>[🗎](../../src/ClarityEngineTrait.php#L529)</small>

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

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable with signature: fn($value, ...$args): mixed |

**Return value**

- Type: `static`
- Description: Fluent interface


---

### addFunction() · <small>[🗎](../../src/ClarityEngineTrait.php#L545)</small>

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Functions are called directly in templates, e.g. `{{ name(arg) }}`.
This is distinct from filters, which transform a piped value.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**Return value**

- Type: `static`


---

### setLoader() · <small>[🗎](../../src/ClarityEngineTrait.php#L557)</small>

`public function setLoader(Clarity\Template\TemplateLoader $loader): static`

Set a custom template loader, replacing the default FileLoader.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - | The loader to use. |

**Return value**

- Type: `static`


---

### getLoader() · <small>[🗎](../../src/ClarityEngineTrait.php#L570)</small>

`public function getLoader(): Clarity\Template\TemplateLoader`

Return the active template loader, lazily creating a FileLoader if none
has been set explicitly.

**Return value**

- Type: [TemplateLoader](Clarity_Template_TemplateLoader.md)


---

### setCachePath() · <small>[🗎](../../src/ClarityEngineTrait.php#L609)</small>

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path to the cache directory. |

**Return value**

- Type: `static`


---

### getCachePath() · <small>[🗎](../../src/ClarityEngineTrait.php#L620)</small>

`public function getCachePath(): string`

Get the currently configured cache directory.

**Return value**

- Type: `string`
- Description: Absolute path to the cache directory.


---

### flushCache() · <small>[🗎](../../src/ClarityEngineTrait.php#L630)</small>

`public function flushCache(): static`

Flush all cached compiled templates.

**Return value**

- Type: `static`


---

### render() · <small>[🗎](../../src/ClarityEngineTrait.php#L681)</small>

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

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to render. Can include namespace prefix (e.g. 'admin::dashboard'). |
| `$vars` | array | `[]` | Variables to pass to the template. Objects stay objects: `a.b`<br>reads a public property while `a:b` reads an array key. |

**Return value**

- Type: `string`
- Description: Rendered HTML/output.

**Throws**

- [ClarityException](Clarity_ClarityException.md)  If template not found or compilation fails.


---

### renderPartial() · <small>[🗎](../../src/ClarityEngineTrait.php#L703)</small>

`public function renderPartial(string $view, array $vars = []): string`

Render a partial view (without applying a layout) and return the output.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to resolve and render. |
| `$vars` | array | `[]` | Variables for this render call. |

**Return value**

- Type: `string`
- Description: Rendered HTML/output.


---

### renderLayout() · <small>[🗎](../../src/ClarityEngineTrait.php#L729)</small>

`public function renderLayout(string $layout, string $content, array $vars = []): string`

Render a layout template wrapping provided content.

The layout receives the rendered view in the `content` variable.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string | - | Layout view name. |
| `$content` | string | - | Previously rendered content. |
| `$vars` | array | `[]` | Additional variables to pass to the layout. |

**Return value**

- Type: `string`
- Description: Rendered layout output.



---

[Back to the Index ⤴](README.md)
