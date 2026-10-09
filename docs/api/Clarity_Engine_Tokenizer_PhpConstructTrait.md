# Class: PhpConstructTrait

**Full name:** [Clarity\Engine\Tokenizer\PhpConstructTrait](../../src/Engine/Tokenizer/PhpConstructTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

Three constructs name a PHP CLASS rather than a template value: `new Foo(...)`,
`Foo::member`, and the right operand of `instanceof`.

`new` and `Foo::member` are gated by the `newExpressions` and `staticCalls`
policy rules. `instanceof` is not gated by a rule.

All three compile the class name to a fully qualified form with a leading `\`,
so the name resolves as a global class name regardless of the namespace the
compiled template is emitted in.



---

[Back to the Index ⤴](README.md)
