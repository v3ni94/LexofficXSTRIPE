-- Migration 030: sevdesk-Pilotphase (Entscheidung 08.09.2026, Version 4.42).
-- sevdesk_connect = 'pilot': Verbindung und Wechsel zu sevdesk nur fuer Pilotfirmen (Firmen mit einem aktiven Mitglied
-- mit Administratorrecht der Plattform oder in sevdesk_pilot_orgs, kommagetrennte Firmenkennungen), bis der
-- Freigabetermin sevdesk_release_at (Vorgabe 2026-09-30) erreicht ist; danach fuer alle. Ein bereits gesetzter Wert
-- bleibt unangetastet (INSERT IGNORE). Wiederholbar, rein additiv.
INSERT IGNORE INTO platform_settings (`key`, `value`) VALUES ('sevdesk_connect', 'pilot');
INSERT IGNORE INTO platform_settings (`key`, `value`) VALUES ('sevdesk_pilot_orgs', '');
