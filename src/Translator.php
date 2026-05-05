<?php

namespace Shipcannon\I18n;

/**
 * Tiny English-as-key translator.
 *
 * Usage:
 *   Translator::register('/path/to/lang')
 *       ->setLocale('es')
 *       ->setFallback('en');
 *
 *   echo t('Save changes');
 *   echo t('Hi :name', ['name' => 'Matt']);
 *   echo t('{count, plural, =0{No items} =1{One item} other{# items}}',
 *          ['count' => 5]);
 *
 * Translations live in JSON files keyed by source string:
 *   lang/en.json:  {"Save changes": "Save changes"}
 *   lang/es.json:  {"Save changes": "Guardar cambios"}
 *
 * Missing keys fall back to the source string, so untranslated UI just
 * renders English instead of throwing.
 */
class Translator
{
    private static ?Translator $instance = null;

    private string $langDir;
    private string $locale = 'en';
    private string $fallback = 'en';

    /** @var array<string, array<string, string>> Loaded locale dictionaries by code */
    private array $loaded = [];

    private function __construct(string $langDir)
    {
        $this->langDir = rtrim($langDir, '/');
    }

    /**
     * Initialize the global translator. Subsequent calls reset the directory.
     */
    public static function register(string $langDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($langDir);
        } else {
            self::$instance->langDir = rtrim($langDir, '/');
            self::$instance->loaded = [];
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            // Allow t() to work even without explicit registration — falls
            // back to source string with no JSON loaded.
            self::$instance = new self(sys_get_temp_dir());
        }
        return self::$instance;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function setFallback(string $locale): self
    {
        $this->fallback = $locale;
        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getLangDir(): string
    {
        return $this->langDir;
    }

    /**
     * Translate a source string. Missing translations fall back to the source.
     *
     * @param string               $source English source string (also the JSON key)
     * @param array<string, mixed> $vars   Variables for :name or ICU MessageFormat
     */
    public function translate(string $source, array $vars = []): string
    {
        $translated = $this->lookup($source, $this->locale)
                   ?? $this->lookup($source, $this->fallback)
                   ?? $source;

        if (empty($vars)) {
            return $translated;
        }

        // ICU MessageFormat path: detect by '{' which neither :name nor plain
        // strings ever contain. MessageFormat handles plurals, gender, select.
        if (strpos($translated, '{') !== false && class_exists(\MessageFormatter::class)) {
            $fmt = \MessageFormatter::create($this->locale, $translated);
            if ($fmt !== null) {
                $result = $fmt->format($vars);
                if ($result !== false) {
                    return $result;
                }
            }
        }

        // Simple :name interpolation (Laravel-style). Skip keys that aren't
        // alphanumeric to avoid eating colons inside the message.
        $replacements = [];
        foreach ($vars as $k => $v) {
            if (!is_string($k) || !preg_match('/^[A-Za-z0-9_]+$/', $k)) continue;
            $replacements[':' . $k] = (string)$v;
        }
        return $replacements ? strtr($translated, $replacements) : $translated;
    }

    private function lookup(string $source, string $locale): ?string
    {
        $dict = $this->load($locale);
        $val = $dict[$source] ?? null;
        return ($val !== null && $val !== '') ? $val : null;
    }

    /**
     * @return array<string, string>
     */
    private function load(string $locale): array
    {
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }
        $path = $this->langDir . '/' . $locale . '.json';
        if (!is_file($path)) {
            return $this->loaded[$locale] = [];
        }
        $raw = @file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return $this->loaded[$locale] = is_array($data) ? $data : [];
    }
}
