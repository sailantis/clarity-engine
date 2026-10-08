<?php

namespace Clarity\Localization;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Engine\Directive;
use Clarity\ModuleInterface;
use Clarity\Template\TemplateLocation;

/**
 * Translation module for the Clarity template engine.
 *
 * Registers a single `t` filter that looks up translation strings from
 * domain-separated locale files (PHP, JSON, or YAML).
 *
 * File naming convention
 * ----------------------
 * `{translations_path}/{domain}.{locale}.{ext}`
 *
 * Examples:
 *   - `locales/messages.de_DE.yaml`   ← default domain
 *   - `locales/common.de_DE.json`
 *   - `locales/books.en_US.php`
 *
 * Registration
 * ------------
 * ```php
 * // Optional: register to set an application-wide default locale
 * $engine->addModule(new LocaleService(['locale' => 'de_DE']));
 *
 * $engine->addModule(new TranslationModule([
 *     'locale'            => 'de_DE',
 *     'fallback_locale'   => 'en_US',
 *     'translations_path' => __DIR__ . '/locales',
 *     'default_domain'    => 'messages',   // optional, default: 'messages'
 *     'cache_path'        => sys_get_temp_dir(), // optional, where JSON/YAML caches go
 *     'loader'            => null,  // optional, any TranslationLoaderInterface
 * ]));
 * ```
 *
 * Template usage
 * --------------
 * ```twig
 * {# Simple lookup (default domain = messages) #}
 * {{ "logout" |> t }}
 *
 * {# With placeholder variables #}
 * {{ "greeting" |> t({name: user.name}) }}
 *
 * {# Specific domain #}
 * {{ "title" |> t({}, domain:"common") }}
 * {{ "overview" |> t(domain:"books") }}
 *
 * {# Locale switch block (requires LocaleService or auto-bootstrapped) #}
 * {% with_locale user.locale %}
 *     {{ "welcome" |> t }}
 * {% endwith_locale %}
 * ```
 */
class TranslationModule implements ModuleInterface
{
    private ?string $configuredLocale;
    private string $detectedLocale;
    private string $fallbackLocale;
    private string $defaultDomain;
    private array $domainStack = [];
    private string $currentDomain;
    private TranslationLoaderInterface $loader;
    private ?LocaleService $localeService = null;

    public function __construct(array $config = [])
    {
        $this->configuredLocale = $config['locale'] ?? null;
        $this->detectedLocale   = LocaleService::detectLocale();
        $this->fallbackLocale   = $config['fallback_locale'] ?? 'en_US';
        $this->defaultDomain    = $config['default_domain'] ?? 'messages';
        $this->currentDomain    = $this->defaultDomain;

        if (isset($config['loader'])) {
            if (!$config['loader'] instanceof TranslationLoaderInterface) {
                throw new \InvalidArgumentException("Loader must implement TranslationLoaderInterface.");
            }
            $this->loader = $config['loader'];
            return;
        }

        $translationsPath = $config['translations_path'] ?? null;

        if ($translationsPath !== null) {
            $translationsPath = rtrim($translationsPath, '/\\');
            if (!is_dir($translationsPath)) {
                throw new \InvalidArgumentException("Translations path '{$translationsPath}' does not exist or is not a directory.");
            }
        }

        $cachePath = $config['cache_path'] ?? null;
        if ($cachePath === null) {
            $cachePath = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_translations';
            if ($translationsPath !== null) {
                $cachePath .= \DIRECTORY_SEPARATOR;
                $cachePath .= md5($translationsPath);
            }
        }
        $cachePath = rtrim($cachePath, '/\\');

        $this->loader = new FileTranslationLoader(
            $translationsPath ?? '',
            $cachePath
        );

    }

    public function register(ClarityEngine $engine): void
    {
        // Bootstrap the locale service (uses existing one if LocaleService was already registered)
        $this->localeService = LocaleService::bootstrap($engine);
        $engine->addService('t', $this);

        // ── t filter ────────────────────────────────────────────────────────
        // Signature: t($key, $vars=null, $domain=null, $locale=null)
        // Named arg: {{ "key" |> t(domain:"books") }}              → vars defaults to null
        //            {{ "key" |> t({name: v}, domain:"common") }}
        //            {{ "key" |> t(locale:"de_DE") }}
        $engine->addInlineFilter('t', [
            'php'      => "\$__c_sv['t']->get({1}, {2}, {3}, {4})",
            'params'   => ['vars', 'domain', 'locale'],
            'defaults' => ['vars' => 'null', 'domain' => 'null', 'locale' => 'null'],
        ]);

        $engine->addDirective(
            'with_t_domain',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                $rest = trim($rest);
                if ($rest === '') {
                    throw new ClarityException(
                        "'with_t_domain' requires a domain argument, e.g. {% with_t_domain \"emails\" %}",
                        $at
                    );
                }
                $param = $processExpr($rest);
                return "\$__c_sv['t']->pushDomain({$param});";
            },
            Directive::opens('endwith_t_domain')
        );

        $engine->addDirective(
            'endwith_t_domain',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                return "\$__c_sv['t']->popDomain();";
            },
            Directive::closes('with_t_domain')
        );

    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Return the loader this module resolves keys with.
     *
     * The loader is injectable, and a decorator such as `RedisCachingLoader`
     * has an `invalidate()` that is not reachable any other way. The module is
     * registered as the `t` service, so:
     *
     * ```php
     * $loader = $engine->getService('t')->getLoader();
     * if ($loader instanceof RedisCachingLoader) {
     *     $loader->invalidate('messages');
     * }
     * ```
     */
    public function getLoader(): TranslationLoaderInterface
    {
        return $this->loader;
    }

    /**
     * Resolve the locale to translate into, in order of precedence:
     *
     * 1. the locale passed to this call,
     * 2. the `{% with_locale %}` block currently in effect,
     * 3. this module's own `locale` option,
     * 4. the `LocaleService` application-wide default,
     * 5. the detected environment locale.
     */
    private function resolveLocale(?string $locale = null): string
    {
        return $locale
            ?? $this->localeService?->current()
                ?? $this->configuredLocale
                    ?? $this->localeService?->defaultLocale()
                        ?? $this->detectedLocale;
    }

    /**
     * Look up a translation key with optional placeholder substitution.
     *
     * @param string              $key    Translation key.
     * @param ?array<string,mixed> $vars   Placeholder values for `{name}` substitution.
     * @param string|null         $domain Override the default domain.
     * @param string|null         $locale Translate into this locale for this call only,
     *                                    overriding both the active `{% with_locale %}`
     *                                    block and the module's own `locale` option.
     */
    public function get(
        string $key,
        ?array $vars = null,
        ?string $domain = null,
        ?string $locale = null
    ): string {
        $locale = $this->resolveLocale($locale);

        $domain ??= $this->currentDomain;

        // Find out what is missing and load as needed, in order of preference.
        // The loader is asked on every lookup: the module caches nothing, so a
        // loader that is expensive should be wrapped in RedisCachingLoader.
        // 1. Requested locale
        $catalog = $this->loader->load($domain, $locale);
        $msg     = $catalog[$key] ?? null;

        // 2. Fallback locale (if different from requested)
        if ($msg === null && $locale !== $this->fallbackLocale) {
            $fallback = $this->loader->load($domain, $this->fallbackLocale);
            $msg      = $fallback[$key] ?? null;
        }

        // 3. Nothing found → return key
        $msg ??= $key;

        if ($vars === null) {
            return $msg;
        }

        $pairs = [];
        foreach ($vars as $k => $v) {
            $pairs['{' . $k . '}'] = (string) $v;
        }

        return \strtr($msg, $pairs);
    }

    /** =========================================================================
     * Domain stack (for with_t_domain blocks)
     * =========================================================================
     *
     * The domain stack allows nested overrides of the current domain, e.g.:
     *
     * {% with_t_domain "emails" %}
     *     {{ "welcome_subject" |> t }}
     *
     *     {% with_t_domain "passwords" %}
     *         {{ "reset_subject" |> t }}
     *     {% endwith_t_domain %}
     *
     * {% endwith_t_domain %}
     *
     * In this example, the first `t` filter looks up `welcome_subject` in the
     * `emails` domain, while the second looks up `reset_subject` in the nested
     * `passwords` domain.
     */
    public function pushDomain(?string $domain): void
    {
        if ($domain !== null && $domain !== '') {
            $this->domainStack[] = $domain;
            $this->currentDomain = $domain;
        }
    }

    /** Pop the most recently pushed domain off the stack. */
    public function popDomain(): void
    {
        \array_pop($this->domainStack);
        $this->currentDomain = empty($this->domainStack)
            ? $this->defaultDomain
            : \end($this->domainStack);
    }

}