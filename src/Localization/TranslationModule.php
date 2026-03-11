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
 * ```clarity
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

    /**
     * Loaded catalogs: [domain][locale] → [key → message].
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $catalog = [];

    public function __construct(array $config = [])
    {
        $this->locale = $config['locale'] ?? '';
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
    }

    public function register(ClarityEngine $engine): void
    {
        // Bootstrap the locale service (uses existing one if LocaleService was already registered)
        /*$locale = */
        LocaleService::bootstrap($engine, $this->locale);
        $engine->addService('t', $this);

        // ── t filter ────────────────────────────────────────────────────────
        // Signature: t($key, $vars=null, $domain=null)
        // Named arg: {{ "key" |> t(domain:"books") }}     → vars defaults to null
        //            {{ "key" |> t({name: v}, domain:"common") }}
        /*
        $engine->addFilter(
            't',
            fn(string $key, ?array $vars = null, ?string $domain = null): string => $this->get($locale->current(), $key, $vars, $domain)
        );
        */
        $engine->addInlineFilter('t', [
            'php' => "\$__sv['t']->get(\$__sv['locale']->current(), {1}, {2}, {3})",
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
     * @param string              $locale Active locale (e.g. 'de_DE').
     * @param string              $key    Translation key.
     * @param ?array<string,mixed> $vars   Placeholder values for `{name}` substitution.
     * @param string|null         $domain Override the default domain.
     */
    public function get(
        string $locale,
        string $key,
        ?array $vars = null,
        ?string $domain = null
    ): string {

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
            $catalog = $this->load($domain, $locale);
            $msg = $catalog[$key] ?? null;
            if ($msg !== null) {
                goto buildPairs;
            }
        }

        // 2. Fallback locale (if different from requested)
        if ($locale !== $this->fallbackLocale && !isset($this->catalog[$domain][$this->fallbackLocale])) {
            $fallback = $this->load($domain, $this->fallbackLocale);
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

    /**
     * Load a catalog for a domain + locale combination.
     * Results are memory-cached for the lifetime of this object.
     *
     * @return array<string, string>
     */
    private function load(string $domain, string $locale): array
    {
        //if (isset($this->catalog[$domain][$locale])) {
        //    return $this->catalog[$domain][$locale];
        //}

        if ($this->translationsPath === null) {
            return $this->catalog[$domain][$locale] = [];
        }

        $base = \rtrim($this->translationsPath, '/\\') . \DIRECTORY_SEPARATOR . $domain . '.' . $locale;

        // 1. PHP file — fastest, loaded directly via require
        if (\is_file($base . '.php')) {
            return $this->catalog[$domain][$locale] = $this->loadPhpFile($base . '.php');
        }

        // 2. JSON file — compiled to PHP cache, then required
        if (\is_file($base . '.json')) {
            return $this->catalog[$domain][$locale] = $this->loadViaCachePhp(
                $base . '.json',
                fn(string $src): array => $this->parseJson($src)
            );
        }

        // 3. YAML / YML file — compiled to PHP cache, then required
        foreach (['.yaml', '.yml'] as $ext) {
            if (\is_file($base . $ext)) {
                return $this->catalog[$domain][$locale] = $this->loadViaCachePhp(
                    $base . $ext,
                    fn(string $src): array => YamlParser::parse($src)
                );
            }
        }

        return $this->catalog[$domain][$locale] = [];
    }

    // =========================================================================
    // Format-specific helpers
    // =========================================================================

    /** @return array<string, string> */
    private function loadPhpFile(string $file): array
    {
        $data = @require $file;
        if (!\is_array($data)) {
            throw new \RuntimeException("Translation file '{$file}' did not return an array.");
        }
        return $data; //$this->normalizeToStrings(\is_array($data) ? $data : []);
    }

    /** @return array<string, string> */
    private function parseJson(string $src): array
    {
        try {
            $decoded = \json_decode($src, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }
        return $this->normalizeToStrings($this->flattenArray($decoded));
    }

    /**
     * Load a translation file by compiling it to a PHP cache if necessary,
     * then requiring the cached file.
     *
     * @param  string   $sourceFile Absolute path to the source (JSON/YAML) file.
     * @param  callable $parser     fn(string $content): array<string,string>
     * @return array<string, string>
     */
    private function loadViaCachePhp(string $sourceFile, callable $parser): array
    {
        $cacheFile = $this->cachePath . \DIRECTORY_SEPARATOR . \md5($sourceFile) . '.php';

        $sourceMtime = @\filemtime($sourceFile);
        if ($sourceMtime === false) {
            throw new \RuntimeException("Source translation file '{$sourceFile}' does not exist or is not readable.");
        }

        if (@\filemtime($cacheFile) >= $sourceMtime) {
            $data = @require $cacheFile;
            if (\is_array($data)) {
                return $data;
            }
        }

        $src = \file_get_contents($sourceFile);
        if ($src === false) {
            return [];
        }

        /** @var array<string, string> $data */
        $data = $parser($src);
        $this->writePhpCache($cacheFile, $data);

        return $data;
    }

    // =========================================================================
    // Cache helpers
    // =========================================================================

    /** @param array<string, string> $data */
    private function writePhpCache(string $cacheFile, array $data): void
    {
        $dir = \dirname($cacheFile);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        $export = \var_export($data, true);
        $content = "<?php\n// Auto-generated translation cache — do not edit\nreturn {$export};\n";

        // Atomic write via temp file
        $tmp = $cacheFile . '.tmp.' . \getmypid();
        if (\file_put_contents($tmp, $content, \LOCK_EX) !== false) {
            \rename($tmp, $cacheFile);
            \clearstatcache(true, $cacheFile);
            if (\function_exists('opcache_invalidate')) {
                \opcache_invalidate($cacheFile, true);
            }
        }
    }

    // =========================================================================
    // Utility
    // =========================================================================

    /**
     * Flatten a nested array into dot-notation keys.
     * `['page' => ['title' => 'Foo']]` → `['page.title' => 'Foo']`
     *
     * @param  array<mixed, mixed> $array
     * @return array<string, string>
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $k => $v) {
            $key = $prefix !== '' ? $prefix . '.' . $k : (string) $k;
            if (\is_array($v)) {
                foreach ($this->flattenArray($v, $key) as $fk => $fv) {
                    $result[$fk] = $fv;
                }
            } else {
                $result[$key] = (string) $v;
            }
        }
        return $result;
    }

    /**
     * Ensure every value in the catalog is a string.
     *
     * @param  array<mixed, mixed> $data
     * @return array<string, string>
     */
    private function normalizeToStrings(array $data): array
    {
        foreach ($data as $k => $v) {
            if (!\is_string($v)) {
                $data[$k] = (string) $v;
            }
        }
        /** @var array<string, string> $data */
        return $data;
    }
}
