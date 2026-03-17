<?php

namespace Clarity\Localization;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Locale;

/**
 * Shared locale service for Clarity localization modules.
 *
 * Registers a locale service under the engine service key `'locale'`
 * and installs the `{% with_locale %}` / `{% endwith_locale %}` block
 * directives so that both `TranslationModule` and `IntlFormatModule`
 * — and any user-defined modules — can participate in locale switching.
 *
 * Registration order
 * ------------------
 * Always register `LocaleService` **before** the translation / format modules:
 *
 * ```php
 * $engine->use(new LocaleService(['locale' => 'de_DE']));
 * $engine->use(new TranslationModule(['translations_path' => __DIR__ . '/locales']));
 * $engine->use(new IntlFormatModule());
 * ```
 *
 * If either translation or format module is registered without a prior
 * `LocaleService`, they create their own locale service automatically.
 * The `with_locale` blocks are then registered by whichever module runs first.
 *
 * Template usage
 * --------------
 * ```twig
 * {% with_locale "fr_FR" %}
 *     {{ price |> format_currency("EUR") }}
 *     {{ "greeting" |> t }}
 * {% endwith_locale %}
 * ```
 */
class LocaleService
{
    /** @var string[] */
    private array $localeStack = [];

    private ?string $currentLocale = null;

    public static function detectLocale(): string
    {
        // 1. intl
        if (extension_loaded('intl')) {
            $loc = Locale::getDefault();
            if ($loc) {
                return $loc;
            }
        }

        // 2. C-Locale
        $loc = setlocale(LC_ALL, 0);
        if ($loc && $loc !== 'C') {
            return $loc;
        }

        // 3. Environment
        foreach (['LC_ALL', 'LANG', 'LANGUAGE'] as $env) {
            $loc = getenv($env);
            if ($loc) {
                return $loc;
            }
        }

        return 'en_US';
    }

    /**
     * Push a locale onto the stack.
     *
     * Passing null or an empty string is a no-op so that template variables
     * that may be null do not corrupt the stack.
     */
    public function push(?string $locale): void
    {
        if ($locale !== null && $locale !== '') {
            $this->localeStack[] = $locale;
            $this->currentLocale = $locale;
        }
    }

    /**
     * Pop the top locale from the stack.
     *
     * Calling this when the stack is empty is a no-op.
     */
    public function pop(): void
    {
        \array_pop($this->localeStack);
        $this->currentLocale = empty($this->localeStack)
            ? null
            : \end($this->localeStack);
    }

    /**
     * Return the currently active locale (top of the stack), or the default
     * locale when the stack is empty.
     */
    public function current(): ?string
    {
        return $this->currentLocale;
    }

    /**
     * Register `with_locale` / `endwith_locale` block handlers on the engine.
     *
     * Called internally by `register()`, and also by `TranslationModule`
     * and `IntlFormatModule` when they need to self-bootstrap the service.
     */
    public static function registerBlocks(ClarityEngine $engine): void
    {
        $engine->addBlock(
            'with_locale',
            static function (string $rest, string $sourcePath, int $tplLine, callable $processExpr): string {
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

    /**
     * Ensure the locale service and blocks are available on the engine.
     *
     * Called by `TranslationModule` and `IntlFormatModule` to
     * self-bootstrap when `LocaleService` was not explicitly registered.
     *
     * @return static The shared locale stack instance.
     */
    public static function bootstrap(ClarityEngine $engine): static
    {
        if (!$engine->hasService('locale')) {
            $service = new static();
            $engine->addService('locale', $service);
            self::registerBlocks($engine);
        }

        /** @var static */
        return $engine->getService('locale');
    }
}
