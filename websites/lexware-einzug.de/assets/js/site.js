/*
 * Lexware-Einzug, site.js
 * Keine Abhängigkeiten, kein Framework.
 * 1) UTM-Parameter an Links zur App-Domain weiterreichen
 * 2) Anonyme, cookielose Reichweitenmessung (sendBeacon, Fallback fetch keepalive)
 * 3) Sticky Mobile-CTA-Leiste ein-/ausblenden (IntersectionObserver)
 * Die FAQ nutzt <details>/<summary> und benötigt kein JavaScript.
 */
(function () {
  'use strict';

  var APP_HOST = 'app.lexware-einzug.de';
  var TRACK_ENDPOINT = 'https://app.lexware-einzug.de/track.php';
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

  /* 1) UTM-Parameter weiterreichen */
  function propagateUtm() {
    var params;
    try {
      params = new URLSearchParams(window.location.search);
    } catch (e) {
      return;
    }

    var utmValues = {};
    var hasUtm = false;
    UTM_KEYS.forEach(function (key) {
      var value = params.get(key);
      if (value) {
        utmValues[key] = value;
        hasUtm = true;
      }
    });
    if (!hasUtm) {
      return;
    }

    var links = document.querySelectorAll('a[href]');
    links.forEach(function (link) {
      var href = link.getAttribute('href');
      if (!href) {
        return;
      }
      try {
        var url = new URL(href, window.location.href);
        if (url.hostname === APP_HOST) {
          UTM_KEYS.forEach(function (key) {
            if (utmValues[key] && !url.searchParams.has(key)) {
              url.searchParams.set(key, utmValues[key]);
            }
          });
          link.setAttribute('href', url.toString());
        }
      } catch (e) {
        /* ungültige URL wird ignoriert */
      }
    });
  }

  /* 2) Anonyme, cookielose Reichweitenmessung */
  function send(payload) {
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        var ok = navigator.sendBeacon(TRACK_ENDPOINT, blob);
        if (ok) {
          return;
        }
      }
      if (window.fetch) {
        fetch(TRACK_ENDPOINT, {
          method: 'POST',
          body: body,
          headers: { 'Content-Type': 'application/json' },
          keepalive: true
        })["catch"](function () {
          /* Fehler still ignorieren */
        });
      }
    } catch (e) {
      /* Fehler still ignorieren */
    }
  }

  function trackPageView() {
    send({ d: location.hostname, e: 'page_view', p: location.pathname });
  }

  function trackCtaClicks() {
    document.addEventListener('click', function (event) {
      var el = event.target;
      while (el && el !== document && !el.hasAttribute('data-cta')) {
        el = el.parentElement;
      }
      if (!el || el === document) {
        return;
      }
      send({
        d: location.hostname,
        e: 'cta_click',
        p: location.pathname,
        c: el.getAttribute('data-cta')
      });
    });
  }

  /* 3) Sticky Mobile-CTA-Leiste */
  function initStickyCta() {
    var bar = document.querySelector('.sticky-cta');
    var hero = document.querySelector('.hero');
    if (!bar || !hero || !('IntersectionObserver' in window)) {
      return;
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          var scrolledPast = !entry.isIntersecting && entry.boundingClientRect.top < 0;
          if (scrolledPast) {
            bar.classList.add('is-visible');
          } else {
            bar.classList.remove('is-visible');
          }
        });
      },
      { threshold: 0 }
    );
    observer.observe(hero);
  }

  /* Mobile-Navigation nach Linkklick schließen */
  function closeNavOnLinkClick() {
    var toggle = document.getElementById('nav-toggle');
    if (!toggle) {
      return;
    }
    var nav = document.querySelector('.main-nav');
    if (!nav) {
      return;
    }
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        toggle.checked = false;
      });
    });
  }

  function init() {
    propagateUtm();
    trackCtaClicks();
    initStickyCta();
    closeNavOnLinkClick();
    trackPageView();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
