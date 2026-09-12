<?php
/**
 * Prueft das Google-Tag der Anwendung (app.smart-einzug.de), ohne Netz und ohne Datenbank.
 *
 * Vorgabe des Betreibers vom 10.09.2026: Die Google-Ads-Kennung soll auch in der Anwendung
 * hinterlegt sein. Weil die Anwendung Kundendaten fuehrt, gilt eine enge Grenze, die diese
 * Pruefung absichert:
 *
 *   1. Das Tag erscheint nur auf den oeffentlichen Seiten register.php und vormerken.php.
 *   2. Auf angemeldeten Seiten und auf Seiten mit Token in der Adresse erscheint es nie
 *      (dort stuenden Kunden-, Rechnungs- oder Mandatskennungen im Seitenpfad).
 *   3. Ohne Einwilligung wird kein Google-Skript geladen: Das ausgelieferte HTML enthaelt
 *      keinen googletagmanager-Aufruf, nur die eigene consent.js.
 *   4. Fehlerhafte oder fremde Kennungen werden verworfen.
 *   5. 'analytics.enabled' false schaltet alles ab.
 *
 * Aufruf: php tools/app-tracking-check.php     Exit 0 = alle Faelle bestanden
 */
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('SMARTEINZUG_CONFIG=' . $root . '/php-ionos/app/config.example.php');
require $root . '/php-ionos/app/bootstrap.php';

$pass = 0; $fail = 0;
$ok  = static function (string $m) use (&$pass): void { $pass++; echo "  OK    $m\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "  FAIL  $m\n"; };

$setze = static function (array $analytics): void {
    $GLOBALS['config']['analytics'] = $analytics;
};
$AN = ['enabled' => true, 'ga_id' => 'G-8C1W9817PV', 'ads_id' => 'AW-18431688840'];

echo "A) Geltungsbereich: nur oeffentliche Seiten ohne Kennungen in der Adresse\n";
$setze($AN);
foreach (['register.php', 'vormerken.php'] as $seite) {
    tracking_allowed_for('/' . $seite)
        ? $ok("$seite darf das Tag einbinden")
        : $bad("$seite sollte das Tag einbinden duerfen");
}
$verboten = [
    'dashboard.php' => 'angemeldet',
    'customer.php' => 'Kundenkennung in der Adresse',
    'invoices.php' => 'Rechnungsdaten',
    'collections.php' => 'Einzuege',
    'mandat.php' => 'Mandatsdaten',
    'settings.php' => 'Zugangsdaten',
    'admin.php' => 'Adminbereich',
    'reset-password.php' => 'Token in der Adresse',
    'invite.php' => 'Token in der Adresse',
    'twofa-verify.php' => 'Zweitfaktor',
    'support-login.php' => 'Support-Zugang',
    'login.php' => 'Anmeldung',
    'billing-webhook.php' => 'Schnittstelle',
];
foreach ($verboten as $seite => $grund) {
    tracking_allowed_for('/' . $seite)
        ? $bad("$seite darf das Tag NICHT einbinden ($grund)")
        : $ok("$seite ohne Tag ($grund)");
}

echo "\nB) Konfiguration\n";
$setze([]);
$ids = tracking_ids();
($ids['ads'] === TRACKING_DEFAULT_ADS_ID && $ids['ads'] !== '')
    ? $ok('ohne Konfiguration greift die eingebaute Ads-Kennung (scharf ohne Servereingriff)')
    : $bad('die eingebaute Ads-Kennung greift nicht: ' . json_encode($ids));
($ids['ga'] === '')
    ? $ok('ohne Konfiguration kein Analytics (keine GA4-Property fuer app.smart-einzug.de)')
    : $bad('Analytics laeuft ohne Konfiguration: ' . json_encode($ids));
tracking_allowed_for('/register.php')
    ? $ok('register.php traegt das Tag ohne Servereingriff')
    : $bad('register.php traegt das Tag nicht');
tracking_allowed_for('/dashboard.php')
    ? $bad('dashboard.php traegt das Tag trotz Vorgabe')
    : $ok('dashboard.php bleibt auch mit Vorgabe ohne Tag');
$setze(['enabled' => false, 'ga_id' => 'G-8C1W9817PV', 'ads_id' => 'AW-18431688840']);
(!tracking_allowed_for('/register.php') && tracking_head_html() === '')
    ? $ok("'enabled' false schaltet das Tag vollstaendig ab")
    : $bad("'enabled' false schaltet das Tag nicht ab");

$setze(['enabled' => true, 'ga_id' => '', 'ads_id' => '']);
(!tracking_allowed_for('/register.php') && tracking_head_html() === '')
    ? $ok('ausdruecklich leere Kennungen ergeben kein Tag')
    : $bad('leere Kennungen erzeugen trotzdem ein Tag');

$setze(['enabled' => true, 'ga_id' => 'UA-12345', 'ads_id' => 'AW-abc']);
$ids = tracking_ids();
($ids['ga'] === '' && $ids['ads'] === '')
    ? $ok('fehlerhafte Kennungen werden verworfen')
    : $bad('fehlerhafte Kennungen werden uebernommen: ' . json_encode($ids));

$setze(['enabled' => true, 'ga_id' => 'G-8C1W9817PV', 'ads_id' => 'AW-18431688840']);
$ids = tracking_ids();
($ids['ga'] === 'G-8C1W9817PV' && $ids['ads'] === 'AW-18431688840')
    ? $ok('gueltige Kennungen werden uebernommen')
    : $bad('gueltige Kennungen fehlen: ' . json_encode($ids));

echo "\nC) Ausgeliefertes HTML laedt ohne Einwilligung kein Google-Skript\n";
$html = tracking_head_html();
(strpos($html, 'googletagmanager') === false && strpos($html, 'gtag(') === false)
    ? $ok('kein Google-Aufruf im HTML')
    : $bad('das HTML enthaelt bereits einen Google-Aufruf');
(strpos($html, 'assets/js/consent.js') !== false)
    ? $ok('consent.js wird eingebunden')
    : $bad('consent.js fehlt im HTML');
(strpos($html, 'data-ads="AW-18431688840"') !== false)
    ? $ok('Ads-Kennung wird als data-Attribut uebergeben')
    : $bad('Ads-Kennung fehlt im data-Attribut');
(strpos($html, 'data-privacy="') !== false && strpos($html, '/datenschutz') !== false)
    ? $ok('Verweis auf die Datenschutzerklaerung vorhanden')
    : $bad('Verweis auf die Datenschutzerklaerung fehlt');

echo "\nD) consent.js: Einwilligung ist Bedingung, keine Messung im Firmenaccount\n";
$js = (string)file_get_contents($root . '/php-ionos/assets/js/consent.js');
(strpos($js, "'consent', 'default'") !== false)
    ? $ok('Consent Mode wird gesetzt')
    : $bad('Consent Mode fehlt');
(strpos($js, "ad_personalization: 'denied'") !== false)
    ? $ok('personalisierte Werbung bleibt abgeschaltet')
    : $bad('personalisierte Werbung ist nicht abgeschaltet');
(strpos($js, 'function loadTag') !== false && strpos($js, "state === 'all'") !== false)
    ? $ok('das Google-Skript laedt erst nach Zustimmung')
    : $bad('das Google-Skript laedt moeglicherweise ohne Zustimmung');
(strpos($js, 'data-consent-open') !== false)
    ? $ok('die Entscheidung laesst sich spaeter aendern')
    : $bad('kein Weg, die Entscheidung zu aendern');

echo "\nE) Einbindung in den Seiten\n";
$layout = (string)file_get_contents($root . '/php-ionos/app/layout.php');
(strpos($layout, "empty(\$opts['tracking'])") !== false)
    ? $ok('layout_header bindet das Tag nur bei ausdruecklicher Freigabe ein')
    : $bad('layout_header bindet das Tag ohne Freigabe ein');
foreach (['register.php', 'vormerken.php'] as $seite) {
    $src = (string)file_get_contents($root . '/php-ionos/' . $seite);
    (strpos($src, "'tracking' => true") !== false)
        ? $ok("$seite gibt das Tag frei")
        : $bad("$seite gibt das Tag nicht frei");
}
$verdacht = [];
foreach (glob($root . '/php-ionos/*.php') as $datei) {
    $name = basename($datei);
    if (in_array($name, TRACKING_PUBLIC_PAGES, true)) {
        continue;
    }
    $src = (string)file_get_contents($datei);
    if (strpos($src, 'googletagmanager') !== false) {
        $verdacht[] = $name . ' (Google-Aufruf im Quelltext)';
        continue;
    }
    if (strpos($src, "'tracking' => true") === false) {
        continue;
    }
    // Einzige zugelassene Ausnahme (seit 4.58): die Bestaetigungsseite der abgeschlossenen Bestellung, und
    // dort ausschliesslich an tracking_conversion_page() gebunden. Eine bedingungslose Freigabe bleibt ein Fehler.
    $erlaubt = $name === TRACKING_CONVERSION_PAGE
        && substr_count($src, "'tracking' => true") === 1
        && strpos($src, "tracking_conversion_page() ? ['tracking' => true] : []") !== false;
    if (!$erlaubt) {
        $verdacht[] = $name;
    }
}
$verdacht === []
    ? $ok('keine weitere Seite bindet ein Google-Skript ein')
    : $bad('unerwartete Einbindung in: ' . implode(', ', $verdacht));

echo "\nF) Abgeschlossene Bestellung: die einzige Ausnahme im angemeldeten Bereich (seit 4.58)\n";
$AK = ['enabled' => true, 'ga_id' => 'G-8C1W9817PV', 'ads_id' => 'AW-18431688840', 'ads_conversion_label' => 'AbCdEfGhIjK'];
$mitGet = static function (array $get, callable $f) {
    $alt = $_GET;
    $_GET = $get;
    try { return $f(); } finally { $_GET = $alt; }
};

$setze($AK);
$mitGet([], static fn(): bool => tracking_allowed_for('/subscription.php'))
    ? $bad('subscription.php ohne Parameter darf kein Tag einbinden')
    : $ok('subscription.php ohne Parameter ohne Tag');
$mitGet(['bestellt' => '1'], static fn(): bool => tracking_allowed_for('/subscription.php'))
    ? $ok('abgeschlossene Bestellung darf das Tag einbinden')
    : $bad('abgeschlossene Bestellung bindet kein Tag ein');
$mitGet(['bestellt' => '1', 'id' => 'abc'], static fn(): bool => tracking_allowed_for('/subscription.php'))
    ? $bad('zusaetzlicher Parameter darf die Ausnahme nicht oeffnen')
    : $ok('zusaetzlicher Parameter in der Adresse: kein Tag');
$mitGet(['bestellt' => '2'], static fn(): bool => tracking_allowed_for('/subscription.php'))
    ? $bad('anderer Wert darf die Ausnahme nicht oeffnen')
    : $ok('anderer Wert des Parameters: kein Tag');
foreach (['dashboard.php', 'customer.php', 'invoices.php', 'settings.php', 'admin.php'] as $seite) {
    $mitGet(['bestellt' => '1'], static fn(): bool => tracking_allowed_for('/' . $seite))
        ? $bad("$seite darf die Ausnahme nicht nutzen")
        : $ok("$seite auch mit bestellt=1 ohne Tag");
}
// Nur mit Ads-Kennung: eine reine Reichweitenmessung rechtfertigt die Ausnahme nicht.
$setze(['enabled' => true, 'ga_id' => 'G-8C1W9817PV', 'ads_id' => '', 'ads_conversion_label' => 'AbCdEfGhIjK']);
$mitGet(['bestellt' => '1'], static fn(): bool => tracking_allowed_for('/subscription.php'))
    ? $bad('ohne Ads-Kennung darf die Bestellseite kein Tag laden')
    : $ok('ohne Ads-Kennung bleibt die Bestellseite ohne Tag');

$setze($AK);
$htmlK = $mitGet(['bestellt' => '1'], static fn(): string => tracking_head_html('/subscription.php'));
(strpos($htmlK, 'data-ga=""') !== false)
    ? $ok('auf der Bestellseite keine Reichweitenmessung (data-ga leer)')
    : $bad('auf der Bestellseite wird auch die Analytics-Kennung uebergeben');
(strpos($htmlK, 'data-conversion-label="AbCdEfGhIjK"') !== false)
    ? $ok('Conversion-Label wird uebergeben')
    : $bad('Conversion-Label fehlt im HTML');
(strpos($htmlK, 'googletagmanager') === false)
    ? $ok('auch hier kein Google-Aufruf im HTML')
    : $bad('das HTML der Bestellseite enthaelt einen Google-Aufruf');
$setze(['enabled' => true, 'ga_id' => '', 'ads_id' => 'AW-18431688840', 'ads_conversion_label' => 'zu kurz!']);
(tracking_conversion_label() === '')
    ? $ok('fehlerhaftes Conversion-Label wird verworfen')
    : $bad('fehlerhaftes Conversion-Label wird uebernommen');
$setze(['enabled' => true, 'ga_id' => '', 'ads_id' => 'AW-18431688840']);
$htmlOhne = $mitGet(['bestellt' => '1'], static fn(): string => tracking_head_html('/subscription.php'));
(tracking_conversion_label() === '' && strpos($htmlOhne, 'data-conversion-label') === false)
    ? $ok('ohne Label wird keines erfunden')
    : $bad('ohne Label taucht trotzdem ein Attribut auf');

$js2 = (string)file_get_contents($root . '/php-ionos/assets/js/consent.js');
(strpos($js2, 'function reportConversion') !== false && strpos($js2, 'conversionSent') !== false)
    ? $ok('consent.js meldet die Conversion genau einmal')
    : $bad('keine Conversion-Meldung in consent.js');
(strpos($js2, '!conversionLabel') !== false && strpos($js2, "querySelector('[data-conversion]')") !== false)
    ? $ok('Meldung nur mit Label und gekennzeichneter Bestellung')
    : $bad('Meldung ohne Bedingungen');
(substr_count($js2, 'reportConversion();') >= 2 && strpos($js2, "saveState('all'); wrap.remove(); loadTag(); reportConversion();") !== false)
    ? $ok('Meldung erst nach Zustimmung, auch bei frischer Einwilligung')
    : $bad('Meldung nicht an die Zustimmung gebunden');

$sub = (string)file_get_contents($root . '/php-ionos/subscription.php');
(strpos($sub, "redirect('subscription.php?bestellt=1')") !== false)
    ? $ok('Rueckkehr aus dem Checkout behaelt die Kennzeichnung')
    : $bad('Kennzeichnung geht bei der Weiterleitung verloren');
(strpos($sub, "tracking_conversion_page() ? ['tracking' => true] : []") !== false)
    ? $ok('Tag nur auf der Bestaetigungsseite freigegeben')
    : $bad('Freigabe des Tags auf subscription.php nicht begrenzt');
(strpos($sub, 'data-conversion') !== false && preg_match('/data-conversion-id="[^"]*hash\(/', $sub) === 1)
    ? $ok('Bestellung gekennzeichnet, Vorgangskennung gehasht')
    : $bad('Bestellung nicht oder mit Klartextkennung gekennzeichnet');
(strpos($sub, 'data-conversion-value=') !== false && strpos($sub, "data-conversion-currency=\"EUR\"") !== false)
    ? $ok('Nettobetrag und Waehrung werden mitgegeben')
    : $bad('Betrag oder Waehrung fehlen');

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
