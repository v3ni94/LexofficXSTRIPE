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
str_contains($disp, 'requires_review = 1') ? $ok('setzt requires_review = 1') : $bad('setzt requires_review NICHT: widerrufene Lastschrift wird erneut eingezogen');
str_contains($disp, 'review_reason') ? $ok('hinterlegt einen Grund fuer die Klaerung') : $bad('review_reason fehlt');
str_contains($disp, 'tenant_id = ?') ? $ok('Aktualisierung ist auf die Firma begrenzt') : $bad('Aktualisierung ohne Mandantenbezug');
// Gegenprobe: Die Auswahl automatischer Einzuege schliesst 'failed' NICHT aus, deshalb ist requires_review noetig.
$coll = $lies('php-ionos/app/collections.php');
(substr_count($coll, "collection_status NOT IN ('in_collection', 'scheduled', 'collected')") >= 2 && substr_count($coll, 'requires_review = 0') >= 2)
    ? $ok('Auswahl automatischer Einzuege filtert ueber requires_review (Begruendung des Falls)')
    : $bad('Auswahl automatischer Einzuege nicht wie erwartet aufgebaut');

echo "\nB) Unklares Ergebnis bei Stripe-Fehler 5xx und 409\n";
$stripe = $lies('php-ionos/app/stripe.php');
$vierhundert = $abschnitt($stripe, 'if ($status >= 400) {', 'return $data;');
preg_match('/outcomeUnknown\s*=\s*\$status\s*>=\s*500\s*\|\|\s*\$status\s*===\s*409/', $vierhundert)
    ? $ok('5xx und 409 mit lesbarer Fehlerantwort gelten als unbekannt')
    : $bad('5xx mit JSON-Fehlertext gilt als endgueltiger Fehlschlag: Wiederholung erzeugt eine zweite Lastschrift');
str_contains($stripe, '$ex->outcomeUnknown = $status === 0 || $status >= 500;')
    ? $ok('Antwort ohne lesbares JSON bleibt unbekannt') : $bad('Fall ohne JSON-Antwort veraendert');
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

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
