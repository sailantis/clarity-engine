# Class: IntlFormatter

**Full name:** [Clarity\Localization\IntlFormatter](../../src/Localization/IntlFormatter.php)

Engine-independent locale-aware formatting.

Numbers, currency, dates, relative time, ICU messages and locale names — the
logic behind [`IntlFormatModule`](Clarity_Localization_IntlFormatModule.md)'s filters, available independently of a
template engine. Use this class directly for formatting in other contexts,
such as mailers, CLI reports, or validators that render numbers into a
message. [`IntlFormatModule`](Clarity_Localization_IntlFormatModule.md) exposes the same methods as `format_*` filters.

The locale is resolved for every call with the documented precedence, so
`{% with_locale %}` blocks and direct calls use the same active locale:

1. the locale passed to the call,
2. the enclosing `{% with_locale %}` block (the [`LocaleService`](Clarity_Localization_LocaleService.md) stack),
3. this object's own `locale` option,
4. the [`LocaleService`](Clarity_Localization_LocaleService.md) application-wide default,
5. the detected environment locale.

Each method supports native formatting when the `intl` extension is
unavailable. These paths use `number_format()` for numbers,
`DateTime::format()` for dates, English phrasing for relative time, and
`iconv` for transliteration. `hasIntl()` reports whether ICU formatting
is active.

```php
$intl = new IntlFormatter(localeService: $localeService);

$intl->formatCurrency(1234.5, 'EUR', 'de_DE');   // "1.234,50 €"
$intl->formatDate($order->createdAt, 'long', 'de_DE');
$intl->formatMessage('{count, plural, one{# item} other{# items}}', ['count' => 3]);
```

## Public methods

### __construct() · <small>[🗎](../../src/Localization/IntlFormatter.php#L77)</small>

`public function __construct(string|null $locale = null, string|null $timezone = null, Clarity\Localization\LocaleService|null $localeService = null, bool|null $useIntl = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | `null` | Locale for formatting. Falls back to the<br>LocaleService default, then the detected<br>environment locale. |
| `$timezone` | string\|null | `null` | Default timezone (`UTC`, `Europe/Berlin`).<br>Falls back to `date_default_timezone_get()`. |
| `$localeService` | [LocaleService](Clarity_Localization_LocaleService.md)\|null | `null` | Shared locale stack; see the class docblock<br>for how its current locale and default are used. |
| `$useIntl` | bool\|null | `null` | Select whether to use ICU formatting.<br>`null` (the default) follows<br>`extension_loaded('intl')`; an explicit<br>value lets tests exercise native formatting<br>paths on a machine with the extension. |

**Return value**

- Type: `mixed`


---

### useLocaleService() · <small>[🗎](../../src/Localization/IntlFormatter.php#L92)</small>

`public function useLocaleService(Clarity\Localization\LocaleService $service): static`

Adopt a locale service, for example the engine's or a framework's shared one.

Sharing one `LocaleService` between formatting and translation is what makes
`{% with_locale %}` change both at once.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$service` | [LocaleService](Clarity_Localization_LocaleService.md) | - |  |

**Return value**

- Type: `static`


---

### localeService() · <small>[🗎](../../src/Localization/IntlFormatter.php#L99)</small>

`public function localeService(): Clarity\Localization\LocaleService|null`

Return the shared locale service, if one is installed.

**Return value**

- Type: [LocaleService](Clarity_Localization_LocaleService.md)|`null`


---

### setLocale() · <small>[🗎](../../src/Localization/IntlFormatter.php#L105)</small>

`public function setLocale(string|null $locale): static`

Set the locale used below an active scope.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `static`


---

### setTimezone() · <small>[🗎](../../src/Localization/IntlFormatter.php#L112)</small>

`public function setTimezone(string|null $timezone): static`

Set the default timezone for date and time formatting.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$timezone` | string\|null | - |  |

**Return value**

- Type: `static`


---

### hasIntl() · <small>[🗎](../../src/Localization/IntlFormatter.php#L125)</small>

`public function hasIntl(): bool`

Whether ICU formatting is enabled for this instance.

Follows the `intl` extension unless the constructor's `$useIntl` argument
selects a different path. This allows native formatting paths to be tested
on a machine with the extension installed.

**Return value**

- Type: `bool`


---

### locale() · <small>[🗎](../../src/Localization/IntlFormatter.php#L133)</small>

`public function locale(string|null $locale = null): string`

Resolve the locale for a call, following the documented precedence.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatNumber() · <small>[🗎](../../src/Localization/IntlFormatter.php#L147)</small>

`public function formatNumber(mixed $value, int $decimals = 2, string|null $locale = null): string`

Locale-aware decimal number, e.g. `1.234,50` in `de_DE`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$decimals` | int | `2` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatCurrency() · <small>[🗎](../../src/Localization/IntlFormatter.php#L163)</small>

`public function formatCurrency(mixed $value, string $currency = 'EUR', string|null $locale = null): string`

Locale-aware currency amount, e.g. `1.234,50 €` in `de_DE`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$currency` | string | `'EUR'` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### currencyName() · <small>[🗎](../../src/Localization/IntlFormatter.php#L178)</small>

`public function currencyName(string $code, string|null $locale = null): string`

Locale-aware currency display name for an ISO code, e.g. "US-Dollar".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### currencySymbol() · <small>[🗎](../../src/Localization/IntlFormatter.php#L184)</small>

`public function currencySymbol(string $code, string|null $locale = null): string`

Locale-aware currency symbol for an ISO code, e.g. "€", "$".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### percent() · <small>[🗎](../../src/Localization/IntlFormatter.php#L190)</small>

`public function percent(mixed $value, int $decimals = 0, string|null $locale = null): string`

Locale-aware percentage, e.g. `25 %`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$decimals` | int | `0` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### scientific() · <small>[🗎](../../src/Localization/IntlFormatter.php#L206)</small>

`public function scientific(mixed $value, string|null $locale = null): string`

Scientific notation, e.g. `1,23E4` in `de_DE`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### spellout() · <small>[🗎](../../src/Localization/IntlFormatter.php#L220)</small>

`public function spellout(mixed $value, string|null $locale = null): string`

Number in words, e.g. "forty-two". Native formatting returns the input as text.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### ordinal() · <small>[🗎](../../src/Localization/IntlFormatter.php#L234)</small>

`public function ordinal(mixed $value, string|null $locale = null): string`

Ordinal number, e.g. "1st". Native formatting uses English ordinal suffixes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatDate() · <small>[🗎](../../src/Localization/IntlFormatter.php#L263)</small>

`public function formatDate(mixed $value, string $style = 'medium', string|null $locale = null, string|null $timezone = null): string`

Locale-aware date. Style: `none`, `short`, `medium`, `long`, `full`, or an ICU pattern.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$style` | string | `'medium'` |  |
| `$locale` | string\|null | `null` |  |
| `$timezone` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatTime() · <small>[🗎](../../src/Localization/IntlFormatter.php#L277)</small>

`public function formatTime(mixed $value, string $style = 'medium', string|null $locale = null, string|null $timezone = null): string`

Locale-aware time. Same style vocabulary as `formatDate()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$style` | string | `'medium'` |  |
| `$locale` | string\|null | `null` |  |
| `$timezone` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatDateTime() · <small>[🗎](../../src/Localization/IntlFormatter.php#L291)</small>

`public function formatDateTime(mixed $value, string $dateStyle = 'medium', string $timeStyle = 'medium', string|null $locale = null, string|null $timezone = null): string`

Locale-aware date and time.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$dateStyle` | string | `'medium'` |  |
| `$timeStyle` | string | `'medium'` |  |
| `$locale` | string\|null | `null` |  |
| `$timezone` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatRelative() · <small>[🗎](../../src/Localization/IntlFormatter.php#L306)</small>

`public function formatRelative(mixed $value, string|null $locale = null): string`

Relative time, e.g. "3 minutes ago" / "in 2 days".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### transliterate() · <small>[🗎](../../src/Localization/IntlFormatter.php#L343)</small>

`public function transliterate(mixed $value, string $rules = 'Any-Latin; Latin-ASCII'): string`

Transliterate text, e.g. "Hëllo" -> "Hello".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$rules` | string | `'Any-Latin; Latin-ASCII'` |  |

**Return value**

- Type: `string`


---

### formatMessage() · <small>[🗎](../../src/Localization/IntlFormatter.php#L371)</small>

`public function formatMessage(string $pattern, array|null $vars = null, string|null $locale = null): string`

Format an ICU MessageFormat pattern.

When ICU formatting is unavailable or a pattern uses syntax unsupported by
`MessageFormatter`, simple `{placeholder}` replacement handles variables
and a built-in parser handles plural blocks.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pattern` | string | - |  |
| `$vars` | array\|null | `null` | Placeholder values. |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### countryName() · <small>[🗎](../../src/Localization/IntlFormatter.php#L396)</small>

`public function countryName(string $code, string|null $displayLocale = null, string|null $locale = null): string`

ISO country code to display name, e.g. `DE` -> "Germany".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### languageName() · <small>[🗎](../../src/Localization/IntlFormatter.php#L408)</small>

`public function languageName(string $code, string|null $displayLocale = null, string|null $locale = null): string`

Language code to display name, e.g. `de` -> "German".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### localeName() · <small>[🗎](../../src/Localization/IntlFormatter.php#L420)</small>

`public function localeName(string $id, string|null $displayLocale = null, string|null $locale = null): string`

Locale identifier to display name, e.g. `de_DE` -> "German (Germany)".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### timezoneName() · <small>[🗎](../../src/Localization/IntlFormatter.php#L432)</small>

`public function timezoneName(string $timezone, string|null $displayLocale = null): string`

Timezone identifier to display name, e.g. `Europe/Berlin` -> "Central European Time".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$timezone` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
