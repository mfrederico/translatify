<?php

namespace Translatify;

/**
 * Scan a project tree for translation calls and produce a list of unique
 * source strings. Used by the editor to surface "untranslated keys still
 * referenced in code" and by the bin/i18n-scan CLI to bootstrap a fresh
 * en.json from an existing codebase.
 *
 * Recognized call shapes (handled with a real PHP tokenizer for .php files,
 * regex fallback for .js):
 *
 *   t('Save')
 *   t("Hello :name", ['name' => $x])
 *   __('Save')                                  ← optional Laravel-style alias
 *   {{ t("Save") }}
 *
 * NB: only string-literal first arguments are extracted. `t($var)` is skipped
 * because we can't know what string $var holds.
 */
class Scanner
{
    /** @var array<int, string> Function names to extract */
    private array $functions = ['t', '__'];

    /** @var array<int, string> File extensions to scan */
    private array $extensions = ['php', 'js'];

    /** @var array<int, string> Directories to skip (relative or absolute) */
    private array $exclude = ['vendor', 'node_modules', 'cache', 'log', 'uploads', '.git'];

    public function withFunctions(array $names): self
    {
        $this->functions = $names;
        return $this;
    }

    public function withExtensions(array $exts): self
    {
        $this->extensions = $exts;
        return $this;
    }

    public function withExclude(array $dirs): self
    {
        $this->exclude = $dirs;
        return $this;
    }

    /**
     * Scan one or more roots, returning unique source strings sorted alpha.
     *
     * @param string|array<int,string> $roots
     * @return array<int, array{source:string, files: array<int, array{path:string, line:int}>}>
     */
    public function scan($roots): array
    {
        $roots = is_array($roots) ? $roots : [$roots];
        $hits = []; // source => [['path' => ..., 'line' => ...], ...]

        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            if (!is_dir($root)) continue;
            foreach ($this->iterateFiles($root) as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $found = $ext === 'php'
                    ? $this->extractFromPhp($file)
                    : $this->extractFromText($file);
                foreach ($found as $entry) {
                    $hits[$entry['source']][] = ['path' => $file, 'line' => $entry['line']];
                }
            }
        }

        ksort($hits);
        $result = [];
        foreach ($hits as $source => $files) {
            $result[] = ['source' => $source, 'files' => $files];
        }
        return $result;
    }

    /**
     * Scan and merge any new sources into a locale's JSON. Existing
     * translations are preserved; new keys get the source string as default
     * (English-as-key convention, so en.json starts useful out of the box).
     *
     * @param string|array<int,string> $roots
     * @return array{added: array<int,string>, total: int}
     */
    public function syncToLocale(Editor $editor, $roots, string $locale = 'en'): array
    {
        $found = $this->scan($roots);
        $dict = $editor->loadLocale($locale);
        $added = [];
        foreach ($found as $entry) {
            if (!array_key_exists($entry['source'], $dict)) {
                $dict[$entry['source']] = $entry['source']; // default value
                $added[] = $entry['source'];
            }
        }
        if (!empty($added)) {
            $editor->saveLocale($locale, $dict);
        }
        return ['added' => $added, 'total' => count($found)];
    }

    /**
     * @return \Generator<string>
     */
    private function iterateFiles(string $root): \Generator
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) {
                    if ($current->isDir()) {
                        $name = $current->getFilename();
                        return !in_array($name, $this->exclude, true);
                    }
                    return in_array(
                        strtolower($current->getExtension()),
                        $this->extensions,
                        true
                    );
                }
            )
        );
        foreach ($iter as $f) {
            if ($f->isFile()) yield $f->getPathname();
        }
    }

    /**
     * Extract from PHP using the real tokenizer — handles concatenation,
     * heredoc, mixed quote styles, comments, etc.
     *
     * @return array<int, array{source:string, line:int}>
     */
    private function extractFromPhp(string $path): array
    {
        $code = @file_get_contents($path);
        if ($code === false) return [];
        $tokens = @token_get_all($code);
        if (!is_array($tokens)) return [];

        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            // Look for T_STRING matching one of our function names
            if (!is_array($tok) || $tok[0] !== T_STRING) continue;
            if (!in_array($tok[1], $this->functions, true)) continue;

            // Skip if preceded by ::, ->, or function/new — that's a method or
            // declaration, not a function call.
            $prev = $this->prevSignificant($tokens, $i);
            if ($prev !== null) {
                if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NULLSAFE_OBJECT_OPERATOR ?? -1], true)) {
                    continue;
                }
            }

            // Next significant token must be '('
            $next = $this->nextSignificantIndex($tokens, $i);
            if ($next === null || $tokens[$next] !== '(') continue;

            // Read the first literal-string argument inside the parens
            $arg = $this->readFirstStringArgument($tokens, $next + 1);
            if ($arg === null) continue;

            $found[] = ['source' => $arg, 'line' => $tok[2]];
        }
        return $found;
    }

    /**
     * Read a single quoted string literal that starts at $tokens[$start]
     * (skipping whitespace). Returns null if the first non-whitespace token
     * is not a string literal (i.e. we hit a variable, expression, etc.).
     */
    private function readFirstStringArgument(array $tokens, int $start): ?string
    {
        for ($j = $start; $j < count($tokens); $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                // PHP string literal incl. quotes — strip them and unescape
                return $this->unquotePhpString($t[1]);
            }
            // Anything else (variable, expression, etc.) — bail
            return null;
        }
        return null;
    }

    private function unquotePhpString(string $raw): string
    {
        if (strlen($raw) < 2) return $raw;
        $quote = $raw[0];
        $inner = substr($raw, 1, -1);
        if ($quote === "'") {
            // Single-quote: only \' and \\ are interpreted
            return strtr($inner, ["\\'" => "'", '\\\\' => '\\']);
        }
        // Double-quote: handle common escapes via stripcslashes (close enough
        // for human-written translatable strings)
        return stripcslashes($inner);
    }

    private function prevSignificant(array $tokens, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $t;
        }
        return null;
    }

    private function nextSignificantIndex(array $tokens, int $i): ?int
    {
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }
        return null;
    }

    /**
     * Regex-based extractor for non-PHP files (JS).
     * Catches: t('...') / t("...") / __('...') / __("...")
     * Skips template literals (backticks) on purpose — they often contain
     * runtime values and false positives outweigh missed strings.
     *
     * @return array<int, array{source:string, line:int}>
     */
    private function extractFromText(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) return [];

        $names = implode('|', array_map('preg_quote', $this->functions));
        $pattern = '/\b(?:' . $names . ')\(\s*'
                 . '(?:'
                 . "'((?:\\\\.|[^'\\\\])*)'"   // 'single quoted'
                 . '|'
                 . '"((?:\\\\.|[^"\\\\])*)"'   // "double quoted"
                 . ')'
                 . '/m';

        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) return [];

        $out = [];
        foreach ($matches[0] as $idx => $whole) {
            $offset = $whole[1];
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $raw = $matches[1][$idx][0] !== ''
                ? stripcslashes($matches[1][$idx][0])
                : stripcslashes($matches[2][$idx][0]);
            if ($raw === '') continue;
            $out[] = ['source' => $raw, 'line' => $line];
        }
        return $out;
    }
}
