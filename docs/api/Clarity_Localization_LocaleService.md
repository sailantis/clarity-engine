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
| `locale` | string | `null`  | Application-wide default locale, used only when neither the stack nor a module's own `locale` supplies one. It is **not** pushed onto the stack. |

The configured default is kept off the stack. A stack entry outranks every
module's `locale` option, so seeding the stack would override the modules'
own configuration. Stored separately, the default is consulted after the
stack and each module's `locale` option, and before the detected environment
locale.

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

### __construct() · <small>[🗎](../../src/Localization/LocaleService.php#L71)</small>

`public function __construct(array $config = []): mixed`

Create a new LocaleService module instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | Configuration options for the module. |

**Return value**

- Type: `mixed`


---

### register() · <small>[🗎](../../src/Localization/LocaleService.php#L87)</small>

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

The localization modules consult this value after the stack and their own
`locale` option, and before the detected environment locale. It is not part
of the `{% with_locale %}` stack, so `current()` stays null until a block
pushes a locale.

**Return value**

- Type: `string`|`null`


---

### detectLocale() · <small>[🗎](../../src/Localization/LocaleService.php#L134)</small>

`public static function detectLocale(): string`

Detect the environment locale.

Checks, in order: the intl default locale, the process C locale (skipped
when it is `C`), the `LC_ALL`, `LANG` and `LANGUAGE` environment variables,
and finally `en_US`.

**Return value**

- Type: `string`


---

### push() · <small>[🗎](../../src/Localization/LocaleService.php#L167)</small>

`public function push(string|null $locale): void`

Push a locale onto the stack.

Null or an empty string pushes the current locale, so the block changes
nothing. Every call adds one entry, which keeps it paired with `pop()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `void`


---

### pop() · <small>[🗎](../../src/Localization/LocaleService.php#L178)</small>

`public function pop(): void`

Pop the top locale from the stack.

Calling this when the stack is empty is a no-op.

**Return value**

- Type: `void`


---

### current() · <small>[🗎](../../src/Localization/LocaleService.php#L194)</small>

`public function current(): string|null`

Return the locale a `{% with_locale %}` block is currently applying,
or null when no block is active.

A `LocaleService` default locale is *not* reported here — it is a
fallback consulted by the modules, not a stack entry. Use
`self::defaultLocale()` for it.

**Return value**

- Type: `string`|`null`


---

### registerBlocks() · <small>[🗎](../../src/Localization/LocaleService.php#L205)</small>

`public static function registerBlocks(Clarity\ClarityEngine $engine): void`

Register `with_locale` / `endwith_locale` block handlers on the engine.

Called by `register()` and by `bootstrap()`, which `TranslationModule`
and `IntlFormatModule` use to self-bootstrap the service.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**Return value**

- Type: `void`


---

### bootstrap() · <small>[🗎](../../src/Localization/LocaleService.php#L244)</small>

`public static function bootstrap(Clarity\ClarityEngine $engine): static`

Ensure the locale service and blocks are available on the engine.

Called by `TranslationModule` and `IntlFormatModule` to
self-bootstrap when `LocaleService` was not explicitly registered.

A service created here has no configured default. Registering the module
does not go through here: it installs the instance it was called on,
keeping its configured default.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**Return value**

- Type: `static`
- Description: The shared locale stack instance.



---

[Back to the Index ⤴](README.md)
