# Class: Policy

**Full name:** [Clarity\Engine\Policy](../../src/Engine/Policy.php)

What a template is allowed to reach.

A policy is a set of RULES plus two ALLOWLISTS.  It replaces the
single `sandbox` boolean the engine used to carry: one bit could only say
"everything Clarity has" or "nothing that touches PHP", so an application
that needed one PHP function had to give up every compile-time guarantee.

Every decision a policy makes is made at COMPILE TIME.  Nothing here is
consulted while a template renders — the render path has no policy object in
it at all — which is what keeps the sandbox free of runtime cost.

Rules
-----
  phpFunctions       bare calls (`strtoupper(name)`) and filter steps that
                     resolve to a PHP function
  rawPhp             `{% php CODE %}`
  methodCalls        `$obj->method(args)` on a `$`-sigil chain
  superglobals       `$_SERVER`, `$_GET`, … as chain roots
  phpVariables       the render scope seeded into PHP locals — the thing that
                     makes `$title` and `{% php echo $title; %}` the same name
  variableVariables  `$$name` / `${expr}` (on by default; see below)
  newExpressions     `new Foo(args)`
  staticCalls        `Foo::method(args)` / `Foo::CONST`
  strictTypes        `declare(strict_types=1)` in the compiled template, so a
                     value of the wrong type at a call boundary throws instead
                     of being coerced

Allowlists
----------
  functions  names the `phpFunctions` rule may resolve to a PHP function
  filters    names accepted after `|>`

The rule for both allowlists is the same:

  An EMPTY allowlist means unrestricted.  A NON-EMPTY allowlist means only
  the listed names resolve; anything else is a compile-time error.

`allowFilters()` narrows only: it is consulted where a filter step is already
being resolved, so a lone filter allowlist stays sandboxed.  `allowFunctions()`
is different in one respect: PHP function calls ARE the construct it names, so
granting one turns the `phpFunctions` rule on as well — `default()->allowFunctions('count')`
reaches the sandbox without needing a second, unrelated rule to carry it.

Empty-means-unrestricted is what makes `Policy::unrestricted()` the engine's
old PHP mode exactly, rather than a mode that happens to deny everything.

Why `variableVariables` defaults on
-----------------------------------
Because denying it achieves nothing.  `$$name` and `${expr}` resolve against
the render scope and loop locals in every policy, and the engine's own
`__c_`-prefixed frame is protected by binding order rather than by rejecting
the syntax — so the form reaches nothing a literal name could not.  It exists
as a rule so an application can be explicit about wanting it off.

Why `strictTypes` exists and defaults on
----------------------------------------
A caller cannot opt a template into PHP's strict types: `declare(strict_types=1)`
is per-file, and every compiled template is its own file, whose `<?php` the
engine emits.  Without this rule a typed filter — `fn(string $s)` — is
handed `42` as `"42"` and nothing reports it.  The declaration is a
rule, therefore, because that is the only way a template can carry it.

It is on in every preset, including `restricted()`. Coercion is a silent
success: `'1abc'` becomes `1`, a `null` becomes `''`, and nothing anywhere says
a type was wrong.  A type error says so.  The cost is a diagnostic, so a
template gets strict behaviour unless its application opts out with
`denyRule('strictTypes')`.

It is not an `allowsPhp()` rule: it grants no construct, and it decides
nothing about what a template can name.  What it changes is the *contract at a
call boundary*, which is why it is deliberately absent from
`allowsPhp()` — a strict template is no less sandboxed than a weak one.
It hardens the boundary; it does not move it.

Scope: the declaration governs calls made *from* the compiled file, so it makes
a mismatched argument to a registered filter or function throw, and it makes a
fractional float passed to an `int` parameter throw.  It deliberately does NOT
remove the engine's output cast in `{{ … }}`: `htmlspecialchars((string)(…))`
is how any non-string renders at all, so stripping it would break
`{{ 42 }}`, `{{ items |> length }}`, `{{ price }}` — most real templates —
rather than catching a mistake.

## Public Constants

- **RULES** = `[
    'methodCalls',
    'newExpressions',
    'phpFunctions',
    'phpVariables',
    'rawPhp',
    'staticCalls',
    'strictTypes',
    'superglobals',
    'variableVariables'
]`

## Public methods

### restricted() · <small>[🗎](../../src/Engine/Policy.php#L147)</small>

`public static function restricted(): self`

The default: no template reaches PHP.  Identical to the engine's
historical sandbox mode, and what a bare `new ClarityEngine()` uses.

Two rules are on rather than off, for opposite reasons:
`strictTypes` because it is desirable (it costs nothing and reports
mismatches instead of hiding them), and `variableVariables` because
turning it off would achieve nothing (see below).

**Return value**

- Type: `self`


---

### unrestricted() · <small>[🗎](../../src/Engine/Policy.php#L170)</small>

`public static function unrestricted(): self`

Everything on, no allowlist: templates have the full power of PHP.

This is the engine's former PHP mode (`setSandboxMode(false)`), with no
restrictions applied.  It is intended for templates written by trusted
authors (Blade / Stempler / Plates parity), not for templates a request
can choose.

**Return value**

- Type: `self`


---

### trusted() · <small>[🗎](../../src/Engine/Policy.php#L201)</small>

`public static function trusted(): self`

Trusted templates have access to most of the engine's rules, but not everything.

What stays off: `rawPhp`, and the two rules that let a template name
a class of its own. Raw `{% php %}` blocks and constructing an arbitrary
class are both a different order of trust from calling a method on an
object the application already passed in.

`phpFunctions` is on, so bare PHP function calls in template expressions
are available; that is the counterpart of the object access the other
rules grant.  `denyFunctions()` narrows it.

`strictTypes` is on. It is not a reach rule — it grants no construct
and names no class — so it is not one of the things this preset
withholds; a trusted template is simply held to the types it declares.

**Return value**

- Type: `self`


---

### default() · <small>[🗎](../../src/Engine/Policy.php#L227)</small>

`public static function default(): self`

Start from the engine's default (`restricted()`) and change what you
mean to change.  Nothing here is a blank slate: this is the sandboxed
policy, so every rule you do not name stays off.

```php
Policy::default()
    ->allowRule('methodCalls')
    ->allowFunctions('strtoupper', 'count');
```

**Return value**

- Type: `self`


---

### fromArray() · <small>[🗎](../../src/Engine/Policy.php#L254)</small>

`public static function fromArray(array $data): self`

Build a policy from a plain array — the form a config file can express.

```
Policy::fromArray([
    'rules' => ['methodCalls' => true],
    'functions'    => ['strtoupper', 'count'],
    'filters'      => ['markdown'],
]);
```

An omitted `rules` key starts from `restricted()`, so a config
only has to name what it changes.  Every key is validated; an unknown
rule or allowlist is refused rather than ignored, because a policy
that silently drops a rule is worse than one that refuses to load.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array | - |  |

**Return value**

- Type: `self`


---

### toArray() · <small>[🗎](../../src/Engine/Policy.php#L308)</small>

`public function toArray(): array`

The array form of this policy.  Round-trips through `fromArray()`.

**Return value**

- Type: `array`


---

### allows() · <small>[🗎](../../src/Engine/Policy.php#L322)</small>

`public function allows(string $rule): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$rule` | string | - |  |

**Return value**

- Type: `bool`


---

### rules() · <small>[🗎](../../src/Engine/Policy.php#L336)</small>

`public function rules(): array`

**Return value**

- Type: `array`


---

### allowRule() · <small>[🗎](../../src/Engine/Policy.php#L346)</small>

`public function allowRule(string ...$rules): self`

Turn rules on.  Accepts more than one so a grant reads as a list.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$rules` | string | - |  |

**Return value**

- Type: `self`


---

### denyRule() · <small>[🗎](../../src/Engine/Policy.php#L360)</small>

`public function denyRule(string ...$rules): self`

Turn rules off.  Accepts more than one so a denial reads as a list.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$rules` | string | - |  |

**Return value**

- Type: `self`


---

### allowFunctions() · <small>[🗎](../../src/Engine/Policy.php#L388)</small>

`public function allowFunctions(string ...$names): self`

Permit PHP functions to be called by their own name, without registering
them.  A non-empty list becomes the complete set that may be called.

Turns the `phpFunctions` rule on as it grants, because a PHP function call
IS the construct the rule names: `default()->allowFunctions('count')` is
enough to reach the sandbox, with no second rule to carry it.  Only a
non-empty list does so: with no names it is a no-op rather than an
accidental grant of every PHP function, since an EMPTY allowlist is
unrestricted.

Distinct from `addFunction()`, which registers a CALLABLE under a name:
a name that is registered and allowed stays the registered callable, a name
that is allowed and not registered calls the PHP function of that name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | string | - |  |

**Return value**

- Type: `self`


---

### allowFilters() · <small>[🗎](../../src/Engine/Policy.php#L406)</small>

`public function allowFilters(string ...$names): self`

Permit names after `|>`.  A non-empty list becomes the complete set.

Registered filters are always usable and are not part of this list; the
list is about the PHP-function fallback.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | string | - |  |

**Return value**

- Type: `self`


---

### restrictsFunctions() · <small>[🗎](../../src/Engine/Policy.php#L420)</small>

`public function restrictsFunctions(): bool`

Whether the function allowlist restricts anything at all.

An EMPTY allowlist is not "nothing allowed" — it is "no filter applied", so
what decides is the rules plus this list.  See the class docblock.

**Return value**

- Type: `bool`


---

### restrictsFilters() · <small>[🗎](../../src/Engine/Policy.php#L426)</small>

`public function restrictsFilters(): bool`

Whether the filter allowlist restricts anything at all.

**Return value**

- Type: `bool`


---

### allowsFunction() · <small>[🗎](../../src/Engine/Policy.php#L438)</small>

`public function allowsFunction(string $name): bool`

Whether a PHP function may be called by name under this policy.

Only meaningful where a PHP function is reachable at all; the caller
decides that from the rules.  Names are case-insensitive and a
leading namespace separator is ignored, because PHP's are.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### allowsFilter() · <small>[🗎](../../src/Engine/Policy.php#L445)</small>

`public function allowsFilter(string $name): bool`

Whether a name may be used as a `|>` filter step under this policy.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### allowedFunctions() · <small>[🗎](../../src/Engine/Policy.php#L452)</small>

`public function allowedFunctions(): array`

**Return value**

- Type: `array`


---

### allowedFilters() · <small>[🗎](../../src/Engine/Policy.php#L458)</small>

`public function allowedFilters(): array`

**Return value**

- Type: `array`


---

### denyFunctions() · <small>[🗎](../../src/Engine/Policy.php#L469)</small>

`public function denyFunctions(string ...$names): self`

Deny PHP function calls resolved from template expressions.

Raw `{% php %}` blocks are emitted verbatim and are not inspected by this
deny list. Denial takes precedence over the function allowlist.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | string | - |  |

**Return value**

- Type: `self`


---

### deniesFunction() · <small>[🗎](../../src/Engine/Policy.php#L478)</small>

`public function deniesFunction(string $name): bool`

Whether this policy denies a function name outright.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### deniedFunctions() · <small>[🗎](../../src/Engine/Policy.php#L484)</small>

`public function deniedFunctions(): array`

**Return value**

- Type: `array`


---

### isUnrestricted() · <small>[🗎](../../src/Engine/Policy.php#L497)</small>

`public function isUnrestricted(): bool`

True when every rule is on and neither allowlist restricts anything:
the engine's former PHP mode.

**Return value**

- Type: `bool`


---

### allowsPhp() · <small>[🗎](../../src/Engine/Policy.php#L526)</small>

`public function allowsPhp(): bool`

True when this template may reach PHP at all — the gate for the two things
that are not a named rule because they ARE "PHP is reachable": a bare
call to an unregistered name, and a filter step falling back to a PHP
function.

The `phpFunctions` rule decides the first of those directly; the rest decide
whether the constructs that carry a call exist at all.  The allowlists do
NOT count as reachable on their own — they narrow which PHP functions a
construct may call, they do not create a construct to call them from.
`allowFunctions()` is the exception in one direction: it turns the
`phpFunctions` rule on as it grants, so it IS sufficient on its own.

`variableVariables` is deliberately not part of this.  It decides a syntax
the engine resolves against its own scope, so turning it off does not make
a template any less able to run PHP.

**Return value**

- Type: `bool`


---

### isSandboxed() · <small>[🗎](../../src/Engine/Policy.php#L551)</small>

`public function isSandboxed(): bool`

True when PHP is unreachable in every direction the engine has: the
engine's former sandbox mode, and its default.

Answers the old `isSandboxed()` question from the policy, so the state has
one source of truth rather than a second flag that could disagree.

**Return value**

- Type: `bool`


---

### strictTypes() · <small>[🗎](../../src/Engine/Policy.php#L567)</small>

`public function strictTypes(): bool`

Whether compiled templates declare PHP's strict types.

`declare(strict_types=1)` is per-file and the engine emits the file, so this
is the only way a template can carry it. It is read at compile time by the
code builder, which is why the rule and not a global flag: the digest
then makes the cache recompile when it changes.

Deliberately NOT part of `allowsPhp()`: a strict template reaches no
less PHP than a weak one, it is merely held to the types it declares.

**Return value**

- Type: `bool`


---

### digest() · <small>[🗎](../../src/Engine/Policy.php#L584)</small>

`public function digest(): string`

A stable fingerprint of the effective policy.

The compiled template records this and the loader recompiles on a
mismatch, which is what makes changing a policy safe — a class compiled
under one policy must never be served under another, and the cache keys on
template source only.

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
