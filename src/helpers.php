<?php

use Translatify\Translator;

if (!function_exists('t')) {
    /**
     * Translate a source string using the global Translator instance.
     * Missing translations fall back to the source string.
     *
     * @param string               $source English source string (also the JSON key)
     * @param array<string, mixed> $vars   Variables for :name or ICU MessageFormat
     */
    function t(string $source, array $vars = []): string
    {
        return Translator::instance()->translate($source, $vars);
    }
}
