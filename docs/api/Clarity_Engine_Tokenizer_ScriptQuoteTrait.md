# Class: ScriptQuoteTrait

**Full name:** [Clarity\Engine\Tokenizer\ScriptQuoteTrait](../../src/Engine/Tokenizer/ScriptQuoteTrait.php)

Decides how a script interpolation is emitted, from where it sits.

`js` context has two output forms, and the position selects between them:

    var name = "{{ user.name }}";   ->  var name = "Ada";        (string content)
    var obj  = {{ user }};          ->  var obj  = {"name":"Ada"}; (JSON literal)

The template supplies the quotes when it wants a string; an unquoted tag is a
complete JSON literal, so `{{ n }}` renders `3` and `{{ tiers }}` renders
`[1,2]`.

## Why this trait exists

Choosing between two forms means the choice can be WRONG, and a wrong choice
changes what is rendered. That is the risk this file is entirely about. It was
taken deliberately, after seeing that a bare `{{ x }}` is the form people
naturally write and that rejecting it forced a workaround for every number,
boolean and JSON payload.

The mitigation is that a misjudgement must fail loudly:

  - wrong in the code-position direction (a quoted tag read as code) emits a
    JSON literal, which for a string value is a double quote — a JavaScript
    syntax error on that line;
  - wrong in the string direction (a code tag read as quoted) emits escaped
    string content, which for a string value is a bare identifier or a
    ReferenceError.

Neither silently produces a different-but-valid value, and the numbers case
coincides on purpose: `json_encode(12)` and the string content of `12` are both
`12`.

## Why the scan lexes instead of counting quotes

Counting quotes would be enough if JavaScript had no comments or regular
expressions. One quote in the wrong place opens a phantom string that swallows
the rest of the line:

    <script>/* ' *\/ var x = {{ v }};</script>
    <script>var r = /'[/; var x = {{ v }};</script>

Read as quoted, that emitted the value as bare JavaScript — script injection
from data the template did not control. Comments and regular expressions are
therefore lexed here, so the quote cannot be misread.

## States

| Position              | Emitted           | Why                              |
| --------------------- | ----------------- | -------------------------------- |
| inside `"…"` or `'…'` | escaped content   | cannot close the literal         |
| inside a comment      | escaped content   | inert either way                 |
| code position         | JSON literal      | a literal is valid where a value is expected |
| inside a regex        | **rejected**      | a value inside `/…/` is not meaningful |
| inside a template     | **rejected**      | `` ` `` and `${` are not escaped by either form, so a value containing them would break out or execute |

## Known limitations

  - Template literals are tracked opaquely: `${ … }` is not parsed, so a tag
    inside the interpolation of a template literal is rejected too. Use a
    normal string.
  - A comment opener split across two text segments (`/` ending one segment,
    `*` starting the next) is not recognised; a tag between them is rejected.



---

[Back to the Index ⤴](README.md)
