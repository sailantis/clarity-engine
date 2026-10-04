# Class: ClarityException

**Full name:** [Clarity\ClarityException](../../src/ClarityException.php)

Exception thrown when a Clarity template fails to compile or render.

Carries where the failure belongs, in two forms:

 - `$templateName` — the LOGICAL name the loader was asked for
   (`pages/home`, `generated-docs::intro`).  Always known.
 - `$templatePath` — the PHYSICAL file that name resolved to, when the active
   loader is file-backed.  Empty for an array/string/database loader, which
   has no path to give.

together with `$templateLine` within that template.

The location is also written onto the inherited `$file` / `$line`, so
`getFile()` / `getLine()` — and everything built on them, beginning with the
uncaught-fatal line `Fatal error: Uncaught … in <file>:<line>` and xdebug's
develop-mode error page — name the template rather than the engine frame that
threw.  `$templatePath` is preferred there, because it is the form an IDE can
open; the logical name is the fallback.  Those two properties are `protected`
and their getters are `final`, so assigning them is the only way to relocate
the location; Smarty does the same thing for the same reason.  The trace is
deliberately left alone: the real call path through the engine stays useful,
and Clarity's own runtime mapping walks it.

## Public Properties

- `public readonly` string `$templateName` · <small>[🗎](../../src/ClarityException.php)</small>
- `public readonly` int `$templateLine` · <small>[🗎](../../src/ClarityException.php)</small>
- `public` string `$templatePath` · <small>[🗎](../../src/ClarityException.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/ClarityException.php#L63)</small>

`public function __construct(string $message, Clarity\Template\TemplateLocation|string $templateName = '', int $templateLine = 0, string $templatePath = '', Throwable|null $previous = null): mixed`

Construct a new ClarityException.

The location may be given as the three separate values an engine layer
already holds, or as a single [`TemplateLocation`](Clarity_Template_TemplateLocation.md) — what a directive
handler is handed, and can hand straight back:

```php
throw new ClarityException('cache needs a key', $at);
```

That form matters because the handler cannot know the physical path on its
own: the active loader is the only authority on it. Handing the whole
location through keeps the exception COMPLETE, so no engine layer has to
fill in what the thrower was never given.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - | The exception message. |
| `$templateName` | [TemplateLocation](Clarity_Template_TemplateLocation.md)\|string | `''` | The logical name of the template, or a TemplateLocation carrying name, line and path. |
| `$templateLine` | int | `0` | The line number within the template. |
| `$templatePath` | string | `''` | The physical path to the template file. |
| `$previous` | Throwable\|null | `null` | The previous exception, if any. |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
