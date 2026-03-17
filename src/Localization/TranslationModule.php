<?php

namespace Clarity\Localization;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\ModuleInterface;

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
 * // Optional: explicit locale service (register first to share with IntlFormatModule)
 * $engine->use(new LocaleService(['locale' => 'de_DE']));
 *
 * $engine->use(new TranslationModule([
 *     'locale'            => 'de_DE',
 *     'fallback_locale'   => 'en_US',
 *     'translations_path' => __DIR__ . '/locales',
 *     'default_domain'    => 'messages',   // optional, default: 'messages'
 *     'cache_path'        => sys_get_temp_dir(), // optional, where JSON/YAML caches go
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
    private string $locale;
    private string $fallbackLocale;
    private ?string $translationsPath;
    private string $defaultDomain;
    private ?string $cachePath;
    private array $domainStack = [];
    private string $currentDomain;
    private TranslationLoaderInterface $loader;
    private ?LocaleService $localeService = null;

    /**
     * Loaded catalogs: [domain][locale] → [key → message].
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $catalog = [];

    public function __construct(array $config = [])
    {
        $this->locale = $config['locale'] ?? LocaleService::detectLocale();
        $this->fallbackLocale = $config['fallback_locale'] ?? 'en_US';
        $this->translationsPath = $config['translations_path'] ?? null;
        $this->defaultDomain = $config['default_domain'] ?? 'messages';
        $this->currentDomain = $this->defaultDomain;

        if ($this->translationsPath !== null) {
            $this->translationsPath = rtrim($this->translationsPath, '/\\');
            if (!is_dir($this->translationsPath)) {
                throw new \InvalidArgumentException("Translations path '{$this->translationsPath}' does not exist or is not a directory.");
            }
        }

        $cachePath = $config['cache_path'] ?? null;
        if ($cachePath === null) {
            $cachePath = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'clarity_translations';
            if ($this->translationsPath !== null) {
                $cachePath .= \DIRECTORY_SEPARATOR;
                $cachePath .= md5($this->translationsPath);
            }
        }
        $this->cachePath = rtrim($cachePath, '/\\');

        $this->loader = $config['loader'] ?? new FileTranslationLoader(
            $this->translationsPath ?? '',
            $this->cachePath
        );

    }

    public function register(ClarityEngine $engine): void
    {
        // Bootstrap the locale service (uses existing one if LocaleService was already registered)
        $this->localeService = LocaleService::bootstrap($engine);
        $engine->addService('t', $this);

        // ── t filter ────────────────────────────────────────────────────────
        // Signature: t($key, $vars=null, $domain=null)
        // Named arg: {{ "key" |> t(domain:"books") }}     → vars defaults to null
        //            {{ "key" |> t({name: v}, domain:"common") }}
        $engine->addInlineFilter('t', [
            'php' => "\$__sv['t']->get({1}, {2}, {3})",
            'params' => ['vars', 'domain'],
            'defaults' => ['vars' => 'null', 'domain' => 'null'],
        ]);

        $engine->addBlock(
            'with_t_domain',
            static function (string $rest, string $sourcePath, int $tplLine, callable $processExpr): string {
                $rest = trim($rest);
                if ($rest === '') {
                    throw new ClarityException(
                        "'with_t_domain' requires a domain argument, e.g. {% with_t_domain \"emails\" %}",
                        $sourcePath,
                        $tplLine
                    );
                }
                $param = $processExpr($rest);
                return "\$__sv['t']->pushDomain({$param});";
            }
        );

        $engine->addBlock(
            'endwith_t_domain',
            static function (string $rest, string $sourcePath, int $tplLine, callable $processExpr): string {
                return "\$__sv['t']->popDomain();";
            }
        );

    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Look up a translation key with optional placeholder substitution.
     *
     * @param string              $key    Translation key.
     * @param ?string             $locale Active locale (e.g. 'de_DE').
     * @param ?array<string,mixed> $vars   Placeholder values for `{name}` substitution.
     * @param string|null         $domain Override the default domain.
     */
    public function get(
        string $key,
        ?array $vars = null,
        ?string $domain = null
    ): string {
        $locale = $this->localeService?->current() ??  $this->locale;

        $domain ??= $this->currentDomain;

        // Quick path: direct lookup without loading if we already have the catalog and key
        $msg = $this->catalog[$domain][$locale][$key]
            ?? $this->catalog[$domain][$this->fallbackLocale][$key]
            ?? null;

        // If we got a hit, we can skip the loading logic and go straight to substitution
        if ($msg !== null) {
            goto buildPairs;
        }

        // Find out what is missing and load as needed, in order of preference:
        // 1. Requested locale
        if (!isset($this->catalog[$domain][$locale])) {
            $catalog = $this->loader->load($domain, $locale);
            $msg = $catalog[$key] ?? null;
            if ($msg !== null) {
                goto buildPairs;
            }
        }

        // 2. Fallback locale (if different from requested)
        if ($locale !== $this->fallbackLocale && !isset($this->catalog[$domain][$this->fallbackLocale])) {
            $fallback = $this->loader->load($domain, $this->fallbackLocale);
            $msg = $fallback[$key] ?? null;
            if ($msg !== null) {
                goto buildPairs;
            }
        }

        // 3. Nothing found → return key
        $msg = $key;

        buildPairs:

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
