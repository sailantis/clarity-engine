# Clarity Template Engine

![Clarity Logo](docs/images/clarity-dsl-logo-opt.svg)

> **A fast, secure, and expressive PHP template engine** – Clarity compiles `.clarity.html` templates into cached PHP classes for maximum performance while maintaining a sandboxed, secure execution environment.

---

## ✨ Features

- **🚀 Compiled & Cached** – Templates compile to PHP classes and leverage OPcache for blazing-fast rendering
- **🔒 Secure Sandbox** – No arbitrary PHP execution; templates are strictly sandboxed with controlled access
- **🎨 Expressive Syntax** – Clean, readable template syntax inspired by modern template engines
- **📦 Template Inheritance** – Reusable layouts with `extends` and `blocks` for DRY template architecture
- **🔧 Extensible** – Custom filters, functions, and namespaces for flexible template organization
- **⚡ Auto-escaping** – Built-in XSS protection with automatic HTML escaping
- **🌍 Unicode Support** – Full multibyte string handling with transparent normalization
- **🎯 Zero Dependencies** – Standalone engine with no external dependencies beyond PHP 8.1+

---

## 📦 Installation

```bash
composer require clarity/template-engine
```

**Requirements:** PHP 8.1 or higher

---

## 🚀 Quick Start

### Basic Setup

```php
<?php
require_once 'vendor/autoload.php';

use Clarity\ClarityEngine;

// Initialize the engine
$engine = new ClarityEngine();
$engine->setViewPath(__DIR__ . '/templates');
$engine->setCachePath(__DIR__ . '/cache');

// Render a template
echo $engine->render('welcome', [
    'title' => 'Welcome to Clarity',
    'user' => ['name' => 'Developer']
]);
```

### Your First Template

**templates/welcome.clarity.html:**

```html
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

---

## 📚 Documentation

### For Template Authors

Start here if you're writing templates:

- **[Getting Started](docs/guides/00-getting-started.md)** – Installation, setup, and your first template
- **[Template Syntax](docs/guides/01-template-syntax.md)** – Variables, directives, operators, and control flow
- **[Filters & Functions](docs/guides/02-filters-and-functions.md)** – Transform data with built-in and custom filters
- **[Layout Inheritance](docs/guides/03-layout-inheritance.md)** – Reusable layouts with extends and blocks

### For Developers

Integration and advanced topics:

- **[Advanced Topics](docs/guides/04-advanced-topics.md)** – Namespaces, caching, auto-escaping, and Unicode
- **[Best Practices](docs/guides/05-best-practices.md)** – Organization, security, performance, and testing
- **[Troubleshooting](docs/guides/06-troubleshooting.md)** – Common errors and debugging techniques

### Reference

- **[API Documentation](docs/api/)** – Auto-generated API reference for all classes
- **[Examples](docs/examples/)** – Runnable template examples demonstrating features
- **[Guide Index](docs/guides/README.md)** – Complete documentation index

---

### Output & Variables

```twig
{{ expression }}              {# Output with auto-escaping #}
{{ expression |> raw }}       {# Output raw HTML (no escaping) #}
{{ user.name }}               {# Dot notation #}
{{ items[0] }}                {# Bracket notation #}
{{ firstName ~ ' ' ~ lastName }} {# String concatenation #}
```

### Control Flow

```twig
{% if condition %}...{% elseif other %}...{% else %}...{% endif %}

{% for item in items %}
  {{ item.name }}
{% endfor %}

{% for i in 1..10 %}{{ i }}{% endfor %}        {# Range: 1 to 9 #}
{% for i in 1...10 %}{{ i }}{% endfor %}       {# Range: 1 to 10 #}
{% for i in 0...100 step 10 %}{{ i }}{% endfor %} {# With step #}

{% set total = items |> length %}
```

### Filters

```twig
{{ text |> upper }}
{{ price |> number(2) }}
{{ timestamp |> date('Y-m-d H:i') }}
{{ tags |> join(', ') }}
{{ users |> map(u => u.name) |> join(', ') }} {# Lambda expression #}
{{ items |> filter(i => i.active) |> length }}
```

Common filters: `upper`, `lower`, `trim`, `length`, `number`, `date`, `json`, `join`, `map`, `filter`, `reduce`, `default`, `escape`, `raw`

📖 **[See all filters and detailed syntax →](docs/guides/02-filters-and-functions.md)**

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
{% include "partials/header" %}              {# Static include #}
{{ include("widgets/card", { title: "Hi" }) }} {# Dynamic include with context #}
```

📖 **[Full syntax reference →](docs/guides/01-template-syntax.md)**

---

## ⚙️ Configuration

Configure the engine with these methods:

```php
$engine = new ClarityEngine();

// Required configuration
$engine->setViewPath(__DIR__ . '/templates');
$engine->setCachePath(__DIR__ . '/cache');

// Optional configuration
$engine->setExtension('.tpl.html');           // Default: .clarity.html
$engine->addFilter('currency', fn($v) => '€ ' . number_format($v, 2));
$engine->addNamespace('admin', '/path/to/admin/templates');
$engine->flushCache();                        // Clear compiled templates
```

📖 **[Configuration guide →](docs/guides/00-getting-started.md#configuration)**

---

## 🔒 Security

Clarity provides a secure sandbox environment:

- ✅ **No arbitrary PHP execution** – Templates cannot call PHP functions or access global state
- ✅ **Auto-escaping by default** – All output is HTML-escaped to prevent XSS attacks
- ✅ **Compile-time validation** – Syntax errors caught during compilation, not at runtime
- ✅ **Object safety** – Objects are converted to arrays, preventing method calls from templates
- ✅ **Controlled lambdas** – Lambda expressions can only use registered filters

📖 **[Security best practices →](docs/guides/05-best-practices.md#security)**

---

## ⚡ Performance

Clarity is designed for speed. Templates compile to native PHP classes and leverage OPcache for optimal performance:

- **Compiled templates** – One-time compilation to PHP, then served from OPcache
- **Auto-invalidation** – Cache automatically refreshed when templates change
- **Zero runtime overhead** – Inheritance resolved at compile time
- **Minimal memory footprint** – Efficient compilation with predictable memory usage

### Benchmark Results

| Engine  | Warm (ms) | Mean (ms) | P95 (ms) |
| ------- | --------- | --------- | -------- |
| PHP     | 1.88      | 0.22      | 0.32     |
| Clarity | 0.70      | 0.27      | 0.38     |
| Plates  | 5.96      | 0.39      | 0.56     |
| Blade   | 28.47     | 0.75      | 1.04     |
| Twig    | 19.75     | 0.83      | 1.11     |

_10,000 iterations, PHP 8.3 with OPcache CLI enabled_

📖 **[Performance optimization guide →](docs/guides/05-best-practices.md#performance)**

---

## 🤝 Contributing

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

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## 🔗 Links

- **[Documentation](docs/guides/README.md)** – Complete guide index
- **[Examples](docs/examples/)** – Runnable example templates
- **[API Reference](docs/api/)** – Auto-generated API documentation
- **[GitHub Issues](https://github.com/clarity/engine/issues)** – Report bugs or request features

---

Built with ❤️ for developers who value security and performance
