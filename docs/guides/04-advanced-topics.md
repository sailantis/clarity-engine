# Advanced Topics

This guide covers advanced Clarity features including namespaces, caching, auto-escaping, error handling, and Unicode support.

## Named Namespaces

Namespaces allow you to organize templates into logical directories and reference them with a clean prefix syntax.

### Registering Namespaces

Register a namespace in PHP:

```php
$engine->addNamespace('admin', __DIR__ . '/views/admin');
$engine->addNamespace('emails', __DIR__ . '/views/emails');
$engine->addNamespace('components', __DIR__ . '/views/components');
```

### Using Namespaces in Templates

Reference templates using the `namespace::path` syntax:

```html
{% include "admin::sidebar" %} {% extends "admin::layouts/base" %} {{
include("emails::welcome", { userName: user.name }) }}
```

### Path Separators

Both dots and slashes work as path separators:

```html
{% include "admin::partials.sidebar" %} {% include "admin::partials/sidebar" %}
{# Both are equivalent #}
```

### Nested Directories

Namespaces can contain nested directories:

```php
$engine->addNamespace('components', __DIR__ . '/views/components');
```

```html
{% include "components::buttons/primary" %} {% include
"components::cards/user-card" %} {% include "components::forms/input-field" %}
```

### Benefits of Namespaces

1. **Organization** — Separate concerns (admin, public, emails, etc.)
2. **Portability** — Move template directories without changing template code
3. **Clarity** — Self-documenting template paths
4. **Modularity** — Package reusable components

### Example Structure

```
views/
├── layouts/
│   └── main.clarity.html
├── pages/
│   ├── home.clarity.html
│   └── about.clarity.html
├── admin/                    (namespace: admin)
│   ├── layouts/
│   │   └── admin.clarity.html
│   └── pages/
│       └── users.clarity.html
├── components/               (namespace: components)
│   ├── buttons/
│   │   └── primary.clarity.html
│   └── cards/
│       └── user-card.clarity.html
└── emails/                   (namespace: emails)
    ├── layouts/
    │   └── email-base.clarity.html
    └── welcome.clarity.html
```

**Configuration:**

```php
$engine->setViewPath(__DIR__ . '/views');
$engine->addNamespace('admin', __DIR__ . '/views/admin');
$engine->addNamespace('components', __DIR__ . '/views/components');
$engine->addNamespace('emails', __DIR__ . '/views/emails');
```

**Usage:**

```html
{# Main site pages (no namespace) #} {% extends "layouts/main" %} {# Admin area
#} {% include "admin::partials/header" %} {# Reusable components #} {% include
"components::buttons/primary" %} {# Email templates #} {% extends
"emails::layouts/email-base" %}
```

## Caching

Clarity compiles `.clarity.html` templates into PHP classes and caches them on disk for maximum performance.

### How Caching Works

1. **First Request:** Template is compiled to PHP and saved in the cache directory
2. **Subsequent Requests:** Cached PHP file is loaded directly (zero compilation overhead)
3. **Auto-Invalidation:** Cache is automatically regenerated when source files change

### Cache Configuration

#### Set Cache Directory

```php
$engine->setCachePath(__DIR__ . '/cache/clarity');
```

> **Important:** Cache directory must be writable by the web server.

#### Get Cache Path

```php
$cachePath = $engine->getCachePath();
echo "Templates cached in: $cachePath";
```

#### Default Cache Location

If not configured, defaults to:

```php
sys_get_temp_dir() . '/clarity'
```

### Cache Invalidation

#### Automatic Invalidation

Clarity automatically detects changes to:

- The template file itself
- Extended layouts (`{% extends %}`)
- Included partials (`{% include %}`)

When any file changes, the cache is regenerated automatically.

#### Manual Cache Flush

Clear all cached templates:

```php
$engine->flushCache();
```

Use cases for manual flushing:

- Development when auto-invalidation doesn't work (rare)
- After deployment to ensure fresh compilation
- Troubleshooting cache issues

#### Development vs. Production

**Development:**

```php
if ($_ENV['APP_ENV'] === 'development') {
    // Optionally flush on every request during development
    $engine->flushCache();
}
```

**Production:**

```php
// Set persistent cache directory
$engine->setCachePath('/var/cache/clarity');

// Let automatic invalidation handle updates
// Do NOT call flushCache() on every request
```

### Cache Performance

**Cold start (first render):**

- Template is tokenized, parsed, and compiled to PHP
- PHP file is written to cache
- Template is rendered

**Warm path (subsequent renders):**

- Cache file is loaded directly (1 `require` statement)
- PHP OPcache accelerates the cached file
- Near-native PHP performance

### Cache Directory Structure

Cached files are organized by hash:

```
cache/clarity/
├── a1b2c3d4e5f6...php  (compiled: views/home.clarity.html)
├── b2c3d4e5f6a1...php  (compiled: layouts/main.clarity.html)
└── ...
```

File names are deterministic hashes of the template path.

### OPcache Considerations

When using PHP's OPcache, be aware:

- **Cached PHP files are stored in OPcache memory** for maximum speed
- **Problem:** If you manually write/overwrite cache files and immediately require them, OPcache might serve stale bytecode
- **Solution:** Clarity handles this internally by calling `opcache_invalidate()` and `clearstatcache()` when regenerating files

**For custom cache manipulation:**

```php
$cachePath = $engine->getCachePath() . '/template_hash.php';

// Write new cache file
file_put_contents($cachePath, $compiledCode);

// Invalidate OPcache
clearstatcache(true, $cachePath);
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($cachePath, true);
}

// Now safe to require
require $cachePath;
```

See [User Memory: debugging.md](memory://debugging.md) for OPcache notes.

## Auto-Escaping

Clarity automatically escapes all output for security by default.

### How Auto-Escaping Works

Every output expression is wrapped with `htmlspecialchars()`:

```html
{{ userInput }}
```

Compiles to:

```php
htmlspecialchars($vars['userInput'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
```

### Why Auto-Escaping Matters

**Without auto-escaping:**

```html
{{ userComment }}
<!-- If userComment = "<script>alert('XSS')</script>" -->
<!-- Outputs: <script>alert('XSS')</script> -->
<!-- DANGER: Script executes! -->
```

**With auto-escaping (Clarity default):**

```html
{{ userComment }}
<!-- Outputs: &lt;script&gt;alert('XSS')&lt;/script&gt; -->
<!-- SAFE: Displays as text, doesn't execute -->
```

### Disabling Auto-Escaping (raw filter)

To output raw HTML, use the `raw` filter:

```html
{{ trustedHtml |> raw }}
```

The `raw` filter is a **compile-time marker** that disables the auto-escape wrapper.

### When to Use raw

✅ **Safe uses:**

```html
{# 1. Sanitized HTML from a WYSIWYG editor #} {{ article.sanitizedBody |> raw }}
{# 2. Pre-rendered HTML fragments from your application #} {{ renderedWidget |>
raw }} {# 3. JSON output #} {{ data |> json |> raw }} {# 4. HTML-generating
filters like nl2br #} {{ description |> nl2br |> raw }}
```

❌ **Dangerous uses:**

```html
{# NEVER use raw with user input #} {{ userInput |> raw }} {# ⚠️ XSS
VULNERABILITY #} {# NEVER use raw with untrusted data #} {{ $_GET['name'] |> raw
}} {# ⚠️ DANGER #}
```

### Safe HTML Generation

If you need to generate HTML in a filter:

```php
$engine->addFilter('badge', function($value, string $type = 'default') {
    $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return "<span class=\"badge badge-$type\">$safeValue</span>";
});
```

Use in template:

```html
{{ status |> badge('success') |> raw }}
```

**Key:** Filter escapes the dynamic content internally, so the returned HTML is safe.

### Multiple Filters and raw

When `raw` appears **anywhere** in the filter chain, auto-escaping is disabled for the entire expression:

```html
{{ description |> trim |> nl2br |> raw }} {# No escaping applied (because of
raw) #} {{ description |> raw |> upper }} {# Still no escaping (raw anywhere in
chain) #}
```

## Error Handling

Clarity provides detailed error messages with template file and line mapping.

### ClarityException

All template errors throw `Clarity\ClarityException`:

```php
use Clarity\ClarityException;

try {
    $output = $engine->render('page', $data);
} catch (ClarityException $e) {
    echo "Template error: " . $e->getMessage();
    echo "\nFile: " . $e->getFile();
    echo "\nLine: " . $e->getLine();
}
```

### Error Messages

Clarity maps errors back to the **original template file and line**:

```
Syntax error in template: unexpected token '}' at line 42
File: views/products/show.clarity.html
Line: 42
```

Even though the error occurs in compiled PHP, Clarity traces it back to the source `.clarity.html` file.

### Common Errors

#### Undefined Variable

```html
{{ nonExistentVariable }}
```

**Error:** `Undefined array key "nonExistentVariable"`

**Solution:** Pass the variable to `render()`, or use `default` filter:

```html
{{ nonExistentVariable |> default('N/A') }}
```

#### Undefined Filter

```html
{{ value |> unknownFilter }}
```

**Error:** `Filter 'unknownFilter' is not registered`

**Solution:** Register the filter or fix the typo.

#### Syntax Errors

```html
{{ user.name |> upper( }} {# Missing closing parenthesis #}
```

**Error:** `Syntax error: unexpected end of expression`

**Solution:** Check template syntax.

#### Circular Includes

```html
{# a.clarity.html #} {% include "b" %} {# b.clarity.html #} {% include "a" %} {#
Circular! #}
```

**Error:** `Circular include detected: a → b → a`

**Solution:** Refactor to avoid circular dependencies.

### Development Error Handling

Show detailed errors during development:

```php
if ($_ENV['APP_ENV'] === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);

    try {
        echo $engine->render('page', $data);
    } catch (ClarityException $e) {
        echo "<pre>";
        echo "Template Error:\n";
        echo $e->getMessage() . "\n";
        echo "\nFile: " . $e->getFile();
        echo "\nLine: " . $e->getLine();
        echo "\n\nStack Trace:\n" . $e->getTraceAsString();
        echo "</pre>";
        exit;
    }
}
```

### Production Error Handling

Log errors but show user-friendly messages:

```php
try {
    echo $engine->render('page', $data);
} catch (ClarityException $e) {
    error_log("Template error: " . $e->getMessage());
    error_log("File: " . $e->getFile() . ":" . $e->getLine());

    http_response_code(500);
    echo "Sorry, something went wrong. Please try again later.";
}
```

## Unicode Support

Clarity is fully Unicode-aware via the `mbstring` extension.

### Built-in Unicode Support

String filters use multibyte functions:

```html
{{ "Ä Ö Ü ß" |> upper }} {# Output: "Ä Ö Ü SS" (Unicode-aware) #} {{ "ПРИВЕТ
МИР" |> lower }} {# Output: "привет мир" #} {{ "你好世界" |> length }} {#
Output: 4 (characters, not bytes) #}
```

### UnicodeString Class

For advanced Unicode operations, use the `unicode` filter:

```html
{{ text |> unicode |> reverse }} {# Unicode-aware string reversal #}
```

**UnicodeString API:**

```php
$ustr = new UnicodeString("Hello 世界", 0, 5);
$ustr->length();       // Character count
$ustr->slice(0, 5);    // Substring (character positions)
$ustr->reverse();      // Reverse string
```

### Emoji Support

Clarity handles emoji correctly:

```html
{{ "Hello 👋 World 🌍" |> length }} {# Output: 13 (counts emoji as 1 character
each) #} {{ "🚀🌟💡" |> reverse }} {# Output: "💡🌟🚀" #}
```

### Character Encoding

Clarity assumes **UTF-8** encoding:

- All templates should be saved as UTF-8
- Input data should be UTF-8
- Output is UTF-8

If working with other encodings:

```php
// Convert to UTF-8 before rendering
$data['text'] = mb_convert_encoding($data['text'], 'UTF-8', 'ISO-8859-1');

$engine->render('page', $data);
```

## Security Model

Clarity enforces strict security through compilation-time checks and runtime sandboxing.

### Compile-Time Restrictions

The following are **rejected at compile time** (template won't compile):

❌ **Direct PHP variables:**

```html
{{ $variable }} {# ERROR #}
```

❌ **Arbitrary function calls:**

```html
{{ strtoupper(name) }} {# ERROR #} {{ file_get_contents('/etc/passwd') }} {#
ERROR #}
```

❌ **Method calls:**

```html
{{ user.getName() }} {# ERROR #}
```

❌ **PHP statements:**

```html
{{ $x = 5; }} {# ERROR #}
```

❌ **Backticks, heredocs, PHP tags:**

```html
{{ `ls -la` }} {# ERROR #}
```

### Runtime Sandboxing

**Objects are converted to arrays:**

When you pass objects to `render()`, Clarity automatically converts them to arrays:

```php
class User {
    public $name = 'John';
    private $password = 'secret';

    public function getName() {
        return $this->name;
    }
}

$user = new User();
$engine->render('page', ['user' => $user]);
```

**In template:**

```html
{{ user.name }} {# Works: public properties exposed #} {{ user.password }} {#
NULL: private properties hidden #} {{ user.getName() }} {# COMPILE ERROR: method
calls not allowed #}
```

**Custom serialization:**

Implement `JsonSerializable` or `toArray()`:

```php
class User implements JsonSerializable {
    private $name;
    private $email;

    public function jsonSerialize(): array {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
```

### Lambda Security

Lambdas in `map`, `filter`, `reduce` only accept:

1. **Lambda expressions** (parsed at compile time)
2. **Filter references** (validated at compile time)

❌ **NOT allowed:**

```html
{# Cannot pass callable via variable #} {% set callback = someCallable %} {{
items |> map(callback) }} {# ERROR #}
```

✅ **Allowed:**

```html
{{ items |> map(i => i.name) }} {# Lambda: safe #} {{ items |> map("upper") }}
{# Filter reference: safe #}
```

### Registered Filters/Functions

Only **registered** filters and functions are callable:

```php
$engine->addFilter('customFilter', $callable);
```

```html
{{ value |> customFilter }} {# Allowed: registered #} {{ value |> notRegistered
}} {# ERROR: not registered #}
```

## Performance Optimization

### Pre-Compilation

Pre-compile all templates after deployment:

```php
$templates = [
    'layouts/main',
    'pages/home',
    'pages/about',
    // ... all templates
];

foreach ($templates as $template) {
    $engine->render($template, []);
}
```

This warms the cache and ensures the first user request is fast.

### Cache in Persistent Storage

Use a persistent cache directory (not `/tmp`):

```php
$engine->setCachePath('/var/cache/clarity');
```

Ensure it survives server restarts.

### OPcache Configuration

Enable OPcache in production (`php.ini`):

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

### Minimize Template Complexity

- Keep logic simple (complex logic in PHP, not templates)
- Avoid deeply nested loops
- Cache computed values in PHP before passing to template

## Configuration Reference

### All Configuration Methods

| Method                                      | Description                               |
| ------------------------------------------- | ----------------------------------------- |
| `setViewPath(string $path)`                 | Base directory for templates              |
| `setLayout(?string $layout)`                | Default layout template                   |
| `setExtension(string $ext)`                 | File extension (default: `.clarity.html`) |
| `setCachePath(string $path)`                | Cache directory                           |
| `getCachePath(): string`                    | Get current cache path                    |
| `flushCache(): void`                        | Delete all cached files                   |
| `addFilter(string $name, callable $fn)`     | Register custom filter                    |
| `addFunction(string $name, callable $fn)`   | Register custom function                  |
| `addNamespace(string $ns, string $path)`    | Register named namespace                  |
| `render(string $view, array $vars): string` | Render template and return HTML           |

### Example: Complete Setup

```php
use Clarity\ClarityEngine;

$engine = new ClarityEngine();

// Paths
$engine->setViewPath(__DIR__ . '/views');
$engine->setCachePath(__DIR__ . '/cache/clarity');

// Default layout
$engine->setLayout('layouts/main');

// Namespaces
$engine->addNamespace('admin', __DIR__ . '/views/admin');
$engine->addNamespace('emails', __DIR__ . '/views/emails');

// Custom filters
$engine->addFilter('currency', fn($v) => '€ ' . number_format($v, 2));
$engine->addFilter('excerpt', fn($text, $len = 100) =>
    mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '...' : $text
);

// Custom functions
$engine->addFunction('asset', fn($path) => '/assets/' . ltrim($path, '/'));

// Render
echo $engine->render('pages/home', [
    'title' => 'Home',
    'user' => $user,
]);
```

## Next Steps

- **[Best Practices](05-best-practices.md)** — Organization, naming, security
- **[Troubleshooting](06-troubleshooting.md)** — Common errors and solutions
- **[Examples](../examples/README.md)** — See advanced features in action
