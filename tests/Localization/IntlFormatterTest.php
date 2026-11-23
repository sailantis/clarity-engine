<?php

namespace Clarity\Tests\Localization;

use Clarity\Localization\IntlFormatter;
use Clarity\Localization\LocaleService;
use Clarity\Tests\BaseTestCase;

/**
 * The engine-free formatting layer: no ClarityEngine, no rendering.
 *
 * These tests pin the logic that used to live inside IntlFormatModule, so the
 * module (and any framework service built on the formatter) can rely on the same
 * behaviour without going through a template.
 */
class IntlFormatterTest extends BaseTestCase
{
    private function formatter(string $locale = 'en_US'): IntlFormatter
    {
        return new IntlFormatter(locale: $locale, timezone: 'Europe/Berlin');
    }

    public function testHasIntlReportsTheExtension(): void
    {
        $this->assertSame(\extension_loaded('intl'), $this->formatter()->hasIntl());
    }

    // ---- Numbers ------------------------------------------------------------

    public function testFormatNumber(): void
    {
        $f = $this->formatter();

        $this->assertSame('1,234,567.89', $f->formatNumber(1234567.891, 2, 'en_US'));
        $this->assertSame('1.234.567,89', $f->formatNumber(1234567.891, 2, 'de_DE'));
    }

    public function testFormatNumberDefaultsToTwoDecimals(): void
    {
        $this->assertSame('42.00', $this->formatter()->formatNumber(42, 2, 'en_US'));
    }

    public function testFormatCurrency(): void
    {
        $f = $this->formatter();

        $this->assertSame('€1,234.50', $f->formatCurrency(1234.5, 'EUR', 'en_US'));
        $this->assertStringContainsString('1.234,50', $f->formatCurrency(1234.5, 'EUR', 'de_DE'));
    }

    public function testCurrencyNameAndSymbol(): void
    {
        $f = $this->formatter();

        if (!$f->hasIntl()) {
            $this->assertSame('USD', $f->currencyName('USD', 'en_US'));
            $this->assertSame('EUR', $f->currencySymbol('EUR', 'en_US'));
            return;
        }

        $this->assertSame('US Dollar', $f->currencyName('USD', 'en_US'));
        $this->assertSame('US-Dollar', $f->currencyName('USD', 'de_DE'));
        $this->assertSame('€', $f->currencySymbol('EUR', 'de_DE'));
    }

    public function testPercent(): void
    {
        $f = $this->formatter();

        $this->assertSame('26%', $f->percent(0.256, 0, 'en_US'));
        $this->assertSame('25.6%', $f->percent(0.256, 1, 'en_US'));
    }

    public function testOrdinalFallsBackToEnglishSuffixes(): void
    {
        // The ordinal filter's non-intl path is English-only by design.
        $native = new IntlFormatter('en_US', null, null, useIntl: false);

        $this->assertSame('1st', $native->ordinal(1));
        $this->assertSame('2nd', $native->ordinal(2));
        $this->assertSame('3rd', $native->ordinal(3));
        $this->assertSame('11th', $native->ordinal(11));
        $this->assertSame('12th', $native->ordinal(12));
        $this->assertSame('13th', $native->ordinal(13));
        $this->assertSame('22nd', $native->ordinal(22));
        $this->assertSame('101st', $native->ordinal(101));
    }

    public function testOrdinalUsesIcuWhenAvailable(): void
    {
        $icu = new IntlFormatter('de_DE', null, null, useIntl: true);

        if (!\extension_loaded('intl')) {
            $this->assertSame('1st', $icu->ordinal(1));
            return;
        }

        // German ordinals are "1." — not the English "1st".
        $this->assertSame('1.', $icu->ordinal(1, 'de_DE'));
    }

    public function testSpelloutWithoutIntlEchoesTheValue(): void
    {
        $native = new IntlFormatter('en_US', null, null, useIntl: false);

        $this->assertSame('42', $native->spellout(42));
    }

    public function testNativeNumberFallbacks(): void
    {
        $native = new IntlFormatter('de_DE', null, null, useIntl: false);

        $this->assertFalse($native->hasIntl());
        $this->assertSame('1,234.57', $native->formatNumber(1234.567, 2));
        $this->assertSame('EUR 1,234.57', $native->formatCurrency(1234.567));
        $this->assertSame('26%', $native->percent(0.256, 0));
        $this->assertSame((string) \sprintf('%E', 12345.0), $native->scientific(12345));
    }

    public function testSpelloutUsesIcuWhenAvailable(): void
    {
        $icu = new IntlFormatter('en_US', null, null, useIntl: true);

        if (!\extension_loaded('intl')) {
            $this->assertSame('42', $icu->spellout(42));
            return;
        }

        $this->assertSame('forty-two', $icu->spellout(42, 'en_US'));
    }

    // ---- Dates --------------------------------------------------------------

    public function testFormatDateAcceptsTimestampStringAndObject(): void
    {
        $f = $this->formatter();

        $fromInt   = $f->formatDate(1700000000, 'long', 'en_US');
        $fromObject = $f->formatDate(new \DateTimeImmutable('@1700000000'), 'long', 'en_US');
        $fromString = $f->formatDate('@1700000000', 'long', 'en_US');

        $this->assertSame($fromInt, $fromObject);
        $this->assertSame($fromInt, $fromString);
    }

    public function testDateStylesDiffer(): void
    {
        $f = $this->formatter();

        $this->assertNotSame(
            $f->formatDate(1700000000, 'short', 'en_US'),
            $f->formatDate(1700000000, 'full', 'en_US')
        );
    }

    public function testTimezoneOverrideIsHonoured(): void
    {
        $f = $this->formatter();

        $berlin = $f->formatDateTime(1700000000, 'short', 'short', 'en_US', 'Europe/Berlin');
        $tokyo  = $f->formatDateTime(1700000000, 'short', 'short', 'en_US', 'Asia/Tokyo');

        $this->assertNotSame($berlin, $tokyo);
    }

    public function testFormatRelative(): void
    {
        $f = $this->formatter();

        $past   = $f->formatRelative(\time() - 7200, 'en_US');
        $future = $f->formatRelative(\time() + 7200, 'en_US');

        $this->assertNotSame($past, $future);
        $this->assertNotSame('', $past);
    }

    // ---- Text and messages --------------------------------------------------

    public function testTransliterate(): void
    {
        $this->assertSame('Hello World', $this->formatter()->transliterate('Hëllo Wörld'));
    }

    public function testFormatMessagePlural(): void
    {
        $f = $this->formatter();
        $pattern = '{count, plural, one{# item} other{# items}}';

        $this->assertSame('1 item', $f->formatMessage($pattern, ['count' => 1], 'en_US'));
        $this->assertSame('3 items', $f->formatMessage($pattern, ['count' => 3], 'en_US'));
    }

    public function testFormatMessagePlaceholders(): void
    {
        $this->assertSame(
            'Hello Anna',
            $this->formatter()->formatMessage('Hello {name}', ['name' => 'Anna'], 'en_US')
        );
    }

    public function testFormatMessageFallsBackWithoutIntl(): void
    {
        // Exercises the plural parser directly: `=0` wins over `zero`, `other`
        // catches the rest, and `#` is replaced by the count.
        $f = $this->formatter();
        $pattern = '{count, plural, =0{none} one{one} other{#}}';

        $this->assertSame('none', $f->formatMessage($pattern, ['count' => 0], 'en_US'));
        $this->assertSame('7', $f->formatMessage($pattern, ['count' => 7], 'en_US'));
    }

    // ---- Locale information -------------------------------------------------

    public function testLocaleInfoWithoutIntlReturnsTheCode(): void
    {
        $f = $this->formatter();

        if ($f->hasIntl()) {
            $this->assertSame('Germany', $f->countryName('DE', null, 'en_US'));
            $this->assertSame('German', $f->languageName('de', null, 'en_US'));
            $this->assertStringContainsString('German', $f->localeName('de_DE', null, 'en_US'));
            return;
        }

        $this->assertSame('DE', $f->countryName('DE'));
        $this->assertSame('de', $f->languageName('de'));
        $this->assertSame('de_DE', $f->localeName('de_DE'));
        $this->assertSame('Europe/Berlin', $f->timezoneName('Europe/Berlin'));
    }

    // ---- Locale resolution --------------------------------------------------

    public function testLocalePrecedence(): void
    {
        $service = new LocaleService(['locale' => 'nb_NO']);
        $f       = new IntlFormatter(locale: 'de_DE', localeService: $service);

        // 1. per call
        $this->assertSame('fr_FR', $f->locale('fr_FR'));

        // 2. the with_locale stack outranks the module option
        $service->push('it_IT');
        $this->assertSame('it_IT', $f->locale());
        $service->pop();

        // 3. the formatter's own option
        $this->assertSame('de_DE', $f->locale());

        // 4. the service default when the formatter has none
        $this->assertSame('nb_NO', (new IntlFormatter(localeService: $service))->locale());
    }

    public function testUseLocaleServiceSharesTheStack(): void
    {
        $service = new LocaleService();
        $f       = (new IntlFormatter(locale: 'en_US'))->useLocaleService($service);

        $this->assertSame($service, $f->localeService());

        $service->push('de_DE');
        $this->assertSame('de_DE', $f->locale());
    }

    public function testOutputFollowsTheActiveLocaleScope(): void
    {
        $service = new LocaleService();
        $f       = (new IntlFormatter(locale: 'en_US', localeService: $service));

        $english = $f->formatNumber(1234.5, 2);
        $service->push('de_DE');
        $german = $f->formatNumber(1234.5, 2);

        $this->assertNotSame($english, $german);
    }
}
