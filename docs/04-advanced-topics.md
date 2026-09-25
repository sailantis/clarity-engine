# Advanced Topics

This guide covers advanced Clarity features including template loaders, caching, auto-escaping, error handling, and Unicode support.

## Template Loaders

Clarity resolves template names through a pluggable loader system. The default `FileLoader` handles straightforward file-based resolution. Two additional loaders cover more advanced scenarios.

### Named Namespaces (`addNamespace`)

The easiest way to organise templates across multiple directories is the `addNamespace()` convenience method. Each namespace is a short alias that maps to a filesystem path; templates reference it with the `namespace::path` syntax.

```php
// Register namespaces individually
$engine->addNamespace('admin',      __DIR__ . '/views/admin');
$engine->addNamespace('emails',     __DIR__ . '/views/emails');
$engine->addNamespace('components', __DIR__ . '/views/components');

// Or pass them all at once in the constructor
$engine = new ClarityEngine([
    'viewPath'   => __DIR__ . '/views',
    'namespaces' => [
        'admin'      => __DIR__ . '/views/admin',
        'emails'     => __DIR__ . '/views/emails',
        'components' => __DIR__ . '/views/components',
    ],
]);
```

Use the namespace prefix inside templates:

```twig
{% include "admin::partials/sidebar" %}
{% extends "emails::layouts/base" %}
{{ include("components::card", { title: item:title }) }}
{# Unprefixed names resolve against the base viewPath: #}
{% extends "layouts/main" %}
```

`addNamespace()` returns `$this` and is fully chainable. Internally it sets up a `DomainRouterLoader` with the base `viewPath` as the fallback, so unprefixed template names continue to work as before.

To inspect registered namespaces at runtime:

```php
$map = $engine->getNamespaces(); // ['admin' => '/path/to/views/admin', ...]
```

### DomainRouterLoader

`DomainRouterLoader` dispatches template resolution based on a `domain::localName` prefix. This is the recommended way to organise templates across multiple directories or packages.

```php
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;

$engine->setLoader(new DomainRouterLoader(
    [
        'admin'      => new FileLoader(__DIR__ . '/views/admin'),
        'emails'     => new FileLoader(__DIR__ . '/views/emails'),
        'components' => new FileLoader(__DIR__ . '/views/components'),
    ],
    fallback: new FileLoader(__DIR__ . '/views'),  // handles names without a prefix
));
```

Templates are referenced using the `domain::path` syntax:

```twig
{% include "admin::sidebar" %}
{% extends "admin::layouts/base" %}
{{ include("emails::welcome", { userName: user:name }) }}
```

Dots and slashes are interchangeable as path separators within the local name:

```twig
{% include "admin::partials.sidebar" %}
{% include "admin::partials/sidebar" %}
{# Both are equivalent #}
```

If no `::` prefix is present and a fallback loader is configured, the name is passed to the fallback unchanged. If no fallback is configured, `load()` returns `null` (template not found).

#### Example Structure

```
views/
├── layouts/
│   └── main.clarity.html           (fallback)
├── pages/
│   ├── home.clarity.html           (fallback)
│   └── about.clarity.html          (fallback)
├── admin/                          (domain: admin)
│   ├── layouts/
│   │   └── admin.clarity.html
│   └── pages/
│       └── users.clarity.html
├── components/                     (domain: components)
│   ├── buttons/
│   │   └── primary.clarity.html
│   └── cards/
│       └── user-card.clarity.html
└── emails/                         (domain: emails)
    ├── layouts/
    │   └── email-base.clarity.html
    └── welcome.clarity.html
```

**Configuration:**

```php
$engine->setLoader(new DomainRouterLoader(
    [
        'admin'      => new FileLoader(__DIR__ . '/views/admin'),
        'components' => new FileLoader(__DIR__ . '/views/components'),
        'emails'     => new FileLoader(__DIR__ . '/views/emails'),
    ],
    fallback: new FileLoader(__DIR__ . '/views'),
));
```

**Usage in templates:**

```twig
{# Main site pages (no prefix, hits the fallback) #}
{% extends "layouts/main" %}
{# Admin area #}
{% include "admin::partials/header" %}
{# Reusable components #}
{% include "components::buttons/primary" %}
{# Email templates #}
{% extends "emails::layouts/email-base" %}
```

### CompositeLoader

`CompositeLoader` chains multiple loaders and returns the first non-`null` result. It is useful for overlaying a dynamic source (e.g. database or array) on top of a file-based one:

```php
use Clarity\Template\CompositeLoader;
use Clarity\Template\ArrayLoader;
use Clarity\Template\FileLoader;

$engine->setLoader(new CompositeLoader(
    new ArrayLoader(['promo' => '<p>{{ offer }}</p>']),  // checked first
    new FileLoader(__DIR__ . '/views'),                   // fallback
));
```

The loaders are tried in the order they are passed to the constructor. The first loader that returns a non-`null` `TemplateSource` wins.

### setExtension() and Loaders

When `setExtension()` is called on the engine it automatically propagates to all `FileLoader` instances inside any composite or domain-router loader:

```php
$engine->setExtension('.tpl.html');  // applies to every nested FileLoader
```

## Caching

Clarity compiles `.clarity.html` templates into PHP classes and caches them on disk for maximum performance.

### How Caching Works

1. **First Request:** Template is compiled to PHP and saved in the cache directory
2. **Subsequent Requests:** Cached PHP file is loaded directly (zero compilation overhead)
3. **Auto-Invalidation:** Cache is automatically regenerated when source files change
   or when the engine's compiler version changes

### Compiler Version

Every compiled class records the `Compiler::COMPILER_VERSION` that produced it. A
cached file stamped with a different version is treated as stale and recompiled,
and files written before versioning existed (no stamp) count as stale too.

This covers upgrades that change the PHP a template compiles to **without
changing the template file** — for example the 1.0 change of the two-variable
`for` loop from `(value, key)` to `(key, value)`. The source revision is
identical in such a case, so a revision check alone would keep executing the old
bindings silently. Upgrading Clarity therefore never requires flushing the cache
by hand.

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
- The engine's compiler version (`Compiler::COMPILER_VERSION`)

When any of these change, the cache is regenerated automatically.

#### Manual Cache Flush

Clear all cached templates:

```php
$engine->flushCache();
```

Use cases for manual flushing:

- Development when auto-invalidation doesn't work (rare)
- Troubleshooting cache issues

> **Note:** Flushing is _not_ required after upgrading Clarity — the compiler
> version stamp handles that. See [Compiler Version](#compiler-version) above.

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

#### `opcache.enable` does NOT cover RoadRunner

There are two switches and they govern different SAPIs:

| Setting              | Default | Governs                                                    |
| -------------------- | ------- | ---------------------------------------------------------- |
| `opcache.enable`     | `1`     | `fpm-fcgi`, `apache`, `cgi` — i.e. classic nginx → php-fpm |
| `opcache.enable_cli` | **`0`** | the `cli` SAPI only                                        |

A RoadRunner worker (and any Swoole/ReactPHP worker) is spawned as `cli`, so
**`opcache.enable_cli` is the switch that matters**, and its default is off.
With `opcache.enable = 1` alone, CLI scripts are never cached — every worker
recompiles and re-allocates its own copy of every file it loads.

This matters more for compiled templates than for ordinary code, because a
compiled class carries its metadata as _static property literals_. A static
array initialised from a literal is materialised per process when OPcache is
off, but served as an immutable shared-memory value when it is on. Measured on
a compiled class carrying a 1000-range source map
(`php -d opcache.enable_cli=0` vs `=1`, same file, per process):

| Configuration                      | Heap growth on load | File in OPcache script table |
| ---------------------------------- | ------------------- | ---------------------------- |
| OPcache off                        | 237,568 B           | no                           |
| `opcache.enable_cli=1`             | **144 B**           | yes                          |
| `opcache.enable=1`, `enable_cli=0` | 237,568 B           | no                           |

So for a resident worker:

```ini
; required for RoadRunner / Swoole workers — opcache.enable is not enough
opcache.enable_cli = 1
```

Verify rather than assume — the engine's own resident-memory measurements
exclude shared memory, so a worker with `enable_cli` off looks the same as one
with it on until you measure heap growth per worker.

#### The source map is stored packed

Each compiled class declares the source map used to map a runtime error back to
a template file and line (`$sourceMap`, plus the parallel `$sourceFiles` and the
body offset `$renderBodyLine`). These are read **only on the error path** —
PHP does not materialise a static property until it is read, so on the happy
path they cost nothing.

The map is stored as one packed string rather than the more natural
`list<[phpLine, fileIndex, templateLine]>` literal:

```php
public static string $sourceMap = '1,0,1;1,0,3;2,1,-3;2,1,1;...';
```

Line numbers are delta-encoded (the map is appended in ascending line order, so
the deltas stay in single digits however large the template is). For 1000
ranges the two encodings compare as:

| Encoding                     | Source in the class file | Retained heap (OPcache off) |
| ---------------------------- | ------------------------ | --------------------------- |
| nested `var_export()` arrays | 65,348 B                 | 236,536 B                   |
| packed string                | **10,450 B**             | **12,288 B**                |

That is ~95% less memory and ~91% less emitted source, on a real template
turning metadata from ~2.3× the size of the render body into a small fraction
of it. `var_export()` writes three small integers as a nested array costing ~65
bytes of PHP each — the encoding, not the location, was the cost.

The decode cost (~0.1 ms per 500 ranges) is paid once, on a path that is already
formatting an exception. A malformed literal decodes to an _empty_ map, so the
engine degrades to "no line mapping" rather than reporting a shifted, wrong
line. See `Clarity\Engine\SourceMap` for the format and
`tests/Engine/SourceMapTest.php` for the pinned round-trip guarantees.

#### Emitted annotations are line comments, not doc comments

The compiled class annotates each metadata property with a `//` line comment:

```php
// sourceMap: packed "lineDelta,fileIndex,tplLineDelta;" ranges
public static string $sourceMap = '1,0,1;...';
```

This looks like a style choice and is not. **OPcache retains doc comments but
discards line comments.** `opcache.save_comments` (on by default) keeps
`/** … */` in the compiled script so `ReflectionClass::getDocComment()` can work;
`//` comments are thrown away. Anything retained is then charged to the script's
shared-memory slot — for every cached template, for the life of the worker.

Measured on one compiled page (`opcache.enable_cli=1`,
`memory_consumption` from `opcache_get_status(true)`), the same file emitted both
ways:

| Emission                               | Source  | OPcache script memory |
| -------------------------------------- | ------- | --------------------- |
| `/** @var … */` doc comments           | 2,413 B | 6,672 B               |
| `// …` line comments                   | 2,001 B | **5,880 B**           |
| either, with `opcache.save_comments=0` | —       | 5,880 B               |

That is **792 B per compiled template**, and the last row is what proves the
whole difference is the retained annotation. A project with 1,000 templates
therefore holds ~750 KB less shared memory. Nothing reflects the emitted
comments (the metadata is read as a static property), so the annotation form is
free to choose.

Two caveats when measuring this yourself: an _isolated_ static-array literal is
inlined at compile time and can show no difference at all, so it must be
measured on a real compiled class; and `opcache_get_status(false)` returns an
empty `scripts` array — pass `true`.

The 412 B of source the change also saves per template is the smaller half of
the win: source size only matters at compile time, whereas the shared-memory
saving is per cached template in every worker.

See [User Memory: debugging.md](memory://debugging.md) for OPcache notes.

## Auto-Escaping

Clarity automatically escapes all output for security by default.

### How Auto-Escaping Works

Every output expression is wrapped with `htmlspecialchars()`:

```twig
{{ userInput }}
```

Compiles to:

```php
htmlspecialchars($vars['userInput'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
```

### Why Auto-Escaping Matters

**Without auto-escaping:**

```twig
{{ userComment }}
<!-- If userComment = "<script>alert('XSS')</script>" -->
<!-- Outputs: <script>alert('XSS')</script> -->
<!-- DANGER: Script executes! -->
```

**With auto-escaping (Clarity default):**

```twig
{{ userComment }}
<!-- Outputs: &lt;script&gt;alert('XSS')&lt;/script&gt; -->
<!-- SAFE: Displays as text, doesn't execute -->
```

### Disabling Auto-Escaping (raw filter)

To output raw HTML, use the `raw` filter:

```twig
{{ trustedHtml |> raw }}
```

The `raw` filter is a **compile-time marker** that disables the auto-escape wrapper.

### When to Use raw

```twig
{# 1. Sanitized HTML from a WYSIWYG editor #}
{{ article:sanitizedBody |> raw }}
{# 2. Pre-rendered HTML fragments from your application #}
{{ renderedWidget |> raw }}
{# 3. JSON output #}
{{ data |> json |> raw }}
{# 4. HTML-generating filters like nl2br #}
{{ description |> nl2br |> raw }}
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

```twig
{{ status |> badge('success') |> raw }}
```

**Key:** Filter escapes the dynamic content internally, so the returned HTML is safe.

### Multiple Filters and raw

When `raw` appears **anywhere** in the filter chain, auto-escaping is disabled for the entire expression:

```twig
{{ description |> trim |> nl2br |> raw }} {# No escaping applied (because of raw) #}
{{ description |> raw |> upper }} {# Still no escaping (raw anywhere in chain) #}
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
    echo "\nTemplate: " . $e->templateFile;
    echo "\nLine: " . $e->templateLine;
}
```

> **Use `$e->templateFile` / `$e->templateLine`, not `$e->getFile()` / `$e->getLine()`.**
> `getFile()` and `getLine()` report where the exception was _thrown_ — i.e. inside
> the engine or, for a syntax error, the generated cache file. `templateFile` and
> `templateLine` point at the originating `.clarity.html` source. When the location
> could not be resolved, `templateFile` falls back to the logical template name.

The original throwable is always available as `$e->getPrevious()`.

### What gets mapped

| Failure                                                                | Result                                                                                                                           |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Undefined variable / array key / null offset                           | `ClarityException` — the render is aborted                                                                                       |
| Syntax error in a template expression                                  | `ClarityException` wrapping the `ParseError`, pointing at the template line                                                      |
| Exception thrown by a filter, function, or inline-filter PHP           | `ClarityException` wrapping the original, pointing at the template line that invoked it                                          |
| `TypeError` / `Error` (e.g. a typed filter argument rejects the value) | `ClarityException` wrapping the original, pointing at the template line                                                          |
| Other PHP diagnostics (e.g. `foreach()` over `null`)                   | Handed to your own error handler, annotated `… in <template>:<line>`; **rendering continues** and the partial output is returned |
| Exception raised entirely outside the render path                      | Passed through unchanged, keeping its original type                                                                              |

### Interoperating with your own error handler

Clarity installs an error handler for the duration of a render and **chains** to whatever
handler was already installed, so your logging / error-reporting listener keeps working:

```php
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    $log->warning($msg, ['file' => $file, 'line' => $line]);
    return true;
});

// Diagnostics raised inside a template arrive here annotated with the template
// location, e.g. "foreach() argument must be of type array|object, null given
// in pages/list on line 4".
$engine->render('pages/list', $data);
```

Two consequences worth knowing:

- **Clarity does not impose a severity policy.** Non-variable diagnostics are handed to you
  with their level translated to the matching `E_USER_*` constant (`E_WARNING` →
  `E_USER_WARNING`, `E_NOTICE` → `E_USER_NOTICE`). If your handler promotes warnings to
  exceptions, template warnings will abort the render — your choice, not Clarity's.
- **Diagnostics raised outside the template** are passed straight through to your handler
  unannotated.

### Error Messages

Clarity maps errors back to the **original template file and line**:

```
Syntax error in template: unexpected token '}' in views/products/show.clarity.html on line 42
```

Even though the error occurs in compiled PHP, Clarity traces it back to the source `.clarity.html` file.

### Common Errors

#### Undefined Variable

```twig
{{ nonExistentVariable }}
```

**Error:** `Undefined array key "nonExistentVariable"`

**Solution:** Pass the variable to `render()`, or use `default` filter:

```twig
{{ nonExistentVariable |> default('N/A') }}
```

#### Undefined Filter

```twig
{{ value |> unknownFilter }}
```

**Error:** `Filter 'unknownFilter' is not registered`

**Solution:** Register the filter or fix the typo.

#### Syntax Errors

```twig
{{ user:name |> upper( }} {# Missing closing parenthesis #}
```

**Error:** `Syntax error: unexpected end of expression`

**Solution:** Check template syntax.

#### Circular Includes

```twig
{# a.clarity.html #}
{% include "b" %}
{# b.clarity.html #}
{% include "a" %} {# Circular! #}
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
        echo "\nFile: " . $e->templateFile;
        echo "\nLine: " . $e->templateLine;
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
    error_log("File: " . $e->templateFile . ":" . $e->templateLine);

    http_response_code(500);
    echo "Sorry, something went wrong. Please try again later.";
}
```

## Unicode Support

Clarity is fully Unicode-aware via the `mbstring` extension.

### Built-in Unicode Support

String filters use multibyte functions:

```twig
{{ "Ä Ö Ü ß" |> upper }} {# Output: "Ä Ö Ü SS" (Unicode-aware) #}
{{ "ПРИВЕТМИР" |> lower }} {# Output: "привет мир" #}
{{ "你好世界" |> length }} {# Output: 4 (characters, not bytes) #}
```

### UnicodeString Class

For advanced Unicode operations, use the `unicode` filter:

```twig
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

```twig
{{ "Hello 👋 World 🌍" |> length }} {# Output: 13 (counts emoji as 1 character each) #}
{{ "🚀🌟💡" |> reverse }} {# Output: "💡🌟🚀" #}
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

**Direct PHP variables:**

```twig
{{ $variable }} {# ERROR #}
```

**Arbitrary function calls:**

```twig
{{ strtoupper(name) }} {# ERROR #}
{{ file_get_contents('/etc/passwd') }} {# ERROR #}
```

**Method calls:**

```twig
{{ user:getName() }} {# ERROR #}
```

**PHP statements:**

```twig
{{ $x = 5; }} {# ERROR #}
```

**Backticks, heredocs, PHP tags:**

```twig
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

```twig
{{ user:name }} {# Works: public properties exposed #}
{{ user:password }} {# NULL: private properties hidden #}
{{ user:getName() }} {# COMPILE ERROR: method calls not allowed #}
```

Object → array is the **general rule**: the array is the object's _public_
properties, so visibility is enforced by PHP itself and is never widened by
anything a class chooses to implement.

**Custom serialization:**

Implement `toArray()` — or `JsonSerializable` if the class already needs it:

```php
class User {
    private $name;
    private $email;

    public function toArray(): array {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
```

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

`toArray()` is checked first, so a class implementing both uses `toArray()`.
Prefer `toArray()`: its `: array` return type is enforced, whereas
`jsonSerialize(): mixed` may return a scalar, in which case the template
receives that scalar rather than an array.

**Recognition order** for a value passed to `render()` (checked in this order,
each recursing afterwards so nested objects are converted too):

| Value                                                         | Becomes                                               |
| ------------------------------------------------------------- | ----------------------------------------------------- |
| `DateTimeInterface`                                           | ISO-8601 string, e.g. `2026-09-21T12:00:00+02:00`     |
| object with a public `toArray()`                              | the returned array                                    |
| `JsonSerializable`                                            | the result of `jsonSerialize()`                       |
| `Traversable`                                                 | array of its iterations                               |
| any other object                                              | array of its **public** properties (the general rule) |
| …an object with **no** public properties that is `Stringable` | its `__toString()` value                              |
| scalar / `null`                                               | passed through                                        |

Two consequences worth knowing:

- **`DateTime` works directly.** It becomes an ISO-8601 string, so
  `{{ order:createdAt |> date("Y-m-d") }}` renders correctly. Previously a
  `DateTime` object converted to `[]` and the filter printed `1970-01-01`.
- **`__toString()` is a last resort.** It is only consulted when an object
  exposes no public properties, so a value object such as `Money` (all state
  private) renders as `12.34`, while an object that _does_ have public
  properties keeps them instead of being replaced by its string form.

````

### Lambda Security

Lambdas in `map`, `filter`, `reduce` only accept:

1. **Lambda expressions** (parsed at compile time)
2. **Filter references** (validated at compile time)

**NOT allowed:**

```twig
{# Cannot pass callable via variable #}
{% set callback = someCallable %}
{{ items |> map(callback) }} {# ERROR #}
````

**Allowed:**

```twig
{{ items |> map(i => i:name) }} {# Lambda: safe #}
{{ items |> map("upper") }} {# Filter reference: safe #}
```

### Registered Filters/Functions

Only **registered** filters and functions are callable:

```php
$engine->addFilter('customFilter', $callable);
```

```twig
{{ value |> customFilter }} {# Allowed: registered #}
{{ value |> notRegistered }} {# ERROR: not registered #}
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
| `setLoader(TemplateLoader $loader)`         | Set custom template loader                |
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

// Domain-based loader (admin:: and emails:: prefixes, plus fallback)
$engine->setLoader(new \Clarity\Template\DomainRouterLoader(
    [
        'admin'  => new \Clarity\Template\FileLoader(__DIR__ . '/views/admin'),
        'emails' => new \Clarity\Template\FileLoader(__DIR__ . '/views/emails'),
    ],
    fallback: new \Clarity\Template\FileLoader(__DIR__ . '/views'),
));

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

## Modules

Modules are the recommended way to bundle related filters, functions, block directives, and services into a single reusable unit.

### Registering a Module

```php
use Clarity\ClarityEngine;
use Clarity\Localization\IntlFormatModule;
use Clarity\Localization\TranslationModule;

$engine = new ClarityEngine();

// Register a built-in module
$engine->use(new IntlFormatModule([
    'locale'   => 'de_DE',
    'timezone' => 'Europe/Berlin',
]));

$engine->use(new TranslationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'translations_path' => __DIR__ . '/locales',
]));
```

### Writing a Custom Module

Implement `Clarity\ModuleInterface`:

```php
use Clarity\ClarityEngine;
use Clarity\ModuleInterface;

class MyModule implements ModuleInterface
{
    public function register(ClarityEngine $engine): void
    {
        $engine->addFilter('shout', fn($v) => strtoupper($v) . '!');
        $engine->addFunction('now', fn() => date('Y-m-d H:i:s'));
    }
}

$engine->use(new MyModule());
```

### Built-in Modules

#### IntlFormatModule

Provides locale-aware number, currency, date, and text filters backed by PHP's `intl` extension. Requires `intl` to be installed; filters degrade gracefully otherwise.

```php
$engine->use(new \Clarity\Localization\IntlFormatModule([
    'locale'   => 'en_US',
    'timezone' => 'America/New_York',
]));
```

Registered filters: `format_number`, `format_currency`, `currency_name`, `currency_symbol`, `percent`, `scientific`, `spellout`, `ordinal`, `format_date`, `format_time`, `format_datetime`, `format_relative`, `country_name`, `language_name`, `locale_name`, `transliterate`, `format_message`.

```twig
{{ 1234567.89 |> format_number(2) }}
{{ price |> format_currency('USD') }}
{{ 0.75 |> percent }}
{{ 42 |> spellout }}
{{ 1 |> ordinal }}
{{ order:created_at |> format_date('long') }}
{{ order:created_at |> format_relative }}
{{ "DE" |> country_name }}
{{ "{count, plural, one{# item} other{# items}}" |> format_message({count: n}) }}
```

#### TranslationModule

Provides a `t` filter for looking up translations from domain-separated locale files (PHP, JSON, or YAML).

```php
$engine->use(new \Clarity\Localization\TranslationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'translations_path' => __DIR__ . '/locales',
    'default_domain'    => 'messages',
]));
```

```twig
{# Simple key lookup #}
{{ "logout" |> t }}

{# With placeholder substitution #}
{{ "greeting" |> t({name: user:name}) }}

{# Specific domain #}
{{ "title" |> t({}, domain:"common") }}
{{ "overview" |> t(domain:"books") }}

{# Switch domain for a block #}
{% with_t_domain "emails" %}
    {{ "subject" |> t }}
{% endwith_t_domain %}
```

Translation files use the naming convention `{domain}.{locale}.{ext}`:

```
locales/
├── messages.de_DE.yaml
├── messages.en_US.php
└── common.de_DE.json
```

#### LocaleService

Provides a push/pop locale stack for switching locales within templates. Both `IntlFormatModule` and `TranslationModule` auto-bootstrap it; register it explicitly if you need fine-grained control:

```php
$engine->use(new \Clarity\Localization\LocaleService(['locale' => 'de_DE']));
```

The `with_locale` block directive (registered by `TranslationModule`) allows per-block locale switching:

```twig
{% with_locale user:preferredLocale %}
    {{ "welcome" |> t }}
{% endwith_locale %}
```

## Debug Mode

Enable debug mode to add runtime safety checks in compiled templates:

```php
$engine->setDebugMode(true);
```

When active:

- Range loop steps are validated at runtime: a step of `0` throws a `RuntimeException`
- A step that moves away from the end (which would produce an infinite loop) also throws
- The compiled class records `$debugCompiled = true` so that cache files compiled under debug mode are automatically recompiled when the flag changes

```php
// Check whether debug mode is currently on
$engine->isDebugMode(); // bool
```

> **Tip:** Enable debug mode in development and disable it in production to keep generated code lean.

## Inline Filters

Inline filters are compiled **directly into the generated PHP expression** — no callable is invoked at runtime, making them zero-overhead alternatives to regular filters.

### Registering an Inline Filter

```php
$engine->addInlineFilter('dollars', [
    'php'     => '\number_format((float) {1}, 2, ".", ",") . " USD"',
]);
```

### With Parameters

```php
$engine->addInlineFilter('pad', [
    'php'      => '\str_pad((string) {1}, {2}, {3}, \STR_PAD_LEFT)',
    'params'   => ['length', 'char'],
    'defaults' => ['char' => "' '"],
]);
```

The template:

```twig
{{ invoiceNumber |> pad(8, '0') }}
```

Compiles to: `\str_pad((string) $vars['invoiceNumber'], 8, '0', \STR_PAD_LEFT)` — no function lookup at runtime.

### Template Syntax

| Placeholder | Meaning                           |
| ----------- | --------------------------------- |
| `{1}`       | The piped value                   |
| `{2}`       | First additional parameter        |
| `{3}`       | Second additional parameter, etc. |

## Custom Directives

Directives extend the template compiler with custom `{% keyword %}` tags. They are compiled at build time and emit raw PHP code.

### Registering Directives

```php
$engine->addDirective('cache', function(string $rest, string $path, int $line, callable $expr): string {
    // Always open the buffer, and remember the cache key for endcache.
    return "\$__cacheKey = {$expr(trim($rest))}; ob_start();";
});

$engine->addDirective('endcache', function(string $rest, string $path, int $line, callable $expr): string {
    // Close the buffer on BOTH branches: a hit discards it, a miss stores AND
    // emits it (storing alone would swallow the block's output).
    return "if (\$__sv['cache']->has(\$__cacheKey)) { ob_end_clean(); echo \$__sv['cache']->get(\$__cacheKey); } "
         . "else { \$__cached = ob_get_clean(); \$__sv['cache']->set(\$__cacheKey, \$__cached); echo \$__cached; }";
});
```

Use the `$expr` callable to convert any Clarity expression (variable or literal) to a PHP expression string.

### Handler Signature

```php
function (
    string   $rest,        // text after the keyword inside {% … %}
    string   $sourcePath,  // source file path (for error messages)
    int      $tplLine,     // template line number (for error messages)
    callable $processExpr  // fn(string): string — converts Clarity expr → PHP expr
): string                  // must return PHP statement(s) to emit
```

### Buffer Safety

A directive emits PHP into the render body, and that body already runs inside
one output buffer opened by the compiled class. Output buffering is therefore
safe — and is how a block-capturing directive (like the `cache` pair above)
works — but two rules keep it that way.

**Close every buffer you open, on every branch.** `ob_start()` in one handler
and `ob_get_clean()` in its partner is only balanced when the block always opens
one. If the open handler starts the buffer conditionally, the close handler must
still close something on the branch where it was never opened — otherwise it
silently closes _Clarity's_ buffer instead, and the output collected so far is
lost. The example above avoids this by always opening and then closing on both
branches.

**Never close a buffer you did not open.** An unconditional `ob_end_clean()`
in a directive can reach past the template into the caller's buffers (an outer
view engine, a framework response buffer). Clarity's own buffer level is
guaranteed by the compiled scaffold, not by your directive.

You do **not** need to clean up after a block that throws. The compiled
`render()` captures its buffer level in the local `$__obLevel` and, in its catch
block, drains every buffer opened above that level before rethrowing — so a
directive that opened a buffer and then let an exception escape cannot leak it.
This is deliberately handled by the scaffold rather than by each directive,
because a directive's close handler never runs when the block throws.
`COMPILER_VERSION` 6 is the release that introduced this drain;
`Clarity\Tests\Engine\OutputBufferTest` pins it.

`$__obLevel` joins `$__fl`, `$__fn` and `$__sv` as a name the scaffold owns.
Emitted directive PHP runs in the same scope, so do not reuse it.

## Services

Services are arbitrary objects registered into the engine and made available inside compiled templates via `$__sv['key']`. They're primarily used by modules to share mutable state (e.g. a locale stack or cache object) between registered filters/directives and inline filter PHP templates.

### Registering a Service

```php
$myService = new MyStatefulService();

$engine->addService('my_service', $myService);
```

### Checking and Retrieving Services

```php
if ($engine->hasService('my_service')) {
    $svc = $engine->getService('my_service');
}
```

### Accessing Services in Inline Filters

Inline filter PHP templates can reference services via `$__sv['key']`:

```php
$engine->addInlineFilter('t', [
    'php'     => "\$__sv['translator']->get(\$__sv['locale']->current(), {1})",
    'params'  => ['vars'],
    'defaults'=> ['vars' => 'null'],
]);
```

> **Note:** Services are primarily an infrastructure tool for module authors. Application code that only registers filters and functions does not need to use services directly.

## Next Steps

- **[Best Practices](05-best-practices.md)** — Organization, naming, security
- **[Troubleshooting](06-troubleshooting.md)** — Common errors and solutions
- **[Examples](examples/README.md)** — See advanced features in action
