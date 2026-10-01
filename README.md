![Clarity Logo](docs/images/clarity-engine-logo.svg)

> **A fast, secure, and expressive PHP template engine** – Maximum performance, whether sandboxed and secure or with the full power of PHP.

---

## Features

- **Compiled & Cached** – Templates compile to PHP classes and leverage OPcache for blazing-fast rendering
- **Secure Sandbox** – No arbitrary PHP execution by default; templates are strictly sandboxed with controlled access
- **Opt-In PHP Mode** – Disable the sandbox to give templates the full power of PHP (any function call or filter, method calls, `{% php %}` blocks)
- **Expressive Syntax** – Clean, readable template syntax inspired by modern template engines
- **Twig-Style Tests** – `in`, `is defined`, `starts with`, `matches`, `divisible by`, and more, with absence-tolerant `defined`/`null`/`empty`
- **Whitespace Control** – `{%- … -%}` trims whitespace around a tag
- **Loop Fallbacks** – `{% for %} … {% else %} … {% endfor %}` renders the `else` branch when the sequence is empty
- **Template Inheritance** – Reusable layouts with `extends` and `blocks` for DRY template architecture
- **Macros** – Define reusable template fragments with parameters and call them inline
- **Extensible** — Custom filters, functions, inline filters, block directives, and loader plugins
- **Modules** – Bundle filters, functions, and directives into self-registering plug-ins
- **Auto-escaping** – Built-in XSS protection with context-aware automatic HTML/JS/CSS escaping
- **Unicode Support** – Full multibyte string handling with transparent normalization
- **Zero Dependencies** – Standalone engine with no external dependencies beyond PHP 8.2+

---

## Installation

```bash
composer require sailantis/clarity-engine
```

**Requirements:** PHP 8.2 or higher

---

## Quick Start

### Basic Setup

```php
<?php
require_once 'vendor/autoload.php';

use Clarity\ClarityEngine;

// Initialize the engine
$engine = new ClarityEngine([
    'viewPath'   => __DIR__ . '/views',
]);

// Render a template. The second argument is the template's scope.
echo $engine->render('welcome', [
    'title' => 'Welcome to Clarity',
    'user'  => (object) ['name' => 'Developer'],   // object -> {{ user.name }}
    // 'user' => ['name' => 'Developer'],          // array  -> {{ user:name }}
]);
```

### Your First Template

**views/welcome.clarity.html:**

```twig
<!DOCTYPE html>
<html>
  <head>
    <title>{{ title }}</title>
  </head>
  <body>
    <h1>Hello, {{ user.name }}!</h1>
    <p>The current time is {{ "now" |> date("H:i:s") }}</p>
  </body>
</html>
```

That's it! Clarity automatically compiles and caches your template.

### What a Template May Reach

Every template compiles under a **policy**: a set of capabilities plus two
allowlists. The default is the most restrictive one, and the syntax is the same
in all of them — only what a template may _reach_ changes:

```twig
{# Always available #}
<h1>Hello, {{ user.name }}!</h1>       {# object property #}
<p>{{ settings:tagline }}</p>          {# array key; {{ settings['tagline'] }} also works #}
{{ items[0] }}                         {# index #}
{{ $user->name }}                      {# property via the `$` sigil #}
{{ "now" |> date("H:i:s") }}           {# registered filters and functions #}
{{ $missing is defined }}              {# operator tests #}

{# Granted by a capability #}
{{ strtoupper(name) }}                 {# any PHP function #}
{{ 'ab' |> strtoupper }}               {# any function as a filter step #}
{{ $user->greet() }}                   {# methodCalls #}
{% php echo "Hi" %}                    {# rawPhp #}
{{ $_SERVER['HTTP_HOST'] }}            {# superglobals #}
{{ new DateTime("now") }}              {# newExpressions #}
{{ DateTime::ATOM }}                   {# staticCalls #}
```

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::sandboxed());   // the default
$engine->setPolicy(Policy::open());        // everything on
$engine->setPolicy(Policy::custom()        // grant one thing, not all
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count'));
```

`Policy::open()` is the full-power **PHP mode** and is equivalent to executing
arbitrary PHP. A `Policy::custom()` grant is a real grant too — see
[the security model](docs/04-advanced-topics.md#security-model) and
[the policy reference](docs/09-policy-api.md).

---

## Documentation

### For Template Authors

Start here if you're writing templates:

- **[Getting Started](docs/00-getting-started.md)** – Installation, setup, and your first template
- **[Template Syntax](docs/01-template-syntax.md)** – Variables, directives, operators, and control flow
- **[Filters & Functions](docs/02-filters-and-functions.md)** – Transform data with built-in and custom filters
- **[Layout Inheritance](docs/03-layout-inheritance.md)** – Reusable layouts with extends and blocks

### For Developers

Integration and advanced topics:

- **[Advanced Topics](docs/04-advanced-topics.md)** – Namespaces, caching, auto-escaping, and Unicode
- **[Best Practices](docs/05-best-practices.md)** – Organization, security, performance, and testing
- **[Troubleshooting](docs/06-troubleshooting.md)** – Common errors and debugging techniques

### Reference

- **[API Documentation](docs/api/)** – Auto-generated API reference for all classes
- **[Examples](docs/examples/)** – Runnable template examples demonstrating features
- **[Guide Index](docs/README.md)** – Complete documentation index

---

### Output & Variables

```twig
{{ expression }}                 {# Output with auto-escaping #}
{{ expression | raw }}           {# Output raw HTML (no escaping) #}
{{ expression |> raw }}          {# Same — both | and |> are filter pipes #}
{{ user:name }}                  {# Array key #}
{{ user.name }}                  {# Object property #}
{{ items[0] }}                   {# Array index #}
{{ user[var] }}                  {# Dynamic array key #}
{{ user{var} }}                  {# Dynamic property #}
{{ firstName ~ ' ' ~ lastName }} {# String concatenation #}
```

### Control Flow

```twig
{% if condition %}...{% elseif other %}...{% else %}...{% endif %}

{% for item in items %}
  {{ item:name }}
{% endfor %}

{% for key, value in assocArray %}               {# Loop with key variable #}
  {{ key }}: {{ value }}
{% endfor %}

{% for i in 1..10 %}{{ i }}{% endfor %}          {# Range: 1 to 10 (inclusive) #}
{% for i in 1...10 %}{{ i }}{% endfor %}         {# Range: 1 to 9 (exclusive) #}
{% for i in 0..100 step 10 %}{{ i }}{% endfor %} {# With step #}

{% set total = len(items) %}
```

### Macros

```twig
{% macro @card(title, body) %}
<div class="card"><h3>{{ title }}</h3><p>{{ body }}</p></div>
{% endmacro %}

{% @card("Welcome", intro) %}
{% @card(article.title, article.excerpt) %}
```

### Filters

```twig
{{ text | upper }}
{{ text |> upper }}                          {# both | and |> are equivalent #}
{{ price | number(2) }}
{{ timestamp | date('Y-m-d H:i') }}
{{ "Hello, %s!" | sprintf(user.name) }}
{{ tags | join(', ') }}
{{ users | map(u => u.name) | join(', ') }}  {# Lambda expression #}
{{ items | filter(i => i.active) | length }}
{{ title | slug }}                           {# URL-friendly slug #}
{{ html | striptags }}                       {# Strip HTML tags #}
```

Common filters: `upper`, `lower`, `trim`, `length`, `number`, `date`, `sprintf`, `json`, `join`, `split`, `slug`, `map`, `filter`, `reduce`, `default`, `empty`, `striptags`, `escape`, `raw`

**[See all filters and detailed syntax →](docs/02-filters-and-functions.md)**

### Template Inheritance

```twig
{# layouts/base.clarity.html #}
<!DOCTYPE html>
<html>
  <head>
    <title>{% block title %}Default Title{% endblock %}</title>
  </head>
  <body>
    {% block content %}{% endblock %}
  </body>
</html>

{# pages/home.clarity.html #}
{% extends "layouts/base" %}
{% block title %}Home Page{% endblock %}
{% block content %}
  <h1>Welcome!</h1>
{% endblock %}
```

### Includes

```twig
{% include "partials/header" %}                {# Static include #}
{{ include("widgets/card", { title: "Hi" }) }} {# Dynamic include with context #}
```

**[Full syntax reference →](docs/01-template-syntax.md)**

---

## Configuration

Configure the engine with these methods:

```php
// Initialize with config array
$engine = new ClarityEngine([
    'viewPath' => __DIR__ . '/views',
    'cachePath' => __DIR__ . '/cache',
]);

// Or configure via setters
$engine = ClarityEngine::create()
    ->setViewPath(__DIR__ . '/views')
    ->setCachePath(__DIR__ . '/cache'); // Default: sys temp + /clarity_cache

// Additional configuration
$engine->setExtension('.tpl.html');           // Default: .clarity.html

// Register named namespaces (convenience method)
$engine->addNamespace('admin',  __DIR__ . '/views/admin');
$engine->addNamespace('emails', __DIR__ . '/views/emails');
// Templates with a namespace prefix: {% include "admin::sidebar" %}
// Unprefixed templates still resolve via the base viewPath.

// Namespaces can also be passed in the constructor:
// new ClarityEngine(['namespaces' => ['admin' => __DIR__ . '/views/admin']]);

// For advanced multi-source setups, set a loader directly:
$engine->setLoader(new \Clarity\Template\DomainRouterLoader(
    ['admin' => new \Clarity\Template\FileLoader('/path/to/admin/views')],
    fallback: new \Clarity\Template\FileLoader('/path/to/views'),
));
$engine->setDebugMode(true);                  // Runtime safety checks (dev only)

// Add custom filter
$engine->addFilter('currency', fn($v) => '€ ' . number_format($v, 2));
// Clear compiled templates
$engine->flushCache();

// Modules: bundle filters, functions, and directives
$engine->use(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));
$engine->use(new \Clarity\Localization\TranslationModule([
    'locale'            => 'en_US',
    'translations_path' => __DIR__ . '/locales',
]));
```

**[Configuration guide →](docs/00-getting-started.md#configuration-options)**

---

## Security

Clarity is sandboxed by default:

- **No arbitrary PHP execution** – Templates cannot call PHP functions or access global state
- **Auto-escaping by default** – All output is HTML-escaped to prevent XSS attacks
- **Compile-time validation** – Syntax errors caught during compilation, not at runtime
- **Object safety** – Objects stay objects: `a.b` reads a public property and `a:b` reads an array key, so method calls are unreachable from templates and PHP visibility rules apply. Container operations read an object's public properties, and the `date` filter accepts `DateTimeInterface` directly
- **Controlled lambdas** – Lambda expressions can only use registered filters

### Policies

What a template may reach is decided by a policy — a set of capabilities plus two
allowlists, all resolved at **compile time**. The default is sandboxed and
unreachable from PHP:

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::open());        // full PHP: any function, methods, raw PHP
$engine->setPolicy(Policy::custom()
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count'));
```

A policy that grants `rawPhp`, `phpVariables` or `methodCalls` is
**equivalent to executing arbitrary PHP** and disables every guarantee listed
above. Use it only for templates written and reviewed by trusted authors.

Templates record a digest of the policy that compiled them and are recompiled
automatically when it changes — including when a single allowlist entry is added
or removed. `denyFunctions()` adds guardrails on top of an open policy; nothing
is denied by default, because an open policy is already the security decision.

**[The policy reference →](docs/09-policy-api.md)**
**[Security best practices →](docs/05-best-practices.md#security-best-practices)**

---

## Performance

Clarity is designed for speed. Templates compile to native PHP classes and leverage OPcache for optimal performance:

- **Compiled templates** – One-time compilation to PHP, then served from OPcache
- **Auto-invalidation** – Cache automatically refreshed when templates change
- **Zero runtime overhead** – Inheritance resolved at compile time
- **Minimal memory footprint** – Efficient compilation with predictable memory usage

### Benchmark Results

Clarity is measured against the other mainstream PHP template engines rendering
the same page, on the same machine and PHP build. The two charts below are
generated from the benchmark.

![Per-render time](docs/images/benchmarks/mixed-render-time.svg)

The dot is the median render and the caps bound the fastest observation and p95,
so an engine that is usually fast but occasionally slow looks different from one
that is uniformly slower.

![Memory retained per run](docs/images/benchmarks/mixed-memory.svg)

Measured in a fresh process per engine, so no engine inherits another's
footprint. The bar runs from the floor that engine costs to have loaded (left
cap) to the peak it reached (right cap), and the dot is what it still holds once
the run is done — the figure a serving process actually carries.

The full comparison — every shape, every chart, and the environment and engine
versions this run recorded — is in the [benchmark
report](docs/08-benchmark.md), with the public version of the same data at
<https://sailantis.github.io/azera-competition/benchmarks/view-engine.html>.

**[Performance optimization guide →](docs/05-best-practices.md#performance-best-practices)**

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Running Tests

```bash
composer install
composer test
```

Or run PHPUnit directly:

```bash
php vendor/bin/phpunit
```

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## Links

- **[Documentation](docs/README.md)** – Complete guide index
- **[Examples](docs/examples/)** – Runnable example templates
- **[API Reference](docs/api/)** – Auto-generated API documentation
- **[GitHub Issues](https://github.com/sailantis/clarity-engine/issues)** – Report bugs or request features

---

Built with ❤️ for developers who value security and performance
