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

        $engine = TestEnvironment::engine();
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

    public function testClarityLocalePushPop(): void
    {
        $locale = new \Clarity\Localization\LocaleService('en_US');
        $this->assertSame('en_US', $locale->current());

        $locale->push('de_DE');
        $this->assertSame('de_DE', $locale->current());

        $locale->push('fr_FR');
        $this->assertSame('fr_FR', $locale->current());

        $locale->pop();
        $this->assertSame('de_DE', $locale->current());

        $locale->pop();
        $this->assertSame('en_US', $locale->current());
    }

    public function testClarityLocaleIgnoresNullAndEmptyPush(): void
    {
        $locale = new \Clarity\Localization\LocaleService('en_US');
        $locale->push(null);
        $locale->push('');
        $this->assertSame('en_US', $locale->current());
    }

    public function testClarityLocalePopOnEmptyStackIsNoOp(): void
    {
        $locale = new \Clarity\Localization\LocaleService('en_US');
        $locale->pop(); // should not throw
        $this->assertSame('en_US', $locale->current());
    }

    // =========================================================================
    // TranslationLoader (unit)
    // =========================================================================

    public function testTranslationLoaderSimpleGet(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_translations_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['greeting' => 'Hello, {name}!'], true) . ';');

        $loader = new \Clarity\Localization\TranslationModule([
            'locale' => 'en_US',
            'fallback_locale' => 'en_US',
            'translations_path' => $dir,
        ]);
        $result = $loader->get('en_US', 'greeting', ['name' => 'Alice']);
        $this->assertSame('Hello, Alice!', $result);

        @unlink($dir . '/messages.en_US.php');
        @rmdir($dir);
    }

    public function testTranslationLoaderFallsBackToFallbackLocale(): void
    {
        $dir = sys_get_temp_dir() . '/clarity_test_translations_fallback_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/messages.en_US.php', '<?php return ' . \var_export(['save' => 'Save'], true) . ';');

        $loader = new \Clarity\Localization\TranslationModule([
            'locale' => 'en_US',
            'fallback_locale' => 'en_US',
            'translations_path' => $dir,
        ]);
        $result = $loader->get('de_DE', 'save');
        $this->assertSame('Save', $result);

        @unlink($dir . '/messages.en_US.php');
        @rmdir($dir);
    }

    public function testTranslationLoaderFallsBackToKeyWhenMissing(): void
    {
        $loader = new \Clarity\Localization\TranslationModule([
            'locale' => 'en_US',
            'fallback_locale' => 'en_US',
            'translations_path' => null,
        ]);
        $result = $loader->get('de_DE', 'some.missing.key');
        $this->assertSame('some.missing.key', $result);
    }

    // =========================================================================
    // TranslationModule (integration)
    // =========================================================================

    private function makeLocaleEngine(?string $translationsDir = null): ClarityEngine
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->use(new \Clarity\Localization\TranslationModule([
            'locale' => 'en_US',
            'fallback_locale' => 'en_US',
            'translations_path' => $translationsDir,
        ]));
        return $engine;
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

    public function testCurrencyFilter(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->use(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));
        self::tpl('lmod_currency', '{{ price |> format_currency("USD", "en_US") }}');
        $result = $engine->renderPartial('lmod_currency', ['price' => 1234.56]);
        $this->assertStringContainsString('1,234.56', $result);
    }

    public function testWithLocaleBlockChangesLocale(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension required');
        }
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->use(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

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
        $engine->use(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

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
        $engine->use(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

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
        $engine->use(new \Clarity\Localization\IntlFormatModule(['locale' => 'en_US']));

        self::tpl('lmod_var_locale', '{% with_locale userLocale %}{{ 1234.56 |> format_currency("EUR", "de_DE") }}{% endwith_locale %}');
        $result = $engine->renderPartial('lmod_var_locale', ['userLocale' => 'de_DE']);
        $this->assertStringContainsString('1.234,56', $result);
    }
}
