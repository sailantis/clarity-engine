# Class: LocaleService

**Full name:** [Clarity\Localization\LocaleService](../../src/Localization/LocaleService.php)

Shared locale service for Clarity localization modules.

Registers a locale service under the engine service key `'locale'`
and installs the `{% with_locale %}` / `{% endwith_locale %}` block
directives so that both `TranslationModule` and `IntlFormatModule`
— and any user-defined modules — can participate in locale switching.

Registration
------------
The module is **optional**: `TranslationModule` and `IntlFormatModule` both
bootstrap it on their own, so `{% with_locale %}` works either way. Register
it explicitly to share one stack across them and to set an application-wide
default locale for the case where a module configures none of its own:

```php
$engine->addModule(new LocaleService(['locale' => 'de_DE']));
$engine->addModule(new TranslationModule([
    'translations_path' => __DIR__ . '/locales',
]));
```

| Option   | Type   | Default | Description                          |
| -------- | ------ | ------- | ------------------------------------ |
| `locale` | string | `null`  | Application-wide default locale, used only when neither the stack nor a module's own `locale` supplies one. It is **not** pushed onto the stack |

The configured default is deliberately kept *off* the stack: a stack entry
outranks every module's `locale` option, so seeding the stack would silently
discard the modules' configuration. Kept beside the stack it acts as the
lowest-precedence fallback instead, and each module keeps its own locale.

A later registration does not replace an already installed locale service,
but its configured default is still adopted when the installed one has none,
so the modules may bootstrap first and the explicit registration follow.

Template usage
--------------
```twig
{% with_locale "fr_FR" %}
    {{ price |> format_currency("EUR") }}
    {{ "greeting" |> t }}
{% endwith_locale %}
```

## Public methods

### __construct() · <small>[🗎](../../src/Localization/LocaleService.php#L70)</small>

`public function __construct(array $config = []): mixed`

Create a new LocaleService module instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | Configuration options for the module. |

**Return value**

- Type: `mixed`


---

### register() · <small>[🗎](../../src/Localization/LocaleService.php#L86)</small>

`public function register(Clarity\ClarityEngine $engine): void`

Register the locale service and the `with_locale` / `endwith_locale`
block handlers on the engine.

The instance itself becomes the engine's `'locale'` service, so the object
a caller already holds and the one templates reach are the same stack.

Registering when a service is already installed does not displace it — a
module that bootstrapped first keeps its stack — but a configured default
is still handed to it when it has none of its own.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**Return value**

- Type: `void`


---

### defaultLocale() · <small>[🗎](../../src/Localization/LocaleService.php#L122)</small>

`public function defaultLocale(): string|null`

Return the application-wide default locale, or null when none was configured.

This is the lowest-precedence fallback consulted by the localization
modules; it is not part of the `{% with_locale %}` stack, so `current()`
stays null until a block pushes a locale.

**Return value**

- Type: `string`|`null`


---

### detectLocale() · <small>[🗎](../../src/Localization/LocaleService.php#L127)</small>

`public static function detectLocale(): string`

**Return value**

- Type: `string`


---

### push() · <small>[🗎](../../src/Localization/LocaleService.php#L160)</small>

`public function push(string|null $locale): void`

Push a locale onto the stack.

Passing null or an empty string is a no-op so that template variables
that may be null do not corrupt the stack.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `void`


---

### pop() · <small>[🗎](../../src/Localization/LocaleService.php#L173)</small>

`public function pop(): void`

Pop the top locale from the stack.

Calling this when the stack is empty is a no-op.

**Return value**

- Type: `void`


---

### current() · <small>[🗎](../../src/Localization/LocaleService.php#L189)</small>

`public function current(): string|null`

Return the locale a `{% with_locale %}` block is currently applying,
or null when no block is active.

A `LocaleService` default locale is *not* reported here — it is a
fallback consulted by the modules, not a stack entry. Use
`self::defaultLocale()` for it.

**Return value**

- Type: `string`|`null`


---

### registerBlocks() · <small>[🗎](../../src/Localization/LocaleService.php#L200)</small>

`public static function registerBlocks(Clarity\ClarityEngine $engine): void`

Register `with_locale` / `endwith_locale` block handlers on the engine.

Called internally by `register()`, and also by `TranslationModule`
and `IntlFormatModule` when they need to self-bootstrap the service.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**Return value**

- Type: `void`


---

### bootstrap() · <small>[🗎](../../src/Localization/LocaleService.php#L238)</small>

`public static function bootstrap(Clarity\ClarityEngine $engine): static`

Ensure the locale service and blocks are available on the engine.

Called by `TranslationModule` and `IntlFormatModule` to
self-bootstrap when `LocaleService` was not explicitly registered.

Registering the module does NOT go through here: it installs the
instance it was called on, keeping its configured default off the stack.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**Return value**

- Type: `static`
- Description: The shared locale stack instance.



---

[Back to the Index ⤴](README.md)
