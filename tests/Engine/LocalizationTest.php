<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

class LocalizationTest extends BaseTestCase
{
    public function testNamespacedViewResolution(): void
    {
        $viewDir = TestEnvironment::viewDir();
        $nsDir   = $viewDir . '/admin';
        if (!is_dir($nsDir)) {
            mkdir($nsDir, 0755, true);
        }

        file_put_contents($nsDir . '/hello.clarity.html', 'ns-hello');

        $engine         = TestEnvironment::engine();
        $originalLoader = $engine->getLoader();
        $engine->setLoader(new DomainRouterLoader(
            ['admin' => new FileLoader($nsDir)],
            new FileLoader($viewDir),
        ));

        try {
            $this->assertSame('ns-hello', $engine->render('admin::hello'));
        } finally {
            $engine->setLoader($originalLoader);
        }
    }

    // =========================================================================
    // LocaleService (unit)
    // =========================================================================

    /**
     * Collapse the Unicode space characters ICU uses as grouping separators
     * (NBSP, narrow NBSP, word joiner) so formatter output can be asserted with
     * plain strings regardless of the ICU data version.
     *
     * @param string $s Formatted output.
     */
    private static function normalizeSpaces(string $s): string
    {
        return \str_replace(["\u{202F}", "\u{00A0}", "\u{2060}"], ' ', $s);
    }

    public function testClarityLocalePushPop(): void
    {
        $locale = new \Clarity\Localization\LocaleService();
        $this->assertSame(null, $locale->current());

        $locale->push('de_DE');
        $this->assertSame('de_DE', $locale->current());

        $locale->push('fr_FR');
        $this->assertSame('fr_FR', $locale->current());

        $locale->pop();
        $this->assertSame('de_DE', $locale->current());

        $locale->pop();
        $this->assertSame(null, $locale->current());
    }

    public function testClarityLocaleIgnoresNullAndEmptyPush(): void
    {
        $locale = new \Clarity\Localization\LocaleService();
        $locale->push(null);
        $locale->push('');
        $this->assertSame(null, $locale->current());
    }

    public function testClarityLocalePopOnEmptyStackIsNoOp(): void
    {
        $locale = new \Clarity\Localization\LocaleService();
        $locale->pop(); // should not throw
        $this->assertSame(null, $locale->current());
    }

    public function testEmptyLocalePushKeepsPopBalanced(): void
    {
        $locale = new \Clarity\Localization\LocaleService();
        $locale->push('de_DE');
        $locale->push(null);
        $this->assertSame('de_DE', $locale->current());

        $locale->pop();
        $this->assertSame('de_DE', $locale->current());

        $locale->pop();
        $this->assertSame(null, $locale->current());
    }

    public function testEmptyDomainPushKeepsPopBalanced(): void
    {
        $loader = new class implements \Clarity\Localization\TranslationLoaderInterface {
            public function load(string $domain, string $locale): array
            {
                return ['k' => $domain];
            }
        };
        $module = new \Clarity\Localization\TranslationModule(['loader' => $loader, 'locale' => 'en_US']);

        $module->pushDomain('emails');
        $module->pushDomain(null);
        $this->assertSame('emails', $module->get('k'));

        $module->popDomain();
        $this->assertSame('emails', $module->get('k'));

        $module->popDomain();
        $this->assertSame('messages', $module->get('k'));
    }

    public function testLocaleServiceIsAModule(): void
    {
        $this->assertInstanceOf(\Clarity\ModuleInterface::class, new \Clarity\Localization\LocaleService());
    }

    public function testLocaleServiceKeepsItsConfiguredLocaleOffTheStack(): void
    {
        $engine = new ClarityEngine();
        $module = new \Clarity\Localization\LocaleService(['locale' => 'de_DE']);
        $engine->addModule($module);

        // The instance that was registered IS the engine's service, so a caller
        // holding the module sees the same stack templates push onto.
        $this->assertSame($module, $engine->getService('locale'));

        // The configured locale is a fallback beside the stack, not an entry on
        // it: a stack entry would outrank every module's own `locale` option and
        // silently discard it.
        $this->assertSame('de_DE', $module->defaultLocale());
        $this->assertSame(null, $module->current());
    }

    public function testLocaleServiceRegisteredAfterBootstrapLeavesTheServiceAlone(): void
    {
        $engine = new ClarityEngine();
        $engine->addModule(new \Clarity\Localization\TranslationModule(['locale' => 'en_US']));
        $bootstrapped = $engine->getService('locale');
        $this->assertSame(null, $bootstrapped->defaultLocale());

        $engine->addModule(new \Clarity\Localization\LocaleService(['locale' => 'de_DE']));

        // The stack itself is not displaced by a late registration...
        $this->assertSame($bootstrapped, $engine->getService('locale'));
        $this->assertSame(null, $engine->getService('locale')->current());

        // ...but its configured default is adopted, since the installed service
        // had none of its own.
        $this->assertSame('de_DE', $bootstrapped->defaultLocale());
    }

    public function testLocaleServiceRegisteredAfterAnotherDefaultKeepsTheFirst(): void
    {
        $engine = new ClarityEngine();
        $first  = new \Clarity\Localization\LocaleService(['locale' => 'de_DE']);
        $engine->addModule($first);
        $engine->addModule(new \Clarity\Localization\LocaleService(['locale' => 'fr_FR']));

        $this->assertSame($first, $engine->getService('locale'));
        $this->assertSame('de_DE', $first->defaultLocale());
    }

    // =========================================================================
    // Locale precedence
    // =========================================================================

    /**
     * A module's own `locale` option must win over the LocaleService default.
     *
     * Before the split, the default was pushed onto the stack, and the stack
     * outranks module config — so `fr_FR` here was dead and everything came out
     * as `de_DE`.
     */
    public function testModuleLocaleWinsOverTheLocaleServiceDefault(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\LocaleService(['locale' => 'de_DE']));
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'fr_FR']));

        self::tpl('lmod_prec_module_wins', '{{ 1234.56 |> format_currency("EUR") }}');
        $result = self::normalizeSpaces($engine->renderPartial('lmod_prec_module_wins'));

        // fr_FR groups with a space and uses a comma as the decimal separator.
        $this->assertStringContainsString('1 234,56', $result);
        $this->assertStringNotContainsString('1.234,56', $result);
    }

    /**
     * The module's `locale` option is only consulted when the stack is empty, so
     * a `{% with_locale %}` block must still override it — and must pop back to
     * the module's locale, not to the LocaleService default.
     */
    public function testWithLocaleOverridesTheModuleLocaleAndPopsBackToIt(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\LocaleService(['locale' => 'de_DE']));
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl(
            'lmod_prec_stack_wins',
            '{{ 1234.56 |> format_currency("EUR") }}|'
            . '{% with_locale "de_DE" %}{{ 1234.56 |> format_currency("EUR") }}{% endwith_locale %}|'
            . '{{ 1234.56 |> format_currency("EUR") }}'
        );
        $result = self::normalizeSpaces($engine->renderPartial('lmod_prec_stack_wins'));

        // en_US | de_DE | en_US — the last segment proves the pop restored the
        // module's own locale rather than the LocaleService default.
        $this->assertSame('€1,234.56|1.234,56 €|€1,234.56', \trim($result));
    }

    public function testLocaleServiceDefaultIsUsedWhenTheModuleConfiguresNone(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\LocaleService(['locale' => 'fr_FR']));
        $engine->addModule(new \Clarity\Localization\IntlFormatModule());

        self::tpl('lmod_prec_service_default', '{{ 1234.56 |> format_currency("EUR") }}');
        $result = self::normalizeSpaces($engine->renderPartial('lmod_prec_service_default'));

        // fr_FR, not the detected environment locale (de_DE here).
        $this->assertStringContainsString('1 234,56', $result);
    }

    /**
     * The resolved locale must not depend on registration order: a late
     * LocaleService still contributes its default.
     */
    public function testLocalePrecedenceIsRegistrationOrderIndependent(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }

        $render = static function (bool $serviceFirst): string {
            $engine = new ClarityEngine();
            $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
            $service = new \Clarity\Localization\LocaleService(['locale' => 'de_DE']);
            $intl    = new \Clarity\Localization\IntlFormatModule(['locale' => 'fr_FR']);

            $engine->addModule($serviceFirst ? $service : $intl);
            $engine->addModule($serviceFirst ? $intl : $service);

            $name = 'lmod_prec_order_' . ($serviceFirst ? 'a' : 'b');
            self::tpl($name, '{{ 1234.56 |> format_currency("EUR") }}');
            return $engine->renderPartial($name);
        };

        $this->assertSame($render(true), $render(false));
    }

    // =========================================================================
    // TranslationLoader (unit)
    // =========================================================================

    public function testTranslationLoaderSimpleGet(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_translations_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['greeting' => 'Hello, {name}!'], true) . ';');

        $loader = new \Clarity\Localization\TranslationModule(
            [
                'locale'            => 'en_US',
                'fallback_locale'   => 'en_US',
                'translations_path' => $dir,
            ]
        );
        $result = $loader->get('greeting', ['name' => 'Alice']);
        $this->assertSame('Hello, Alice!', $result);

        @unlink($dir . '/messages.en_US.php');
        @rmdir($dir);
    }

    public function testTranslationLoaderFallsBackToFallbackLocale(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_translations_fallback_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['save' => 'Save'], true) . ';');

        $loader = new \Clarity\Localization\TranslationModule(
            [
                'locale'            => 'en_US',
                'fallback_locale'   => 'en_US',
                'translations_path' => $dir,
            ]
        );
        $result = $loader->get('save');
        $this->assertSame('Save', $result);

        @unlink($dir . '/messages.en_US.php');
        @rmdir($dir);
    }

    public function testTranslationLoaderFallsBackToKeyWhenMissing(): void
    {
        $loader = new \Clarity\Localization\TranslationModule(
            [
                'locale'            => 'en_US',
                'fallback_locale'   => 'en_US',
                'translations_path' => null,
            ]
        );
        $result = $loader->get('some.missing.key');
        $this->assertSame('some.missing.key', $result);
    }

    // =========================================================================
    // TranslationModule (integration)
    // =========================================================================

    private function makeLocaleEngine(?string $translationsDir = null): ClarityEngine
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\TranslationModule([
            'locale'            => 'en_US',
            'fallback_locale'   => 'en_US',
            'translations_path' => $translationsDir,
        ]));
        return $engine;
    }

    public function testTFilterAcceptsAPerCallLocale(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_t_locale_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['greeting' => 'Hello'], true) . ';');
        file_put_contents($dir . '/messages.de_DE.php', '<?php return ' . \var_export(['greeting' => 'Hallo'], true) . ';');

        $engine = $this->makeLocaleEngine($dir); // module locale: en_US

        // Positional and named, next to a domain, and with vars.
        self::tpl('lmod_t_loc_pos', '{{ "greeting" |> t(null, null, "de_DE") }}');
        $this->assertSame('Hallo', $engine->renderPartial('lmod_t_loc_pos'));

        self::tpl('lmod_t_loc_named', '{{ "greeting" |> t(locale: "de_DE") }}');
        $this->assertSame('Hallo', $engine->renderPartial('lmod_t_loc_named'));

        self::tpl('lmod_t_loc_full', '{{ "greeting" |> t({}, domain: "messages", locale: "de_DE") }}');
        $this->assertSame('Hallo', $engine->renderPartial('lmod_t_loc_full'));

        @unlink($dir . '/messages.en_US.php');
        @unlink($dir . '/messages.de_DE.php');
        @rmdir($dir);
    }

    /**
     * The per-call locale must outrank an enclosing `{% with_locale %}` block,
     * the same way the intl filters' trailing locale argument does. Otherwise an
     * explicit argument could be silently ignored.
     */
    public function testTFilterPerCallLocaleOutranksWithLocaleBlock(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_t_loc_prec_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['greeting' => 'Hello'], true) . ';');
        file_put_contents($dir . '/messages.de_DE.php', '<?php return ' . \var_export(['greeting' => 'Hallo'], true) . ';');
        file_put_contents($dir . '/messages.fr_FR.php', '<?php return ' . \var_export(['greeting' => 'Bonjour'], true) . ';');

        $engine = $this->makeLocaleEngine($dir);

        self::tpl(
            'lmod_t_loc_prec',
            '{% with_locale "de_DE" %}{{ "greeting" |> t }}|{{ "greeting" |> t(locale: "fr_FR") }}{% endwith_locale %}'
        );
        // The block governs the bare lookup; the argument wins for the other.
        $this->assertSame('Hallo|Bonjour', $engine->renderPartial('lmod_t_loc_prec'));

        @unlink($dir . '/messages.en_US.php');
        @unlink($dir . '/messages.de_DE.php');
        @unlink($dir . '/messages.fr_FR.php');
        @rmdir($dir);
    }

    public function testTFilterPerCallLocaleFallsBackToFallbackLocale(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_t_loc_fb_' . uniqid();
        mkdir($dir);
        // No pt_BR catalogue; only the en_US fallback exists.
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['greeting' => 'Hello'], true) . ';');

        $engine = $this->makeLocaleEngine($dir);
        self::tpl('lmod_t_loc_fb', '{{ "greeting" |> t(locale: "pt_BR") }}');

        // fallback_locale is relative to the RESOLVED locale, not the module's.
        $this->assertSame('Hello', $engine->renderPartial('lmod_t_loc_fb'));

        @unlink($dir . '/messages.en_US.php');
        @rmdir($dir);
    }

    public function testTFilterSimpleTranslation(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_lmod_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['hello' => 'Hello World'], true) . ';');

        $engine = $this->makeLocaleEngine($dir);
        self::tpl('lmod_t_simple', '{{ "hello" |> t }}');
        $result = $engine->renderPartial('lmod_t_simple');
        $this->assertSame('Hello World', $result);

        @unlink($dir . '/messages.en_US.php');
        @rmdir($dir);
    }

    public function testTFilterFallsBackToKeyWhenNoTranslation(): void
    {
        $engine = $this->makeLocaleEngine(null);
        self::tpl('lmod_t_missing', '{{ "missing.key" |> t }}');
        $result = $engine->renderPartial('lmod_t_missing');
        $this->assertSame('missing.key', $result);
    }

    public function testFormatMessageWithoutVarsReturnsThePattern(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        // With no vars, the intl path used to receive null and throw a TypeError.
        self::tpl('lmod_format_message_novars', '{{ "No placeholders here" |> format_message }}');
        $this->assertSame(
            'No placeholders here',
            $engine->renderPartial('lmod_format_message_novars')
        );
    }

    /**
     * The ICU MessageFormat filter is registered as `format_message` — the name
     * the module's own docblock, `.phpstorm.meta.php`, and the user docs all use.
     *
     * It used to be registered as `format`, which nothing documented; that made
     * the documented name `format_message` fail at runtime. This test exists so
     * the two can never drift apart again.
     */
    public function testFormatMessageFilterIsRegisteredUnderItsDocumentedName(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        // Rendering a documented example is the assertion that matters: before
        // the rename the filter was registered as `format`, so this threw
        // "Unknown filter 'format_message'".
        self::tpl('lmod_format_message_pipe', '{{ "{name}!" |> format_message({ name: who }) }}');
        $this->assertSame(
            'Joe!',
            $engine->renderPartial('lmod_format_message_pipe', ['who' => 'Joe'])
        );
    }

    public function testFormatMessageFilterFormatsAPattern(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        // A simple `{placeholder}` pattern is handled by the local fallback, so
        // this asserts the filter is wired up without requiring the intl extension.
        self::tpl('lmod_format_message', '{{ "{name} has {count}" |> format_message({ name: who, count: n }) }}');
        $result = $engine->renderPartial('lmod_format_message', ['who' => 'Joe', 'n' => 3]);

        $this->assertSame('Joe has 3', $result);
    }

    public function testCurrencyFilter(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));
        self::tpl('lmod_currency', '{{ price |> format_currency("USD", "en_US") }}');
        $result = $engine->renderPartial('lmod_currency', ['price' => 1234.56]);
        $this->assertStringContainsString('1,234.56', $result);
    }

    /**
     * `currency_name` and `currency_symbol` read their data out of ICU's
     * `ICUDATA-curr` bundle.
     *
     * Both used to throw `Cannot use object of type ResourceBundle as array`:
     * an ICU currency entry is itself a `ResourceBundle`, and `ResourceBundle`
     * implements `Countable`/`Traversable` but NOT `ArrayAccess` — so the
     * `isset($entry[1])` guard they used as a presence check is a fatal error
     * rather than a false. The read that follows it is fine; it is specifically
     * `isset()` on an offset that throws.
     */
    public function testCurrencyNameResolvesThroughIcuData(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl('lmod_currency_name', '{{ currency_name("USD") }}');
        $this->assertSame('US Dollar', $engine->renderPartial('lmod_currency_name'));

        // A per-call locale selects that locale's name for the same currency.
        self::tpl('lmod_currency_name_loc', '{{ currency_name("USD", locale: "de_DE") }}');
        $this->assertSame('US-Dollar', $engine->renderPartial('lmod_currency_name_loc'));
    }

    public function testCurrencySymbolResolvesThroughIcuData(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl('lmod_currency_symbol', '{{ currency_symbol("USD") }}|{{ currency_symbol("EUR") }}');
        $this->assertSame('$|€', $engine->renderPartial('lmod_currency_symbol'));
    }

    /**
     * An unknown code has no ICU entry, so the filter falls back to the code
     * itself rather than throwing or rendering an empty string.
     */
    public function testCurrencyNameFallsBackToTheCodeWhenUnknown(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl('lmod_currency_name_unknown', '{{ currency_name("ZZZ") }}|{{ currency_symbol("ZZZ") }}');
        $this->assertSame('ZZZ|ZZZ', $engine->renderPartial('lmod_currency_name_unknown'));
    }

    /**
     * A regional locale with no entry of its own must resolve through its base:
     * ICU is asked with `fallback = true`, so `de_AT` can still name `EUR`.
     */
    public function testCurrencyNameResolvesRegionalLocaleThroughItsBase(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl('lmod_currency_name_regional', '{{ currency_name("EUR", locale: "de_AT") }}');
        $this->assertNotSame('EUR', $engine->renderPartial('lmod_currency_name_regional'));
    }

    public function testWithLocaleBlockChangesLocale(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl('lmod_with_locale', '{{ 1234.56 |> format_currency("EUR", "en_US") }}|{% with_locale "de_DE" %}{{ 1234.56 |> format_currency("EUR", "de_DE") }}{% endwith_locale %}');
        $result = $engine->renderPartial('lmod_with_locale');

        [$outside, $inside] = explode('|', $result, 2);
        $this->assertStringContainsString('1,234.56', $outside);
        $this->assertStringContainsString('1.234,56', $inside);
    }

    public function testWithLocaleBlockRestoresLocaleAfter(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl(
            'lmod_locale_restore',
            '{% with_locale "de_DE" %}inner{% endwith_locale %}{{ 1234.56 |> format_currency("EUR", "en_US") }}'
        );
        $result = $engine->renderPartial('lmod_locale_restore');
        $this->assertStringContainsString('1,234.56', $result);
    }

    public function testWithLocaleRequiresArgument(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'with_locale' requires/");
        self::tpl('lmod_no_arg', '{% with_locale %}oops{% endwith_locale %}');
        $engine->renderPartial('lmod_no_arg');
    }

    public function testWithLocaleAcceptsVariableExpression(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addModule(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl('lmod_var_locale', '{% with_locale userLocale %}{{ 1234.56 |> format_currency("EUR", "de_DE") }}{% endwith_locale %}');
        $result = $engine->renderPartial('lmod_var_locale', ['userLocale' => 'de_DE']);
        $this->assertStringContainsString('1.234,56', $result);
    }
}