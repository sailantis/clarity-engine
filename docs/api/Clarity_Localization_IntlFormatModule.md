# Class: IntlFormatModule

**Full name:** [Clarity\Localization\IntlFormatModule](../../src/Localization/IntlFormatModule.php)

ICU / intl formatting module for the Clarity template engine.

Provides locale-aware number, currency, date, time, and text formatting
filters backed by PHP's `intl` extension. Each filter has a fallback when
`intl` is unavailable: a PHP-native equivalent, or the unmodified value.

Registration
------------
```php
// Optional: register to set an application-wide default locale
$engine->addModule(new LocaleService(['locale' => 'de_DE']));

$engine->addModule(new IntlFormatModule([
    'locale'    => 'de_DE',   // this module's locale; wins over the LocaleService default
    'timezone'  => 'Europe/Dublin',  // default timezone for date/time formatting
]));
```

Registered filters
------------------
| Filter            | Signature                                              | Description                                      |
|-------------------|--------------------------------------------------------|--------------------------------------------------|
| `format_number`   | `format_number($v [, $decimals=2] [, $locale])`        | Locale-aware decimal number                      |
| `format_currency` | `format_currency($v [, $currency='EUR'] [, $locale])`  | Locale-aware currency amount                     |
| `currency_name`   | `currency_name($code [, $locale])`                     | Currency code → display name (e.g. "US Dollar")  |
| `currency_symbol` | `currency_symbol($code [, $locale])`                   | Currency code → symbol (e.g. "$")                |
| `percent`         | `percent($v [, $decimals=0] [, $locale])`              | Locale-aware percentage                          |
| `scientific`      | `scientific($v [, $locale])`                           | Scientific notation (e.g. "1.23E4")              |
| `spellout`        | `spellout($v [, $locale])`                             | Number → words (e.g. "forty-two")                |
| `ordinal`         | `ordinal($v [, $locale])`                              | Ordinal suffix (e.g. "1st", "2nd")               |
| `format_date`     | `format_date($v [, $style='medium'] [, $locale] [, $tz])` | Locale-aware date                            |
| `format_time`     | `format_time($v [, $style='medium'] [, $locale] [, $tz])` | Locale-aware time                            |
| `format_datetime` | `format_datetime($v [, $ds='medium'] [, $ts='medium'] [, $locale] [, $tz])` | Date + time       |
| `format_relative` | `format_relative($v [, $locale])`                      | Relative time ("3 minutes ago")                  |
| `transliterate`   | `transliterate($v [, $rules='Any-Latin; Latin-ASCII'])` | Transliterate text                               |
| `format_message`  | `format_message($pattern [, $vars] [, $locale])`       | ICU MessageFormat (plurals, selects, …)          |

Registered functions
--------------------
| Function          | Signature                                              | Description                                      |
|-------------------|--------------------------------------------------------|--------------------------------------------------|
| `country_name`    | `country_name($code [, $displayLocale] [, $locale])`  | ISO country code → display name                  |
| `language_name`   | `language_name($code [, $displayLocale] [, $locale])` | Language code → display name                     |
| `locale_name`     | `locale_name($id [, $displayLocale] [, $locale])`     | Locale identifier → display name                 |
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
{{ "USD" |> currency_name }}
{{ country_name("DE") }}
{{ language_name("de") }}
{{ locale_name("en_US") }}
{{ timezone_name("America/New_York") }}
```

## Public methods

### __construct() · <small>[🗎](../../src/Localization/IntlFormatModule.php#L91)</small>

`public function __construct(array $config = []): mixed`

Create a new IntlFormatModule instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | `locale`: locale for formatting (e.g. "en_US"). Falls back to the<br>LocaleService default, then the detected environment locale.<br>`timezone`: default timezone (e.g. "UTC" or "Europe/Berlin"). Falls back<br>to `date_default_timezone_get()`. |

**Return value**

- Type: `mixed`


---

### register() · <small>[🗎](../../src/Localization/IntlFormatModule.php#L100)</small>

`public function register(Clarity\ClarityEngine $engine): void`

Register all filters, functions, services, and directives that
this module provides into the given engine instance.

[`ClarityEngine::addModule()`](Clarity_ClarityEngine.md#addmodule) calls this method immediately. Call
addModule() before rendering templates so the module's filters and
directives are available.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - | The engine to register into. |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
