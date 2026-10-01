# The Policy API

A template is compiled against a **policy** that decides what it may reach. A policy is a set of **capabilities** — each one the kind of construct it permits — plus two **allowlists** naming the PHP functions and filters a template may call.

Every decision is made at **compile time**. No policy is consulted while a template renders, so the restrictions cost nothing to enforce. A template that violates its policy does not compile, and the compiler reports the violation with a message that names the grant that would fix it.

```php
use Clarity\Engine\Policy;

// One of the ready-made modes …
$engine->setPolicy(Policy::sandboxed());   // the default
$engine->setPolicy(Policy::trusted());     // raw PHP, but no `new` and no `::`
$engine->setPolicy(Policy::open());        // full PHP

// … or the default plus the grants you name
$engine->setPolicy(Policy::custom()
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown'));
```

A freshly built engine is **sandboxed**: a template reaches its own scope and the filters and functions the host registered, and nothing else.

---

## Presets

Four factory methods build a policy. Three are ready-made modes; the fourth starts from the default so only the changes need naming.

```php
Policy::sandboxed();   // the default, and the most restrictive
Policy::trusted();     // raw PHP, method calls and superglobals
Policy::open();        // every capability
Policy::custom();      // sandboxed(), plus whatever you grant
```

### What each preset contains

The three ready-made modes differ only in their capabilities. This table is the complete difference between them.

| Capability          | `sandboxed()` | `trusted()` | `open()` |
| ------------------- | ------------- | ----------- | -------- |
| `rawPhp`            | off           | **on**      | **on**   |
| `methodCalls`       | off           | **on**      | **on**   |
| `superglobals`      | off           | **on**      | **on**   |
| `phpVariables`      | off           | **on**      | **on**   |
| `variableVariables` | on            | on          | on       |
| `newExpressions`    | off           | off         | **on**   |
| `staticCalls`       | off           | off         | **on**   |

Every preset leaves both allowlists empty and denies no function. An empty
allowlist is unrestricted (see [the one rule](#the-one-rule)), and it only
constrains anything once the policy can reach PHP at all — so it does not need to
change between the modes.

### Choosing a preset

| Preset        | In one line                                                         | Appropriate when                                                                      |
| ------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `sandboxed()` | The template's own scope, and the registered filters and functions. | The default. Templates a request can choose, or any template you are unsure about.    |
| `trusted()`   | Sandboxed plus the constructs PHP-authored templates normally use.  | Templates written by you that need inline PHP and object access.                      |
| `open()`      | Every capability: the full power of PHP.                            | Templates never chosen by a request, as a parity mode with Blade, Stempler or Plates. |
| `custom()`    | `sandboxed()`, plus the grants you name.                            | Most applications: a narrow, explicit set of allowances.                              |

### `Policy::sandboxed()`

```php
$engine->setPolicy(Policy::sandboxed());
```

The most restrictive mode: no capability that reaches PHP is on. A template may
read the scope it was rendered with, use every Clarity tag, and call registered
filters and functions. It may not open a `{% php %}` block, call a method, read a
superglobal, construct a class or reach a static member.

This is the default — what a bare `new ClarityEngine()` uses — and the mode the
rest of the documentation describes.

### `Policy::trusted()`

```php
$engine->setPolicy(Policy::trusted());
```

Enables everything `sandboxed()` omits except the two class-reaching
capabilities. A template may write `{% php %}` blocks, call methods on objects
from the scope, read superglobals and reach the scope through PHP locals. It may
not use `new` or `::`.

`newExpressions` and `staticCalls` stay off because constructing a class, or
calling a static member, reaches code the application never handed the template —
a different order of trust from calling a method on an object that was passed in.

### `Policy::open()`

```php
$engine->setPolicy(Policy::open());
```

Every capability is on, both allowlists are empty and nothing is denied: no
construct is refused and a template has the full power of PHP. This is the
engine's former PHP mode, and the parity mode for template languages that expect
PHP inline.

Use it only for templates that are never chosen by a request.

### `Policy::custom()`

```php
$policy = Policy::custom()
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown');

$engine->setPolicy($policy);
```

Starts from `sandboxed()` and returns the same mutable policy every preset
returns, so grants can be chained. Most applications want this mode: the default
is nearly right, and one or two named allowances make it exactly right.

---

## The model

### Capabilities

| Capability          | Default | What it grants                                                                                          |
| ------------------- | ------- | ------------------------------------------------------------------------------------------------------- |
| `rawPhp`            | `false` | `{% php CODE %}` tags and `{% php %}…{% endphp %}` blocks                                               |
| `methodCalls`       | `false` | `$obj->method()` and `$obj->method(arg, …)`                                                             |
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

That is the whole rule, and there is no "absent means unconfigured" case. It is
also what makes `Policy::open()` the engine's former PHP mode _exactly_ — every
capability on, both allowlists empty, therefore nothing denied — rather than a
mode that happens to deny everything.

`denyFunctions(...)` applies **after** the allowlists and wins. It is the one
thing an allowlist cannot express, because "everything except `exec`" has no
positive form:

```php
$engine->setPolicy(Policy::open()->denyFunctions('exec', 'system', 'proc_open'));
```

### `methodCalls` and `phpVariables` are independent

The capabilities are independent, and the two that look most alike are worth
spelling out. `methodCalls` lets `$obj->name()` compile. `phpVariables` seeds the
render scope into PHP locals, which is what makes `{{ title }}` and
`{% php echo $title; %}` the same variable — and it is only meaningful next to
`rawPhp`.

Because `$obj->m()` compiles to `$__c_va['obj']->m()` unless `phpVariables` is
also on, granting `methodCalls` **alone** does not require the scope to be
seeded, and does not seed it. A policy can therefore hand a template the ability
to call methods without also handing it a frame of PHP locals to scribble on.

### `variableVariables`

It is on by default because turning it off changes nothing about what a template
can reach. `$$name` and `${expr}` resolve against the render scope and loop
locals under every policy, and the engine's own `__c_`-prefixed frame is
protected by the binding order in the compiled body rather than by rejecting the
syntax — so the form reaches nothing a literal name could not already reach.

The capability exists so an application can be explicit about the syntax. It is
not counted as "reaching PHP" for [inspecting a policy](#inspecting-a-policy),
because turning it off does not make a template any less able to run PHP.

### `superglobals`

Without the capability, a superglobal name is an ordinary scope read of a name
the scope does not hold, so `{{ _SERVER }}` throws — **even when `phpVariables`
is granted**, which is the case that matters:

```php
// Refused: no superglobals capability, so this is a scope read of an absent name
$engine->setPolicy(Policy::custom()->allowCapability('phpVariables'));

// Granted: the name now means PHP's own variable, whatever the scope holds
$engine->setPolicy(Policy::custom()->allowCapability('superglobals'));
```

The second example matters: without a capability of its own, `superglobals` would
collapse into `phpVariables`, since a template could reach every superglobal
through the seeded-local form as soon as the scope was seeded.

Only the known names count — `GLOBALS`, `_SERVER`, `_GET`, `_POST`, `_FILES`,
`_COOKIE`, `_SESSION`, `_REQUEST`, `_ENV`. `_SERVERX` is an ordinary variable.

### `newExpressions` and `staticCalls`

Both compile a class name to a **fully qualified** form — `new DateTime()`
becomes `new \DateTime`. The separator is emitted because a compiled template is
a plain class in the global namespace with no `use` statements, so an unqualified
name would resolve according to whatever the file happened to import.

`instanceof` is the exception among the class-name constructs. It takes a class
name because that is what the operator means, it reaches nothing the scope did
not already hold, and it therefore works under **every** policy:

```twig
{% if order instanceof App\Order %}…{% endif %}
```

A class name in any other position (`{{ Foo\Bar }}`) is reported rather than
silently compiled.

---

## The API

### Fluent builders, array form accepted everywhere

```php
// Fluent — the primary shape
$policy = Policy::custom()
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

The array form is what an `env.php` or a framework config can express, and it is
the same object: `toArray()` round-trips. Every key and every capability name is
validated, so a typo is refused rather than silently ignored — a policy that drops
a rule the author wrote is worse than one that refuses to load.

`fromArray()` starts from `sandboxed()`, so a config only has to name what it
changes.

### Engine integration

```php
$engine->setPolicy(Policy::sandboxed());   // the default
$engine->setPolicy(Policy::trusted());
$engine->setPolicy(Policy::open());
$engine->setPolicy(['capabilities' => ['rawPhp' => true]]);   // array form
$policy = $engine->getPolicy();            // always a real object
```

`setPolicy()` accepts either form; `getPolicy()` never returns null, because a
freshly built engine answers with `Policy::sandboxed()`.

### `allowFunctions()` vs `addFunction()`

They are different statements and both are needed:

- `addFunction('upper', $fn)` registers a **callable** under a name. Existing
  API, unchanged.
- `allowFunctions('strtoupper')` **permits a PHP function to be called by its own
  name**, without registering anything.

A name that is registered _and_ allowed is a registered callable. A name that is
allowed and not registered calls the PHP function of that name. A name that is
neither is a compile-time error. Registered names always win over a PHP function
of the same name, in every policy.

### Inspecting a policy

Coarse questions about a policy have their own methods, so a caller never has to
inspect individual capabilities.

| Method                   | True when                                                             |
| ------------------------ | --------------------------------------------------------------------- |
| `$policy->isOpen()`      | every capability is on **and** nothing is restricted or denied        |
| `$policy->isSandboxed()` | no capability that reaches PHP is on                                  |
| `$policy->allowsPhp()`   | a capability that reaches PHP is on, **or** an allowlist is non-empty |

`allowsPhp()` is the answer the compiler asks for most often: it gates a bare
call to an unregistered name and a filter step falling back to a PHP function. A
**non-empty allowlist counts as reaching PHP**, because listing names is the
explicit statement "these PHP functions may be used" — otherwise
`Policy::sandboxed()->allowFunctions('count')` would be a grant that grants
nothing.

The engine keeps `isSandboxed()` as a shortcut for `getPolicy()->isSandboxed()`,
at a call site that only wants the coarse answer.

---

## Semantics

### Every rejection is a compile-time `ClarityException`

True for capabilities, allowlists, bare function calls and filter steps. A
template that violates its policy does not compile, so the failure is reported at
the compile that introduced it rather than on a request.

Filter steps are checked the same way. An unregistered `|>` name that the policy
will not resolve to a PHP function is reported at compile time, because such a
policy leaves _nothing_ for the name to fall back to: "not registered" and
"cannot ever resolve" are the same statement.

### The message names the remedy

Every rejection says what to change.

| Situation                         | Message                                                                                                                                                                                                                               |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| unregistered **function**         | `Call to unregistered function in context '…'. Register it via addFunction() first.`                                                                                                                                                  |
| unregistered **filter step**      | `Filter 'x' is not registered, and this policy does not allow a template to reach PHP, so there is nothing for it to resolve to. Register it with addFilter(), or grant a capability to let a PHP function of the same name be used.` |
| a **denied or unlisted** function | `Function 'x' is not allowed by this policy: it is not in the function allowlist, or it is denied. Add it with allowFunctions().`                                                                                                     |
| an **unlisted filter**            | `Filter 'x' is not registered and is not in the policy's filter allowlist. Add it with allowFilters(), or register it with addFilter().`                                                                                              |
| a **denied capability**           | `'{% php %}' is not allowed by this policy. Grant the 'rawPhp' capability to allow it.`                                                                                                                                               |

The filter messages name both remedies rather than one, because there genuinely
are two and which is right depends on whether the author wants a template filter
or a PHP function.

### A policy change invalidates the compiled cache

A compiled template records a **digest** of the effective policy, and the loader
recompiles on a mismatch. This is what makes changing a policy safe: the cache
keys on template source only, so a class compiled under one policy is never
served under another.

The digest covers the full identity — every capability and every allowlist entry,
sorted — so two policies that differ in their last entry cannot collide. That is
what lets an allowlist change recompile correctly **without** bumping
`COMPILER_VERSION`.

A **digest**, rather than the policy itself, is what the compiled file carries:
that file is source code that ships to a server and does not need to hold a
readable inventory of what a template may call.

### Registration order does not change the policy

`allowFunctions('a', 'b')` behaves the same whether it is called before or after
the engine's own registration, because the policy is resolved against the
registry when the template compiles. The last policy set before a render is the
one that applies.

---

## What the policy does not cover

### Strict types

Clarity does not implement strict typing for filter input, and it is a different
idea from the capability model: not _what a template may reach_, but **what a
filter may assume about its input**.

```twig
{{ 42 |> upper }}    {# an integer reaching a string filter #}
```

A compiled template does not declare strict types, so PHP coerces. Clarity's
built-in filters also cast explicitly: `upper` compiles to

```php
\mb_strtoupper((string) {1})
```

so a built-in filter coerces a wrong type into a right one rather than throwing. A
registered or PHP-function filter receives the raw value and is coerced by PHP in
the same way. Nothing is checked at render time either way — the difference would
be entirely in what the compiler emits.

For comparison, Latte emits `declare(strict_types=1)` and declares its filter
signatures strictly, so PHP throws a `TypeError` at the call boundary. That is
also decided entirely at compile time.

### A `tags` allowlist

There is none. Every Clarity tag is the engine's own vocabulary, and none of them
reaches anything a filter or a function cannot. Restricting them is a linting
concern — keeping a large template surface small — rather than a security one.
Latte ships a tag list because its features appear only through tags; Clarity's are
covered by capabilities.

### Method and property allowlists by class

There are none, and they could not be decided at compile time: whether
`$order->total()` is permitted depends on an object that does not exist until the
template renders. `methodCalls` is the whole grant — if a template may call a
method, it may call a method on anything the scope already holds.

### A read-only mode

There is none. Read-only is a property of the _data_, not of the template, so it
belongs to whatever passes the scope in.

### Runtime checks

There are none, by design. Every capability and allowlist is resolved while the
template compiles, which is what keeps the restrictions free at render time.

---

## Comparison with other engines

The capability model covers the same ground as the security policies of other
template engines, with differences in how the axes are drawn.

- **Superglobals.** Latte's `SecurityPolicy` has five axes — tags, filters,
  functions, methods and properties — and no superglobal one, so `$_SERVER` is an
  ordinary variable there. Clarity treats a superglobal as a capability of its
  own. The difference is a matter of framing: Clarity's contract covers a
  template's scope and nothing ambient.
- **Includes.** Latte has no path syntax in templates at all, so it has no
  traversal to guard against. Clarity's `{% include %}` takes a literal name only,
  and the [view path](#the-view-path-is-a-boundary) is the boundary that keeps it
  inside the view root.
- **Variable variables.** Latte rejects `${expr}` outright in its sandbox.
  Clarity allows the form under every policy and relies on the scope being closed:
  the syntax reaches nothing a literal name could not. The two guards protect
  different things — Latte's is about syntax, Clarity's is about what the syntax
  can name.
- **Blade** has no policy at all; it is PHP with a different spelling. **Twig** has
  one (`SecurityPolicy`: allowed tags, filters, methods, properties and
  functions), and its `is defined` compiles to `array_key_exists` — the same model
  Clarity's `is defined` uses.

---

## Migrating from the old API

Earlier versions decided all of this with a single `sandbox` boolean and a
denied-functions list. The `Policy` object replaces both.

| Old API                           | Express with                                                                |
| --------------------------------- | --------------------------------------------------------------------------- |
| `setSandboxMode(true)`            | `setPolicy(Policy::sandboxed())` — or just the default                      |
| `setSandboxMode(false)`           | `setPolicy(Policy::open())`                                                 |
| `['sandbox' => true/false]`       | `['policy' => Policy::sandboxed()/open()]`                                  |
| `isSandboxed()`                   | kept on the engine; `getPolicy()->isSandboxed()` / `isOpen()` for the parts |
| `setDeniedFunctions(['exec', …])` | `setPolicy(Policy::open()->denyFunctions('exec', …))`                       |
| `getDeniedFunctions()`            | `getPolicy()->deniedFunctions()`                                            |
| `['deniedFunctions' => [...]]`    | `['policy' => ['deniedFunctions' => [...]]]`                                |

**Only the removal of `setSandboxMode()` is a breaking change**, and the
replacement is a one-line change per call site. There is no alias for it:
`setPolicy()` covers every case the old method did, and a mode and a rule set by
the same method cannot drift out of sync.

## The view path is a boundary

Not part of the policy and not fixable by it, but documented here because it is
the other half of "what can a template reach". A template name may not address a
file outside the view path:

| Rejected                 | Because                                                                         |
| ------------------------ | ------------------------------------------------------------------------------- |
| `/etc/passwd`            | absolute                                                                        |
| `C:/x`, `\\server\share` | absolute                                                                        |
| `../secret`              | a `..` segment                                                                  |
| `admin/../../secret`     | a `..` segment, anywhere in the name                                            |
| `admin//user`, `''`      | an empty segment — it would collapse on disk while staying a distinct cache key |

The loader splits the name on `/` (with `.` and `\` read as the same separator) and
requires every segment to be a plain name. Because no surviving segment can be `.`
or `..`, the path **cannot** climb out, whatever the spelling — the property worth
having, since it is a check on the outcome rather than a list of dangerous
spellings somebody has to remember to extend.

**Absolute template names are not accepted.** A template name can be derived from
a request and a template can be stored in a database, so accepting one would make
a name an arbitrary file reader. A host that genuinely wants a loader rooted
elsewhere says so in configuration — `new FileLoader('/their/root')` — where the
base path is the root and the same rules apply to it.

A custom loader therefore has to apply the same rules itself, which is part of
`TemplateLoader`'s contract rather than something to discover.

---

## Related reading

- [Best Practices](05-best-practices.md) — organizing templates under the default
  policy.
- [Troubleshooting](06-troubleshooting.md) — diagnosing a compile-time rejection.
- [The benchmark](08-benchmark.md) — where the policy's compile-time cost sits.
