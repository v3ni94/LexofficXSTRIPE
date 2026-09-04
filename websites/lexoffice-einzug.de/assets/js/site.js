/* Lexware-Einzug, lexoffice-einzug.de
   Kleines, abhängigkeitsfreies Skript:
   1) UTM-Parameter an App-Links weiterreichen
   2) Anonyme, cookielose Reichweitenmessung
   3) Sticky Mobile-CTA ein-/ausblenden (per IntersectionObserver auf den Hero-Bereich) */
(function () {
  'use strict';

  var TRACK_ENDPOINT = 'https://app.lexware-einzug.de/track.php';
  var APP_HOST = 'app.lexware-einzug.de';
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

  function send(payload) {
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon(TRACK_ENDPOINT, blob);
      } else {
        fetch(TRACK_ENDPOINT, {
          method: 'POST',
          body: body,
          keepalive: true,
          headers: { 'Content-Type': 'application/json' }
        }).catch(function () {});
      }
    } catch (e) {
      /* Fehler still ignorieren */
    }
  }

  function appendUtm() {
    try {
      var params = new URLSearchParams(window.location.search);
      var utmPairs = [];
      UTM_KEYS.forEach(function (key) {
        var value = params.get(key);
        if (value) utmPairs.push([key, value]);
      });
      if (!utmPairs.length) return;

      var links = document.querySelectorAll('a[href]');
      links.forEach(function (a) {
        var href = a.getAttribute('href');
        if (!href) return;
        var url;
        try {
          url = new URL(href, window.location.href);
        } catch (e) {
          return;
        }
        if (url.hostname !== APP_HOST) return;
        utmPairs.forEach(function (pair) {
          if (!url.searchParams.has(pair[0])) {
            url.searchParams.set(pair[0], pair[1]);
          }
        });
        a.setAttribute('href', url.toString());
      });
    } catch (e) {
      /* Fehler still ignorieren */
    }
  }

  function trackPageView() {
    send({ d: window.location.hostname, e: 'page_view', p: window.location.pathname });
  }

  function bindCtaTracking() {
    document.addEventListener('click', function (evt) {
      var el = evt.target.closest ? evt.target.closest('[data-cta]') : null;
      if (!el) return;
      send({
        d: window.location.hostname,
        e: 'cta_click',
        p: window.location.pathname,
        c: el.getAttribute('data-cta')
      });
    });
  }

  function initMobileCta() {
    var bar = document.querySelector('.mobile-cta');
    var hero = document.querySelector('.hero');
    if (!bar || !hero) return;

    document.body.classList.add('has-mobile-cta');

    if (!('IntersectionObserver' in window)) {
      bar.classList.add('is-visible');
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          bar.classList.remove('is-visible');
        } else {
          bar.classList.add('is-visible');
        }
      });
    }, { threshold: 0 });

    observer.observe(hero);
  }

  document.addEventListener('DOMContentLoaded', function () {
    appendUtm();
    bindCtaTracking();
    initMobileCta();
    trackPageView();
  });
})();
