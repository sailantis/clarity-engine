# 🧩 Class: TranslationModule

**Full name:** [Clarity\Localization\TranslationModule](../../src/Localization/TranslationModule.php)

Translation module for the Clarity template engine.

Registers a single `t` filter that looks up translation strings from
domain-separated locale files (PHP, JSON, or YAML).

File naming convention
----------------------
`{translations_path}/{domain}.{locale}.{ext}`

Examples:
  - `locales/messages.de_DE.yaml`   ← default domain
  - `locales/common.de_DE.json`
  - `locales/books.en_US.php`

Registration
------------
```php
// Optional: explicit locale service (register first to share with IntlFormatModule)
$engine->use(new LocaleService(['locale' => 'de_DE']));

$engine->use(new TranslationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'translations_path' => __DIR__ . '/locales',
    'default_domain'    => 'messages',   // optional, default: 'messages'
    'cache_path'        => sys_get_temp_dir(), // optional, where JSON/YAML caches go
]));
```

Template usage
--------------
```clarity
{# Simple lookup (default domain = messages) #}
{{ "logout" |> t }}

{# With placeholder variables #}
{{ "greeting" |> t({name: user.name}) }}

{# Specific domain #}
{{ "title" |> t(}, domain:"common") }}
{{ "overview" |> t(domain:"books") }}

{# Locale switch block (requires LocaleService or auto-bootstrapped) #}
{% with_locale user.locale %}
    {{ "welcome" |> t }}
{% endwith_locale %}
```

## 🚀 Public methods

### __construct() · [source](../../src/Localization/TranslationModule.php#L75)

`public function __construct(array $config = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` |  |

**➡️ Return value**

- Type: mixed


---

### register() · [source](../../src/Localization/TranslationModule.php#L101)

`public function register(Clarity\ClarityEngine $engine): void`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**➡️ Return value**

- Type: void


---

### get() · [source](../../src/Localization/TranslationModule.php#L161)

`public function get(string $locale, string $key, array|null $vars = null, string|null $domain = null): string`

Look up a translation key with optional placeholder substitution.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - | Active locale (e.g. 'de_DE'). |
| `$key` | string | - | Translation key. |
| `$vars` | array\|null | `null` | Placeholder values for `{name}` substitution. |
| `$domain` | string\|null | `null` | Override the default domain. |

**➡️ Return value**

- Type: string


---

### pushDomain() · [source](../../src/Localization/TranslationModule.php#L235)

`public function pushDomain(string|null $domain): void`

=========================================================================
Domain stack (for with_t_domain blocks)
=========================================================================

The domain stack allows nested overrides of the current domain, e.g.:

{% with_t_domain "emails" %}
    {{ "welcome_subject" |> t }}

    {% with_t_domain "passwords" %}
        {{ "reset_subject" |> t }}
    {% endwith_t_domain %}

{% endwith_t_domain %}

In this example, the first `t` filter looks up `welcome_subject` in the
`emails` domain, while the second looks up `reset_subject` in the nested
`passwords` domain.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string\|null | - |  |

**➡️ Return value**

- Type: void


---

### popDomain() · [source](../../src/Localization/TranslationModule.php#L244)

`public function popDomain(): void`

Pop the most recently pushed domain off the stack.

**➡️ Return value**

- Type: void



---

[Back to the Index ⤴](README.md)
