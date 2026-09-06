# Zwei-Faktor-Authentifizierung: Gerät für 90 Tage merken

Stand: 06.09.2026 (Auftrag II, Abschnitt 5). Datenbankänderung: Migration 016 (Tabelle trusted_devices). Code: app/devices.php, Einbindung in app/auth.php, twofa-verify.php, twofa-setup.php, security.php, logout.php, app/layout.php, cron.php.

## Grundprinzip

Es wird kein 2FA-Code gespeichert. Nach einer tatsächlich erfolgreichen Authenticator-Bestätigung und ausdrücklich gesetzter Checkbox erhält der Browser ein Cookie mit einer Datensatzkennung und einem geheimen Teil aus 32 Zufallsbytes (random_bytes). Serverseitig liegt nur der HMAC-SHA256 des geheimen Teils (Schlüssel app_secret), der Vergleich läuft mit hash_equals.

Die Freigabe ersetzt innerhalb der Frist ausschließlich die Codeabfrage bei der regulären Anmeldung in diesem Browser. Passwort, Kontosperren, Benutzerstatus, Rollen und Firmenmitgliedschaften werden unverändert geprüft. Ein Geräte-Token allein ermöglicht keine Anmeldung.

Die 90 Tage sind eine Produkteinstellung, kein Nachweis eines Bankenstandards oder einer regulatorischen Konformität.

## Ablauf

| Schritt | Verhalten |
|---|---|
| Anmeldung | E-Mail und Passwort werden wie bisher geprüft (inklusive Sperren). Erst danach prüft device_trust_check das Cookie: Datensatz vorhanden, Hash passt, gleicher Benutzer, gleicher Anwendungsbereich, nicht widerrufen, nicht abgelaufen. |
| Gültig | Token wird atomar rotiert (UPDATE über den alten Hash), Ablauf bleibt unverändert, Anmeldung wird mit Anmeldeart "device" abgeschlossen. login_attempts erhält stage device, das Audit login_success enthält method device. |
| Ungültig, manipuliert, widerrufen, anderer Bereich | Cookie wird gelöscht, reguläre 2FA-Abfrage. Technische Fehler gelten nie als Erfolg. |
| Anderer Benutzer | Cookie bleibt unangetastet, reguläre 2FA-Abfrage. Eine fremde Freigabe wird nie übernommen. |
| Abgelaufen | Cookie wird gelöscht, 2FA-Seite zeigt: "Die 90-tägige Freigabe für dieses Gerät ist abgelaufen. Bitte bestätigen Sie Ihre Anmeldung erneut mit Ihrem Authenticator-Code." Ohne erneute Auswahl entsteht keine neue Freigabe. |
| Checkbox gesetzt, Code korrekt | device_trust_create legt den Datensatz an (created_at, expires_at = created_at + 90 Tage, UTC), setzt das Cookie, schreibt Audit device_trusted und sendet eine Sicherheits-E-Mail mit Zeitpunkt, Browserbezeichnung, Ablaufdatum und Link zur Geräteverwaltung. |
| Checkbox gesetzt, Recovery-Code | Anmeldung möglich, keine Freigabe. Hinweis in der Meldung. |
| 2FA-Einrichtung | Die Checkbox wird bereits im Bestätigungsformular angeboten (Abschnitt 4.1). |

## Feste Gültigkeit

Ablaufzeitpunkt = Freigabezeitpunkt + 90 Tage, berechnet und gespeichert in UTC. Normale Anmeldungen, Seitenaufrufe, Firmenwechsel und Token-Rotationen verändern expires_at nicht. Der Vergleich läuft in PHP gegen auth_now(); die Grenze ist strikt (gültig nur, wenn expires_at größer als jetzt).

Sitzungen, deren Anmeldung auf einer Freigabe beruht, tragen in der Sitzung den Verweis trusted_device_id. current_user prüft bei jedem geschützten Zugriff, ob die Freigabe noch gilt. Ist sie abgelaufen oder widerrufen, endet die Sitzung sofort mit dem Hinweis, sich erneut anzumelden. Sitzungen mit Authenticator-Anmeldung sind davon nicht betroffen. Bestehende Sitzungs- und Inaktivitätsregeln bleiben unverändert.

Testuhr: config test_time_offset_seconds verschiebt auth_now() (nur in Testkonfigurationen). Die E2E-Suite prüft die Grenzen zusätzlich über direkt gesetzte expires_at-Werte (kurz vor, genau bei, nach Ablauf).

## Cookie

Name lxeinzug_device, über HTTPS mit Präfix __Host-. Attribute: Secure (bei HTTPS), HttpOnly, SameSite=Lax, Path=/, kein Domain-Attribut (Host-only). Die Cookie-Laufzeit entspricht genau dem serverseitigen Ablauf. Maßgeblich ist immer der Datenbankeintrag. Der Token liegt weder in localStorage, sessionStorage, URLs noch in Logs. Passwort und TOTP-Geheimnis sind nie Teil des Cookies.

## Anwendungsbereiche

Die Freigabe speichert den Bereich app oder admin (device_scope über on_admin_host). Weil das Cookie Host-only ist, gilt es ohnehin nur für den Host, auf dem es gesetzt wurde. Zusätzlich lehnt der Server eine Freigabe mit fremdem Bereich ab. Für admin.smart-einzug.de ist eine eigene Bestätigung erforderlich. Läuft die Administration im Übergangsmodus auf demselben Host, gilt der Bereich app für beide, weil dann kein getrennter Host existiert.

Innerhalb der Kundenanwendung ist die Freigabe benutzerbezogen. Ein Firmenwechsel löst keine neue 2FA-Abfrage aus; die Mitgliedschaft je Firma wird bei jedem Wechsel serverseitig geprüft. Gerätevertrauen verleiht weder Mitgliedschaften noch Rechte.

## Sicherheitskritische Aktionen

require_recent_totp verlangt einen aktuellen Authenticator-Code. Der optionale Parameter allowFreshWindow erlaubt die Wiederverwendung einer tatsächlich eingegebenen und geprüften Bestätigung innerhalb von 5 Minuten (Sitzungswert totp_verified_at). Eine Anmeldung über die Gerätefreigabe oder einen Recovery-Code setzt dieses Fenster nicht; die Anmeldeart wird in der Sitzung getrennt gespeichert (auth_method). Das Fenster verlängert die Gerätefreigabe nicht.

Aktuell nutzt die Passwortänderung das Fenster (Passwort plus Code oder frische Bestätigung). Die übrigen kritischen Aktionen (Not-Stopp, Tarifänderungen, Support-Zugriff, Inhaberwechsel, Stripe-Import) verlangen weiterhin bei jeder Ausführung einen Code.

## Verwaltung und Widerruf

Sicherheit, Gemerkte Geräte: Browserbezeichnung (grob aus dem User-Agent, keine erfundenen Hardwaredaten), Bereich, Freigabezeitpunkt, letzte Verwendung, Ablaufdatum, Kennzeichnung "Dieses Gerät". Aktionen "Gerät vergessen" und "Alle Geräte vergessen" (POST, CSRF, nur eigene Datensätze).

Ein Widerruf wirkt sofort. Sitzungen, die auf der widerrufenen Freigabe beruhen, enden beim nächsten geschützten Zugriff.

Automatischer Widerruf aller Freigaben bei: Passwortänderung, Passwortzurücksetzung, Änderung oder Zurücksetzung der 2FA-Konfiguration (auch durch den Betreiber), "Überall abmelden". Eine Änderung der Login-E-Mail-Adresse gibt es in der Anwendung derzeit nicht.

Abmelden: "Abmelden" beendet nur die Sitzung, die Freigabe bleibt. "Abmelden und Gerät vergessen" (Profilmenü, POST) widerruft zusätzlich die Freigabe dieses Browsers. "Überall abmelden" (Sicherheit) erhöht die Sitzungsepoche und widerruft alle Freigaben.

Eine reine Token-Rotation löst keine Benachrichtigung aus.

## Grenzen

Die Wiedererkennung ist browserbezogen. Ein entwendetes Cookie kann zusammen mit dem Passwort bis zum Widerruf oder Ablauf verwendet werden. Die Rotation bei jeder Anmeldung entwertet ältere Kopien, die Verwaltungsseite und die E-Mail machen neue Freigaben sichtbar. Eine Hardwarebindung besteht nicht. IP-Adresse und Browserkennung dienen nur der Anzeige, nicht als Vertrauensnachweis; ein IP-Wechsel hebt Freigaben nicht auf.

## Datenhaltung

Tabelle trusted_devices: id, user_id, scope, token_hash, label, created_at, expires_at, last_used_at, rotated_at, revoked_at, revoked_reason, ip_created. Alle Zeiten UTC. Bestehende Sitzungen wurden bei der Einführung nicht in Freigaben umgewandelt. cron.php löscht widerrufene und abgelaufene Einträge nach 30 Tagen (devices_cleanup). Sicherheitsprotokolle (audit_log, login_attempts) bleiben davon getrennt.

## Audit

device_trusted, device_revoked, devices_revoked_all, logout_everywhere, login_success mit method (password, totp, recovery, device).

## Tests

scratchpad/e2e_saas.php, Abschnitt 27, deckt die Fälle aus Abschnitt 9.3 ab: ohne Checkbox, Checkbox mit falschem Code, Freigabe mit festem Ablauf, Anmeldung über Freigabe mit Rotation ohne Fristverlängerung, falsches Passwort, anderes Konto im selben Browser, anderer Browser, gelöschtes und manipuliertes Cookie, Grenzen kurz vor, genau bei und nach Ablauf, offene Sitzung über den Ablauf hinaus, Widerruf aus zweiter Sitzung, Passwortänderung, normales Abmelden, Abmelden und Gerät vergessen, Überall abmelden, fremder Anwendungsbereich, Firmenwechsel, sicherheitskritische Aktion, Recovery-Code, Einrichtung mit Checkbox, Cron-Bereinigung.
