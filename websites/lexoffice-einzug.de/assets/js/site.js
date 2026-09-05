/*
 * Lexware-Einzug, site.js (identisch auf lexware-einzug.de und lexoffice-einzug.de)
 * Keine Abhängigkeiten, kein Framework.
 * 1) UTM-Parameter an Links zur App-Domain weiterreichen
 * 2) Anonyme, cookielose Reichweitenmessung (eigener Endpunkt, keine Cookies)
 * 3) Sticky Mobile-CTA-Leiste ein-/ausblenden
 * 4) Einwilligung (Consent), Google Analytics 4 und Google Ads (nur lexoffice-einzug.de): gtag.js wird erst nach
 *    ausdrücklicher Zustimmung geladen. Ohne Zustimmung wird kein Google-Skript
 *    geladen und kein Cookie gesetzt. Entscheidung wird lokal gespeichert
 *    (localStorage, 12 Monate) und kann über "Cookie-Einstellungen" geändert werden.
 */
(function () {
  'use strict';

  var APP_HOST = 'app.lexware-einzug.de';
  var TRACK_ENDPOINT = 'https://app.lexware-einzug.de/track.php';
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];
  var GA_IDS = {
    'lexware-einzug.de': 'G-SXEH8K7HP7',
    'www.lexware-einzug.de': 'G-SXEH8K7HP7',
    'lexoffice-einzug.de': 'G-9NCFJ1Y4Z3',
    'www.lexoffice-einzug.de': 'G-9NCFJ1Y4Z3'
  };
  /* Google Ads Conversion-Tag, nur auf lexoffice-einzug.de, ebenfalls nur nach Einwilligung */
  var ADS_IDS = {
    'lexoffice-einzug.de': 'AW-18431688840',
    'www.lexoffice-einzug.de': 'AW-18431688840'
  };
  var CONSENT_KEY = 'le_consent_v1';
  var CONSENT_MAX_AGE = 365 * 24 * 60 * 60 * 1000;

  /* 1) UTM-Parameter weiterreichen */
  function propagateUtm() {
    var params;
    try { params = new URLSearchParams(window.location.search); } catch (e) { return; }
    var utmValues = {}, hasUtm = false;
    UTM_KEYS.forEach(function (key) {
      var value = params.get(key);
      if (value) { utmValues[key] = value; hasUtm = true; }
    });
    if (!hasUtm) { return; }
    document.querySelectorAll('a[href]').forEach(function (link) {
      var href = link.getAttribute('href');
      if (!href) { return; }
      try {
        var url = new URL(href, window.location.href);
        if (url.hostname === APP_HOST) {
          UTM_KEYS.forEach(function (key) {
            if (utmValues[key] && !url.searchParams.has(key)) { url.searchParams.set(key, utmValues[key]); }
          });
          link.setAttribute('href', url.toString());
        }
      } catch (e) { /* ungültige URL ignorieren */ }
    });
  }

  /* 2) Anonyme, cookielose Reichweitenmessung */
  function send(payload) {
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        if (navigator.sendBeacon(TRACK_ENDPOINT, blob)) { return; }
      }
      if (window.fetch) {
        fetch(TRACK_ENDPOINT, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true })["catch"](function () {});
      }
    } catch (e) { /* still ignorieren */ }
  }
  function trackPageView() { send({ d: location.hostname, e: 'page_view', p: location.pathname }); }
  function ctaOf(target) {
    var el = target;
    while (el && el !== document && !(el.hasAttribute && el.hasAttribute('data-cta'))) { el = el.parentElement; }
    return (el && el !== document) ? el.getAttribute('data-cta') : null;
  }
  function trackCtaClicks() {
    document.addEventListener('click', function (event) {
      var cta = ctaOf(event.target);
      if (!cta) { return; }
      send({ d: location.hostname, e: 'cta_click', p: location.pathname, c: cta });
      if (window.gtag && consentState() === 'all') {
        window.gtag('event', 'cta_click', { cta_position: cta, page_path: location.pathname });
      }
    });
  }

  /* 3) Sticky Mobile-CTA-Leiste */
  function initStickyCta() {
    var bar = document.querySelector('.sticky-cta, .mobile-cta');
    var hero = document.querySelector('.hero');
    if (!bar || !hero || !('IntersectionObserver' in window)) { return; }
    new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var scrolledPast = !entry.isIntersecting && entry.boundingClientRect.top < 0;
        bar.classList.toggle('is-visible', scrolledPast);
      });
    }, { threshold: 0 }).observe(hero);
  }

  /* Mobile-Navigation nach Linkklick schließen */
  function closeNavOnLinkClick() {
    var toggle = document.getElementById('nav-toggle');
    var nav = document.querySelector('.main-nav');
    if (!toggle || !nav) { return; }
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () { toggle.checked = false; });
    });
  }

  /* 4) Einwilligung und Google Analytics 4 */
  function readConsent() {
    try {
      var raw = localStorage.getItem(CONSENT_KEY);
      if (!raw) { return null; }
      var data = JSON.parse(raw);
      if (!data || !data.t || (Date.now() - data.t) > CONSENT_MAX_AGE) { return null; }
      return data;
    } catch (e) { return null; }
  }
  function consentState() { var c = readConsent(); return c ? c.s : null; }
  function saveConsent(state) {
    try { localStorage.setItem(CONSENT_KEY, JSON.stringify({ s: state, t: Date.now() })); } catch (e) { /* kein Speicher */ }
  }

  var gaLoaded = false;
  function loadAnalytics() {
    var id = GA_IDS[location.hostname];
    var adsId = ADS_IDS[location.hostname];
    if (!id || gaLoaded) { return; }
    gaLoaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', {
      ad_storage: adsId ? 'granted' : 'denied', ad_user_data: adsId ? 'granted' : 'denied',
      ad_personalization: 'denied', analytics_storage: 'granted'
    });
    window.gtag('js', new Date());
    window.gtag('config', id, { anonymize_ip: true });
    if (adsId) { window.gtag('config', adsId); }
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(s);
  }

  function buildBanner() {
    var wrap = document.createElement('div');
    wrap.className = 'consent';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-live', 'polite');
    wrap.setAttribute('aria-label', 'Einwilligung zu Cookies und Reichweitenmessung');
    var box = document.createElement('div');
    box.className = 'consent-box';
    var title = document.createElement('p');
    title.className = 'consent-title';
    title.textContent = 'Cookies und Reichweitenmessung';
    var text = document.createElement('p');
    text.className = 'consent-text';
    var adsHint = ADS_IDS[location.hostname] ? ' und Google Ads (Messung, ob ein Besuch über eine Anzeige zu einer Registrierung führt)' : '';
    text.appendChild(document.createTextNode('Wir nutzen Google Analytics' + adsHint + ', um zu verstehen, wie diese Seite genutzt wird. Dabei werden Cookies gesetzt und Daten an Google übertragen, auch in die USA. Das geschieht nur mit Ihrer Einwilligung, die Sie jederzeit über "Cookie-Einstellungen" im Fußbereich ändern können. Technisch notwendige Funktionen und unsere eigene cookielose Zählung laufen ohne Einwilligung. Details in der '));
    var link = document.createElement('a');
    link.href = '/datenschutz';
    link.textContent = 'Datenschutzerklärung';
    text.appendChild(link);
    text.appendChild(document.createTextNode('.'));
    var row = document.createElement('div');
    row.className = 'consent-actions';
    var accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'btn btn-primary';
    accept.textContent = 'Alle akzeptieren';
    var decline = document.createElement('button');
    decline.type = 'button';
    decline.className = 'btn btn-outline';
    decline.textContent = 'Nur notwendige';
    row.appendChild(accept);
    row.appendChild(decline);
    box.appendChild(title);
    box.appendChild(text);
    box.appendChild(row);
    wrap.appendChild(box);
    accept.addEventListener('click', function () { saveConsent('all'); wrap.remove(); loadAnalytics(); });
    decline.addEventListener('click', function () { saveConsent('necessary'); wrap.remove(); });
    return wrap;
  }

  function showBanner() {
    if (document.querySelector('.consent')) { return; }
    document.body.appendChild(buildBanner());
  }

  function initConsent() {
    if (!GA_IDS[location.hostname]) { return; }
    var state = consentState();
    if (state === 'all') { loadAnalytics(); }
    else if (state === null) { showBanner(); }
    document.addEventListener('click', function (event) {
      var el = event.target;
      while (el && el !== document && !(el.hasAttribute && el.hasAttribute('data-consent-open'))) { el = el.parentElement; }
      if (el && el !== document) { event.preventDefault(); showBanner(); }
    });
  }

  function init() {
    propagateUtm();
    trackCtaClicks();
    initStickyCta();
    closeNavOnLinkClick();
    trackPageView();
    initConsent();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
