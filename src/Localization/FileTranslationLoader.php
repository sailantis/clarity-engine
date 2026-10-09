<?php
namespace Clarity\Localization;

/**
 * File-based translation loader with support for PHP, JSON, and YAML formats.
 *
 * This loader looks for translation files in a specified directory following the
 * naming convention `{domain}.{locale}.{ext}` (e.g. `messages.de_DE.yaml`).
 *
 * Supported formats:
 *   - YAML: flat or nested key → message mappings (nested keys flattened to dot notation).
 *   - JSON: flat or nested key → message mappings (nested keys flattened to dot notation).
 *   - PHP: flat or nested key → message mappings (nested keys flattened to dot notation).
 *
 * Only the first existing file for a domain and locale is loaded, in the order
 * `.yaml`, `.yml`, `.json`, `.php`.
 *
 * Each source file is compiled to a PHP cache file. The cache is reused while it is
 * at least as new as its source and is regenerated when the source file is newer.
 */
class FileTranslationLoader implements TranslationLoaderInterface
{
    use CatalogNormalizationTrait;

    /**
     * @param string      $translationsPath Directory containing the translation files.
     * @param string|null $cachePath        Directory for generated caches. Defaults to
     *                                      `sys_get_temp_dir()/clarity_translations/<md5 of translationsPath>`.
     */
    public function __construct(
        private string $translationsPath,
        private ?string $cachePath = null
    ) {
        $this->translationsPath = rtrim($this->translationsPath, '/\\');
        if ($this->cachePath === null) {
            $this->cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_translations' . DIRECTORY_SEPARATOR . md5($this->translationsPath);
        }
        $this->cachePath = rtrim($this->cachePath, '/\\');
    }

    /**
     * @return array<string, string>
     * @throws \RuntimeException If a source file is unreadable or yields no message table.
     * @throws \JsonException    If a JSON source file is malformed.
     */
    public function load(string $domain, string $locale): array
    {
        $base = $this->translationsPath . DIRECTORY_SEPARATOR . $domain . '.' . $locale;

        foreach (['.yaml', '.yml'] as $ext) {
            if (is_file($base . $ext)) {
                return $this->loadViaCachePhp(
                    $base . $ext,
                    fn(string $src) => YamlParser::parse($src)
                );
            }
        }

        if (is_file($base . '.json')) {
            return $this->loadViaCachePhp(
                $base . '.json',
                fn(string $src) => $this->parseJson($src)
            );
        }

        if (is_file($base . '.php')) {
            return $this->loadViaCachePhp(
                $base . '.php',
                fn(string $src) => $this->loadPhpFile($base . '.php')
            );
        }

        return [];
    }

    // =========================================================================
    // Format-specific helpers
    // =========================================================================

    /** @return array<string, string>|null */
    private function loadPhpFile(string $file): ?array
    {
        $data = @require $file;
        if (!\is_array($data)) {
            return null;
        }
        return $this->flattenCatalog($data);
    }

    /** @return array<string, string>|null */
    private function parseJson(string $src): ?array
    {
        $decoded = \json_decode($src, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return null;
        }
        return $this->flattenCatalog($decoded);
    }

    /**
     * Load a source file through its PHP cache, regenerating the cache when the
     * source is newer.
     *
     * @param  string   $sourceFile Absolute path to the source file (YAML, JSON, or PHP).
     * @param  callable $parser     fn(string $content): array<string,string>|null. Returns null
     *                              when the content is unusable. PHP sources are loaded by
     *                              file path, so the content argument is not used.
     * @return array<string, string>
     * @throws \RuntimeException If the source file is missing or the parser returns null.
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

        /** @var array<string, string>|null $data */
        $data = $parser($src);
        if ($data === null) {
            throw new \RuntimeException("Translation file '{$sourceFile}' did not return a usable message table.");
        }
        $this->writePhpCache($cacheFile, $data);

        return $data;
    }

    // =========================================================================
    // Cache helpers
    // =========================================================================

    /**
     * @param string $cacheFile
     * @param array<string, string> $data
     */
    private function writePhpCache(string $cacheFile, array $data): void
    {
        $dir = \dirname($cacheFile);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        $export  = \var_export($data, true);
        $content = "<?php\n// Auto-generated translation cache — do not edit\nreturn {$export};\n";

        // Atomic write via temp file
        $tmp = $cacheFile . '.tmp.' . \getmypid();
        if (\file_put_contents($tmp, $content, \LOCK_EX) !== false) {
            if (!@\rename($tmp, $cacheFile)) {
                @\unlink($tmp);
                return;
            }
            \clearstatcache(true, $cacheFile);
            if (\function_exists('opcache_invalidate')) {
                \opcache_invalidate($cacheFile, true);
            }
        }
    }

}
