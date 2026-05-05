# translatify/translatify

Tiny English-as-key translator + scanner + drop-in editor for FlightPHP /
RedBean projects. Designed to be shared across multiple sibling apps via a
local Composer path repository.

## Install

In each host project's `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "../translatify", "options": { "symlink": true } }
  ],
  "require": {
    "translatify/translatify": "*"
  }
}
```

Then `composer require translatify/translatify`.

## Bootstrap

```php
use Translatify\Translator;

Translator::register(__DIR__ . '/lang')
    ->setLocale($member->locale ?? 'en')
    ->setFallback('en');
```

## Use it

```php
echo t('Save changes');
echo t('Hi :name', ['name' => $user->first_name]);
echo t('{n, plural, =0{No items} =1{One item} other{# items}}', ['n' => $count]);
```

Untranslated keys render the source string — nothing breaks during a
gradual rollout.

## JSON dictionaries

`lang/en.json`, `lang/es.json`, etc. are flat `{source: translation}` maps.
Keep `en.json` as the source of truth (English-as-key). Other locales hold
the actual translations.

## Browser

```html
<script src="/path/to/i18n.js"></script>
<script>
  I18n.configure({ locale: 'es', baseUrl: '/lang' });
  I18n.load().then(() => {
    document.title = t('Dashboard');
  });
</script>
```

## Scanner

Find all `t('...')` calls in your codebase and merge new keys into a
locale (defaults to `en`):

```sh
vendor/bin/i18n-scan --root=views,controls,services --lang=lang
vendor/bin/i18n-scan --root=. --lang=lang --dry-run
```

Only string-literal first arguments are extracted — `t($var)` is skipped.

## Editor

The editor is a drop-in PHP partial; the host project mounts its own
auth-checked routes. Minimal example:

```php
// controls/Web/Translations.php (admin-only)
public function index() {
    $editor = new \Translatify\Editor(__DIR__ . '/../../lang');
    $data   = $editor->buildRowData('en');
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
```

Then in `views/translations/index.php`:

```php
<?php include __DIR__ . '/../../vendor/translatify/translatify/views/editor.php'; ?>
```

The editor POSTs `action=set|remove|remove_all` to `$saveUrl`. The host
handler validates CSRF + auth then calls:

```php
$editor->setKey($locale, $source, $translation);
$editor->removeKey($locale, $source);
```

## Why English-as-key?

- Templates read naturally: `<?= t('Save changes') ?>` vs `<?= t('btn.save') ?>`
- No collisions in the common case
- New strings just work (fallback returns the source)
- Refactor cost = a search-and-replace
