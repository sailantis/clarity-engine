# Class: Policy

**Full name:** [Clarity\Engine\Policy](../../src/Engine/Policy.php)

What a template is allowed to reach.

A policy is a set of CAPABILITIES plus two ALLOWLISTS.  It replaces the
single `sandbox` boolean the engine used to carry: one bit could only say
"everything Clarity has" or "nothing that touches PHP", so an application
that needed one PHP function had to give up every compile-time guarantee.

Every decision a policy makes is made at COMPILE TIME.  Nothing here is
consulted while a template renders — the render path has no policy object in
it at all — which is what keeps the sandbox free of runtime cost.

Capabilities
------------
  rawPhp             `{% php CODE %}`
  methodCalls        `$obj->method(args)` on a `$`-sigil chain
  superglobals       `$_SERVER`, `$_GET`, … as chain roots
  phpVariables       the render scope seeded into PHP locals — the thing that
                     makes `$title` and `{% php echo $title; %}` the same name
  variableVariables  `$$name` / `${expr}` (on by default; see below)
  newExpressions     `new Foo(args)`
  staticCalls        `Foo::method(args)` / `Foo::CONST`

Allowlists
----------
  functions  bare calls and filter steps that resolve to a PHP function
  filters    names accepted after `|>`

The rule for both is the same and is the whole rule:

  An EMPTY allowlist means unrestricted.  A NON-EMPTY allowlist means only
  the listed names resolve; anything else is a compile-time error.

An allowlist NARROWS; it never opens a door.  It is consulted only where a
capability has already made a construct reachable, so
`restricted()->allowFunctions('count')` is still sandboxed — pair the grant
with a capability for it to have anything to apply to.

Empty-means-unrestricted is what makes `Policy::unrestricted()` the engine's
old PHP mode exactly, rather than a mode that happens to deny everything.

Why `variableVariables` defaults on
-----------------------------------
Because denying it achieves nothing.  `$$name` and `${expr}` resolve against
the render scope and loop locals in every policy, and the engine's own
`__c_`-prefixed frame is protected by binding order rather than by rejecting
the syntax — so the form reaches nothing a literal name could not.  It exists
as a capability so an application can be explicit about wanting it off.

## Public Constants

- **CAPABILITIES** = `[
    'rawPhp',
    'methodCalls',
    'superglobals',
    'phpVariables',
    'variableVariables',
    'newExpressions',
    'staticCalls'
]`
- **ALLOWLISTS** = `[
    'functions',
    'filters'
]`

## Public methods

### restricted() · <small>[🗎](../../src/Engine/Policy.php#L109)</small>

`public static function restricted(): self`

The default: no template reaches PHP.  Identical to the engine's
historical sandbox mode, and what a bare `new ClarityEngine()` uses.

**Return value**

- Type: `self`


---

### unrestricted() · <small>[🗎](../../src/Engine/Policy.php#L130)</small>

`public static function unrestricted(): self`

Everything on, no allowlist: templates have the full power of PHP.

This is the engine's former PHP mode (`setSandboxMode(false)`), and it is
exactly as dangerous.  Intended for templates written by trusted authors
(Blade / Stempler / Plates parity), never for templates a request can
choose.

**Return value**

- Type: `self`


---

### trusted() · <small>[🗎](../../src/Engine/Policy.php#L151)</small>

`public static function trusted(): self`

Trusted templates have access to most of the engine's capabilities, but not everything.

What stays off: `rawPhp`, and the two capabilities that let a template name
a class of its own. Raw `{% php %}` blocks and constructing an arbitrary
class are both a different order of trust from calling a method on an
object the application already passed in.

**Return value**

- Type: `self`


---

### default() · <small>[🗎](../../src/Engine/Policy.php#L175)</small>

`public static function default(): self`

Start from the engine's default (`restricted()`) and change what you
mean to change.  Nothing here is a blank slate: this is the sandboxed
policy, so every capability you do not name stays off.

```
Policy::default()
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count');
```

**Return value**

- Type: `self`


---

### fromArray() · <small>[🗎](../../src/Engine/Policy.php#L202)</small>

`public static function fromArray(array $data): self`

Build a policy from a plain array — the form a config file can express.

```
Policy::fromArray([
    'capabilities' => ['methodCalls' => true],
    'functions'    => ['strtoupper', 'count'],
    'filters'      => ['markdown'],
]);
```

An omitted `capabilities` key starts from `restricted()`, so a config
only has to name what it changes.  Every key is validated; an unknown
capability or allowlist is refused rather than ignored, because a policy
that silently drops a rule is worse than one that refuses to load.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array | - |  |

**Return value**

- Type: `self`


---

### toArray() · <small>[🗎](../../src/Engine/Policy.php#L256)</small>

`public function toArray(): array`

The array form of this policy.  Round-trips through `fromArray()`.

**Return value**

- Type: `array`


---

### allows() · <small>[🗎](../../src/Engine/Policy.php#L270)</small>

`public function allows(string $capability): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$capability` | string | - |  |

**Return value**

- Type: `bool`


---

### capabilities() · <small>[🗎](../../src/Engine/Policy.php#L284)</small>

`public function capabilities(): array`

**Return value**

- Type: `array`


---

### allowCapability() · <small>[🗎](../../src/Engine/Policy.php#L294)</small>

`public function allowCapability(string ...$capabilities): self`

Turn capabilities on.  Accepts more than one so a grant reads as a list.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$capabilities` | string | - |  |

**Return value**

- Type: `self`


---

### denyCapability() · <small>[🗎](../../src/Engine/Policy.php#L308)</small>

`public function denyCapability(string ...$capabilities): self`

Turn capabilities off.  Accepts more than one so a denial reads as a list.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$capabilities` | string | - |  |

**Return value**

- Type: `self`


---

### allowFunctions() · <small>[🗎](../../src/Engine/Policy.php#L329)</small>

`public function allowFunctions(string ...$names): self`

Permit PHP functions to be called by their own name, without registering
them.  A non-empty list becomes the complete set that may be called.

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

### allowFilters() · <small>[🗎](../../src/Engine/Policy.php#L343)</small>

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

### restrictsFunctions() · <small>[🗎](../../src/Engine/Policy.php#L357)</small>

`public function restrictsFunctions(): bool`

Whether the function allowlist restricts anything at all.

An EMPTY allowlist is not "nothing allowed" — it is "no filter applied", so
what decides is the capabilities plus this list.  See the class docblock.

**Return value**

- Type: `bool`


---

### restrictsFilters() · <small>[🗎](../../src/Engine/Policy.php#L363)</small>

`public function restrictsFilters(): bool`

Whether the filter allowlist restricts anything at all.

**Return value**

- Type: `bool`


---

### allowsFunction() · <small>[🗎](../../src/Engine/Policy.php#L375)</small>

`public function allowsFunction(string $name): bool`

Whether a PHP function may be called by name under this policy.

Only meaningful where a PHP function is reachable at all; the caller
decides that from the capabilities.  Names are case-insensitive and a
leading namespace separator is ignored, because PHP's are.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### allowsFilter() · <small>[🗎](../../src/Engine/Policy.php#L382)</small>

`public function allowsFilter(string $name): bool`

Whether a name may be used as a `|>` filter step under this policy.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### allowedFunctions() · <small>[🗎](../../src/Engine/Policy.php#L389)</small>

`public function allowedFunctions(): array`

**Return value**

- Type: `array`


---

### allowedFilters() · <small>[🗎](../../src/Engine/Policy.php#L395)</small>

`public function allowedFilters(): array`

**Return value**

- Type: `array`


---

### denyFunctions() · <small>[🗎](../../src/Engine/Policy.php#L407)</small>

`public function denyFunctions(string ...$names): self`

Refuse names outright, whatever the allowlists say.

Applied last, so a name that is both allowlisted and denied is denied. The
denial is the more specific statement, and a policy that allows and denies
the same name is a mistake worth resolving in favour of the safer reading.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$names` | string | - |  |

**Return value**

- Type: `self`


---

### deniesFunction() · <small>[🗎](../../src/Engine/Policy.php#L416)</small>

`public function deniesFunction(string $name): bool`

Whether this policy denies a function name outright.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### deniedFunctions() · <small>[🗎](../../src/Engine/Policy.php#L422)</small>

`public function deniedFunctions(): array`

**Return value**

- Type: `array`


---

### isUnrestricted() · <small>[🗎](../../src/Engine/Policy.php#L435)</small>

`public function isUnrestricted(): bool`

True when every capability is on and neither allowlist restricts anything:
the engine's former PHP mode.

**Return value**

- Type: `bool`


---

### allowsPhp() · <small>[🗎](../../src/Engine/Policy.php#L463)</small>

`public function allowsPhp(): bool`

True when this template may reach PHP at all — the gate for the two things
that are not a named capability because they ARE "PHP is reachable": a bare
call to an unregistered name, and a filter step falling back to a PHP
function.

An allowlist does NOT count as reachable on its own. It narrows which PHP
functions a construct may call; it does not create a construct to call them
from. `Policy::restricted()->allowFunctions('count')` therefore stays
sandboxed — the grant needs a capability to apply to, so pair it with one
(`->allowCapability('methodCalls')->allowFunctions('count')`).

`variableVariables` is deliberately not part of this.  It decides a syntax
the engine resolves against its own scope, so turning it off does not make
a template any less able to run PHP.

**Return value**

- Type: `bool`


---

### isSandboxed() · <small>[🗎](../../src/Engine/Policy.php#L487)</small>

`public function isSandboxed(): bool`

True when PHP is unreachable in every direction the engine has: the
engine's former sandbox mode, and its default.

Answers the old `isSandboxed()` question from the policy, so the state has
one source of truth rather than a second flag that could disagree.

**Return value**

- Type: `bool`


---

### digest() · <small>[🗎](../../src/Engine/Policy.php#L504)</small>

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
