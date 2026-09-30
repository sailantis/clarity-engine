# Policy API — Design Proposal

> **Status: proposal, not implemented.** Nothing on this page exists yet.
> It is written down so the shape can be reviewed before any code lands. The
> `sandbox` flag described in [PHP Mode](04-advanced-topics.md#php-mode) is what
> Clarity ships today; this describes what would replace it — including its
> removal.

A template engine has one question to answer about a template: _what is this
template allowed to reach?_ Today Clarity answers it with a single boolean.
The boolean is a good default and a poor lever:

1. **It is all-or-nothing.** Denying one function means giving up every
   compile-time guarantee, or keeping every guarantee and going without the
   function.
2. **It denies things nobody was worried about.** Sandbox mode refuses raw PHP,
   which is the point — but it also refuses `strtoupper`, which is not a
   security property. Latte and Twig both ship a `SecurityPolicy` built on
   explicit allowlists, for exactly this reason. (Blade has none; it is PHP with
   a different spelling.)
3. **It leaks the decision into the render path.** A bare call to an unregistered
   function is rejected at compile time, but the _same name used as a filter
   step_ is not: it compiles to a runtime lookup and fails on the first render,
   with an error that names a variable the template never wrote. One boolean
   cannot express the difference, so the compiler has nothing to check against.

The proposal inverts the mechanism rather than relaxing it. Everything stays
**compile-time**; what changes is that the decision stops being made by one
bit and starts being made by a policy object, which can say _this_ function yes
and _that_ function no, without touching whether method calls are reachable.

**No runtime checks are added.** A policy that made every property read consult
a checker would cost the same on every render — and the harness in this
workspace measures the sandbox as render-cost free, which is the property worth
keeping. A policy has to be free at render time to be worth having.

---

## The model

A policy is a set of **capabilities** plus two **allowlists**:

| Capability          | Default (sandboxed) | What it grants                                                                                                    |
| ------------------- | ------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `rawPhp`            | `false`             | `{% php CODE %}` tags and `{% php %}…{% endphp %}` blocks                                                         |
| `methodCalls`       | `false`             | `$obj->method()` and `$obj->method(arg, …)` in expressions                                                        |
| `superglobals`      | `false`             | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                                                                    |
| `phpVariables`      | `false`             | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` the same thing           |
| `variableVariables` | `true`              | `$$name` / `${expr}` (see [why this defaults on](#why-variablevariables-defaults-on))                             |
| `newExpressions`    | `false`             | `new Foo()`                                                                                                       |
| `staticCalls`       | `false`             | `Foo::bar()`                                                                                                      |
| `strictTypes`       | `false`             | emit `declare(strict_types=1)` into the compiled template (see [Strict types](#strict-types-a-separate-proposal)) |

| Allowlist   | Default | What it governs                                                                           |
| ----------- | ------- | ----------------------------------------------------------------------------------------- |
| `functions` | `[]`    | bare calls (`strtoupper(name)`) and PHP functions as filter steps (`'ab' \|> strtoupper`) |
| `filters`   | `[]`    | names accepted after `\|>`, which need not be functions                                   |

An empty allowlist denies everything it governs. That is the whole rule — there
is no "absent means unconfigured" case to explain, which is part of why `tags`
is not here.

### No `tags` allowlist

A `tags` allowlist (which `{% … %}` keywords a template may use) is **left out**
of this proposal.

It is the allowlist with the weakest security story: every tag is Clarity's own
vocabulary, and none of them reaches anything a filter or a function cannot. Its
only real use is keeping a large template surface small, which is a linting
concern rather than a policy one — worth adding once someone asks for it.

Latte does ship one, so the omission is a deliberate difference rather than an
oversight: Latte's policy has to express "no `php` tag, no `include`" because
its tags are the only place those features appear. Clarity's are covered by
capabilities (`rawPhp`) and, for the file-access part, by the include
confinement described under [the security finding](#a-security-finding-included-in-this-review).

### Sandbox becomes a policy; the boolean is deleted

The boolean **goes away**, rather than surviving as an alias. A method that means
"set the policy to a preset" is a second way to say one thing, and a second way
to say one thing is a way for the two to disagree.

| Preset                | Produces                                                                              |
| --------------------- | ------------------------------------------------------------------------------------- |
| `Policy::sandboxed()` | the defaults above — today's sandbox mode                                             |
| `Policy::open()`      | every capability on, no allowlist: today's PHP mode                                   |
| `Policy::trusted()`   | `rawPhp`, `methodCalls`, `superglobals`, `phpVariables` on; everything else still off |
| `Policy::custom()`    | start from `sandboxed()` and change what you mean to change                           |

The **default is unchanged**: a `new ClarityEngine()` is sandboxed, because
`Policy::sandboxed()` is the default. A default is a documented promise; the
boolean was only ever a way to change it.

```php
$engine->setPolicy(Policy::custom()
    ->allowFunctions('strtoupper', 'strtolower', 'number_format', 'count')
    ->allowFilters('markdown', 'excerpt')
    ->allowCapability('methodCalls'));       // without giving up anything else
```

### Why `variableVariables` defaults on

Because denying it does not achieve anything. `$$name` and `${expr}` resolve
against the render scope and loop locals in **both** modes, and the engine's own
`__c_`-prefixed namespace is protected by the binding order in the compiled body
rather than by rejecting the syntax — so a template that uses the form reaches
nothing it could not already reach.

Latte is *stricter* here than Clarity is: its sandbox rejects `${expr}` outright
with "Forbidden variable variables.", while Clarity allows the form and relies on
the scope being closed. That is a real difference and it is deliberate, because
the two guards protect different things — Latte's guard is about syntax, and
Clarity's is about what the syntax can name.

It exists as a capability so an application can be explicit, not because the
engine needs it.

---

## The API

### Fluent builders, array form accepted everywhere

```php
// Fluent — the primary shape
$policy = Policy::custom()
    ->allowFunctions(...)
    ->allowFilters(...)
    ->allowCapability(...)
    ->denyCapability(...);

// Array — for config files, which cannot call methods
$policy = Policy::fromArray([
    'capabilities' => ['methodCalls' => true],
    'functions'    => ['strtoupper', 'count'],
    'filters'      => ['markdown'],
]);
```

The array form is what an `env.php` or a framework config can express, and it
is the same object: `toArray()` round-trips, so a policy can be dumped into a
config file and read back.

### Engine integration

```php
$engine->setPolicy(Policy::sandboxed());   // the default
$engine->setPolicy(Policy::open());        // was: setSandboxMode(false)
$policy = $engine->getPolicy();            // inspectable, always resolves to a real object
```

There is no `setSandboxMode()`. Its removal is a **breaking change**, so it goes
with a minor-version bump on `0.x` and a migration note.

If a coarse predicate is still wanted, `$engine->getPolicy()->isOpen()` answers
it from the policy, so the question keeps an answer without the flag keeping a
second source of truth.

### `allowFunctions()` vs `addFunction()`

They are different statements and both are needed:

- `addFunction('upper', $fn)` registers a **callable** under a name. Existing
  API, unchanged.
- `allowFunctions('strtoupper')` **permits a PHP function to be called by its
  own name**, without registering anything.

A name that is registered _and_ allowed is a registered callable. A name that is
allowed and not registered calls the PHP function of that name. A name that is
neither stays a compile-time error.

---

## A security finding, found in this review and FIXED

Reading the engine back for this proposal surfaced a defect that is not part of
the policy design and could not be fixed by it. It is recorded here because it
was found here.

**A sandboxed template could read any file the PHP process could read.**

```twig
{% include "../../../../etc/passwd.clarity.html" %}
{% extends "C:/secrets/config.clarity.html" %}
```

Both **compiled**, so the file's contents were read at compile time and compiled
into the cached class, ready to be printed. Sandbox mode was on (the default)
throughout.

The cause was two permissive checks in series, neither of which constrained the
path:

- `CompilerCoreTrait::resolveLogicalName()` validated only **characters**:
  `/^[\w.\-\/:]+$/u`. `.` and `/` are both in the class, so `../` passed by
  construction — the check was written to stop shell metacharacters, not
  traversal.
- `Template/FileLoader::resolveName()` then accepted an absolute path (leading
  `/`, a Windows drive, a UNC share) or a `./`-relative path **verbatim**, and
  otherwise mapped `.` to `/`. Nothing compared the result against the view path.

One caveat that mattered when reproducing it: the loader appends the configured
extension, so a traversal only found a file whose name already ended in it
(`.clarity.html`). A test against `/etc/passwd` "passes" for the wrong reason;
the vector was real for any file that matched the extension.

### The fix

The loader no longer decides *what to strip*; it decides *what to accept*. A
name is split on `/` (with `.` and `\` read as the same separator, so
`admin.user`, `admin/users` and `admin\users` are one name) and every segment
must be a plain name:

| Rejected                | Because                                              |
| ----------------------- | ---------------------------------------------------- |
| `/etc/passwd`           | absolute                                             |
| `C:/x`, `\\server\share`| absolute                                             |
| `../secret`             | a `..` segment                                       |
| `admin/../../secret`    | a `..` segment, anywhere in the name                 |
| `admin//user`, `''`     | an empty segment — it would collapse in the filesystem while remaining a distinct cache key |

Because no surviving segment can be `.` or `..`, the path **cannot** climb out,
whatever the spelling. That is the property worth having: it is a check on the
outcome, not a list of dangerous spellings somebody has to remember to extend.

`resolveName()` throws a `ClarityException` naming the name, and the two
character checks stayed in the compiler as a second layer.

**Absolute template names are gone entirely.** They were documented, and they
were the whole of the risk: a template name can be derived from a request, and a
template can be stored in a database, so accepting one meant accepting an
arbitrary file reader. A host that genuinely wants a loader rooted elsewhere
says so in configuration — `new FileLoader('/their/root')` — where the base path
is the root and the rules above apply to it unchanged.

### What this means for reaching another tree

Nothing, if it was being done correctly: `addNamespace()` is the supported way,
and an explicit namespace is visible in the configuration rather than encoded in
a template name. What is no longer possible is a template *naming its way* into
an arbitrary directory.

**Latte is not vulnerable in the same way, by construction**: it has no path
syntax in templates at all — `{include}` was removed from the language, and its
safe policy excludes `include`, `extends`, `layout` and `import`. Clarity's
`{% include %}` deliberately takes only a string literal (`RE_INCLUDE`), so no
expression could *build* a path — but the literal itself was unconstrained, which
was the whole of the problem. It is now constrained.

Tests: `tests/Engine/LoadPathSecurityTest.php` (19 cases) pins every escape form
above, checks the refusal through the engine rather than only through the loader,
and asserts that `admin.user` and `admin/user` still resolve to the same file.


This is orthogonal to the flags: no capability setting changes it, and turning
PHP mode off does not turn it off. It needs its own fix in the loader
(confine resolved paths to the view base, rejecting absolute and UNC forms), and
its own tests.

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

| Situation                    | Message today                                                                      | Should be                                                                                                                   |
| ---------------------------- | ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| unregistered **function**    | `Call to unregistered function … Register it via addFunction() first.`             | keep — it is the model                                                                                                      |
| unregistered **filter step** | `Variable "strtoupper" is not defined in this context`                             | `Filter \|strtoupper is not allowed. Allow it via Policy::allowFunctions()/allowFilters(), or register it via addFilter().` |
| blocked function (PHP mode)  | `Function 'x' is blocked in PHP mode. Allow it by removing it from the deny-list.` | `…not allowed by this policy. Allow it via Policy::allowFunctions().`                                                       |

### A policy change invalidates the compiled cache

The compiled class already records `$sandboxCompiled`, and the cache compares
it — which is what makes flipping the flag safe today. A policy is the same
class of input, so it needs the same treatment: the compiled class records a
**digest** of the effective policy, and a mismatch recompiles.

A digest, not the policy itself: the compiled file is source code that ships to
a server, and it should not carry a readable inventory of what a template may
call. It also has to be a digest of the _full_ identity rather than a truncated
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
and it is worth separating them: it is not about _what a template may reach_, it
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
matters.** Clarity's compiled template does _not_ declare strict types, so PHP
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

| Existing API                      | Expressed as policy                                                   |
| --------------------------------- | --------------------------------------------------------------------- |
| `setSandboxMode(true/false)`      | **removed** — `setPolicy(Policy::sandboxed())` / `Policy::open()`     |
| `isSandboxed()`                   | **removed** — `getPolicy()->isOpen()` answers the same question       |
| `setDeniedFunctions(['exec', …])` | a **denylist**, which today applies in PHP mode only                  |
| `addFilter()`, `addFunction()`    | registration, not permission — unchanged                              |
| `COMPILER_VERSION`                | bumped once; the policy digest handles every subsequent policy change |

Removing the two boolean methods is the only **breaking** part of this proposal.
Everything else is additive. On `0.x` that is a minor bump and a migration note;
the replacement is a one-line change at each call site.

`setDeniedFunctions()` is the one that needs a decision. It exists because
`Registry::DEFAULT_DENIED_FUNCTIONS` is empty — blocking names in PHP mode was
offered as an application-chosen guardrail. In a policy world the allowlist
supersedes it: `allowFunctions(...)` is the same idea stated positively, and it
works in both modes rather than only where the sandbox is already off. The
denylist should stay as a thin alias while it is in use, and should gain a
deprecation note pointing at `allowFunctions()`.

---

## Implementation sketch

| File                                            | Change                                                                        |
| ----------------------------------------------- | ----------------------------------------------------------------------------- |
| `src/Engine/Policy.php`                         | new: capabilities, allowlists, presets, `fromArray()`/`toArray()`, `digest()` |
| `src/Engine/Registry.php`                       | expose the registered filter/callable names a policy resolves against         |
| `src/ClarityEngineTrait.php`                    | `setPolicy()`/`getPolicy()`; delete `setSandboxMode()`/`isSandboxed()`        |
| `src/Engine/Compiler/CompilerCoreTrait.php`     | read capabilities where `$this->sandboxMode` is read today                    |
| `src/Engine/Compiler/DirectiveSupportTrait.php` | `{% php %}` on `rawPhp` instead of `!sandboxMode`                             |
| `src/Engine/Tokenizer/CallableTrait.php`        | bare calls on `functions`, with the current message                           |
| `src/Engine/Tokenizer/FilterCompilerTrait.php`  | filter steps on `filters`/`functions`, as a compile-time check                |
| `src/Engine/Tokenizer/ExpressionCoreTrait.php`  | method calls on `methodCalls`                                                 |
| `src/Engine/Compiler/CodeBuilderTrait.php`      | emit the policy digest beside `$sandboxCompiled`                              |
| `src/Template/FileLoader.php`                   | **contain resolved paths** (the security finding — separate change)           |
| `docs/04-advanced-topics.md`                    | move the capability table here and link to it                                 |
| `docs/09-policy-api.md`                         | this page, minus the status note                                              |

The compile-time checks themselves already exist; most of the work is replacing
`$this->sandboxMode` with a named capability, which makes each check say what it
is guarding instead of inferring it from one flag. Two behaviours genuinely
change: the filter step moves from a runtime failure to a compile-time
rejection, and calling `setSandboxMode()` stops existing.

---

## Deliberately not included

- **A `tags` allowlist.** See [above](#no-tags-allowlist): weakest security
  story of the three, and a linting concern rather than a policy one.
- **Method and property allowlists by class.** This is the one part of Latte's
  policy that **cannot** be compile-time: whether `$order->total()` is
  permitted depends on an object that does not exist yet, so Latte resolves it
  in `RuntimeChecker` on every access. Adopting it would add per-access cost to
  the mode the benchmark shows is already the slower of the two. `methodCalls`
  stays the whole of the grant.
- **A separate read-only mode.** Read-only is a property of the _data_, not of
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
   It is also the only one whose *removal* would change already-compiled output,
   which makes it a candidate for the separate "compilation" group.
4. **Should the path rules live in `FileLoader`, or in the compiler?** They are
   in the loader (with the character checks still in the compiler as a second
   layer), because the loader is what touches the filesystem. A custom loader
   therefore has to apply the same rules itself — worth documenting in
   `TemplateLoader`'s contract rather than leaving to be discovered.
5. **Should `superglobals` be presented as an advantage at all?** Latte's
   sandbox permits them, so this is a difference rather than a win. The honest
   framing is "Clarity's strict-access contract covers a template's scope and
   nothing ambient; a template cannot read process state unless you say so" —
   not "we block superglobals and others do not".
