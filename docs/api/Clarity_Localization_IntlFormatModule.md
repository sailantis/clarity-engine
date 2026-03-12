# 🧩 Class: IntlFormatModule

**Full name:** [Clarity\Localization\IntlFormatModule](../../src/Localization/IntlFormatModule.php)

ICU / intl formatting module for the Clarity template engine.

Provides locale-aware number, currency, date, time, and text formatting
filters backed by PHP's `intl` extension. Every filter degrades gracefully
when `intl` is unavailable, falling back to a PHP-native equivalent or
returning the value unmodified.

Registration
------------
```php
// Optional: explicit locale service (register first to share with TranslationModule)
$engine->use(new LocaleService(['locale' => 'de_DE']));

$engine->use(new IntlFormatModule([
    'locale'    => 'de_DE',   // default locale (inherits from LocaleService if registered first)
    'timezone'  => 'Europe/Dublin',  // default timezone for date/time formatting
]));
```

Registered filters
------------------
| Filter            | Signature                                              | Description                                      |
|-------------------|--------------------------------------------------------|--------------------------------------------------|
| `format_number`   | `format_number($v [, $decimals=2] [, $loc])`           | Locale-aware decimal number                      |
| `format_currency` | `format_currency($v [, $currency='EUR'] [, $loc])`     | Locale-aware currency amount                     |
| `currency_name`   | `currency_name($code [, $displayLocale] [, $loc])`     | Currency code → display name (e.g. "US Dollar")  |
| `currency_symbol` | `currency_symbol($code [, $loc])`                      | Currency code → symbol (e.g. "$")                |
| `percent`         | `percent($v [, $decimals=0] [, $loc])`                 | Locale-aware percentage                          |
| `scientific`      | `scientific($v [, $loc])`                              | Scientific notation (e.g. "1.23E4")              |
| `spellout`        | `spellout($v [, $loc])`                                | Number → words (e.g. "forty-two")                |
| `ordinal`         | `ordinal($v [, $loc])`                                 | Ordinal suffix (e.g. "1st", "2nd")               |
| `format_date`     | `format_date($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware date                                |
| `format_time`     | `format_time($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware time                                |
| `format_datetime` | `format_datetime($v [, $ds='medium'] [, $ts='medium'] [, $loc] [, $tz])` | Date + time               |
| `format_relative` | `format_relative($v [, $loc])`                         | Relative time ("3 minutes ago")                  |
| `transliterate`   | `transliterate($v [, $rules='Any-Latin; Latin-ASCII'])` | Transliterate text                               |
| `format_message`  | `format_message($pattern [, $vars=[]] [, $loc])`       | ICU MessageFormat (plurals, selects, …)          |

Registered functions
--------------------
| Function          | Signature                                              | Description                                      |
|-------------------|--------------------------------------------------------|--------------------------------------------------|
| `country_name`    | `country_name($code [, $displayLocale] [, $loc])`      | ISO country code → display name                  |
| `language_name`   | `language_name($code [, $displayLocale] [, $loc])`     | Language code → display name                     |
| `locale_name`     | `locale_name($id [, $displayLocale] [, $loc])`         | Locale identifier → display name                 |
| `timezone_name`   | `timezone_name($tz [, $displayLocale])`                | Timezone identifier → display name               |

Template usage
--------------
```twig
{{ 1234567.89 |> format_number(2) }}
{{ price |> format_currency("USD") }}
{{ 0.1234 |> percent }}
{{ 42 |> spellout }}
{{ 1 |> ordinal }}
{{ order.created_at |> format_date("long") }}
{{ order.created_at |> format_relative }}
{{ "Hëllo Wörld" |> transliterate }}
{{ "{count, plural, one{# item} other{# items}}" |> format_message({count: n}) }}
{{ currency_name("USD") }}
{{ country_name("DE") }}
{{ language_name("de") }}
{{ locale_name("en_US") }}
{{ timezone_name("America/New_York") }}
```

## 🚀 Public methods

### __construct() · [source](../../src/Localization/IntlFormatModule.php#L100)

`public function __construct(array $config = []): mixed`

Create a new IntlFormatModule instance.

```php
Config options: {
    string|null $locale   Default locale (e.g. "en_US"). Inherits from LocaleService if omitted.
    string|null $timezone Default timezone (e.g. "UTC" or "Europe/Berlin").
}
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | Configuration options for the module. |

**➡️ Return value**

- Type: mixed


---

### register() · [source](../../src/Localization/IntlFormatModule.php#L108)

`public function register(Clarity\ClarityEngine $engine): void`

Register all filters, functions, services, and block directives that
this module provides into the given engine instance.

This method is called once by [`ClarityEngine::use()`](Clarity_ClarityEngine.md#use) at engine
setup time, before any templates are compiled or rendered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - | The engine to register into. |

**➡️ Return value**

- Type: void



---

[Back to the Index ⤴](README.md)
