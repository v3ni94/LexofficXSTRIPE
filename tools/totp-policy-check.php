<?php
/**
 * Geltungsbereich der Zweitbestaetigung per 2FA-Code (require_recent_totp) statisch pruefen.
 *
 * Beschluss des Vorstands vom 07.09.2026 (umgesetzt in 4.36): Zweitbestaetigung nur fuer Wichtiges, naemlich
 * Anmeldung, Wechsel in Kundenaccounts (Support-Modus), Wartung AKTIVIEREN, Not-Stopp AUFHEBEN und Geldfluss
 * sowie Kontosicherheit (Passwort, Inhaberwechsel, Wechsel des Buchhaltungssystems laut Projektregel). Nicht fuer
 * Tarife, Entwuerfe, Statusveroeffentlichung, Diagnose, Vormerkungen oder technische Betriebsschalter.
 * CSRF-Schutz und Audit bleiben bei jeder Aktion. Aufruf: php tools/totp-policy-check.php   (Exit 0 = gruen)
 */
declare(strict_types=1);
$root = dirname(__DIR__) . '/php-ionos/';
$pass = 0; $fail = 0;
$ok = static function (string $n, bool $c) use (&$pass, &$fail): void { $c ? $pass++ : $fail++; echo ($c ? '  OK    ' : '  FAIL  ') . $n . "\n"; };
$src = static function (string $f) use ($root): string { $s = file_get_contents($root . $f); if ($s === false) { throw new RuntimeException('Datei fehlt: ' . $f); } return $s; };

/** Text des Zweigs ab "action === 'X'" bis zum naechsten elseif/catch auf derselben Ebene (grob, aber ausreichend). */
$branch = static function (string $s, string $action): ?string {
    $pos = strpos($s, "action === '" . $action . "'");
    if ($pos === false) { return null; }
    $rest = substr($s, $pos);
    // Zweigende: naechstes "} elseif ($action" oder "} catch" (innere elseif ohne $action gehoeren zum Zweig).
    $end = preg_match('/\n\s*\} (elseif \(\$action|catch) /', $rest, $m, PREG_OFFSET_CAPTURE, 1) ? $m[0][1] : strlen($rest);
    return substr($rest, 0, $end);
};
/** Alle <form ...>...</form>, die den Aktionswert enthalten. */
$forms = static function (string $s, string $action): array {
    preg_match_all('/<form\b.*?<\/form>/s', $s, $m);
    return array_values(array_filter($m[0], static fn(string $f): bool => str_contains($f, 'value="' . $action . '"')));
};

echo "1) Pflicht (Zweig verlangt require_recent_totp)\n";
$pflicht = [
    ['admin-support.php', 'support_start', 'Support-Modus: auf Kundenaccount schalten'],
    ['notstopp.php', 'resume', 'Not-Stopp der Firma aufheben (Geldfluss)'],
    ['collections.php', 'process_due_now', 'Einreichung ausserhalb des Fensters erzwingen (Geldfluss)'],
    ['stripe-import.php', 'apply', 'Stripe-Import uebernehmen (Zahlungsstatus)'],
    ['security.php', 'change_password', 'Passwort aendern (Kontosicherheit)'],
    ['team.php', 'transfer_ownership', 'Inhaberschaft uebertragen (Kontosicherheit)'],
    ['settings.php', 'switch_invoice_source', 'Buchhaltungssystem wechseln (Projektregel)'],
    ['admin-marketing.php', 'campaign_start', 'Massenversand einer Werbekampagne freigeben (aussenwirksam, nicht rueckholbar, 4.63)'],
];
foreach ($pflicht as [$f, $a, $t]) {
    $b = $branch($src($f), $a);
    $ok("$f $a: $t", $b !== null && str_contains($b, 'require_recent_totp('));
}

echo "2) Geteilte Zweige: nur die wichtige Richtung\n";
$as = $src('admin-system.php'); $ad = $src('admin.php'); $al = $src('admin-legal.php');
$b = $branch($as, 'incident_publish');
$ok('admin-system incident_publish: Veroeffentlichen (Wartung/Stoerung aktivieren) mit 2FA', $b !== null && preg_match("/if \\(\\\$action === 'incident_publish'\\) \\{\\s*(\\/\\/[^\\n]*\\s*)*require_recent_totp\\(/s", $b) === 1);
$ok('admin-system incident_unpublish: Zurueckziehen ohne 2FA (kein Aufruf ausserhalb des if)', $b !== null && substr_count($b, 'require_recent_totp(') === 1);
$b = $branch($as, 'org_sync_pause');
$ok('admin-system org_sync_pause: Wartungsmodus aktivieren mit 2FA', $b !== null && preg_match("/if \\(\\\$pause\\) \\{\\s*(\\/\\/[^\\n]*\\s*)*require_recent_totp\\(/s", $b) === 1);
$ok('admin-system org_sync_resume: Fortsetzen ohne 2FA', $b !== null && substr_count($b, 'require_recent_totp(') === 1);
$b = $branch($ad, 'platform_pause');
$ok('admin platform_pause: Aufheben (pause=0) mit 2FA', $b !== null && preg_match("/if \\(!\\\$pause\\) \\{\\s*(\\/\\/[^\\n]*\\s*)*require_recent_totp\\(/s", $b) === 1);
$ok('admin platform_pause: Aktivieren ohne Huerde (genau ein Aufruf, im if)', $b !== null && substr_count($b, 'require_recent_totp(') === 1);
foreach (['job_retry_now', 'job_release'] as $a) {
    $b = $branch($as, $a);
    $ok("admin-system $a: 2FA nur bei geldbewegenden Jobtypen (queue_type_is_money)", $b !== null && preg_match('/if \(queue_type_is_money\([^)]*\)\) \{\s*(\/\/[^\n]*\s*)*require_recent_totp\(/s', $b) === 1 && substr_count($b, 'require_recent_totp(') === 1);
    $ok("admin-system $a: Job wird vor der Entscheidung geladen (queue_get)", $b !== null && str_contains($b, 'queue_get('));
}
$b = $branch($al, 'publish');
$ok('admin-legal publish/retire: mit 2FA', $b !== null && str_contains($b, 'require_recent_totp('));
$head = substr($al, 0, (int)strpos($al, "action === 'import_draft'"));
$ok('admin-legal: kein gemeinsamer Aufruf vor den Zweigen', !str_contains($head, 'require_recent_totp('));

echo "3) Entfallen (Zweig ohne require_recent_totp, Audit bleibt)\n";
$entfallen = [
    ['admin-system.php', 'publish_now', 'Statusdaten uebertragen', 'audit_log('],
    ['admin-system.php', 'test_mail', 'Testnachricht', 'audit_log('],
    ['admin-system.php', 'test_prenotification', 'Muster der Vorabankuendigung an eigene Adresse', 'audit_log('],
    ['admin-system.php', 'sync_enqueue', 'Synchronisation einreihen', 'audit_log('],
    ['admin-system.php', 'org_queue_flag_on', 'Warteschlangen-Flag je Firma', 'tenant_feature_set('],
    ['admin.php', 'plan_update', 'Tarif bearbeiten', 'audit_log('],
    ['admin.php', 'org_plan', 'Tarif einer Firma', 'audit_log('],
    ['admin.php', 'interest_unsubscribe', 'Vormerkung abmelden/loeschen', 'audit_log('],
    ['admin.php', 'interest_block', 'Sperrvermerk/Betaeinladung', 'audit_log('],
    ['admin-legal.php', 'import_draft', 'Vorlage uebernehmen', 'legal_document_create('],
    ['admin-legal.php', 'create', 'Fassung anlegen', 'legal_document_create('],
    ['admin-legal.php', 'delete', 'unveroeffentlichte Fassung loeschen', 'legal_document_delete('],
];
foreach ($entfallen as [$f, $a, $t, $audit]) {
    $b = $branch($src($f), $a);
    $ok("$f $a: $t ohne 2FA", $b !== null && !str_contains($b, 'require_recent_totp('));
    $ok("$f $a: Nachweis bleibt ($audit)", $b !== null && str_contains($b, $audit));
}
foreach (['admin-system.php', 'admin.php', 'admin-legal.php', 'admin-support.php', 'admin-kunde.php', 'admin-marketing.php'] as $f) {
    $ok("$f: csrf_check() im POST-Zweig", str_contains($src($f), 'csrf_check();'));
}

echo "4) Formulare: Codefeld nur, wo der Code verlangt wird\n";
foreach ([['admin-system.php', 'test_mail'], ['admin-system.php', 'test_prenotification'], ['admin-system.php', 'publish_now'], ['admin-system.php', 'sync_enqueue'], ['admin-system.php', 'org_sync_resume'],
          ['admin.php', 'plan_update'], ['admin.php', 'org_plan'], ['admin.php', 'interest_delete'], ['admin-legal.php', 'import_draft'], ['admin-legal.php', 'create'],
          ['admin-kunde.php', 'org_update'], ['admin-kunde.php', 'user_update'],
          ['admin-marketing.php', 'campaign_save'], ['admin-marketing.php', 'campaign_test'], ['admin-marketing.php', 'rates_save'], ['admin-marketing.php', 'list_import'], ['admin-marketing.php', 'suppress_add']] as [$f, $a]) {
    $fs = $forms($src($f), $a);
    $ok("$f Formular $a ohne Codefeld", $fs !== [] && !array_filter($fs, static fn(string $x): bool => str_contains($x, 'name="code"')));
}
foreach ([['admin-system.php', 'org_sync_pause'], ['admin-legal.php', 'publish'], ['admin-support.php', 'support_start'], ['notstopp.php', 'resume'], ['admin-marketing.php', 'campaign_start']] as [$f, $a]) {
    $fs = $forms($src($f), $a);
    $ok("$f Formular $a mit Codefeld", $fs !== [] && !array_filter($fs, static fn(string $x): bool => !str_contains($x, 'name="code"')));
}
foreach (['job_retry_now', 'job_cancel', 'job_close', 'job_release'] as $a) {
    $fs = $forms($as, $a);
    $ok("admin-system Formular $a: Codefeld nur bei geldbewegendem Job", $fs !== [] && !array_filter($fs, static fn(string $x): bool => !preg_match('/<\?php if \((\$money|queue_type_is_money).*?\?><input[^>]*name="code"/', $x)));
}
preg_match_all('/<form\b.*?<\/form>/s', $as, $mm);
$fs = array_values(array_filter($mm[0], static fn(string $x): bool => str_contains($x, "'incident_publish'"))); // Aktionswert wird per PHP gewaehlt
$ok('admin-system Formular incident_publish/unpublish: Codefeld nur beim Veroeffentlichen', $fs !== [] && !array_filter($fs, static fn(string $x): bool => !preg_match('/<\?php if \(!\(int\)\$inc\[\'published\'\]\): \?>.*name="code"/s', $x)));
$fs = $forms($ad, 'platform_pause');
$ok('admin Formular platform_pause: Codefeld nur beim Aufheben', $fs !== [] && !array_filter($fs, static fn(string $x): bool => !preg_match('/<\?php if \(\$paused\): \?><input[^>]*name="code"/', $x)));

echo "5) Geldfluss-Jobtypen\n";
$GLOBALS['config'] = [];
require $root . 'app/queue.php';
$ok('collections_due ist geldbewegend', queue_type_is_money('collections_due'));
$ok('unclear_attempts ist geldbewegend', queue_type_is_money('unclear_attempts'));
foreach (['sync_run', 'mail', 'alerts', 'mandate_reminders', 'monitor_collect', 'maintenance'] as $t) {
    $ok("$t ist nicht geldbewegend", !queue_type_is_money($t));
}
$ok('unbekannter Typ und null sind nicht geldbewegend', !queue_type_is_money('x') && !queue_type_is_money(null));

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
