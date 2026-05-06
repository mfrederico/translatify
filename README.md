# translatify

A tiny English-as-key i18n helper + scanner + drop-in editor for
FlightPHP / RedBean PHP projects. ~600 lines of code total, designed
to be shared across sibling apps via a local Composer path repo.

- **English-as-key**: `<?= t('Save changes') ?>` instead of dotted keys
- **Safe gradual rollout**: missing translations fall back to the source string
- **Drop-in admin editor**: a single PHP partial, no JS framework, theme-aware
- **Two CLIs**: scan code for `t()` calls; find candidate strings to wrap
- **Browser companion**: same JSON dictionaries, ~70 lines of JS
- **ICU MessageFormat support** for plurals, gender, select

## TL;DR

```php
// 1. Bootstrap once per request
\Translatify\Translator::register(__DIR__ . '/lang')
    ->setLocale($member->locale ?? 'en')
    ->setFallback('en');

// 2. Wrap strings as you touch them
<?= t('Save changes') ?>
<?= t('Hi :name', ['name' => $user->first_name]) ?>
<?= t('{n, plural, =0{No items} =1{One item} other{# items}}', ['n' => $count]) ?>

// 3. Run the scanner once a session, translate inline at /translations
$ vendor/bin/i18n-scan --root=views,controls,services --lang=lang
```

---

## Install

`translatify` is intended to be required as a local Composer package
shared across sibling apps. Clone it next to your projects:

```
~/development/
  ├── translatify/
  ├── cannonwms/
  └── dealerportal/
```

Then in each host project's `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "../translatify", "options": { "symlink": true } }
  ],
  "require": {
    "translatify/translatify": "@dev"
  }
}
```

…and `composer require translatify/translatify:@dev`.

If you ever rename or move the package, **delete `vendor/bin/i18n-*`
and run `composer install`** in each host project — the bin proxy
generated at install time hard-codes the package path and will 404
otherwise.

### Requirements

- PHP **8.1+**
- ext-json
- ext-intl (for ICU MessageFormat plurals + `Locale::getDisplayLanguage`)

---

## Components

| File | Role |
|---|---|
| `src/Translator.php` | Core translator — register, setLocale, translate |
| `src/helpers.php` | Global `t($source, $vars = [])` function |
| `src/Editor.php` | Read/write JSON dictionaries (used by the editor UI) |
| `src/Scanner.php` | Extract `t('...')` calls from PHP/JS into `en.json` |
| `src/ViewExtractor.php` | Find candidate strings (placeholders, `<h1>`, `<button>`, etc.) for the human to wrap |
| `assets/i18n.js` | Browser companion — same JSON contract |
| `views/editor.php` | Drop-in editor UI (PHP partial) |
| `bin/i18n-scan` | CLI: scan code for `t()` calls, merge new keys into `en.json` |
| `bin/i18n-find-untranslated` | CLI: triage report of un-wrapped candidate strings |

---

## Bootstrap

Wire the translator once per request, before any `t()` call. In a
FlightPHP app this typically lives in `Bootstrap.php` after sessions
are started:

```php
private function initI18n(): void {
    $langDir = dirname(__DIR__) . '/lang';
    if (!is_dir($langDir)) @mkdir($langDir, 0755, true);

    $locale = 'en';
    if (!empty($_SESSION['member']['locale'])) {
        $locale = (string)$_SESSION['member']['locale'];
    } elseif (!empty($_GET['lang']) && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $_GET['lang'])) {
        $locale = $_GET['lang'];   // ?lang=es override for testing
    }

    \Translatify\Translator::register($langDir)
        ->setLocale($locale)
        ->setFallback('en');
}
```

The `?lang=xx` override is useful for QA without logging in/out.

---

## Use the helper

```php
<?= t('Save changes') ?>
<?= t('Welcome, :name!', ['name' => $user->first_name]) ?>
<?= t('Hi :name, you have :count notes', [
    'name'  => $user->first_name,
    'count' => $count
]) ?>
```

ICU MessageFormat (auto-detected by the presence of `{`):

```php
<?= t('{n, plural, =0{No items} =1{One item} other{# items}}', ['n' => $count]) ?>

<?= t('{gender, select, female{She liked it} male{He liked it} other{They liked it}}', [
    'gender' => $user->gender
]) ?>
```

Inside HTML attributes — wrap with `htmlspecialchars` because the
helper itself doesn't escape:

```html
<input placeholder="<?= htmlspecialchars(t('Search...'), ENT_QUOTES) ?>">
```

---

## Workflow: bringing a new view online

1. **Find candidates** in raw templates that haven't been wrapped yet:

   ```sh
   vendor/bin/i18n-find-untranslated --root=views --limit=50
   ```

   Reports things like:

   ```
     14x  Save Changes
            views/admin/member.php:256       (button)
            views/channels/edit.php:262      (button)
            ...
     16x  Email
            views/admin/index.php:86         (th)
            ...
   ```

2. **Wrap by hand**. The tool is intentionally read-only; auto-wrapping
   is a footgun (false positives, dynamic strings, etc.).

   ```diff
   - <button class="btn btn-primary">Save Changes</button>
   + <button class="btn btn-primary"><?= t('Save Changes') ?></button>
   ```

3. **Scan** to add new keys to `en.json`:

   ```sh
   vendor/bin/i18n-scan --root=views,controls,services --lang=lang
   ```

   Output:
   ```
   Found 9 source string(s); added 7 new key(s) to lang/en.json
     + Email
     + Email address
     ...
   ```

4. **Translate inline** at `/translations` (or wherever you mounted the
   editor). New keys appear with empty fields for each non-base locale;
   blur or hit Enter to save atomically.

5. **Test** with `?lang=es`. Untranslated strings render English so
   nothing breaks — keep migrating views one at a time.

---

## CLIs

### `i18n-scan`

Scans PHP/JS source for `t('...')` and `__('...')` calls (string-literal
first arg only — `t($var)` is skipped) and merges any new keys into a
locale's JSON dictionary.

```sh
vendor/bin/i18n-scan --root=views,controls,services --lang=lang [--locale=en] [--dry-run]
```

PHP files are parsed with the real tokenizer (handles concatenation,
heredoc, mixed quote styles, comments). JS files use a regex.

### `i18n-find-untranslated`

Triage report for strings that *should be* translatable but haven't
been wrapped yet. Looks at:

- Attributes: `placeholder`, `title`, `alt`, `aria-label`, `aria-placeholder`
- Element text content: `<h1>`–`<h6>`, `<button>`, `<label>`, `<th>`,
  `<legend>`, `<caption>`, `<summary>`, `<figcaption>`, `<option>`

Skips PHP expressions inside the candidate, URLs, code-like
identifiers, pure numbers, and short noise.

```sh
vendor/bin/i18n-find-untranslated --root=views --limit=50
vendor/bin/i18n-find-untranslated --root=views,controls --json > candidates.json
```

This **never auto-wraps** — it produces a list for you to review.

---

## Drop-in admin editor

The editor is a single PHP partial (`views/editor.php`). The host
project mounts its own auth-checked controller + route, then includes
the partial.

### Host controller (skeleton)

```php
namespace app;

use app\BaseControls\Control;
use Translatify\Editor;
use Translatify\Scanner;
use Flight as Flight;

class Translations extends Control
{
    private function langDir(): string {
        return dirname(__DIR__, 2) . '/lang';
    }

    public function index(): void {
        if (!$this->requireAdmin()) return;
        $editor = new Editor($this->langDir());
        $data = $editor->buildRowData('en');
        $this->render('translations/index', [
            'rows'         => $data['rows'],
            'locales'      => $data['locales'],
            'baseLocale'   => 'en',
            'saveUrl'      => '/translations/save',
            'newLocaleUrl' => '/translations/newlocale',
            'scanUrl'      => '/translations/scan',
            'csrfToken'    => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    public function save(): void {
        if (!$this->requireAdmin()) return;
        if (!$this->validateCSRF()) return;

        $action = (string)$this->getParam('action', '');
        $editor = new Editor($this->langDir());
        try {
            if ($action === 'set') {
                $locale = (string)$this->getParam('locale', '');
                $source = (string)$this->getParam('source', '');
                $translation = (string)$this->getParam('translation', '');
                if ($source === '' || $locale === '') {
                    throw new \InvalidArgumentException('locale and source required');
                }
                if ($translation === '') {
                    $editor->removeKey($locale, $source);
                } else {
                    $editor->setKey($locale, $source, $translation);
                }
                $this->jsonSuccess(['locale' => $locale, 'source' => $source]);
                return;
            }
            if ($action === 'remove_all') {
                $source = (string)$this->getParam('source', '');
                if ($source === '') throw new \InvalidArgumentException('source required');
                foreach ($editor->listLocales() as $code) {
                    $editor->removeKey($code, $source);
                }
                $this->jsonSuccess(['source' => $source]);
                return;
            }
            $this->jsonError("Unknown action '{$action}'", 400);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function newlocale(): void {
        if (!$this->requireAdmin()) return;
        $this->validateCSRF();
        $code = trim((string)$this->getParam('locale', ''));
        try {
            (new Editor($this->langDir()))->createLocale($code);
            $this->flash('success', "Locale '{$code}' created");
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
        }
        \Flight::redirect('/translations');
    }

    public function scan(): void {
        if (!$this->requireAdmin()) return;
        $this->validateCSRF();

        $editor = new Editor($this->langDir());
        $scanner = new Scanner();
        $roots = [
            dirname(__DIR__, 2) . '/views',
            dirname(__DIR__, 2) . '/controls',
            dirname(__DIR__, 2) . '/services',
        ];
        try {
            $result = $scanner->syncToLocale($editor, $roots, 'en');
            $this->flash('success',
                "Scanned source — {$result['total']} unique strings, "
                . count($result['added']) . " new key(s) added to en.json"
            );
        } catch (\Exception $e) {
            $this->flash('error', 'Scan failed: ' . $e->getMessage());
        }
        \Flight::redirect('/translations');
    }
}
```

### Host view

```php
<!-- views/translations/index.php -->
<h1>Translations</h1>
<?php include dirname(__DIR__, 2) . '/vendor/translatify/translatify/views/editor.php'; ?>
```

### Editor behavior

- Inputs save **on Tab and Enter** (Enter calls `.blur()`)
- Subtle status accents: yellow left border = untranslated, blue =
  saving, green = saved (~1.5s flash), red = error
- Inputs use Bootstrap CSS variables (`--bs-body-bg`, `--bs-body-color`)
  so they adapt to light/dark themes
- Empty translations call `removeKey()` — falling back to the source
- Each save is atomic (write to `*.tmp.xxxxx`, then `rename()`)

---

## Per-member language picker

A common pattern: let each user pick their UI language. Add a `locale`
column to your `member` (or `user`) table, and show a dropdown of
whatever JSON files exist:

```php
// In your profile controller
$availableLocales = [];
$codes = (new \Translatify\Editor(__DIR__ . '/../lang'))->listLocales();
if (!in_array('en', $codes, true)) array_unshift($codes, 'en');
foreach ($codes as $code) {
    $name = $code;
    if (class_exists(\Locale::class)) {
        $native = \Locale::getDisplayLanguage($code, $code);
        if ($native) $name = ucfirst($native);
    }
    $availableLocales[$code] = $name;  // "Español", "English", "Français", ...
}
```

```html
<!-- In the profile view -->
<select name="locale">
    <?php foreach ($availableLocales as $code => $name): ?>
    <option value="<?= htmlspecialchars($code) ?>"
        <?= ($member->locale ?? 'en') === $code ? 'selected' : '' ?>>
        <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($code) ?>)
    </option>
    <?php endforeach; ?>
</select>
```

When the user saves the form, write to `member.locale`. The next
request re-runs `Bootstrap::initI18n()`, picks up `$_SESSION['member']['locale']`,
and renders translated.

`Locale::getDisplayLanguage($code, $code)` returns the language's name
*in its own language* — so each option reads naturally to whoever
speaks it.

---

## Browser companion

`assets/i18n.js` is ~70 lines and uses the same JSON contract:

```html
<script src="/path/to/i18n.js"></script>
<script>
  I18n.configure({ locale: 'es', baseUrl: '/lang' });
  I18n.load().then(() => {
    document.title = t('Dashboard');
    toast.show(t('Saved'));
  });
</script>
```

Same `:name` interpolation. For ICU plurals on the client, drop in
[`messageformat-runtime`](https://www.npmjs.com/package/messageformat-runtime)
or similar and pass strings through it after translation.

---

## JSON dictionaries

`lang/en.json`, `lang/es.json`, `lang/es-MX.json`, etc. — flat
`{source: translation}` maps, sorted alphabetically:

```json
{
    "Email address": "Correo electrónico",
    "Forgot password?": "¿Olvidó su contraseña?",
    "Password": "Contraseña",
    "Sign in": "Iniciar sesión"
}
```

The file *can* contain extra keys not present in `en.json` (left over
from a refactor) — `buildRowData()` shows them anyway so you can clean
up. The Editor's atomic save sorts keys, so JSON diffs stay sane in
git.

---

## Why English-as-key?

- **Templates read naturally**: `<?= t('Save changes') ?>` vs `<?= t('btn.save') ?>`
- **No collision in the common case** — most app strings are unique
- **New strings just work** — fallback returns the source so half-done
  migrations never blank-out the UI
- **Easy refactor** — global search-and-replace works
- **Translators see the source** — context arrives for free

The cost: when you have two contextually-different strings that are
identical in English (`"Order"` the noun vs `"Order"` the verb), you
either accept the collision or pick a slightly different source string
(`"New order"` vs `"Order this item"`).

---

## Architecture notes

### `Translator::register()` is a singleton

The class has one global instance. `t()` calls always go through
`Translator::instance()`. Calling `register()` again resets the lang
directory but keeps the singleton — useful in tests, irrelevant in
production.

If `t()` is called before `register()`, it returns the source string
(the singleton lazily creates itself with no lang dir).

### Auto-permission gotcha (FlightPHP-specific)

If your host project has an auto-permission system that creates
`authcontrol` rows on first hit at the *current user's level*, a ROOT
user hitting `/translations` first will pin the row to ROOT and lock
out admins. The fix in your `PermissionCache::createPermission()` is
to look up `control::*` first and inherit *that* level if present.
(See cannonwms `lib/PermissionCache.php` for the full implementation.)

### Bootstrap timing

The editor's drop-in partial uses Bootstrap 5 classes and CSS
variables. It assumes the host page has Bootstrap loaded. If your
project doesn't, adjust the styles inline at the top of the partial.

---

## Roadmap / open questions

- **Pluggable storage**: Editor + Translator both bottleneck on
  filesystem JSON. Swapping in S3/Wasabi/DB-backed stores is a small
  refactor.
- **Strings extracted from JS**: Scanner picks up `t('...')` in `.js`
  via regex; doesn't handle template literals on purpose (too many
  false positives from runtime expressions).
- **Auto-wrap tool**: deliberately not built. False positives are
  expensive and hard to spot. Use `i18n-find-untranslated` and wrap by
  hand.

---

## License

Proprietary. Use at will inside your own org; not currently published
to Packagist.
