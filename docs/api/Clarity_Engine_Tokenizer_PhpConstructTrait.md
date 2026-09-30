# Class: PhpConstructTrait

**Full name:** [Clarity\Engine\Tokenizer\PhpConstructTrait](../../src/Engine/Tokenizer/PhpConstructTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

The three constructs that name a PHP CLASS rather than a template value:
`new Foo(...)`, `Foo::member`, and the right operand of `instanceof`.

All three are gated on a policy capability (`newExpressions`, `staticCalls`)
and all three compile the name to a FULLY QUALIFIED form with a leading `\`.

That leading separator is the whole point.  A compiled template is a plain
class in the global namespace with no `use` statements, so an unqualified
`new DateTime()` would be resolved as `\DateTime` by luck and as
`\Clarity\Engine\DateTime`-style names by nobody's intention.  Emitting `\`
makes the resolution explicit and independent of where the engine lives —
which is also why leaving the name bare (the bug this replaced) produced
`unexpected fully qualified name "\DateTime"` from PHP: a `\` was reaching
the output with no name attached to it.



---

[Back to the Index ⤴](README.md)
