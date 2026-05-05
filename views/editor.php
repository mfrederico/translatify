<?php
/**
 * Shipcannon i18n — drop-in editor partial.
 *
 * The host project mounts its own auth-checked routes for these actions and
 * passes the data in. This file is pure presentation; all writes go through
 * the host's POST handler which calls Shipcannon\I18n\Editor.
 *
 * Required variables when including this partial:
 *   $rows          array {source, translations: {locale: text}}
 *   $locales       array of locale codes (sorted)
 *   $baseLocale    locale to anchor "untranslated" highlighting (usually 'en')
 *   $saveUrl       URL the inline-edit form POSTs to (see expected payload)
 *   $newLocaleUrl  URL to POST {locale: 'xx'} for creating a new locale file
 *   $scanUrl       (optional) URL to POST to trigger a code scan; null = hide
 *   $csrfToken     CSRF token string
 *
 * Save payload (POST $saveUrl):
 *   action=set    locale=es  source=...  translation=...
 *   action=remove locale=es  source=...
 *
 * The host's handler should respond with a JSON {ok: true} or {ok: false,
 * error: '...'} so the inline JS can update without a reload.
 */

$rows         = $rows         ?? [];
$locales      = $locales      ?? ['en'];
$baseLocale   = $baseLocale   ?? 'en';
$saveUrl      = $saveUrl      ?? '#';
$newLocaleUrl = $newLocaleUrl ?? null;
$scanUrl      = $scanUrl      ?? null;
$csrfToken    = $csrfToken    ?? '';
?>
<div class="i18n-editor">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h4 mb-0">Translations</h2>
        <div class="d-flex gap-2">
            <?php if ($scanUrl): ?>
            <form method="post" action="<?= htmlspecialchars($scanUrl) ?>" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    Scan source files
                </button>
            </form>
            <?php endif; ?>
            <?php if ($newLocaleUrl): ?>
            <form method="post" action="<?= htmlspecialchars($newLocaleUrl) ?>" class="d-flex gap-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="text" name="locale" class="form-control form-control-sm"
                       placeholder="es or es-MX" pattern="[a-z]{2}(-[A-Z]{2})?" required
                       style="width:8rem">
                <button type="submit" class="btn btn-outline-primary btn-sm">Add locale</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($rows)): ?>
    <div class="alert alert-info">
        No translation keys yet. Run the scanner (or wrap a string with <code>t('…')</code>) to get started.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle i18n-table">
            <thead>
                <tr>
                    <th style="min-width:18rem">Source (<?= htmlspecialchars($baseLocale) ?>)</th>
                    <?php foreach ($locales as $code): ?>
                        <?php if ($code === $baseLocale) continue; ?>
                        <th style="min-width:18rem"><?= htmlspecialchars($code) ?></th>
                    <?php endforeach; ?>
                    <th style="width:4rem"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr data-source="<?= htmlspecialchars($row['source']) ?>">
                    <td><code><?= htmlspecialchars($row['source']) ?></code></td>
                    <?php foreach ($locales as $code): ?>
                        <?php if ($code === $baseLocale) continue; ?>
                        <?php $val = $row['translations'][$code] ?? ''; ?>
                        <td>
                            <input type="text"
                                   class="form-control form-control-sm i18n-input <?= $val === '' ? 'is-untranslated' : '' ?>"
                                   data-locale="<?= htmlspecialchars($code) ?>"
                                   value="<?= htmlspecialchars($val) ?>"
                                   placeholder="(untranslated — falls back to source)">
                        </td>
                    <?php endforeach; ?>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger i18n-remove" title="Remove key">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<style>
/* Inputs adapt to Bootstrap's current theme (light or dark) by using its
   CSS variables instead of the default white form-control background. */
.i18n-editor .i18n-input {
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-color: var(--bs-border-color, rgba(0,0,0,.15));
    border-left-width: 3px;
    border-left-color: transparent;
}
.i18n-editor .i18n-input:focus {
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
}
.i18n-editor .i18n-input::placeholder {
    color: var(--bs-secondary-color, #6c757d);
    opacity: .7;
}
/* Subtle status accents — semi-transparent so they layer on light or dark. */
.i18n-editor .is-untranslated   { border-left-color: #c79100; background-color: rgba(255, 193, 7, .08); }
.i18n-editor .i18n-saving       { border-left-color: #0d6efd; background-color: rgba(13, 110, 253, .10); }
.i18n-editor .i18n-saved        { border-left-color: #198754; background-color: rgba(25, 135, 84, .10); transition: background-color .2s; }
.i18n-editor .i18n-error        { border-left-color: #dc3545; background-color: rgba(220, 53, 69, .12); }
.i18n-editor .i18n-table code   { color: var(--bs-emphasis-color, inherit); }
</style>

<script>
(function () {
    var saveUrl = <?= json_encode($saveUrl) ?>;
    var csrf    = <?= json_encode($csrfToken) ?>;
    var root    = document.querySelector('.i18n-editor');
    if (!root) return;

    function post(payload, onResult) {
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
        fetch(saveUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
            .then(onResult)
            .catch(function () { onResult({ ok: false, error: 'Network error' }); });
    }

    function flash(el, klass) {
        el.classList.remove('i18n-saving', 'i18n-saved', 'i18n-error');
        el.classList.add(klass);
        if (klass === 'i18n-saved') {
            setTimeout(function () { el.classList.remove('i18n-saved'); }, 1500);
        }
    }

    // Save on blur or Enter
    root.addEventListener('blur', function (e) {
        if (!e.target.classList.contains('i18n-input')) return;
        var input = e.target;
        var tr = input.closest('tr');
        var source = tr.getAttribute('data-source');
        var locale = input.getAttribute('data-locale');
        var translation = input.value;
        var initial = input.defaultValue;
        if (translation === initial) return;

        flash(input, 'i18n-saving');
        post({ action: 'set', locale: locale, source: source, translation: translation }, function (res) {
            if (res && res.ok) {
                input.defaultValue = translation;
                input.classList.toggle('is-untranslated', translation === '');
                flash(input, 'i18n-saved');
            } else {
                flash(input, 'i18n-error');
                if (res && res.error) input.title = res.error;
            }
        });
    }, true);

    root.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList.contains('i18n-input')) {
            e.preventDefault();
            e.target.blur();
        }
    });

    // Remove key
    root.addEventListener('click', function (e) {
        if (!e.target.classList.contains('i18n-remove')) return;
        var tr = e.target.closest('tr');
        var source = tr.getAttribute('data-source');
        if (!confirm('Remove "' + source + '" from all locales?')) return;
        post({ action: 'remove_all', source: source }, function (res) {
            if (res && res.ok) tr.remove();
            else alert((res && res.error) || 'Remove failed');
        });
    });
})();
</script>
