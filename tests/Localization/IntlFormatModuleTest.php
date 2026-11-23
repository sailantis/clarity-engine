<?php

namespace Clarity\Tests\Localization;

use Clarity\ClarityEngine;
use Clarity\Localization\IntlFormatModule;
use Clarity\Localization\IntlFormatter;
use Clarity\Localization\LocaleService;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * IntlFormatModule is a registration layer over IntlFormatter.
 *
 * These tests pin the delegation contract: the module must expose the
 * formatter's behaviour rather than keeping a second copy of the logic, because
 * the same formatter is what a framework (Azera) exposes outside templates.
 *
 * Each test builds its own engine, because these cases need module registration
 * order and configuration the shared test engine does not have.
 */
class IntlFormatModuleTest extends BaseTestCase
{
    /** @var string[] */
    private array $views = [];

    protected function tearDown(): void
    {
        foreach ($this->views as $view) {
            @\unlink(TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . $view . '.clarity.html');
        }
        $this->views = [];
    }

    private function engine(): ClarityEngine
    {
        return (new ClarityEngine())
            ->setViewPath(TestEnvironment::viewDir())
            ->setCachePath(TestEnvironment::cacheDir());
    }

    private function template(string $name, string $content): string
    {
        \file_put_contents(
            TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . $name . '.clarity.html',
            $content
        );

        $this->views[] = $name;

        return $name;
    }

    public function testModuleExposesItsFormatter(): void
    {
        $formatter = new IntlFormatter('en_US');
        $module    = new IntlFormatModule(['formatter' => $formatter]);

        $this->assertSame($formatter, $module->getFormatter());
    }

    public function testModuleBuildsAFormatterFromConfig(): void
    {
        $module = new IntlFormatModule(['locale' => 'de_DE', 'timezone' => 'Europe/Berlin']);

        $this->assertSame('de_DE', $module->getFormatter()->locale());
    }

    public function testRegisterSharesTheEngineLocaleService(): void
    {
        $engine = $this->engine();
        $module = new IntlFormatModule(['locale' => 'en_US']);

        $engine->addModule($module);

        $this->assertTrue($engine->hasService('locale'));
        $this->assertSame(
            $engine->getService('locale'),
            $module->getFormatter()->localeService(),
            'The formatter must use the engine locale stack, not a private one'
        );
    }

    public function testFormatterAdoptsAnExistingLocaleService(): void
    {
        $engine = $this->engine();
        $shared = new LocaleService(['locale' => 'nb_NO']);
        $engine->addService('locale', $shared);

        $module = new IntlFormatModule(['locale' => 'en_US']);
        $engine->addModule($module);

        $this->assertSame($shared, $module->getFormatter()->localeService());
    }

    public function testWithLocaleBlockChangesFormatting(): void
    {
        $engine = $this->engine();
        $engine->addModule(new IntlFormatModule(['locale' => 'en_US']));

        $with    = $this->template('intl_with_locale', '{% with_locale "de_DE" %}{{ 1234.5 |> format_number(2) }}{% endwith_locale %}');
        $without = $this->template('intl_without_locale', '{{ 1234.5 |> format_number(2) }}');

        $this->assertNotSame(
            $engine->renderPartial($without),
            $engine->renderPartial($with),
            '{% with_locale %} must change formatting'
        );
    }

    public function testFiltersDelegateToTheSameFormatter(): void
    {
        $engine = $this->engine();

        $formatter = new IntlFormatter('de_DE', 'Europe/Berlin');
        $engine->addModule(new IntlFormatModule(['formatter' => $formatter]));

        $view = $this->template('intl_direct_compare', '{{ 1234.5 |> format_number(2) }}');

        $this->assertSame(
            $formatter->formatNumber(1234.5, 2),
            $engine->renderPartial($view),
            'The template filter and a direct call must produce identical output'
        );
    }

    public function testFormatterAndTranslatorShareOneLocaleStack(): void
    {
        $engine    = $this->engine();
        $formatter = new IntlFormatter('en_US');
        $engine->addModule(new IntlFormatModule(['formatter' => $formatter]));

        $service = $formatter->localeService();
        $this->assertNotNull($service);

        $english = $formatter->formatNumber(1234.5, 2);
        $service->push('de_DE');
        $german = $formatter->formatNumber(1234.5, 2);
        $service->pop();

        $this->assertNotSame($english, $german);
        $this->assertSame($english, $formatter->formatNumber(1234.5, 2));
    }

    /**
     * Every documented filter must compile and render.
     *
     * @dataProvider filterProvider
     */
    public function testDocumentedFiltersAreRegistered(string $filter, string $expression): void
    {
        $engine = $this->engine();
        $engine->addModule(new IntlFormatModule(['locale' => 'en_US']));

        $view = $this->template('intl_probe_' . $filter, '{{ ' . $expression . ' }}');

        $this->assertNotSame('', $engine->renderPartial($view), $filter . ' must render');
    }

    /**
     * One representative call per filter, with the input type that filter takes.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function filterProvider(): array
    {
        return [
            'format_number'   => ['format_number', '1234.5 |> format_number(2)'],
            'format_currency' => ['format_currency', '1234.5 |> format_currency("EUR")'],
            'currency_name'   => ['currency_name', '"USD" |> currency_name'],
            'currency_symbol' => ['currency_symbol', '"EUR" |> currency_symbol'],
            'percent'         => ['percent', '0.25 |> percent'],
            'scientific'      => ['scientific', '12345 |> scientific'],
            'spellout'        => ['spellout', '42 |> spellout'],
            'ordinal'         => ['ordinal', '1 |> ordinal'],
            'format_date'     => ['format_date', '1700000000 |> format_date("long")'],
            'format_time'     => ['format_time', '1700000000 |> format_time("short")'],
            'format_datetime' => ['format_datetime', '1700000000 |> format_datetime("short", "short")'],
            'format_relative' => ['format_relative', '1700000000 |> format_relative'],
            'transliterate'   => ['transliterate', '"Hëllo" |> transliterate'],
            'format_message'  => ['format_message', '"{count, plural, one{# item} other{# items}}" |> format_message({count: 2})'],
        ];
    }

    /** Every documented function must compile and render. */
    public function testDocumentedFunctionsAreRegistered(): void
    {
        $engine = $this->engine();
        $engine->addModule(new IntlFormatModule(['locale' => 'en_US']));

        $cases = [
            'country_name'  => '{{ country_name("DE") }}',
            'language_name' => '{{ language_name("de") }}',
            'locale_name'   => '{{ locale_name("de_DE") }}',
            'timezone_name' => '{{ timezone_name("Europe/Berlin") }}',
        ];
        foreach ($cases as $name => $source) {
            $view = $this->template('intl_fn_' . $name, $source);
            $this->assertNotSame('', $engine->renderPartial($view), $name . ' must render');
        }
    }
}
