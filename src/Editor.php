<?php

namespace Shipcannon\I18n;

/**
 * Read/write JSON dictionaries for the translation editor UI.
 *
 * Each dictionary is a flat object: { "English source": "Translation" }.
 * The editor partial calls these methods through a host-project route so
 * the host controls auth.
 */
class Editor
{
    private string $langDir;

    public function __construct(?string $langDir = null)
    {
        $this->langDir = rtrim($langDir ?? Translator::instance()->getLangDir(), '/');
    }

    /**
     * List locales by scanning the lang directory for *.json files.
     *
     * @return array<int, string> e.g. ['en', 'es', 'fr']
     */
    public function listLocales(): array
    {
        if (!is_dir($this->langDir)) return [];
        $codes = [];
        foreach (glob($this->langDir . '/*.json') ?: [] as $path) {
            $code = basename($path, '.json');
            if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code)) {
                $codes[] = $code;
            }
        }
        sort($codes);
        return $codes;
    }

    /**
     * Load a locale's full dictionary.
     *
     * @return array<string, string>
     */
    public function loadLocale(string $locale): array
    {
        $path = $this->pathFor($locale);
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Atomically save a dictionary. Sorts keys alphabetically for sane diffs.
     *
     * @param array<string, string> $dict
     */
    public function saveLocale(string $locale, array $dict): void
    {
        $this->assertWritable();
        ksort($dict);
        $path = $this->pathFor($locale);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode(
            $dict,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new \RuntimeException('Failed to encode locale JSON');
        }
        if (@file_put_contents($tmp, $json . "\n") === false) {
            throw new \RuntimeException("Failed to write {$tmp}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to atomically replace {$path}");
        }
    }

    /**
     * Set a single key in a locale (creating the file if missing).
     */
    public function setKey(string $locale, string $source, string $translation): void
    {
        $dict = $this->loadLocale($locale);
        $dict[$source] = $translation;
        $this->saveLocale($locale, $dict);
    }

    /**
     * Remove a key from a locale.
     */
    public function removeKey(string $locale, string $source): void
    {
        $dict = $this->loadLocale($locale);
        if (array_key_exists($source, $dict)) {
            unset($dict[$source]);
            $this->saveLocale($locale, $dict);
        }
    }

    /**
     * Create a new locale file (empty dictionary).
     */
    public function createLocale(string $locale): void
    {
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)) {
            throw new \InvalidArgumentException("Invalid locale code '{$locale}' — use 'es' or 'es-MX' format");
        }
        $path = $this->pathFor($locale);
        if (is_file($path)) {
            throw new \RuntimeException("Locale '{$locale}' already exists");
        }
        $this->saveLocale($locale, []);
    }

    /**
     * Build the editor's row data: every key from base locale (English),
     * with each locale's translation alongside. Keys missing in a locale
     * surface as empty strings so the UI shows them as untranslated.
     *
     * @return array{
     *   locales: array<int,string>,
     *   rows:    array<int, array{source:string, translations: array<string,string>}>
     * }
     */
    public function buildRowData(string $baseLocale = 'en'): array
    {
        $locales = $this->listLocales();
        if (!in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
            sort($locales);
        }

        // Union of all keys across all locales (so "extra" keys in a
        // non-base locale are still editable).
        $allKeys = [];
        $byLocale = [];
        foreach ($locales as $code) {
            $dict = $this->loadLocale($code);
            $byLocale[$code] = $dict;
            foreach (array_keys($dict) as $k) $allKeys[$k] = true;
        }
        $keys = array_keys($allKeys);
        sort($keys);

        $rows = [];
        foreach ($keys as $source) {
            $translations = [];
            foreach ($locales as $code) {
                $translations[$code] = $byLocale[$code][$source] ?? '';
            }
            $rows[] = ['source' => $source, 'translations' => $translations];
        }

        return ['locales' => $locales, 'rows' => $rows];
    }

    private function pathFor(string $locale): string
    {
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)) {
            throw new \InvalidArgumentException("Invalid locale code '{$locale}'");
        }
        return $this->langDir . '/' . $locale . '.json';
    }

    private function assertWritable(): void
    {
        if (!is_dir($this->langDir)) {
            if (!@mkdir($this->langDir, 0755, true)) {
                throw new \RuntimeException("Cannot create lang dir {$this->langDir}");
            }
        }
        if (!is_writable($this->langDir)) {
            throw new \RuntimeException("Lang dir {$this->langDir} is not writable");
        }
    }
}
