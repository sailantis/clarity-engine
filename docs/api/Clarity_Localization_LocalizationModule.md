# 🧩 Class: LocalizationModule

**Full name:** [Clarity\Localization\LocalizationModule](../../src/Localization/LocalizationModule.php)

Clarity Localization Module

Bundles a complete, locale-aware suite of filters and a `with_locale` block
directive into a single registerable unit.

Registration
------------
```php
$engine->use(new LocalizationModule([
    'locale'            => 'de_DE',          // default locale
    'fallback_locale'   => 'en_US',          // fallback when key missing
    'translations_path' => __DIR__ . '/locales', // directory with *.json files
]));
```

Registered filters
------------------
| Filter          | Signature                                        | Description                        |
|-----------------|--------------------------------------------------|------------------------------------|
| `t`             | `t($key [, $vars=[]])`                           | Translation lookup                 |
| `plural`        | `plural($key, $count)`                           | ICU plural translation             |
| `format_date`   | `format_date($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware date          |
| `format_time`   | `format_time($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware time          |
| `format_datetime` | `format_datetime($v [, $ds='medium'] [, $ts='medium'] [, $loc] [, $tz])` | Date+time |
| `intl_number`   | `intl_number($v [, $decimals=0] [, $loc])`       | Locale-aware number format         |
| `currency`      | `currency($v [, $currency='EUR'] [, $loc])`      | Locale-aware currency format       |
| `percent`       | `percent($v [, $loc])`                           | Locale-aware percent format        |

Registered blocks
-----------------
| Block          | Description                                     |
|----------------|-------------------------------------------------|
| `with_locale`  | Push a locale onto the locale stack             |
| `endwith_locale` | Pop the locale from the locale stack          |

Template examples
-----------------
```clarity
{# Simple translation #}
{{ "logout" |> t }}

{# Translation with placeholders #}
{{ "greeting" |> t({"name": user.name}) }}

{# Plural translation (ICU MessageFormat) #}
{{ item_count |> plural("item_count") }}

{# Locale-aware date #}
{{ order.created_at |> format_date("long") }}

{# Currency #}
{{ price |> currency("USD") }}

{# Temporary locale switch #}
{% with_locale user.locale %}
    {{ price |> currency("EUR") }}
    {{ date |> format_date("long") }}
{% endwith_locale %}
```

Accessing the locale object directly
-------------------------------------
The shared `ClarityLocale` instance is also stored under the `locale`
service key, so all registered filter closures *and* the compiled template's
`$__sv['locale']` refer to the same object — locale changes in one
place are immediately visible everywhere.

## 🚀 Public methods

### __construct() · [source](../../src/Localization/LocalizationModule.php#L84)

`public function __construct(array $config = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` |  |

**➡️ Return value**

- Type: mixed


---

### register() · [source](../../src/Localization/LocalizationModule.php#L92)

`public function register(Clarity\ClarityEngine $engine): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**➡️ Return value**

- Type: void



---

[Back to the Index ⤴](README.md)
