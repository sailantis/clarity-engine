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

> **Note:** Flushing is _not_ required after upgrading Clarity Engine — the compiler version stamp handles that. See [Compiler Version](#compiler-version) above.

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

- Cache file is loaded directly (one `require` statement)
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

**Error:** `Filter 'unknownFilter' is not registered, and the sandbox is enabled, …`

Raised at compile time. **Solution:** register the filter, fix the typo, or — for
a trusted template that means to call a PHP function — run in PHP mode.

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

Clarity enforces strict security through compilation-time checks. **Nothing is
checked at render time**, so the restrictions cost nothing to enforce.

What a template may reach is decided by a **policy**: a set of _capabilities_
plus two _allowlists_. The default is the most restrictive one.

> See [The Policy API](09-policy-api.md) for the full reference. The rest of this
> page describes what the default policy refuses, which is the safe mode the
> engine has always had.

### Compile-Time Restrictions

The following are **rejected at compile time** (template won't compile) under
the default policy:

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

**Class names:**

```twig
{{ new DateTime() }}    {# ERROR: needs the 'newExpressions' capability #}
{{ Foo::create() }}     {# ERROR: needs the 'staticCalls' capability #}
{{ Foo\Bar }}           {# ERROR: a class name is not a value #}
```

`instanceof` is the exception: `x instanceof Foo` takes a class name because
that is what the operator means, and it reaches nothing the scope did not
already hold. It works under every policy.

### Object and Value Handling

**Objects stay objects.**

The scope passed to `render()` is handed to the template **unchanged** — there
is no eager object → array conversion. The access operator decides what is read,
and PHP's own visibility rules apply:

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
{{ user.name }}       {# 'John' — real property read #}
{{ user:name }}       {# ERROR: a key read on an object #}
{{ user:getName() }}  {# COMPILE ERROR: method calls not allowed #}
```

`a.b` is an **object property read** (`->b`); `a:b` is an **array key read**
(`['b']`). A public property is a property, not a container entry, so
`{{ user:name }}` does **not** see it — use `{{ user.name }}`. Visibility is
enforced by PHP itself, so private/protected state is never exposed.

**Containers read public properties — not `toArray()`.**

The old object → array conversion is gone, and with it the `toArray()` /
`JsonSerializable` access path. **Container** operations — `{% for %}`, `keys`,
`values`, `length`, `first`, `last` — read an object's **public properties**:

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

`Traversable` objects are iterated instead. `toArray()` and `JsonSerializable`
are **not** consulted on the access path; a value object with no public
properties that implements `Stringable` keeps its string form, which is why
`length` of a `Money` object counts the characters of its `__toString()`.

**Value handling** for a value passed to `render()`:

| Value                                                     | Behaviour                                                     |
| --------------------------------------------------------- | ------------------------------------------------------------- |
| `DateTimeInterface`                                       | Kept as an object; the `date` filter accepts it directly      |
| object with public properties                             | Read as properties (`a.b`); iterated by its public properties |
| `Traversable`                                             | Iterated for container operations                             |
| object with **no** public properties that is `Stringable` | Output via its `__toString()` value                           |
| scalar / `null`                                           | passed through                                                |

Two consequences worth knowing:

- **`DateTime` works directly.** It is kept as an object and accepted by the
  `date` filter, so `{{ order.createdAt |> date("Y-m-d") }}` renders correctly.
- **`__toString()` is a fallback.** It is only used when an object exposes no
  public properties, so a value object such as `Money` (all state private)
  renders as `12.34`, while an object that _does_ have public properties keeps
  them instead of being replaced by its string form.

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
{{ items |> map("upper") }} {# Filter reference: safe #}
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

A policy answers one question: _what is this template allowed to reach?_ It is a
set of capabilities plus two allowlists, and the default is sandboxed.

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::sandboxed());   // the default
$engine->setPolicy(Policy::open());        // everything on
$engine->setPolicy(Policy::trusted());     // trusted templates, but no `new`
$engine->setPolicy(Policy::custom()        // the useful one
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count'));
```

| Capability          | Default | What it grants                                                                                         |
| ------------------- | ------- | ------------------------------------------------------------------------------------------------------ |
| `rawPhp`            | `false` | `{% php CODE %}` tags and `{% php %}…{% endphp %}` blocks                                              |
| `methodCalls`       | `false` | `$obj->method()` and `$obj->method(arg, …)`                                                            |
| `superglobals`      | `false` | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                                                         |
| `phpVariables`      | `false` | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` the same name |
| `variableVariables` | `true`  | `$$name` / `${expr}`                                                                                   |
| `newExpressions`    | `false` | `new Foo(args)`                                                                                        |
| `staticCalls`       | `false` | `Foo::method(args)`, `Foo::CONST`, `Foo::class`, `Foo::$prop`                                          |

| Allowlist   | Default | What it governs                                                                 |
| ----------- | ------- | ------------------------------------------------------------------------------- |
| `functions` | `[]`    | bare calls (`strtoupper(name)`) and filter steps that resolve to a PHP function |
| `filters`   | `[]`    | names accepted after `\|>`, which need not be functions                         |

**An empty allowlist is not a denial — it is no restriction.** A non-empty one is
the complete set: only the listed names resolve, and anything else is a
compile-time error. That is what makes `Policy::open()` exactly the engine's
full-power mode rather than a mode that happens to deny everything.

`denyFunctions(...)` applies last and wins, so an allowlist entry can still be
revoked by name.

**`Policy::open()` is equivalent to executing arbitrary PHP.** It grants every
capability on the list above. Use it only for templates written and reviewed by
trusted authors. The same is true of `rawPhp`, `phpVariables` and `methodCalls`
individually — each is a real grant, not a cosmetic one.

#### Guardrails

An application can add its own limits on top of an open policy. This is the one
case an allowlist cannot express, because "everything except `exec`" has no
positive form:

```php
$engine->setPolicy(Policy::open()->denyFunctions('exec', 'system', 'proc_open'));
```

What still holds in every policy is that the engine's own render-frame namespace
stays out of reach: a template cannot bind a `__c_`-prefixed name, and
`$$name` / `${expr}` variable-variable expansion resolves against the render
scope, so it can never reach an engine internal.

`superglobals` is a genuinely separate grant. Without it, `{{ _SERVER }}` is an
ordinary scope read of a name that is absent, so it throws — even when
`phpVariables` is on. With it, the name means PHP's own variable.

#### Policy changes and the cache

Every compiled template records a **digest** of the policy it was built under,
and the loader recompiles when that differs from the current one. Without this, a
template compiled under one policy could be served under another, since the cache
keys on template source only.

The digest covers every capability and every allowlist entry, so changing a
single entry — or deleting one — invalidates what needs it. A digest rather than
the policy itself, because the compiled file is source code that ships to a
server and should not carry a readable inventory of what a template may call.

## PHP Mode

A policy that grants PHP turns the engine into a template engine with the full
power of PHP — **PHP mode**, also called _open mode_. It is not a different
language: the syntax is identical and every registered filter and function still
resolves first. What changes is what an _unregistered_ name or a refused
construct may reach.

The whole of PHP mode is expressible as a policy, and three presets cover how
much rope a template gets:

| Preset                          | Grants                                                                      |
| ------------------------------- | --------------------------------------------------------------------------- |
| `Policy::sandboxed()` (default) | nothing that reaches PHP — the safe mode the engine has always had          |
| `Policy::trusted()`             | `rawPhp`, `methodCalls`, `superglobals`, `phpVariables` — but not `new`/`::` |
| `Policy::open()`                | every capability — equivalent to executing arbitrary PHP                    |

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::trusted());
$engine->setPolicy(Policy::open());   // the full-power mode
```

`Policy::trusted()` is the one worth knowing: it is PHP mode minus the two
capabilities that let a template name a class of its own. `newExpressions` and
`staticCalls` are a different order of trust from calling a method on an object
the application already passed in, so `trusted()` withholds them.

**What each capability turns on** is tabulated under [Policies](#policies)
below. The one that is not obvious is `superglobals`: without it a superglobal
name is an ordinary scope read, so `{{ _SERVER }}` throws _even when_
`phpVariables` is granted, and `{{ $_SERVER }}` is the only spelling that means
PHP's own variable.

**PHP mode is a strict superset of the sandbox syntax.** Every template that
compiles under `sandboxed()` compiles identically under `open()`; only a
construct the policy refused becomes available. A template is therefore never
written _against_ a mode — the same `{{ name }}` reads the render scope in both.
See [Template Syntax → PHP Mode](01-template-syntax.md#php-mode) for the
template author's view of what each capability adds.

> **`Policy::open()` is equivalent to executing arbitrary PHP.** Use it only for
templates written and reviewed by trusted authors, never for a template a
request can choose.

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
`render()` captures its buffer level in the local `$__c_ob_level` and, in its catch
block, drains every buffer opened above that level before rethrowing — so a
directive that opened a buffer and then let an exception escape cannot leak it.
This is deliberately handled by the scaffold rather than by each directive,
because a directive's close handler never runs when the block throws.
`COMPILER_VERSION` 6 is the release that introduced this drain;
`Clarity\Tests\Engine\OutputBufferTest` pins it.

### The `__c_` prefix

Every PHP variable the engine binds into the render frame carries the `__c_`
prefix (`c` for Clarity): `$__c_fn`, `$__c_sv`, `$__c_va`,
`$__c_ob_level`, `$__c_e`, and the `$__c_tmp` / `$__c_val` temporaries that
inlined filters and callable-filter lambdas assign.

Emitted directive and inline-filter PHP runs in that same scope, so use these
names and do not bind your own with the prefix. It is a **prefix rule**, not a
list: a name is protected by being spelled `__c_…`, so a new internal cannot
silently collide with a template variable.

Template authors cannot bind a `__c_`-prefixed name (the compiler rejects it in
both modes), because doing so would swap an internal for the rest of the render
— binding `__c_fn` would break every filter and call after that line. Every
other name starting with underscores — `__foo`, `_c_foo`, `___foo` — is an
ordinary template variable.

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

> **Note:** Services are primarily an infrastructure tool for module authors. Application code that only registers filters and functions does not need to use services directly.

## Next Steps

- **[Best Practices](05-best-practices.md)** — Organization, naming, security
- **[Troubleshooting](06-troubleshooting.md)** — Common errors and solutions
- **[Examples](examples/README.md)** — See advanced features in action
