-- 033: Berechtigung support.customers fuer die Systemrolle "Mitarbeiter Support" (Version 4.62, wiederholbar, rein additiv)
--
-- Der Support pflegt Kundenprofile (Firmenname, Anschrift, Kontaktdaten der Benutzer) auf admin-kunde.php. Das neue Recht
-- steht im Katalog PLATFORM_PERMISSIONS (app/platform.php) und wird hier der Systemrolle support nachgetragen, weil die
-- Rollenrechte in platform_roles.permissions (JSON-Array) gespeichert sind und nicht aus der Konstante gelesen werden.
-- Die Rolle admin hat "*" und braucht nichts. Eigene Rollen bleiben unveraendert (bewusste Vergabe im Adminbereich).
-- Idempotent: nur ergaenzen, wenn das Recht noch fehlt und das Feld gueltiges JSON ist.
UPDATE platform_roles
   SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'support.customers')
 WHERE code = 'support' AND is_system = 1 AND JSON_VALID(permissions)
   AND NOT JSON_CONTAINS(permissions, '"support.customers"', '$');
