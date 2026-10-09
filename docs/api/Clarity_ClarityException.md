# Class: ClarityException

**Full name:** [Clarity\ClarityException](../../src/ClarityException.php)

Exception thrown when a Clarity template fails to compile or render.

Records where the failure occurred:

 - `$templateName`: the logical name the loader was asked for (`pages/home`,
   `generated-docs::intro`). Set whenever it is known.
 - `$templatePath`: the physical file that name resolved to. Set only for
   file-backed loaders; empty for array, string, and database loaders.
 - `$templateLine`: the 1-based line within that template.

The location is also written to the inherited `$file` and `$line`, so
`getFile()` and `getLine()` point at the template instead of the engine frame
that threw. `$templatePath` is preferred there because IDEs and xdebug can
open it; `$templateName` is the fallback. The properties are `protected` and
their getters are `final`, so reassigning them is the only way to relocate
the exception. The stack trace is left unchanged, because Clarity's runtime
source mapping walks it.

## Public Properties

- `public readonly` string `$templateName` · <small>[🗎](../../src/ClarityException.php)</small>
- `public readonly` int `$templateLine` · <small>[🗎](../../src/ClarityException.php)</small>
- `public readonly` string `$templatePath` · <small>[🗎](../../src/ClarityException.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/ClarityException.php#L57)</small>

`public function __construct(string $message, Clarity\Template\TemplateLocation|string $templateName = '', int $templateLine = 0, string $templatePath = '', Throwable|null $previous = null): mixed`

Construct a new ClarityException.

The location may be given as three separate values, or as a single
[`TemplateLocation`](Clarity_Template_TemplateLocation.md), which is what a directive handler receives and can
pass straight back:

```php
throw new ClarityException('cache needs a key', $at);
```

A handler cannot determine the physical path itself, because the active
loader decides it. Passing the whole location keeps the exception complete,
so no engine layer needs to fill in missing values.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - | The exception message. |
| `$templateName` | [TemplateLocation](Clarity_Template_TemplateLocation.md)\|string | `''` | The logical template name, or a TemplateLocation with name, line, and path. |
| `$templateLine` | int | `0` | The 1-based line number within the template. |
| `$templatePath` | string | `''` | The physical path to the template file. |
| `$previous` | Throwable\|null | `null` | The previous exception, if any. |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
