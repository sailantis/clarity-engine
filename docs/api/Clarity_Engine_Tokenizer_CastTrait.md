# Class: CastTrait

**Full name:** [Clarity\Engine\Tokenizer\CastTrait](../../src/Engine/Tokenizer/CastTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

The PHP cast prefix: `(int) x`, `(string) (a + b)`, `(float) user.price`.

A cast is GRAMMAR, not a capability. It needs no policy rule and adds
nothing to `Policy::RULES`, exactly like `instanceof` — whose class-name
operand is likewise grammar rather than a reach. Consequently a cast changes
no policy digest and invalidates no compiled cache.

The hard part is telling a cast from a parenthesised sub-expression, because
`(int)` and `(a)` are lexically the same shape. The rule is what follows the
closing paren: whitespace is OPTIONAL, and the cast is decided by whether the
character after the `)` can OPEN an operand.

  `(int) x`   — a cast: whitespace, then a character that can OPEN an operand.
  `(int)x`    — a cast: the glued form is accepted too. A call, an index or a
                subtraction is what the text would otherwise have been, and
                naming a variable `int` to reach one is a collision rather than
                a reading, so the cast wins.
  `(a) + b`   — a parenthesised expression: `+` cannot open an operand, so
                this stays arithmetic. Likewise `(a) ? b : c` and `(a) foo`.
  `(a)`       — a parenthesised expression: nothing follows at all.

`(int) (x)` and `(int)(x)` are the same reading — a cast of the parenthesised
`x`. Neither is a call, because a call needs a CALLABLE name before the `(`
and a cast name is not one.

Whitespace is not what makes a cast; the cast NAME is. Gluing is therefore
accepted, at the cost of three spellings that change meaning for a template
that holds a variable named after a cast type:

  `(int)(x)`   a call on the variable `int`  → a cast of the grouped `x`
  `(int)[0]`   an index into the variable `int` → a cast of `[0]`
  `(int)-5`    the variable `int` minus 5    → a cast of `-5`

The matcher still returns null on EVERY other doubt and never throws, so the
ordinary parenthesised path in [`ExpressionCoreTrait::convertVarsAndOps()`](Clarity_Engine_Tokenizer_ExpressionCoreTrait.md#convertvarsandops)
stays the default for a name that is not a cast type.



---

[Back to the Index ⤴](README.md)
