<?php
/**
 * Regressionstest des rollierenden Stichtags des Einführungspreises (php-ionos/app/pricing.php) und der
 * Preisangaben auf den Marketingseiten. Ohne Datenbank, ohne Netz.
 *
 * Hintergrund: Der Einführungspreis galt laut Text "bis 31.12.2026". Ein festes Datum muss von Hand
 * gepflegt werden und ist nach Ablauf eine falsche Preisangabe. Er gilt jetzt bis zum Ende des laufenden
 * Kalendermonats: Die Anwendung berechnet den Tag (Hilfe-Center), die statischen Seiten nennen die
 * gleichlautende Formulierung ohne Datum. Dieser Test hält beides zusammen und verhindert, dass wieder
 * ein festes Datum in eine Preisangabe gerät.
 *
 * Aufruf: php tools/pricing-check.php     Exit 0 = alle Fälle bestanden
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/php-ionos/app/pricing.php';

$pass = 0;
$fail = 0;
$ok = static function (string $t) use (&$pass): void { $pass++; echo "  OK    $t\n"; };
$bad = static function (string $t) use (&$fail): void { $fail++; echo "  FAIL  $t\n"; };

echo "A) Stichtag ist der letzte Tag des laufenden Monats\n";
$faelle = [
    '2026-09-07' => '30.09.2026',
    '2026-09-30' => '30.09.2026',
    '2026-10-01' => '31.10.2026',   // Monatswechsel: rollt von selbst weiter
    '2026-10-31' => '31.10.2026',
    '2026-12-31' => '31.12.2026',
    '2027-01-01' => '31.01.2027',   // Jahreswechsel
    '2027-02-15' => '28.02.2027',
    '2028-02-01' => '29.02.2028',   // Schaltjahr
];
foreach ($faelle as $heute => $erwartet) {
    $ist = intro_price_deadline(new DateTimeImmutable($heute));
    $ist === $erwartet ? $ok("$heute -> $erwartet") : $bad("$heute -> $ist, erwartet $erwartet");
}
$satz = intro_price_deadline_sentence(new DateTimeImmutable('2026-09-07'));
str_contains($satz, '30.09.2026') && str_contains($satz, 'Ende des laufenden Kalendermonats')
    ? $ok('Standardsatz nennt Tag und Regel') : $bad('Standardsatz unvollständig: ' . $satz);
intro_price_deadline() === (new DateTimeImmutable('now'))->modify('last day of this month')->format('d.m.Y')
    ? $ok('ohne Argument gilt der heutige Monat (Zeitzone der Anwendung)') : $bad('Vorgabewert falsch');

echo "\nB) Kein festes Datum mehr in Preisangaben\n";
$dateien = [];
foreach (['websites', 'php-ionos'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = (string)$f;
        // Erzeugte Dokumentation ausnehmen: Sie enthaelt historische Zitate mit dem alten Stichtag.
        if (preg_match('/\.(html|php)$/', $p) && !str_contains($p, '/docs-build/')) {
            $dateien[] = $p;
        }
    }
}
$verstoss = [];
foreach ($dateien as $p) {
    $t = (string)file_get_contents($p);
    // Verboten: "bis (zum) TT.MM.JJJJ" in einer Preis-/Registrierungsaussage (festes Ablaufdatum).
    if (preg_match_all('/bis\s+(?:zum\s+)?\d{2}\.\d{2}\.\d{4}\s+(?:angelegt|registriert)/u', $t, $m)) {
        $verstoss[] = basename(dirname($p)) . '/' . basename($p) . ': ' . implode(', ', $m[0]);
    }
}
$verstoss ? $bad('festes Ablaufdatum gefunden: ' . implode(' | ', $verstoss))
          : $ok(count($dateien) . ' HTML-/PHP-Dateien ohne festes Ablaufdatum in Preisangaben');

echo "\nC) Marketingseiten und Anwendung sagen dasselbe\n";
$mitPreis = $mitRegel = 0;
foreach ($dateien as $p) {
    if (!str_contains($p, '/websites/')) {
        continue;
    }
    $t = (string)file_get_contents($p);
    if (str_contains($t, 'Einführungspreis') && (str_contains($t, 'angelegt werden') || str_contains($t, 'registriert werden'))) {
        $mitPreis++;
        if (str_contains($t, 'Ende des laufenden Kalendermonats')) {
            $mitRegel++;
        } else {
            echo "        ohne Regel: " . str_replace($root . '/', '', $p) . "\n";
        }
    }
}
$mitPreis > 0 && $mitPreis === $mitRegel
    ? $ok("$mitRegel von $mitPreis Seiten mit Einführungspreis nennen die Regel wörtlich")
    : $bad("$mitRegel von $mitPreis Seiten nennen die Regel");
$help = (string)file_get_contents($root . '/php-ionos/app/help_content.php');
str_contains($help, 'intro_price_deadline()') && str_contains($help, 'Ende des laufenden Kalendermonats')
    ? $ok('Hilfe-Center berechnet den Tag und nennt die Regel') : $bad('Hilfe-Center nennt Tag oder Regel nicht');

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
