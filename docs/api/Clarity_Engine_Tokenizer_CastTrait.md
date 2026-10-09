# Class: CastTrait

**Full name:** [Clarity\Engine\Tokenizer\CastTrait](../../src/Engine/Tokenizer/CastTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

The PHP cast prefix: `(int) x`, `(string) (a + b)`, `(float) user.price`.

Casts are grammar, not a policy rule: they add nothing to `Policy::RULES`.

The hard part is telling a cast from a parenthesised sub-expression, because
`(int)` and `(a)` have the same shape. A cast is recognised by a fixed cast
name (see CAST_TYPES) followed by an operand, so the character after the `)`
must be able to open one.

  `(int) x`   a cast: whitespace, then an operand opener.
  `(int)x`    a cast: whitespace is optional.
  `(a) + b`   not a cast: `a` is not a cast name, and `+` cannot open an operand.
  `(a)`       not a cast: nothing follows.

`(int) (x)` and `(int)(x)` are both a cast of the parenthesised `x`. Neither is
a call, because a call needs a callable name before the `(`.

Gluing is accepted, which has a cost. For a template that holds a variable named
after a cast type, these spellings change meaning:

  `(int)(x)`   a call on the variable `int`     → a cast of the grouped `x`
  `(int)[0]`   an index into the variable `int` → a cast of `[0]`
  `(int)-5`    the variable `int` minus 5       → a cast of `-5`

The matcher returns null on any doubt and never throws, so the ordinary
parenthesised path in [`ExpressionCoreTrait::convertVarsAndOps()`](Clarity_Engine_Tokenizer_ExpressionCoreTrait.md#convertvarsandops) still
handles any name that is not a cast type.



---

[Back to the Index ⤴](README.md)
