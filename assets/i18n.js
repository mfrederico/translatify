/**
 * Shipcannon i18n — browser companion
 *
 * Loads the same JSON dictionary the PHP Translator uses so client-side code
 * can call window.t('Save changes') with the same English-as-key contract.
 *
 * Usage:
 *   <script src="/path/to/i18n.js"></script>
 *   <script>
 *     I18n.configure({ locale: 'es', baseUrl: '/lang' });  // loads /lang/es.json
 *     I18n.load().then(() => {
 *       document.body.textContent = t('Save changes');     // "Guardar cambios"
 *     });
 *   </script>
 */
(function (global) {
  'use strict';

  let locale = 'en';
  let fallback = 'en';
  let baseUrl = '/lang';
  const dicts = Object.create(null);

  function configure(opts) {
    if (opts.locale)   locale = String(opts.locale);
    if (opts.fallback) fallback = String(opts.fallback);
    if (opts.baseUrl)  baseUrl = String(opts.baseUrl).replace(/\/$/, '');
    return I18n;
  }

  async function loadOne(code) {
    if (dicts[code]) return dicts[code];
    try {
      const res = await fetch(`${baseUrl}/${code}.json`, { credentials: 'same-origin' });
      if (!res.ok) { dicts[code] = {}; return dicts[code]; }
      dicts[code] = await res.json();
    } catch (e) {
      dicts[code] = {};
    }
    return dicts[code];
  }

  async function load() {
    const codes = [locale, fallback].filter((v, i, a) => a.indexOf(v) === i);
    await Promise.all(codes.map(loadOne));
    return I18n;
  }

  function lookup(source) {
    const inLocale = dicts[locale] && dicts[locale][source];
    if (inLocale) return inLocale;
    const inFallback = dicts[fallback] && dicts[fallback][source];
    if (inFallback) return inFallback;
    return source;
  }

  /**
   * Translate a source string with optional :name interpolation.
   * For ICU MessageFormat / plurals on the client, plug in a small library
   * (icu-messageformat or messageformat-runtime) and pass strings through it
   * after translation; this helper stays minimal.
   */
  function t(source, vars) {
    const text = lookup(source);
    if (!vars) return text;
    return text.replace(/:([A-Za-z0-9_]+)/g, (m, k) =>
      Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m
    );
  }

  const I18n = {
    configure,
    load,
    t,
    setLocale: (c) => { locale = c; return I18n; },
    getLocale: () => locale,
  };

  global.I18n = I18n;
  if (!global.t) global.t = t;
})(typeof window !== 'undefined' ? window : globalThis);
