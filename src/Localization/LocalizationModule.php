<?php

namespace Clarity\Localization;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Module;

/**
 * Clarity Localization Module
 *
 * Bundles a complete, locale-aware suite of filters and a `with_locale` block
 * directive into a single registerable unit.
 *
 * Registration
 * ------------
 * ```php
 * $engine->use(new LocalizationModule([
 *     'locale'            => 'de_DE',          // default locale
 *     'fallback_locale'   => 'en_US',          // fallback when key missing
 *     'translations_path' => __DIR__ . '/locales', // directory with *.json files
 * ]));
 * ```
 *
 * Registered filters
 * ------------------
 * | Filter          | Signature                                        | Description                        |
 * |-----------------|--------------------------------------------------|------------------------------------|
 * | `t`             | `t($key [, $vars=[]])`                           | Translation lookup                 |
 * | `plural`        | `plural($key, $count)`                           | ICU plural translation             |
 * | `format_date`   | `format_date($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware date          |
 * | `format_time`   | `format_time($v [, $style='medium'] [, $loc] [, $tz])` | Locale-aware time          |
 * | `format_datetime` | `format_datetime($v [, $ds='medium'] [, $ts='medium'] [, $loc] [, $tz])` | Date+time |
 * | `intl_number`   | `intl_number($v [, $decimals=0] [, $loc])`       | Locale-aware number format         |
 * | `currency`      | `currency($v [, $currency='EUR'] [, $loc])`      | Locale-aware currency format       |
 * | `percent`       | `percent($v [, $loc])`                           | Locale-aware percent format        |
 *
 * Registered blocks
 * -----------------
 * | Block          | Description                                     |
 * |----------------|-------------------------------------------------|
 * | `with_locale`  | Push a locale onto the locale stack             |
 * | `endwith_locale` | Pop the locale from the locale stack          |
 *
 * Template examples
 * -----------------
 * ```clarity
 * {# Simple translation #}
 * {{ "logout" |> t }}
 *
 * {# Translation with placeholders #}
 * {{ "greeting" |> t({"name": user.name}) }}
 *
 * {# Plural translation (ICU MessageFormat) #}
 * {{ item_count |> plural("item_count") }}
 *
 * {# Locale-aware date #}
 * {{ order.created_at |> format_date("long") }}
 *
 * {# Currency #}
 * {{ price |> currency("USD") }}
 *
 * {# Temporary locale switch #}
 * {% with_locale user.locale %}
 *     {{ price |> currency("EUR") }}
 *     {{ date |> format_date("long") }}
 * {% endwith_locale %}
 * ```
 *
 * Accessing the locale object directly
 * -------------------------------------
 * The shared `ClarityLocale` instance is also stored under the `locale`
 * service key, so all registered filter closures *and* the compiled template's
 * `$__sv['locale']` refer to the same object — locale changes in one
 * place are immediately visible everywhere.
 */
class LocalizationModule implements Module
{
    private string $locale;
    private string $fallbackLocale;
    private ?string $translationsPath;
    private bool $intlAvailable;

    public function __construct(array $config = [])
    {
        $this->intlAvailable = extension_loaded('intl');
        $this->locale = $config['locale'] ?? ($this->intlAvailable ? \Locale::getDefault() : false) ?: 'en_US';
        $this->fallbackLocale = $config['fallback_locale'] ?? 'en_US';
        $this->translationsPath = $config['translations_path'] ?? null;
    }

    public function register(ClarityEngine $engine): void
    {
        $locale = new LocaleStack($this->locale);
        $translations = new TranslationLoader($this->translationsPath, $this->fallbackLocale);

        // Store locale object as a service so that:
        //   (a) closures below close over it directly, and
        //   (b) compiled with_locale PHP can call $__sv['locale']->push/pop()
        $engine->addService('locale', $locale);

        // ── Translation filters ──────────────────────────────────────────────

        $engine->addFilter(
            't',
            function (string $key, array $vars = []) use ($locale, $translations): string {
                return $translations->get($locale->current(), $key, $vars);
            }
        );

        $engine->addFilter(
            'plural',
            function (string $key, int $count = 1) use ($locale, $translations): string {
                return $translations->plural($locale->current(), $key, $count);
            }
        );

        // ── Date / time formatters ───────────────────────────────────────────

        $engine->addFilter('format_date', $this->makeIntlDateFilter($locale, true, false));
        $engine->addFilter('format_time', $this->makeIntlDateFilter($locale, false, true));
        $engine->addFilter('format_datetime', $this->makeIntlDatetimeFilter($locale));

        // ── Number formatters ────────────────────────────────────────────────

        $engine->addFilter(
            'intl_number',
            function (mixed $v, int $decimals = 0, ?string $loc = null) use ($locale): string {
                $fmt = new \NumberFormatter($loc ?? $locale->current(), \NumberFormatter::DECIMAL);
                $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
                $result = $fmt->format($v);
                return $result !== false ? $result : '';
            }
        );

        $engine->addFilter(
            'currency',
            function (mixed $v, string $currency = 'EUR', ?string $loc = null) use ($locale): string {
                $fmt = new \NumberFormatter($loc ?? $locale->current(), \NumberFormatter::CURRENCY);
                $result = $fmt->formatCurrency((float) $v, $currency);
                return $result !== false ? $result : '';
            }
        );

        $engine->addFilter(
            'percent',
            function (mixed $v, ?string $loc = null) use ($locale): string {
                $fmt = new \NumberFormatter($loc ?? $locale->current(), \NumberFormatter::PERCENT);
                $result = $fmt->format($v);
                return $result !== false ? $result : '';
            }
        );

        // ── Block directives ─────────────────────────────────────────────────

        $engine->addBlock(
            'with_locale',
            function (string $rest, string $sourcePath, int $tplLine, callable $processExpr): string {
                $rest = \trim($rest);
                if ($rest === '') {
                    throw new ClarityException(
                        "'with_locale' requires a locale argument, e.g. {% with_locale \"fr_FR\" %}",
                        $sourcePath,
                        $tplLine
                    );
                }
                $param = $processExpr($rest);
                return "\$__sv['locale']->push({$param});";
            }
        );

        $engine->addBlock(
            'endwith_locale',
            static function (string $rest, string $sourcePath, int $tplLine, callable $processExpr): string {
                return "\$__sv['locale']->pop();";
            }
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static array $styleMap = [
        'none' => \IntlDateFormatter::NONE,
        'short' => \IntlDateFormatter::SHORT,
        'medium' => \IntlDateFormatter::MEDIUM,
        'long' => \IntlDateFormatter::LONG,
        'full' => \IntlDateFormatter::FULL,
    ];

    /**
     * Build a filter callable for format_date (date only) or format_time (time only).
     *
     * Signature in templates:
     *   `format_date($v [, $style='medium'] [, $loc=null] [, $tz=null])`
     *
     * @param bool $hasDate Include the date component.
     * @param bool $hasTime Include the time component.
     */
    private function makeIntlDateFilter(LocaleStack $locale, bool $hasDate, bool $hasTime): callable
    {
        return function (mixed $v, string $style = 'medium', ?string $loc = null, ?string $tz = null, ) use ($locale, $hasDate, $hasTime): string {
            $mapped = self::$styleMap[$style] ?? \IntlDateFormatter::MEDIUM;
            $dateType = $hasDate ? $mapped : \IntlDateFormatter::NONE;
            $timeType = $hasTime ? $mapped : \IntlDateFormatter::NONE;

            return $this->intlFormat($v, $dateType, $timeType, $loc ?? $locale->current(), $tz);
        };
    }

    /**
     * Build a filter callable for format_datetime (both date and time).
     *
     * Signature in templates:
     *   `format_datetime($v [, $dateStyle='medium'] [, $timeStyle='medium'] [, $loc=null] [, $tz=null])`
     */
    private function makeIntlDatetimeFilter(LocaleStack $locale): callable
    {
        return function (mixed $v, string $dateStyle = 'medium', string $timeStyle = 'medium', ?string $loc = null, ?string $tz = null, ) use ($locale): string {
            $ds = self::$styleMap[$dateStyle] ?? \IntlDateFormatter::MEDIUM;
            $ts = self::$styleMap[$timeStyle] ?? \IntlDateFormatter::MEDIUM;

            return $this->intlFormat($v, $ds, $ts, $loc ?? $locale->current(), $tz);
        };
    }

    private function intlFormat(
        mixed $v,
        int $dateStyle,
        int $timeStyle,
        string $locale,
        ?string $timezone,
    ): string {
        $timestamp = \is_int($v) ? $v : (int) \strtotime((string) $v);

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone($timezone ?? \date_default_timezone_get()));

        $fmt = new \IntlDateFormatter(
            $locale,
            $dateStyle,
            $timeStyle,
            $dt->getTimezone()->getName(),
        );

        $out = $fmt->format($dt);
        return $out !== false ? $out : '';
    }
}
