# Modules

Modules are self-contained bundles of filters, functions, block directives, and shared services that are registered into the engine with a single call. Clarity ships with built-in localization modules and fully supports custom modules.

## The Module System

### Registering a Module

Pass any `ModuleInterface` implementation to `$engine->use()`:

```php
$engine->use(new MyModule());
$engine->use(new IntlFormatModule(['locale' => 'de_DE']));
```

`use()` returns the engine instance, so calls can be chained:

```php
$engine
    ->use(new LocaleService(['locale' => 'de_DE']))
    ->use(new TranslationModule(['translations_path' => __DIR__ . '/locales']))
    ->use(new IntlFormatModule());
```

### Implementing a Custom Module

Implement the `Clarity\ModuleInterface` interface, which requires a single `register()` method:

```php
use Clarity\ClarityEngine;
use Clarity\ModuleInterface;

class MyModule implements ModuleInterface
{
    public function __construct(private string $apiKey) {}

    public function register(ClarityEngine $engine): void
    {
        // Filters
        $engine->addFilter('shout', fn($v) => strtoupper($v) . '!');

        // Inline filter (zero runtime overhead — compiled directly into template)
        $engine->addInlineFilter('double', [
            'php' => '({1} * 2)',
        ]);

        // Functions
        $engine->addFunction('asset', fn(string $path) => '/assets/' . ltrim($path, '/'));

        // Shared service (accessible from inline filters via $__sv['key'])
        $engine->addService('myapi', new MyApiClient($this->apiKey));

        // Custom block directive
        $engine->addBlock(
            'debug_if',
            function (string $rest, string $path, int $line, callable $expr): string {
                return 'if (' . $expr($rest) . ' && $__debug) {';
            }
        );
        $engine->addBlock('enddebug_if', fn() => '}');
    }
}
```

### What a Module Can Register

| API                                 | Purpose                                                    |
| ----------------------------------- | ---------------------------------------------------------- |
| `addFilter(name, callable)`         | Named filter callable invoked at render time               |
| `addInlineFilter(name, definition)` | Filter expression compiled directly into the template PHP  |
| `addFunction(name, callable)`       | Function callable available in template expressions        |
| `addBlock(keyword, handler)`        | Custom `{% keyword %}` directive processed at compile time |
| `addService(key, object)`           | Shared mutable object accessible inside inline filter PHP  |

---

## Localization Modules

Clarity provides three cooperating localization modules that share a common locale stack so you can switch the active locale at runtime and have all formatting and translation respond immediately.

### Architecture

```
LocaleService          — shared locale stack (push/pop + with_locale block)
    ↑                       ↑
TranslationModule      IntlFormatModule
(t filter, domains)    (number/date/currency filters)
```

Both modules auto-bootstrap `LocaleService` if it hasn't been registered yet. Registering `LocaleService` explicitly first lets the two modules share the same locale stack.

---

## LocaleService

`Clarity\Localization\LocaleService`

Manages a locale stack and installs the `{% with_locale %}` / `{% endwith_locale %}` block directives. It is automatically created by `TranslationModule` or `IntlFormatModule` when not already present.

### Configuration

```php
use Clarity\Localization\LocaleService;

$engine->use(new LocaleService([
    'locale' => 'de_DE',   // default locale; auto-detected from intl/env if omitted
]));
```

| Option   | Type   | Default       | Description                                |
| -------- | ------ | ------------- | ------------------------------------------ |
| `locale` | string | auto-detected | Default locale (e.g. `'de_DE'`, `'en_US'`) |

Locale auto-detection order: PHP `intl` extension → `setlocale(LC_ALL, 0)` → `LC_ALL`/`LANG`/`LANGUAGE` env vars → `'en_US'`.

### Template Usage

```twig
{# Switch locale for a block #}
{% with_locale "fr_FR" %}
    {{ price |> format_currency("EUR") }}
    {{ "greeting" |> t }}
{% endwith_locale %}

{# Dynamic locale from a variable #}
{% with_locale user.locale %}
    {{ date |> format_date("long") }}
{% endwith_locale %}
```

`{% with_locale %}` is safely nestable. Passing `null` or an empty string is a no-op.

---

## TranslationModule

`Clarity\Localization\TranslationModule`

Registers the `t` filter for looking up translation strings from locale files, plus the `{% with_t_domain %}` / `{% endwith_t_domain %}` block for switching the active domain.

### Configuration

```php
use Clarity\Localization\TranslationModule;

$engine->use(new TranslationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'translations_path' => __DIR__ . '/locales',
    'default_domain'    => 'messages',   // optional
    'cache_path'        => sys_get_temp_dir(), // optional, for compiled YAML/JSON caches
]));
```

| Option              | Type   | Default         | Description                                        |
| ------------------- | ------ | --------------- | -------------------------------------------------- |
| `locale`            | string | auto-detected   | Active locale                                      |
| `fallback_locale`   | string | `'en_US'`       | Used when a key is not found in the active locale  |
| `translations_path` | string | `null`          | Directory containing translation files             |
| `default_domain`    | string | `'messages'`    | Domain used when none is specified in the template |
| `cache_path`        | string | system temp dir | Where compiled YAML/JSON caches are stored         |

### File Naming Convention

```
{translations_path}/{domain}.{locale}.{ext}
```

Supported formats (in resolution order):

| Extension        | Notes                                                        |
| ---------------- | ------------------------------------------------------------ |
| `.php`           | Must return a flat `array<string, string>`                   |
| `.json`          | Flat or nested object; keys are flattened with `.` separator |
| `.yaml` / `.yml` | Flat or nested map; keys are flattened with `.` separator    |

**Examples:**

```
locales/
├── messages.de_DE.yaml     ← default domain, German
├── messages.en_US.yaml     ← default domain, English (fallback)
├── common.de_DE.json
└── emails.de_DE.php
```

**YAML example (`messages.de_DE.yaml`):**

```yaml
logout: Abmelden
greeting: "Hallo, {name}!"
nav:
  home: Startseite
  about: Über uns
```

**PHP example (`emails.de_DE.php`):**

```php
<?php
return [
    'welcome_subject' => 'Willkommen bei {appName}!',
    'reset_subject'   => 'Passwort zurücksetzen',
];
```

### The `t` Filter

```twig
{# Simple key lookup (uses default domain) #}
{{ "logout" |> t }}

{# With placeholder variables #}
{{ "greeting" |> t({name: user.name}) }}

{# Nested key (flattened with dot) #}
{{ "nav.home" |> t }}

{# Specify a domain explicitly #}
{{ "welcome_subject" |> t(domain:"emails") }}
{{ "title" |> t({}, domain:"common") }}
```

**Filter signature:** `t(key, vars?, domain?)`

- `vars` — associative array of `{placeholder}` replacements
- `domain` — override the active domain for this call

When a key is not found in the active locale, the fallback locale is tried. If still not found, the key itself is returned.

### Domain Blocks

Use `{% with_t_domain %}` to switch the active domain for a section of the template:

```twig
{% with_t_domain "emails" %}
    <h1>{{ "welcome_subject" |> t }}</h1>
    <p>{{ "welcome_body" |> t({name: user.name}) }}</p>

    {# Nested domain switch #}
    {% with_t_domain "common" %}
        {{ "footer_text" |> t }}
    {% endwith_t_domain %}
{% endwith_t_domain %}
```

### Combining with LocaleService

```php
$engine
    ->use(new LocaleService(['locale' => 'de_DE']))
    ->use(new TranslationModule([
        'translations_path' => __DIR__ . '/locales',
        'fallback_locale'   => 'en_US',
    ]));
```

```twig
{% with_locale user.preferredLocale %}
    {{ "greeting" |> t({name: user.name}) }}
{% endwith_locale %}
```

---

## IntlFormatModule

`Clarity\Localization\IntlFormatModule`

Registers locale-aware number, currency, date, time, and text formatting filters backed by PHP's `intl` extension. Every filter degrades gracefully when `intl` is unavailable, falling back to a PHP-native equivalent or returning the value unchanged.

### Configuration

```php
use Clarity\Localization\IntlFormatModule;

$engine->use(new IntlFormatModule([
    'locale'   => 'de_DE',
    'timezone' => 'Europe/Berlin',
]));
```

| Option     | Type   | Default       | Description                               |
| ---------- | ------ | ------------- | ----------------------------------------- |
| `locale`   | string | auto-detected | Default locale for all formatting filters |
| `timezone` | string | `null`        | Default timezone for date/time formatting |

### Filter Reference

Every filter accepts an optional trailing `$loc` parameter to override the active locale for that single call.

#### Number Filters

| Filter            | Signature                               | Description                           |
| ----------------- | --------------------------------------- | ------------------------------------- |
| `format_number`   | `format_number(decimals:2, loc?)`       | Locale-aware decimal number           |
| `format_currency` | `format_currency(currency:"EUR", loc?)` | Locale-aware currency amount          |
| `currency_name`   | `currency_name(displayLocale?, loc?)`   | `"USD"` → `"US Dollar"`               |
| `currency_symbol` | `currency_symbol(loc?)`                 | `"USD"` → `"$"`                       |
| `percent`         | `percent(decimals:0, loc?)`             | Locale-aware percentage               |
| `scientific`      | `scientific(loc?)`                      | Scientific notation, e.g. `"1.23E4"`  |
| `spellout`        | `spellout(loc?)`                        | Number to words, e.g. `"forty-two"`   |
| `ordinal`         | `ordinal(loc?)`                         | Ordinal suffix, e.g. `"1st"`, `"2nd"` |

```twig
{{ 1234567.89 |> format_number(2) }}
{{ 1234567.89 |> format_number(decimals:0) }}

{{ price |> format_currency("USD") }}
{{ "USD" |> currency_name }}
{{ "USD" |> currency_symbol }}

{{ 0.1234 |> percent }}
{{ 0.1234 |> percent(decimals:1) }}

{{ 42 |> spellout }}
{{ 1 |> ordinal }}
```

#### Date and Time Filters

Styles for `format_date`, `format_time`, and `format_datetime`: `none`, `short`, `medium` (default), `long`, `full`.

Input values can be a Unix timestamp (int), a `DateTimeInterface`, or a date string accepted by `strtotime()`.

| Filter            | Signature                                                            | Description                           |
| ----------------- | -------------------------------------------------------------------- | ------------------------------------- |
| `format_date`     | `format_date(style:"medium", loc?, tz?)`                             | Locale-aware date                     |
| `format_time`     | `format_time(style:"medium", loc?, tz?)`                             | Locale-aware time                     |
| `format_datetime` | `format_datetime(dateStyle:"medium", timeStyle:"medium", loc?, tz?)` | Date + time                           |
| `format_relative` | `format_relative(loc?)`                                              | Relative time, e.g. `"3 minutes ago"` |

```twig
{{ order.created_at |> format_date }}
{{ order.created_at |> format_date("long") }}
{{ order.created_at |> format_date("full", "de_DE", "Europe/Berlin") }}

{{ order.created_at |> format_time("short") }}

{{ order.created_at |> format_datetime("long", "short") }}

{{ comment.created_at |> format_relative }}
```

#### Locale Information Filters

| Filter          | Signature                             | Description                      |
| --------------- | ------------------------------------- | -------------------------------- |
| `country_name`  | `country_name(displayLocale?, loc?)`  | ISO country code → display name  |
| `language_name` | `language_name(displayLocale?, loc?)` | ISO language code → display name |
| `locale_name`   | `locale_name(displayLocale?, loc?)`   | Locale identifier → display name |

```twig
{{ "DE" |> country_name }}            {# "Germany" (in current locale) #}
{{ "DE" |> country_name("de_DE") }}   {# "Deutschland" #}
{{ "de" |> language_name }}           {# "German" #}
{{ "de_DE" |> locale_name }}          {# "German (Germany)" #}
```

#### Text Filters

| Filter          | Signature                                       | Description                      |
| --------------- | ----------------------------------------------- | -------------------------------- |
| `transliterate` | `transliterate(rules:"Any-Latin; Latin-ASCII")` | Transliterate text via ICU rules |

```twig
{{ "Héllo Wörld" |> transliterate }}    {# "Hello World" #}
{{ "Привет" |> transliterate }}         {# "Privet" #}
```

#### ICU MessageFormat

| Filter           | Signature                       | Description                             |
| ---------------- | ------------------------------- | --------------------------------------- |
| `format_message` | `format_message(vars:[], loc?)` | ICU MessageFormat (plurals, selects, …) |

```twig
{{ "{count, plural, one{# item} other{# items}}" |> format_message({count: n}) }}
{{ "{gender, select, male{He} female{She} other{They}} liked your post." |> format_message({gender: user.gender}) }}
```

### Overriding Locale per Call

All filters accept an optional locale string as a trailing argument:

```twig
{{ price |> format_currency("EUR", "de_DE") }}
{{ 0.75 |> percent(0, "fr_FR") }}
{{ date |> format_date("long", "ja_JP", "Asia/Tokyo") }}
```

### Full Setup Example

```php
use Clarity\ClarityEngine;
use Clarity\Localization\LocaleService;
use Clarity\Localization\TranslationModule;
use Clarity\Localization\IntlFormatModule;

$engine = new ClarityEngine();
$engine->setViewPath(__DIR__ . '/templates');
$engine->setCachePath(__DIR__ . '/cache/clarity');

$engine
    ->use(new LocaleService(['locale' => 'de_DE']))
    ->use(new TranslationModule([
        'translations_path' => __DIR__ . '/locales',
        'fallback_locale'   => 'en_US',
    ]))
    ->use(new IntlFormatModule([
        'timezone' => 'Europe/Berlin',
    ]));
```

```twig
{# Combined usage #}
{% with_locale user.locale %}
    <h1>{{ "welcome" |> t({name: user.name}) }}</h1>
    <p>{{ "balance_info" |> t({amount: account.balance |> format_currency("EUR")}) }}</p>
    <p>{{ "last_login" |> t({date: user.lastLogin |> format_relative}) }}</p>
{% endwith_locale %}
```
