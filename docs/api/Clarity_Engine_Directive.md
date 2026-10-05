# Class: Directive

**Full name:** [Clarity\Engine\Directive](../../src/Engine/Directive.php)

What one custom directive IS in a paired construct.

Passed as the optional third argument to [`ClarityEngine::addDirective()`](Clarity_ClarityEngine.md#adddirective).
It replaces the former `keyword => role` array, whose two forms said different
things in the same shape: `['endcache' => 'required']` meant "this tag is my
closing tag", while `['cache' => 'owner']` meant "this tag belongs to cache".
Here the factory NAME states the role, so the two directions cannot be
confused and no magic string has to be spelled correctly.

There are four factories, and they cover TWO independent dimensions:

Structural — the tag takes part in the construct and changes the compiler
stack.  This is the dimension a formatter also reads: an opener indents the
body, a branch dedents and re-indents, a closer dedents.

  - `opens()`   — the tag CREATES the construct and names its parts:
    exactly one closer plus any number of optional branch tags.
  - `branches()` — the tag IS a branch segment of $owner (like
    `{% else %}`: it ends the current body and starts the next).
  - `closes()`  — the tag ENDS $owner.

Containment — the tag changes nothing structurally; it is an ordinary leaf
that is merely not allowed everywhere.  A formatter prints it at the current
depth, which is why this factory is a preposition, not a verb:

  - `inside()`  — the tag may appear ONLY directly inside $owner.

The opener is the single source of truth for the structure.  A member's
`branches()`/`closes()` is an assertion that must AGREE with what the opener
declares; a disagreement is a registration error, not a silent override.
That is what catches "registered the closer, forgot the opener" — the claim
points at an owner that never declared the tag.

## Public methods

### opens() · <small>[🗎](../../src/Engine/Directive.php#L73)</small>

`public static function opens(string $closer, string ...$branches): self`

The tag OPENS a construct and names its parts.

`$closer` is a single named parameter, so "exactly one closing tag" is
guaranteed by the shape of the call — there is no position that could
accidentally receive a second one.  The variadic tail carries the optional
branch tags:

```php
Directive::opens('endcache', 'cache_else');
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$closer` | string | - |  |
| `$branches` | string | - | Optional branch tag keywords, at most one per tag word. |

**Return value**

- Type: `self`


---

### branches() · <small>[🗎](../../src/Engine/Directive.php#L82)</small>

`public static function branches(string $owner): self`

The tag IS a branch segment of `$owner`, like `{% else %}` (ends the
current body, starts the next; the construct stays open).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$owner` | string | - |  |

**Return value**

- Type: `self`


---

### closes() · <small>[🗎](../../src/Engine/Directive.php#L90)</small>

`public static function closes(string $owner): self`

The tag ENDS `$owner`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$owner` | string | - |  |

**Return value**

- Type: `self`


---

### inside() · <small>[🗎](../../src/Engine/Directive.php#L99)</small>

`public static function inside(string $owner): self`

The tag may appear ONLY directly inside `$owner`, and is otherwise an
ordinary leaf: it opens/bloses nothing and carries no cardinality.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$owner` | string | - |  |

**Return value**

- Type: `self`


---

### isOpener() · <small>[🗎](../../src/Engine/Directive.php#L105)</small>

`public function isOpener(): bool`

Whether this tag opens a construct (and therefore declares members).

**Return value**

- Type: `bool`


---

### isBranch() · <small>[🗎](../../src/Engine/Directive.php#L111)</small>

`public function isBranch(): bool`

Whether this tag claims to be a branch segment of its owner.

**Return value**

- Type: `bool`


---

### isCloser() · <small>[🗎](../../src/Engine/Directive.php#L117)</small>

`public function isCloser(): bool`

Whether this tag claims to close its owner.

**Return value**

- Type: `bool`


---

### isContainment() · <small>[🗎](../../src/Engine/Directive.php#L123)</small>

`public function isContainment(): bool`

Whether this tag is a leaf restricted to the inside of its owner.

**Return value**

- Type: `bool`


---

### owner() · <small>[🗎](../../src/Engine/Directive.php#L129)</small>

`public function owner(): string|null`

The opener keyword a branch/closer/containment tag claims, or null for an opener.

**Return value**

- Type: `string`|`null`


---

### closer() · <small>[🗎](../../src/Engine/Directive.php#L135)</small>

`public function closer(): string|null`

The closing keyword an opener declares, or null for a member.

**Return value**

- Type: `string`|`null`


---

### branchTags() · <small>[🗎](../../src/Engine/Directive.php#L145)</small>

`public function branchTags(): array`

The optional branch keywords an opener declares.

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
