# 🧩 Class: LocaleService

**Full name:** [Clarity\Localization\LocaleService](../../src/Localization/LocaleService.php)

Shared locale service for Clarity localization modules.

Registers a locale service under the engine service key `'locale'`
and installs the `{% with_locale %}` / `{% endwith_locale %}` block
directives so that both `TranslationModule` and `IntlFormatModule`
— and any user-defined modules — can participate in locale switching.

Registration order
------------------
Always register `LocaleService` **before** the translation / format modules:

```php
$engine->use(new LocaleService(['locale' => 'de_DE']));
$engine->use(new TranslationModule(['translations_path' => __DIR__ . '/locales']));
$engine->use(new IntlFormatModule());
```

If either translation or format module is registered without a prior
`LocaleService`, they create their own locale service automatically.
The `with_locale` blocks are then registered by whichever module runs first.

Template usage
--------------
```twig
{% with_locale "fr_FR" %}
    {{ price |> format_currency("EUR") }}
    {{ "greeting" |> t }}
{% endwith_locale %}
```

## 🚀 Public methods

### __construct() · [source](../../src/Localization/LocaleService.php#L52)

`public function __construct(string $defaultLocale = ''): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$defaultLocale` | string | `''` | Locale returned when the stack is empty.<br>Defaults to PHP's Locale::getDefault(), or<br>'en_US' if that is unset. |

**➡️ Return value**

- Type: mixed


---

### push() · [source](../../src/Localization/LocaleService.php#L94)

`public function push(string|null $locale): void`

Push a locale onto the stack.

Passing null or an empty string is a no-op so that template variables
that may be null do not corrupt the stack.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**➡️ Return value**

- Type: void


---

### pop() · [source](../../src/Localization/LocaleService.php#L107)

`public function pop(): void`

Pop the top locale from the stack.

Calling this when the stack is empty is a no-op.

**➡️ Return value**

- Type: void


---

### current() · [source](../../src/Localization/LocaleService.php#L119)

`public function current(): string`

Return the currently active locale (top of the stack), or the default
locale when the stack is empty.

**➡️ Return value**

- Type: string


---

### setDefault() · [source](../../src/Localization/LocaleService.php#L127)

`public function setDefault(string $locale): void`

Change the default locale used when the stack is empty.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - |  |

**➡️ Return value**

- Type: void


---

### registerBlocks() · [source](../../src/Localization/LocaleService.php#L138)

`public static function registerBlocks(Clarity\ClarityEngine $engine): void`

Register `with_locale` / `endwith_locale` block handlers on the engine.

Called internally by `register()`, and also by `TranslationModule`
and `IntlFormatModule` when they need to self-bootstrap the service.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**➡️ Return value**

- Type: void


---

### bootstrap() · [source](../../src/Localization/LocaleService.php#L172)

`public static function bootstrap(Clarity\ClarityEngine $engine, string $defaultLocale): static`

Ensure the locale service and blocks are available on the engine.

Called by `TranslationModule` and `IntlFormatModule` to
self-bootstrap when `LocaleService` was not explicitly registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |
| `$defaultLocale` | string | - |  |

**➡️ Return value**

- Type: static
- Description: The shared locale stack instance.



---

[Back to the Index ⤴](README.md)
