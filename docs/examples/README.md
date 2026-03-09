# Clarity Template Examples

This directory contains runnable example templates demonstrating Clarity features from basic to advanced usage.

## Running the Examples

### Quick Start

1. **Set up Clarity:**

```bash
composer install
```

2. **Run the example renderer:**

```bash
php render.php 01-hello
```

Or render all examples:

```bash
php render.php --all
```

### Manual Rendering

```php
<?php
require __DIR__ . '/../../vendor/autoload.php';

use Clarity\ClarityEngine;

$engine = new ClarityEngine();
$engine->setViewPath(__DIR__);
$engine->setCachePath(__DIR__ . '/../../cache/examples');

// Render an example
echo $engine->render('01-hello', [
    'message' => 'Hello, Clarity!',
    'userName' => 'Developer'
]);
```

## Examples Overview

### 01-hello.clarity.html

**Basics: Variables and Output**

Demonstrates:

- Simple variable output
- Auto-escaping
- Dot notation for nested data

### 02-filters.clarity.html

**Filters: Transform Data**

Demonstrates:

- Text filters (upper, lower, capitalize, trim)
- Number formatting
- Date formatting
- Filter chaining

### 03-conditionals.clarity.html

**Control Flow: Conditionals**

Demonstrates:

- if/else/elseif statements
- Comparison operators
- Logical operators (and, or, not)
- Ternary operator

### 04-loops.clarity.html

**Control Flow: Loops**

Demonstrates:

- for loops over arrays
- Loop variables (index, first, last)
- Range loops (exclusive and inclusive)
- Loops with step
- Nested loops

### 05-layout.clarity.html & 05-page.clarity.html

**Template Inheritance**

Demonstrates:

- Defining layouts with blocks
- Extending layouts
- Overriding blocks
- Multiple blocks

### 06-complex.clarity.html

**Real-World: Blog Post Listing**

Demonstrates:

- Combining multiple features
- Realistic data structure
- Filters with arrays (map, filter)
- Includes and layouts together
- Conditional rendering

### \_components/

**Reusable Components**

Small reusable template fragments:

- `header.clarity.html` — Site header
- `footer.clarity.html` — Site footer
- `nav.clarity.html` — Navigation menu
- `user-card.clarity.html` — User profile card

## Example Data

Each example uses realistic sample data. See `render.php` for the data structure passed to each template.

## Learning Path

**For Beginners:**

1. Start with `01-hello.clarity.html` — understand basic syntax
2. Move to `02-filters.clarity.html` — learn data transformation
3. Try `03-conditionals.clarity.html` — add logic
4. Practice `04-loops.clarity.html` — work with collections

**For Intermediate Users:** 5. Study `05-layout.clarity.html` + `05-page.clarity.html` — master inheritance 6. Examine `06-complex.clarity.html` — see realistic usage 7. Explore `_components/` — learn reusability patterns

## Modifying Examples

Feel free to modify these examples to experiment:

```bash
# Edit an example
nano 02-filters.clarity.html

# Clear cache to see changes
rm -rf ../../cache/examples/*

# Re-render
php render.php 02-filters
```

## Creating Your Own Examples

Add your own example template:

**File: `my-example.clarity.html`**

```twig
<!DOCTYPE html>
<html>
  <head>
    <title>My Example</title>
  </head>
  <body>
    <h1>{{ title }}</h1>
  </body>
</html>
```

**Render it:**

```php
echo $engine->render('my-example', ['title' => 'Testing']);
```

## Common Issues

### Template Not Found

Ensure you're in the `docs/examples/` directory when rendering, or set the correct view path.

### Cache Permission Errors

Make sure `cache/examples/` directory exists and is writable:

```bash
mkdir -p ../../cache/examples
chmod 755 ../../cache/examples
```

### Changes Not Appearing

Clear the cache:

```bash
rm -rf ../../cache/examples/*
```

Or programmatically:

```php
$engine->flushCache();
```

## Next Steps

- **[Getting Started Guide](../guides/00-getting-started.md)** — Complete setup guide
- **[Template Syntax Reference](../guides/01-template-syntax.md)** — All syntax features
- **[Filters Reference](../guides/02-filters-and-functions.md)** — Complete filter list
- **[Best Practices](../guides/05-best-practices.md)** — Code organization tips
