/*
 * SmartEinzug: kleine, inline-freie Hilfsskripte (kein Inline-JS wegen CSP-Vorbild).
 * Derzeit: "Passwort anzeigen" auf Formularen. Eine Checkbox mit
 * data-toggle-password="feld1,feld2" schaltet die genannten Passwortfelder
 * zwischen type=password und type=text um.
 */
(function () {
    'use strict';
    function bindToggle(box) {
        var ids = (box.getAttribute('data-toggle-password') || '').split(',');
        function apply() {
            for (var i = 0; i < ids.length; i++) {
                var el = document.getElementById(ids[i].trim());
                if (el) {
                    el.setAttribute('type', box.checked ? 'text' : 'password');
                }
            }
        }
        box.addEventListener('change', apply);
        apply();
    }
    function initProfileMenu() {
        var menu = document.getElementById('profile-menu');
        if (!menu) { return; }
        document.addEventListener('click', function (event) {
            if (menu.hasAttribute('open') && !menu.contains(event.target)) { menu.removeAttribute('open'); }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.hasAttribute('open')) { menu.removeAttribute('open'); }
        });
    }
    function initCountdownRedirect() {
        // Registrierung mit bestehendem Konto: sichtbarer Countdown, danach Weiterleitung zur Anmeldung.
        // Der Button "Jetzt anmelden" ist ein normaler Link und funktioniert unabhängig davon.
        var els = document.querySelectorAll('[data-countdown-redirect]');
        for (var i = 0; i < els.length; i++) {
            (function (el) {
                var seconds = parseInt(el.getAttribute('data-countdown-seconds') || '5', 10);
                var target = el.getAttribute('data-countdown-redirect');
                var out = el.querySelector('[data-countdown-value]');
                var timer = window.setInterval(function () {
                    seconds -= 1;
                    if (out) { out.textContent = String(Math.max(seconds, 0)); }
                    if (seconds <= 0) { window.clearInterval(timer); window.location.href = target; }
                }, 1000);
            })(els[i]);
        }
    }
    function init() {
        var boxes = document.querySelectorAll('input[type="checkbox"][data-toggle-password]');
        for (var i = 0; i < boxes.length; i++) {
            bindToggle(boxes[i]);
        }
        initProfileMenu();
        initCountdownRedirect();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
