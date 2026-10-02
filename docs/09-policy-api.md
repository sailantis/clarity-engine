# The Policy API

A template is compiled against a **policy** that decides what it may reach. A policy is a set of **capabilities** — each one the kind of construct it permits — plus two **allowlists** naming the PHP functions and filters a template may call.

Policies are enforced at **compile time**. A template that violates its policy fails to compile, and the error names the required change. Policies add no render-time checks.

```php
use Clarity\Engine\Policy;

// One of the ready-made modes …
$engine->setPolicy(Policy::restricted());   // the default
$engine->setPolicy(Policy::trusted());     // method calls, superglobals and PHP locals — but no raw `{% php %}` and no `new`/`::`
$engine->setPolicy(Policy::unrestricted());        // full PHP

// … or the default plus the grants you name
$engine->setPolicy(Policy::default()
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown'));
```

A new engine uses the restricted policy: templates can access their render scope and host-registered filters and functions, but nothing else.

---

## Presets

Use one of three presets or start with `default()` and add specific grants.

```php
Policy::restricted();     // the default, and the most restrictive
Policy::trusted();        // method calls and superglobals
Policy::unrestricted();   // every capability
Policy::default();        // the default, plus whatever you grant
```

### What each preset contains

The three ready-made modes differ only in their capabilities. This table is the complete difference between them.

| Capability          | `restricted()` | `trusted()` | `unrestricted()` |
| ------------------- | -------------- | ----------- | ---------------- |
| `variableVariables` | ✓              | ✓           | ✓                |
| `methodCalls`       | -              | **✓**       | **✓**            |
| `superglobals`      | -              | **✓**       | **✓**            |
| `phpVariables`      | -              | **✓**       | **✓**            |
| `rawPhp`            | -              | -           | **✓**            |
| `newExpressions`    | -              | -           | **✓**            |
| `staticCalls`       | -              | -           | **✓**            |

All presets start with empty allowlists and no denied functions. Empty allowlists
do not restrict names; see [The one rule](#the-one-rule).

### Choosing a preset

| Preset           | In one line                                                         | Appropriate when                                                                      |
| ---------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `restricted()`   | The template's scope and registered filters and functions.          | The default, especially for templates selected by a request.                          |
| `trusted()`      | Adds method calls, superglobals and PHP locals.                     | Templates you write that need object access.                                          |
| `unrestricted()` | Every capability: the full power of PHP.                            | Templates never chosen by a request, as a parity mode with Blade, Stempler or Plates. |
| `default()`      | `restricted()`, plus the grants you name.                           | Most applications: a narrow, explicit set of allowances.                              |

### `Policy::restricted()`

```php
$engine->setPolicy(Policy::restricted());
```

No capability that reaches PHP is enabled. Templates can read their render scope,
use Clarity tags and call registered filters and functions. They cannot use
`{% php %}`, call methods, read superglobals, construct classes or access static
members.

This is the default for `new ClarityEngine()`.

### `Policy::trusted()`

```php
$engine->setPolicy(Policy::trusted());
```

Enables `methodCalls`, `superglobals` and `phpVariables`. Templates can call
methods on scoped objects, read superglobals and access the scope through PHP
locals. They cannot use `{% php %}`, `new` or `::`.

`rawPhp`, `newExpressions` and `staticCalls` stay off. Inline PHP, class
construction and static calls can reach code the application did not pass to the
template.

### `Policy::unrestricted()`

```php
$engine->setPolicy(Policy::unrestricted());
```

Every capability is enabled; both allowlists are empty and no function is denied.
Templates have the full power of PHP. This is the engine's former PHP mode and
supports template languages that expect inline PHP.

Use it only for templates that are never chosen by a request.

### `Policy::default()`

```php
$policy = Policy::default()
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown');

$engine->setPolicy($policy);
```

Starts from `restricted()`. Add only the capabilities, functions and filters
your templates need.

---

## The model

### Capabilities

| Capability          | Default | What it grants                                                                                          |
| ------------------- | ------- | ------------------------------------------------------------------------------------------------------- |
| `rawPhp`            | `false` | `{% php CODE %}` tags                                                                                   |
| `methodCalls`       | `false` | `obj.method()` / `$obj->method()`, with arguments and dynamic names                                     |
| `superglobals`      | `false` | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                                                          |
| `phpVariables`      | `false` | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` the same thing |
| `variableVariables` | `true`  | `$$name` / `${expr}` (see [`variableVariables`](#variablevariables))                                    |
| `newExpressions`    | `false` | `new Foo(args)`                                                                                         |
| `staticCalls`       | `false` | `Foo::method(args)`, `Foo::CONST`, `Foo::class`, `Foo::$prop`                                           |

### Allowlists

| Allowlist   | Default | What it governs                                                                 |
| ----------- | ------- | ------------------------------------------------------------------------------- |
| `functions` | `[]`    | bare calls (`strtoupper(name)`) and filter steps that resolve to a PHP function |
| `filters`   | `[]`    | names accepted after `\|>`, which need not be functions                         |

### The one rule

> **An empty allowlist is no restriction. A non-empty allowlist is the complete
> set: only the listed names resolve, and anything else is a compile-time error.**

`denyFunctions(...)` takes precedence over the function allowlist. It blocks
PHP function calls resolved from template expressions, but does not inspect raw
`{% php %}` blocks:

```php
$engine->setPolicy(Policy::unrestricted()->denyFunctions('exec', 'system', 'proc_open'));
```

### `methodCalls` and `phpVariables` are independent

`methodCalls` permits method calls on objects in the scope. It works with either
`obj.method()` or `$obj->method()` syntax; both use the same capability. Method
calls do not require `phpVariables`, which exposes the scope as PHP locals. See
[Template Syntax → Method calls](01-template-syntax.md#method-calls).

### `variableVariables`

`$$name` and `${expr}` resolve against the render scope and loop locals under
every policy. They cannot access the engine's protected `__c_` variables, so
disabling this capability does not change what a template can reach. It is not
counted as "reaching PHP" for [policy inspection](#inspecting-a-policy).

### `superglobals`

Without `superglobals`, `{{ _SERVER }}` is a scope read and throws if the scope
does not contain that name. This remains true when `phpVariables` is enabled:

```php
// Refused without 'superglobals': a scope read of an absent name
$engine->setPolicy(Policy::default()->allowCapability('phpVariables'));

// Granted: the name now means PHP's own variable
$engine->setPolicy(Policy::default()->allowCapability('superglobals'));
```

The capability recognizes only `GLOBALS`, `_SERVER`, `_GET`, `_POST`, `_FILES`,
`_COOKIE`, `_SESSION`, `_REQUEST` and `_ENV`. Other names, such as `_SERVERX`,
are ordinary variables.

### `newExpressions` and `staticCalls`

Both compile class names as fully qualified names; for example,
`new DateTime()` becomes `new \DateTime`. Compiled templates have no `use`
statements, so this avoids resolving names based on the importing file.

`instanceof` works under every policy because it checks an object's class without
constructing or accessing one:

```twig
{% if order instanceof App\Order %}…{% endif %}
```

Class names in other positions, such as `{{ Foo\Bar }}`, are rejected.

---

## The API

### Fluent builders, array form accepted everywhere

```php
// Fluent — the primary shape
$policy = Policy::default()
    ->allowCapability('methodCalls', 'superglobals')
    ->denyCapability('variableVariables')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown')
    ->denyFunctions('exec');

// Array — for config files, which cannot call methods
$policy = Policy::fromArray([
    'capabilities'    => ['methodCalls' => true],
    'functions'       => ['strtoupper', 'count'],
    'filters'         => ['markdown'],
    'deniedFunctions' => ['exec'],
]);
```

Use the array form in `env.php` or framework configuration. `toArray()` produces
a round-trippable representation. Unknown keys and capability names are rejected.

`fromArray()` starts from `restricted()`, so a config only has to name what it
changes.

### Engine integration

```php
$engine->setPolicy(Policy::restricted());   // the default
$engine->setPolicy(Policy::trusted());
$engine->setPolicy(Policy::unrestricted());
$engine->setPolicy(['capabilities' => ['rawPhp' => true]]);   // array form
$policy = $engine->getPolicy();            // always a real object
```

`setPolicy()` accepts either form. `getPolicy()` always returns a policy; a new
engine uses `Policy::restricted()`.

### `allowFunctions()` vs `addFunction()`

These methods do different things:

- `addFunction('upper', $fn)` registers a **callable** under a name. Existing
  API, unchanged.
- `allowFunctions('strtoupper')` **permits a PHP function to be called by its own
  name**, without registering anything.

If a name is both registered and allowed, the registered callable is used. If it
is allowed but not registered, the PHP function is called. If neither, compilation
fails. A registered callable takes precedence over a PHP function with the same
name.

### Inspecting a policy

Use these methods for a summary without inspecting individual capabilities:

| Method                   | True when                                                     |
| ------------------------ | ------------------------------------------------------------- |
| `$policy->isUnrestricted()` | every capability is on **and** nothing is restricted or denied |
| `$policy->isSandboxed()` | no capability that reaches PHP is on                          |
| `$policy->allowsPhp()`   | a capability that reaches PHP is on                           |

`allowsPhp()` controls bare calls to unregistered names and filter steps that
fall back to PHP functions. An allowlist alone does not enable PHP access; it
only narrows which functions may be called. For example,
`Policy::restricted()->allowFunctions('count')` remains sandboxed. Add a
capability to enable PHP access:
`->allowCapability('methodCalls')->allowFunctions('count')`.

The engine also exposes `isSandboxed()` as a shortcut for
`getPolicy()->isSandboxed()`.

---

## Semantics

### Every rejection is a compile-time `ClarityException`

Capability, allowlist, function-call and filter-step violations raise a
`ClarityException` during compilation, not rendering. An unregistered filter
that cannot resolve to a PHP function also fails at compile time.

### The message names the remedy

Errors name the required change and report the template location through
`getFile()` / `getLine()` and `templateName` / `templateLine`. See
[Error Handling](04-advanced-topics.md#error-handling).

| Situation                         | Message                                                                                                                                                                                                                               |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| unregistered **function**         | `Call to unregistered function in context '…'. Register it via addFunction() first.`                                                                                                                                                  |
| unregistered **filter step**      | `Filter 'x' is not registered, and this policy does not allow a template to reach PHP, so there is nothing for it to resolve to. Register it with addFilter(), or grant a capability to let a PHP function of the same name be used.` |
| a **denied or unlisted** function | `Function 'x' is not allowed by this policy: it is not in the function allowlist, or it is denied. Add it with allowFunctions().`                                                                                                     |
| an **unlisted filter**            | `Filter 'x' is not registered and is not in the policy's filter allowlist. Add it with allowFilters(), or register it with addFilter().`                                                                                              |
| a **denied capability**           | `'{% php %}' is not allowed by this policy. Grant the 'rawPhp' capability to allow it.`                                                                                                                                               |

### A policy change invalidates the compiled cache

Compiled templates record a digest of the effective policy. The loader recompiles
when the digest changes, so a template compiled under one policy is not reused
under another. The digest includes sorted capabilities and allowlist entries.
Compiled files store the digest, not the policy itself.

### Registration order does not change the policy

The policy is resolved against the function registry during compilation, so
`allowFunctions('a', 'b')` works regardless of registration order. The last
policy set before rendering applies.

---

## What the policy does not cover

### Strict types

Filter input types are separate from capabilities, which control what a template
may reach.

```twig
{{ 42 |> upper }}    {# an integer reaching a string filter #}
```

A compiled template does not declare strict types, so PHP may coerce values.
Built-in filters can also cast explicitly; for example, `upper` compiles to:

```php
\mb_strtoupper((string) {1})
```

This casts the input to a string. Registered filters and PHP-function filters
receive the raw value; PHP applies its normal coercion rules.

Latte can emit `declare(strict_types=1)` and enforce filter signatures at the call
boundary.

### A `tags` allowlist

There is no tag allowlist. Clarity tags do not expose access beyond the
capabilities, filters and functions already described. Restricting tags is a
linting concern, not a security control.

### Method and property allowlists by class

There are no per-class method or property allowlists. The object is only known at
render time; `methodCalls` permits calls on any object in the scope.

### A read-only mode

There is no read-only mode. Make data read-only when building the render scope.

### Runtime checks

Policies are enforced at compile time; the engine does not recheck them while
rendering.

---

## Comparison with other engines

Other template engines organize these controls differently:

- **Superglobals.** Latte's `SecurityPolicy` controls tags, filters, functions,
  methods and properties, but has no superglobal control. Clarity treats
  superglobals as a separate capability.
- **Includes.** Clarity's `{% include %}` accepts a literal name; the
  [view path](#the-view-path-is-a-boundary) keeps it inside the view root.
- **Variable variables.** Latte rejects `${expr}` in its sandbox. Clarity permits
  it under every policy because it resolves only names in the render scope.
- **Blade** has no policy; templates are PHP. **Twig** has a `SecurityPolicy`
  for tags, filters, methods, properties and functions. Its `is defined`, like
  Clarity's, uses `array_key_exists`.

---

## Migrating from the old API

Earlier versions used a `sandbox` boolean and a denied-functions list. `Policy`
replaces both.

| Old API                           | Express with                                                                |
| --------------------------------- | --------------------------------------------------------------------------- |
| `setSandboxMode(true)`            | `setPolicy(Policy::restricted())` — or just the default                     |
| `setSandboxMode(false)`           | `setPolicy(Policy::unrestricted())`                                         |
| `['sandbox' => true/false]`       | `['policy' => Policy::restricted()/unrestricted()]`                         |
| `isSandboxed()`                   | kept on the engine; `getPolicy()->isSandboxed()` / `isUnrestricted()` for the parts |
| `setDeniedFunctions(['exec', …])` | `setPolicy(Policy::unrestricted()->denyFunctions('exec', …))`               |
| `getDeniedFunctions()`            | `getPolicy()->deniedFunctions()`                                            |
| `['deniedFunctions' => [...]]`    | `['policy' => ['deniedFunctions' => [...]]]`                                |

Removing `setSandboxMode()` is the only breaking change. Replace each call with
`setPolicy()`; no alias is provided.

## The view path is a boundary

The policy does not control file access. The loader confines template names to
the view path:

| Rejected                 | Because                                                                         |
| ------------------------ | ------------------------------------------------------------------------------- |
| `/etc/passwd`            | absolute                                                                        |
| `C:/x`, `\\server\share` | absolute                                                                        |
| `../secret`              | a `..` segment                                                                  |
| `admin/../../secret`     | a `..` segment, anywhere in the name                                            |
| `admin//user`, `''`      | an empty segment — it would collapse on disk while staying a distinct cache key |

The loader treats `/`, `.` and `\` as separators and requires every segment to be
a plain name. This prevents `.` or `..` segments from escaping the view path.

Absolute template names are rejected. To use a different root, configure it on
the host, for example `new FileLoader('/their/root')`. The same path rules apply.

Custom loaders must enforce the same rules as part of the `TemplateLoader`
contract.

---

## Related reading

- [Best Practices](05-best-practices.md) — organizing templates under the default
  policy.
- [Troubleshooting](06-troubleshooting.md) — diagnosing a compile-time rejection.
- [The benchmark](08-benchmark.md) — where the policy's compile-time cost sits.
