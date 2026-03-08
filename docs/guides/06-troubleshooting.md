# Troubleshooting Guide

This guide helps you diagnose and fix common issues when working with Clarity templates.

## Common Errors

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

```html
{{ variableName |> default('Default Value') }}
```

3. **Check with conditional:**

```html
{% if variableName %} {{ variableName }} {% else %} No value provided {% endif
%}
```

4. **Use null coalescing operator:**

```html
{{ variableName ?? 'Default' }}
```

---

### Undefined Filter

**Error:**

```
Filter 'filterName' is not registered
```

**Cause:** Typo in filter name or filter not registered.

**Solutions:**

1. **Check filter name spelling:**

```html
{# ❌ Wrong #} {{ value |> uppercase }} {# ✅ Correct #} {{ value |> upper }}
```

2. **Register custom filter:**

```php
$engine->addFilter('customFilter', function($value) {
    return strtoupper($value);
});
```

3. **Check available filters:**

See [Filters Reference](02-filters-and-functions.md#built-in-filters) for complete list.

---

### Syntax Error

**Error:**

```
Syntax error: unexpected token '}' at line 42
```

**Cause:** Missing delimiter, unclosed tag, or typo.

**Common syntax issues:**

1. **Unclosed tags:**

```html
{# ❌ Wrong #} {% if condition %}
<p>Content</p>
{# Missing {% endif %} #} {# ✅ Correct #} {% if condition %}
<p>Content</p>
{% endif %}
```

2. **Mismatched delimiters:**

```html
{# ❌ Wrong #} {{ value |> upper } {# ✅ Correct #} {{ value |> upper }}
```

3. **Missing closing parenthesis:**

```html
{# ❌ Wrong #} {{ value |> truncate(100 }} {# ✅ Correct #} {{ value |>
truncate(100) }}
```

**Debugging tip:** Check the line number in the error message and examine the template file at that line.

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

```html
{# ❌ Wrong #} {% include "admin/sidebar" %} {# ✅ Correct with namespace #} {%
include "admin::sidebar" %}
```

---

### Circular Include Detected

**Error:**

```
Circular include detected: template1 → template2 → template1
```

**Cause:** Template includes itself directly or indirectly.

**Example:**

```html
{# templates/a.clarity.html #} {% include "b" %} {# templates/b.clarity.html #}
{% include "a" %} {# Circular! #}
```

**Solution:** Refactor to break the circular dependency:

```html
{# templates/a.clarity.html #} {% include "c" %} {# templates/b.clarity.html #}
{% include "c" %} {# templates/c.clarity.html #}
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

On Windows:

```powershell
icacls cache\clarity /grant IIS_IUSRS:F /T
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

Restart PHP-FPM:

```bash
sudo systemctl restart php8.2-fpm
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

### Output Not Escaped

**Problem:** HTML tags appearing in output when they should be escaped.

**Cause:** Using `raw` filter inappropriately.

**Example:**

```html
{# ❌ Wrong: Disables escaping #} {{ userInput |> raw }} {# ✅ Correct:
Auto-escaped #} {{ userInput }}
```

**Solution:** Remove `raw` filter unless outputting trusted HTML:

```html
{# Only use raw with sanitized content #} {{ sanitizedArticleBody |> raw }}
```

---

### XSS Vulnerability

**Problem:** Script injection in output.

**Example:**

```html
{# ❌ Vulnerable #} {{ userComment |> raw }} {# If userComment = "
<script>
  alert("XSS");
</script>
" → executes! #} {# ✅ Safe #} {{ userComment }} {# Output:
&lt;script&gt;alert('XSS')&lt;/script&gt; #}
```

**Solution:** Never use `raw` with user input. Always rely on auto-escaping for untrusted data.

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

2. **Restart PHP-FPM:**

```bash
sudo systemctl restart php8.2-fpm
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
// ❌ Bad: Passing huge dataset
$engine->render('page', ['items' => $millionRows]);

// ✅ Good: Paginate
$engine->render('page', ['items' => array_slice($millionRows, 0, 20)]);
```

2. **Infinite loop in template:**

```html
{# ❌ Infinite loop #} {% for i in 1..999999999 %} {{ i }} {% endfor %}
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

```html
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

```html
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

### Dump Template Variables

```html
<pre>{{ context() |> json |> raw }}</pre>
```

Or specific variable:

```html
<pre>{{ dump(user) }}</pre>
```

### Check Compiled Output

Inspect the compiled PHP file:

```php
$cachePath = $engine->getCachePath();
echo "Cache directory: $cachePath\n";

// Find compiled file and view it
$files = glob($cachePath . '/*.php');
echo file_get_contents($files[0]);
```

### Isolate the Problem

Create minimal test template:

```html
{# test.clarity.html #}
<p>Test: {{ variable }}</p>
```

```php
$output = $engine->render('test', ['variable' => 'Hello']);
echo $output;
```

If this works, the problem is in your template logic, not Clarity itself.

### Use Try-Catch

```php
use Clarity\ClarityException;

try {
    echo $engine->render('page', $data);
} catch (ClarityException $e) {
    echo "<pre>";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
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

```html
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

## Getting Help

### Check Documentation

1. [Getting Started](00-getting-started.md)
2. [Template Syntax](01-template-syntax.md)
3. [Filters Reference](02-filters-and-functions.md)
4. [Advanced Topics](04-advanced-topics.md)

### Enable Debugging

```php
// Show all errors
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Dump template context
{{ dump(context()) }}

// Clear cache
$engine->flushCache();
```

### Minimal Reproducible Example

Create a simple test case:

```php
require 'vendor/autoload.php';

$engine = new \Clarity\ClarityEngine();
$engine->setViewPath(__DIR__ . '/views');
$engine->setCachePath(__DIR__ . '/cache');

echo $engine->render('test', ['message' => 'Hello']);
```

If this works, the issue is in your application setup, not Clarity.

### Check System Requirements

- PHP >= 8.1
- mbstring extension enabled
- Cache directory writable
- Composer dependencies installed

```bash
php -v
php -m | grep mbstring
composer install
ls -la cache/
```

---

## Prevention Checklist

Before reporting an issue, verify:

- [ ] PHP version >= 8.1
- [ ] Composer dependencies installed (`composer install`)
- [ ] Autoloader included (`require 'vendor/autoload.php'`)
- [ ] View path correctly set
- [ ] Cache directory exists and is writable
- [ ] Template file exists at expected path
- [ ] Variables passed to `render()` correctly
- [ ] No typos in filter/function names
- [ ] No circular includes
- [ ] Error display enabled (development)

---

## Next Steps

- **[Best Practices](05-best-practices.md)** — Avoid common pitfalls
- **[Advanced Topics](04-advanced-topics.md)** — Deep dives
- **[Examples](../examples/README.md)** — Working examples
