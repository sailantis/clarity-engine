# Getting Started with Clarity

Clarity is a fast, secure PHP template engine that compiles `.clarity.html` templates into cached PHP classes. It provides a clean, expressive syntax and enforces security at compile time.

## Key Features

- **Sandboxed** — Templates cannot execute arbitrary PHP code
- **Fast** — Compiles to PHP classes with zero runtime overhead after warmup
- **Auto-escaping** — HTML output is automatically escaped by default
- **Expressive Syntax** — Clean, readable template language
- **Template Inheritance** — Reusable layouts with extends and blocks
- **Extensible** — Add custom filters and functions
- **Unicode-aware** — Built-in support for multibyte strings

## Installation

Install Clarity via Composer:

```bash
composer require sailantis/clarity-engine
```

> **Note:** If using Clarity as part of a framework, it may already be included.

## Minimum Requirements

- PHP >= 8.2
- mbstring extension (for Unicode support)

## Basic Usage

### 1. Initialize the Engine

Create a new `ClarityEngine` instance and configure it:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Clarity\ClarityEngine;

$engine = new ClarityEngine();

// Configure the engine
$engine->setViewPath(__DIR__ . '/views');
$engine->setCachePath(__DIR__ . '/cache/clarity');
```

### 2. Create Your First Template

Create a file `views/hello.clarity.html`:

```twig
<!DOCTYPE html>
<html>
  <head>
    <title>{{ pageTitle }}</title>
  </head>
  <body>
    <h1>Hello, {{ userName }}!</h1>
    <p>Welcome to Clarity template engine.</p>
  </body>
</html>
```

### 3. Render the Template

```php
<?php
// Render the template with data
$output = $engine->render('hello', [
    'pageTitle' => 'Getting Started',
    'userName' => 'Developer'
]);

echo $output;
```

The first render compiles the template. Later renders use the cached PHP class.

## Configuration Options

### Essential Settings

```php
// Base directory where templates are stored
$engine->setViewPath(__DIR__ . '/views');

// Directory for compiled PHP cache files (must be writable)
$engine->setCachePath(__DIR__ . '/cache/clarity');

// Default layout template (optional)
$engine->setLayout('layouts/main');

// Override file extension (default: .clarity.html)
$engine->setExtension('.tpl.html');

// What templates may reach (default: Policy::restricted()).
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::unrestricted());                // grant templates full PHP
$engine->setPolicy(Policy::default()                // or grant one thing at a time
    ->allowRule('methodCalls')
    ->allowFunctions('strtoupper', 'count'));

// Deny named PHP functions when called from template expressions.
// This does not inspect calls inside raw `{% php %}` blocks.
$engine->setPolicy(Policy::unrestricted()->denyFunctions('exec', 'system'));
```

Rules grant different levels of PHP access. `rawPhp` and
`Policy::unrestricted()` allow arbitrary PHP; other rules enable specific
constructs. See [The Policy API](09-policy-api.md) for details. Pass the policy
in the constructor as an object or array using the `policy` option.

One rule is on in `Policy::restricted()` as well: `strictTypes` compiles
templates with `declare(strict_types=1)`, so a value of the wrong type at a
filter or function boundary throws instead of being coerced. If an existing
template relies on coercion (most often a `null` reaching a string filter), deny
the rule rather than widen anything else:

```php
$engine->setPolicy(Policy::default()->denyRule('strictTypes'));
```

See [Why strict by default](09-policy-api.md#why-strict-by-default).

### Registering Template Namespaces

Namespaces let you reference templates from additional directories using the `namespace::path` syntax:

```php
// Register a named template directory
$engine->addNamespace('admin',      __DIR__ . '/views/admin');
$engine->addNamespace('emails',     __DIR__ . '/views/emails');
$engine->addNamespace('components', __DIR__ . '/views/components');

// Reference in templates:
// {% include "admin::sidebar" %}
// {% extends "emails::layouts/base" %}
// {{ include("components::card", { title: item:title }) }}
```

Namespaces can also be passed in the constructor alongside other options:

```php
$engine = new ClarityEngine([
    'viewPath'  => __DIR__ . '/views',
    'cachePath' => __DIR__ . '/cache',
    'namespaces' => [
        'admin'  => __DIR__ . '/views/admin',
        'emails' => __DIR__ . '/views/emails',
    ],
]);
```

Unprefixed template names continue to resolve against the base `viewPath`. Each call to `addNamespace()` is chainable and returns `$this`.

### Cache Management

```php
// Get current cache path
$cachePath = $engine->getCachePath();

// Clear all compiled templates (useful during development)
$engine->flushCache();
```

### Registering Custom Filters

Add custom filters to transform data in templates:

```php
// Simple filter
$engine->addFilter('currency', function($value, string $symbol = '€') {
    return $symbol . ' ' . number_format($value, 2);
});

// Use in template: {{ price |> currency }}
// Use with parameter: {{ price |> currency('$') }}
```

### Registering Custom Functions

Add custom functions for use in expressions:

```php
$engine->addFunction('asset', function(string $path) {
    return '/assets/' . ltrim($path, '/');
});

// Use in template: {{ asset('images/logo.png') }}
```

### Using Modules

Modules bundle related filters, functions, and directives into a single plug-in call:

```php
use Clarity\Localization\IntlFormatModule;
use Clarity\Localization\TranslationModule;

// Locale-aware number, date, and currency filters (requires intl extension)
$engine->addModule(new IntlFormatModule(['locale' => 'en_US']));

// Translation filter (t) with file-based catalogs
$engine->addModule(new TranslationModule([
    'locale'            => 'en_US',
    'translations_path' => __DIR__ . '/locales',
]));
```

**[Module reference →](04-advanced-topics.md#modules)**

### Debug Mode

Enable the full debug experience in development — context-aware, masked `dump()`,
the `dump` filter, runtime range-loop safety checks and a render event bus:

```php
$engine->setDebugMode(true);  // enable
$engine->setDebugMode(false); // disable (default)
```

See **[Debug Mode](04-advanced-topics.md#debug-mode)** for what it turns on.

### Domain Router and Composite Loaders

For most projects, use `addNamespace()` (see above) — it manages the underlying `DomainRouterLoader` automatically.

For advanced setups that require extra control (e.g. multiple fallback chains or dynamic loaders), you can set the loader directly:

```php
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;

// Full manual setup:
$engine->setLoader(new DomainRouterLoader(
    [
        'admin'  => new FileLoader(__DIR__ . '/views/admin'),
        'emails' => new FileLoader(__DIR__ . '/views/emails'),
    ],
    fallback: new FileLoader(__DIR__ . '/views'),
));
```

See [Advanced Topics → Template Loaders](04-advanced-topics.md#template-loaders) for the full loader API.

## Quick Template Syntax Overview

### Output Expressions

```twig
{{ variable }}
{{ user:name }}
{{ items[0]:title }}
{{ price |> number(2) }}
{{ description |> upper |> trim }}
```

### Directives

```twig
<!-- Conditionals -->
{% if user:isActive %}
<p>Active user</p>
{% else %}
<p>Inactive user</p>
{% endif %}

<!-- Loops -->
{% for item in items %}
<li>{{ item:name }}</li>
{% endfor %}

<!-- Variable Assignment -->
{% set total = items:length %}

<!-- Includes -->
{% include "partials/header" %}

<!-- Template Inheritance -->
{% extends "layouts/main" %} {% block content %}
<h1>Page Content</h1>
{% endblock %}
```

## Complete Example

Here's a complete working example:

**File: `index.php`**

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Clarity\ClarityEngine;

$engine = new ClarityEngine();
$engine->setViewPath(__DIR__ . '/views');
$engine->setCachePath(__DIR__ . '/cache');
$engine->setLayout('layouts/main');

// Add a custom filter
$engine->addFilter('excerpt', function($text, int $length = 100) {
    return mb_strlen($text) > $length
        ? mb_substr($text, 0, $length) . '...'
        : $text;
});

// Render a page
echo $engine->render('home', [
    'title' => 'Welcome to Clarity',
    'user' => [
        'name' => 'John Doe',
        'role' => 'Developer'
    ],
    'articles' => [
        ['title' => 'Getting Started', 'body' => 'Learn the basics...'],
        ['title' => 'Advanced Features', 'body' => 'Deep dive into...']
    ]
]);
```

**File: `views/layouts/main.clarity.html`**

```twig
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{% block title %}{{ title }}{% endblock %}</title>
  </head>
  <body>
    <header>
      <h1>My Website</h1>
      {% include "partials/nav" %}
    </header>

    <main>{% block content %}{% endblock %}</main>

    <footer>&copy; {{ "now" |> date("Y") }} My Website</footer>
  </body>
</html>
```

**File: `views/home.clarity.html`**

```twig
{% extends "layouts/main" %}
{% block title %}{{ title }} - My Website{%endblock %}
{% block content %}
  <h2>Hello, {{ user:name }}!</h2>
  <p>You are logged in as: <strong>{{ user:role }}</strong></p>

  <h3>Recent Articles</h3>
  <ul>
    {% for article in articles %}
    <li>
      <strong>{{ article:title }}</strong>
      <p>{{ article:body |> excerpt(50) }}</p>
    </li>
    {% endfor %}
  </ul>
{% endblock %}
```

**File: `views/partials/nav.clarity.html`**

```twig
<nav>
  <ul>
    <li><a href="/">Home</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
  </ul>
</nav>
```

## Development Workflow

### During Development

Enable debug mode while developing to get context-aware `dump()` output and
runtime safety checks:

```php
$engine->setDebugMode(true);
```

Inspect values directly in a template:

```twig
<pre>{{ dump(user) }}</pre>
```

Template errors include the template name and line, and are mapped back to the
template source for tools such as Xdebug. Disable debug mode in production:

```php
$engine->setDebugMode(false);
```

Clarity automatically detects changes to template files and recompiles them,
including layouts and included partials. You normally do not need to clear the
cache manually. If you suspect a stale or inconsistent compiled cache while
debugging, you can clear it explicitly:

```php
$engine->flushCache();
```

## Next Steps

Now that you have Clarity up and running, explore these topics:

- **[Template Syntax](01-template-syntax.md)** — Learn all directives, operators, and expressions
- **[Filters and Functions](02-filters-and-functions.md)** — Master data transformation
- **[Layout Inheritance](03-layout-inheritance.md)** — Build reusable page structures
- **[Examples](examples/README.md)** — See complete working examples

