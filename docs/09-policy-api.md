# The Policy API

A template is compiled against a **policy** that decides what it may reach. A policy is a set of **rules** — each one the kind of construct it permits — plus two **allowlists** naming the PHP functions and filters a template may call.

Policies are enforced at **compile time**. A template that violates its policy fails to compile, and the error names the required change. Policies add no render-time checks.

```php
use Clarity\Engine\Policy;

// One of the ready-made modes …
$engine->setPolicy(Policy::restricted());   // the default
$engine->setPolicy(Policy::trusted());     // PHP function calls, method calls, superglobals and PHP locals — but no raw `{% php %}` and no `new`/`::`
$engine->setPolicy(Policy::unrestricted());        // full PHP

// … or the default plus the grants you name
$engine->setPolicy(Policy::default()
    ->allowRule('methodCalls')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown'));
```

A new engine uses the restricted policy: templates can access their render scope and host-registered filters and functions, but nothing else.

---

## Presets

Use one of three presets or start with `default()` and add specific grants.

```php
Policy::restricted();     // the default, and the most restrictive
Policy::trusted();        // PHP function calls, method calls and superglobals
Policy::unrestricted();   // every rule
Policy::default();        // the default, plus whatever you grant
```

### What each preset contains

The three ready-made modes differ only in their rules. This table is the complete difference between them.

| Rule          | `restricted()` | `trusted()` | `unrestricted()` |
| ------------------- | -------------- | ----------- | ---------------- |
| `variableVariables` | ✓              | ✓           | ✓                |
| `strictTypes`       | ✓              | ✓           | ✓                |
| `phpFunctions`      | -              | **✓**       | **✓**            |
| `methodCalls`       | -              | **✓**       | **✓**            |
| `superglobals`      | -              | **✓**       | **✓**            |
| `phpVariables`      | -              | **✓**       | **✓**            |
| `rawPhp`            | -              | -           | **✓**            |
| `newExpressions`    | -              | -           | **✓**            |
| `staticCalls`       | -              | -           | **✓**            |

All presets start with empty allowlists and no denied functions. Empty allowlists
do not restrict names; see [The rule for allowlists](#the-rule-for-allowlists).

### Choosing a preset

| Preset           | In one line                                                         | Appropriate when                                                                      |
| ---------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `restricted()`   | The template's scope and registered filters and functions.          | The default, especially for templates selected by a request.                          |
| `trusted()`      | Adds PHP function calls, method calls, superglobals and PHP locals.                     | Templates you write that need object access.                                          |
| `unrestricted()` | Every rule: the full power of PHP.                            | Templates never chosen by a request, as a parity mode with Blade, Stempler or Plates. |
| `default()`      | `restricted()`, plus the grants you name.                           | Most applications: a narrow, explicit set of allowances.                              |

### `Policy::restricted()`

```php
$engine->setPolicy(Policy::restricted());
```

No rule that reaches PHP is enabled. Templates can read their render scope,
use Clarity tags and call registered filters and functions. They cannot use
`{% php %}`, call methods, read superglobals, construct classes or access static
members.

`strictTypes` is the one grant a sandboxed policy carries, because it is not a
reach rule: it changes the contract at a call boundary, not what a template can
name. See [`strictTypes`](#stricttypes).

This is the default for `new ClarityEngine()`.

### `Policy::trusted()`

```php
$engine->setPolicy(Policy::trusted());
```

Enables `phpFunctions`, `methodCalls`, `superglobals` and `phpVariables`. Templates can call
PHP functions and methods on scoped objects, read superglobals and access the
scope through PHP locals. They cannot use `{% php %}`, `new` or `::`.

`rawPhp`, `newExpressions` and `staticCalls` stay off. Inline PHP, class
construction and static calls can reach code the application did not pass to the
template.

### `Policy::unrestricted()`

```php
$engine->setPolicy(Policy::unrestricted());
```

Every rule is enabled; both allowlists are empty and no function is denied.
Templates have the full power of PHP. This is the engine's former PHP mode and
supports template languages that expect inline PHP.

Use it only for templates that are never chosen by a request.

### `Policy::default()`

```php
$policy = Policy::default()
    ->allowRule('methodCalls')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown');

$engine->setPolicy($policy);
```

Starts from `restricted()`. Add only the rules, functions and filters
your templates need.

---

## The model

### Rules

| Rule          | Default | What it grants                                                                                          |
| ------------------- | ------- | ------------------------------------------------------------------------------------------------------- |
| `phpFunctions`      | `false` | bare calls (`strtoupper(name)`) and filter steps that resolve to a PHP function                         |
| `rawPhp`            | `false` | `{% php CODE %}` tags                                                                                   |
| `methodCalls`       | `false` | `obj.method()` / `$obj->method()`, with arguments and dynamic names                                     |
| `superglobals`      | `false` | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                                                          |
| `phpVariables`      | `false` | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` the same thing |
| `variableVariables` | `true`  | `$$name` / `${expr}` (see [`variableVariables`](#variablevariables))                                    |
| `newExpressions`    | `false` | `new Foo(args)`                                                                                         |
| `staticCalls`       | `false` | `Foo::method(args)`, `Foo::CONST`, `Foo::class`, `Foo::$prop`                                           |
| `strictTypes`       | `true`  | `declare(strict_types=1)` in the compiled template (see [`strictTypes`](#stricttypes))                  |

Deny `strictTypes` to opt a template back into PHP's weak-mode coercion.

### Allowlists

| Allowlist   | Default | What it governs                                                                 |
| ----------- | ------- | ------------------------------------------------------------------------------- |
| `functions` | `[]`    | names the `phpFunctions` rule may resolve to a PHP function                     |
| `filters`   | `[]`    | names accepted after `\|>`, which need not be functions                         |

### The rule for allowlists

> **An empty allowlist is no restriction. A non-empty allowlist is the complete
> set: only the listed names resolve, and anything else is a compile-time error.**

A filter allowlist only narrows: it is consulted where a filter step is already
being resolved, so `Policy::restricted()->allowFilters('markdown')` stays
sandboxed. A **function** allowlist is different in one respect: a PHP function
call is the construct the `phpFunctions` rule names, so `allowFunctions()` turns
that rule on as it grants. `Policy::restricted()->allowFunctions('count')` is
therefore enough on its own — no second, unrelated rule to carry it. Only a
non-empty call does so: `allowFunctions()` with no names is a no-op, since an
empty allowlist is unrestricted and a no-argument call must not grant every
function.

`denyFunctions(...)` takes precedence over the function allowlist. It blocks
PHP function calls resolved from template expressions, but does not inspect raw
`{% php %}` blocks:

```php
$engine->setPolicy(Policy::unrestricted()->denyFunctions('exec', 'system', 'proc_open'));
```

`denyFunctions()` only narrows. It never turns the `phpFunctions` rule on, so
`Policy::restricted()->denyFunctions('exec')` remains sandboxed — the denial only
means something where another rule has already made a PHP function reachable.

### `methodCalls` and `phpVariables` are independent

`methodCalls` permits method calls on objects in the scope. It works with either
`obj.method()` or `$obj->method()` syntax; both use the same rule. Method
calls do not require `phpVariables`, which exposes the scope as PHP locals. See
[Template Syntax → Method calls](01-template-syntax.md#method-calls).

### `variableVariables`

`$$name` and `${expr}` resolve against the render scope and loop locals under
every policy. They cannot access the engine's protected `__c_` variables, so
disabling this rule does not change what a template can reach. It is not
counted as "reaching PHP" for [policy inspection](#inspecting-a-policy).

### `superglobals`

Without `superglobals`, `{{ _SERVER }}` is a scope read and throws if the scope
does not contain that name. This remains true when `phpVariables` is enabled:

```php
// Refused without 'superglobals': a scope read of an absent name
$engine->setPolicy(Policy::default()->allowRule('phpVariables'));

// Granted: the name now means PHP's own variable
$engine->setPolicy(Policy::default()->allowRule('superglobals'));
```

The rule recognizes only `GLOBALS`, `_SERVER`, `_GET`, `_POST`, `_FILES`,
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

### `strictTypes`

**On by default.** The compiled template file begins with
`declare(strict_types=1);`, so the template is held to the types it declares at
every call boundary. Deny it with `denyRule('strictTypes')` to get PHP's
weak-mode coercion back.

This rule exists because the declaration is **per-file** and the engine
emits the file: a caller cannot opt a template into strict types by declaring
them in their own code, and every compiled template is a separate file whose
`<?php` Clarity writes. The rule is the only place a template can carry the
declaration.

It is on in every preset, `restricted()` included — not because it makes a
template reach less, but because the alternative to a type error is not safety,
it is silence. Weak mode coerces `'1abc'` to `1`, a `null` to `''`, and a
fractional float to a truncated `int`, and nothing reports that a type was
wrong. See [Why strict by default](#why-strict-by-default).

What it changes is the contract at a **call boundary**. A registered filter or
function that declares a parameter type now throws `TypeError` — reported as a
`ClarityException` naming the template line — instead of receiving a coerced
value:

```php
$engine->addFilter('shout', fn(string $s): string => strtoupper($s) . '!');

// Default: a ClarityException at the template line.
// After denyRule('strictTypes'): '42!' — the int was coerced, silently.
$engine->setPolicy(Policy::default()->denyRule('strictTypes'));
```

```twig
{{ 42 |> shout }}    {# TypeError under strictTypes: int given, string expected #}
```

It also makes a fractional float passed to an `int` parameter throw, rather than
being truncated, and stops numeric strings coercing to `int` / `float` — a
numeric string reaching `round`, `ceil` or `floor` (which take `int|float`) is a
type error rather than a silently formatted number.

**What it does not change.** `strictTypes` is not a reach rule: it grants no
construct and names no class, so it is not counted by
[`allowsPhp()`](#inspecting-a-policy) and a strict template is no less sandboxed
than a weak one — `Policy::restricted()` is strict *and* sandboxed at once. It
hardens the boundary; it does not move it.

Nor does it remove the engine's **output** cast. `{{ … }}` compiles to
`\htmlspecialchars((string)(<expr>), …)`, and that is how any non-string is
printed at all — `{{ 42 }}`, `{{ items |> length }}`, `{{ price }}`. Stripping it
would break most real templates rather than catch a mistake, so the cast stays:
the rule governs the type of an argument passed *into* a filter or function,
not the stringification of a value on its way out.

```twig
{{ 42 }}              {# still renders "42" under strictTypes #}
{{ items |> length }} {# still renders "3" #}
```

Latte offers the same declaration, scoped the same way: it governs the signatures
at the call boundary, not the engine's own output handling.

#### Why strict by default

Because the failure it replaces is invisible. A cast that succeeds silently and a
type error are the same event as far as the template author is concerned, except
that only one of them can be debugged:

| Template                 | Weak mode          | `strictTypes`      |
| ------------------------ | ------------------ | ------------------ |
| `{{ 42 \|> upper }}`     | `'42'`             | `TypeError`        |
| `{{ null \|> upper }}`   | `''` + deprecation | `TypeError`        |
| `{{ ' 3.14 ' \|> round(1) }}` | `'3.1'`       | `TypeError`        |
| `{{ 42 }}`               | `'42'`             | `'42'` (unchanged) |

The third row is the one that matters: a numeric string from a form field or a
query parameter formatted as if it were a number. It looked right and was not,
and only strict types say so — `round`, `ceil` and `floor` take `int|float`, so a
string is a type error there, unlike the filters that take a `string`.

**What it costs.** A template that relied on coercion — most often a value that
may be `null` arriving at a string filter — now throws. That is the intended
trade, and it is why the rule is a *rule*: `denyRule('strictTypes')` gives a
project weak mode back for one engine, or for the whole application, without
touching anything else.

---

## The API

### Fluent builders, array form accepted everywhere

```php
// Fluent — the primary shape
$policy = Policy::default()
    ->allowRule('methodCalls', 'superglobals')
    ->denyRule('variableVariables')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown')
    ->denyFunctions('exec');

// Array — for config files, which cannot call methods
$policy = Policy::fromArray([
    'rules'    => ['methodCalls' => true],
    'functions'       => ['strtoupper', 'count'],
    'filters'         => ['markdown'],
    'deniedFunctions' => ['exec'],
]);
```

Use the array form in `env.php` or framework configuration. `toArray()` produces
a round-trippable representation. Unknown keys and rule names are rejected.

`fromArray()` starts from `restricted()`, so a config only has to name what it
changes.

### Engine integration

```php
$engine->setPolicy(Policy::restricted());   // the default
$engine->setPolicy(Policy::trusted());
$engine->setPolicy(Policy::unrestricted());
$engine->setPolicy(['rules' => ['rawPhp' => true]]);   // array form
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

Use these methods for a summary without inspecting individual rules:

| Method                   | True when                                                     |
| ------------------------ | ------------------------------------------------------------- |
| `$policy->isUnrestricted()` | every rule is on **and** nothing is restricted or denied |
| `$policy->isSandboxed()` | no rule that reaches PHP is on                          |
| `$policy->allowsPhp()`   | a rule that reaches PHP is on                           |

`allowsPhp()` is on when the `phpFunctions` rule is on, or when a rule that makes
another PHP construct reachable is on. A **filter** allowlist alone does not
enable PHP access; it only narrows which names may be used. For example,
`Policy::restricted()->allowFilters('markdown')` remains sandboxed. A **function**
allowlist does turn the `phpFunctions` rule on as it grants, so
`Policy::restricted()->allowFunctions('count')` reaches PHP and resolves only
`count`.

The engine also exposes `isSandboxed()` as a shortcut for
`getPolicy()->isSandboxed()`.

---

## Semantics

### Every rejection is a compile-time `ClarityException`

Rule, allowlist, function-call and filter-step violations raise a
`ClarityException` during compilation, not rendering. An unregistered filter
that cannot resolve to a PHP function also fails at compile time.

### The message names the remedy

Errors name the required change and report the template location through
`getFile()` / `getLine()` and `templateName` / `templateLine`. See
[Error Handling](04-advanced-topics.md#error-handling).

| Situation                         | Message                                                                                                                                                                                                                               |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| unregistered **function**         | `Call to unregistered function 'x()' in context '…'. Grant the 'phpFunctions' rule to allow PHP function calls, then add the name with allowFunctions().`                                                                              |
| unregistered **filter step**      | `Filter 'x' is not registered, and this policy does not allow PHP function calls, so there is nothing for it to resolve to. Register it with addFilter(), or grant the 'phpFunctions' rule and add the name with allowFunctions().` |
| a **denied or unlisted** function | `Function 'x' is not allowed by this policy: it is not in the function allowlist, or it is denied. Add it with allowFunctions().`                                                                                                     |
| an **unlisted filter**            | `Filter 'x' is not registered and is not in the policy's filter allowlist. Add it with allowFilters(), or register it with addFilter().`                                                                                              |
| a **denied rule**           | `'{% php %}' is not allowed by this policy. Grant the 'rawPhp' rule to allow it.`                                                                                                                                               |

### A policy change invalidates the compiled cache

Compiled templates record a digest of the effective policy. The loader recompiles
when the digest changes, so a template compiled under one policy is not reused
under another. The digest includes sorted rules and allowlist entries.
Compiled files store the digest, not the policy itself.

### Registration order does not change the policy

The policy is resolved against the function registry during compilation, so
`allowFunctions('a', 'b')` works regardless of registration order. The last
policy set before rendering applies.

---

## What the policy does not cover

### A `tags` allowlist

There is no tag allowlist. Clarity tags do not expose access beyond the
rules, filters and functions already described. Restricting tags is a
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
  superglobals as a separate rule.
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
