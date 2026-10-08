<?php

namespace Clarity\Localization;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Engine\Directive;
use Clarity\ModuleInterface;
use Clarity\Template\TemplateLocation;
use Locale;

/**
 * Shared locale service for Clarity localization modules.
 *
 * Registers a locale service under the engine service key `'locale'`
 * and installs the `{% with_locale %}` / `{% endwith_locale %}` block
 * directives so that both `TranslationModule` and `IntlFormatModule`
 * — and any user-defined modules — can participate in locale switching.
 *
 * Registration
 * ------------
 * The module is **optional**: `TranslationModule` and `IntlFormatModule` both
 * bootstrap it on their own, so `{% with_locale %}` works either way. Register
 * it explicitly to share one stack across them and to set an application-wide
 * default locale for the case where a module configures none of its own:
 *
 * ```php
 * $engine->addModule(new LocaleService(['locale' => 'de_DE']));
 * $engine->addModule(new TranslationModule([
 *     'translations_path' => __DIR__ . '/locales',
 * ]));
 * ```
 *
 * | Option   | Type   | Default | Description                          |
 * | -------- | ------ | ------- | ------------------------------------ |
 * | `locale` | string | `null`  | Application-wide default locale, used only when neither the stack nor a module's own `locale` supplies one. It is **not** pushed onto the stack |
 *
 * The configured default is deliberately kept *off* the stack: a stack entry
 * outranks every module's `locale` option, so seeding the stack would silently
 * discard the modules' configuration. Kept beside the stack it acts as the
 * lowest-precedence fallback instead, and each module keeps its own locale.
 *
 * A later registration does not replace an already installed locale service,
 * but its configured default is still adopted when the installed one has none,
 * so the modules may bootstrap first and the explicit registration follow.
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
class LocaleService implements ModuleInterface
{
    /** @var string[] */
    private array $localeStack = [];

    private ?string $currentLocale = null;

    private ?string $defaultLocale;

    /**
     * Create a new LocaleService module instance.
     *
     * @param array{locale?: string|null} $config Configuration options for the module.
     */
    public function __construct(array $config = [])
    {
        $this->defaultLocale = $config['locale'] ?? null;
    }

    /**
     * Register the locale service and the `with_locale` / `endwith_locale`
     * block handlers on the engine.
     *
     * The instance itself becomes the engine's `'locale'` service, so the object
     * a caller already holds and the one templates reach are the same stack.
     *
     * Registering when a service is already installed does not displace it — a
     * module that bootstrapped first keeps its stack — but a configured default
     * is still handed to it when it has none of its own.
     */
    public function register(ClarityEngine $engine): void
    {
        if ($engine->hasService('locale')) {
            $installed = $engine->getService('locale');
            if ($installed instanceof self && $this->defaultLocale !== null) {
                $installed->adoptDefaultLocale($this->defaultLocale);
            }
            return;
        }

        $engine->addService('locale', $this);
        self::registerBlocks($engine);
    }

    /**
     * Adopt a default locale, unless one is already configured.
     *
     * Used by {@see self::register()} when a `LocaleService` is registered after
     * the service was already bootstrapped: the first configured default wins
     * and later ones are ignored, so `{% with_locale %}` blocks and module
     * locales stay untouched.
     */
    private function adoptDefaultLocale(?string $locale): void
    {
        if ($this->defaultLocale === null) {
            $this->defaultLocale = $locale;
        }
    }

    /**
     * Return the application-wide default locale, or null when none was configured.
     *
     * This is the lowest-precedence fallback consulted by the localization
     * modules; it is not part of the `{% with_locale %}` stack, so `current()`
     * stays null until a block pushes a locale.
     */
    public function defaultLocale(): ?string
    {
        return $this->defaultLocale;
    }

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
     * Return the locale a `{% with_locale %}` block is currently applying,
     * or null when no block is active.
     *
     * A `LocaleService` default locale is *not* reported here — it is a
     * fallback consulted by the modules, not a stack entry. Use
     * {@see self::defaultLocale()} for it.
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
        $engine->addDirective(
            'with_locale',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                $rest = \trim($rest);
                if ($rest === '') {
                    throw new ClarityException(
                        "'with_locale' requires a locale argument, e.g. {% with_locale \"fr_FR\" %}",
                        $at
                    );
                }
                $param = $processExpr($rest);
                return "\$__c_sv['locale']->push({$param});";
            },
            Directive::opens('endwith_locale')
        );

        $engine->addDirective(
            'endwith_locale',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                return "\$__c_sv['locale']->pop();";
            },
            Directive::closes('with_locale')
        );
    }

    /**
     * Ensure the locale service and blocks are available on the engine.
     *
     * Called by `TranslationModule` and `IntlFormatModule` to
     * self-bootstrap when `LocaleService` was not explicitly registered.
     *
     * Registering the module does NOT go through here: it installs the
     * instance it was called on, keeping its configured default off the stack.
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
