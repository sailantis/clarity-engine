<?php

namespace Clarity\Localization;

use MessageFormatter;

/**
 * Ultra‑performanter Translation Loader
 *
 * Erwartet Übersetzungsdateien als PHP Dateien, z. B. en_US.php:
 * <?php return ['greeting' => 'Hello, {name}!'];
 */
final class TranslationLoader
{
    /** @var array<string, array<string, string>> locale => [key => message] */
    private array $catalog = [];

    /** @var array<string, array<string, string>> resolved cache: [$locale][$key] => message */
    private array $resolved = [];

    /** @var array<string, array<string, MessageFormatter|false>> fmtCache: [$locale][$pattern] => MessageFormatter|false */
    private array $fmtCache = [];

    public function __construct(
        private readonly ?string $path,
        private readonly string $fallbackLocale = 'en_US',
    ) {
    }

    /**
     * Schneller Lookup mit Fallback und Platzhalterersetzung.
     *
     * @param string $locale
     * @param string $key
     * @param array<string,mixed> $vars
     */
    public function get(string $locale, string $key, array $vars = []): string
    {
        // resolved cache prüfen
        if (isset($this->resolved[$locale][$key])) {
            $msg = $this->resolved[$locale][$key];
        } else {
            $msg = $this->resolveAndCache($locale, $key);
        }

        if ($vars === []) {
            return $msg;
        }

        $replace_pairs = [];
        foreach ($vars as $k => $v) {
            $replace_pairs['{' . $k . '}'] = (string) $v;
        }

        return strtr($msg, $replace_pairs);
    }

    /**
     * Pluralisierte Nachricht. Nutzt MessageFormatter wenn verfügbar und cached Formatter.
     *
     * @param string $locale
     * @param string $key
     * @param int $count
     */
    public function plural(string $locale, string $key, int $count): string
    {
        // Pattern holen (ohne Platzhalterersetzung)
        if (isset($this->resolved[$locale][$key])) {
            $pattern = $this->resolved[$locale][$key];
        } else {
            $pattern = $this->resolveAndCache($locale, $key);
        }

        // Wenn intl verfügbar, MessageFormatter cachen
        if (\class_exists(MessageFormatter::class)) {
            // fmtCache key per locale + pattern
            $fmt = $this->fmtCache[$locale][$pattern] ?? null;
            if ($fmt === null) {
                // MessageFormatter kann false zurückgeben bei Fehlern, wir speichern das ebenfalls
                $created = @new MessageFormatter($locale, $pattern);
                $this->fmtCache[$locale][$pattern] = $created === false ? false : $created;
                $fmt = $this->fmtCache[$locale][$pattern];
            }

            if ($fmt !== false) {
                $result = $fmt->format(['count' => $count]);
                if ($result !== false) {
                    return $result;
                }
            }
        }

        // Minimaler Fallback: {count} ersetzen
        return strtr($pattern, ['{count}' => (string) $count]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Resolve key with fallback and cache the resolved message.
     */
    private function resolveAndCache(string $locale, string $key): string
    {
        // Versuche locale
        $cat = $this->load($locale);
        if (isset($cat[$key])) {
            return $this->resolved[$locale][$key] = $cat[$key];
        }

        // Versuche fallback
        if ($locale !== $this->fallbackLocale) {
            $fb = $this->load($this->fallbackLocale);
            if (isset($fb[$key])) {
                return $this->resolved[$locale][$key] = $fb[$key];
            }
        }

        // Letzter Fallback: key selbst
        return $this->resolved[$locale][$key] = $key;
    }

    /**
     * Lade Katalog für Locale. Erwartet PHP Dateien, die ein Array zurückgeben.
     *
     * @return array<string,string>
     */
    private function load(string $locale): array
    {
        if (isset($this->catalog[$locale])) {
            return $this->catalog[$locale];
        }

        if ($this->path === null) {
            return $this->catalog[$locale] = [];
        }

        $file = \rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . $locale . '.php';

        if (!\is_file($file)) {
            return $this->catalog[$locale] = [];
        }

        // require ist Opcache‑freundlich; die Datei sollte ein Array zurückgeben
        $data = @require $file;

        // defensive: ensure array
        if (!\is_array($data)) {
            return $this->catalog[$locale] = [];
        }

        // normalize values to string to avoid object leakage
        foreach ($data as $k => $v) {
            if (!\is_string($v)) {
                $data[$k] = (string) $v;
            }
        }

        return $this->catalog[$locale] = $data;
    }

}
