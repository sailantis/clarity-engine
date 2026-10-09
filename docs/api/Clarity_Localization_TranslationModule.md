# Class: TranslationModule

**Full name:** [Clarity\Localization\TranslationModule](../../src/Localization/TranslationModule.php)

Translation module for the Clarity template engine.

Registers the `t` filter, the `with_t_domain` / `endwith_t_domain` block
directives, and the `t` service. Translation strings are read from
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
// Optional: register to set an application-wide default locale
$engine->addModule(new LocaleService(['locale' => 'de_DE']));

$engine->addModule(new TranslationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'translations_path' => __DIR__ . '/locales',
    'default_domain'    => 'messages',   // optional, default: 'messages'
    'cache_path'        => sys_get_temp_dir(), // optional, directory for caches of all file formats
    'loader'            => null,  // optional, any TranslationLoaderInterface
]));
```

Template usage
--------------
```twig
{# Simple lookup (default domain = messages) #}
{{ "logout" |> t }}

{# With placeholder variables #}
{{ "greeting" |> t({name: user.name}) }}

{# Specific domain #}
{{ "title" |> t(}, domain:"common") }}
{{ "overview" |> t(domain:"books") }}

{# Locale switch block (LocaleService is bootstrapped automatically) #}
{% with_locale user.locale %}
    {{ "welcome" |> t }}
{% endwith_locale %}
```

## Public methods

### __construct() · <small>[🗎](../../src/Localization/TranslationModule.php#L93)</small>

`public function __construct(array $config = []): mixed`

Create a translation module.

When `loader` is given, `translations_path` and `cache_path` are ignored.
Otherwise a `FileTranslationLoader` reads from `translations_path`, which
must be an existing directory, or the constructor throws.

Without `cache_path`, the loader caches under `sys_get_temp_dir()/clarity_translations`,
in a subdirectory named by the MD5 hash of `translations_path`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` |  |

**Return value**

- Type: `mixed`

**Throws**

- InvalidArgumentException  When `loader` is not a TranslationLoaderInterface or `translations_path` is not a directory.


---

### register() · <small>[🗎](../../src/Localization/TranslationModule.php#L124)</small>

`public function register(Clarity\ClarityEngine $engine): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - |  |

**Return value**

- Type: `void`


---

### getLoader() · <small>[🗎](../../src/Localization/TranslationModule.php#L186)</small>

`public function getLoader(): Clarity\Localization\TranslationLoaderInterface`

Return the loader this module resolves keys with.

Use this to reach the loader when the module was registered without
keeping a reference, for example via `getService('t')`. A decorator such
as `RedisCachingLoader` has an `invalidate()` method that is only
reachable through this accessor or the injected instance:

```php
$loader = $engine->getService('t')->getLoader();
if ($loader instanceof RedisCachingLoader) {
    $loader->invalidate('messages');
}
```

**Return value**

- Type: [TranslationLoaderInterface](Clarity_Localization_TranslationLoaderInterface.md)


---

### get() · <small>[🗎](../../src/Localization/TranslationModule.php#L223)</small>

`public function get(string $key, array|null $vars = null, string|null $domain = null, string|null $locale = null): string`

Look up a translation key with optional placeholder substitution.

The message is taken from the requested locale. If it is missing, the
fallback locale is tried. If it is missing there too, the key itself is
returned.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Translation key. |
| `$vars` | array\|null | `null` | Placeholder values for `{name}` substitution. |
| `$domain` | string\|null | `null` | Override the default domain. |
| `$locale` | string\|null | `null` | Translate into this locale for this call only,<br>overriding both the active `{% with_locale %}`<br>block and the module's own `locale` option. |

**Return value**

- Type: `string`


---

### pushDomain() · <small>[🗎](../../src/Localization/TranslationModule.php#L282)</small>

`public function pushDomain(string|null $domain): void`

Push a domain onto the stack, making it the current domain.

Null or an empty string pushes the current domain, so the block changes
nothing. Every call adds one entry, which keeps it paired with `popDomain()`.

Blocks nest. In this example the first `t` looks up `welcome_subject` in
the `emails` domain, and the second looks up `reset_subject` in the nested
`passwords` domain:

{% with_t_domain "emails" %}
    {{ "welcome_subject" |> t }}

    {% with_t_domain "passwords" %}
        {{ "reset_subject" |> t }}
    {% endwith_t_domain %}

{% endwith_t_domain %}

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string\|null | - |  |

**Return value**

- Type: `void`


---

### popDomain() · <small>[🗎](../../src/Localization/TranslationModule.php#L289)</small>

`public function popDomain(): void`

Pop the most recently pushed domain off the stack.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
