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
 * For all files, the loader generates a cached PHP file containing
 * the parsed translations for faster subsequent loading. The cache is automatically invalidated when the source file changes.
 */
class FileTranslationLoader implements TranslationLoaderInterface
{
    public function __construct(
        private string $translationsPath,
        private ?string $cachePath = null
    )
    {
        $this->translationsPath = rtrim($this->translationsPath, '/\\');
        $this->cachePath = $this->cachePath !== null ? rtrim($this->cachePath, '/\\') : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_translations';
    }

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

    /** @return array<string, string> */
    private function loadPhpFile(string $file): ?array
    {
        $data = @require $file;
        if (!\is_array($data)) {
            return null;
        }
        return $this->normalizeToStrings($this->flattenArray($data));
    }

    /** @return array<string, string> */
    private function parseJson(string $src): ?array
    {
        $decoded = \json_decode($src, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return null;
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
    * @param string $prefix
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