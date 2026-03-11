<?php

namespace Clarity\Localization;

use Clarity\ClarityEngine;
use Clarity\ModuleInterface;
use MessageFormatter;

/**
 * ICU / intl formatting module for the Clarity template engine.
 *
 * Provides locale-aware number, currency, date, time, and text formatting
 * filters backed by PHP's `intl` extension. Every filter degrades gracefully
 * when `intl` is unavailable, falling back to a PHP-native equivalent or
 * returning the value unmodified.
 *
 * Registration
 * ------------
 * ```php
 * // Optional: explicit locale service (register first to share with TranslationModule)
 * $engine->use(new LocaleService(['locale' => 'de_DE']));
 *
 * $engine->use(new IntlFormatModule([
 *     'locale'    => 'de_DE',   // default locale (inherits from LocaleService if registered first)
 *     'timezone'  => 'Europe/Berlin',  // default timezone for date/time formatting
 * ]));
 * ```
 *
 * Registered filters
 * ------------------
 * | Filter            | Signature                                              | Description                                      |
 * |-------------------|--------------------------------------------------------|--------------------------------------------------|
 * | `format_number`   | `format_number($v [, $decimals=2] [, $loc])`           | Locale-aware decimal number                      |
 * | `format_currency` | `format_currency($v [, $currency='EUR'] [, $loc])`     | Locale-aware currency amount                     |
 * | `currency_name`   | `currency_name($code [, $displayLocale] [, $loc])`     | Currency code → display name (e.g. "US Dollar")  |
 * | `currency_symbol` | `currency_symbol($code [, $loc])`                      | Currency code → symbol (e.g. "$")                |
 * | `percent`         | `percent($v [, $decimals=0] [, $loc])`                 | Locale-aware percentage                          |
 * | `scientific`      | `scientific($v [, $loc])`                              | Scientific notation (e.g. "1.23E4")              |
 * | `spellout`        | `spellout($v [, $loc])`                                | Number → words (e.g. "forty-two")                |
 * | `ordinal`         | `ordinal($v [, $loc])`                                 | Ordinal suffix (e.g. "1st", "2nd")               |
 * | `format_date`     | `format_date($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware date                                |
 * | `format_time`     | `format_time($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware time                                |
 * | `format_datetime` | `format_datetime($v [, $ds='medium'] [, $ts='medium'] [, $loc] [, $tz])` | Date + time               |
 * | `format_relative` | `format_relative($v [, $loc])`                         | Relative time ("3 minutes ago")                  |
 * | `country_name`    | `country_name($code [, $displayLocale] [, $loc])`      | ISO country code → display name                  |
 * | `language_name`   | `language_name($code [, $displayLocale] [, $loc])`     | Language code → display name                     |
 * | `locale_name`     | `locale_name($id [, $displayLocale] [, $loc])`         | Locale identifier → display name                 |
 * | `transliterate`   | `transliterate($v [, $rules='Any-Latin; Latin-ASCII'])` | Transliterate text                               |
 * | `format_message`  | `format_message($pattern [, $vars=[]] [, $loc])`       | ICU MessageFormat (plurals, selects, …)          |
 *
 * Template usage
 * --------------
 * ```clarity
 * {{ 1234567.89 |> format_number(2) }}
 * {{ price |> format_currency("USD") }}
 * {{ "USD" |> currency_name }}
 * {{ 0.1234 |> percent }}
 * {{ 42 |> spellout }}
 * {{ 1 |> ordinal }}
 * {{ order.created_at |> format_date("long") }}
 * {{ order.created_at |> format_relative }}
 * {{ "DE" |> country_name }}
 * {{ "de" |> language_name }}
 * {{ "Hëllo Wörld" |> transliterate }}
 * {{ "{count, plural, one{# item} other{# items}}" |> format_message({count: n}) }}
 * ```
 */
class IntlFormatModule implements ModuleInterface
{
    private string $locale;
    private ?string $timezone;
    private bool $intlAvailable;

    private static array $styleMap = [
        'none' => \IntlDateFormatter::NONE,
        'short' => \IntlDateFormatter::SHORT,
        'medium' => \IntlDateFormatter::MEDIUM,
        'long' => \IntlDateFormatter::LONG,
        'full' => \IntlDateFormatter::FULL,
    ];

    public function __construct(array $config = [])
    {
        $this->intlAvailable = \extension_loaded('intl');
        $this->locale = $config['locale'] ?? '';
        $this->timezone = $config['timezone'] ?? null;
    }

    public function register(ClarityEngine $engine): void
    {
        // Bootstrap the locale service (uses existing one if LocaleService was already registered)
        $locale = LocaleService::bootstrap($engine, $this->locale);

        $this->registerNumberFilters($engine, $locale);
        $this->registerDateFilters($engine, $locale);
        $this->registerLocaleInfoFunctions($engine, $locale);
        $this->registerTextFilters($engine, $locale);
        $this->registerMessageFilter($engine, $locale);
    }

    // =========================================================================
    // Number formatters
    // =========================================================================

    private function registerNumberFilters(ClarityEngine $engine, LocaleService $locale): void
    {
        $intl = $this->intlAvailable;

        $engine->addFilter(
            'format_number',
            function (mixed $v, int $decimals = 2, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $fmt = $cache[$l] ??= new \NumberFormatter($l, \NumberFormatter::DECIMAL);
                    $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
                    $result = $fmt->format((float) $v);
                    return $result !== false ? $result : '';
                }
                return \number_format((float) $v, $decimals);
            }
        );

        $engine->addFilter(
            'format_currency',
            function (mixed $v, string $currency = 'EUR', ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $fmt = $cache[$l] ??= new \NumberFormatter($l, \NumberFormatter::CURRENCY);
                    $result = $fmt->formatCurrency((float) $v, $currency);
                    return $result !== false ? $result : '';
                }
                return $currency . ' ' . \number_format((float) $v, 2);
            }
        );

        // Proper currency_name using ResourceBundle if available
        $engine->addFilter(
            'currency_name',
            function (string $code, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $dl = $loc ?? $locale->current();
                $key = $dl . '|' . $code;
                if ($intl && \class_exists(\ResourceBundle::class)) {
                    if (isset($cache[$key])) {
                        return $cache[$key];
                    }
                    $bundle = \ResourceBundle::create($dl, 'ICUDATA-curr', true);
                    if ($bundle !== null && $bundle !== false) {
                        $currencies = $bundle->get('Currencies');
                        if ($currencies !== null) {
                            $entry = $currencies->get($code);
                            if ($entry !== null && isset($entry[1])) {
                                return $cache[$key] = (string) $entry[1];
                            }
                        }
                    }
                }
                return $code;
            }
        );

        $engine->addFilter(
            'currency_symbol',
            function (string $code, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                $key = $l . '|' . $code;
                if ($intl && \class_exists(\ResourceBundle::class)) {
                    if (isset($cache[$key])) {
                        return $cache[$key];
                    }
                    $bundle = \ResourceBundle::create($l, 'ICUDATA-curr', true);
                    if ($bundle !== null && $bundle !== false) {
                        $currencies = $bundle->get('Currencies');
                        if ($currencies !== null) {
                            $entry = $currencies->get($code);
                            if ($entry !== null && isset($entry[0])) {
                                return $cache[$key] = (string) $entry[0];
                            }
                        }
                    }
                }
                return $code;
            }
        );

        $engine->addFilter(
            'percent',
            function (mixed $v, int $decimals = 0, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $fmt = $cache[$l] ??= new \NumberFormatter($l, \NumberFormatter::PERCENT);
                    $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
                    $result = $fmt->format((float) $v);
                    return $result !== false ? $result : '';
                }
                return \round((float) $v * 100, $decimals) . '%';
            }
        );

        $engine->addFilter(
            'scientific',
            function (mixed $v, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $fmt = $cache[$l] ??= new \NumberFormatter($l, \NumberFormatter::SCIENTIFIC);
                    $result = $fmt->format((float) $v);
                    return $result !== false ? $result : '';
                }
                return \sprintf('%E', (float) $v);
            }
        );

        $engine->addFilter(
            'spellout',
            function (mixed $v, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $fmt = $cache[$l] ??= new \NumberFormatter($l, \NumberFormatter::SPELLOUT);
                    $result = $fmt->format((float) $v);
                    return $result !== false ? $result : '';
                }
                return (string) $v;
            }
        );

        $engine->addFilter(
            'ordinal',
            function (mixed $v, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $fmt = $cache[$l] ??= new \NumberFormatter($l, \NumberFormatter::ORDINAL);
                    $result = $fmt->format((int) $v);
                    return $result !== false ? $result : '';
                }
                // English-only fallback
                $n = (int) $v % 100;
                $m = $n % 10;
                $sfx = ($n >= 11 && $n <= 13) ? 'th' : match ($m) {
                    1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
                };
                return $v . $sfx;
            }
        );
    }

    // =========================================================================
    // Date / time formatters
    // =========================================================================

    private function registerDateFilters(ClarityEngine $engine, LocaleService $locale): void
    {
        $intl = $this->intlAvailable;
        $defTz = $this->timezone;

        $engine->addFilter(
            'format_date',
            function (mixed $v, string $style = 'medium', ?string $loc = null, ?string $tz = null) use ($locale, $intl, $defTz): string {
                $l = $loc ?? $locale->current();
                if ($intl) {
                    return $this->intlDate($v, $style, 'none', $l, $tz ?? $defTz);
                }
                return $this->nativeDate($v, $style, false, true, $tz ?? $defTz);
            }
        );

        $engine->addFilter(
            'format_time',
            function (mixed $v, string $style = 'medium', ?string $loc = null, ?string $tz = null) use ($locale, $intl, $defTz): string {
                $l = $loc ?? $locale->current();
                if ($intl) {
                    return $this->intlDate($v, 'none', $style, $l, $tz ?? $defTz);
                }
                return $this->nativeDate($v, $style, true, false, $tz ?? $defTz);
            }
        );

        $engine->addFilter(
            'format_datetime',
            function (mixed $v, string $dateStyle = 'medium', string $timeStyle = 'medium', ?string $loc = null, ?string $tz = null) use ($locale, $intl, $defTz): string {
                $l = $loc ?? $locale->current();
                if ($intl) {
                    return $this->intlDate($v, $dateStyle, $timeStyle, $l, $tz ?? $defTz);
                }
                return $this->nativeDate($v, $dateStyle, true, true, $tz ?? $defTz);
            }
        );

        $engine->addFilter(
            'format_relative',
            function (mixed $v, ?string $loc = null) use ($locale, $intl): string {
                static $cache = [];
                $l = $loc ?? $locale->current();
                if ($intl && \class_exists(\RelativeDateTimeFormatter::class)) {
                    $ts = $this->toTimestamp($v);
                    $diff = \time() - $ts;
                    $abs = \abs($diff);
                    $fmt = $cache[$l] ??= new \RelativeDateTimeFormatter($l);

                    [$val, $unit] = match (true) {
                        $abs < 60 => [(float) $abs, \RelativeDateTimeFormatter::UNIT_SECOND],
                        $abs < 3600 => [\round($abs / 60), \RelativeDateTimeFormatter::UNIT_MINUTE],
                        $abs < 86400 => [\round($abs / 3600), \RelativeDateTimeFormatter::UNIT_HOUR],
                        $abs < 604800 => [\round($abs / 86400), \RelativeDateTimeFormatter::UNIT_DAY],
                        $abs < 2592000 => [\round($abs / 604800), \RelativeDateTimeFormatter::UNIT_WEEK],
                        $abs < 31536000 => [\round($abs / 2592000), \RelativeDateTimeFormatter::UNIT_MONTH],
                        default => [\round($abs / 31536000), \RelativeDateTimeFormatter::UNIT_YEAR],
                    };

                    $direction = $diff >= 0
                        ? \RelativeDateTimeFormatter::DIRECTION_LAST
                        : \RelativeDateTimeFormatter::DIRECTION_NEXT;

                    $result = $fmt->formatNumeric($val, $direction, $unit);
                    return $result !== false ? $result : '';
                }

                // Fallback: generic English relative time
                return $this->relativeTimeFallback($this->toTimestamp($v));
            }
        );
    }

    // =========================================================================
    // Locale-info functions
    // =========================================================================

    private function registerLocaleInfoFunctions(ClarityEngine $engine, LocaleService $locale): void
    {
        $intl = $this->intlAvailable;

        $engine->addFunction(
            'country_name',
            function (string $code, ?string $displayLocale = null, ?string $loc = null) use ($locale, $intl): string {
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $name = \Locale::getDisplayRegion('und-' . $code, $displayLocale ?? $l);
                    return $name ?: $code;
                }
                return $code;
            }
        );

        $engine->addFunction(
            'language_name',
            function (string $code, ?string $displayLocale = null, ?string $loc = null) use ($locale, $intl): string {
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $name = \Locale::getDisplayLanguage($code, $displayLocale ?? $l);
                    return $name ?: $code;
                }
                return $code;
            }
        );

        $engine->addFunction(
            'locale_name',
            function (string $id, ?string $displayLocale = null, ?string $loc = null) use ($locale, $intl): string {
                $l = $loc ?? $locale->current();
                if ($intl) {
                    $name = \Locale::getDisplayName($id, $displayLocale ?? $l);
                    return $name ?: $id;
                }
                return $id;
            }
        );

        $engine->addFunction(
            'timezone_name',
            function (string $tz, ?string $displayLocale = null) use ($locale, $intl): string {
                if ($intl) {
                    $formatter = new \IntlDateFormatter(
                        $displayLocale ?? $locale->current(),
                        \IntlDateFormatter::NONE,
                        \IntlDateFormatter::NONE,
                        $tz,
                        \IntlDateFormatter::GREGORIAN,
                        'VVVV' // ICU pattern: long timezone name
                    );

                    return $formatter->format(0);
                }
                return $tz;
            }
        );
    }

    // =========================================================================
    // Text transformation filters
    // =========================================================================

    private function registerTextFilters(ClarityEngine $engine, LocaleService $locale): void
    {
        $intl = $this->intlAvailable;

        $engine->addFilter(
            'transliterate',
            function (mixed $v, string $rules = 'Any-Latin; Latin-ASCII') use ($intl): string {
                static $cache = [];
                $s = (string) $v;
                if ($intl) {
                    $t = $cache[$rules] ??= \Transliterator::create($rules);
                    $result = $t?->transliterate($s);
                    return $result ?: $s;
                }
                if (function_exists('iconv')) {
                    // Very basic ASCII fallback
                    return \iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
                }
                return $s;
            }
        );
    }

    // =========================================================================
    // ICU MessageFormat filter
    // =========================================================================

    private function registerMessageFilter(ClarityEngine $engine, LocaleService $locale): void
    {
        $intl = $this->intlAvailable;

        /**
         * Format an ICU MessageFormat pattern with variable substitution.
         *
         * Falls back to simple `{placeholder}` replacement when the `intl`
         * extension is unavailable or MessageFormatter fails to parse the pattern.
         *
         * @param string $pattern ICU pattern or simple string with {placeholders}
         * @param ?array<string,mixed> $vars e.g. ['name'=>'Joe','count'=>2]
         * @param ?string $loc  e.g. 'en_US'
         * @return string
         */
        $formatMessage = function (string $pattern, ?array $vars = null, ?string $loc = null) use ($intl, $locale): string {
            static $cache = [];
            $loc ??= $locale->current();

            // Try intl first (cached per locale+pattern)
            if ($intl) {
                $cacheKey = $loc . '|' . $pattern;
                $fmt = $cache[$cacheKey]
                    ??= @new MessageFormatter($loc, $pattern);

                if ($fmt !== false) {
                    $res = $fmt->format($vars);
                    if ($res !== false) {
                        return $res;
                    }
                    // fall through to local fallback on failure
                }
            }

            // Local fallback: replace all plural blocks first
            // Matches blocks like {count, plural, =0{...} one{...} other{...}}
            $result = \preg_replace_callback(
                '/\{(\w+)\s*,\s*plural\s*,\s*((?:[^{}]|\{[^{}]*\})*)\}/s',
                function (array $m) use ($vars): string {
                    $countVar = $m[1];
                    $rulesBlock = $m[2];

                    $count = isset($vars[$countVar]) ? (int) $vars[$countVar] : 0;

                    $rules = $cache[$rulesBlock] ?? null;
                    if ($rules === null) {
                        // parse rules: =N{...} | zero{...} | one{...} | other{...}
                        \preg_match_all('/(=\\d+|zero|one|other)\\{([^}]*)\\}/i', $rulesBlock, $rm, PREG_SET_ORDER);
                        $rules = [];
                        foreach ($rm as $r) {
                            $rules[\strtolower($r[1])] = $r[2];
                        }
                        $cache[$rulesBlock] = $rules;
                    }

                    $exactKey = '=' . $count;
                    if (isset($rules[$exactKey])) {
                        $chosen = $rules[$exactKey];
                    } elseif ($count === 0 && isset($rules['zero'])) {
                        $chosen = $rules['zero'];
                    } elseif ($count === 1 && isset($rules['one'])) {
                        $chosen = $rules['one'];
                    } elseif (isset($rules['other'])) {
                        $chosen = $rules['other'];
                    } else {
                        // no match -> leave original block (will get {count} replaced later)
                        return $m[0];
                    }

                    // replace '#' with the numeric count
                    return \str_replace('#', (string) $count, $chosen);
                },
                $pattern
            );

            // Replace remaining placeholders {name}, {count}, ...
            if ($vars !== []) {
                $replace = [];
                foreach ($vars as $k => $v) {
                    $replace['{' . $k . '}'] = (string) $v;
                }
                $result = \strtr($result, $replace);
            }

            return $result;
        };


        $engine->addFilter('format', $formatMessage);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function intlDate(mixed $v, string $dateStyle, string $timeStyle, string $locale, ?string $tz): string
    {
        static $cache = [];

        $dateType = self::$styleMap[$dateStyle] ?? $dateStyle;
        $timeType = self::$styleMap[$timeStyle] ?? $timeStyle;

        $ts = $this->toTimestamp($v);
        $dt = new \DateTime('@' . $ts);
        $dt->setTimezone(new \DateTimeZone($tz ?? \date_default_timezone_get()));
        $tz = $dt->getTimezone()->getName();

        $cacheKey = $locale . '|' . $dateType . '|' . $timeType . '|' . $tz;
        $fmt = $cache[$cacheKey] ??= new \IntlDateFormatter(
            $locale,
            $dateType,
            $timeType,
            $tz,
        );

        $result = $fmt->format($dt);
        return $result !== false ? $result : '';
    }

    private function nativeDate(mixed $v, string $style, bool $includeTime, bool $includeDate, ?string $tz): string
    {
        $ts = $this->toTimestamp($v);
        $dt = new \DateTime('@' . $ts);
        $dt->setTimezone(new \DateTimeZone($tz ?? \date_default_timezone_get()));

        $fmt = match ($style) {
            'short' => $includeDate && $includeTime ? 'n/j/y H:i' : ($includeDate ? 'n/j/y' : 'H:i'),
            'long' => $includeDate && $includeTime ? 'F j, Y H:i:s' : ($includeDate ? 'F j, Y' : 'g:i:s A'),
            'full' => $includeDate && $includeTime ? 'l, F j, Y H:i:s T' : ($includeDate ? 'l, F j, Y' : 'g:i:s A T'),
            default => $includeDate && $includeTime ? 'M j, Y H:i' : ($includeDate ? 'M j, Y' : 'H:i'),
        };

        return $dt->format($fmt);
    }

    private function relativeTimeFallback(int $ts): string
    {
        $diff = \time() - $ts;
        $abs = \abs($diff);
        $past = $diff >= 0;

        [$val, $unit] = match (true) {
            $abs < 60 => [$abs, 'second'],
            $abs < 3600 => [\round($abs / 60), 'minute'],
            $abs < 86400 => [\round($abs / 3600), 'hour'],
            $abs < 604800 => [\round($abs / 86400), 'day'],
            $abs < 2592000 => [\round($abs / 604800), 'week'],
            $abs < 31536000 => [\round($abs / 2592000), 'month'],
            default => [\round($abs / 31536000), 'year'],
        };

        $unit .= $val !== 1.0 ? 's' : '';
        return $past ? "{$val} {$unit} ago" : "in {$val} {$unit}";
    }

    private function toTimestamp(mixed $v): int
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->getTimestamp();
        }
        if (\is_int($v)) {
            return $v;
        }
        return (int) \strtotime((string) $v);
    }

}
