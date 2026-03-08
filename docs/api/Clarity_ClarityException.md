# 🧩 Class: ClarityException

**Full name:** [Clarity\ClarityException](../../src/ClarityException.php)

Exception thrown when a Clarity template fails to compile or render.

Carries the original source template file and the line number within
that template, allowing error messages to point at the `.clarity.html`
source rather than the compiled PHP cache file.

## 🔐 Public Properties

- `public readonly` string `$templateFile` · [source](../../src/ClarityException.php)
- `public readonly` int `$templateLine` · [source](../../src/ClarityException.php)

## 🚀 Public methods

### __construct() · [source](../../src/ClarityException.php#L13)

`public function __construct(string $message, string $templateFile = '', int $templateLine = 0, Throwable|null $previous = null): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - |  |
| `$templateFile` | string | `''` |  |
| `$templateLine` | int | `0` |  |
| `$previous` | Throwable\|null | `null` |  |

**➡️ Return value**

- Type: mixed



---

[Back to the Index ⤴](README.md)
