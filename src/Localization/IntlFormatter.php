<?php

namespace Clarity\Localization;

/**
 * Engine-independent locale-aware formatting.
 *
 * Numbers, currency, dates, relative time, ICU messages and locale names — the
 * logic behind {@see IntlFormatModule}'s filters, available independently of a
 * template engine. Use this class directly for formatting in other contexts,
 * such as mailers, CLI reports, or validators that render numbers into a
 * message. {@see IntlFormatModule} exposes the same methods as `format_*` filters.
 *
 * The locale is resolved for every call with the documented precedence, so
 * `{% with_locale %}` blocks and direct calls use the same active locale:
 *
 * 1. the locale passed to the call,
 * 2. the enclosing `{% with_locale %}` block (the {@see LocaleService} stack),
 * 3. this object's own `locale` option,
 * 4. the {@see LocaleService} application-wide default,
 * 5. the detected environment locale.
 *
 * Each method supports native formatting when the `intl` extension is
 * unavailable. These paths use `number_format()` for numbers,
 * `DateTime::format()` for dates, English phrasing for relative time, and
 * `iconv` for transliteration. {@see hasIntl()} reports whether ICU formatting
 * is active.
 *
 * ```php
 * $intl = new IntlFormatter(localeService: $localeService);
 *
 * $intl->formatCurrency(1234.5, 'EUR', 'de_DE');   // "1.234,50 €"
 * $intl->formatDate($order->createdAt, 'long', 'de_DE');
 * $intl->formatMessage('{count, plural, one{# item} other{# items}}', ['count' => 3]);
 * ```
 */
class IntlFormatter
{
    /** Memoized NumberFormatter instances, keyed by locale + kind + options. */
    private array $numberFormatters = [];

    /** Memoized IntlDateFormatter instances. */
    private array $dateFormatters = [];

    /** Memoized RelativeDateTimeFormatter instances. */
    private array $relativeFormatters = [];

    /** Memoized MessageFormatter instances, keyed by locale + pattern. */
    private array $messageFormatters = [];

    /**
     * Memoized ICU currency `[symbol, name]` pairs, keyed by locale + code.
     *
     * @var array<string, array{0?: string, 1?: string}>
     */
    private array $currencyEntries = [];

    /** Parsed ICU plural blocks, keyed by the rules text. */
    private array $pluralRules = [];

    private bool $intlAvailable;

    /**
     * @param string|null        $locale        Locale for formatting. Falls back to the
     *                                          LocaleService default, then the detected
     *                                          environment locale.
     * @param string|null        $timezone      Default timezone (`UTC`, `Europe/Berlin`).
     *                                          Falls back to `date_default_timezone_get()`.
     * @param LocaleService|null $localeService Shared locale stack; see the class docblock
     *                                          for how its current locale and default are used.
     * @param bool|null          $useIntl       Select whether to use ICU formatting.
     *                                          `null` (the default) follows
     *                                          `extension_loaded('intl')`; an explicit
     *                                          value lets tests exercise native formatting
     *                                          paths on a machine with the extension.
     */
    public function __construct(
        private ?string $locale = null,
        private ?string $timezone = null,
        private ?LocaleService $localeService = null,
        ?bool $useIntl = null,
    ) {
        $this->intlAvailable = $useIntl ?? \extension_loaded('intl');
    }

    /**
     * Adopt a locale service, for example the engine's or a framework's shared one.
     *
     * Sharing one `LocaleService` between formatting and translation is what makes
     * `{% with_locale %}` change both at once.
     */
    public function useLocaleService(LocaleService $service): static
    {
        $this->localeService = $service;
        return $this;
    }

    /** Return the shared locale service, if one is installed. */
    public function localeService(): ?LocaleService
    {
        return $this->localeService;
    }

    /** Set the locale used below an active scope. */
    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    /** Set the default timezone for date and time formatting. */
    public function setTimezone(?string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    /**
     * Whether ICU formatting is enabled for this instance.
     *
     * Follows the `intl` extension unless the constructor's `$useIntl` argument
     * selects a different path. This allows native formatting paths to be tested
     * on a machine with the extension installed.
     */
    public function hasIntl(): bool
    {
        return $this->intlAvailable;
    }

    /**
     * Resolve the locale for a call, following the documented precedence.
     */
    public function locale(?string $locale = null): string
    {
        return $locale
            ?? $this->localeService?->current()
            ?? $this->locale
            ?? $this->localeService?->defaultLocale()
            ?? LocaleService::detectLocale();
    }

    // =========================================================================
    // Numbers
    // =========================================================================

    /** Locale-aware decimal number, e.g. `1.234,50` in `de_DE`. */
    public function formatNumber(mixed $value, int $decimals = 2, ?string $locale = null): string
    {
        $l = $this->locale($locale);

        if ($this->intlAvailable) {
            $fmt = $this->numberFormatter($l, \NumberFormatter::DECIMAL);
            $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $result = $fmt->format((float) $value);

            return $result !== false ? $result : '';
        }

        return \number_format((float) $value, $decimals);
    }

    /** Locale-aware currency amount, e.g. `1.234,50 €` in `de_DE`. */
    public function formatCurrency(mixed $value, string $currency = 'EUR', ?string $locale = null): string
    {
        $l = $this->locale($locale);

        if ($this->intlAvailable) {
            $fmt    = $this->numberFormatter($l, \NumberFormatter::CURRENCY);
            $result = $fmt->formatCurrency((float) $value, $currency);

            return $result !== false ? $result : '';
        }

        return $currency . ' ' . \number_format((float) $value, 2);
    }

    /** Locale-aware currency display name for an ISO code, e.g. "US-Dollar". */
    public function currencyName(string $code, ?string $locale = null): string
    {
        return $this->currencyEntry($this->locale($locale), $code)[1] ?? $code;
    }

    /** Locale-aware currency symbol for an ISO code, e.g. "€", "$". */
    public function currencySymbol(string $code, ?string $locale = null): string
    {
        return $this->currencyEntry($this->locale($locale), $code)[0] ?? $code;
    }

    /** Locale-aware percentage, e.g. `25 %`. */
    public function percent(mixed $value, int $decimals = 0, ?string $locale = null): string
    {
        $l = $this->locale($locale);

        if ($this->intlAvailable) {
            $fmt = $this->numberFormatter($l, \NumberFormatter::PERCENT);
            $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $result = $fmt->format((float) $value);

            return $result !== false ? $result : '';
        }

        return \round((float) $value * 100, $decimals) . '%';
    }

    /** Scientific notation, e.g. `1,23E4` in `de_DE`. */
    public function scientific(mixed $value, ?string $locale = null): string
    {
        $l = $this->locale($locale);

        if ($this->intlAvailable) {
            $result = $this->numberFormatter($l, \NumberFormatter::SCIENTIFIC)->format((float) $value);

            return $result !== false ? $result : '';
        }

        return \sprintf('%E', (float) $value);
    }

    /** Number in words, e.g. "forty-two". Native formatting returns the input as text. */
    public function spellout(mixed $value, ?string $locale = null): string
    {
        $l = $this->locale($locale);

        if ($this->intlAvailable) {
            $result = $this->numberFormatter($l, \NumberFormatter::SPELLOUT)->format((float) $value);

            return $result !== false ? $result : '';
        }

        return (string) $value;
    }

    /** Ordinal number, e.g. "1st". Native formatting uses English ordinal suffixes. */
    public function ordinal(mixed $value, ?string $locale = null): string
    {
        $l = $this->locale($locale);

        if ($this->intlAvailable) {
            $result = $this->numberFormatter($l, \NumberFormatter::ORDINAL)->format((int) $value);

            return $result !== false ? $result : '';
        }

        $n   = (int) $value % 100;
        $m   = $n % 10;
        $sfx = ($n >= 11 && $n <= 13)
            ? 'th'
            : match ($m) {
                1       => 'st',
                2       => 'nd',
                3       => 'rd',
                default => 'th'
            };

        return $value . $sfx;
    }

    // =========================================================================
    // Dates and time
    // =========================================================================

    /** Locale-aware date. Style: `none`, `short`, `medium`, `long`, `full`, or an ICU pattern. */
    public function formatDate(
        mixed $value,
        string $style = 'medium',
        ?string $locale = null,
        ?string $timezone = null,
    ): string {
        $l = $this->locale($locale);

        return $this->intlAvailable
            ? $this->intlDate($value, $style, 'none', $l, $timezone ?? $this->timezone)
            : $this->nativeDate($value, $style, false, true, $timezone ?? $this->timezone);
    }

    /** Locale-aware time. Same style vocabulary as {@see formatDate()}. */
    public function formatTime(
        mixed $value,
        string $style = 'medium',
        ?string $locale = null,
        ?string $timezone = null,
    ): string {
        $l = $this->locale($locale);

        return $this->intlAvailable
            ? $this->intlDate($value, 'none', $style, $l, $timezone ?? $this->timezone)
            : $this->nativeDate($value, $style, true, false, $timezone ?? $this->timezone);
    }

    /** Locale-aware date and time. */
    public function formatDateTime(
        mixed $value,
        string $dateStyle = 'medium',
        string $timeStyle = 'medium',
        ?string $locale = null,
        ?string $timezone = null,
    ): string {
        $l = $this->locale($locale);

        return $this->intlAvailable
            ? $this->intlDate($value, $dateStyle, $timeStyle, $l, $timezone ?? $this->timezone)
            : $this->nativeDate($value, $dateStyle, true, true, $timezone ?? $this->timezone);
    }

    /** Relative time, e.g. "3 minutes ago" / "in 2 days". */
    public function formatRelative(mixed $value, ?string $locale = null): string
    {
        $l = $this->locale($locale);

        if ($this->intlAvailable && \class_exists(\RelativeDateTimeFormatter::class)) {
            $ts   = $this->toTimestamp($value);
            $diff = \time() - $ts;
            $abs  = \abs($diff);
            $fmt  = $this->relativeFormatters[$l] ??= new \RelativeDateTimeFormatter($l);

            [$amount, $unit] = match (true) {
                $abs < 60 => [(float) $abs, \RelativeDateTimeFormatter::UNIT_SECOND],
                $abs < 3600 => [\round($abs / 60), \RelativeDateTimeFormatter::UNIT_MINUTE],
                $abs < 86400 => [\round($abs / 3600), \RelativeDateTimeFormatter::UNIT_HOUR],
                $abs < 604800 => [\round($abs / 86400), \RelativeDateTimeFormatter::UNIT_DAY],
                $abs < 2592000 => [\round($abs / 604800), \RelativeDateTimeFormatter::UNIT_WEEK],
                $abs < 31536000 => [\round($abs / 2592000), \RelativeDateTimeFormatter::UNIT_MONTH],
                default => [\round($abs / 31536000), \RelativeDateTimeFormatter::UNIT_YEAR]
            };

            $direction = $diff >= 0
                ? \RelativeDateTimeFormatter::DIRECTION_LAST
                : \RelativeDateTimeFormatter::DIRECTION_NEXT;

            $result = $fmt->formatNumeric($amount, $direction, $unit);

            return $result !== false ? $result : '';
        }

        return $this->relativeTimeFallback($this->toTimestamp($value));
    }

    // =========================================================================
    // Text
    // =========================================================================

    /** Transliterate text, e.g. "Hëllo" -> "Hello". */
    public function transliterate(mixed $value, string $rules = 'Any-Latin; Latin-ASCII'): string
    {
        $string = (string) $value;

        if ($this->intlAvailable) {
            static $cache = [];
            $transliterator = $cache[$rules] ??= \Transliterator::create($rules);
            $result         = $transliterator?->transliterate($string);

            return $result ?: $string;
        }

        if (\function_exists('iconv')) {
            return \iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string) ?: $string;
        }

        return $string;
    }

    /**
     * Format an ICU MessageFormat pattern.
     *
     * When ICU formatting is unavailable or a pattern uses syntax unsupported by
     * `MessageFormatter`, simple `{placeholder}` replacement handles variables
     * and a built-in parser handles plural blocks.
     *
     * @param array<string, mixed>|null $vars Placeholder values.
     */
    public function formatMessage(string $pattern, ?array $vars = null, ?string $locale = null): string
    {
        $l    = $this->locale($locale);
        $vars ??= [];

        if ($this->intlAvailable) {
            $key = $l . '|' . $pattern;
            $fmt = $this->messageFormatters[$key] ??= @new \MessageFormatter($l, $pattern);

            if ($fmt !== false) {
                $result = $fmt->format($vars);
                if ($result !== false) {
                    return $result;
                }
            }
        }

        return $this->fallbackMessage($pattern, $vars);
    }

    // =========================================================================
    // Locale information
    // =========================================================================

    /** ISO country code to display name, e.g. `DE` -> "Germany". */
    public function countryName(string $code, ?string $displayLocale = null, ?string $locale = null): string
    {
        if (!$this->intlAvailable) {
            return $code;
        }

        $name = \Locale::getDisplayRegion('und-' . $code, $displayLocale ?? $this->locale($locale));

        return $name ?: $code;
    }

    /** Language code to display name, e.g. `de` -> "German". */
    public function languageName(string $code, ?string $displayLocale = null, ?string $locale = null): string
    {
        if (!$this->intlAvailable) {
            return $code;
        }

        $name = \Locale::getDisplayLanguage($code, $displayLocale ?? $this->locale($locale));

        return $name ?: $code;
    }

    /** Locale identifier to display name, e.g. `de_DE` -> "German (Germany)". */
    public function localeName(string $id, ?string $displayLocale = null, ?string $locale = null): string
    {
        if (!$this->intlAvailable) {
            return $id;
        }

        $name = \Locale::getDisplayName($id, $displayLocale ?? $this->locale($locale));

        return $name ?: $id;
    }

    /** Timezone identifier to display name, e.g. `Europe/Berlin` -> "Central European Time". */
    public function timezoneName(string $timezone, ?string $displayLocale = null): string
    {
        if (!$this->intlAvailable) {
            return $timezone;
        }

        $formatter = new \IntlDateFormatter(
            $this->locale($displayLocale),
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $timezone,
            \IntlDateFormatter::GREGORIAN,
            'VVVV'
        );

        return $formatter->format(0);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function numberFormatter(string $locale, int $style): \NumberFormatter
    {
        return $this->numberFormatters[$locale . '|' . $style] ??= new \NumberFormatter($locale, $style);
    }

    /**
     * Map a style name to an IntlDateFormatter constant.
     *
     * Style constants are resolved on demand, allowing this class to load in
     * environments where the `intl` extension is not installed.
     */
    private function dateStyleType(string $style): int|string
    {
        if (!\defined('IntlDateFormatter::NONE')) {
            return $style;
        }

        return match ($style) {
            'none'   => \IntlDateFormatter::NONE,
            'short'  => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long'   => \IntlDateFormatter::LONG,
            'full'   => \IntlDateFormatter::FULL,
            default  => $style,
        };
    }

    private function intlDate(mixed $value, string $dateStyle, string $timeStyle, string $locale, ?string $timezone): string
    {
        $dateType = $this->dateStyleType($dateStyle);
        $timeType = $this->dateStyleType($timeStyle);

        $dt = new \DateTime('@' . $this->toTimestamp($value));
        $dt->setTimezone(new \DateTimeZone($timezone ?? \date_default_timezone_get()));
        $tz = $dt->getTimezone()->getName();

        $key = $locale . '|' . $dateType . '|' . $timeType . '|' . $tz;
        $fmt = $this->dateFormatters[$key] ??= new \IntlDateFormatter(
            $locale,
            \is_int($dateType) ? $dateType : \IntlDateFormatter::MEDIUM,
            \is_int($timeType) ? $timeType : \IntlDateFormatter::MEDIUM,
            $tz,
        );

        $result = $fmt->format($dt);

        return $result !== false ? $result : '';
    }

    private function nativeDate(mixed $value, string $style, bool $includeTime, bool $includeDate, ?string $timezone): string
    {
        $dt = new \DateTime('@' . $this->toTimestamp($value));
        $dt->setTimezone(new \DateTimeZone($timezone ?? \date_default_timezone_get()));

        $format = match ($style) {
            'short' => $includeDate && $includeTime ? 'n/j/y H:i' : ($includeDate ? 'n/j/y' : 'H:i'),
            'long'  => $includeDate && $includeTime ? 'F j, Y H:i:s' : ($includeDate ? 'F j, Y' : 'g:i:s A'),
            'full'  => $includeDate && $includeTime ? 'l, F j, Y H:i:s T' : ($includeDate ? 'l, F j, Y' : 'g:i:s A T'),
            default => $includeDate && $includeTime ? 'M j, Y H:i' : ($includeDate ? 'M j, Y' : 'H:i')
        };

        return $dt->format($format);
    }

    private function relativeTimeFallback(int $timestamp): string
    {
        $diff = \time() - $timestamp;
        $abs  = \abs($diff);
        $past = $diff >= 0;

        [$amount, $unit] = match (true) {
            $abs < 60 => [$abs, 'second'],
            $abs < 3600 => [\round($abs / 60), 'minute'],
            $abs < 86400 => [\round($abs / 3600), 'hour'],
            $abs < 604800 => [\round($abs / 86400), 'day'],
            $abs < 2592000 => [\round($abs / 604800), 'week'],
            $abs < 31536000 => [\round($abs / 2592000), 'month'],
            default => [\round($abs / 31536000), 'year']
        };

        $unit .= $amount !== 1.0 ? 's' : '';

        return $past ? "{$amount} {$unit} ago" : "in {$amount} {$unit}";
    }

    /**
     * Handle ICU-style plural blocks and `{placeholders}` through native formatting.
     *
     * @param array<string, mixed> $vars
     */
    private function fallbackMessage(string $pattern, array $vars): string
    {
        $result = \preg_replace_callback(
            '/\{(\w+)\s*,\s*plural\s*,\s*((?:[^{}]|\{[^{}]*\})*)\}/s',
            function (array $match) use ($vars): string {
                $count = isset($vars[$match[1]]) ? (int) $vars[$match[1]] : 0;
                $rules = $this->pluralRules[$match[2]] ??= $this->parsePluralRules($match[2]);

                $chosen = $rules['=' . $count]
                    ?? ($count === 0 ? $rules['zero'] ?? null : null)
                    ?? ($count === 1 ? $rules['one'] ?? null : null)
                    ?? $rules['other']
                    ?? null;

                // Preserve unmatched blocks for the placeholder substitution pass.
                return $chosen === null ? $match[0] : \str_replace('#', (string) $count, $chosen);
            },
            $pattern
        );

        if ($vars !== []) {
            $replace = [];
            foreach ($vars as $key => $value) {
                $replace['{' . $key . '}'] = (string) $value;
            }
            $result = \strtr((string) $result, $replace);
        }

        return (string) $result;
    }

    /**
     * Parse a plural rule block such as `=0{none} one{one item} other{# items}`.
     *
     * @return array<string, string> Rule keyword (lowercase) to message.
     */
    private function parsePluralRules(string $rulesBlock): array
    {
        \preg_match_all('/(=\d+|zero|one|other)\{([^}]*)\}/i', $rulesBlock, $matches, PREG_SET_ORDER);

        $rules = [];
        foreach ($matches as $match) {
            $rules[\strtolower($match[1])] = $match[2];
        }

        return $rules;
    }

    /**
     * Look up a currency's `[symbol, display name]` pair in a locale's ICU data.
     *
     * Returns an empty array when currency data is unavailable; callers use the
     * currency code as the default value for each missing element.
     *
     * ICU represents a currency entry as a `ResourceBundle`. Read its elements
     * with `get()`; offset access such as `isset($entry[1])` raises an `Error`.
     *
     * @return array{0?: string, 1?: string} Index 0 is the symbol, 1 the name.
     */
    private function currencyEntry(string $locale, string $code): array
    {
        $key = $locale . '|' . $code;
        if (isset($this->currencyEntries[$key])) {
            return $this->currencyEntries[$key];
        }

        if (!$this->intlAvailable || !\class_exists(\ResourceBundle::class)) {
            return $this->currencyEntries[$key] = [];
        }

        // `fallback = true` lets locales such as `de_AT` resolve currency data
        // through `de` when regional data is absent.
        $bundle = \ResourceBundle::create($locale, 'ICUDATA-curr', true);
        if ($bundle === null || $bundle === false) {
            return $this->currencyEntries[$key] = [];
        }

        $currencies = $bundle->get('Currencies');
        if ($currencies === null || $currencies === false) {
            return $this->currencyEntries[$key] = [];
        }

        $entry = $currencies->get($code);
        if (!$entry instanceof \ResourceBundle) {
            return $this->currencyEntries[$key] = [];
        }

        $symbol = $entry->get(0);
        $name   = $entry->get(1);

        return $this->currencyEntries[$key] = [
            \is_scalar($symbol) ? (string) $symbol : $code,
            \is_scalar($name) ? (string) $name : $code,
        ];
    }

    private function toTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (\is_int($value)) {
            return $value;
        }

        return (int) \strtotime((string) $value);
    }
}
