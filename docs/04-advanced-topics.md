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
│   ├── about.clarity.html          (fallback)
│   └── users/
│       └── list.clarity.html       (fallback)
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
    └── welcome.clarity.html
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

Clarity compiles `.clarity.html` templates into PHP classes and caches them on disk.

### How Caching Works

1. **First render:** the template is compiled to PHP and written to the cache directory
2. **Subsequent renders:** the cached file is loaded directly (one `require`, accelerated by OPcache)

### Compiler Version

Every compiled class records the `Compiler::COMPILER_VERSION` that produced it. A cached file stamped with a different version — or with no stamp at all — is stale and is recompiled. This covers upgrades that change the compiled PHP **without changing the template file**, so **upgrading Clarity never requires flushing the cache by hand**.

The compiled class also records the debug-mode flag and a digest of the [policy](09-policy-api.md#a-policy-change-invalidates-the-compiled-cache) it was built under; a change to either recompiles the template.

### Cache Configuration

```php
$engine->setCachePath(__DIR__ . '/cache/clarity'); // must be writable by the web server
$cachePath = $engine->getCachePath();              // default: sys_get_temp_dir() . '/clarity'

$engine->flushCache(); // delete all cached files
```

**Development:** optionally flush on every request:

```php
if ($_ENV['APP_ENV'] === 'development') {
    $engine->flushCache();
}
```

**Production:** use a persistent directory and let automatic invalidation handle updates — do **not** call `flushCache()` on every request.

### Cache Directory Structure

Cached files are organized by hash:

```
cache/clarity/
├── a1b2c3d4e5f6...php  (compiled: views/home.clarity.html)
├── b2c3d4e5f6a1...php  (compiled: layouts/main.clarity.html)
└── ...
```

File names are deterministic hashes of the template path.

## Auto-Escaping

Clarity automatically escapes all output for security by default.

### How Auto-Escaping Works

Every output expression is wrapped with `htmlspecialchars()`:

```twig
{{ userInput }}
<!-- If userInput = "<script>alert('XSS')</script>" -->
<!-- Output: &lt;script&gt;alert('XSS')&lt;/script&gt; — safe, displayed as text -->
```

```php
htmlspecialchars($vars['userInput'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
```

### Disabling Auto-Escaping (raw filter)

To output raw HTML, use the `raw` filter. It is a **compile-time marker** that disables the auto-escape wrapper, and it applies to the **entire expression** when it appears anywhere in the filter chain:

```twig
{{ article:sanitizedBody |> raw }}          {# sanitized HTML from a WYSIWYG editor #}
{{ renderedWidget |> raw }}                 {# pre-rendered HTML from your application #}
{{ data |> json |> raw }}                   {# JSON output #}
{{ description |> nl2br |> raw }}           {# HTML-generating filters like nl2br #}
{{ description |> trim |> nl2br |> raw }}   {# raw anywhere disables escaping for the whole chain #}
```

Only use `raw` with trusted content — never with user input.

### Safe HTML Generation

If you need to generate HTML in a filter, escape the dynamic content internally so the returned HTML is safe:

```php
$engine->addFilter('badge', function($value, string $type = 'default') {
    $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return "<span class=\"badge badge-$type\">$safeValue</span>";
});
```

```twig
{{ status |> badge('success') |> raw }}
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

Two consequences follow:

- **Clarity does not impose a severity policy.** Non-variable diagnostics are handed to you
  with their level translated to the matching `E_USER_*` constant (`E_WARNING` →
  `E_USER_WARNING`, `E_NOTICE` → `E_USER_NOTICE`). If your handler promotes warnings to
  exceptions, template warnings will abort the render — your choice, not Clarity's.
- **Diagnostics raised outside the template** are passed straight through to your handler
  unannotated.

### Error Messages

Clarity maps errors back to the **original template file and line**, even though the error occurs in compiled PHP:

```
Syntax error in template: unexpected token '}' in views/products/show.clarity.html on line 42
```

For a catalogue of common errors and their fixes, see the [Troubleshooting Guide](06-troubleshooting.md).

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

For more errors and fixes, see the [Troubleshooting Guide](06-troubleshooting.md).

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

Clarity enforces its security through **compile-time checks** — nothing is checked at render time, so the restrictions cost nothing to enforce.

What a template may reach is decided by a **policy**: a set of capabilities plus two allowlists. The default is `Policy::sandboxed()`, the most restrictive one. See [The Policy API](09-policy-api.md) for the full reference; this page describes what the default policy refuses.

### Compile-Time Restrictions

Under the default policy, the following are rejected at compile time (the template won't compile):

```twig
{{ $variable }}                        {# ERROR: direct PHP variables #}
{{ strtoupper(name) }}                 {# ERROR: unregistered function calls #}
{{ file_get_contents('/etc/passwd') }} {# ERROR #}
{{ user.getName() }}                   {# ERROR: method calls #}
{{ $x = 5; }}                          {# ERROR: PHP statements #}
{{ `ls -la` }}                         {# ERROR: backticks, heredocs, PHP tags #}
{{ new DateTime() }}                   {# ERROR: needs the 'newExpressions' capability #}
{{ Foo::create() }}                    {# ERROR: needs the 'staticCalls' capability #}
{{ Foo\Bar }}                          {# ERROR: a class name is not a value #}
```

`instanceof` is the exception: `x instanceof Foo` takes a class name because that is what the operator means, and it reaches nothing the scope did not already hold. It works under every policy.

### Object and Value Handling

The scope passed to `render()` is handed to the template **unchanged** — there is no eager object → array conversion. `a.b` is an **object property read** (`->b`); `a:b` is an **array key read** (`['b']`); PHP's own visibility rules apply:

```php
class User {
    public $name = 'John';
    private $password = 'secret';
}

$user = new User();
$engine->render('page', ['user' => $user]);
```

```twig
{{ user.name }}       {# 'John' — real property read #}
{{ user:name }}       {# ERROR: a key read on an object #}
{{ user.getName() }}  {# COMPILE ERROR: method calls not allowed #}
```

**Containers read public properties — not `toArray()`.** Container operations — `{% for %}`, `keys`, `values`, `length`, `first`, `last` — read an object's **public properties** (`toArray()` and `JsonSerializable` are not consulted):

```php
class User {
    public string $name = 'Jane';
    private string $secret = 'hidden';
}
```

```twig
{% for key, value in user %}[{{ key }}={{ value }}]{% endfor %}
{# [name=Jane] — private state never leaks #}
```

`Traversable` objects are iterated instead. A value object with no public properties that implements `Stringable` keeps its string form, which is why `length` of a `Money` object counts the characters of its `__toString()`.

**Value handling** for a value passed to `render()`:

| Value                                                     | Behaviour                                                     |
| --------------------------------------------------------- | ------------------------------------------------------------- |
| `DateTimeInterface`                                       | Kept as an object; the `date` filter accepts it directly      |
| object with public properties                             | Read as properties (`a.b`); iterated by its public properties |
| `Traversable`                                             | Iterated for container operations                             |
| object with **no** public properties that is `Stringable` | Output via its `__toString()` value                           |
| scalar / `null`                                           | passed through                                                |

### Lambda Security

Lambdas in `map`, `filter`, `reduce` only accept:

1. **Lambda expressions** (parsed at compile time)
2. **Filter references** (validated at compile time)

**NOT allowed:**

```twig
{# Cannot pass callable via variable #}
{% set callback = someCallable %}
{{ items |> map(callback) }} {# ERROR #}
```

**Allowed:**

```twig
{{ items |> map(i => i:name) }} {# Lambda: safe #}
{{ items |> map("upper") }}     {# Filter reference: safe #}
```

### Registered Filters/Functions

Only **registered** filters and functions are callable under the default policy:

```php
$engine->addFilter('customFilter', $callable);
```

```twig
{{ value |> customFilter }} {# Allowed: registered #}
{{ value |> notRegistered }} {# ERROR: not registered #}
```

A policy can also allow a PHP function to be called **by its own name**, without
registering anything:

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::custom()->allowFunctions('strtoupper', 'count'));
```

### Policies

A policy answers one question: _what is this template allowed to reach?_ It is a set of capabilities plus two allowlists, and the default is sandboxed:

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::sandboxed());   // the default
$engine->setPolicy(Policy::trusted());     // raw PHP, method calls and superglobals
$engine->setPolicy(Policy::open());        // every capability
$engine->setPolicy(Policy::custom()        // the default, plus named grants
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count'));

// Guardrails on top of an open policy — "everything except exec" has no allowlist form:
$engine->setPolicy(Policy::open()->denyFunctions('exec', 'system', 'proc_open'));
```

**Every capability that reaches PHP is a real grant.** `rawPhp`, `phpVariables`, `methodCalls`, `newExpressions` and `staticCalls` are equivalent to executing arbitrary PHP from a template, and `Policy::open()` turns all of them on. Use it only for templates written and reviewed by trusted authors.

Two things hold in every policy:

- **The engine's render frame stays out of reach.** A template cannot bind a `__c_`-prefixed name (the compiler rejects it), and `$$name` / `${expr}` variable-variable expansion resolves against the render scope, so it can never reach an engine internal.
- **`superglobals` is a separate grant.** Without it, `{{ _SERVER }}` is an ordinary scope read of an absent name and throws — even when `phpVariables` is on. With it, the name means PHP's own variable.

Every compiled template records a **digest** of the policy it was built under, and the loader recompiles when that differs from the current one — so a policy change never leaves a template compiled under an old policy in the cache. See [The Policy API](09-policy-api.md) for the capability and allowlist tables, presets, error messages, and what the policy deliberately does not cover.

## PHP Mode

A policy that grants PHP turns the engine into a template engine with the full power of PHP — **PHP mode**, also called _open mode_. It is not a different language: the syntax is identical and every registered filter and function still resolves first. What changes is what an _unregistered_ name or a refused construct may reach.

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::trusted());   // raw PHP, method calls, superglobals — but no `new`/`::`
$engine->setPolicy(Policy::open());      // the full-power mode
```

`Policy::trusted()` is PHP mode minus the two capabilities that let a template name a class of its own — `newExpressions` and `staticCalls` reach code the application never handed the template.

**PHP mode is a strict superset of the sandbox syntax.** Every template that compiles under `sandboxed()` compiles identically under `open()`; only a construct the policy refused becomes available. A template is therefore never written _against_ a mode — the same `{{ name }}` reads the render scope in both. See [Template Syntax → PHP Mode](01-template-syntax.md#php-mode) for the template author's view of what each capability adds.

> **`Policy::open()` is equivalent to executing arbitrary PHP.** Use it only for templates written and reviewed by trusted authors, never for a template a request can choose.

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

| Method                                      | Description                                        |
| ------------------------------------------- | -------------------------------------------------- |
| `setViewPath(string $path)`                 | Base directory for templates                       |
| `setLayout(?string $layout)`                | Default layout template                            |
| `setExtension(string $ext)`                 | File extension (default: `.clarity.html`)          |
| `setCachePath(string $path)`                | Cache directory                                    |
| `getCachePath(): string`                    | Get current cache path                             |
| `setPolicy(Policy\|array $policy)`          | What templates may reach (default: sandboxed)      |
| `getPolicy(): Policy`                       | The current policy                                 |
| `isSandboxed(): bool`                       | Whether the policy lets templates reach PHP at all |
| `flushCache(): void`                        | Delete all cached files                            |
| `addFilter(string $name, callable $fn)`     | Register custom filter                             |
| `addFunction(string $name, callable $fn)`   | Register custom function                           |
| `setLoader(TemplateLoader $loader)`         | Set custom template loader                         |
| `render(string $view, array $vars): string` | Render template and return HTML                    |

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

Modules are the recommended way to bundle related filters, functions, block directives, and services into a single reusable unit:

```php
use Clarity\Localization\IntlFormatModule;
use Clarity\Localization\TranslationModule;

$engine->use(new IntlFormatModule(['locale' => 'de_DE', 'timezone' => 'Europe/Berlin']));
$engine->use(new TranslationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'translations_path' => __DIR__ . '/locales',
]));
```

The built-in localization modules provide:

- **`IntlFormatModule`** — locale-aware number, currency, date and text filters backed by PHP's `intl` extension: `format_number`, `format_currency`, `percent`, `spellout`, `ordinal`, `format_date`, `format_relative`, `country_name`, `format_message`, …
- **`TranslationModule`** — a `t` filter for lookup in domain-separated locale files (PHP, JSON, YAML) plus the `{% with_t_domain %}` block for domain switching
- **`LocaleService`** — a push/pop locale stack with the `{% with_locale %}` block (auto-bootstrapped by the other two modules)

Custom modules implement `Clarity\ModuleInterface` with a single `register(ClarityEngine $engine): void` method.

**[Full module reference →](07-modules.md)**

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
    return "if (\$__c_sv['cache']->has(\$__cacheKey)) { ob_end_clean(); echo \$__c_sv['cache']->get(\$__cacheKey); } "
         . "else { \$__cached = ob_get_clean(); \$__c_sv['cache']->set(\$__cacheKey, \$__cached); echo \$__cached; }";
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

### The `__c_` prefix

The engine reserves the `__c_` prefix for its own internal variables. Therefore, template authors **cannot** bind a `__c_`-prefixed name.

## Services

Services are arbitrary objects registered into the engine and made available inside compiled templates via `$__c_sv['key']`. They're primarily used by modules to share mutable state (e.g. a locale stack or cache object) between registered filters/directives and inline filter PHP templates.

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

Inline filter PHP templates can reference services via `$__c_sv['key']`:

```php
$engine->addInlineFilter('t', [
    'php'     => "\$__c_sv['translator']->get(\$__c_sv['locale']->current(), {1})",
    'params'  => ['vars'],
    'defaults'=> ['vars' => 'null'],
]);
```

> **Note:** Services are infrastructure for module authors: an application that only registers filters and functions does not need them.

## Next Steps

- **[Best Practices](05-best-practices.md)** — Organization, naming, security
- **[Troubleshooting](06-troubleshooting.md)** — Common errors and solutions
- **[Examples](examples/README.md)** — See advanced features in action
