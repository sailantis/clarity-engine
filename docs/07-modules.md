# Modules

Modules bundle filters, functions, directives and shared services for one-call
registration. Clarity includes localization modules and supports custom modules.

## The Module System

### Registering a Module

Pass any `ModuleInterface` implementation to `$engine->addModule()`:

```php
$engine->addModule(new MyModule());
$engine->addModule(new IntlFormatModule(['locale' => 'sv_SE']));
```

`addModule()` returns the engine instance, so calls can be chained:

```php
$engine
    ->addModule(new LocaleService(['locale' => 'nb_NO']))
    ->addModule(new TranslationModule(['translations_path' => __DIR__ . '/locales']))
    ->addModule(new IntlFormatModule());
```

### Implementing a Custom Module

Implement the `Clarity\ModuleInterface` interface, which requires a single `register()` method:

```php
use Clarity\ClarityEngine;
use Clarity\Engine\Directive;
use Clarity\ModuleInterface;

class MyModule implements ModuleInterface
{
    public function __construct(
        private string $apiKey
    ) {}

    public function register(ClarityEngine $engine): void
    {
        $engine->addFilter('shout', fn($v) => strtoupper($v) . '!');

        // Inline filter (zero runtime overhead — compiled directly into template)
        $engine->addInlineFilter('double', [
            'php' => '({1} * 2)',
        ]);

        // Inline FUNCTION: same codegen, but call-only (no `|>` form)
        $engine->addInlineFunction('present', [
            'php'       => 'isset({1})',
            'callGuard' => 'presence',
        ]);

        $engine->addFunction('asset', fn(string $path) => '/assets/' . ltrim($path, '/'));

        // Shared service, read in template and directive PHP as $this->services['myapi']
        $engine->addService('myapi', new MyApiClient($this->apiKey));

        // Custom directive
        $engine->addService('checkDebug', fn(): bool => $engine->isDebugMode());
        $engine->addDirective(
            'debug_if',
            function (string $rest, TemplateLocation $at, callable $processExpr): string {
                return 'if (' . $processExpr($rest) . ' && $this->services["checkDebug"]()) {';
            },
            Directive::opens('debug_endif')   // pair the closer so the compiler checks it
        );
        $engine->addDirective('debug_endif', fn() => '}', Directive::closes('debug_if'));
    }
}
```

### What a Module Can Register

| API                                         | Purpose                                                                                 |
| ------------------------------------------- | --------------------------------------------------------------------------------------- | --- |
| `addFilter(name, callable)`                 | Named filter callable invoked at render time                                            |
| `addInlineFilter(name, definition)`         | Filter expression compiled directly into the template PHP                               |
| `addInlineFunction(name, definition)`       | The same, call-only: callable but refused under `                                       | >`  |
| `addFunction(name, callable)`               | Function callable available in template expressions                                     |
| `addDirective(keyword, handler)`            | Custom `{% keyword %}` directive processed at compile time                              |
| `addDirective(keyword, handler, Directive)` | The same, with a role the compiler validates (a paired construct or containment)        |
| `addService(key, object)`                   | Shared value/object, read in template PHP and directive PHP as `$this->services['key']` |

> **Block directives:** when a directive wraps a body, declare its role on the opener with
> `Directive::opens('endmyblock')` so the compiler rejects an unclosed block, a stray
> closer, or a closer that crosses another construct — with the template line named. A
> member tag may additionally assert its side of that structure with `Directive::closes()`
> or `Directive::branches()`; a leaf that belongs inside a block uses
> `Directive::inside('myblock')`. See _Paired Directives_ in
> [04-advanced-topics.md](04-advanced-topics.md#paired-directives).
>
> **Directive errors:** a handler receives a `TemplateLocation` (the template name, the
> line, and the physical file) and can `throw new ClarityException('…', $at)` to get an
> error that already points at the template — no engine pass fills anything in. See
> _Handler Signature_ in [04-advanced-topics.md](04-advanced-topics.md#handler-signature).
> See [Paired Directives](04-advanced-topics.md#paired-directives).
>
> **Argument lists:** a handler that takes several arguments can call
> `$processExpr($rest, true)` to get `[positional, named]` compiled for it, instead of
> splitting the raw text itself. See
> [Directive Arguments](04-advanced-topics.md#directive-arguments).

---

## Localization Modules

Clarity's localization modules share a locale stack, so formatting and
translations follow locale changes at runtime.

### Architecture

```
LocaleService          — shared locale stack (push/pop + with_locale block)
    ↑                       ↑
TranslationModule      IntlFormatModule
(t filter, domains)    (number/date/currency filters)
```

Both modules register `LocaleService` automatically if it is not already
registered. Registering it explicitly sets an application-wide default locale
for modules without their own `locale` option.

Each module has an independent `locale` option. If unset, the module uses the
`LocaleService` default, then the detected environment locale. Detection checks
PHP `intl`, `setlocale(LC_ALL, 0)`, `LC_ALL`, `LANG`, and `LANGUAGE`, then
defaults to `'en_US'`.

### Locale precedence

The first available source determines the active locale:

| #   | Source                              | Example                                        |
| --- | ----------------------------------- | ---------------------------------------------- |
| 1   | Locale passed to the filter         | `format_currency("EUR", "it_IT")`              |
| 2   | Enclosing `{% with_locale %}` block | `{% with_locale "fr_FR" %}`                    |
| 3   | The module's own `locale` option    | `new TranslationModule(['locale' => 'de_DE'])` |
| 4   | The `LocaleService` default locale  | `new LocaleService(['locale' => 'nb_NO'])`     |
| 5   | Detected environment locale         | —                                              |

Both modules accept a per-call locale argument — `t(locale: …)` and the intl
filters' trailing `locale` — and both honor `{% with_locale %}`. When the block
ends, each resumes its own locale precedence, starting with its configured
`locale` if present.

```php
$engine->addModule(new LocaleService(['locale' => 'nb_NO']));      // row 4
$engine->addModule(new IntlFormatModule(['locale' => 'fr_FR']));   // row 3 → fr_FR wins
$engine->addModule(new TranslationModule(['locale' => 'de_DE']));  // row 3 → de_DE wins
```

Formatting uses French and translations use German. The `nb_NO` default applies
to modules without their own `locale` option.

---

## LocaleService

`Clarity\Localization\LocaleService`

A `ModuleInterface` implementation that puts the locale stack on the engine
under the service key `'locale'` and installs the `{% with_locale %}` /
`{% endwith_locale %}` directives.

### Configuration

```php
use Clarity\Localization\LocaleService;

$engine->addModule(new LocaleService([
    'locale' => 'de_DE',   // optional: application-wide default locale
]));
```

| Option   | Type   | Default | Description                                                                                                                           |
| -------- | ------ | ------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `locale` | string | `null`  | Application-wide default used when no `{% with_locale %}` block or module-specific `locale` applies. It is not pushed onto the stack. |

The service stores this default separately from the locale stack. Stack entries
take precedence over module options, so adding the default to the stack would
override module-specific settings. Registering `LocaleService` after a module
has already registered it does not replace that instance. The new default is
used only if the installed service has none.

### Template Usage

```twig
{# Switch locale for a block #}
{% with_locale "fr_FR" %}
    {{ price |> format_currency("EUR") }}
    {{ "greeting" |> t }}
{% endwith_locale %}

{# Dynamic locale from a variable #}
{% with_locale user:locale %}
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

$engine->addModule(new TranslationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'default_domain'    => 'messages',
    'translations_path' => __DIR__ . '/locales',
    'cache_path'        => sys_get_temp_dir(),
    'loader'            => null,   // optional, any TranslationLoaderInterface
]));
```

| Option              | Type                         | Default         | Description                                                                                                                                                                                              |
| ------------------- | ---------------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `locale`            | string                       | `null`          | Locale to translate into; falls back to the `LocaleService` default, then to the detected environment locale                                                                                             |
| `fallback_locale`   | string                       | `'en_US'`       | Used when a key is not found in the active locale                                                                                                                                                        |
| `translations_path` | string                       | `null`          | Directory containing translation files                                                                                                                                                                   |
| `default_domain`    | string                       | `'messages'`    | Domain used when none is specified in the template                                                                                                                                                       |
| `cache_path`        | string                       | system temp dir | Where compiled YAML/JSON caches are stored                                                                                                                                                               |
| `loader`            | `TranslationLoaderInterface` | `null`          | Loader used to resolve keys. Defaults to a `FileTranslationLoader` over `translations_path` and `cache_path`, which are otherwise not used for loading. See [Translation Loaders](#translation-loaders). |

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
{{ "greeting" |> t({name: user:name}) }}

{# Nested key (flattened with dot) #}
{{ "nav.home" |> t }}

{# Specify a domain explicitly #}
{{ "welcome_subject" |> t(domain:"emails") }}
{{ "title" |> t({}, domain:"common") }}

{# Translate one string into another locale, without a with_locale block #}
{{ "greeting" |> t(locale:"fr_FR") }}
{{ "welcome" |> t({name: user:name}, domain:"emails", locale:"de_DE") }}
```

**Filter signature:** `t(key, vars?, domain?, locale?)`

- `vars` — associative array of `{placeholder}` replacements
- `domain` — override the active domain for this call
- `locale` — translate into this locale for this call, overriding both the
  enclosing `{% with_locale %}` block and the module's `locale` option

When a key is not found in the active locale, the fallback locale is tried. If still not found, the key itself is returned.

### Domain Blocks

Use `{% with_t_domain %}` to switch the active domain for a section of the template:

```twig
{% with_t_domain "emails" %}
    <h1>{{ "welcome_subject" |> t }}</h1>
    <p>{{ "welcome_body" |> t({name: user:name}) }}</p>

    {# Nested domain switch #}
    {% with_t_domain "common" %}
        {{ "footer_text" |> t }}
    {% endwith_t_domain %}
{% endwith_t_domain %}
```

### Combining with LocaleService

```php
$engine
    ->addModule(new LocaleService(['locale' => 'de_DE']))
    ->addModule(new TranslationModule([
        'translations_path' => __DIR__ . '/locales',
        'fallback_locale'   => 'en_US',
    ]));
```

```twig
{% with_locale user:preferredLocale %}
    {{ "greeting" |> t({name: user:name}) }}
{% endwith_locale %}
```

### Translation Loaders

`TranslationModule` delegates loading to a `TranslationLoaderInterface`.
Loaders receive a `domain` and `locale`, similar to how a `TemplateLoader`
resolves a template name. A loader can read translations from any source.

```php
interface TranslationLoaderInterface
{
    /** @return array<string, string> Flat key → message map. */
    public function load(string $domain, string $locale): array;
}
```

Set the `loader` option to use a custom loader instead of the default file
loader:

```php
$engine->addModule(new TranslationModule([
    'locale' => 'de_DE',
    'loader' => $myLoader,   // any TranslationLoaderInterface
]));
```

Clarity includes four translation loaders:

| Loader                     | Purpose                                                                     |
| -------------------------- | --------------------------------------------------------------------------- |
| `FileTranslationLoader`    | Default; reads `{domain}.{locale}.{php,json,yaml}` files and compiles a PHP cache |
| `ArrayTranslationLoader`   | Loads in-memory catalogs from a PHP array                                      |
| `ChainTranslationLoader`   | Combines loaders; later loaders override earlier ones                         |
| `RedisCachingLoader`       | Caches results from another loader in Redis                                    |

#### ArrayTranslationLoader

Store translations in an array instead of files. This can be useful in tests
without a fixture directory, or for a small set of strings defined alongside
the code that uses them:

```php
use Clarity\Localization\ArrayTranslationLoader;

$loader = new ArrayTranslationLoader([
    'messages' => [
        'de_DE' => ['greeting' => 'Hallo', 'nav' => ['home' => 'Startseite']],
        'en_US' => ['greeting' => 'Hello'],
    ],
]);

// Add an individual entry
$loader->set('messages', 'de_DE', 'nav.about', 'Über uns');
```

Nested keys are flattened with dots, so `nav.home` resolves the same way it does
from a file. `set()` treats a dotted key as one message key, not as a path; for
example, setting `nav.home` does not change a separate `nav` entry. An unknown
domain or locale returns an empty array, as it does for a missing file.

Combine the array loader with the file loader to override selected messages
while retaining the other file-based translations:

```php
'loader' => new ChainTranslationLoader(
    new FileTranslationLoader(__DIR__ . '/locales'),
    new ArrayTranslationLoader(['messages' => ['de_DE' => ['greeting' => 'Servus']]]),
),
```

#### ChainTranslationLoader

Combine sources in precedence order, such as a database overriding file-based
translations:

```php
use Clarity\Localization\ChainTranslationLoader;
use Clarity\Localization\FileTranslationLoader;

$engine->addModule(new TranslationModule([
    'loader' => new ChainTranslationLoader(
        new FileTranslationLoader(__DIR__ . '/locales'),   // base
        new DatabaseTranslationLoader($pdo),               // overrides the base
    ),
]));
```

The chain queries loaders in argument order and merges their results. If more
than one loader defines a key, the last definition wins. The chain queries
every loader rather than stopping at the first match.

#### RedisCachingLoader

Wrap a loader to cache its results in Redis. A cache hit skips the wrapped
loader; a miss queries it and stores the result.

```php
use Clarity\Localization\RedisCachingLoader;

$inner  = new FileTranslationLoader(__DIR__ . '/locales');
$loader = new RedisCachingLoader($inner, $redis, /* ttl */ 3600);
```

Keys use `translations:{domain}:{locale}` and are stored with `SETEX` for the
configured TTL. Values are PHP-serialized. Invalidate entries explicitly:

```php
$loader->invalidate('messages', 'de_DE');  // one key
$loader->invalidate('messages');           // every locale of one domain
$loader->invalidate();                     // everything under translations:*
```

Requirements and invalidation:

- Requires the **`ext-redis`** extension and a `\Redis` instance as a
  constructor argument. The extension is optional, as is `ext-intl` for
  `IntlFormatModule`.
- Call `invalidate()` explicitly when the underlying data changes. You can
  access the loader through the module:

  ```php
  $loader = $engine->getService('t')->getLoader();

  if ($loader instanceof RedisCachingLoader) {
      $loader->invalidate('messages');
  }
  ```

  `getLoader()` returns the configured loader. Use `instanceof` to check its
  type when it is configured elsewhere, such as through the environment.

#### Caching, and its cost

A loader runs for every `t` lookup; `TranslationModule` does not cache
catalogs. The per-lookup cost depends on the loader:

| Setup                             | Per `t` lookup                         |
| --------------------------------- | -------------------------------------- |
| `FileTranslationLoader` (default) | one `require` of a compiled cache file |
| `RedisCachingLoader(…, $redis)`   | one Redis `GET`                        |

The default file loader does not require an additional cache. Use
`RedisCachingLoader` if a custom loader is slow. Redis caching applies across
requests; per-request memoization is not provided.

#### Writing a Loader

Implement the interface and return a flat map of keys to messages. Handling
nested data is the loader's responsibility. As `FileTranslationLoader` does,
flatten nested keys to dotted keys such as `nav.home` so that
`{{ "nav.home" |> t }}` resolves.

```php
use Clarity\Localization\TranslationLoaderInterface;

final class DatabaseTranslationLoader implements TranslationLoaderInterface
{
    public function __construct(private \PDO $pdo) {}

    public function load(string $domain, string $locale): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT key, message FROM translations WHERE domain = ? AND locale = ?'
        );
        $stmt->execute([$domain, $locale]);

        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
```

Return an empty array when there are no translations; do not return `null`.
The module then checks `fallback_locale` and returns the key if no translation
is found there.

---

## IntlFormatModule

`Clarity\Localization\IntlFormatModule`

Registers locale-aware number, currency, date, time, and text formatting filters backed by PHP's `intl` extension. Every filter degrades gracefully when `intl` is unavailable, falling back to a PHP-native equivalent or returning the value unchanged.

### Configuration

```php
use Clarity\Localization\IntlFormatModule;

$engine->addModule(new IntlFormatModule([
    'locale'   => 'de_DE',
    'timezone' => 'Europe/Berlin',
]));
```

| Option     | Type   | Default | Description                                                                                                                   |
| ---------- | ------ | ------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `locale`   | string | `null`  | Default locale for all formatting filters; falls back to the `LocaleService` default, then to the detected environment locale |
| `timezone` | string | `null`  | Default timezone for date/time formatting                                                                                     |

### Filter Reference

Every filter accepts an optional trailing `locale` parameter to override the active locale for that single call.

#### Number Filters

| Filter            | Signature                                  | Description                           |
| ----------------- | ------------------------------------------ | ------------------------------------- |
| `format_number`   | `format_number(decimals:2, locale?)`       | Locale-aware decimal number           |
| `format_currency` | `format_currency(currency:"EUR", locale?)` | Locale-aware currency amount          |
| `currency_name`   | `currency_name(displayLocale?, locale?)`   | `"USD"` → `"US Dollar"`               |
| `currency_symbol` | `currency_symbol(locale?)`                 | `"USD"` → `"$"`                       |
| `percent`         | `percent(decimals:0, locale?)`             | Locale-aware percentage               |
| `scientific`      | `scientific(locale?)`                      | Scientific notation, e.g. `"1.23E4"`  |
| `spellout`        | `spellout(locale?)`                        | Number to words, e.g. `"forty-two"`   |
| `ordinal`         | `ordinal(locale?)`                         | Ordinal suffix, e.g. `"1st"`, `"2nd"` |

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

| Filter            | Signature                                                               | Description                           |
| ----------------- | ----------------------------------------------------------------------- | ------------------------------------- |
| `format_date`     | `format_date(style:"medium", locale?, tz?)`                             | Locale-aware date                     |
| `format_time`     | `format_time(style:"medium", locale?, tz?)`                             | Locale-aware time                     |
| `format_datetime` | `format_datetime(dateStyle:"medium", timeStyle:"medium", locale?, tz?)` | Date + time                           |
| `format_relative` | `format_relative(locale?)`                                              | Relative time, e.g. `"3 minutes ago"` |

```twig
{{ order:created_at |> format_date }}
{{ order:created_at |> format_date("long") }}
{{ order:created_at |> format_date("full", "de_DE", "Europe/Berlin") }}

{{ order:created_at |> format_time("short") }}

{{ order:created_at |> format_datetime("long", "short") }}

{{ comment:created_at |> format_relative }}
```

#### Locale Information Filters

| Filter          | Signature                                | Description                      |
| --------------- | ---------------------------------------- | -------------------------------- |
| `country_name`  | `country_name(displayLocale?, locale?)`  | ISO country code → display name  |
| `language_name` | `language_name(displayLocale?, locale?)` | ISO language code → display name |
| `locale_name`   | `locale_name(displayLocale?, locale?)`   | Locale identifier → display name |

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

| Filter           | Signature                          | Description                             |
| ---------------- | ---------------------------------- | --------------------------------------- |
| `format_message` | `format_message(vars:[], locale?)` | ICU MessageFormat (plurals, selects, …) |

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
    ->addModule(new LocaleService(['locale' => 'de_DE']))
    ->addModule(new TranslationModule([
        'translations_path' => __DIR__ . '/locales',
        'fallback_locale'   => 'en_US',
    ]))
    ->addModule(new IntlFormatModule([
        'timezone' => 'Europe/Berlin',
    ]));
```

```twig
{# Combined usage #}
{% with_locale user:locale %}
    <h1>{{ "welcome" |> t({name: user:name}) }}</h1>
    <p>{{ "balance_info" |> t({amount: account:balance |> format_currency("EUR")}) }}</p>
    <p>{{ "last_login" |> t({date: user:lastLogin |> format_relative}) }}</p>
{% endwith_locale %}
```
