# Policy API — Design Proposal

> **Status: proposal, not implemented.** Nothing on this page exists yet.
> It is written down so the shape can be reviewed before any code lands. The
> `sandbox` flag described in [PHP Mode](04-advanced-topics.md#php-mode) is what
> Clarity ships today; this describes what would replace it.

A template engine has one question to answer about a template: *what is this
template allowed to reach?* Today Clarity answers it with a single boolean.
The boolean is a good default and a poor lever:

1. **It is all-or-nothing.** Denying one function means giving up every
   compile-time guarantee, or keeping every guarantee and going without the
   function.
2. **It denies things nobody was worried about.** Sandbox mode refuses raw PHP,
   which is the point — but it also refuses `strtoupper`, which is not a
   security property. Latte and Twig both ship a `SecurityPolicy` whose centre
   is an explicit allowlist of functions, filters and tags, for exactly this
   reason. (Blade has none; it is PHP with a different spelling.)
3. **It leaks the decision into the render path.** A bare call to an unregistered
   function is rejected at compile time, but the *same name used as a filter
   step* is not: it compiles to a runtime lookup and fails on the first render,
   with an error that names a variable the template never wrote. One boolean
   cannot express the difference, so the compiler has nothing to check against.

The proposal inverts the mechanism rather than relaxing it. Everything stays
**compile-time**; what changes is that the decision stops being made by one
bit and starts being made by a policy object, which can say *this* function yes
and *that* function no, without touching whether method calls are reachable.

**No runtime checks are added.** A policy that made every property read consult
a checker would cost the same on every render — and the harness in this
workspace measures the sandbox as render-cost free, which is the property worth
keeping. A policy has to be free at render time to be worth having.

---

## The model

A policy is a set of **capabilities** plus three **allowlists**:

| Capability          | Default (sandboxed) | What it grants                                                       |
| ------------------- | ------------------- | -------------------------------------------------------------------- |
| `rawPhp`            | `false`             | `{% php CODE %}` tags and `{% php %}…{% endphp %}` blocks            |
| `methodCalls`       | `false`             | `$obj->method()` and `$obj->method(arg, …)` in expressions           |
| `superglobals`      | `false`             | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                       |
| `phpVariables`      | `false`             | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` the same thing |
| `variableVariables` | `true`              | `$$name` / `${expr}` (see [why this defaults on](#why-variablevariables-defaults-on)) |
| `newExpressions`    | `false`             | `new Foo()`                                                          |
| `staticCalls`       | `false`             | `Foo::bar()`                                                         |
| `strictTypes`       | `false`             | emit `declare(strict_types=1)` into the compiled template (see [Strict types](#strict-types-a-separate-proposal)) |

| Allowlist                       | Default          | What it governs                                              |
| ------------------------------- | ---------------- | ------------------------------------------------------------ |
| `functions`                     | `[]`             | bare calls (`strtoupper(name)`) and PHP functions as filter steps (`'ab' \|> strtoupper`) |
| `filters`                       | `[]`             | names accepted after `\|>`, which need not be functions      |
| `tags`                          | every built-in   | `{% … %}` keywords: `if`, `for`, `include`, `extends`, …     |

An empty allowlist denies; absent means "not configured", which for `tags` means
"all built-ins stay available". The two rules are deliberately different: tags
are Clarity's own vocabulary, while functions are the application's.

### Relationship to the `sandbox` flag

The flag becomes a **preset over a policy**, and every preset is inspectable:

| Preset                | Produces                                                                              |
| --------------------- | ------------------------------------------------------------------------------------- |
| `Policy::sandboxed()` | the defaults above — today's sandbox mode                                              |
| `Policy::open()`      | every capability on, no allowlist: today's PHP mode                                    |
| `Policy::trusted()`   | `rawPhp`, `methodCalls`, `superglobals`, `phpVariables` on; everything else still off   |
| `Policy::custom()`    | start from `sandboxed()` and change what you mean to change                             |

```php
$engine->setPolicy(Policy::custom()
    ->allowFunctions('strtoupper', 'strtolower', 'number_format', 'count')
    ->allowFilters('markdown', 'excerpt')
    ->allowTags('if', 'for', 'include')      // narrower than the default
    ->allowCapability('methodCalls'));       // without giving up anything else
```

### Why `variableVariables` defaults on

Because denying it does not achieve anything. `$$name` and `${expr}` resolve
against the render scope and loop locals in **both** modes; they cannot reach a
superglobal or an engine internal, and the engine's own `__c_`-prefixed
namespace is protected by the binding order in the compiled body, not by
rejecting the syntax. Latte reaches the same conclusion from the other side: it
rejects non-string variable names at compile time (a *literal* `$$` is not even
expressible) but must defer the general case to a runtime check.

What actually protects the frame — a template cannot bind a `__c_`-prefixed
name — is unchanged by this setting, so there is nothing to gain by defaulting
it off. It exists as a capability so an application can be explicit, not because
the engine needs it.

---

## The API

### Fluent builders, array form accepted everywhere

```php
// Fluent — the primary shape
$policy = Policy::custom()
    ->allowFunctions(...)
    ->allowFilters(...)
    ->allowTags(...)
    ->allowCapability(...)
    ->denyCapability(...);

// Array — for config files, which cannot call methods
$policy = Policy::fromArray([
    'capabilities' => ['methodCalls' => true],
    'functions'    => ['strtoupper', 'count'],
    'filters'      => ['markdown'],
    'tags'         => ['if', 'for', 'include'],
]);
```

The array form is what an `env.php` or a framework config can express, and it
is the same object: `toArray()` round-trips, so a policy can be dumped into a
config file and read back.

### Engine integration

```php
$engine->setPolicy(Policy::sandboxed());

// The flag stays, expressed in terms of the policy. These are equivalent:
$engine->setSandboxMode(false);
$engine->setPolicy(Policy::open());
```

`setSandboxMode()` and `isSandboxed()` stay as the coarse spelling. A policy
can answer the question they ask — `isSandboxed()` means "no capability beyond
the sandboxed defaults is on" — so the two cannot disagree.

### `allowFunctions()` vs `addFunction()`

They are different statements and both are needed:

- `addFunction('upper', $fn)` registers a **callable** under a name. Existing
  API, unchanged.
- `allowFunctions('strtoupper')` **permits a PHP function to be called by its
  own name**, without registering anything.

A name that is registered *and* allowed is a registered callable. A name that is
allowed and not registered calls the PHP function of that name. A name that is
neither stays a compile-time error.

---

## Semantics

### Every rejection is a compile-time `ClarityException`

Unchanged from today for capabilities, allowlists and **bare function calls**:
a template that violates its policy does not compile, so the failure happens at
the deploy that introduced it rather than on a request.

**Filter steps are the exception, and should not stay one.** A `|>` name that is
not registered compiles today (in sandbox mode) to a lookup in the runtime
callable table, so an unregistered filter step fails **at render time**, not at
compile time — and the message the error handler produces is:

```
Variable "strtoupper" is not defined in this context
```

That is a real defect, not a preference. It names a variable the template never
wrote, so the author is told to fix something that does not exist. The bare-call
path already does this correctly:

```
Call to unregistered function in context '…'. Register it via addFunction() first.
```

The two paths should agree. This is fixable on its own, before any policy lands,
and probably should be — and a policy makes it more important, because the
allowlist gives the compiler the information to reject the filter step at compile
time, where the author will see it.

### The message must name the remedy

Whichever path reports a rejection, the message should say what to change:

| Situation                   | Message today                                                        | Should be                                                    |
| --------------------------- | -------------------------------------------------------------------- | ------------------------------------------------------------ |
| unregistered **function**   | `Call to unregistered function … Register it via addFunction() first.` | keep — it is the model                                     |
| unregistered **filter step**| `Variable "strtoupper" is not defined in this context`                 | `Filter \|strtoupper is not allowed. Allow it via Policy::allowFunctions()/allowFilters(), or register it via addFilter().` |
| blocked function (PHP mode) | `Function 'x' is blocked in PHP mode. Allow it by removing it from the deny-list.` | `…not allowed by this policy. Allow it via Policy::allowFunctions().` |

### A policy change invalidates the compiled cache

The compiled class already records `$sandboxCompiled`, and the cache compares
it — which is what makes flipping the flag safe today. A policy is the same
class of input, so it needs the same treatment: the compiled class records a
**digest** of the effective policy, and a mismatch recompiles.

A digest, not the policy itself: the compiled file is source code that ships to
a server, and it should not carry a readable inventory of what a template may
call. It also has to be a digest of the *full* identity rather than a truncated
form — a prefix would collide on exactly the policies that differ in their last
entry.

### Registration order cannot silently narrow the policy

`allowFunctions('a', 'b')` must not behave differently depending on whether it
is called before or after the engine's own registration. The policy is resolved
against the registry **when the template compiles**, so the last policy set
before a render is the one that applies, and no ordering inside the builder
changes the outcome.

---

## Strict types: a separate proposal

Latte's strict typing is a genuinely different idea from the capability model,
and it is worth separating them: it is not about *what a template may reach*, it
is about **what a filter may assume about its input**.

```twig
{{ items |> upper }}       {# items is a list — a mistake in any engine #}
{{ 42 |> upper }}          {# an integer: Latte throws, Clarity and Twig coerce #}
```

Latte is stricter here and the strictness is defensible — `upper` on an integer
is almost always a template bug, and a `TypeError` says so at the point of the
mistake instead of rendering `42` and looking fine.

**The mechanism is narrower than it looks.** Latte does not check types at
compile time because it cannot: the type is unknown until a value exists. What
it actually does is emit `declare(strict_types=1)` into the compiled template,
and declare its own filter signature `upper(Stringable|string|null $s)`. PHP then
throws at the call boundary because strict mode forbids the `int` → `string`
coercion. All the strictness is PHP's, and all of it is decided at compile time
by what gets emitted.

**Clarity is closer to that than it looks, and further from it in one way that
matters.** Clarity's compiled template does *not* declare strict types, so PHP
coerces: `{{ 42 |> upper }}` renders `42` and looks fine. But Clarity's built-in
filters do not simply pass the value through — `upper` compiles to

```php
\mb_strtoupper((string) {1})
```

a **hard cast written into the emitted code**, with no runtime table lookup and
no strict mode needed. Every inline filter is written this way. That is the
interesting asymmetry:

> Clarity's built-in filters are cast-based and therefore never throw;
> a user-registered or PHP-mode filter receives the raw value and is coerced by
> PHP. Latte makes **all** of them throw. Neither engine checks anything at
> runtime — the difference is entirely in what each compiler emits.

So a `strictTypes` setting is cheap for us in a way it is not for a design that
would check at runtime. The options, in order of how much they cost the render
path:

1. **Nothing.** Cast, as the built-in filters already do. `{{ 42 |> upper }}`
   renders. This is today.
2. **Emit `declare(strict_types=1)` in the compiled template** and declare filter
   signatures strictly, for a `{{ 42 |> upper }}` that throws. Zero render-time
   cost — it changes compiled output and nothing else — which is exactly how
   Latte does it.
3. **A runtime type check per filter**, deferring the decision to render time.
   Correct for cases a signature cannot describe, and the one approach this
   proposal deliberately avoids.

**Recommendation: option 2, as a policy capability.** It is the only option that
catches the mistake in the example (an int reaching a string filter) without
adding a single instruction to the render path, and it is the same mechanism the
engine it is being compared against uses, so the comparison stays honest. It is
strictly better than where Clarity is now: today a built-in filter casts a wrong
type into a right one silently, which is the outcome the example is complaining
about.

It should land **after** the capability work, for two reasons: the emitted-code
path is the one part of the compiler with no policy equivalent today
(`$sandboxCompiled` is a single boolean and strictness would be its second
flag), and the cache digest has to be extended for it either way.

> The honest comparison to publish, if this lands, is that both engines make the
> mistake visible at the same moment — a thrown `TypeError` — and neither pays
> for it at render time. Clarity's built-in filters would then differ from
> Latte's only in being cast-based: they coerce the type they can safely coerce
> and throw on the one they cannot.

---

## Mapping the existing API onto the policy

Nothing needs to break. Each existing surface becomes a view of the policy:

| Existing API                          | Expressed as policy                                                    |
| ------------------------------------- | ---------------------------------------------------------------------- |
| `setSandboxMode(true/false)`          | `setPolicy(Policy::sandboxed())` / `Policy::open()`                    |
| `isSandboxed()`                       | "no capability outside the sandboxed defaults is on"                   |
| `setDeniedFunctions(['exec', …])`     | a **denylist**, which today applies in PHP mode only                    |
| `addFilter()`, `addFunction()`        | registration, not permission — unchanged                               |
| `COMPILER_VERSION`                    | bumped once; the policy digest handles every subsequent policy change  |

`setDeniedFunctions()` is the one that needs a decision. It exists because
`Registry::DEFAULT_DENIED_FUNCTIONS` is empty — blocking names in PHP mode was
offered as an application-chosen guardrail. In a policy world the allowlist
supersedes it: `allowFunctions(...)` is the same idea stated positively, and it
works in both modes rather than only where the sandbox is already off. The
denylist should stay as a thin alias while it is in use, and should gain a
deprecation note pointing at `allowFunctions()`.

---

## Implementation sketch

| File                                | Change                                                                       |
| ----------------------------------- | ---------------------------------------------------------------------------- |
| `src/Engine/Policy.php`             | new: capabilities, allowlists, presets, `fromArray()`/`toArray()`, `digest()` |
| `src/Engine/Registry.php`           | expose the registered filter/callable names a policy resolves against        |
| `src/ClarityEngineTrait.php`        | `setPolicy()`/`getPolicy()`; derive `sandboxMode` from the policy            |
| `src/Engine/Compiler/CompilerCoreTrait.php` | read capabilities where `$this->sandboxMode` is read today          |
| `src/Engine/Compiler/DirectiveSupportTrait.php` | `{% php %}` on `rawPhp` instead of `!sandboxMode`             |
| `src/Engine/Tokenizer/CallableTrait.php` | bare calls on `functions`, with the current message                |
| `src/Engine/Tokenizer/FilterCompilerTrait.php` | filter steps on `filters`/`functions`, as a compile-time check |
| `src/Engine/Tokenizer/ExpressionCoreTrait.php` | method calls on `methodCalls`                             |
| `src/Engine/Compiler/CodeBuilderTrait.php` | emit the policy digest beside `$sandboxCompiled`                    |
| `docs/04-advanced-topics.md`        | move the capability table here and link to it                                |
| `docs/09-policy-api.md`             | this page, minus the status note                                             |

The compile-time checks themselves already exist; most of the work is replacing
`$this->sandboxMode` with a named capability, which makes each check say what it
is guarding instead of inferring it from one flag. The one behaviour that
genuinely changes is the filter step, which moves from a runtime failure to a
compile-time rejection.

---

## Deliberately not included

- **Method and property allowlists by class.** This is the one part of Latte's
  policy that **cannot** be compile-time: whether `$order->total()` is
  permitted depends on an object that does not exist yet, so Latte resolves it
  in `RuntimeChecker` on every access. Adopting it would add per-access cost to
  the mode the benchmark shows is already the slower of the two. `methodCalls`
  stays the whole of the grant.
- **A separate read-only mode.** Read-only is a property of the *data*, not of
  the template, so it belongs to whatever passes the scope in.
- **Runtime checks of any kind.** See above; the whole design is arranged around
  this constraint.

---

## Open questions

1. **Should `rawPhp` be a capability, or the opposite of one?** Latte has no
   raw-PHP tag at all, so its policy never needs to express this. Ours does,
   which is why it is a capability here.
2. **Does `superglobals` want an allowlist** (`['_SERVER']`) rather than a
   boolean? The cost is a second allowlist to explain; the benefit is that a
   template reading `$_SERVER['HTTP_HOST']` no longer implies `$_ENV`.
3. **Where does `strictTypes` belong** — in the policy, or as its own engine
   setting? It is the only entry that changes emitted code rather than reach.
4. **Is `tags` worth having at all?** It is the allowlist with the weakest
   security story: tags are Clarity's own vocabulary and none of them reach
   anything a filter cannot. It earns its place only as a way to keep a large
   template surface small.
