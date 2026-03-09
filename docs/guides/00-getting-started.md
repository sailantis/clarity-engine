# Getting Started with Clarity

Clarity is a fast, secure PHP template engine that compiles `.clarity.html` templates into cached PHP classes. It provides a clean, expressive syntax while maintaining strict security through sandboxing.

## Key Features

- **🔒 Sandboxed** — Templates cannot execute arbitrary PHP code
- **⚡ Fast** — Compiles to PHP classes with zero runtime overhead after warmup
- **🛡️ Auto-escaping** — HTML output is automatically escaped by default
- **🎨 Expressive Syntax** — Clean, readable template language
- **📦 Template Inheritance** — Reusable layouts with extends and blocks
- **🔧 Extensible** — Add custom filters and functions
- **🌐 Unicode-aware** — Built-in support for multibyte strings

## Installation

Install Clarity via Composer:

```bash
composer require clarity/engine
```

> **Note:** If using Clarity as part of a framework, it may already be included.

## Minimum Requirements

- PHP >= 8.1
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

That's it! The template will be compiled to PHP on first render, then served from cache on subsequent requests.

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
```

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

### Named Namespaces

Register directories with namespaces for organized template resolution:

```php
// Register a namespace
$engine->addNamespace('admin', __DIR__ . '/views/admin');
$engine->addNamespace('emails', __DIR__ . '/views/emails');

// Use in templates:
// {% include "admin::sidebar" %}
// {% extends "emails::layouts/base" %}
```

## Quick Template Syntax Overview

### Output Expressions

```twig
{{ variable }}
{{ user.name }}
{{ items[0].title }}
{{ price |> number(2) }}
{{ description |> upper |> trim }}
```

### Directives

```twig
<!-- Conditionals -->
{% if user.isActive %}
<p>Active user</p>
{% else %}
<p>Inactive user</p>
{% endif %}

<!-- Loops -->
{% for item in items %}
<li>{{ item.name }}</li>
{% endfor %}

<!-- Variable Assignment -->
{% set total = items.length %}

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
{% extends "layouts/main" %} {% block title %}{{ title }} - My Website{%
endblock %} {% block content %}
<h2>Hello, {{ user.name }}!</h2>
<p>You are logged in as: <strong>{{ user.role }}</strong></p>

<h3>Recent Articles</h3>
<ul>
  {% for article in articles %}
  <li>
    <strong>{{ article.title }}</strong>
    <p>{{ article.body |> excerpt(50) }}</p>
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

```php
// Enable cache flushing for every request (in development only)
if ($_ENV['APP_ENV'] === 'development') {
    $engine->flushCache();
}
```

Or manually flush when needed:

```bash
# CLI command to flush cache
php -r "require 'vendor/autoload.php'; (new \Clarity\ClarityEngine())->setCachePath(__DIR__ . '/cache')->flushCache();"
```

### In Production

1. Ensure cache directory is persistent and writable by the web server
2. Do NOT call `flushCache()` on every request
3. Templates are automatically recompiled when source files change
4. Consider pre-warming the cache after deployment

```php
// Production settings
$engine->setViewPath('/var/www/views');
$engine->setCachePath('/var/cache/clarity');  // Persistent, writable
```

## Next Steps

Now that you have Clarity up and running, explore these topics:

- **[Template Syntax](01-template-syntax.md)** — Learn all directives, operators, and expressions
- **[Filters and Functions](02-filters-and-functions.md)** — Master data transformation
- **[Layout Inheritance](03-layout-inheritance.md)** — Build reusable page structures
- **[Examples](../examples/README.md)** — See complete working examples

## Common Questions

### Where are compiled files stored?

By default, compiled templates are stored in `sys_get_temp_dir() . '/clarity'`. You should set a custom cache path in production:

```php
$engine->setCachePath(__DIR__ . '/cache/clarity');
```

### Do I need to manually clear the cache?

No! Clarity automatically detects when template files (including extended layouts and included partials) are modified and recompiles them. Only call `flushCache()` during development if you encounter issues.

### Can I use Clarity without a framework?

Yes! Clarity is completely standalone. The examples above show standalone usage without any framework dependencies.

### Is the output cached?

No. Clarity caches the **compiled PHP code**, not the rendered output. Each render call executes the compiled template with fresh data.

## Troubleshooting

### Cache directory not writable

**Error:** `Failed to write cache file...`

**Solution:** Ensure the cache directory exists and is writable:

```bash
mkdir -p cache/clarity
chmod 755 cache/clarity
```

### Templates not updating

**Solution:** Clear the cache manually:

```php
$engine->flushCache();
```

If using OPcache, restart PHP-FPM or clear OPcache.

### Class not found errors

**Solution:** Run `composer dump-autoload` to regenerate the autoloader.

For more troubleshooting help, see [Troubleshooting Guide](06-troubleshooting.md).
