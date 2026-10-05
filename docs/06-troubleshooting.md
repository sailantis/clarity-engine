# Troubleshooting Guide

This guide helps you diagnose and fix common issues when working with Clarity templates.

## Common Errors

### TypeError: Argument must be of type string

**Error:**

```
Clarity\ClarityException: mb_strtoupper(): Argument #1 ($string) must be of type
string, null given
```

**Cause:** A template passed a value of the wrong type into a filter or function.
`strictTypes` is on by default, so a compiled template declares
`declare(strict_types=1)` and a mismatched argument throws instead of being
coerced. The most common form is a value that may be `null` reaching a string
filter: `{{ maybeMissing |> upper }}` renders `''` in weak mode and throws here.
The exception names the template line.

**Fix:** Give the value a real type at the boundary:

```twig
{{ maybeMissing |> default('') |> upper }}
```

`default('')` handles both a `null` from the scope and a name the scope does not
contain (a name absent from both the scope and the loop locals is a compile-time
error on its own, which the filter chain suppresses).

To restore PHP's old coercing behaviour instead, deny the rule — it is one rule,
so nothing else opens up:

```php
$engine->setPolicy(Policy::default()->denyRule('strictTypes'));
```

See [strictTypes](09-policy-api.md#stricttypes) and
[Why strict by default](09-policy-api.md#why-strict-by-default).

---

### Undefined Variable

**Error:**

```
Warning: Undefined array key "variableName"
```

**Cause:** Variable not passed to template or typo in variable name.

**Solutions:**

1. **Pass the variable to render():**

```php
$engine->render('page', [
    'variableName' => $value,  // Make sure it's included
]);
```

2. **Use default filter:**

```twig
{{ variableName |> default('Default Value') }}
```

3. **Check with conditional:**

```twig
{% if isset(variableName) %}
    {{ variableName }}
{% else %}
    No value provided
{% endif %}
```

4. **Use null coalescing operator:**

```twig
{{ variableName ?? 'Default' }}
```

---

### Undefined Filter

**Error:**

```bash
Filter 'filterName' is not registered, and this policy does not allow PHP 
function calls, so there is nothing for it to resolve to. Register it with 
addFilter(), or grant the 'phpFunctions' rule and add the name with 
allowFunctions().
```

**Cause:** Typo in filter name, or the filter was never registered.

The error occurs during compilation, not rendering.

**Fix:** Register a custom filter with `addFilter()`. To use a PHP function by
name, grant the `phpFunctions` rule and allowlist the function (which
`allowFunctions()` does in one step):

```php
$engine->setPolicy(Policy::default()
    ->allowRule('methodCalls')
    ->allowFunctions('strtoupper', 'count'));
```

See the [policy reference](09-policy-api.md) for details.

Also check the filter name and available built-ins:

```twig
{{ value |> upper }}
```

See [Built-in Filters](02-filters-and-functions.md#built-in-filters).

---

### Template Not Found

**Error:**

```
Template 'templateName' not found
```

**Cause:** Incorrect template path or file doesn't exist.

**Solutions:**

1. **Check file exists:**

```bash
ls views/templateName.clarity.html
```

2. **Verify view path:**

```php
$engine->setViewPath(__DIR__ . '/views');
echo $engine->getViewPath();  // Check the actual path
```

3. **Check file extension:**

```php
// Default extension is .clarity.html
$engine->render('home', []);  // Looks for home.clarity.html

// If using custom extension:
$engine->setExtension('.tpl.html');
```

4. **Use correct namespace:**

```twig
{# Wrong #}
{% include "admin/sidebar" %}
{# Correct with namespace #}
{% include "admin::sidebar" %}
```

---

### Circular Include Detected

**Error:**

```
Circular include detected: template1 → template2 → template1
```

**Cause:** Template includes itself directly or indirectly.

**Example:**

```twig
{# templates/a.clarity.html #}
{% include "b" %}
{# templates/b.clarity.html #}
{% include "a" %} {# Circular! #}
```

**Solution:** Refactor to break the circular dependency:

```twig
{# templates/a.clarity.html #}
{% include "c" %}
{# templates/b.clarity.html #}
{% include "c" %}
{# templates/c.clarity.html #}
<div>Shared content</div>
```

---

### Class Not Found / Autoload Error

**Error:**

```
Fatal error: Class 'Clarity\ClarityEngine' not found
```

**Cause:** Composer autoload not included or dependencies not installed.

**Solutions:**

1. **Include autoloader:**

```php
require_once __DIR__ . '/vendor/autoload.php';

use Clarity\ClarityEngine;
```

2. **Install dependencies:**

```bash
composer install
```

3. **Regenerate autoloader:**

```bash
composer dump-autoload
```

---

### Permission Denied (Cache Directory)

**Error:**

```
Warning: file_put_contents(...): Permission denied
```

**Cause:** Cache directory not writable by web server.

**Solutions:**

1. **Create directory:**

```bash
mkdir -p cache/clarity
```

2. **Set permissions:**

```bash
chmod -R 755 cache/clarity
chown -R www-data:www-data cache/clarity  # Linux/Apache
```

3. **Verify path:**

```php
$cachePath = $engine->getCachePath();
echo "Cache path: $cachePath\n";
echo "Writable: " . (is_writable($cachePath) ? 'Yes' : 'No') . "\n";
```

---

### Template Not Updating

**Problem:** Changes to template file don't appear in output.

**Causes & Solutions:**

1. **OPcache serving stale files:**

```php
// Clear OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// Or flush Clarity cache
$engine->flushCache();
```

Restart PHP-FPM gracefully:

```bash
sudo systemctl reload php8.3-fpm
```

2. **Browser caching HTML:**

Hard refresh: `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac)

3. **Wrong template file edited:**

```php
// Check which template is being rendered
echo $engine->getCachePath();
// Verify the template path is correct
```

4. **Manual cache flush:**

```bash
rm -rf cache/clarity/*
```

---

### Output Escaped

**Problem:** HTML tags appearing in output when they should not be escaped.

**Solution:** Add `raw` filter when outputting trusted HTML:

```twig
{{ sanitizedArticleBody |> raw }}
```

---

### XSS Vulnerability

**Problem:** Script injection in output.

**Example:**

```twig
{# Vulnerable #}
{{ userComment |> raw }}
{# Probable output if userComment contains a script tag:
<script>
  alert("XSS");
</script>
#}
{# Safe #}
{{ userComment }}
{# Output: &lt;script&gt;alert('XSS')&lt;/script&gt; #}
```

**Solution:** Never use `raw` with unfiltered user input. Rely on auto-escaping for untrusted data or use a proper sanitization filter before outputting.

---

## Cache Issues

### Stale Cache in Production

**Problem:** Old template version served after deployment.

**Solution:**

1. **Flush cache after deployment:**

```php
// deploy.php
$engine->flushCache();
```

Or via CLI:

```bash
php -r "require 'vendor/autoload.php'; (new \Clarity\ClarityEngine())->setCachePath(__DIR__ . '/cache/clarity')->flushCache();"
```

2. **Restart PHP-FPM gracefully:**

```bash
sudo systemctl reload php8.3-fpm
```

3. **Clear OPcache:**

```php
opcache_reset();
```

### Cache Growing Too Large

**Problem:** Cache directory consuming disk space.

**Solutions:**

1. **Periodic cleanup:**

```bash
# Cron job to clear old cache files
find /var/cache/clarity -type f -mtime +30 -delete
```

2. **Flush during deployment:**

```php
$engine->flushCache();  // Removes all cached files
```

### Multiple Environments Sharing Cache

**Problem:** Development and production sharing the same cache.

**Solution:** Use environment-specific cache paths:

```php
$env = $_ENV['APP_ENV'] ?? 'production';
$engine->setCachePath(__DIR__ . "/cache/clarity-{$env}");
```

---

## Performance Issues

### Slow First Request

**Cause:** Template compilation on first render.

**Normal behavior:** Clarity compiles templates on first request, then caches them.

**Solutions:**

1. **Pre-warm cache after deployment:**

```php
$templates = ['home', 'about', 'contact', 'products/index'];
foreach ($templates as $template) {
    $engine->render($template, []);
}
```

2. **Accept warm-up time:** Subsequent requests will be fast.

### Slow Every Request

**Cause:** Cache disabled or not working.

**Check:**

```php
echo "Cache path: " . $engine->getCachePath() . "\n";
echo "Writable: " . (is_writable($engine->getCachePath()) ? 'Yes' : 'No') . "\n";
```

**Solutions:**

1. **Verify cache directory is writable**
2. **Ensure you're NOT calling `flushCache()` on every request**
3. **Enable OPcache** (php.ini):

```ini
opcache.enable=1
opcache.memory_consumption=128
```

### Memory Issues

**Error:**

```
Fatal error: Allowed memory size exhausted
```

**Causes:**

1. **Too much data passed to template:**

```php
// Bad: Passing huge dataset
$engine->render('page', ['items' => $millionRows]);

// Good: Paginate
$engine->render('page', ['items' => array_slice($millionRows, 0, 20)]);
```

2. **Infinite loop in template:**

```twig
{# Long running loop #}
{% for i in 1..999999999 %}
    {{ i }}
{% endfor %}
```

---

## Encoding Issues

### Garbled Unicode Characters

**Problem:** `Ã¤` instead of `ä`, `â€™` instead of `'`

**Cause:** Encoding mismatch (template not UTF-8 or output not declared UTF-8).

**Solutions:**

1. **Save templates as UTF-8:**

In your editor: File → Save with Encoding → UTF-8

2. **Declare charset in HTML:**

```twig
<meta charset="UTF-8" />
```

3. **Set PHP output encoding:**

```php
header('Content-Type: text/html; charset=UTF-8');
```

4. **Check database encoding:**

```php
// MySQL: Use UTF-8
$pdo = new PDO('mysql:host=localhost;dbname=mydb;charset=utf8mb4', ...);
```

### Emoji Not Displaying

**Problem:** Emoji showing as `?` or boxes.

**Solutions:**

1. **Use UTF-8mb4 (MySQL):**

```sql
ALTER TABLE posts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. **Ensure UTF-8 encoding:**

```twig
<meta charset="UTF-8" />
```

3. **Check font support:** Some fonts don't include emoji glyphs.

---

## Debugging Techniques

### Enable Error Display

**Development:**

```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```

**Production:**

```php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_log('Template error: ' . $exception->getMessage());
```

### Turn On Debug Mode

`setDebugMode(true)` turns on the full debug experience — context-aware, masked
`dump()`, the `dump` filter, runtime range-loop safety checks and a render event
bus:

```php
$engine->setDebugMode(true);                    // dev
$engine->isDebugMode();                         // bool

// …or with options (depth, masking, HTML panel)
use Clarity\Debug\DumpOptions;
$engine->setDebugMode(new DumpOptions(showPanel: true, maxDepth: 99));
```

`dump()` displays a masked, context-aware tree only when debug mode is enabled.
Otherwise it outputs nothing and is safe to leave in templates — see
[Debug Mode](04-advanced-topics.md#debug-mode).

### Dump Template Variables

```twig
<pre>{{ vars() |> json |> raw }}</pre>
```

Or specific variable:

```twig
<pre>{{ dump(user) }}</pre>
```

Unlike `{{ vars() |> json }}`, `dump()` does not expose data in production.

### Use Try-Catch

```php
use Clarity\ClarityException;

try {
    echo $engine->render('page', $data);
} catch (ClarityException $e) {
    echo "<pre>";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Template: " . $e->templateName . "\n";
    echo "Path: " . $e->templatePath . "\n";
    echo "Line: " . $e->templateLine . "\n";
    echo "\nStack Trace:\n" . $e->getTraceAsString();
    echo "</pre>";
}
```

---

## Integration Issues

### Framework Conflicts

**Problem:** Clarity conflicts with framework's view engine.

**Solution:** Use namespacing or conditional initialization:

```php
// Only initialize Clarity for specific routes
if ($request->isAdminRoute()) {
    $viewEngine = new ClarityEngine();
} else {
    $viewEngine = new FrameworkViewEngine();
}
```

### Asset Path Issues

**Problem:** CSS/JS paths broken when using Clarity.

**Solution:** Use absolute paths or custom function:

```php
$engine->addFunction('asset', function($path) {
    return '/assets/' . ltrim($path, '/');
});
```

```twig
<link rel="stylesheet" href="{{ asset('css/main.css') }}" />
<script src="{{ asset('js/app.js') }}"></script>
```

### AJAX Rendering

**Problem:** Need to render partial template for AJAX response.

**Solution:** Disable layout for AJAX requests:

```php
if ($request->isAjax()) {
    $engine->setLayout(null);
}

echo $engine->render('partials/user-list', ['users' => $users]);
```

---

## Error Reference

### Common Clarity Errors

| Error Message                   | Likely Cause                     | Solution                              |
| ------------------------------- | -------------------------------- | ------------------------------------- |
| Template 'X' not found          | Wrong path or file doesn't exist | Check view path and file name         |
| Filter 'X' not registered       | Typo or filter not added         | Register filter or check spelling     |
| Undefined array key "X"         | Variable not passed to template  | Pass variable or use `\|> default`    |
| Circular include detected       | Template includes itself         | Refactor to break circular dependency |
| Permission denied               | Cache directory not writable     | Set correct permissions               |
| Syntax error: unexpected token  | Missing delimiter or typo        | Check template syntax                 |
| Class 'ClarityEngine' not found | Autoloader not included          | Include `vendor/autoload.php`         |
| Memory exhausted                | Too much data or infinite loop   | Reduce data size or fix loop          |

---

## Next Steps

- **[Best Practices](05-best-practices.md)** — Avoid common pitfalls
- **[Advanced Topics](04-advanced-topics.md)** — Deep dives
- **[Examples](examples/README.md)** — Working examples
