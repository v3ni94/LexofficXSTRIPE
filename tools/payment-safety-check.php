<?php
/**
 * Sicherungen des Geldflusses, die aus der Gesamtpruefung vom 09.09.2026 stammen (Version 4.52).
 * Prueft ohne Datenbank, ohne Netz und ohne Stripe-Konto, dass die damals behobenen Fehler nicht zurueckfallen.
 *
 * Jeder Fall nennt den urspruenglichen Fehler, damit spaetere Aenderungen die Absicht erkennen:
 *  A) Ruecklastschrift (charge.dispute.created) setzt Klaerungsbedarf, sonst zieht der naechste automatische
 *     Lauf dieselbe Rechnung erneut ein.
 *  B) Ein Stripe-Fehler 5xx oder 409 gilt als UNBEKANNTES Ergebnis; eine Wiederholung mit neuem
 *     Idempotenz-Schluessel wuerde sonst eine zweite Lastschrift erzeugen.
 *  C) queue_requeue() liest den Zwischenstand aus der Datenbank, nicht aus der uebergebenen Jobkopie.
 *  D) sync_invoices_step() ergaenzt fehlende Cursor-Schluessel immer (Vollabgleich setzt nur force_full).
 *  E) Der Not-Aus je Buchhaltungssystem wird im Einzugspfad wirklich abgefragt (Verhalten zusaetzlich in
 *     tools/sevdesk-check.sh gegen eine echte Datenbank).
 *  F) Der Cutover in deploy.sh wertet seinen Exitcode aus und loest ein Rollback aus.
 *
 * Aufruf: php tools/payment-safety-check.php     Exit 0 = alle Faelle bestanden
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok  = static function (string $m) use (&$pass): void { $pass++; echo "  OK    $m\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "  FAIL  $m\n"; };
$lies = static function (string $rel) use ($root): string { return (string)file_get_contents($root . '/' . $rel); };
/** Textabschnitt zwischen zwei Markierungen (fuer Pruefungen, die nur einen Zweig betreffen). */
$abschnitt = static function (string $text, string $von, string $bis): string {
    $a = strpos($text, $von);
    if ($a === false) { return ''; }
    $b = strpos($text, $bis, $a + strlen($von));
    return substr($text, $a, ($b === false ? strlen($text) : $b) - $a);
};

echo "A) Ruecklastschrift setzt Klaerungsbedarf (kein automatischer Neu-Einzug)\n";
$hook = $lies('php-ionos/stripe-webhook.php');
$disp = $abschnitt($hook, "case 'charge.dispute.created':", 'break;');
$disp !== '' ? $ok('Zweig charge.dispute.created gefunden') : $bad('Zweig charge.dispute.created fehlt');
// Seit 4.73 liegt die Wirkung in collection_apply_dispute() (app/collections.php), die auch der manuelle
// Statusabgleich nutzt; der Webhook-Zweig muss sie aufrufen, die Funktion selbst traegt die Sicherungen.
$coll = $lies('php-ionos/app/collections.php');
str_contains($disp, 'collection_apply_dispute(') ? $ok('Webhook-Zweig ruft collection_apply_dispute() auf') : $bad('Webhook-Zweig setzt die Ruecklastschrift nicht ueber collection_apply_dispute()');
$dispFn = $abschnitt($coll, 'function collection_apply_dispute(', "\n}\n");
$dispFn !== '' ? $ok('collection_apply_dispute() gefunden') : $bad('collection_apply_dispute() fehlt');
str_contains($dispFn, 'requires_review = 1') ? $ok('setzt requires_review = 1') : $bad('setzt requires_review NICHT: widerrufene Lastschrift wird erneut eingezogen');
str_contains($dispFn, 'review_reason') ? $ok('hinterlegt einen Grund fuer die Klaerung') : $bad('review_reason fehlt');
substr_count($dispFn, 'tenant_id = ?') >= 2 ? $ok('Aktualisierung von Einzug und Rechnung ist auf die Firma begrenzt') : $bad('Aktualisierung ohne Mandantenbezug');
str_contains($dispFn, "=== 'disputed'") ? $ok('idempotent: bereits vermerkte Ruecklastschrift wird nicht erneut geschrieben') : $bad('keine Idempotenz in collection_apply_dispute()');
// Der manuelle Statusabgleich (4.73) muss abgeschlossene Einzuege ueber dieselben Funktionen behandeln, nie ueber eigene UPDATEs.
$sync = $abschnitt($coll, 'function sync_collection_statuses(', "\n}\n");
(str_contains($sync, 'collection_apply_dispute(') && str_contains($sync, 'collection_apply_refund('))
    ? $ok('Statusabgleich vermerkt Ruecklastschrift und Erstattung ueber collection_apply_dispute()/collection_apply_refund()')
    : $bad('Statusabgleich behandelt Ruecklastschrift oder Erstattung nicht ueber die gemeinsamen Funktionen');
str_contains($sync, "stripe_status IN ('succeeded', 'refunded')") ? $ok('Rueckschau umfasst erfolgreiche und erstattete Einzuege') : $bad('Rueckschau auf abgeschlossene Einzuege fehlt');
// Gegenprobe: Die Auswahl automatischer Einzuege schliesst 'failed' NICHT aus, deshalb ist requires_review noetig.
(substr_count($coll, "collection_status NOT IN ('in_collection', 'scheduled', 'collected')") >= 2 && substr_count($coll, 'requires_review = 0') >= 2)
    ? $ok('Auswahl automatischer Einzuege filtert ueber requires_review (Begruendung des Falls)')
    : $bad('Auswahl automatischer Einzuege nicht wie erwartet aufgebaut');

echo "\nB) Unklares Ergebnis bei Stripe-Fehler 5xx und 409\n";
$stripe = $lies('php-ionos/app/stripe.php');
$vierhundert = $abschnitt($stripe, 'if ($status >= 400) {', 'return $data;');
preg_match('/outcomeUnknown\s*=\s*\$status\s*>=\s*500\s*\|\|\s*\$status\s*===\s*409/', $vierhundert)
    ? $ok('5xx und 409 mit lesbarer Fehlerantwort gelten als unbekannt')
    : $bad('5xx mit JSON-Fehlertext gilt als endgueltiger Fehlschlag: Wiederholung erzeugt eine zweite Lastschrift');
str_contains($stripe, '$ex->outcomeUnknown = $status === 0 || $status >= 500 || $status < 400;')
    ? $ok('Antwort ohne lesbares JSON bleibt unbekannt (nur klare 4xx sind endgueltig, Audit 10.09.2026)') : $bad('Fall ohne JSON-Antwort veraendert');
str_contains($stripe, '$ex->outcomeUnknown = true;') ? $ok('Verbindungsfehler bleibt unbekannt') : $bad('Verbindungsfehler nicht mehr unbekannt');
// Der Einzugspfad muss ein unbekanntes Ergebnis in die Klaerung fuehren und darf es nie als 'failed' journalisieren.
$mitAttempt = $abschnitt($coll, 'function _execute_with_attempt', 'collection_attempt_finish($attempt[\'id\'], \'succeeded\'');
(str_contains($mitAttempt, "if (\$e->outcomeUnknown)") && str_contains($mitAttempt, "collection_attempt_finish(\$attempt['id'], 'unknown'"))
    ? $ok('unbekanntes Ergebnis wird als "unknown" journalisiert und geklaert')
    : $bad('Zuordnung unbekannt -> Klaerung fehlt');

echo "\nC) Fortsetzung eines Jobs verliert den Zwischenstand nicht\n";
$queue = $lies('php-ionos/app/queue.php');
$requeue = $abschnitt($queue, 'function queue_requeue(', "\n}\n");
(str_contains($requeue, 'SELECT payload FROM jobs WHERE id = ?'))
    ? $ok('queue_requeue liest den Zwischenstand aus der Datenbank')
    : $bad('queue_requeue schreibt die uebergebene Jobkopie zurueck und loescht damit _seen');
str_contains($requeue, "\$payload['_continuations']") ? $ok('Fortsetzungszaehler bleibt erhalten') : $bad('Fortsetzungszaehler fehlt');
$jobs = $lies('php-ionos/app/jobs.php');
str_contains($jobs, "queue_update_payload(\$job, \$payload);") ? $ok('Einzugsjob speichert seinen Zwischenstand (_seen)') : $bad('Zwischenstand wird nicht gespeichert');

echo "\nD) Cursor der Synchronisation ist immer vollstaendig\n";
$sync = $lies('php-ionos/app/sync.php');
$kopf = $abschnitt($sync, 'function sync_invoices_step(', '$rules = sync_rules_config();');
str_contains($kopf, '$cursor = is_array($cursor) ? $cursor + $vorgabe : $vorgabe;')
    ? $ok('fehlende Schluessel werden immer ergaenzt') : $bad('Vorgabewerte nur bei leerem Cursor: Vollabgleich bricht ab');
!preg_match('/if \(\$cursor === null\) \{\s*\$cursor = \[/', $kopf)
    ? $ok('keine Initialisierung nur fuer den Fall null mehr') : $bad('alte Initialisierung noch vorhanden');
foreach (['phase', 'listing_status', 'lex_page', 'collected', 'proc_index', 'result', 'recheck_ids', 'metrics'] as $k) {
    str_contains($kopf, "'" . $k . "'") ? $ok("Vorgabe enthaelt $k") : $bad("Vorgabe ohne $k");
}
str_contains($jobs, "JSON_SET(COALESCE(cursor_json, '{}'), '\$.force_full', true)")
    ? $ok('Vollabgleich setzt force_full auf einen sonst leeren Cursor (Ursache des Falls)') : $bad('force_full nicht mehr wie beschrieben gesetzt');
// Gegenprobe der Zusammenfuehrung mit demselben Ausdruck wie im Code
$cursor = ['force_full' => true];
$vorgabe = ['phase' => 'listing', 'proc_index' => 0, 'force_full' => false];
$zusammen = is_array($cursor) ? $cursor + $vorgabe : $vorgabe;
($zusammen['phase'] === 'listing' && $zusammen['force_full'] === true && $zusammen['proc_index'] === 0)
    ? $ok('Zusammenfuehrung ergaenzt fehlende Schluessel und behaelt force_full') : $bad('Zusammenfuehrung falsch');

echo "\nE) Not-Aus je Buchhaltungssystem wirkt im Einzugspfad\n";
str_contains($coll, 'function collections_source_blocked(string $tenantId): ?string')
    ? $ok('collections_source_blocked() vorhanden') : $bad('collections_source_blocked() fehlt');
(substr_count($coll, 'collections_source_blocked($tenantId)') >= 2)
    ? $ok('vor sofortigem UND vor terminiertem Einzug geprueft') : $bad('Not-Aus nicht in beiden Einzugswegen geprueft');
$sofort = $abschnitt($coll, 'function _submit_collection_locked(', '// 2. Kontingent');
(str_contains($sofort, 'collections_pause_reason($tenantId)') && str_contains($sofort, 'collections_source_blocked($tenantId)'))
    ? $ok('Reihenfolge: Not-Stopp, dann Not-Aus des Systems') : $bad('Not-Aus fehlt vor dem sofortigen Einzug');
$terminiert = $abschnitt($coll, 'function _submit_single_scheduled(', 'Einzug atomar beanspruchen');
str_contains($terminiert, 'CollectionDeferredException($blocked)')
    ? $ok('terminierter Einzug wird zurueckgestellt, nicht als Fehlversuch gewertet') : $bad('terminierter Einzug ohne Zurueckstellung');
$src = $lies('php-ionos/app/invoice_source.php');
str_contains($src, 'function invoice_source_code_for_tenant(string $tenantId): string')
    ? $ok('Systemcode je Firma ohne Client-Aufbau ermittelbar') : $bad('invoice_source_code_for_tenant() fehlt');
str_contains($lies('php-ionos/app/integration_state.php'), "'collections' => false")
    ? $ok('Vorgabe des Schalters ist gesperrt') : $bad('Vorgabe des Schalters nicht gesperrt');

echo "\nF) Cutover des Deployments mit Fehlerbehandlung\n";
$deploy = $lies('deploy/vps/scripts/deploy.sh');
$cut = $abschnitt($deploy, 'Aktiviere Release $SHA (Cutover', 'deploy_step "warte-healthy"');
str_contains($cut, 'CUTOVER_RC=$?') ? $ok('Exitcode des Cutovers wird ausgewertet') : $bad('Cutover ohne Auswertung des Exitcodes');
str_contains($cut, 'run_rollback') ? $ok('Rollback bei fehlgeschlagenem Cutover') : $bad('kein Rollback bei fehlgeschlagenem Cutover');
str_contains($cut, 'deploy_fail_report "cutover"') ? $ok('Fehlerbericht in der Statusdatei') : $bad('kein Fehlerbericht');
(str_contains($cut, 'set +e') && str_contains($cut, 'set -e')) ? $ok('Abschnitt ist gegen set -e gekapselt') : $bad('Abschnitt nicht gekapselt');

echo "\nG) Webhooks: Wiederholung statt stiller Verlust\n";
$bh = $lies('php-ionos/billing-webhook.php');
(str_contains($bh, 'http_response_code(500)') && str_contains($bh, "echo 'retry'"))
    ? $ok('Abrechnungs-Webhook antwortet bei Verarbeitungsfehlern mit 500') : $bad('Abrechnungs-Webhook quittiert Fehler weiter mit 200');
$bill = $lies('php-ionos/app/billing.php');
(str_contains($bill, "webhook_event_release('billing', \$eventId)") && str_contains($bill, '_billing_handle_event_inner'))
    ? $ok('Beanspruchung wird bei Fehler zurueckgenommen') : $bad('Beanspruchung bleibt bei Fehler bestehen: Wiederholung liefe ins Leere');
$hook = $lies('php-ionos/stripe-webhook.php');
(str_contains($hook, "webhook_event_claim('tenant'") && str_contains($hook, "webhook_event_is_stale('tenant'"))
    ? $ok('Mandanten-Webhook: Doppelzustellung und Reihenfolge geprueft') : $bad('Mandanten-Webhook ohne Idempotenz- oder Reihenfolgeschutz');
(str_contains($hook, 'http_response_code(500)') && str_contains($hook, "webhook_event_release('tenant'"))
    ? $ok('Mandanten-Webhook: Fehler fuehrt zu Wiederholung mit Freigabe') : $bad('Mandanten-Webhook verschluckt Fehler weiterhin');
(substr_count($hook, 'webhook_exit(') > 5 && str_contains($hook, 'bool $freigeben = false'))
    ? $ok('vorlaeufige Faelle koennen die Beanspruchung freigeben') : $bad('kein Freigabeweg fuer vorlaeufige Faelle');
$we = $lies('php-ionos/app/webhook_events.php');
foreach (['webhook_event_claim', 'webhook_event_release', 'webhook_event_is_stale', 'webhook_event_mark_object'] as $fn) {
    str_contains($we, 'function ' . $fn) ? $ok("gemeinsames Modul: $fn") : $bad("Modul ohne $fn");
}

echo "\nH) Support-Modus: gesperrt, was der Firma vorbehalten ist\n";
$cs = $lies('php-ionos/app/customer_settings.php');
(substr_count($cs, 'support_guard();') >= 3)
    ? $ok('IBAN setzen, IBAN deaktivieren und SEPA-Freigabe zentral gesperrt') : $bad('Sperre fehlt in app/customer_settings.php');
$cp = $lies('php-ionos/customer.php');
str_contains($cp, "support_guard();") ? $ok('Kundendetailseite sperrt die betroffenen Aktionen') : $bad('Kundendetailseite ohne Sperre');
str_contains($coll, "if (!\$paused && function_exists('support_mode') && support_mode())")
    ? $ok('Not-Stopp: Aufheben gesperrt, Aktivieren bleibt moeglich') : $bad('Not-Stopp im Support-Modus nicht geregelt');
$leg = $lies('php-ionos/app/legal.php');
(substr_count($leg, 'support_mode()') >= 2)
    ? $ok('keine rechtsverbindliche Zustimmung im Namen der Firma') : $bad('legal.php ohne Support-Sperre');
str_contains($lies('php-ionos/team.php'), 'support_guard();') ? $ok('Firmendaten mit Geldbezug gesperrt') : $bad('team.php ohne Sperre');
str_contains($lies('php-ionos/settings.php'), "support_guard(); // trennt die Verbindung")
    ? $ok('Wechsel des Buchhaltungssystems gesperrt') : $bad('Wechsel des Buchhaltungssystems ohne Sperre');

echo "\nI) Sicherheit: Cookie, Einrichtungspruefung, Platzhalter\n";
$boot = $lies('php-ionos/app/bootstrap.php');
(str_contains($boot, '$secureCookie') && str_contains($boot, "str_starts_with(strtolower(app_base_url()), 'https://')"))
    ? $ok('Sitzungscookie mit Secure auch hinter einem Proxy') : $bad('Secure-Flag haengt weiter allein an $_SERVER[HTTPS]');
str_contains($boot, 'function config_is_placeholder')
    ? $ok('zentrale Erkennung von Platzhaltern') : $bad('config_is_placeholder() fehlt');
str_contains($lies('php-ionos/app/crypto.php'), 'config_is_placeholder($secret)')
    ? $ok('kein Schluessel aus dem Platzhalter') : $bad('crypto.php akzeptiert den Platzhalter (genau 32 Zeichen)');
str_contains($lies('php-ionos/cron.php'), 'config_is_placeholder($expected)')
    ? $ok('Cron-Endpunkt weist den Platzhalter ab') : $bad('cron.php akzeptiert den Platzhalter');
str_contains($lies('php-ionos/migrate.php'), 'config_is_placeholder($expected)')
    ? $ok('Migrationsendpunkt weist den Platzhalter ab') : $bad('migrate.php akzeptiert den Platzhalter');
$sc = $lies('php-ionos/setup-check.php');
(str_contains($sc, "getenv('SMARTEINZUG_CONFIG')") && str_contains($sc, 'elseif (is_array($preConfig))'))
    ? $ok('Einrichtungspruefung findet die Konfiguration des VPS und bleibt sonst verschlossen') : $bad('setup-check.php weiter oeffentlich erreichbar');
str_contains($lies('deploy/vps/Caddyfile'), '@setup_check path /setup-check.php')
    ? $ok('Caddy sperrt die Einrichtungspruefung zusaetzlich') : $bad('Caddy liefert setup-check.php aus');

echo "\nJ) Nachweis der zahlungspflichtigen Bestellung\n";
$con = $lies('php-ionos/app/consent.php');
str_contains($con, "'bestellung' =>") ? $ok('eigener Gegenstand fuer die Bestellung') : $bad('kein Gegenstand bestellung');
str_contains($con, "\$subject !== 'bestellung'") ? $ok('jede Bestellung wird einzeln festgehalten') : $bad('Bestellungen wuerden zusammengefasst');
str_contains($con, '$details') ? $ok('Erlaeuterungsfeld vorhanden') : $bad('kein Erlaeuterungsfeld');
(str_contains($bill, "consent_record(") && str_contains($bill, "'bestellung',"))
    ? $ok('Bestellung wird dauerhaft gespeichert, nicht nur im Protokoll') : $bad('Bestellung nur im Protokoll (nach 90 Tagen geloescht)');
!str_contains($bill, "'AGB smart-einzug.de, Stand ' . date('d.m.Y')")
    ? $ok('Fassung kommt nicht mehr aus dem Tagesdatum') : $bad('Fassung wird aus dem Tagesdatum gebildet');
str_contains($lies('php-ionos/sql/schema.sql'), 'details         VARCHAR(255) NULL')
    ? $ok('Spalte details im Schema') : $bad('Spalte details fehlt im Schema');
str_contains($lies('php-ionos/app/migrate.php'), "'031' => ['consent_records', 'details']")
    ? $ok('Migration 031 mit Marker') : $bad('Marker fuer Migration 031 fehlt');

echo "\nK) Alarmierung: Marke erst nach Versand, unabhaengiger Kanal\n";
$mon = $lies('php-ionos/app/monitor.php');
str_contains($mon, '$gesendet = monitor_alert_send($c, true);')
    ? $ok('Alarmmarke erst nach erfolgreichem Versand') : $bad('Marke wird weiter vor dem Versand gesetzt');
str_contains($mon, 'function monitor_alert_send(string $component, bool $opened): bool')
    ? $ok('Versandergebnis wird zurueckgegeben') : $bad('monitor_alert_send meldet den Erfolg nicht');
str_contains($mon, 'function monitor_heartbeat_ping')
    ? $ok('unabhaengiger Alarmkanal vorhanden') : $bad('kein unabhaengiger Alarmkanal');
str_contains($mon, "preg_match('~^https://~i', \$url)")
    ? $ok('Kanal nur ueber https') : $bad('Kanal ohne https-Pflicht');
str_contains($mon, 'if (!$allesOk)')
    ? $ok('Signal bleibt bei Stoerung bewusst aus (Totmannschalter)') : $bad('Signal auch bei Stoerung');
str_contains($lies('php-ionos/app/config.example.php'), "'heartbeat_url' => ''")
    ? $ok('Konfigurationsschluessel dokumentiert') : $bad('heartbeat_url fehlt in der Vorlage');

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
