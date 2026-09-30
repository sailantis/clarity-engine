# The Policy API

> **Status: implemented.** `Clarity\Engine\Policy` replaces the `sandbox`
> boolean. Nothing on this page is a proposal any more.

A template engine has one question to answer about a template: _what is this
template allowed to reach?_ Clarity answers it with a **policy** — a set of
capabilities plus two allowlists.

Everything a policy decides is decided at **compile time**. Nothing on this page
is consulted while a template renders: the compiled class has no policy object in
it, and no check is added to the render path. That property is what makes the
sandbox free, and it is the constraint the whole design is arranged around.

```php
use Clarity\Engine\Policy;

$engine->setPolicy(Policy::sandboxed());   // the default
$engine->setPolicy(Policy::open());        // every capability on
$engine->setPolicy(Policy::trusted());     // trusted, but no `new` / `::`
$engine->setPolicy(Policy::custom()        // the useful one
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count')
    ->allowFilters('markdown'));
```

---

## The model

### Capabilities

| Capability          | Default | What it grants                                                                                          |
| ------------------- | ------- | ------------------------------------------------------------------------------------------------------- |
| `rawPhp`            | `false` | `{% php CODE %}` tags and `{% php %}…{% endphp %}` blocks                                               |
| `methodCalls`       | `false` | `$obj->method()` and `$obj->method(arg, …)`                                                             |
| `superglobals`      | `false` | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                                                          |
| `phpVariables`      | `false` | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` the same thing |
| `variableVariables` | `true`  | `$$name` / `${expr}` (see [why it defaults on](#why-variablevariables-defaults-on))                     |
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

### Presets

| Preset                | Produces                                                                                             |
| --------------------- | ---------------------------------------------------------------------------------------------------- |
| `Policy::sandboxed()` | the defaults above — the engine's historical safe mode, and what a bare `new ClarityEngine()` uses   |
| `Policy::open()`      | every capability on, no allowlist                                                                    |
| `Policy::trusted()`   | `rawPhp`, `methodCalls`, `superglobals`, `phpVariables` on; `newExpressions`/`staticCalls` still off |
| `Policy::custom()`    | start from `sandboxed()` and change what you mean to change                                          |

`Policy::trusted()` stops short of `newExpressions` and `staticCalls` on purpose:
constructing an arbitrary class is a different order of trust from calling a
method on an object the application already passed in.

### Why `methodCalls` does not imply `phpVariables`

The capabilities are independent, and the two that look most alike are worth
spelling out. `methodCalls` lets `$obj->name()` compile. `phpVariables` seeds the
render scope into PHP locals, which is what makes `{{ title }}` and
`{% php echo $title; %}` the same variable — and it is only meaningful next to
`rawPhp`.

Because `$obj->m()` compiles to `$__c_va['obj']->m()` unless `phpVariables` is
also on, granting `methodCalls` **alone** does not require the scope to be
seeded, and does not seed it. A policy can therefore hand a template the ability
to call methods without also handing it a frame of PHP locals to scribble on.

### Why `variableVariables` defaults on

Because denying it achieves nothing. `$$name` and `${expr}` resolve against the
render scope and loop locals under every policy, and the engine's own
`__c_`-prefixed frame is protected by the binding order in the compiled body
rather than by rejecting the syntax — so the form reaches nothing a literal name
could not already reach.

It exists as a capability so an application can be explicit, not because the
engine needs it. It is deliberately **not** counted as "reaching PHP" for
[the coarse question](#the-coarse-question), because turning it off does not make
a template any less able to run PHP.

### `superglobals` is genuinely separate

Without the capability, a superglobal name is an ordinary scope read of a name
the scope does not hold, so `{{ _SERVER }}` throws — **even when `phpVariables`
is granted**, which is the case that matters:

```php
// Refused: no superglobals capability, so this is a scope read of an absent name
$engine->setPolicy(Policy::custom()->allowCapability('phpVariables'));

// Granted: the name now means PHP's own variable, whatever the scope holds
$engine->setPolicy(Policy::custom()->allowCapability('superglobals'));
```

The second half is load-bearing. Without it, a template would reach every
superglobal through the seeded-local form the moment `phpVariables` was granted,
and the two capabilities would really be one.

Only the known names count — `GLOBALS`, `_SERVER`, `_GET`, `_POST`, `_FILES`,
`_COOKIE`, `_SESSION`, `_REQUEST`, `_ENV`. `_SERVERX` is an ordinary variable.

### `newExpressions` and `staticCalls`

These are the two capabilities that required real compiler work rather than a
flag, because neither construct was usable before: the tokenizer emitted the
leading `\` of a fully qualified name as a stray character, so `new DateTime()`
and `DateTime::createFromFormat(...)` produced
`syntax error, unexpected fully qualified name`.

Class names are now read by a dedicated handler and compiled to a genuinely
**fully qualified** form (`\DateTime`). That is not cosmetic: a compiled template
is a plain class in the global namespace with no `use` statements, so an
unqualified name would resolve by luck. Emitting the separator makes the
resolution explicit and independent of where the engine lives.

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
$engine->setPolicy(Policy::open());        // was: setSandboxMode(false)
$engine->setPolicy(['capabilities' => ['rawPhp' => true]]);   // array form
$policy = $engine->getPolicy();            // always resolves to a real object
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

### The coarse question

`isSandboxed()` survives on the engine, reading the policy, and
`$policy->isOpen()` answers the rest:

| Method                   | True when                                                             |
| ------------------------ | --------------------------------------------------------------------- |
| `$policy->isOpen()`      | every capability is on **and** neither allowlist restricts anything   |
| `$policy->isSandboxed()` | no capability that reaches PHP is on                                  |
| `$policy->allowsPhp()`   | a capability that reaches PHP is on, **or** an allowlist is non-empty |

`allowsPhp()` is the honest middle answer and the one the compiler asks: it gates
a bare call to an unregistered name and a filter step falling back to a PHP
function. A **non-empty allowlist counts as reaching PHP**, because listing names
is the explicit statement "these PHP functions may be used" — otherwise
`Policy::sandboxed()->allowFunctions('count')` would be a grant that grants
nothing.

---

## Semantics

### Every rejection is a compile-time `ClarityException`

True for capabilities, allowlists, bare function calls and filter steps. A
template that violates its policy does not compile, so the failure happens at the
deploy that introduced it rather than on a request.

Filter steps used to be the exception: a `|>` name that resolved to no registered
filter compiled to a lookup in the runtime callable table and failed on the first
render, reporting `Variable "strtoupper" is not defined in this context` — naming
a variable the template never wrote. The check can be made at compile time
because a policy that reaches no PHP leaves _nothing_ for an unregistered name to
fall back to, so "not registered" and "cannot ever resolve" are the same
statement.

### The message must name the remedy

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
keys on template source only, so a class built under one policy must never be
served under another.

A **digest**, not the policy itself: the compiled file is source code that ships
to a server and should not carry a readable inventory of what a template may
call. It covers the full identity — every capability and every allowlist entry,
sorted — so two policies that differ in their last entry cannot collide. That is
what lets an allowlist change recompile correctly **without** bumping
`COMPILER_VERSION`.

### Registration order cannot narrow the policy

`allowFunctions('a', 'b')` behaves the same whether it is called before or after
the engine's own registration, because the policy is resolved against the
registry when the template compiles. The last policy set before a render is the
one that applies.

---

## Strict types

Latte's strict typing is a different idea from the capability model, and it is
not implemented here. It is not about _what a template may reach_ but about
**what a filter may assume about its input**:

```twig
{{ 42 |> upper }}    {# an integer reaching a string filter #}
```

Latte throws a `TypeError`; Clarity renders `42` and looks fine. The mechanism is
narrower than it looks: Latte does not check at compile time (it cannot — the type
is unknown until a value exists). It emits `declare(strict_types=1)` into the
compiled template and declares `upper(Stringable|string|null $s)`, so PHP throws
at the call boundary. All of the strictness is PHP's, and all of it is decided by
what gets emitted — zero render-time cost.

Clarity is closer to that than it looks, and further in one way that matters. A
compiled template does **not** declare strict types, so PHP coerces. But Clarity's
built-in filters do not pass the value through: `upper` compiles to a hard cast
written into the emitted code,

```php
\mb_strtoupper((string) {1})
```

so a built-in filter silently coerces a wrong type into a right one. The
asymmetry worth stating is:

> Clarity's built-in filters are cast-based and therefore never throw;
> a registered or PHP-function filter receives the raw value and is coerced by
> PHP. Latte makes **all** of them throw. Neither engine checks anything at
> runtime — the difference is entirely in what each compiler emits.

So a `strictTypes` setting would be cheap for us: emit `declare(strict_types=1)`
and declare filter signatures strictly. It lands **after** the capability work,
because it changes emitted code rather than reach, and because the digest already
has the shape to carry it.

---

## Mapping the old API

| Old API                           | Express with                                                                |
| --------------------------------- | --------------------------------------------------------------------------- |
| `setSandboxMode(true)`            | `setPolicy(Policy::sandboxed())` — or just the default                      |
| `setSandboxMode(false)`           | `setPolicy(Policy::open())`                                                 |
| `['sandbox' => true/false]`       | `['policy' => Policy::sandboxed()/open()]`                                  |
| `isSandboxed()`                   | kept on the engine; `getPolicy()->isSandboxed()` / `isOpen()` for the parts |
| `setDeniedFunctions(['exec', …])` | `setPolicy(Policy::open()->denyFunctions('exec', …))`                       |
| `getDeniedFunctions()`            | `getPolicy()->deniedFunctions()`                                            |
| `['deniedFunctions' => [...]]`    | `['policy' => ['deniedFunctions' => [...]]]`                                |

**Only the removal of `setSandboxMode()` is breaking**, and the replacement is a
one-line change per call site. Keeping it as an alias was rejected deliberately: a
method that means "set the policy to a preset" is a second way to say one thing,
and a second way to say one thing is a way for the two to disagree.

---

## Deliberately not included

- **A `tags` allowlist.** It is the allowlist with the weakest security story:
  every tag is Clarity's own vocabulary, and none of them reaches anything a
  filter or a function cannot. Its only real use is keeping a large template
  surface small, which is a linting concern rather than a policy one. Latte ships
  one, so the omission is a deliberate difference — Latte's tags are the only
  place its features appear, whereas Clarity's are covered by capabilities.
- **Method and property allowlists by class.** This is the one part of Latte's
  policy that **cannot** be compile-time: whether `$order->total()` is permitted
  depends on an object that does not exist yet, so Latte resolves it in a
  `RuntimeChecker` on every access. Adopting it would add per-access cost to the
  mode the benchmark shows is already the slower one. `methodCalls` stays the
  whole of the grant.
- **A separate read-only mode.** Read-only is a property of the _data_, not of the
  template, so it belongs to whatever passes the scope in.
- **Runtime checks of any kind.** The whole design is arranged around this
  constraint.

## Differences from other engines

Worth stating plainly, because the direction is not always in Clarity's favour:

- **Superglobals.** Latte's sandbox permits them: its `SecurityPolicy` has five
  axes (tags, filters, functions, methods, properties) and no superglobal one, and
  its variable check rejects only `${this}` and non-string names, so `$_SERVER` is
  an ordinary variable. Clarity's is stricter here — but the honest framing is
  that Clarity's strict-access contract covers a template's scope and nothing
  ambient, not "we block superglobals and others do not".
- **Includes.** Latte has no path syntax in templates at all (`{include}` was
  removed and its safe policy excludes `include`/`extends`/`layout`/`import`), so
  it is not vulnerable to a traversal the way Clarity's literal-only
  `{% include %}` was before the loader was confined. See
  [the view path](#the-view-path-is-a-boundary).
- **Variable variables.** Latte is stricter: its sandbox rejects `${expr}` outright
  with "Forbidden variable variables." Clarity allows the form and relies on the
  scope being closed. The two guards protect different things — Latte's is about
  syntax, Clarity's is about what the syntax can name.
- **Blade** has no policy at all; it is PHP with a different spelling. **Twig** has
  one (`SecurityPolicy`: allowed tags/filters/methods/properties/functions), and
  its `is defined` compiles to `array_key_exists` — the same model Clarity's
  `is defined` uses.

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

**Absolute template names are gone entirely.** They were documented, and they were
the whole of the risk: a template name can be derived from a request and a
template can be stored in a database, so accepting one made a name an arbitrary
file reader. A host that genuinely wants a loader rooted elsewhere says so in
configuration — `new FileLoader('/their/root')` — where the base path is the root
and the same rules apply to it.

A custom loader therefore has to apply the same rules itself, which is part of
`TemplateLoader`'s contract rather than something to discover.

## Open questions

1. **Does `superglobals` want an allowlist** (`['_SERVER']`) rather than a boolean?
   The cost is a second allowlist to explain; the benefit is that a template
   reading `$_SERVER['HTTP_HOST']` no longer implies `$_ENV`.
2. **Where does `strictTypes` belong** — in the policy, or as its own engine
   setting? It is the only candidate that changes emitted code rather than reach,
   which makes it a candidate for a separate "compilation" group.
3. **Should `functions` and `filters` be one allowlist?** They are separate because
   a filter name need not be a function and the remedies differ, but two lists is
   two things to explain.
