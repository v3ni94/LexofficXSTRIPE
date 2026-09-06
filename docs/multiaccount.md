# Multiaccount, Registrierung mit bestehender E-Mail-Adresse, Dublettenprüfung

Stand: 06.09.2026 (Auftrag II, Abschnitte 3, 4 und 6). Datenbankänderungen: Migration 015.

## Grundsatz

Eine E-Mail-Adresse gehört zu genau einer Benutzeridentität (eindeutiger Index auf users.email). Ein Benutzer kann mehreren Firmen zugeordnet sein (Tabelle organization_members, eindeutiger Index je Firma und Benutzer). Multiaccount verbindet nur Anmeldung und Navigation. Kunden, Rechnungen, Einzüge, Lexware- und Stripe-Zugänge, Exporte und Abonnements bleiben je Firma getrennt; jede Abfrage filtert wie bisher nach der aktiven Firma.

## Multiaccount-Einstellung (Abschnitt 3)

Ort: Firmendaten, Abschnitt Mein Profil, Checkbox "Multiaccount aktivieren". Die Einstellung ist benutzerbezogen (users.multiaccount_enabled), nicht firmenbezogen.

| Zustand | Verhalten |
|---|---|
| Eine Firma, Schalter aus | Standard. Firmenübersicht nicht im Profilmenü. companies.php bleibt direkt aufrufbar und zeigt einen Hinweis. |
| Eine Firma, Schalter an | Firmenübersicht im Profilmenü. Aktivieren legt weder Firma noch Abonnement an. Deaktivieren löscht nichts. |
| Mehrere Firmen | Automatisch aktiv und gesperrt. Anzeige: "Multiaccount ist aktiviert, weil Ihrem Benutzerkonto mehrere Firmen zugeordnet sind." |

Der wirksame Zustand wird zur Laufzeit ermittelt (user_multiaccount_state in app/auth.php): manueller Schalter oder mehr als eine aktive, nicht gelöschte Firma. Beim Anlegen einer weiteren Firma (Firmenübersicht, Registrierungsfortsetzung) wird der Schalter zusätzlich gesetzt. Die Migration setzt ihn für bestehende Benutzer mit mehreren Firmen.

Der Firmenwechsel (switch_company) prüft die Mitgliedschaft serverseitig bei jedem Wechsel. Das Ausblenden des Menüpunkts ist keine Zugriffskontrolle. Ein Wechsel ändert weder die Benutzeridentität noch Rollen in anderen Firmen.

Die Firmenliste des Betreibers im Adminbereich ist davon unabhängig.

## Registrierung (Abschnitt 4)

register.php ordnet jede Registrierung serverseitig ein (registration_classify):

| Fall | Ablauf |
|---|---|
| E-Mail unbekannt | Bisheriger Ablauf: Benutzer, Firma, Mitgliedschaft, Integrationsdatensatz in einer Transaktion, danach E-Mail-Bestätigung und 2FA-Einrichtung. Multiaccount bleibt aus. |
| E-Mail bekannt, Firma dieses Namens noch nicht zugeordnet | Firmendaten (Name, Mandatspräfix) werden in registration_requests für 30 Minuten gespeichert und an den vorhandenen Benutzer gebunden. Weiterleitung zur Anmeldung mit vorbelegter E-Mail-Adresse und Hinweis. Nach Passwort und 2FA landet der Benutzer auf register-fortsetzen.php und bestätigt die Anlage mit "Firma jetzt anlegen". Die Firma entsteht genau einmal, der Benutzer wird Inhaber, Multiaccount wird aktiviert. |
| E-Mail bekannt, Firma dieses Namens bereits zugeordnet | Hinweisseite mit sichtbarem Countdown (5 Sekunden, assets/js/app.js) und Button "Jetzt anmelden". Anmeldeseite mit vorbelegter E-Mail-Adresse. Kein Benutzer, keine Firma, keine Aktivierung von Multiaccount. |

Sicherheitsregeln:

- Das bei der Registrierung eingegebene Passwort wird bei bekannter E-Mail-Adresse weder geprüft noch gespeichert. Es gilt ausschließlich die zentrale Anmeldung (auth_login, auth_login_2fa) inklusive Kontosperren.
- Die vorbelegte E-Mail-Adresse wird nur in der Sitzung übergeben (login_prefill_email), nicht in der URL.
- Der Registrierungsvorgang ist an die Benutzer-ID gebunden. Meldet sich ein anderer Benutzer an, wird der Sitzungsverweis entfernt und der Vorgang nicht ausgeführt. Er läuft nach 30 Minuten ab.
- Der Abschluss läuft in einer Transaktion mit Zeilensperre (SELECT ... FOR UPDATE) und Statuswechsel pending zu completed. Wiederholte Aufrufe oder Doppelklicks legen keine zweite Firma an.
- Ein bereits angemeldeter Benutzer wird von register.php zur Firmenübersicht geleitet; dort läuft dieselbe Anlage (create_company).

Ratenbegrenzung: Registrierungsversuche mit bekannter E-Mail-Adresse werden in login_attempts mit stage register protokolliert und je IP auf 10 Versuche in 15 Minuten begrenzt (register_throttle_check). Diese Einträge zählen nicht gegen die Anmeldesperre des echten Kontoinhabers, damit niemand ein fremdes Konto über die Registrierung sperren kann.

Verbleibendes Risiko: Die gewünschten Hinweise ("Benutzerkonto vorhanden", Weiterleitung zur Anmeldung) legen offen, dass eine E-Mail-Adresse registriert ist und ob eine gleichnamige Firma zugeordnet ist. Die Ratenbegrenzung verlangsamt systematisches Ausprobieren, beseitigt die Offenlegung aber nicht. Weitere Firmen- oder Kontodetails werden nicht ausgegeben.

## Dublettenprüfung (Abschnitt 6)

- E-Mail-Adressen werden wie beim Login normalisiert (Kleinschreibung, Leerzeichen entfernt). Aliasbestandteile werden nicht entfernt.
- Firmennamen werden für den Vergleich in Kleinschreibung gesetzt und mehrfache Leerzeichen zusammengefasst (org_name_normalize). Rechtsformen oder Namensbestandteile werden nicht entfernt.
- Ein gleicher Firmenname ist kein Berechtigungsnachweis. Ein Benutzer wird nie automatisch einer fremden Firma zugeordnet; der Beitritt läuft ausschließlich über Einladungen. Zwei verschiedene Benutzer dürfen Firmen mit demselben Namen führen.
- Je Benutzer darf derselbe normalisierte Firmenname nur einmal zugeordnet sein. Die Prüfung läuft innerhalb der Anlage erneut.
- Gleichzeitige Registrierungen: Benutzer- und Firmenanlage laufen unter einer datenbankweiten Sperre (GET_LOCK smarteinzug_registration, 5 Sekunden Wartezeit), zusätzlich in Transaktionen. Datenbank-Constraints: users.email eindeutig, organization_members (organization_id, user_id) eindeutig. Ein Verstoß gegen users.email wird abgefangen und als "bereits registriert" gemeldet.
- Das Mandatspräfix wird unter derselben Sperre auf Eindeutigkeit geprüft. Ein zusätzlicher eindeutiger Index wurde nicht angelegt, weil Altbestände mit leerem Präfix kollidieren könnten; die Sperre stellt die Eindeutigkeit bei gleichzeitigen Anlagen sicher.

## Migration 015

Datei sql/migrations/015_multiaccount_registration.sql, eingespielt ausschließlich über den Migrationsendpunkt (docs/migrations.md):

- users.multiaccount_enabled TINYINT(1) NOT NULL DEFAULT 0
- Tabelle registration_requests (id, user_id, org_name, mandate_prefix, status, created_org_id, ip, expires_at, created_at, completed_at)
- UPDATE: Benutzer mit mehreren aktiven Firmen erhalten multiaccount_enabled = 1

Vor der Migration verhält sich die Anwendung wie bisher (Firmenübersicht immer sichtbar, Registrierung mit bekannter E-Mail meldet einen Hinweis statt einer Fortsetzung). Keine Tabelle oder Spalte wird nebenbei angelegt.

Bereinigung: cron.php markiert abgelaufene Vorgänge als expired und löscht abgeschlossene, verworfene oder abgelaufene Einträge nach 30 Tagen (registration_requests_cleanup). Die Tabelle enthält keine Passwörter oder Token.

## Audit

- multiaccount_enabled, multiaccount_disabled (manueller Schalter)
- company_created (jede Firmenanlage)
- registration_continued (Abschluss eines Registrierungsvorgangs, mit request_id)

## Tests

scratchpad/e2e_saas.php, Abschnitt 26: Schalter und Menü, Deaktivierung ohne Datenverlust, Hinweisseite mit Countdown, vorbelegte E-Mail-Adresse, Passwort aus der Registrierung ungültig, Fortsetzung nur nach Passwort und 2FA, genau eine Firma bei Wiederholung, Rollen unverändert, gesperrte Checkbox, gleichnamige Firma je Benutzer abgelehnt, gleicher Name bei anderem Benutzer erlaubt ohne Verknüpfung, fremde Sitzung übernimmt den Vorgang nicht, Ratenbegrenzung ohne Auswirkung auf die Anmeldesperre, Cron-Bereinigung.
