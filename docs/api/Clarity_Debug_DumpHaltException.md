# Class: DumpHaltException

**Full name:** [Clarity\Debug\DumpHaltException](../../src/Debug/DumpHaltException.php)

Thrown by dd() when [`DumpOptions::haltWithException()`](Clarity_Debug_DumpOptions.md#haltwithexception) is set. It
carries the rendered dump, so the host can send it as the response.

The host must catch this exception at the request boundary. If nothing
catches it, it propagates like any other uncaught error.

## Public Properties

- `public readonly` string `$context` · <small>[🗎](../../src/Debug/DumpHaltException.php)</small>
- `public readonly` string `$output` · <small>[🗎](../../src/Debug/DumpHaltException.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DumpHaltException.php#L20)</small>

`public function __construct(string $context, string $output): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$context` | string | - | The escape context of the dd() call: 'html', 'js' or 'css'. |
| `$output` | string | - | The rendered dump for that context. |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
