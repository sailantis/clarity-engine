<?php

namespace Clarity\Localization;

use Clarity\ClarityEngine;
use Clarity\ModuleInterface;

/**
 * ICU / intl formatting filters for the Clarity template engine.
 *
 * A thin registration layer: all formatting logic lives in {@see IntlFormatter},
 * which operates independently of the template engine. This module registers its
 * methods as filters and functions and wires the shared {@see LocaleService}, so
 * `{% with_locale %}` updates formatting and translation together.
 *
 * Direct calls to the formatter — in a mailer, CLI task, or framework service —
 * produce the same output as the filters because both use the same methods.
 *
 * Registration
 * ------------
 * ```php
 * // Register a LocaleService to set an application-wide default locale
 * $engine->addModule(new LocaleService(['locale' => 'de_DE']));
 *
 * $engine->addModule(new IntlFormatModule([
 *     'locale'    => 'de_DE',   // this module's locale; takes precedence over the LocaleService default
 *     'timezone'  => 'Europe/Dublin',
 * ]));
 * ```
 *
 * Registered filters
 * ------------------
 * | Filter            | Signature                                              | Description                                      |
 * |-------------------|--------------------------------------------------------|--------------------------------------------------|
 * | `format_number`   | `format_number($v [, $decimals=2] [, $locale])`        | Locale-aware decimal number                      |
 * | `format_currency` | `format_currency($v [, $currency='EUR'] [, $locale])`  | Locale-aware currency amount                     |
 * | `currency_name`   | `currency_name($code [, $locale])`                     | Currency code → display name (e.g. "US Dollar")  |
 * | `currency_symbol` | `currency_symbol($code [, $locale])`                   | Currency code → symbol (e.g. "$")                |
 * | `percent`         | `percent($v [, $decimals=0] [, $locale])`              | Locale-aware percentage                          |
 * | `scientific`      | `scientific($v [, $locale])`                           | Scientific notation (e.g. "1.23E4")              |
 * | `spellout`        | `spellout($v [, $locale])`                             | Number → words (e.g. "forty-two")                |
 * | `ordinal`         | `ordinal($v [, $locale])`                              | Ordinal suffix (e.g. "1st", "2nd")               |
 * | `format_date`     | `format_date($v [, $style='medium'] [, $locale] [, $tz])` | Locale-aware date                            |
 * | `format_time`     | `format_time($v [, $style='medium'] [, $locale] [, $tz])` | Locale-aware time                            |
 * | `format_datetime` | `format_datetime($v [, $ds='medium'] [, $ts='medium'] [, $locale] [, $tz])` | Date + time       |
 * | `format_relative` | `format_relative($v [, $locale])`                      | Relative time ("3 minutes ago")                  |
 * | `transliterate`   | `transliterate($v [, $rules='Any-Latin; Latin-ASCII'])` | Transliterate text                               |
 * | `format_message`  | `format_message($pattern [, $vars] [, $locale])`       | ICU MessageFormat (plurals, selects, …)          |
 *
 * Registered functions
 * --------------------
 * | Function          | Signature                                              | Description                                      |
 * |-------------------|--------------------------------------------------------|--------------------------------------------------|
 * | `country_name`    | `country_name($code [, $displayLocale] [, $locale])`  | ISO country code → display name                  |
 * | `language_name`   | `language_name($code [, $displayLocale] [, $locale])` | Language code → display name                     |
 * | `locale_name`     | `locale_name($id [, $displayLocale] [, $locale])`     | Locale identifier → display name                 |
 * | `timezone_name`   | `timezone_name($tz [, $displayLocale])`                | Timezone identifier → display name               |
 *
 * Template usage
 * --------------
 * ```twig
 * {{ 1234567.89 |> format_number(2) }}
 * {{ price |> format_currency("USD") }}
 * {{ 0.1234 |> percent }}
 * {{ 42 |> spellout }}
 * {{ 1 |> ordinal }}
 * {{ order.created_at |> format_date("long") }}
 * {{ order.created_at |> format_relative }}
 * {{ "Hëllo Wörld" |> transliterate }}
 * {{ "{count, plural, one{# item} other{# items}}" |> format_message({count: n}) }}
 * {{ "USD" |> currency_name }}
 * {{ country_name("DE") }}
 * {{ language_name("de") }}
 * {{ locale_name("en_US") }}
 * {{ timezone_name("America/New_York") }}
 * ```
 */
class IntlFormatModule implements ModuleInterface
{
    private IntlFormatter $formatter;

    /**
     * Create a new IntlFormatModule instance.
     *
     * @param array{locale?: string|null, timezone?: string|null, formatter?: IntlFormatter|null} $config
     *     `locale`: locale for formatting (e.g. "en_US"). Falls back to the
     *     LocaleService default, then the detected environment locale.
     *     `timezone`: default timezone (e.g. "UTC" or "Europe/Berlin"). Falls back
     *     to `date_default_timezone_get()`.
     *     `formatter`: a pre-built formatter instance; use it to share formatting
     *     configuration with application code. A formatter is bound to at most
     *     one {@see LocaleService}: `register()` throws when it is already bound
     *     to a different stack, instead of overwriting another engine's locale.
     */
    public function __construct(array $config = [])
    {
        $this->formatter = $config['formatter'] ?? new IntlFormatter(
            $config['locale'] ?? null,
            $config['timezone'] ?? null,
        );
    }

    /** Return the formatter this module registers. */
    public function getFormatter(): IntlFormatter
    {
        return $this->formatter;
    }

    /**
     * @inheritDoc
     *
     * @throws \LogicException When the configured formatter is already bound to a
     *                         different {@see LocaleService}. Rebinding it would
     *                         replace that stack, so the earlier engine's filters
     *                         would silently resolve their locale from this one.
     */
    public function register(ClarityEngine $engine): void
    {
        // Reuse a registered LocaleService or register one for this engine.
        $localeService = LocaleService::bootstrap($engine);

        // The binding is stored on the formatter itself, so a formatter shared by
        // two engines (or two modules backed by different stacks) cannot serve
        // both. Refuse instead of overwriting the other registration's stack.
        $bound = $this->formatter->localeService();
        if ($bound !== null && $bound !== $localeService) {
            throw new \LogicException(
                'Cannot share one IntlFormatter between different LocaleServices: the formatter is already '
                . 'bound to another locale stack, and rebinding it would replace that stack for its existing '
                . 'filters. Give each engine its own formatter (omit "formatter" to let the module build one), '
                . 'or register the module on an engine that uses the same LocaleService.'
            );
        }

        // Connect the formatter to the shared stack, regardless of registration order.
        $this->formatter->useLocaleService($localeService);

        $this->registerNumberFilters($engine);
        $this->registerDateFilters($engine);
        $this->registerLocaleInfoFunctions($engine);
        $this->registerTextFilters($engine, $localeService);
        $this->registerMessageFilter($engine);
    }

    // =========================================================================
    // Filter registration
    // =========================================================================

    private function registerNumberFilters(ClarityEngine $engine): void
    {
        $f = $this->formatter;

        $engine->addFilter('format_number', fn (mixed $v, int $decimals = 2, ?string $locale = null): string
            => $f->formatNumber($v, $decimals, $locale));

        $engine->addFilter('format_currency', fn (mixed $v, string $currency = 'EUR', ?string $locale = null): string
            => $f->formatCurrency($v, $currency, $locale));

        $engine->addFilter('currency_name', fn (string $code, ?string $locale = null): string
            => $f->currencyName($code, $locale));

        $engine->addFilter('currency_symbol', fn (string $code, ?string $locale = null): string
            => $f->currencySymbol($code, $locale));

        $engine->addFilter('percent', fn (mixed $v, int $decimals = 0, ?string $locale = null): string
            => $f->percent($v, $decimals, $locale));

        $engine->addFilter('scientific', fn (mixed $v, ?string $locale = null): string
            => $f->scientific($v, $locale));

        $engine->addFilter('spellout', fn (mixed $v, ?string $locale = null): string
            => $f->spellout($v, $locale));

        $engine->addFilter('ordinal', fn (mixed $v, ?string $locale = null): string
            => $f->ordinal($v, $locale));
    }

    private function registerDateFilters(ClarityEngine $engine): void
    {
        $f = $this->formatter;

        $engine->addFilter('format_date', fn (mixed $v, string $style = 'medium', ?string $locale = null, ?string $tz = null): string
            => $f->formatDate($v, $style, $locale, $tz));

        $engine->addFilter('format_time', fn (mixed $v, string $style = 'medium', ?string $locale = null, ?string $tz = null): string
            => $f->formatTime($v, $style, $locale, $tz));

        $engine->addFilter('format_datetime', fn (mixed $v, string $dateStyle = 'medium', string $timeStyle = 'medium', ?string $locale = null, ?string $tz = null): string
            => $f->formatDateTime($v, $dateStyle, $timeStyle, $locale, $tz));

        $engine->addFilter('format_relative', fn (mixed $v, ?string $locale = null): string
            => $f->formatRelative($v, $locale));
    }

    private function registerLocaleInfoFunctions(ClarityEngine $engine): void
    {
        $f = $this->formatter;

        $engine->addFunction('country_name', fn (string $code, ?string $displayLocale = null, ?string $locale = null): string
            => $f->countryName($code, $displayLocale, $locale));

        $engine->addFunction('language_name', fn (string $code, ?string $displayLocale = null, ?string $locale = null): string
            => $f->languageName($code, $displayLocale, $locale));

        $engine->addFunction('locale_name', fn (string $id, ?string $displayLocale = null, ?string $locale = null): string
            => $f->localeName($id, $displayLocale, $locale));

        $engine->addFunction('timezone_name', fn (string $tz, ?string $displayLocale = null): string
            => $f->timezoneName($tz, $displayLocale));
    }

    /**
     * @param LocaleService $localeService Unused, but must stay in the signature: it is a
     *                                     protected extension point, and existing subclass
     *                                     overrides declare it as a required parameter.
     */
    protected function registerTextFilters(ClarityEngine $engine, LocaleService $localeService): void
    {
        $engine->addFilter('transliterate', fn (mixed $v, string $rules = 'Any-Latin; Latin-ASCII'): string
            => $this->formatter->transliterate($v, $rules));
    }

    private function registerMessageFilter(ClarityEngine $engine): void
    {
        $f = $this->formatter;

        $engine->addFilter('format_message', fn (string $pattern, ?array $vars = null, ?string $locale = null): string
            => $f->formatMessage($pattern, $vars, $locale));
    }
}
