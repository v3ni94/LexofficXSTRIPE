/*
 * SmartEinzug-Anwendung: Einwilligung und Google-Tag fuer die oeffentlichen Seiten.
 *
 * Wird ausschliesslich auf oeffentlichen Seiten ohne Anmeldung eingebunden
 * (Registrierung, Vormerkung). Auf angemeldeten Seiten laedt diese Datei nie,
 * weil deren Adressen Kunden- und Rechnungskennungen enthalten koennen und
 * diese nicht an Google gelangen duerfen.
 *
 * Ohne ausdrueckliche Einwilligung wird kein Google-Skript geladen und kein
 * Cookie gesetzt. Die Entscheidung liegt 12 Monate im localStorage dieser
 * Herkunft und laesst sich ueber "Cookie-Einstellungen" in der Fusszeile aendern.
 * Die Kennungen stehen als data-Attribute am eigenen script-Tag, damit kein
 * Inline-JavaScript noetig ist.
 */
(function () {
    'use strict';

    var self = document.currentScript;
    if (!self) { return; }
    var gaId = self.getAttribute('data-ga') || '';
    var adsId = self.getAttribute('data-ads') || '';
    var privacyUrl = self.getAttribute('data-privacy') || '';
    if (!gaId && !adsId) { return; }

    var KEY = 'se_consent_v1';
    var MAX_AGE = 365 * 24 * 60 * 60 * 1000;
    var loaded = false;

    function readState() {
        try {
            var raw = localStorage.getItem(KEY);
            if (!raw) { return null; }
            var data = JSON.parse(raw);
            if (!data || !data.t || (Date.now() - data.t) > MAX_AGE) { return null; }
            return data.s || null;
        } catch (e) { return null; }
    }

    function saveState(state) {
        try { localStorage.setItem(KEY, JSON.stringify({ s: state, t: Date.now() })); } catch (e) { /* kein Speicher */ }
    }

    function loadTag() {
        if (loaded) { return; }
        loaded = true;
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
        window.gtag('consent', 'default', {
            ad_storage: adsId ? 'granted' : 'denied',
            ad_user_data: adsId ? 'granted' : 'denied',
            ad_personalization: 'denied',
            analytics_storage: gaId ? 'granted' : 'denied'
        });
        window.gtag('js', new Date());
        if (gaId) { window.gtag('config', gaId, { anonymize_ip: true }); }
        if (adsId) { window.gtag('config', adsId); }
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(gaId || adsId);
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
        var dienste = [];
        if (gaId) { dienste.push('Google Analytics'); }
        if (adsId) { dienste.push('Google Ads (Messung, ob ein Besuch über eine Anzeige zu einer Registrierung führt)'); }
        text.appendChild(document.createTextNode(
            'Auf dieser Seite nutzen wir ' + dienste.join(' und ') + '. Dabei werden Cookies gesetzt und Daten an Google übertragen, auch in die USA. '
            + 'Das geschieht nur mit Ihrer Einwilligung. Innerhalb Ihres Firmenaccounts findet keine Messung statt. '
            + 'Technisch notwendige Funktionen laufen ohne Einwilligung. Details in der '));
        if (privacyUrl) {
            var link = document.createElement('a');
            link.href = privacyUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = 'Datenschutzerklärung';
            text.appendChild(link);
            text.appendChild(document.createTextNode('.'));
        } else {
            text.appendChild(document.createTextNode('Datenschutzerklärung.'));
        }

        var row = document.createElement('div');
        row.className = 'consent-actions';
        var accept = document.createElement('button');
        accept.type = 'button';
        accept.className = 'btn btn-primary';
        accept.textContent = 'Alle akzeptieren';
        var decline = document.createElement('button');
        decline.type = 'button';
        decline.className = 'btn btn-secondary';
        decline.textContent = 'Nur notwendige';
        row.appendChild(accept);
        row.appendChild(decline);

        box.appendChild(title);
        box.appendChild(text);
        box.appendChild(row);
        wrap.appendChild(box);

        accept.addEventListener('click', function () { saveState('all'); wrap.remove(); loadTag(); });
        decline.addEventListener('click', function () { saveState('necessary'); wrap.remove(); });
        return wrap;
    }

    function showBanner() {
        if (document.querySelector('.consent')) { return; }
        document.body.appendChild(buildBanner());
    }

    function init() {
        var state = readState();
        if (state === 'all') { loadTag(); }
        else if (state === null) { showBanner(); }

        document.addEventListener('click', function (event) {
            var el = event.target;
            while (el && el !== document && !(el.hasAttribute && el.hasAttribute('data-consent-open'))) {
                el = el.parentElement;
            }
            if (el && el !== document) { event.preventDefault(); showBanner(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
