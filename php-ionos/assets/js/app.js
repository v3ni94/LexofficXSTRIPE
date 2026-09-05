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
    function init() {
        var boxes = document.querySelectorAll('input[type="checkbox"][data-toggle-password]');
        for (var i = 0; i < boxes.length; i++) {
            bindToggle(boxes[i]);
        }
        initProfileMenu();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
