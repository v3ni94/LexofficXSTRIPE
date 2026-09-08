# SmartEinzug Benutzerhandbuch

Dieses Handbuch richtet sich an Firmen, die SmartEinzug einsetzen, um Rechnungen aus Lexware Office per SEPA-Lastschrift über ein eigenes Stripe-Konto einzuziehen. Es beschreibt die Bedienung der Kundenanwendung Schritt für Schritt, mit den tatsächlichen Menü- und Schaltflächenbezeichnungen der Oberfläche.

## Inhalt

1. Schnelleinstieg
2. Voraussetzungen
3. Registrierung, E-Mail-Bestätigung, Zwei-Faktor-Authentifizierung, Einrichtung
4. Firmendaten, Team und Rollen
5. Buchhaltungssystem und Verbindungen
6. Rechnungssynchronisation
7. Kunden und SEPA-Mandate
8. Lastschriften
9. Status der Einzüge
10. Abonnement
11. Rechtliches, Protokoll und Export
12. Sicherheit
13. Hilfe und Support
14. Häufige Fragen
15. Glossar

## 1. Schnelleinstieg

@@diagramm 14-kundeneinrichtung

Diese Übersicht zeigt den Weg von der Registrierung bis zum ersten Einzug in wenigen Schritten. Die einzelnen Punkte werden in den folgenden Kapiteln ausführlich erklärt.

1. Auf der Seite "Firmenaccount registrieren" Firmenname, Mandatspräfix, persönliche Daten und Passwort eingeben, AGB und Datenschutzerklärung bestätigen.
2. E-Mail-Adresse über den zugesandten Link bestätigen (sofern der Mailversand aktiv ist).
3. Zwei-Faktor-Authentifizierung mit einer Authenticator-App einrichten und die Recovery-Codes sichern.
4. Auf der Seite "Einrichtung" die Schritte abarbeiten: Abonnement aktivieren (falls erforderlich), Lexware Office verbinden, Stripe verbinden, Verbindungen prüfen, Firmendaten für SEPA-Mandate hinterlegen.
5. Auf "Rechnungen" die erste Synchronisation mit Lexware Office starten.
6. Für jeden Kunden unter "SEPA Pflegen" oder in den Kundendetails eine IBAN hinterlegen und das SEPA-Mandat erzeugen.
7. Auf "Rechnungen" einzelne Lastschriften auslösen oder terminieren, oder auf "Einzüge" alle bereiten Einzüge gesammelt einreichen.
8. Auf "Einzüge" den Status jeder Lastschrift verfolgen, bei Bedarf stornieren, umterminieren oder eine Klärung abschließen.

[Screenshot: Dashboard, Gesamtansicht mit Kennzahlenkarten und "Letzte Einzüge"]

## 2. Voraussetzungen

Für den Einsatz von SmartEinzug benötigen Sie:

- **Lexware Office mit Public API**: Nach Angaben von Lexware setzt die Public API derzeit den Tarif Lexware Office XL voraus. Prüfen Sie dies in Ihrem eigenen Lexware-Office-Konto, da sich Tarifbedingungen ändern können. Der Zugang erfolgt über einen API-Schlüssel, den Sie in Lexware Office selbst erzeugen (Kapitel 5).
- **Ein eigenes Stripe-Konto mit SEPA-Lastschrift**: SmartEinzug führt keine Zahlungen über ein eigenes Konto aus. Alle Lastschriften laufen über Ihr Stripe-Konto; Stripe unterstützt die SEPA-Basislastschrift (Core).
- **Eine persönliche E-Mail-Adresse** je Benutzerkonto. Sammelpostfächer sind nicht vorgesehen, da jede Person ein eigenes Konto mit eigener Zwei-Faktor-Authentifizierung benötigt.
- **Eine Authenticator-App** auf einem Mobilgerät oder Desktop, zum Beispiel Microsoft Authenticator, Google Authenticator, Aegis oder 1Password. Die Einrichtung der Zwei-Faktor-Authentifizierung ist verpflichtend und ohne sie kann die Anwendung nicht genutzt werden.

Optional, aber empfehlenswert:

- Eine **Gläubiger-Identifikationsnummer** der Deutschen Bundesbank für Ihre Firma. Sie ist kein Pflichtfeld, erscheint aber, wenn hinterlegt, auf dem Mandatsdokument.
- Ein aktives **Stripe-Webhook-Secret**, damit Statusänderungen (erfolgreiche Einzüge, Rücklastschriften, Erstattungen) sofort ankommen, statt nur beim manuellen Abgleich.

## 3. Registrierung, E-Mail-Bestätigung, Zwei-Faktor-Authentifizierung, Einrichtung

### 3.1 Firmenaccount registrieren

**Ziel:** Einen neuen Firmenaccount mit persönlichem Zugang anlegen.

**Voraussetzungen:** Firmenname, ein noch nicht verwendetes Mandatspräfix (2 bis 10 Zeichen, zum Beispiel ein Firmenkürzel), eine persönliche E-Mail-Adresse, ein Passwort mit mindestens 10 Zeichen.

**Schritte:**

1. Die Seite "Firmenaccount registrieren" öffnen.
2. Im Abschnitt "Firma" den Firmennamen eintragen. Dieser erscheint später als Zahlungsempfänger auf dem SEPA-Mandat.
3. Das Mandatspräfix eintragen. Es bildet den Anfang der SEPA-Mandatsreferenzen Ihrer Kunden, zum Beispiel "MF10045". Das Präfix ist nach der Einrichtung nicht mehr änderbar.
4. Im Abschnitt "Ihr persönlicher Zugang" Vorname, Nachname, E-Mail-Adresse und Passwort eintragen und wiederholen.
5. Die Checkbox zu AGB und Datenschutzerklärung setzen. Ist ein Auftragsverarbeitungsvertrag veröffentlicht, zusätzlich diese Checkbox setzen; sie schließt den Vertrag im Namen der Firma ab.
6. Auf "Firmenaccount erstellen" klicken.

**Erwartetes Ergebnis:** Sie sind angemeldet, Inhaber des neuen Firmenaccounts und werden zur E-Mail-Bestätigung beziehungsweise zur Einrichtung der Zwei-Faktor-Authentifizierung weitergeleitet.

**Typische Fehler und Lösung:**

- "Die Passwörter stimmen nicht überein.": Beide Passwortfelder gleich ausfüllen.
- "Das Mandatspräfix ... wird bereits verwendet.": Ein anderes Präfix wählen.
- "Diese E-Mail-Adresse ist bereits registriert.": Mit dem bestehenden Konto anmelden oder, falls diese Adresse bereits einer anderen Firma zugeordnet ist, über die bestehende Anmeldung eine weitere Firma anlegen (Kapitel 4.4).
- Ist die Registrierung deaktiviert, erscheint ein entsprechender Hinweis mit dem Verweis auf den Betreiber (siehe Impressum).

### 3.2 E-Mail-Adresse bestätigen

Ist der Mailversand auf der Installation aktiv, erhalten Sie nach der Registrierung eine E-Mail mit einem Bestätigungslink. Die Seite "E-Mail-Adresse bestätigen" zeigt, an welche Adresse die Mail gesendet wurde, und bietet eine Schaltfläche "E-Mail erneut senden" (mit einer Wartezeit von einer Minute zwischen zwei Anforderungen). Ohne aktiven Mailversand entfällt dieser Schritt und die Adresse gilt als bestätigt.

**Typischer Fehler:** "Der Bestätigungslink ist ungültig oder abgelaufen.": Über "E-Mail erneut senden" einen neuen Link anfordern.

### 3.3 Zwei-Faktor-Authentifizierung einrichten

**Ziel:** Den zweiten Anmeldefaktor einrichten, ohne den die Anwendung nicht nutzbar ist.

**Voraussetzungen:** Eine Authenticator-App auf einem Gerät.

**Schritte:**

1. Auf der Seite "Zwei-Faktor-Authentifizierung einrichten" die Authenticator-App öffnen.
2. Den angezeigten QR-Code scannen oder den darunter angezeigten Schlüssel manuell eingeben.
3. Den 6-stelligen Code, den die App jetzt anzeigt, in das Feld "Code aus der App" eintragen.
4. Optional die Checkbox "Dieses Gerät für 90 Tage merken" setzen, wenn es sich um ein eigenes, nicht gemeinsam genutztes Gerät handelt.
5. Auf "Code überprüfen" klicken.
6. Die angezeigten zehn Recovery-Codes an einem sicheren Ort speichern oder ausdrucken. Jeder Code ist genau einmal gültig und wird nur jetzt angezeigt.
7. Die Checkbox "Recovery-Codes sicher gespeichert." setzen und auf "Weiter" klicken.

**Erwartetes Ergebnis:** Die Zwei-Faktor-Authentifizierung ist aktiv, Sie gelangen zur Einrichtung beziehungsweise zum Dashboard.

**Typischer Fehler:** "Der Code ist ungültig.": Die Uhrzeit des Geräts mit der Authenticator-App prüfen (zeitbasierte Codes gelten nur 30 Sekunden) und den aktuellen Code erneut eingeben.

Die Option "Dieses Gerät für 90 Tage merken" entbindet nur von der Codeabfrage, das Passwort bleibt bei jeder Anmeldung erforderlich. Bei sicherheitsrelevanten Änderungen kann trotzdem eine erneute Bestätigung mit dem Authenticator-Code verlangt werden. Details zu gemerkten Geräten finden Sie in Kapitel 12.

[Screenshot: Zwei-Faktor-Authentifizierung einrichten, QR-Code und Eingabefeld]

### 3.4 Einrichtung (Onboarding)

Nach der Anmeldung und der Einrichtung der Zwei-Faktor-Authentifizierung führt Sie die Seite "Einrichtung" durch die verbleibenden Schritte, mit einer Fortschrittsanzeige ("X von Y Schritten erledigt"):

1. **Konto erstellt**: Bereits abgeschlossen.
2. **Abonnement aktivieren** (falls die Plattform-Abrechnung aktiv ist): Nur der Inhaber kann das Abonnement abschließen (Kapitel 10).
3. **Lexware Office verbinden**: API-Schlüssel aus Lexware Office hinterlegen (Kapitel 5.1).
4. **Stripe verbinden**: Eigenes Stripe-Konto anbinden (Kapitel 5.2).
5. **Verbindungen prüfen**: Beide Verbindungen werden gegen die jeweilige API geprüft.
6. **Firmendaten für SEPA-Mandate** (optional, empfohlen): Anschrift der Firma hinterlegen, damit sie auf dem Mandatsdokument erscheint.
7. **Einrichtung abgeschlossen**: Erste Synchronisation der offenen Rechnungen und Kunden aus Lexware Office.

Jeder offene Schritt zeigt einen Link "Jetzt erledigen" zu der zuständigen Seite, sofern Ihre Rolle die Berechtigung dafür hat; andernfalls den Hinweis "(durch Inhaber bzw. Administrator)". Sind alle Pflichtschritte erledigt, erscheint die Schaltfläche "Einrichtung abschließen".

## 4. Firmendaten, Team und Rollen

### 4.1 Rollen im Überblick

SmartEinzug unterscheidet drei Rollen innerhalb einer Firma:

| Rolle | Bezeichnung in der Oberfläche | Rechte |
|---|---|---|
| owner | Inhaber | Alle Rechte von Administrator zusätzlich: Mitarbeiter einladen, Rollen ändern, Mitarbeiter sperren oder entfernen, Inhaberschaft übertragen, Abonnement abschließen, kündigen oder Tarif wechseln, Protokoll und dessen Export einsehen. Genau eine Person je Firma. |
| admin | Administrator | Zusätzlich zu den Rechten von Mitarbeiter: API-Verbindungen (Lexware Office, Stripe) einrichten und trennen, Firmendaten und SEPA-Regeln ändern, Not-Stopp bedienen, Klärungen abschließen, Mandatsdokumente löschen, Buchhaltungssystem wechseln. |
| member | Mitarbeiter | Voller operativer Zugriff auf Rechnungen, Kunden, SEPA-Mandate und Einzüge; keine Änderung von Verbindungen, Firmendaten, Team oder Abonnement. |

Es gibt in jeder Firma genau einen Inhaber. Die Rolle des Inhabers kann nur durch eine ausdrückliche Übertragung wechseln (Kapitel 4.3).

### 4.2 Mein Profil

**Ziel:** Anzeigenamen, Telefonnummern und Profilbild pflegen.

**Schritte:**

1. Auf "Firmendaten" den Abschnitt "Mein Profil" öffnen.
2. Anzeigenamen eintragen (Pflichtfeld), geschäftliche und private Telefonnummer optional ergänzen.
3. Optional ein Profilbild oder Logo hochladen (JPG, PNG, WebP oder GIF, bis 2 MB). Es erscheint rechts oben im Kopf der Anwendung und ist nur für Mitglieder der eigenen Firma sichtbar.
4. Auf "Profil speichern" klicken.

Telefonnummern sind nur für die eigene Person und den Inhaber der Firma sichtbar und dienen der Erreichbarkeit, zum Beispiel beim Support.

Ist Ihrem Benutzerkonto bereits mehr als eine Firma zugeordnet, ist Multiaccount fest aktiv. Andernfalls können Sie die Checkbox "Multiaccount aktivieren" setzen, um im Profilmenü die "Firmenübersicht" zu erhalten.

### 4.3 Firmendaten und SEPA-Einstellungen

**Ziel:** Anschrift, Gläubiger-Identifikationsnummer und Regeln für Vorabankündigung und Mandatsnachweis pflegen.

**Voraussetzungen:** Rolle Inhaber oder Administrator.

**Schritte:**

1. Auf "Firmendaten" den Abschnitt "Firmendaten und SEPA-Einstellungen" öffnen.
2. Firmenname (Zahlungsempfänger), Straße, PLZ, Ort und Land eintragen.
3. Optional die Gläubiger-Identifikationsnummer eintragen (Format zum Beispiel "DE98ZZZ09999999999"). Sie wird mit Prüfziffer validiert.
4. Die Vorabankündigungsfrist in Tagen festlegen (1 bis 30). Ohne abweichende Vereinbarung mit dem Zahler gilt nach Angaben der Deutschen Bundesbank eine Frist von 14 Kalendertagen; eine kürzere Frist setzt eine eigene Vereinbarung mit Ihren Kunden voraus.
5. Optional die Checkbox "Vorabankündigung per E-Mail durch das Portal senden" setzen. Ist sie aktiv, sind Sofort-Einzüge gesperrt; Einzüge werden stattdessen terminiert und die Ankündigung wird beim Terminieren versendet (der Kunde benötigt dafür eine E-Mail-Adresse).
6. Optional die Checkbox "Handschriftlicher Nachweis erforderlich" setzen. Ist sie aktiv, sind Einzüge erst nach erfasster Unterschrift oder hochgeladenem Mandat möglich.
7. Auf "Speichern" klicken.

Ohne diese Berechtigung zeigt die Seite die Firmendaten nur zur Ansicht mit dem Hinweis, dass Änderungen durch Inhaber oder Administrator erfolgen.

### 4.4 Mitarbeiter einladen und verwalten

**Ziel:** Weitere Personen mit eigenem Zugang zur Firma hinzufügen.

**Voraussetzungen:** Rolle Inhaber. Ein freier Sitzplatz laut Tarif (offene Einladungen zählen mit).

**Schritte:**

1. Auf "Firmendaten" den Abschnitt "Mitarbeiter einladen" öffnen.
2. Vorname und Nachname optional eintragen, E-Mail-Adresse (persönlich) eintragen.
3. Rolle wählen: "Mitarbeiter (voller operativer Zugriff)" oder "Administrator (zusätzlich API-Verbindungen)".
4. Auf "Einladung senden" klicken.

**Erwartetes Ergebnis:** Die eingeladene Person erhält eine E-Mail mit einem 7 Tage gültigen Link (oder, ohne aktiven Mailversand, wird der Link einmalig auf der Seite angezeigt und muss sicher übermittelt werden). Nach dem Klick legt sie ein eigenes Passwort fest und richtet zwingend die Zwei-Faktor-Authentifizierung ein.

**Typische Fehler und Lösung:**

- "Diese Person ist bereits Mitglied der Firma.": Keine erneute Einladung nötig.
- Benutzerlimit erreicht: Eine offene Einladung widerrufen oder einen Benutzer entfernen, oder einen Tarif mit höherem Benutzerlimit wählen (Verweis "Tarif ... ansehen").

Weitere Aktionen in der Mitarbeitertabelle (nur für den Inhaber sichtbar): Rolle ändern, Sperren beziehungsweise Entsperren (beendet bei Sperrung sofort alle Sitzungen der Person), Entfernen (entfernt den Zugriff dauerhaft, Protokolleinträge bleiben erhalten). Offene Einladungen können erneut gesendet oder widerrufen werden.

### 4.5 Inhaberschaft übertragen

**Ziel:** Die Rolle Inhaber an ein anderes aktives Mitglied übergeben.

**Voraussetzungen:** Das Zielmitglied muss aktiv sein und bereits die Zwei-Faktor-Authentifizierung eingerichtet haben.

**Schritte:**

1. Im Abschnitt "Inhaberschaft übertragen" den neuen Inhaber auswählen.
2. Das eigene Passwort eingeben.
3. Den aktuellen Code aus der Authenticator-App eingeben (ein Recovery-Code wird hier nicht akzeptiert).
4. Auf "Inhaberschaft übertragen" klicken und den Sicherheitshinweis bestätigen.

**Erwartetes Ergebnis:** Das gewählte Mitglied ist neuer Inhaber, die bisherige Person wird Mitarbeiter der Firma. Beide erhalten eine Benachrichtigung per E-Mail.

**Typischer Fehler:** "Der neue Inhaber muss zuvor die Zwei-Faktor-Authentifizierung eingerichtet haben.": Das Zielmitglied muss sich zunächst anmelden und die Zwei-Faktor-Authentifizierung abschließen.

### 4.6 Protokoll der Firma

Im Abschnitt "Protokoll (letzte Einträge)" sieht der Inhaber sicherheits- und geldrelevante Aktionen mit Zeitpunkt, Person und Details. Der Export als CSV ist über den Link "Protokoll als CSV exportieren" möglich (Kapitel 11.2).

### 4.7 Firmenübersicht bei mehreren Firmen

**Ziel:** Zwischen mehreren Firmen desselben Benutzerkontos wechseln oder eine weitere Firma anlegen.

**Voraussetzungen:** Multiaccount aktiv (siehe Kapitel 4.2). Der Menüpunkt "Firmenübersicht" erscheint dann im Profilmenü.

**Schritte für den Wechsel:**

1. Auf "Firmenübersicht" die gewünschte Zeile in der Tabelle "Ihre Firmen" suchen.
2. Auf "Wechseln" klicken.

**Schritte für eine neue Firma:**

1. Im Abschnitt "Neue Firma anlegen" Firmennamen und Mandatspräfix eintragen.
2. Auf "Firma anlegen" klicken.

**Erwartetes Ergebnis:** Die neue Firma ist angelegt, Sie sind automatisch deren Inhaber und werden zur Einrichtung weitergeleitet, um dort eigene Lexware-Office- und Stripe-Zugänge einzurichten.

Jede Firma führt vollständig getrennte Kunden, Rechnungen, Einzüge, eine eigene Lexware-Office- beziehungsweise Stripe-Anbindung und ein eigenes Abonnement. Multiaccount verbindet nur Anmeldung und Navigation, nicht die Datenbestände.

## 5. Buchhaltungssystem und Verbindungen

### 5.1 Lexware Office verbinden

**Ziel:** Den API-Zugang zu Lexware Office herstellen, damit Rechnungen und Kunden übernommen werden können.

**Voraussetzungen:** Rolle Inhaber oder Administrator. Ein Lexware-Office-Konto mit freigeschalteter Public API (nach Angaben von Lexware derzeit Tarif Lexware Office XL erforderlich; bitte im eigenen Konto prüfen, da sich Tarifbedingungen ändern können).

**Schritte zur Erstellung des Schlüssels in Lexware Office** (auf der Seite "Einstellungen" als Anleitung hinterlegt):

1. In Lexware Office anmelden und "Einstellungen" öffnen.
2. Zu "Erweiterungen" wechseln, dort "Weitere Apps" beziehungsweise "Public API" wählen.
3. Bei "Public API" auf "Verwalten" klicken.
4. "Schlüssel erstellen" wählen und eine Bezeichnung vergeben, zum Beispiel den Produktnamen.
5. Den erzeugten Schlüssel sofort kopieren. Lexware Office zeigt ihn nur einmal an.

**Schritte in SmartEinzug:**

1. Auf "Einstellungen" den Schlüssel in das Feld "Lexware Office API-Schlüssel" einfügen.
2. Auf "Verbindung herstellen" klicken.

**Erwartetes Ergebnis:** Die Verbindung wird sofort gegen die API geprüft; bei Erfolg erscheint der Firmenname aus Lexware Office und der Status wechselt auf "Verbunden". Der Schlüssel wird verschlüsselt abgelegt und danach nicht mehr angezeigt.

**Weitere Aktionen:** "Verbindung prüfen" testet den bestehenden Schlüssel erneut. "Verbindung trennen" entfernt den Schlüssel; bereits synchronisierte Rechnungen und Kunden bleiben als Historie erhalten.

**Typischer Fehler:** Eine Fehlermeldung der Lexware-Office-API beim Speichern deutet meist auf einen ungültigen oder eingeschränkten Schlüssel hin. Prüfen Sie den gebuchten Tarif und erzeugen Sie bei Bedarf einen neuen Schlüssel.

[Screenshot: Einstellungen, Abschnitt Lexware Office mit Anleitung und Eingabefeld]

### 5.1a sevdesk verbinden

**Ziel:** Den API-Zugang zu sevdesk herstellen, damit Rechnungen und Kunden übernommen werden.

**Voraussetzungen:** Rolle Inhaber oder Administrator. Die Firma muss sevdesk als Buchhaltungssystem gewählt haben (Kapitel 5.4). Ein sevdesk-Konto mit API-Token (nach der offiziellen sevdesk-Hilfe wird dafür der Tarif Buchhaltung Pro, Systemversion 2.0, vorausgesetzt; die Prüfung erfolgt beim Verbindungstest). Die Verbindung muss vom Betreiber bereits freigegeben sein; solange das nicht der Fall ist, zeigt "Einstellungen" anstelle des Eingabefelds den Hinweis, dass die Verbindung zu sevdesk noch nicht freigegeben ist, und Rechnungen werden noch nicht abgerufen.

**Schritte zur Erstellung des Tokens in sevdesk** (auf der Seite "Einstellungen" als Anleitung hinterlegt):

1. In sevdesk anmelden und die "Einstellungen" öffnen.
2. Den Bereich "Benutzer" wählen und den eigenen Benutzer öffnen.
3. Dort den "API-Token" anzeigen lassen oder erzeugen und kopieren (die Menüführung von sevdesk kann sich ändern).

**Schritte in SmartEinzug:**

1. Auf "Einstellungen" den Token in das Feld "sevdesk-API-Token" einfügen.
2. Auf "Verbindung herstellen" klicken.

**Erwartetes Ergebnis:** Die Verbindung wird sofort gegen die sevdesk-API geprüft; bei Erfolg wechselt der Status auf "Verbunden". sevdesk übermittelt beim Verbindungstest keinen Firmennamen, die Karte zeigt deshalb den Hinweis, dass der Firmenname nicht übermittelt wird, statt eines Kontonamens. Der Token wird verschlüsselt abgelegt und danach nicht mehr angezeigt.

**Hinweise:** Solange der Betreiber die Einzüge für sevdesk noch nicht freigegeben hat, sehen Sie einen Hinweis; Rechnungen und Kunden werden bereits gelesen. "Verbindung prüfen" testet den bestehenden Token erneut, "Verbindung trennen" entfernt ihn; bereits synchronisierte Rechnungen und Kunden bleiben als Historie erhalten. Ihr Stripe-Konto können Sie unabhängig davon bereits verbinden.

### 5.2 Stripe verbinden

**Ziel:** Das eigene Stripe-Konto anbinden, über das die Lastschriften laufen.

**Voraussetzungen:** Rolle Inhaber oder Administrator. Ein Stripe-Konto mit einem Secret Key oder Restricted Key.

**Schritte:**

1. Im Stripe-Dashboard "Developers" und "API keys" öffnen und prüfen, ob der Testmodus aktiv ist. Für echte Lastschriften wird ein Live-Schlüssel ("sk_live_..." oder "rk_live_...") benötigt, zum Ausprobieren genügt ein Testschlüssel ("sk_test_...").
2. Empfohlen: Unter "Restricted keys" einen eingeschränkten Schlüssel mit Schreibrecht für Customers, Payment Methods und Payment Intents sowie Leserecht für Charges und Disputes anlegen.
3. Den Schlüssel direkt nach dem Erstellen kopieren, da Stripe Secret Keys nur einmal vollständig anzeigt.
4. In SmartEinzug auf "Einstellungen" den Schlüssel in das Feld "Stripe Secret Key oder Restricted Key" einfügen.
5. Auf "Verbindung herstellen" klicken.

**Erwartetes Ergebnis:** Der Business Name und der Modus (Test oder Live) werden angezeigt. Ein Testschlüssel löst keine echten Lastschriften aus; ein entsprechender Hinweis erscheint im Kopf jeder Seite, solange die Verbindung im Testmodus ist.

**Typischer Fehler:** "Das Format entspricht keinem Stripe Secret Key oder Restricted Key.": Es wurde ein Publishable Key ("pk_...") eingegeben; dieser ist hier nicht verwendbar.

### 5.3 Stripe-Webhook einrichten

**Ziel:** Statusänderungen bei Stripe (erfolgreicher Einzug, Fehlschlag, Rücklastschrift, Erstattung, digital erteiltes Mandat) sofort statt nur beim manuellen Abgleich zu erhalten.

**Schritte:**

1. Im Stripe-Dashboard "Entwickler" > "Webhooks" öffnen und "Endpunkt hinzufügen" wählen. Prüfen, dass der Modus (Test oder Live) zum hinterlegten Schlüssel passt.
2. Als Endpunkt-URL genau die in SmartEinzug angezeigte Adresse eintragen.
3. Unter "Ereignisse auswählen" ausschließlich die folgenden sieben Ereignisse anhaken (nicht "alle Ereignisse" wählen):
   - payment_intent.processing (Lastschrift eingereicht)
   - payment_intent.succeeded (Lastschrift erfolgreich)
   - payment_intent.payment_failed (Lastschrift fehlgeschlagen)
   - charge.dispute.created (Rücklastschrift)
   - charge.refunded (Erstattung ausgeführt)
   - charge.refund.updated (Erstattungsstand geändert)
   - checkout.session.completed (digital erteiltes Mandat)
4. "Endpunkt hinzufügen" klicken, dann auf der Detailseite "Signing Secret" anzeigen lassen (beginnt mit "whsec_") und kopieren.
5. In SmartEinzug das Secret in das Feld eintragen und speichern.
6. In Stripe über "Testereignis senden" prüfen, dass der Endpunkt mit Status 200 antwortet.

**Erwartetes Ergebnis:** Der Status "Webhook-Secret" wechselt von "fehlt" auf "hinterlegt". Wechseln Sie später Schlüssel oder Konto, legen Sie den Webhook im neuen Konto erneut an und tragen Sie ein neues Secret ein.

### 5.4 Buchhaltungssystem wechseln

**Ziel:** Von Lexware Office zu einem anderen Buchhaltungssystem wechseln oder umgekehrt.

Jede Firma arbeitet mit genau einem Buchhaltungssystem. Das Abonnement ist für beide Systeme gleich; bei der Registrierung wird das System vorgewählt, unter "Einstellungen" kann es gewechselt werden. Nach einem Wechsel gilt eine Sperre von vier Wochen. Beim Wechsel wird die Verbindung zum bisherigen System getrennt; Rechnungen, Kunden, Mandate und Einzüge bleiben als Historie erhalten. Wer zwei Buchhaltungen dauerhaft parallel führt, legt dafür eine zweite Firma an (Kapitel 4.7).

**Schritte:**

1. Auf "Einstellungen" im Abschnitt "Buchhaltungssystem" das Ziel-System aufklappen.
2. Optional einen Grund eintragen.
3. Die Checkbox zur Bestätigung des Wechsels setzen.
4. Den aktuellen Code aus der Authenticator-App eintragen.
5. Auf "Jetzt wechseln" klicken.

**Typische Hinderungsgründe:**

- Innerhalb der vierwöchigen Sperrfrist nach dem letzten Wechsel: Datum des nächstmöglichen Wechsels wird angezeigt. Hat der Betreiber die Sperre auf Ihre Anfrage aufgehoben, zeigt die Karte den Zeitpunkt der Aufhebung; ein Wechsel ist dann sofort wieder möglich, danach gilt erneut die Sperre von vier Wochen.
- Noch vorgemerkte, terminierte oder in Verarbeitung befindliche Einzüge: Diese müssen zunächst storniert werden oder abgeschlossen sein.
- Eine Synchronisation läuft gerade: Abschluss abwarten.

**sevdesk:** sevdesk ist als Buchhaltungssystem in Vorbereitung. Solange die Anbindung nicht freigeschaltet ist, zeigt die Oberfläche den Hinweis, dass sevdesk noch nicht für Firmen freigegeben ist, mit einem Link zur unverbindlichen Vormerkung auf der Produktseite. Eine Vormerkung begründet kein Abonnement und keine Zahlungspflicht.

### 5.5 Bestehende Einzüge aus Stripe übernehmen

**Ziel:** Nach einem Neuaufbau oder Wechsel der Installation bereits über das eigene Stripe-Konto eingereichte Lastschriften nachträglich zuordnen, damit nichts doppelt eingezogen wird.

**Voraussetzungen:** Rolle Inhaber oder Administrator, Stripe verbunden.

**Schritte:**

1. Auf "Einstellungen" den Link "Bestehende Einzüge aus Stripe übernehmen" öffnen.
2. Einen Zeitraum wählen (3, 6, 12 oder 24 Monate) und auf "Zahlungen aus Stripe laden (Vorschau)" klicken.
3. Bei mehreren Seiten auf "Weiter laden" klicken, bis alle Zahlungen geladen sind.
4. Die Vorschau prüfen: Anzahl der übernehmbaren, bereits bekannten und nicht zuordenbaren Zahlungen.
5. Den aktuellen 2FA-Code eintragen und auf "Jetzt ... Einzug/Einzüge übernehmen" klicken.

**Erwartetes Ergebnis:** Die zugeordneten Zahlungen erscheinen als Einzüge mit der Herkunft "Import"; betroffene Rechnungen erhalten den passenden Status. Mandate und IBANs werden dabei nicht übernommen, da Stripe die IBAN nicht vollständig herausgibt; diese sind je Kunde unter "SEPA Pflegen" neu zu hinterlegen.

Dieser Vorgang liest Stripe nur lesend aus; es wird nichts bei Stripe verändert und der Import kann gefahrlos wiederholt werden.

## 6. Rechnungssynchronisation

### 6.1 Was automatisch passiert

Läuft die Hintergrundverarbeitung, hält SmartEinzug den Rechnungs- und Kundenbestand automatisch mit Lexware Office aktuell, ohne dass Sie selbst synchronisieren müssen. Die genauen Zeitabstände hängen von der jeweiligen Installation ab; den aktuellen Stand zeigt das Dashboard unter "letzte Synchronisation". Vor jeder Einreichung einer Lastschrift wird zusätzlich der offene Restbetrag der jeweiligen Rechnung bei Lexware Office abgerufen.

### 6.2 Synchronisation manuell starten

**Ziel:** Den aktuellen Stand der Rechnungen aus Lexware Office sofort abrufen.

**Schritte:**

1. Auf "Rechnungen" die Schaltfläche "Mit Lexware Office synchronisieren" anklicken.
2. Der Lauf startet serverseitig in kleinen Schritten. Die Seite kann geschlossen werden; der Lauf wird im Hintergrund fortgesetzt.
3. Der Fortschritt zeigt geprüfte, neue und aktualisierte Rechnungen sowie die aktuelle Phase (Liste abrufen, Rechnungen übernehmen, Abgeschlossene prüfen).
4. Bei Bedarf über "Abbrechen" stoppen oder über "Im Hintergrund weiterlaufen lassen" zum Dashboard zurückkehren.

**Erwartetes Ergebnis:** Eine Meldung nennt die Anzahl geprüfter, neuer, aktualisierter und abgeschlossener Rechnungen.

**Typischer Fehler:** "Synchronisation abgebrochen ... Lexware Office lehnt den API-Schlüssel ab.": Unter "Einstellungen" den Schlüssel prüfen und bei Bedarf neu hinterlegen. Bei "Verbindung zu Lexware Office nicht möglich" später erneut versuchen; Details liefert die Seite "Synchronisationen".

### 6.3 Synchronisationsverlauf einsehen

**Ziel:** Frühere Synchronisationsläufe und deren Kennzahlen einsehen.

**Schritte:**

1. Über "Rechnungen" den Link "Synchronisationen" öffnen, oder direkt die Seite "Synchronisationen" aufrufen.
2. In der Liste einen Lauf anklicken, um Details zu Dauer, Auslöser, Anzahl API-Aufrufen, Fehlerkategorie und Fehlertext einzusehen.

### 6.4 Abgleich mit Lexware Office

**Ziel:** Schnell prüfen, ob Rechnungsnummer und Status zwischen Lexware Office und dem Portal noch übereinstimmen, ohne einzelne Beträge abzurufen.

**Schritte:**

1. Auf "Rechnungen" den Link "Mit Lexware Office abgleichen" öffnen.
2. Auf "Jetzt mit Lexware Office abgleichen" klicken.

**Erwartetes Ergebnis:** Kennzahlen zu offenen Posten laut Lexware Office und im Portal sowie zwei Listen: Rechnungen, die in Lexware Office offen sind, aber lokal fehlen oder einen anderen Status haben (Empfehlung: erneut synchronisieren), und Rechnungen, die lokal offen sind, laut Lexware Office aber nicht mehr (vermutlich bereits bezahlt oder storniert, Empfehlung: erneut synchronisieren).

## 7. Kunden und SEPA-Mandate

### 7.1 Kundenliste

Auf "Kunden" sehen Sie den aus Lexware Office übernommenen Kundenstamm mit Bankverbindung und SEPA-Mandaten. Über die Suche lässt sich nach Name, Kundennummer oder E-Mail-Adresse filtern; über die Schaltflächen "Alle", "Ohne IBAN", "Mandat ohne Unterschrift" und "SEPA: Nein" lässt sich die Liste eingrenzen. Ein Klick auf einen Kunden öffnet die Kundendetails.

Kunden mit einer Sammel-Kundennummer werden als "Laufkunde" gekennzeichnet; für sie wird kein personenbezogenes Mandat erzeugt, da die Kundennummer von mehreren Personen geteilt wird.

### 7.2 IBAN im Portal hinterlegen

**Ziel:** Für einen Kunden eine Bankverbindung erfassen, damit SEPA-Einzüge möglich werden.

**Schritte (schnellster Weg über "SEPA Pflegen"):**

1. "SEPA Pflegen" zeigt automatisch genau einen Kunden ohne aktive IBAN und noch nicht getroffener SEPA-Entscheidung.
2. IBAN, Kontoinhaber und optional BIC eintragen, auf "IBAN speichern (SEPA: Ja)" klicken. Damit wird SEPA-Einzug automatisch auf "Ja" gesetzt.
3. Alternativ auf "Kein SEPA" klicken, wenn für diesen Kunden kein SEPA-Einzug erfolgen soll.
4. Nach dem Speichern erscheint automatisch der nächste offene Kunde.

**Alternativ über die Kundendetails:** Im Abschnitt "Bankverbindung" die neue IBAN, den Kontoinhaber und optional den BIC eintragen und auf "IBAN speichern" klicken.

**Erwartetes Ergebnis:** Die IBAN erscheint maskiert in der Liste, die Zahlungsmethode wird bei Stripe registriert (sofort oder automatisch beim ersten Einzug) und ein vorhandenes Mandat wird an diese IBAN gebunden.

**Weitere Aktion:** Eine bestehende IBAN kann über "Deaktivieren" außer Kraft gesetzt werden.

[Screenshot: SEPA Pflegen, Formular für IBAN-Eingabe]

### 7.3 SEPA-Mandat erzeugen und drucken

**Ziel:** Für einen Kunden ein Mandatsdokument mit automatisch vergebener Mandatsreferenz erstellen.

**Voraussetzungen:** Der Kunde ist kein Laufkunde.

**Schritte:**

1. In den Kundendetails im Abschnitt "SEPA-Mandate" auf "SEPA-Mandat erzeugen (Dokument)" klicken.
2. Die Druckansicht öffnet sich automatisch; über "Drucken / Als PDF speichern" ausdrucken oder als PDF sichern.
3. Nach Unterschrift durch den Kunden im Abschnitt "SEPA-Mandate" bei "Unterschrift erfassen" das Datum und den Ort der Unterschrift eintragen und auf "Unterschrift erfassen" klicken.

**Erwartetes Ergebnis:** Das Mandat ist ab der erfassten Unterschrift für Einzüge nutzbar (sofern die Firmeneinstellung "Handschriftlicher Nachweis erforderlich" dies verlangt, andernfalls bereits ab Erzeugung nutzbar). Die Mandatsreferenz setzt sich aus dem Mandatspräfix der Firma und der Kundennummer zusammen und ist je Firma eindeutig.

Ein Mandat erlischt nach 36 Monaten ohne Einzug. Unterschriebene Mandate sind aufzubewahren (Empfehlung mindestens 36 Monate nach der letzten Nutzung); das Portal löscht Mandate nie, sondern widerruft sie.

### 7.4 Unterschriebenes Mandat hochladen

**Ziel:** Einen Scan oder ein Foto des unterschriebenen SEPA-Mandats hinterlegen.

**Schritte:**

1. In den Kundendetails im Abschnitt "Unterschriebenes Mandat hochladen" (oder in "SEPA Pflegen") die Datei auswählen (PDF, JPG oder PNG, bis 10 MB).
2. Bei mehreren aktiven Mandaten das zutreffende Mandat zuordnen.
3. Optional eine Notiz eintragen.
4. Ist die Unterschrift noch nicht erfasst, die Checkbox "Unterschrift gleich erfassen" mit Datum und Ort setzen.
5. Auf "Dokument hochladen" klicken.

**Erwartetes Ergebnis:** Das Dokument erscheint in der Liste hochgeladener Mandatsdokumente mit Größe, Zeitpunkt und hochladender Person. Es kann heruntergeladen und, mit der Rolle Inhaber oder Administrator, gelöscht werden. Der Upload ersetzt nicht die Aufbewahrungspflicht des Originals.

### 7.5 Mandat digital anfordern

**Ziel:** Dem Kunden einen Link zusenden, über den er das SEPA-Mandat digital erteilt, ohne dass die IBAN im Portal manuell eingegeben werden muss.

**Voraussetzungen:** Diese Funktion muss auf der Installation freigeschaltet sein; für den Kunden muss eine E-Mail-Adresse hinterlegt sein.

**Schritte:**

1. In den Kundendetails im Abschnitt "SEPA-Mandate" auf "Mandat digital anfordern" klicken und bestätigen.
2. Der Kunde erhält per E-Mail einen 14 Tage gültigen Link (öffentliche Seite ohne Anmeldung), liest den Mandatstext und gibt seine Bankverbindung direkt bei Stripe ein.

**Erwartetes Ergebnis:** Nach Bestätigung durch den Kunden ist das Mandat sofort einsatzbereit; die IBAN liegt dann nur maskiert vor. Eine offene Anforderung kann über "Widerrufen" zurückgenommen werden.

### 7.6 Mandat widerrufen

**Ziel:** Ein aktives SEPA-Mandat für zukünftige Einzüge ungültig machen.

**Schritte:**

1. In den Kundendetails in der Mandatstabelle bei dem betreffenden Mandat auf "Widerrufen" klicken.
2. Optional einen Grund eintragen und den Sicherheitshinweis bestätigen.

**Erwartetes Ergebnis:** Das Mandat erhält den Status "Widerrufen" und bleibt zur Aufbewahrung gespeichert. Für weitere Einzüge ist ein neues Mandat erforderlich.

### 7.7 SEPA-Einzug für einen Kunden deaktivieren

Im Kundendetail (Abschnitt "SEPA-Einzug") oder auf "Rechnungen" lässt sich SEPA-Einzug für einen Kunden auf "Nein" setzen. Dies gilt für alle Rechnungen mit dieser Kundennummer; solche Rechnungen lassen sich dann nicht mehr per Lastschrift einziehen, bis SEPA-Einzug wieder auf "Ja" gesetzt wird.

## 8. Lastschriften

### 8.1 Sofort-Einzug

**Ziel:** Eine einzelne offene Rechnung sofort per Lastschrift einziehen.

**Voraussetzungen:** Kunde mit hinterlegter IBAN, SEPA-Einzug aktiv, keine offene Klärung, kein Not-Stopp.

**Schritte:**

1. Auf "Rechnungen" bei der betreffenden Zeile auf "Einziehen" (oder, bei erkannter Teilzahlung, "Restbetrag einziehen") klicken.
2. Den Sicherheitshinweis bestätigen.

**Erwartetes Ergebnis:** Je nach Karenzzeit und Einreichfenster (Kapitel 8.3) wird die Lastschrift entweder sofort bei Stripe eingereicht oder zunächst vorgemerkt und automatisch zum frühestmöglichen Zeitpunkt eingereicht; bis zur Einreichung ist der Einzug stornierbar.

**Typische Hinderungsgründe:** Keine IBAN hinterlegt (Link zu den Kundendetails), Klärungsbedarf offen, SEPA-Einzug für den Kunden deaktiviert, Rechnung laut Lexware Office nicht mehr offen.

### 8.2 Sammel-Einzug

**Ziel:** Alle einzugsbereiten Rechnungen in einem Schritt einreichen.

**Schritte:**

1. Auf "Einzüge" die Schaltfläche "Alle bereiten Einzüge jetzt einreichen" (oder, bei aktiver Karenzzeit, "Alle bereiten Einzüge vormerken") anklicken. Die Schaltfläche zeigt Anzahl und Gesamtbetrag.
2. Den Sicherheitshinweis bestätigen.

**Erwartetes Ergebnis:** Eine Meldung nennt Anzahl der eingereichten beziehungsweise vorgemerkten Rechnungen, den Gesamtbetrag und die Anzahl fehlgeschlagener Versuche mit Gründen.

Diese Schaltfläche steht nicht zur Verfügung, wenn die Vorabankündigung per E-Mail aktiv ist; in diesem Fall werden Einzüge stattdessen über "Rechnungen" terminiert.

### 8.3 Terminierter Einzug, Karenzzeit und Einreichfenster

**Terminieren:** Auf "Rechnungen" bei einer Rechnung ein Datum wählen und auf "Terminieren" klicken. Das früheste wählbare Datum berücksichtigt Wochenenden und, bei aktiver Vorabankündigung, die eingestellte Vorlauffrist.

**Karenzzeit und Einreichfenster:** Ein Sofort-Einzug wird häufig nicht unmittelbar an Stripe übergeben, sondern zunächst als "Vorgemerkt" gespeichert und erst nach Ablauf einer Karenzzeit innerhalb des Einreichfensters eingereicht. Die genaue Regel zeigt der Hinweistext auf "Einzüge" unter "Karenzzeit" an, zum Beispiel "Einreichung bei Stripe frühestens X Stunden nach dem Auslösen, nur im Einreichfenster HH:MM bis HH:MM Uhr". Bis zur tatsächlichen Einreichung kann jeder Einzug storniert werden. Terminierte Einzüge werden am Fälligkeitstag ebenfalls nur innerhalb des Einreichfensters eingereicht.

**Umterminieren:** Auf "Einzüge" bei einem noch nicht eingereichten Einzug ein neues Datum wählen und auf "Umterminieren" klicken.

**Fällige Einzüge jetzt einreichen:** Auf "Einzüge" zeigt die Schaltfläche "Fällige Einzüge jetzt einreichen" die Anzahl der fälligen, noch nicht eingereichten Einzüge. Außerhalb des Einreichfensters ist sie gesperrt; für Inhaber und Administratoren steht dann die Option "Ausnahmsweise jetzt einreichen" mit zusätzlicher 2FA-Bestätigung zur Verfügung, gedacht für Ausnahmefälle wie einen ausgefallenen Cron-Lauf.

**Überfällige Einzüge:** Ein noch nicht eingereichter Einzug, dessen Termin länger als die eingestellte Anzahl Tage zurückliegt (zum Beispiel nach einem Not-Stopp), gilt als überfällig und wird nicht automatisch nachgeholt. Er muss unter "Einzüge" neu terminiert oder storniert werden.

### 8.4 Vorabankündigung

Ist die Vorabankündigung per E-Mail in den Firmeneinstellungen aktiv, sendet das Portal beim Terminieren automatisch eine Ankündigung an den Kunden; Sofort-Einzüge sind dann nicht verfügbar. Ist sie nicht aktiv, gilt die Rechnung selbst mit Angabe von Betrag, Fälligkeit und Mandatsreferenz als Vorabankündigung.

**Inhalt der E-Mail:** Betreff „Vorabankündigung SEPA-Lastschrift“ mit der Rechnungsnummer. Der Text nennt Rechnungsnummer, Betrag und Einzugstermin, Ihre Firma als Zahlungsempfänger, die Mandatsreferenz, Ihre Gläubiger-Identifikationsnummer (sofern in den Firmendaten hinterlegt) sowie den Hinweis, dass der Einzug über den Zahlungsdienstleister Stripe erfolgt und für Kontodeckung zu sorgen ist. Die E-Mail trägt das Design des Portals mit den Pflichtangaben des Betreibers im Fußbereich; ein eigener Text der Firma ist derzeit nicht vorgesehen. Wird ein terminierter Einzug auf ein neues Datum verschoben, geht eine neue Ankündigung mit dem neuen Termin an den Kunden.

**Hinweis zu Stripe:** Stripe kann für SEPA-Lastschriften eigene Benachrichtigungen an Zahler senden; ob und in welcher Form das geschieht, richtet sich nach den Einstellungen Ihres Stripe-Kontos (Kundenmails) und ist nicht Teil des Portals. Verlassen Sie sich für die Vorabankündigung nicht auf Stripe: Entweder ist die Ankündigung durch das Portal aktiv, oder die Rechnung selbst enthält Betrag, Fälligkeit und Mandatsreferenz.

### 8.5 Not-Stopp

**Ziel:** Alle SEPA-Einzüge der eigenen Firma sofort anhalten, zum Beispiel bei Verdacht auf fehlerhafte Beträge.

**Voraussetzungen:** Rolle Inhaber oder Administrator.

**Schritte zum Aktivieren:**

1. Auf "Not-Stopp" optional einen Grund eintragen.
2. Optional die Checkbox setzen, um zusätzlich alle vorgemerkten und terminierten Einzüge zu stornieren (die Rechnungen werden dadurch wieder offen).
3. Auf "Not-Stopp jetzt aktivieren" klicken und bestätigen.

**Wirkung:** Kein Sofort- und kein Sammel-Einzug mehr, keine automatische Einreichung fälliger terminierter Einzüge (weder per Schaltfläche noch per Cron). Statusabgleich, Rechnungssynchronisation und Webhooks laufen weiter. Bereits bei Stripe eingereichte Lastschriften werden nicht gestoppt.

**Schritte zum Aufheben:**

1. Optional einen Grund für die Freigabe eintragen.
2. Die Checkbox "Ich habe geprüft, dass Einzüge wieder eingereicht werden dürfen." setzen.
3. Den aktuellen 2FA-Code eintragen und auf "Not-Stopp aufheben" klicken.

**Erwartetes Ergebnis:** Fällige Einzüge werden im nächsten Einreichfenster automatisch eingereicht; überfällige Einzüge (Termin älter als die eingestellte Anzahl Tage) müssen unter "Einzüge" einzeln neu terminiert oder storniert werden.

Ein zusätzlicher, plattformweiter Not-Stopp durch den Betreiber ist möglich und wird als eigener Hinweis angezeigt; er kann nur vom Betreiber aufgehoben werden.

### 8.6 Storno eines Einzugs

**Ziel:** Einen noch nicht bei Stripe eingereichten Einzug zurücknehmen.

**Schritte:**

1. Auf "Einzüge" bei einem stornierbaren Einzug (Status "Vorgemerkt" oder "Terminiert", noch nicht eingereicht) auf "Stornieren" klicken.
2. Den Sicherheitshinweis bestätigen.

**Erwartetes Ergebnis:** Es wird nichts bei Stripe eingereicht, die Rechnung ist wieder offen und kann korrigiert oder erneut eingezogen werden.

Ein bereits bei Stripe eingereichter Einzug lässt sich über das Portal nicht mehr zurückholen; eine Rückerstattung erfolgt gegebenenfalls über den Kunden oder über Stripe (Kapitel 9).

## 9. Status der Einzüge

Diese Übersicht erklärt jeden auf "Einzüge" und in den Kundendetails angezeigten Status, was er bedeutet, wo eine Wartezeit oder ein externer Schritt liegt und was gegebenenfalls zu tun ist.

| Status (Anzeige) | Bedeutung | Was zu tun ist |
|---|---|---|
| **Vorgemerkt** | Ein Sofort-Einzug wurde ausgelöst, aber noch nicht bei Stripe eingereicht (Karenzzeit oder Einreichfenster noch nicht erreicht). | Nichts zwingend erforderlich. Bis zur Einreichung stornierbar; die Übersicht "Karenzzeit" nennt den frühesten Einreichzeitpunkt. |
| **Terminiert** | Der Einzug ist für ein bestimmtes Datum vorgesehen, aber noch nicht eingereicht. | Bei Bedarf umterminieren oder stornieren, solange der Termin nicht überschritten ist. |
| **Wird eingereicht** | Die Übergabe an Stripe läuft gerade. | Kurzer Zwischenzustand, keine Aktion nötig. |
| **In Bearbeitung** | Stripe hat die SEPA-Lastschrift entgegengenommen; die Bank des Kunden verarbeitet sie noch. | Warten. "Status mit Stripe abgleichen" prüft den aktuellen Stand (nur Lesezugriff), oder auf den Stripe-Webhook warten. |
| **Erfolgreich** | Die Lastschrift wurde von der Bank des Kunden nicht zurückgewiesen und gilt als eingezogen. | Keine weitere Aktion. Eine spätere Rücklastschrift bleibt innerhalb der gesetzlichen Frist möglich (siehe Mandatstext, acht Wochen ab Belastungsdatum). |
| **Fehlgeschlagen** | Die Lastschrift wurde von der Bank abgelehnt (zum Beispiel wegen unzureichender Deckung oder ungültiger IBAN). | Ursache mit dem Kunden klären, bei Bedarf neue IBAN hinterlegen und erneut einziehen. |
| **Rücklastschrift** | Der Kunde oder seine Bank hat die bereits gutgeschriebene Lastschrift nachträglich zurückgebucht. | Ursache klären; ein erneuter Einzug erfolgt nicht automatisch. |
| **Erstattet** | Der Einzug wurde ganz oder teilweise über Stripe erstattet. Bei Vollerstattung geht die Rechnung auf den Status "Offen" zurück, jedoch mit Klärungsvermerk statt automatischem Neu-Einzug. | Die zugehörige Rechnung zeigt "Klärungsbedarf offen"; ein Inhaber oder Administrator prüft den Sachverhalt und schließt die Klärung über "Klärung abgeschlossen" ab. Danach ist die Rechnung wieder manuell einziehbar. |
| **Storniert** | Der Einzug wurde vor der Einreichung zurückgenommen. | Keine weitere Aktion; die Rechnung ist wieder offen. |
| **Überfällig** | Ein noch nicht eingereichter Einzug, dessen Termin die eingestellte Anzahl Tage überschritten hat. | Unter "Einzüge" neu terminieren oder stornieren. |
| **Unklarer Versuch** (Abschnitt "Unklare Einzugsversuche") | Bei der Einreichung hat Stripe nicht geantwortet (Zeitüberschreitung, Netzwerkfehler) oder der Aufruf wurde unterbrochen; ob eine Lastschrift entstanden ist, ist noch nicht sicher. | Auf "Einzüge" oder "Not-Stopp"/Dashboard auf "Unklare Versuche prüfen" klicken. Das Portal prüft per Lesezugriff bei Stripe, trägt einen bereits entstandenen Einzug nach oder gibt die Rechnung wieder frei. Bis zur Klärung erfolgt kein erneuter Einreichversuch. |

Der Statusabgleich ("Status mit Stripe abgleichen") prüft laufende Einzüge nur lesend; es bewegt sich dabei kein Geld. Eine spätere Rücklastschrift oder Erstattung erkennt zuverlässig nur der Stripe-Webhook (Kapitel 5.3); ohne Webhook werden solche Änderungen nur beim manuellen Abgleich beziehungsweise über den regelmäßigen Hintergrundlauf sichtbar.

[Screenshot: Einzüge, Tabelle mit verschiedenen Status-Badges]

## 10. Abonnement

**Hinweis zum Preis:** Nennen Sie Preise stets in der Formulierung der Oberfläche. Der aktuell in der Anwendung angezeigte Starttarif lautet UNLIMITED START mit einem Einführungspreis von 25,00 EUR netto zzgl. USt. je 4 Wochen (zuvor 50,00 EUR), mit unbegrenzten Einzügen und unbegrenzten Mitarbeitern. Der Einführungspreis gilt für Firmenaccounts, die bis zum Ende des laufenden Kalendermonats angelegt werden; bereits angelegte Accounts behalten ihren Preis, solange das Abonnement läuft. Das genaue Datum verschiebt sich fortlaufend und wird in der Anwendung stets tagesaktuell berechnet und angezeigt.

### 10.1 Abonnement abschließen

**Ziel:** Den Firmenaccount durch ein aktives Abonnement freischalten.

**Voraussetzungen:** Rolle Inhaber. Ist die Plattform-Abrechnung noch nicht freigeschaltet, entstehen keine Einschränkungen und keine Kosten; ein Hinweis auf der Seite "Abonnement" zeigt diesen Zustand an.

**Schritte:**

1. Auf "Firmendaten" oder über den Hinweisbanner auf "Jetzt freischalten" beziehungsweise auf "Abonnement" die Schaltfläche "Zahlungspflichtig abonnieren" (nach Ansicht der Bestellübersicht mit Leistung, Preis, Laufzeit, Kündigungsregel und Vertragspartner) anklicken.
2. Die Checkboxen "Ich schließe das Abonnement als Unternehmen ... ab." und zu den AGB setzen.
3. Auf "Zahlungspflichtig abonnieren" klicken.
4. Auf der folgenden, gesicherten Bezahlseite von Stripe die Zahlungsmethode hinterlegen.

**Erwartetes Ergebnis:** Nach Abschluss der Zahlung wird der Firmenaccount freigeschaltet; der Status aktualisiert sich in Kürze automatisch.

### 10.2 Zahlungsmethode ändern, Rechnungen einsehen

Über die Schaltfläche "Zahlungsmethode ändern / Rechnungen ansehen" beziehungsweise "Stripe-Kundenportal öffnen" gelangen Sie zum gesicherten Stripe-Kundenportal. Änderungen am Abonnement, die dort vorgenommen werden, meldet Stripe automatisch an die Anwendung zurück. Frühere Rechnungen erscheinen zusätzlich im Rechnungsarchiv auf der Seite "Abonnement".

### 10.3 Tarif wechseln

Ist mehr als ein öffentlicher Tarif verfügbar, zeigt "Abonnement" im Abschnitt "Tarif wechseln" die verfügbaren Alternativen mit Kennzeichnung als Upgrade oder Downgrade. Ein Upgrade gilt sofort, die anteilige Differenz wird sofort über die hinterlegte Zahlungsmethode eingezogen. Ein Downgrade gilt ebenfalls sofort, die anteilige Gutschrift erscheint auf der nächsten Rechnung. Bestandskunden des Starttarifs behalten ihre Konditionen, solange sie nicht selbst wechseln.

### 10.4 Abonnement kündigen

**Ziel:** Das laufende Abonnement zum Ende der aktuellen Abrechnungsperiode beenden.

**Schritte:**

1. Auf "Abonnement" im Abschnitt "Abonnement kündigen" das eigene Passwort und den aktuellen 2FA-Code eintragen.
2. Auf "Zum Periodenende kündigen" klicken und bestätigen.

**Erwartetes Ergebnis:** Die Kündigung wirkt zum Ende der laufenden Abrechnungsperiode; bis dahin bleibt der Zugriff bestehen. Eine bereits eingereichte Kündigung kann über "Kündigung zurücknehmen" (gleiche Bestätigung mit Passwort und 2FA-Code) rückgängig gemacht werden, solange die Periode noch läuft.

## 11. Rechtliches, Protokoll und Export

### 11.1 Vertragsdokumente akzeptieren

**Ziel:** Veröffentlichte Vertragsdokumente wie den Auftragsverarbeitungsvertrag (Art. 28 DSGVO) für die Firma abschließen.

**Voraussetzungen:** Rolle Inhaber oder Administrator für das Akzeptieren; Einsicht für alle Mitglieder.

**Schritte:**

1. Auf "Rechtliches" das jeweilige Dokument öffnen (Fassung, Veröffentlichungsdatum und Status werden angezeigt).
2. Über "Vollständigen Text anzeigen" den kompletten Text lesen (druckbar über "Drucken").
3. Die Checkbox "Ich habe die Fassung ... gelesen und schließe das Dokument im Namen von ... ab." setzen.
4. Auf "Akzeptieren" klicken.

**Erwartetes Ergebnis:** Der Status wechselt auf "Akzeptiert am ... durch ...". Erscheint eine neue Fassung eines bereits akzeptierten Dokuments, zeigt die Seite den Hinweis "Neue Fassung, Zustimmung erforderlich" mit dem Datum der zuletzt akzeptierten Fassung.

### 11.1a Zustimmungen einsehen

**Ziel:** Nachsehen, wann und in welcher Fassung AGB und Datenschutzerklärung akzeptiert wurden.

**Voraussetzungen:** angemeldeter Benutzer.

**Schritte:**

1. Für die Firma: auf "Rechtliches" den Abschnitt "Zustimmungen zu AGB und Datenschutzerklärung" öffnen. Die Tabelle nennt Gegenstand, Fassung, Zeitpunkt (UTC), Person und Weg (Registrierung).
2. Für das eigene Konto: unter "Sicherheit" den Abschnitt "Meine Zustimmungen" öffnen.

**Erwartetes Ergebnis:** Je Registrierung erscheinen zwei Einträge (AGB, Datenschutzerklärung). Konten, die vor dieser Funktion angelegt wurden, zeigen einen Hinweis statt einer Liste; ihre Zustimmung erfolgte im Registrierungsformular zum Zeitpunkt der Registrierung.

**Typische Fehler:** Keine.

### 11.2 Berufliche Verschwiegenheitspflicht angeben

Unterliegt Ihre Firma einer beruflichen Verschwiegenheitspflicht (§ 203 StGB), etwa als Rechtsanwalt, Steuerberater, Wirtschaftsprüfer, Notar, Arzt oder Apotheker, setzen Sie im Abschnitt "Berufliche Verschwiegenheitspflicht (§ 203 StGB)" die entsprechende Checkbox und tragen optional die Berufsgruppe ein. Damit wird Ihnen die passende Verschwiegenheitsvereinbarung zur Zustimmung angezeigt.

### 11.3 Nachweise und weitere Dokumente

Der Abschnitt "Nachweise" listet alle bisherigen Zustimmungen mit Fassung, Zeitpunkt, Person und Weg (Registrierung, Betreiber oder Backend). Unter "Weitere Dokumente" finden sich Links zu AGB, Datenschutzerklärung und Impressum der Betreiberin.

### 11.4 Protokoll und Export

Auf "Firmendaten" zeigt der Abschnitt "Protokoll (letzte Einträge)" sicherheits- und geldrelevante Aktionen mit Zeitpunkt und Person (nur für den Inhaber sichtbar). Die Aufbewahrung ist zeitlich begrenzt; ältere Einträge werden automatisch gelöscht, Einzüge, Mandate und Vertragszustimmungen bleiben davon unabhängig nachweisbar.

**Export:**

- **Einzugsjournal als CSV:** Über das Profilmenü ("Export") oder auf "Einzüge" über "Journal als CSV exportieren" beziehungsweise "Gefilterte Einzüge als CSV exportieren" (berücksichtigt den aktuell gewählten Statusfilter). Enthält je Zeile unter anderem Rechnungsnummer, Kunde, Betrag, Status, Zeitpunkte, Mandatsreferenzen und auslösende Person.
- **Protokoll als CSV:** Über "Firmendaten", Abschnitt Protokoll, Link "Protokoll als CSV exportieren" (nur Inhaber).

Beide Exporte öffnen sich als Download im UTF-8-Format mit Semikolon als Trennzeichen (für Tabellenprogramme geeignet).

## 12. Sicherheit

### 12.1 Passwort ändern

**Schritte:**

1. Auf "Sicherheit" das aktuelle Passwort sowie zweimal das neue Passwort (mindestens 10 Zeichen) eintragen.
2. Ist die Zwei-Faktor-Authentifizierung aktiv und die letzte Codeeingabe länger als 5 Minuten her, zusätzlich den aktuellen Code eintragen.
3. Auf "Passwort ändern" klicken.

**Erwartetes Ergebnis:** Das Passwort ist geändert, alle gemerkten Geräte werden dabei vergessen.

### 12.2 Gemerkte Geräte

Browser, in denen bei der Anmeldung "Dieses Gerät für 90 Tage merken" gewählt wurde, erscheinen im Abschnitt "Gemerkte Geräte" mit Bereich, Zeitpunkt der Freigabe, letzter Nutzung und Ablaufdatum. Die Freigabe ist an das Cookie dieses Browsers gebunden, endet fest nach 90 Tagen und verlängert sich nicht durch weitere Anmeldungen. Über "Gerät vergessen" beziehungsweise "Alle Geräte vergessen" lässt sich die Freigabe jederzeit widerrufen; danach ist bei der nächsten Anmeldung wieder der Authenticator-Code erforderlich. Das Passwort bleibt in jedem Fall erforderlich, auch auf einem gemerkten Gerät.

### 12.3 Überall abmelden

Über "Überall abmelden" werden alle Sitzungen des eigenen Kontos auf allen Geräten beendet und sämtliche Gerätefreigaben widerrufen. Die nächste Anmeldung erfordert wieder Passwort und Authenticator-Code.

### 12.4 Recovery-Codes neu erzeugen

**Ziel:** Zehn neue Einmal-Codes für den Fall des Geräteverlusts erzeugen.

**Schritte:**

1. Auf "Sicherheit" im Abschnitt "Recovery-Codes neu erzeugen" Passwort und aktuellen Code (oder Recovery-Code) eintragen.
2. Auf "Neue Codes erzeugen" klicken.

**Erwartetes Ergebnis:** Zehn neue Codes werden angezeigt (nur jetzt, danach nicht mehr abrufbar) und alle bisherigen Codes werden ungültig. Eine Sicherheits-E-Mail wird versendet.

### 12.5 Zwei-Faktor-Authentifizierung zurücksetzen

**Ziel:** Beim Wechsel des Geräts die Zwei-Faktor-Authentifizierung neu einrichten.

**Schritte:**

1. Auf "Sicherheit" im Abschnitt "Zwei-Faktor-Authentifizierung zurücksetzen" Passwort und aktuellen Code (oder Recovery-Code) eintragen.
2. Auf "2FA zurücksetzen" klicken und bestätigen.

**Erwartetes Ergebnis:** Sie werden abgemeldet und richten die Zwei-Faktor-Authentifizierung bei der nächsten Anmeldung neu ein.

**Ohne Zugang zu Passwort und Code:** Wenden Sie sich an den Inhaber Ihres Firmenaccounts beziehungsweise an den Support (Kapitel 13).

### 12.6 Letzte Anmeldeversuche

Der Abschnitt "Letzte Anmeldeversuche" zeigt die letzten fünfzehn Versuche mit Zeitpunkt, Stufe (Passwort, Authenticator, Recovery-Code, Gerätefreigabe, Registrierungsversuch), Ergebnis und IP-Adresse.

## 13. Hilfe und Support

### 13.1 Hilfe-Center

Über den Menüpunkt "Hilfe" gelangen Sie zum Hilfe-Center mit Anleitungen, häufigen Fragen und einer Suche über Titel, Zusammenfassung und Volltext. Die Themen sind links aufgelistet, jedes Thema öffnet eine ausführliche Anleitung mit gegebenenfalls verknüpften häufigen Fragen.

### 13.2 Anfrage an den Support stellen

**Ziel:** Eine Frage stellen, die über die Hilfetexte nicht beantwortet wird.

**Schritte:**

1. Im Hilfe-Center im Abschnitt "Frage an den Support" einen Betreff (mindestens 5 Zeichen) und eine Kategorie wählen.
2. Die Frage ausführlich beschreiben (mindestens 20 Zeichen): Was wurde getan, was ist passiert, was wurde erwartet. Rechnungs- oder Kundennummern helfen bei der Bearbeitung.
3. Auf "Anfrage senden" klicken.

**Wichtiger Hinweis:** Keine API-Schlüssel, Passwörter oder vollständigen IBANs in der Anfrage mitschicken; stattdessen Kunden- oder Rechnungsnummern nennen.

**Erwartetes Ergebnis:** Die Anfrage erscheint unter "Meine Anfragen" mit Status (zum Beispiel "Einladung ausstehend" oder vergleichbare Statuswerte) und wird, sofern der Mailversand aktiv ist, zusätzlich per E-Mail beantwortet.

### 13.3 Verlauf einer Anfrage und Ergänzung

Über den Link "Verlauf" bei einer Anfrage öffnet sich der bisherige Nachrichtenverlauf mit Support. Über das Feld "Ergänzung oder Rückfrage" lässt sich eine weitere Nachricht anfügen; die Anfrage wird dadurch wieder als offen markiert.

## 14. Häufige Fragen

**1. Wie lange dauert es, bis eine SEPA-Lastschrift als "Erfolgreich" gilt?**
Das hängt von der Bearbeitung durch die Bank des Kunden ab und lässt sich nicht pauschal angeben. Der Status wechselt automatisch von "In Bearbeitung" zu "Erfolgreich" oder "Fehlgeschlagen", sobald eine Rückmeldung vorliegt.

**2. Warum erscheint mein Sofort-Einzug zunächst als "Vorgemerkt" statt sofort als eingereicht?**
Für die Einreichung gilt in der Regel eine Karenzzeit und ein Einreichfenster. Solange beides nicht erreicht ist, bleibt der Einzug vorgemerkt und ist bis zur tatsächlichen Einreichung stornierbar. Details zeigt der Hinweistext "Karenzzeit" auf der Seite "Einzüge".

**3. Kann ich einen bereits bei Stripe eingereichten Einzug zurückholen?**
Nein. Ein Storno ist nur vor der Einreichung möglich. Danach richtet sich eine Rückabwicklung nach der Rücklastschrift durch die Bank des Kunden oder einer Erstattung über Stripe.

**4. Was bedeutet "Klärungsbedarf offen" bei einer Rechnung?**
Meist wurde ein bereits erfolgter Einzug nachträglich über Stripe ganz oder teilweise erstattet. Die Rechnung wird nicht automatisch erneut eingezogen, bis ein Inhaber oder Administrator die Klärung über "Klärung abgeschlossen" beendet.

**5. Warum kann ich für einen Kunden kein SEPA-Mandat erzeugen?**
Für Laufkunden (Sammel-Kundennummer, mehrere Personen teilen sich eine Kundennummer) wird aus Datenschutzgründen kein personenbezogenes Mandat erzeugt.

**6. Was passiert, wenn ich das Mandatspräfix später ändern möchte?**
Das Mandatspräfix ist nach der Einrichtung nicht mehr änderbar, da es Teil bereits vergebener Mandatsreferenzen ist.

**7. Wie oft kann ich das Buchhaltungssystem wechseln?**
Nach jedem Wechsel gilt eine Sperre von vier Wochen. Wer zwei Buchhaltungen dauerhaft parallel benötigt, richtet dafür eine zweite Firma ein.

**8. Was passiert mit meinen Daten, wenn ich die Verbindung zu Lexware Office oder Stripe trenne?**
Bereits synchronisierte Rechnungen, Kunden, Mandate und Einzüge bleiben als Historie erhalten. Neue Rechnungen können erst nach erneuter Verbindung übernommen beziehungsweise neue Einzüge erst nach erneuter Stripe-Verbindung ausgelöst werden.

**9. Warum sehe ich nur eine Ansicht ohne Bearbeitungsmöglichkeit bei den Einstellungen?**
Nur Inhaber und Administratoren dürfen API-Verbindungen, Firmendaten und das Buchhaltungssystem ändern. Mitarbeiter sehen den Status.

**10. Wie kann ich verhindern, dass versehentlich zu viel eingezogen wird?**
Der Not-Stopp hält alle Einreichungen der Firma sofort an. Zusätzlich prüft die Anwendung vor jeder Einreichung den offenen Restbetrag bei Lexware Office und zieht bei einer erkannten Teilzahlung nur nach ausdrücklicher Bestätigung den Restbetrag ein.

**11. Was ist der Unterschied zwischen Mitarbeiter und Administrator?**
Administratoren dürfen zusätzlich API-Verbindungen einrichten, Firmendaten ändern, den Not-Stopp bedienen und das Buchhaltungssystem wechseln. Mitarbeiter haben vollen operativen Zugriff auf Rechnungen, Kunden und Einzüge, jedoch keine dieser Verwaltungsrechte.

**12. Ich habe mein Gerät mit der Authenticator-App verloren. Was tue ich jetzt?**
Melden Sie sich mit einem Ihrer Recovery-Codes an (Feld "Code aus der Authenticator-App" akzeptiert auch Recovery-Codes). Richten Sie anschließend die Zwei-Faktor-Authentifizierung auf einem neuen Gerät ein. Ohne Recovery-Codes wenden Sie sich an den Inhaber Ihres Firmenaccounts oder an den Support.

**13. Warum wird meine E-Mail-Adresse als "bereits registriert" abgelehnt?**
Jede E-Mail-Adresse gehört zu genau einer Benutzeridentität. Ist sie derselben Firma bereits zugeordnet, melden Sie sich an. Ist sie einer anderen Firma zugeordnet, können Sie über die bestehende Anmeldung eine weitere Firma anlegen (Firmenübersicht).

**14. Kann ich als Mitarbeiter das Abonnement einsehen?**
Ja, die Kennzahlen zum Abonnement sind auf "Firmendaten" für alle Mitglieder sichtbar; abschließen, kündigen oder wechseln kann nur der Inhaber.

**15. Was passiert mit einem eingeladenen Mitarbeiter, der die Einladung nicht rechtzeitig annimmt?**
Der Einladungslink ist 7 Tage gültig. Danach kann der Inhaber die Einladung über "Erneut senden" mit neuem Link versehen.

**16. Warum ist die Schaltfläche zum Sofort-Einzug bei manchen Rechnungen ausgeblendet?**
Mögliche Gründe: keine IBAN hinterlegt, SEPA-Einzug für den Kunden deaktiviert, offene Klärung, aktiver Not-Stopp oder die Rechnung ist laut Lexware Office nicht mehr offen. Der jeweilige Grund wird direkt in der Zeile angezeigt.

**17. Wie erfahre ich, ob mein Stripe-Konto im Testmodus verbunden ist?**
Ein Hinweisbanner "TESTMODUS" erscheint oben auf jeder Seite, solange ein Stripe-Testschlüssel hinterlegt ist. In diesem Modus werden keine echten Lastschriften ausgeführt.

**18. Was bedeutet der Hinweis "Ihr Einzugskontingent ist zu X Prozent belegt"?**
Manche Tarife begrenzen die Anzahl der Einzüge je Abrechnungsperiode. Der Hinweis erscheint auf "Rechnungen", sobald die Auslastung eine Schwelle überschreitet, mit Verweis auf einen passenden höheren Tarif, falls vorhanden.

**19. Kann ich sevdesk schon anbinden?**
Sevdesk ist derzeit als Buchhaltungssystem in Vorbereitung. Bis zur Freigabe ist nur eine unverbindliche Vormerkung über die Produktseite möglich.

**20. Wie exportiere ich alle Einzüge für meine eigene Buchhaltung?**
Über das Profilmenü "Export" oder auf "Einzüge" über "Journal als CSV exportieren". Die Datei enthält unter anderem Rechnungsnummer, Beträge, Status und Zeitpunkte.

## 15. Glossar

**API-Schlüssel**
Zugangsschlüssel zu einer externen Anwendung (hier: Lexware Office oder Stripe), über den SmartEinzug Daten abruft beziehungsweise Lastschriften auslöst.

**Authenticator-App**
Anwendung auf einem Mobilgerät oder Desktop, die zeitbasierte 6-stellige Codes für die Zwei-Faktor-Authentifizierung erzeugt.

**Buchhaltungssystem**
Das System, aus dem Rechnungen und Kunden stammen (aktuell Lexware Office, sevdesk in Vorbereitung). Jede Firma nutzt genau ein Buchhaltungssystem.

**Einreichfenster**
Der Zeitraum, in dem Lastschriften tatsächlich an Stripe übergeben werden (Standard 23:00 bis 06:00 Uhr, je Installation einstellbar). Außerhalb dieses Fensters werden fällige Einzüge zurückgehalten.

**Einzugskontingent**
Die je Abrechnungsperiode im Tarif enthaltene Anzahl an Einzügen, sofern der Tarif eine Begrenzung vorsieht.

**Gerätefreigabe**
Die 90-tägige Befreiung eines konkreten Browsers von der Codeabfrage bei der Anmeldung, nach Auswahl von "Dieses Gerät für 90 Tage merken".

**Gläubiger-Identifikationsnummer**
Von der Deutschen Bundesbank vergebene Nummer, die einen Zahlungsempfänger für SEPA-Lastschriften kennzeichnet. Freiwillige Angabe in den Firmendaten.

**Inhaber**
Die Rolle mit den umfassendsten Rechten einer Firma, unter anderem für Team, Abonnement und Übertragung der Inhaberschaft. Genau eine Person je Firma.

**Karenzzeit**
Die Mindestwartezeit zwischen dem Auslösen eines Sofort-Einzugs und seiner tatsächlichen Einreichung bei Stripe.

**Laufkunde**
Ein Kunde mit einer von mehreren Personen geteilten Sammel-Kundennummer. Für Laufkunden wird kein personenbezogenes SEPA-Mandat erzeugt.

**Mandatspräfix**
Das bei der Registrierung festgelegte, 2 bis 10 Zeichen lange Kürzel, das den Anfang jeder Mandatsreferenz der Firma bildet. Danach nicht mehr änderbar.

**Mandatsreferenz**
Die eindeutige, vom Portal vergebene Kennung eines SEPA-Mandats, zusammengesetzt aus Mandatspräfix und Kundennummer.

**Not-Stopp**
Funktion zum sofortigen Anhalten aller SEPA-Einreichungen einer Firma, mit Zweitbestätigung per 2FA-Code beim Aufheben.

**Public API**
Die Programmierschnittstelle von Lexware Office, über die SmartEinzug Rechnungen und Kunden abruft. Nach Angaben von Lexware derzeit an den Tarif Lexware Office XL gebunden.

**Recovery-Code**
Einmal verwendbarer Ersatzcode für die Zwei-Faktor-Authentifizierung, falls kein Zugriff auf die Authenticator-App besteht.

**Rücklastschrift**
Nachträgliche Rückbuchung einer bereits gutgeschriebenen Lastschrift durch den Kunden oder seine Bank.

**Sammel-Einzug**
Das gleichzeitige Einreichen aller einzugsbereiten Rechnungen in einem Arbeitsschritt.

**SEPA-Basislastschrift (SEPA Core)**
Das von Stripe unterstützte Lastschriftverfahren für Verbraucher und Unternehmen innerhalb des SEPA-Raums, das SmartEinzug nutzt.

**Sofort-Einzug**
Das einzelne Auslösen einer Lastschrift für eine bestimmte Rechnung, im Unterschied zum terminierten Einzug.

**Stripe-Kundenportal**
Die von Stripe bereitgestellte, gesicherte Seite zur Verwaltung von Zahlungsmethode und Rechnungen des Plattform-Abonnements.

**Stripe-Webhook**
Automatische Rückmeldung von Stripe an SmartEinzug über Ereignisse wie erfolgreiche Einzüge, Fehlschläge, Rücklastschriften und Erstattungen.

**Terminierter Einzug**
Ein Einzug mit festgelegtem Fälligkeitsdatum, der erst an diesem Tag innerhalb des Einreichfensters eingereicht wird.

**Überfällig**
Status eines noch nicht eingereichten Einzugs, dessen Termin die eingestellte Anzahl Tage überschritten hat und der deshalb nicht automatisch nachgeholt wird.

**Unklarer Versuch**
Ein Einreichversuch, bei dem die Antwort von Stripe ausgeblieben ist; das Ergebnis wird erst durch eine gesonderte Prüfung geklärt.

**Vorabankündigung (Pre-Notification)**
Die Ankündigung einer bevorstehenden Lastschrift an den Kunden, wahlweise durch die Rechnung selbst oder durch eine gesonderte E-Mail des Portals.

**Zwei-Faktor-Authentifizierung (2FA)**
Verpflichtender zweiter Anmeldefaktor über eine Authenticator-App, ergänzend zum Passwort.

## Zusammenfassung: In der Oberfläche nicht gefundene Funktionen

Die folgenden, in Buchhaltungs- und Zahlungsanwendungen mitunter erwarteten Funktionen wurden beim Durchsehen der genannten Seiten nicht gefunden und sind daher nicht Teil dieses Handbuchs:

- Eine manuelle Anlage oder Bearbeitung von Rechnungen innerhalb von SmartEinzug; Rechnungen werden ausschließlich aus dem Buchhaltungssystem übernommen.
- Eine manuelle Anlage einzelner Kunden innerhalb von SmartEinzug; Kunden werden ausschließlich aus dem Buchhaltungssystem übernommen.
- Ein Löschen von Firmen aus der Firmenübersicht; dort sind nur das Anlegen neuer Firmen und der Wechsel zwischen bestehenden Firmen vorgesehen.
- Ein manuelles Auslösen einer Erstattung an den Kunden aus der Anwendung heraus; Erstattungen laufen über Stripe und werden nur zurückgemeldet.
- Eine eigene Übersicht über Benachrichtigungseinstellungen (welche Sicherheits- oder Änderungsmeldungen an wen gehen); die Anwendung versendet solche Meldungen automatisch an Inhaber beziehungsweise betroffene Person, ohne einstellbare Präferenzen für Kunden.
- Ein Massen-Import von IBANs oder Kundendaten per Datei; IBANs werden einzeln je Kunde erfasst.
- Eine sichtbare Historie oder Ablaufwarnung für hinterlegte API-Schlüssel (Lexware Office, Stripe); lediglich der Zeitpunkt der letzten Prüfung wird angezeigt.
- Eine mobile Anwendung oder ein eigener Darstellungsmodus für kleine Bildschirme wurde in den Seitencode nicht als eigenständige Funktion erkennbar.
- Eine Freitext-Suche oder ein Filter direkt in der Einzugsübersicht über den Statusfilter hinaus (zum Beispiel nach Kunde oder Zeitraum).
- Eine vom Kunden selbst nutzbare Übersicht seiner eigenen Rechnungen oder Einzüge außerhalb der einmaligen Mandatserteilungsseite.

Diese Liste beruht auf der Durchsicht der im Auftrag genannten Seiten und begleitenden Anwendungslogik; sie erhebt keinen Anspruch auf Vollständigkeit für die gesamte Anwendung.
