<?php

namespace Shipcannon\I18n;

/**
 * Find translation candidates in templates that haven't been wrapped with
 * t('...') yet. Heuristic — produces a report for human review, never
 * auto-wraps.
 *
 * Looks for:
 *   - Attribute values: placeholder, title, alt, aria-label, aria-placeholder
 *   - Element text content: <h1>..<h6>, <button>, <label>, <th>,
 *     <legend>, <caption>, <summary>, <figcaption>, <option>
 *
 * Skips:
 *   - Strings already wrapped with t('...') / __('...')  (caller's job: re-scan
 *     after wrapping)
 *   - PHP expressions (<?php / <?=) appearing inside the candidate string —
 *     those are dynamic and not safe to mass-translate
 *   - Pure numbers, URLs, paths, single chars, code-like identifiers
 *     (snake_case / kebab-case / dotted)
 */
class ViewExtractor
{
    private const ATTRS = ['placeholder', 'title', 'alt', 'aria-label', 'aria-placeholder'];

    private const TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'button', 'label', 'th', 'legend', 'caption', 'summary',
        'figcaption', 'option',
    ];

    /** @var array<int, string> */
    private array $extensions = ['php', 'html'];

    /** @var array<int, string> */
    private array $exclude = ['vendor', 'node_modules', 'cache', 'log', 'uploads', '.git', '.playwright-mcp'];

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
     * Scan one or more roots and return all candidate strings, grouped by
     * source string with the kind ("placeholder", "h1", etc.) and file/line
     * for each occurrence.
     *
     * @param string|array<int,string> $roots
     * @return array<int, array{
     *     source:string,
     *     count:int,
     *     occurrences: array<int, array{kind:string, path:string, line:int}>
     * }>
     */
    public function scan($roots): array
    {
        $roots = is_array($roots) ? $roots : [$roots];
        $bySource = []; // source => occurrences[]

        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            if (!is_dir($root)) continue;
            foreach ($this->iterateFiles($root) as $file) {
                foreach ($this->extractFromFile($file) as $hit) {
                    $bySource[$hit['source']][] = [
                        'kind' => $hit['kind'],
                        'path' => $hit['path'],
                        'line' => $hit['line'],
                    ];
                }
            }
        }

        // Sort: most-frequent first, then alpha
        $entries = [];
        foreach ($bySource as $source => $occs) {
            $entries[] = [
                'source'      => $source,
                'count'       => count($occs),
                'occurrences' => $occs,
            ];
        }
        usort($entries, function ($a, $b) {
            if ($a['count'] !== $b['count']) return $b['count'] <=> $a['count'];
            return strcasecmp($a['source'], $b['source']);
        });
        return $entries;
    }

    /**
     * @return \Generator<string>
     */
    private function iterateFiles(string $root): \Generator
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $cur) {
                    if ($cur->isDir()) {
                        return !in_array($cur->getFilename(), $this->exclude, true);
                    }
                    return in_array(
                        strtolower($cur->getExtension()),
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
     * @return array<int, array{kind:string, source:string, path:string, line:int}>
     */
    private function extractFromFile(string $path): array
    {
        $code = @file_get_contents($path);
        if ($code === false || $code === '') return [];

        $found = [];
        $found = array_merge($found, $this->extractAttributes($code, $path));
        $found = array_merge($found, $this->extractTagText($code, $path));
        return $found;
    }

    /**
     * @return array<int, array{kind:string, source:string, path:string, line:int}>
     */
    private function extractAttributes(string $code, string $path): array
    {
        $found = [];
        $attrs = implode('|', array_map('preg_quote', self::ATTRS));
        // Match name="value" or name='value', no embedded <>.
        $pattern = '/(?<![A-Za-z0-9_-])(' . $attrs . ')\s*=\s*'
                 . '(?:"([^"<>]*)"|\'([^\'<>]*)\')/i';
        if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $idx => $whole) {
                $kind = strtolower($m[1][$idx][0]);
                $val = $m[2][$idx][0] !== '' ? $m[2][$idx][0] : $m[3][$idx][0];
                if (!$this->isLikelyTranslatable($val)) continue;
                $line = substr_count(substr($code, 0, $whole[1]), "\n") + 1;
                $found[] = [
                    'kind'   => $kind,
                    'source' => $val,
                    'path'   => $path,
                    'line'   => $line,
                ];
            }
        }
        return $found;
    }

    /**
     * @return array<int, array{kind:string, source:string, path:string, line:int}>
     */
    private function extractTagText(string $code, string $path): array
    {
        $found = [];
        foreach (self::TAGS as $tag) {
            // Non-greedy across newlines, no nested same-tag (rare in templates).
            $pattern = '/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is';
            if (!preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[1] as $inner) {
                $raw = $inner[0];
                $offset = $inner[1];
                // Already wrapped with a translate call — skip
                if ($this->containsTranslateCall($raw)) continue;
                // Strip nested tags + whitespace, then test
                $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5));
                if (!$this->isLikelyTranslatable($text)) continue;
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $found[] = [
                    'kind'   => $tag,
                    'source' => $text,
                    'path'   => $path,
                    'line'   => $line,
                ];
            }
        }
        return $found;
    }

    private function containsTranslateCall(string $s): bool
    {
        // Match common forms: t('x'), t("x"), __('x') (also picks up the
        // PHP-short-echo prefixed variants).
        return (bool)preg_match('/(?:^|[^A-Za-z0-9_])(?:t|__)\s*\(/', $s);
    }

    private function isLikelyTranslatable(string $s): bool
    {
        if ($s === '' || trim($s) === '') return false;
        $s = trim($s);

        // Embedded PHP expressions — dynamic, skip rather than mass-translate
        if (str_contains($s, '<?')) return false;

        // Length sanity
        if (mb_strlen($s) < 2) return false;
        if (mb_strlen($s) > 250) return false;

        // Must contain at least one letter
        if (!preg_match('/[A-Za-z]/u', $s)) return false;

        // Pure number / currency / percentage strings
        if (preg_match('/^[\s\d.,$%+\-:×x*\/]+$/u', $s)) return false;

        // URLs, mailto, paths
        if (preg_match('/^(?:https?:\/\/|mailto:|tel:|\/|#|\?)/i', $s)) return false;

        // Code-like identifiers (snake_case, kebab-case, dotted, no spaces, all lowercase)
        if (!preg_match('/\s/', $s) && !preg_match('/[A-Z]/', $s) && preg_match('/^[a-z0-9_\-\.]+$/', $s)) {
            return false;
        }

        // Looks like CSS class string ("bi bi-arrow-right" / "btn btn-primary")
        if (preg_match('/^(?:[a-z][a-z0-9_-]*\s+){1,}[a-z][a-z0-9_-]*$/', $s)) {
            return false;
        }

        // Mostly punctuation / glyphs (e.g. " · ")
        $alpha = preg_match_all('/[A-Za-z]/u', $s);
        if ($alpha < 2) return false;

        return true;
    }
}
