# Class: DebugRuntime

**Full name:** [Clarity\Debug\DebugRuntime](../../src/Debug/DebugRuntime.php)

The one place that knows how debug output is produced.

Clarity used to have two debug entry points that disagreed: a low-level
`setDebugMode(true)` that merely let `dump()` resolve, and `enableDebug()`
that also installed the renderers, the event bus and the HTML panel.  The
first was a degraded form of the second — and, because the fallback formatter
used `print_r`, a documented public method that printed secrets the masking
renderer would have hidden.

There is only one debug state now, and this object is it.  The engine builds
a DebugRuntime when debug is switched on and hands it to the
[`Registry`](Clarity_Engine_Registry.md), which is what every `dump()`/`dd()` call and every
`{{ x |> dump }}` probe actually reaches.

The registry deliberately owns no debug behaviour of its own: the renderers
and the [`DumpOptions`](Clarity_Debug_DumpOptions.md) live here, so there is no second code path that
could render a value without masking it.

## Public Properties

- `public readonly` [DebugEventBus](Clarity_Debug_DebugEventBus.md) `$bus` · <small>[🗎](../../src/Debug/DebugRuntime.php)</small>
- `public readonly` [HtmlDebugPanel](Clarity_Debug_HtmlDebugPanel.md)|null `$panel` · <small>[🗎](../../src/Debug/DebugRuntime.php)</small>
- `public readonly` [DumpOptions](Clarity_Debug_DumpOptions.md) `$options` · <small>[🗎](../../src/Debug/DebugRuntime.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Debug/DebugRuntime.php#L41)</small>

`public function __construct(Clarity\Debug\DumpOptions $options): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$options` | [DumpOptions](Clarity_Debug_DumpOptions.md) | - |  |

**Return value**

- Type: `mixed`


---

### register() · <small>[🗎](../../src/Debug/DebugRuntime.php#L61)</small>

`public function register(Clarity\Engine\Registry $registry): void`

Install this runtime's formatters on a registry.

`dump` goes through exactly one formatter, which renders according to the
compile-time context and masks the keys listed in [`DumpOptions::maskKeys()`](Clarity_Debug_DumpOptions.md#maskkeys).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**Return value**

- Type: `void`


---

### render() · <small>[🗎](../../src/Debug/DebugRuntime.php#L76)</small>

`public function render(string $ctx, array $args): string`

The body of `dump(…)` and of the `{{ x |> dump }}` probe: rendered output.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ctx` | string | - |  |
| `$args` | array | - |  |

**Return value**

- Type: `string`


---

### probe() · <small>[🗎](../../src/Debug/DebugRuntime.php#L97)</small>

`public function probe(string $ctx, mixed $value, mixed ...$args): mixed`

The pass-through probe behind the FILTER form `{{ x |> dump }}`: emit the
dump at the pipe position, then RETURN the piped value so a following
step still sees the original value.

The dump is emitted (not returned) because the filter RESULT is the
value: `{{ x |> dump |> length }}` must measure x. Emitting keeps the
debug output visible at the pipe position, including as comments in JS
and CSS contexts, exactly where `dump(x)` puts it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ctx` | string | - |  |
| `$value` | mixed | - |  |
| `$args` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### dumpAndDie() · <small>[🗎](../../src/Debug/DebugRuntime.php#L112)</small>

`public function dumpAndDie(string $ctx, array $args): never`

The body of `dd(…)`: render, then end the request.

`dd()` is never pruned, so it is reachable even in production; the
registry therefore only binds this handler while debug is on.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ctx` | string | - |  |
| `$args` | array | - |  |

**Return value**

- Type: `never`



---

[Back to the Index ⤴](README.md)
