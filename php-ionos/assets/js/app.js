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
    function init() {
        var boxes = document.querySelectorAll('input[type="checkbox"][data-toggle-password]');
        for (var i = 0; i < boxes.length; i++) {
            bindToggle(boxes[i]);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
