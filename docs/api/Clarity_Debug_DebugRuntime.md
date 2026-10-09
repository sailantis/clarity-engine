# Class: DebugRuntime

**Full name:** [Clarity\Debug\DebugRuntime](../../src/Debug/DebugRuntime.php)

Holds the debug state of an engine: the dump renderers, the DumpOptions, the
event bus and the optional HTML panel.

The engine creates a DebugRuntime when debug is enabled and registers its
handlers on the [`Registry`](Clarity_Engine_Registry.md). Every `dump()` and `dd()` call and every
`{{ x |> dump }}` probe is routed through this object, so all debug output
is produced by the renderers in this namespace. The registry holds no debug
behaviour of its own.

## Public Properties

- `public readonly` [DebugEventBus](Clarity_Debug_DebugEventBus.md) `$bus` · <small>[🗎](../../src/Debug/DebugRuntime.php)</small>
- `public readonly` [HtmlDebugPanel](Clarity_Debug_HtmlDebugPanel.md)|null `$panel` · <small>[🗎](../../src/Debug/DebugRuntime.php)</small>
- `public readonly` [DumpOptions](Clarity_Debug_DumpOptions.md) `$options` · <small>[🗎](../../src/Debug/DebugRuntime.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DebugRuntime.php#L32)</small>

`public function __construct(Clarity\Debug\DumpOptions $options): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$options` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**Return value**

- Type: `mixed`


---

### register() · <small>[🗎](../../src/Debug/DebugRuntime.php#L53)</small>

`public function register(Clarity\Engine\Registry $registry): void`

Registers this runtime's dump and dd handlers on a registry.

`dump()` goes through one formatter. It renders for the escape context
('html', 'js' or 'css') that the compiler passes as the first argument,
and it masks the keys listed in [`DumpOptions::maskKeys()`](Clarity_Debug_DumpOptions.md#maskkeys).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**Return value**

- Type: `void`


---

### render() · <small>[🗎](../../src/Debug/DebugRuntime.php#L70)</small>

`public function render(string $ctx, array $args): string`

Renders a value for the given escape context. Used by `dump()` and by the
`{{ x |> dump }}` probe. A single argument is rendered as-is; several
arguments are rendered as one list.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ctx` | string | - |  |
| `$args` | array | - |  |

**Return value**

- Type: `string`


---

### probe() · <small>[🗎](../../src/Debug/DebugRuntime.php#L91)</small>

`public function probe(string $ctx, mixed $value, mixed ...$args): mixed`

Pass-through probe behind the filter form `{{ x |> dump }}`. Emits the
dump at the pipe position and returns the piped value unchanged, so later
filters still receive the original value.

The dump is echoed rather than returned, because the return value is what
continues down the pipe: `{{ x |> dump |> length }}` must measure x. In JS
and CSS contexts the dump appears as a comment at the same position where
`dump(x)` places it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ctx` | string | - |  |
| `$value` | mixed | - |  |
| `$args` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### dumpAndDie() · <small>[🗎](../../src/Debug/DebugRuntime.php#L114)</small>

`public function dumpAndDie(string $ctx, array $args): never`

Behind `dd()`: renders the value, then ends the request with exit code 1.

On the CLI, output goes to STDERR, the same stream `dump()` uses, so STDOUT
stays free for program output.

With [`DumpOptions::haltWithException()`](Clarity_Debug_DumpOptions.md#haltwithexception) set, the value is rendered for
the context and thrown as a [`DumpHaltException`](Clarity_Debug_DumpHaltException.md) instead, on every SAPI.
The process keeps running, so the host decides what to do with the output.

The compiler does not prune `dd()`, so the call remains in production
templates. This handler is bound only while debug is on; otherwise the
registry raises an error.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ctx` | string | - |  |
| `$args` | array | - |  |

**Return value**

- Type: `never`



---

[Back to the Index ⤴](README.md)
