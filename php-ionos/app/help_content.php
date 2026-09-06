<?php
/** Hilfetexte des Hilfe-Centers (hilfe.php). Reines Inhaltsarray, HTML nur mit p, h3, ol, ul, li, strong, a, code. */
declare(strict_types=1);
return [
    'topics' => [
        [
            'slug' => 'erste-schritte',
            'title' => 'Erste Schritte',
            'summary' => 'Voraussetzungen und die fünf Schritte bis zum ersten Einzug.',
            'html' => '<h3>Voraussetzungen</h3>'
                . '<p>Für SmartEinzug benötigen Sie einen Zugang zu Lexware Office mit Zugriff auf die Public API. Nach Angaben von Lexware ist dafür derzeit ein bestimmter Tarif erforderlich, bitte prüfen Sie dies in Ihrem eigenen Lexware-Konto. Außerdem benötigen Sie ein eigenes Stripe-Konto, in dem SEPA-Lastschrift als Zahlungsart freigeschaltet ist.</p>'
                . '<h3>Die fünf Schritte der Einrichtung</h3>'
                . '<ol>'
                . '<li><strong>Registrierung.</strong> Firmenaccount mit geschäftlicher E-Mail-Adresse anlegen.</li>'
                . '<li><strong>Zwei-Faktor-Authentifizierung.</strong> Die Einrichtung mit einer Authenticator-App ist verpflichtend.</li>'
                . '<li><strong>Lexware Office verbinden.</strong> API-Schlüssel unter <a href="settings.php">Einstellungen</a> hinterlegen.</li>'
                . '<li><strong>Stripe verbinden und Webhook einrichten.</strong> Secret Key hinterlegen und den Webhook-Endpunkt im eigenen Stripe-Konto anlegen.</li>'
                . '<li><strong>Firmendaten und erste Synchronisation.</strong> Firmendaten unter <a href="team.php">Firmendaten</a> ergänzen und die erste Synchronisation der Rechnungen anstoßen.</li>'
                . '</ol>'
                . '<p>Danach lassen sich Kunden mit IBAN und SEPA-Mandat unter <a href="sepa-pflegen.php">SEPA Pflegen</a> anlegen und Einzüge auslösen. Prüfen Sie nach der Einrichtung, ob die Synchronisation Rechnungen lädt und ob die Stripe-Verbindung als aktiv angezeigt wird, bevor Sie den ersten Einzug freigeben.</p>'
                . '<h3>Nach der Einrichtung</h3>'
                . '<p>Legen Sie unter <a href="team.php">Firmendaten</a> die Firmendaten und, soweit vorhanden, die Gläubiger-Identifikationsnummer an. Weitere Mitarbeiter laden Sie ebenfalls unter <a href="team.php">Firmendaten</a> ein; jedes Mitglied richtet dabei verpflichtend eine eigene Zwei-Faktor-Authentifizierung ein.</p>',
        ],
        [
            'slug' => 'lexware-verbindung',
            'title' => 'Lexware Office verbinden',
            'summary' => 'API-Schlüssel hinterlegen, Verbindung prüfen und Rechnungen synchronisieren.',
            'html' => '<h3>API-Schlüssel erzeugen und hinterlegen</h3>'
                . '<p>Den API-Schlüssel erzeugen Sie in Ihrem Lexware-Office-Konto und fügen ihn unter <a href="settings.php">Einstellungen</a> ein. SmartEinzug speichert den Schlüssel verschlüsselt.</p>'
                . '<h3>Verbindung prüfen</h3>'
                . '<p>Nach dem Speichern zeigt SmartEinzug an, ob die Verbindung funktioniert und ob Rechnungen abgerufen werden können.</p>'
                . '<h3>Synchronisation</h3>'
                . '<p>Die Synchronisation lädt offene und überfällige Rechnungen aus Lexware Office. Sie läuft in Schritten, damit die Laufzeit begrenzt bleibt; ein Cronjob setzt eine unterbrochene Synchronisation fort.</p>'
                . '<h3>Was nicht übertragen wird</h3>'
                . '<p>SmartEinzug schreibt Zahlungen nicht nach Lexware Office zurück. Die Zuordnung eines erfolgreichen Einzugs zur Rechnung erfolgt in Lexware Office manuell. SmartEinzug zeigt Ihnen im Einzugsjournal, welche Rechnung erfolgreich eingezogen wurde, damit Sie diese Zuordnung dort vornehmen können.</p>'
                . '<h3>Restbetrag</h3>'
                . '<p>SmartEinzug hält zu jeder Rechnung den zuletzt bekannten Restbetrag laut Lexware Office fest, mit Angabe des Abrufzeitpunkts. Vor jeder Einreichung eines Einzugs bei Stripe wird dieser Restbetrag erneut live abgerufen, damit keine bereits bezahlte oder teilweise bezahlte Rechnung in falscher Höhe eingezogen wird.</p>'
                . '<h3>Synchronisationshistorie</h3>'
                . '<p>Unter <a href="synchronisationen.php">Synchronisationen</a> finden Sie den Verlauf aller bisherigen Abgleiche mit Lexware Office für Ihre Firma, mit Zeitpunkt, Status, Dauer und Auslöser. Zu jedem Lauf zeigt eine Detailansicht zusätzlich die geprüften, neuen, geänderten und abgeschlossenen Rechnungen, die Anzahl der Lexware-Aufrufe sowie im Fehlerfall die Fehlerkategorie und den Fehlertext. Läuft gerade eine Synchronisation, sehen Sie dort außerdem den aktuellen Fortschritt.</p>',
        ],
        [
            'slug' => 'stripe-verbindung',
            'title' => 'Stripe verbinden und Webhook einrichten',
            'summary' => 'Secret Key hinterlegen und den Webhook mit den sieben benötigten Ereignissen einrichten.',
            'html' => '<h3>Secret Key hinterlegen</h3>'
                . '<p>Unter <a href="settings.php">Einstellungen</a> hinterlegen Sie den Secret Key Ihres Stripe-Kontos, wahlweise im Test- oder im Live-Modus. SmartEinzug zeigt an, in welchem Modus die Verbindung steht, und prüft nach dem Speichern das Konto.</p>'
                . '<h3>Webhook einrichten</h3>'
                . '<p>Der Webhook wird einmalig in Ihrem eigenen Stripe-Konto angelegt, damit Stripe Statusänderungen sofort meldet.</p>'
                . '<ol>'
                . '<li>Im Stripe-Dashboard unter Entwickler, Webhooks einen neuen Endpunkt anlegen.</li>'
                . '<li>Als Endpunkt-URL die von SmartEinzug angezeigte Adresse eintragen.</li>'
                . '<li>Genau diese sieben Ereignisse auswählen: <code>payment_intent.processing</code>, <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code>, <code>charge.dispute.created</code>, <code>charge.refunded</code>, <code>charge.refund.updated</code> und <code>checkout.session.completed</code>.</li>'
                . '<li>Das Signing Secret (<code>whsec_...</code>) aus Stripe unter <a href="settings.php">Einstellungen</a> als Webhook-Secret eintragen.</li>'
                . '</ol>'
                . '<p>Senden Sie anschließend über das Stripe-Dashboard ein Testereignis, um die Einrichtung zu prüfen.</p>'
                . '<h3>Warum genau diese sieben Ereignisse</h3>'
                . '<p>Nur diese sieben Ereignisse werden von SmartEinzug ausgewertet, zum Beispiel für den Status einer Lastschrift, für Rücklastschriften, für Erstattungen und für digital erteilte Mandate. Wählen Sie nicht die Option "alle Ereignisse", das erzeugt lediglich unnötigen Leerlauf im Webhook.</p>'
                . '<h3>Ohne Webhook</h3>'
                . '<p>Ohne eingerichteten Webhook erfährt SmartEinzug den Status eines Einzugs erst beim manuellen Abgleich oder über den Cronjob, nicht sofort. Ein fehlendes Webhook-Secret wird unter <a href="settings.php">Einstellungen</a> als Hinweis angezeigt.</p>',
        ],
        [
            'slug' => 'kunden-iban-mandate',
            'title' => 'Kunden, IBAN und SEPA-Mandate',
            'summary' => 'Kunden aus Lexware Office, SEPA-Einzug je Kunde, IBAN und Mandatsnachweis pflegen.',
            'html' => '<h3>Kunden</h3>'
                . '<p>Kunden werden aus Lexware Office übernommen. Unter <a href="sepa-pflegen.php">SEPA Pflegen</a> schalten Sie den SEPA-Einzug je Kunde ein oder aus.</p>'
                . '<h3>IBAN und Mandatsdokument</h3>'
                . '<p>Die IBAN eines Kunden hinterlegen Sie unter <a href="sepa-pflegen.php">SEPA Pflegen</a>. Anschließend lässt sich das Mandatsdokument erzeugen.</p>'
                . '<h3>Nachweis des unterschriebenen Mandats</h3>'
                . '<p>Zum Nachweis stehen zwei Wege zur Verfügung: den handschriftlichen Nachweis in SmartEinzug erfassen, oder das unterschriebene Mandat als PDF oder Bild hochladen (bis 10 MB).</p>'
                . '<h3>Mandatsreferenz und Verfall</h3>'
                . '<p>Jedem Mandat wird eine eindeutige Mandatsreferenz zugewiesen. Wird ein Mandat 36 Monate lang nicht für einen Einzug verwendet, gilt es als verfallen; für einen neuen Einzug ist dann ein neues Mandat erforderlich.</p>'
                . '<h3>Digitale Mandatsanforderung</h3>'
                . '<p>Eine digitale Mandatsanforderung steht nur zur Verfügung, wenn der Betreiber dieses Merkmal für Ihren Account freigeschaltet hat. Ist sie aktiv, lässt sich ein Kunde zur digitalen Erteilung des Mandats einladen.</p>'
                . '<h3>Gläubiger-ID auf dem Kontoauszug</h3>'
                . '<p>Da der technische Einzug über Stripe abgewickelt wird, erscheint auf dem Kontoauszug Ihres Kunden in der Regel die Gläubiger-Identifikationsnummer und die Mandatsreferenz von Stripe, nicht Ihre eigene Gläubiger-ID. SmartEinzug zeigt beide Referenzen, die interne Mandatsreferenz und die Stripe-Referenz, getrennt in den Kundendetails und in der Einzugsübersicht an.</p>',
        ],
        [
            'slug' => 'einzug-ablauf',
            'title' => 'So läuft ein Einzug',
            'summary' => 'Vom Vormerken über die Karenzzeit und das Einreichfenster bis zum Status.',
            'html' => '<h3>Sofort-Einzug</h3>'
                . '<p>Ein sofort ausgelöster Einzug wird zunächst vorgemerkt. Es gilt eine Karenzzeit von 4 Stunden. Bis zur Einreichung können Sie den Einzug unter Einzüge stornieren, die Rechnung ist dann wieder offen. Die Einreichung bei Stripe erfolgt danach nur innerhalb des Einreichfensters von 23:00 bis 06:00 Uhr.</p>'
                . '<h3>Terminierte Einzüge</h3>'
                . '<p>Terminierte Einzüge erhalten einen Fälligkeitstag. Optional versendet SmartEinzug eine Vorabankündigung per E-Mail; die Frist dafür ist einstellbar, Standard sind 14 Tage.</p>'
                . '<h3>Prüfung des Restbetrags</h3>'
                . '<p>Vor jeder Einreichung wird der Restbetrag live bei Lexware Office geprüft. Teilzahlungen werden dabei berücksichtigt, bereits vollständig bezahlte Rechnungen werden nicht eingezogen.</p>'
                . '<h3>Status</h3>'
                . '<ul>'
                . '<li>Vorgemerkt</li><li>Terminiert</li><li>In Bearbeitung</li><li>Erfolgreich</li>'
                . '<li>Fehlgeschlagen</li><li>Rücklastschrift</li><li>Erstattet</li><li>Storniert</li>'
                . '</ul>'
                . '<p>Überfällige Termine, die älter als 3 Tage sind, werden nicht automatisch nachgeholt; sie müssen unter <a href="collections.php">Einzüge</a> neu terminiert oder storniert werden. Eine Ausnahme-Einreichung außerhalb des Einreichfensters dürfen nur Inhaber oder Administrator auslösen, mit Bestätigung durch einen 2FA-Code.</p>'
                . '<h3>Doppeleinzug ausgeschlossen</h3>'
                . '<p>Bereits laufende oder erfolgreiche Einzüge zu derselben Rechnung werden vom offenen Betrag abgezogen, bevor ein weiterer Einzug eingereicht wird. So wird verhindert, dass eine Rechnung versehentlich doppelt eingezogen wird, auch wenn die Zahlung in Lexware Office noch nicht verbucht ist.</p>',
        ],
        [
            'slug' => 'sammel-einzug',
            'title' => 'Sammel-Einzug und Regeln',
            'summary' => 'Alle bereiten Einzüge auf einmal vormerken.',
            'html' => '<h3>Sammel-Einzug</h3>'
                . '<p>Unter <a href="collections.php">Einzüge</a> lassen sich alle bereiten Einzüge in einem Schritt vormerken.</p>'
                . '<h3>Welche Rechnungen zählen</h3>'
                . '<p>Berücksichtigt werden Rechnungen, die in Lexware Office offen sind, bei denen der Kunde SEPA-Einzug wünscht, eine aktive IBAN hinterlegt ist und kein Klärungsbedarf besteht.</p>'
                . '<h3>Kontingent</h3>'
                . '<p>Je Tarif gilt ein Einzugskontingent je Abrechnungsperiode. Ist es ausgeschöpft, lassen sich keine weiteren Einzüge vormerken.</p>'
                . '<h3>Regeln</h3>'
                . '<p>Regeln für automatische Einzüge sind in SmartEinzug derzeit nur als Vorschau vorbereitet und lösen noch keine Einzüge selbstständig aus. Sie dienen dazu, sich mit der geplanten Automatisierung vertraut zu machen, ohne dass dadurch bereits Lastschriften eingereicht werden.</p>'
                . '<h3>Nach dem Vormerken</h3>'
                . '<p>Nach dem Sammel-Einzug durchläuft jeder einzelne Einzug dieselben Prüfungen wie ein einzeln ausgelöster Einzug, insbesondere die Karenzzeit, das Einreichfenster und die Restbetragsprüfung bei Lexware Office. Ein Not-Stopp während des Laufs bricht den Sammel-Einzug sofort ab.</p>'
                . '<h3>Übersicht behalten</h3>'
                . '<p>Nach dem Vormerken finden Sie alle betroffenen Einzüge mit ihrem jeweiligen Status unter <a href="collections.php">Einzüge</a>. Einzelne Einzüge lassen sich dort weiterhin einzeln stornieren oder umterminieren, auch wenn sie über den Sammel-Einzug angelegt wurden.</p>',
        ],
        [
            'slug' => 'ruecklastschrift-erstattung',
            'title' => 'Rücklastschrift, Erstattung, Klärung',
            'summary' => 'Wie SmartEinzug auf Rücklastschriften und Erstattungen reagiert.',
            'html' => '<h3>Meldung über den Webhook</h3>'
                . '<p>Rücklastschriften und Erstattungen meldet Stripe über den eingerichteten Webhook. SmartEinzug markiert die betroffene Rechnung daraufhin zur Klärung.</p>'
                . '<h3>Kein automatischer Neu-Einzug</h3>'
                . '<p>Ein neuer Einzug nach einer Rücklastschrift wird nicht automatisch ausgelöst. Ein neuer Einzug durchläuft alle Prüfungen erneut.</p>'
                . '<h3>Klärung abschließen</h3>'
                . '<p>Die Klärung abschließen dürfen nur Inhaber oder Administrator. Die Funktion "Status mit Stripe abgleichen" verändert nichts bei Stripe, sie liest den Status nur.</p>'
                . '<h3>Warum kein automatischer Neu-Einzug</h3>'
                . '<p>Nach einer Rücklastschrift oder Erstattung ist der Grund dafür zunächst zu prüfen, etwa ein Widerspruch des Kunden oder eine fehlerhafte IBAN. Ein automatischer Neu-Einzug ohne diese Prüfung würde dasselbe Ergebnis riskieren. Ein manuell ausgelöster neuer Einzug durchläuft danach wieder alle Prüfungen, einschließlich der erneuten Restbetragsprüfung bei Lexware Office.</p>'
                . '<h3>Wo Sie das sehen</h3>'
                . '<p>Rechnungen mit Klärungsbedarf sind unter <a href="collections.php">Einzüge</a> gekennzeichnet und werden vom Sammel-Einzug sowie von terminierten Einzügen automatisch ausgeschlossen, bis die Klärung abgeschlossen ist.</p>',
        ],
        [
            'slug' => 'not-stopp',
            'title' => 'Not-Stopp',
            'summary' => 'Alle Einzüge der Firma sofort anhalten.',
            'html' => '<h3>Wirkung</h3>'
                . '<p>Der Not-Stopp verhindert neue Einreichungen bei Stripe. Vorgemerkte und terminierte Einzüge bleiben bestehen; sie können auf Wunsch gesammelt storniert werden.</p>'
                . '<h3>Aktivieren und Aufheben</h3>'
                . '<p>Den Not-Stopp aktivieren Inhaber und Administratoren ohne zusätzliche Bestätigung unter <a href="notstopp.php">Not-Stopp</a>. Das Aufheben erfordert zusätzlich einen aktuellen Code aus der Authenticator-App.</p>'
                . '<h3>Was der Not-Stopp nicht kann</h3>'
                . '<p>Bereits bei Stripe eingereichte Lastschriften kann der Not-Stopp nicht zurückholen.</p>'
                . '<h3>Plattformweiter Not-Stopp</h3>'
                . '<p>Der Betreiber kann zusätzlich einen plattformweiten Not-Stopp setzen, der alle Firmen betrifft. Diese Sperre kann nur der Betreiber wieder aufheben.</p>'
                . '<h3>Was während des Not-Stopps weiterläuft</h3>'
                . '<p>Der Statusabgleich mit Stripe, die Synchronisation mit Lexware Office und die Verarbeitung von Webhook-Meldungen laufen auch während eines Not-Stopps weiter. Nur neue Einreichungen bei Stripe werden verhindert. Nach dem Aufheben zeigt SmartEinzug an, wie viele terminierte Einzüge im nächsten Einreichfenster automatisch eingereicht werden und wie viele überfällig sind und deshalb manuell neu terminiert oder storniert werden müssen.</p>',
        ],
        [
            'slug' => 'import-bestand',
            'title' => 'Bestehende Einzüge aus Stripe übernehmen',
            'summary' => 'Bereits bei Stripe vorhandene Zahlungen einem Firmenaccount zuordnen.',
            'html' => '<h3>Wann sinnvoll</h3>'
                . '<p>Diese Funktion ist sinnvoll beim Neuaufbau eines Firmenaccounts oder wenn ein bestehendes Stripe-Konto neu mit SmartEinzug verknüpft wird.</p>'
                . '<h3>Ablauf</h3>'
                . '<ol>'
                . '<li>Zeitraum wählen (3, 6, 12 oder 24 Monate).</li>'
                . '<li>Zahlungen aus Stripe laden; SmartEinzug zeigt eine Vorschau.</li>'
                . '<li>Die Zuordnung erfolgt über Rechnungsnummer und Betrag.</li>'
                . '<li>Die Übernahme bestätigen Inhaber oder Administrator mit einem 2FA-Code.</li>'
                . '</ol>'
                . '<p>Der Zugriff ist reiner Lesezugriff, bei Stripe wird nichts verändert. Die Zuordnung über Rechnungsnummer und Betrag wird vor der endgültigen Übernahme als Vorschau angezeigt, damit Sie unpassende Treffer noch aussortieren können.</p>'
                . '<h3>Was nicht übernommen wird</h3>'
                . '<p>Mandate und IBANs werden nicht übernommen, da Stripe die IBAN nicht vollständig herausgibt. Für neue Einzüge hinterlegen Sie IBAN und Mandat je Kunde unter <a href="sepa-pflegen.php">SEPA Pflegen</a>.</p>'
                . '<h3>Zugriff</h3>'
                . '<p>Diese Funktion steht nur Inhaber und Administratoren zur Verfügung. Der gewählte Zeitraum bezieht sich auf das Anlagedatum der Zahlung bei Stripe, nicht auf das Rechnungsdatum in Lexware Office. Bereits übernommene Vorgänge sind in der Übersicht mit Datum, Zeitraum und Status nachvollziehbar.</p>',
        ],
        [
            'slug' => 'export-journal',
            'title' => 'Export und Protokoll',
            'summary' => 'Das Einzugsjournal als CSV exportieren und das Protokoll der Firma einsehen.',
            'html' => '<h3>CSV-Export</h3>'
                . '<p>Unter <a href="export.php">Export</a> lässt sich das Einzugsjournal als CSV-Datei herunterladen, mit Angaben unter anderem zu Rechnung, Kunde, Betrag, Status und Erstattungsbetrag. Die Datei wird als UTF-8 mit BOM und Semikolon als Trennzeichen erzeugt, damit sie sich in gängigen Tabellenkalkulationsprogrammen korrekt öffnen lässt.</p>'
                . '<h3>Formelschutz</h3>'
                . '<p>Zellen, die mit einem Zeichen beginnen, das eine Tabellenkalkulation als Formel deuten könnte, werden beim Export automatisch entschärft.</p>'
                . '<h3>Protokoll</h3>'
                . '<p>Unter <a href="team.php">Firmendaten</a> steht ein Protokoll zur Verfügung, das festhält, wer welche Aktion wann ausgelöst hat, zum Beispiel das Auslösen eines Einzugs, das Setzen und Aufheben des Not-Stopps, Änderungen an Mandaten und den Export selbst.</p>'
                . '<h3>Aufbewahrung</h3>'
                . '<p>Journal und Protokoll dienen auch der Nachvollziehbarkeit gegenüber Kunden und, soweit erforderlich, gegenüber Behörden. Prüfen Sie unabhängig von SmartEinzug, welche handels- und steuerrechtlichen Aufbewahrungsfristen für Ihre Unterlagen gelten. Der Export selbst wird im Protokoll der Firma vermerkt, mit Angabe der auslösenden Person und des Zeitpunkts.</p>',
        ],
        [
            'slug' => 'rollen-sicherheit',
            'title' => 'Rollen, Sicherheit und Zwei-Faktor',
            'summary' => 'Inhaber, Administrator und Mitarbeiter sowie die Absicherung des Kontos.',
            'html' => '<h3>Rollen</h3>'
                . '<p>Der Inhaber verwaltet Mitarbeiter, überträgt die Inhaberschaft und verwaltet das Abonnement; nur er kann diese Aktionen ausführen. Administratoren dürfen zusätzlich zum operativen Zugriff die API-Verbindungen sowie die Firmendaten ändern. Mitarbeiter haben vollen operativen Zugriff auf Rechnungen und Einzüge.</p>'
                . '<h3>Zwei-Faktor-Authentifizierung</h3>'
                . '<p>Die Einrichtung mit einer Authenticator-App ist für jedes Mitglied verpflichtend. Unter <a href="security.php">Sicherheit</a> lassen sich Recovery-Codes neu erzeugen und das Passwort ändern.</p>'
                . '<h3>Kontosperre</h3>'
                . '<p>Nach mehreren Fehlversuchen wird ein Konto vorübergehend gesperrt.</p>'
                . '<h3>Inhaberschaft übertragen</h3>'
                . '<p>Die Übertragung der Inhaberschaft unter <a href="team.php">Firmendaten</a> erfordert das Passwort und einen aktuellen 2FA-Code des bisherigen Inhabers; der neue Inhaber muss zuvor selbst die Zwei-Faktor-Authentifizierung eingerichtet haben.</p>'
                . '<h3>Support-Zugriff des Betreibers</h3>'
                . '<p>Ein Support-Zugriff des Betreibers ist zeitlich begrenzt und wird protokolliert. Der Inhaber wird darüber per E-Mail informiert.</p>'
                . '<h3>Passwort und Recovery-Codes</h3>'
                . '<p>Unter <a href="security.php">Sicherheit</a> ändern Sie Ihr Passwort und lassen sich bei Bedarf neue Recovery-Codes erzeugen. Neue Recovery-Codes machen alle bisherigen Codes ungültig. Für sicherheitsrelevante Aktionen wird zusätzlich zum Passwort ein aktueller 2FA-Code oder ein Recovery-Code verlangt.</p>'
                . '<h3>Gerät für 90 Tage merken</h3>'
                . '<p>Bei der Eingabe des 2FA-Codes können Sie "Dieses Gerät für 90 Tage merken" wählen. In diesem Browser entfällt dann bei der Anmeldung die Codeabfrage, das Passwort bleibt erforderlich. Die Freigabe endet fest nach 90 Tagen und wird durch Anmeldungen nicht verlängert. Unter <a href="security.php#geraete">Sicherheit, Gemerkte Geräte</a> sehen Sie alle Freigaben und können sie einzeln oder gesamt widerrufen; "Überall abmelden" beendet zusätzlich alle Sitzungen. Passwortänderungen und Änderungen der Zwei-Faktor-Einrichtung widerrufen alle Freigaben. Wählen Sie die Option nur auf eigenen, nicht gemeinsam genutzten Geräten.</p>'
                . '<h3>Mehrere Firmen mit einem Benutzerkonto (Multiaccount)</h3>'
                . '<p>Unter <a href="team.php#multiaccount">Firmendaten, Mein Profil</a> aktivieren Sie Multiaccount. Danach finden Sie im Profilmenü die Firmenübersicht, legen weitere Firmen an und wechseln zwischen ihnen. Sobald Ihrem Konto mehrere Firmen zugeordnet sind, ist Multiaccount automatisch aktiv. Jede Firma hat getrennte Kunden, Rechnungen, Einzüge, Zugänge und ein eigenes Abonnement. Registrieren Sie sich mit einer bereits bekannten E-Mail-Adresse für eine weitere Firma, melden Sie sich zunächst mit Ihrem bestehenden Konto an und bestätigen dann die Anlage; ein zweites Benutzerkonto mit derselben E-Mail-Adresse entsteht nicht.</p>',
        ],
        [
            'slug' => 'abo-abrechnung',
            'title' => 'Abonnement und Abrechnung',
            'summary' => 'Tarif, Preis und Kündigung des Abonnements.',
            'html' => '<h3>Tarif</h3>'
                . '<p>SmartEinzug wird im Tarif UNLIMITED START angeboten. Für bis zum 31.12.2026 angelegte Firmenaccounts gilt ein Einführungspreis von 25,00 EUR netto je 4 Wochen (bisher 50,00 EUR). Alle Preise verstehen sich netto zuzüglich der gesetzlichen Umsatzsteuer.</p>'
                . '<h3>Abrechnung</h3>'
                . '<p>Die Abrechnung läuft über Stripe. Rechnungen zum Abonnement finden Sie im Stripe-Kundenportal, erreichbar unter <a href="subscription.php">Abonnement</a>.</p>'
                . '<h3>Kündigung</h3>'
                . '<p>Eine Kündigung wird zum Ende der laufenden Abrechnungsperiode vorgemerkt; bis dahin bleibt das Abonnement nutzbar.</p>'
                . '<p>Hinweis: Die Abo-Abrechnung wird für einen Firmenaccount erst mit Freischaltung durch den Betreiber aktiv.</p>'
                . '<h3>Zugriff</h3>'
                . '<p>Das Abonnement verwalten kann ausschließlich der Inhaber des Firmenaccounts. Unter <a href="subscription.php">Abonnement</a> sehen Sie den aktuellen Tarif, den Status und, sofern eine Kündigung vorgemerkt ist, das Ende der laufenden Periode.</p>'
                . '<h3>Bereits vorgemerkte Kündigung zurücknehmen</h3>'
                . '<p>Solange die Abrechnungsperiode noch läuft, lässt sich eine vorgemerkte Kündigung unter <a href="subscription.php">Abonnement</a> auch wieder zurücknehmen; das Abonnement läuft dann unverändert weiter. Der Inhaber wird über eine Kündigung und deren Rücknahme jeweils per E-Mail informiert.</p>',
        ],
        [
            'slug' => 'fehlermeldungen',
            'title' => 'Häufige Meldungen und ihre Bedeutung',
            'summary' => 'Erklärung der wichtigsten Meldungen in SmartEinzug.',
            'html' => '<h3>Meldungen</h3>'
                . '<ul>'
                . '<li><strong>Stripe ist nicht verbunden.</strong> Unter <a href="settings.php">Einstellungen</a> ist kein gültiger Stripe-Schlüssel hinterlegt; ohne diesen kann kein Einzug bei Stripe eingereicht werden.</li>'
                . '<li><strong>Not-Stopp aktiv.</strong> Für die Firma oder plattformweit ist der Not-Stopp gesetzt; es werden keine Lastschriften eingereicht, bis er aufgehoben wird.</li>'
                . '<li><strong>Keinen offenen Restbetrag mehr.</strong> Laut Lexware Office ist die Rechnung bereits vollständig bezahlt; es wird keine Lastschrift eingereicht.</li>'
                . '<li><strong>Handschriftlicher Nachweis erforderlich.</strong> Für diesen Kunden ist die Einstellung aktiv, dass ein unterschriebenes Mandat als Nachweis erfasst sein muss, bevor ein Einzug möglich ist.</li>'
                . '<li><strong>Einzugsversuch nicht abgeschlossen.</strong> Zu dieser Rechnung läuft bereits ein Versuch mit unklarem Ergebnis; ein neuer Versuch ist erst nach Klärung möglich.</li>'
                . '<li><strong>Zur Klärung markiert.</strong> Nach einer Rücklastschrift oder Erstattung ist die Rechnung zur Klärung markiert; ein Inhaber oder Administrator muss die Klärung abschließen.</li>'
                . '<li><strong>Einreichfenster geschlossen.</strong> Die Einreichung bei Stripe erfolgt nur innerhalb des Einreichfensters von 23:00 bis 06:00 Uhr; außerhalb dieses Fensters können nur Inhaber oder Administrator mit 2FA-Code ausnahmsweise einreichen.</li>'
                . '</ul>',
        ],
    ],
    'faq' => [
        ['topic' => 'einzug-ablauf', 'q' => 'Warum wird mein Einzug nicht sofort eingereicht?', 'a' => '<p>Nach dem Auslösen gilt eine Karenzzeit von 4 Stunden, in der Sie den Einzug noch zurücknehmen können. Danach wird der Einzug nur innerhalb des Einreichfensters von 23:00 bis 06:00 Uhr bei Stripe eingereicht.</p>'],
        ['topic' => 'einzug-ablauf', 'q' => 'Kann ich einen Einzug zurücknehmen?', 'a' => '<p>Solange sich ein Einzug im Status Vorgemerkt oder Terminiert befindet und noch nicht bei Stripe eingereicht wurde, können Sie ihn unter <a href="collections.php">Einzüge</a> stornieren. Die zugehörige Rechnung ist dann wieder offen.</p>'],
        ['topic' => 'einzug-ablauf', 'q' => 'Was passiert bei einer Teilzahlung?', 'a' => '<p>SmartEinzug prüft den Restbetrag vor der Einreichung live bei Lexware Office. Wurde bereits ein Teilbetrag gezahlt, wird nur der verbleibende Restbetrag eingezogen.</p>'],
        ['topic' => 'kunden-iban-mandate', 'q' => 'Warum steht auf dem Kontoauszug des Kunden eine andere Gläubiger-ID?', 'a' => '<p>Da der technische Einzug über Stripe läuft, erscheint auf dem Kontoauszug des Kunden in der Regel die Gläubiger-Identifikationsnummer von Stripe und nicht die eigene Gläubiger-ID der Firma. Die interne Mandatsreferenz und die Stripe-Referenz werden in SmartEinzug getrennt angezeigt.</p>'],
        ['topic' => 'kunden-iban-mandate', 'q' => 'Brauche ich eine Gläubiger-Identifikationsnummer?', 'a' => '<p>Die Angabe unter <a href="team.php">Firmendaten</a> ist freiwillig. Für den Einzug über Stripe verwendet Stripe seine eigene Gläubiger-ID.</p>'],
        ['topic' => 'abo-abrechnung', 'q' => 'Was kostet SmartEinzug?', 'a' => '<p>Im Tarif UNLIMITED START gilt für bis zum 31.12.2026 angelegte Firmenaccounts ein Einführungspreis von 25,00 EUR netto je 4 Wochen (bisher 50,00 EUR), zuzüglich gesetzlicher Umsatzsteuer.</p>'],
        ['topic' => 'rollen-sicherheit', 'q' => 'Wie viele Benutzer kann ich anlegen?', 'a' => '<p>Das Sitzlimit richtet sich nach Ihrem Tarif. Bestandskunden des Starttarifs behalten in der Regel eine unbegrenzte Zahl an Benutzern, solange der Tarif administrativ nicht geändert wird.</p>'],
        ['topic' => 'ruecklastschrift-erstattung', 'q' => 'Was passiert bei einer Rücklastschrift?', 'a' => '<p>Stripe meldet die Rücklastschrift über den Webhook. SmartEinzug markiert die betroffene Rechnung zur Klärung. Ein neuer Einzug wird nicht automatisch ausgelöst, sondern erst nach abgeschlossener Klärung.</p>'],
        ['topic' => 'stripe-verbindung', 'q' => 'Muss jeder Kunde einen Webhook anlegen?', 'a' => '<p>Nein. Der Webhook liegt im Stripe-Konto Ihrer Firma und wird einmalig eingerichtet, nicht je Kunde.</p>'],
        ['topic' => 'lexware-verbindung', 'q' => 'Wird die Zahlung in Lexware Office verbucht?', 'a' => '<p>Nein. SmartEinzug schreibt Zahlungen nicht nach Lexware Office zurück. Die Zuordnung der Zahlung zur Rechnung erfolgt in Lexware Office manuell.</p>'],
        ['topic' => 'einzug-ablauf', 'q' => 'Wie lange dauert eine SEPA-Lastschrift?', 'a' => '<p>Nach der Einreichung bei Stripe dauert es einige Bankarbeitstage, bis der Status feststeht. SmartEinzug zeigt den jeweiligen Status im Einzugsjournal an.</p>'],
        ['topic' => 'einzug-ablauf', 'q' => 'Was ist die Vorabankündigung?', 'a' => '<p>Bei terminierten Einzügen kann SmartEinzug eine Vorabankündigung per E-Mail versenden. Die Frist dafür ist unter <a href="team.php">Firmendaten</a> einstellbar, Standard sind 14 Tage vor Fälligkeit.</p>'],
        ['topic' => 'stripe-verbindung', 'q' => 'Kann ich SmartEinzug im Stripe-Testmodus ausprobieren?', 'a' => '<p>Ja. Unter <a href="settings.php">Einstellungen</a> lässt sich ein Secret Key im Testmodus hinterlegen. SmartEinzug zeigt an, in welchem Modus die Verbindung steht.</p>'],
        ['topic' => 'not-stopp', 'q' => 'Was ist der Not-Stopp?', 'a' => '<p>Der Not-Stopp hält alle neuen Einreichungen bei Stripe an. Vorgemerkte und terminierte Einzüge bleiben bestehen und können gesammelt storniert werden. Bereits eingereichte Lastschriften sind davon nicht betroffen.</p>'],
        ['topic' => 'abo-abrechnung', 'q' => 'Wie kündige ich?', 'a' => '<p>Unter <a href="subscription.php">Abonnement</a> können Inhaber die Kündigung vormerken. Sie wirkt zum Ende der laufenden Abrechnungsperiode; bis dahin bleibt das Abonnement nutzbar.</p>'],
        ['topic' => 'kunden-iban-mandate', 'q' => 'Wann verfällt ein SEPA-Mandat?', 'a' => '<p>Ein Mandat gilt als verfallen, wenn 36 Monate lang keine Lastschrift dazu eingezogen wurde. Für einen weiteren Einzug ist dann ein neues Mandat erforderlich.</p>'],
        ['topic' => 'import-bestand', 'q' => 'Was übernimmt der Import aus Stripe nicht?', 'a' => '<p>Mandate und IBANs werden beim Import nicht übernommen, da Stripe die IBAN nicht vollständig herausgibt. Diese hinterlegen Sie weiterhin je Kunde unter <a href="sepa-pflegen.php">SEPA Pflegen</a>.</p>'],
        ['topic' => 'sammel-einzug', 'q' => 'Werden automatische Regeln bereits angewendet?', 'a' => '<p>Nein. Regeln für automatische Einzüge stehen derzeit nur als Vorschau zur Verfügung und lösen noch keine Einzüge selbstständig aus.</p>'],
    ],
];
