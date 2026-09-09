<?php
/**
 * Prueft die Trennung von Kundenanwendung und Adminbereich auf getrennten Hosts, ohne Netz und ohne Datenbank.
 *
 * Hintergrund (Befund 09.09.2026): Im Adminbereich erschien der Hinweisbalken "Ihr Firmenaccount ist noch nicht
 * freigeschaltet" mit dem Knopf "Jetzt freischalten". Der Link war relativ und fuehrte auf
 * admin.<domain>/subscription.php; dort liefert enforce_host_rules() bewusst 404 ("Nicht gefunden"), weil auf dem
 * Adminhost ausschliesslich Adminseiten, Anmeldung und Sicherheit erreichbar sind. Kundenbezogene Hinweisbalken
 * duerfen auf einem getrennten Adminhost deshalb weder erscheinen noch dorthin verlinken.
 *
 * Aufruf: php tools/host-separation-check.php     Exit 0 = alle Faelle bestanden
 */
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('SMARTEINZUG_CONFIG=' . $root . '/php-ionos/app/config.example.php');
require $root . '/php-ionos/app/bootstrap.php';

$pass = 0; $fail = 0;
$ok  = static function (string $m) use (&$pass): void { $pass++; echo "  OK    $m\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "  FAIL  $m\n"; };

/** Konfiguration und angefragten Host setzen, dann admin_host_separated() auswerten. */
$fall = static function (string $app, string $admin, string $host): bool {
    $GLOBALS['config']['app_base_url'] = $app;
    $GLOBALS['config']['base_url'] = $app;
    $GLOBALS['config']['admin_base_url'] = $admin;
    $_SERVER['HTTP_HOST'] = $host;
    return admin_host_separated();
};

echo "1) admin_host_separated(): nur der eigene Adminhost zaehlt\n";
$fall('https://app.example.de', 'https://admin.example.de', 'admin.example.de')
    ? $ok('Anfrage ueber den getrennten Adminhost') : $bad('getrennter Adminhost nicht erkannt');
!$fall('https://app.example.de', 'https://admin.example.de', 'app.example.de')
    ? $ok('Anfrage ueber die Kundenanwendung') : $bad('Kundenhost faelschlich als Adminhost');
!$fall('https://app.example.de', '', 'app.example.de')
    ? $ok('Uebergangsmodus ohne eigenen Adminhost') : $bad('Uebergangsmodus falsch bewertet');
!$fall('https://app.example.de', 'https://app.example.de', 'app.example.de')
    ? $ok('gemeinsamer Host fuer Admin und Anwendung gilt nicht als getrennt') : $bad('gemeinsamer Host falsch bewertet');
!$fall('https://app.example.de', 'https://admin.example.de', 'status.example.de')
    ? $ok('fremder Host') : $bad('fremder Host falsch bewertet');
$fall('https://app.example.de', 'https://ADMIN.example.de', 'admin.example.de:8443')
    ? $ok('Gross-/Kleinschreibung und Port werden abgeschnitten') : $bad('Host-Normalisierung fehlt');

echo "\n2) layout.php: Kundenbalken nur in der Kundenanwendung, Links absolut\n";
$layout = (string)file_get_contents($root . '/php-ionos/app/layout.php');
str_contains($layout, '$kundenBanner = !admin_host_separated();')
    ? $ok('Kennzeichen aus admin_host_separated() gesetzt') : $bad('admin_host_separated() nicht ausgewertet');
foreach ([
    "\$kundenBanner && \$ctx && !empty(\$ctx['support_mode'])" => 'Support-Modus',
    "\$kundenBanner && \$ctx && integration_stripe_test_mode" => 'Testmodus',
    "\$kundenBanner && \$ctx && billing_enabled()" => 'Abonnement',
] as $needle => $titel) {
    str_contains($layout, $needle) ? $ok("Balken $titel ist auf die Kundenanwendung begrenzt") : $bad("Balken $titel ohne Begrenzung");
}
// Im Balkenbereich kein relativer Link mehr auf Kundenseiten, die auf dem Adminhost 404 liefern.
// (Die Navigation darunter ist bereits ueber !on_admin_host() ausgeblendet und bleibt unberuehrt.)
$von = strpos($layout, '$kundenBanner = !admin_host_separated();');
$bis = strpos($layout, 'function layout_footer');
$balken = $von !== false && $bis !== false && $bis > $von ? substr($layout, $von, $bis - $von) : '';
$balken !== '' ? $ok('Balkenbereich abgegrenzt') : $bad('Balkenbereich nicht gefunden');
foreach (['subscription.php', 'settings.php', 'support-end.php'] as $seite) {
    preg_match_all('/href="(?!<\?)[^"]*' . preg_quote($seite, '/') . '/', $balken, $m);
    empty($m[0]) ? $ok("kein relativer Link auf $seite") : $bad("relativer Link auf $seite: " . implode(', ', $m[0]));
}
substr_count($layout, '<?= e($appBase) ?>/subscription.php') === 2
    ? $ok('beide Abo-Links zeigen absolut auf die Kundenanwendung') : $bad('Abo-Links nicht absolut');

echo "\n3) bootstrap.php: Kundenseiten bleiben auf dem Adminhost gesperrt\n";
$boot = (string)file_get_contents($root . '/php-ionos/app/bootstrap.php');
preg_match('/\$adminAllowed = \[(.*?)\];/s', $boot, $m);
$erlaubt = $m[1] ?? '';
$erlaubt !== '' ? $ok('Positivliste des Adminhosts gefunden') : $bad('Positivliste nicht gefunden');
foreach (['subscription.php', 'settings.php', 'support-end.php', 'invoices.php', 'collections.php'] as $seite) {
    !str_contains($erlaubt, $seite) ? $ok("$seite bleibt auf dem Adminhost gesperrt") : $bad("$seite faelschlich erlaubt");
}
foreach (['login.php', 'twofa-verify.php', 'logout.php', 'security.php'] as $seite) {
    str_contains($erlaubt, $seite) ? $ok("$seite bleibt auf dem Adminhost erreichbar") : $bad("$seite fehlt in der Positivliste");
}

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
