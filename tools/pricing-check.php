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
 * Abschnitt D (Fassung seit 13.09.2026): Die Marketingseiten DÜRFEN den Produktpreis nennen; die Vorgabe vom
 * 07.09.2026, keine Beträge auszuspielen, hat der Betreiber am 13.09.2026 aufgehoben. Geprüft wird deshalb nicht
 * mehr das Vorhandensein, sondern die Richtigkeit: Genannt werden darf ausschließlich der gültige Betrag, immer
 * mit Steuerhinweis und Periode. Der frühere Vergleichspreis (50,00 EUR, „bisher") bleibt gesperrt, solange seine
 * wettbewerbsrechtliche Zulässigkeit nicht geklärt ist (Faktenregister TARIF-07).
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
// Nennt eine Seite den Einführungspreis, muss sie die rollierende Regel wörtlich tragen. Ein fester Stichtag im
// Text würde veralten, ohne dass es jemandem auffällt; die Regel verschiebt sich von selbst.
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
$mitPreis === $mitRegel
    ? $ok($mitPreis === 0 ? 'keine Marketingseite nennt den Einführungspreis' : "$mitRegel von $mitPreis Seiten mit Einführungspreis nennen die Regel wörtlich")
    : $bad("$mitRegel von $mitPreis Seiten nennen die Regel");
$help = (string)file_get_contents($root . '/php-ionos/app/help_content.php');
str_contains($help, 'intro_price_deadline()') && str_contains($help, 'Ende des laufenden Kalendermonats')
    ? $ok('Hilfe-Center berechnet den Tag und nennt die Regel') : $bad('Hilfe-Center nennt Tag oder Regel nicht');

echo "\nD) Preisangaben auf den Marketingseiten sind richtig und vollstaendig (Fassung seit 13.09.2026)\n";
// Der Betreiber hat am 13.09.2026 entschieden, den Preis oeffentlich zu nennen. Geprueft wird deshalb die
// Richtigkeit statt des Verbots. Drei Dinge muessen zusammen stehen, sonst ist die Angabe irrefuehrend:
// der gueltige Betrag, der Steuerhinweis (netto zzgl. USt) und die Periode (vier Wochen).
// Gesperrt bleibt der Vergleichspreis ("bisher 50,00 EUR"): Seine wettbewerbsrechtliche Zulaessigkeit ist
// ungeklaert (Faktenregister TARIF-07), und ein durchgestrichener Preis ohne Nachweis ist angreifbar.
// AGB-Seiten sind Vertragstexte und werden nur gemeldet, nicht bewertet.
$gueltigerBetrag = '25,00';

// Wichtig: Die Seiten enthalten Rechnungsbeispiele (890,00 EUR offene Posten und aehnlich). Diese Betraege
// sind legitim und duerfen nicht als Produktpreis bewertet werden. Geprueft wird deshalb gezielt:
//   1. Steht der gueltige Betrag auf einer Seite, muessen Steuerhinweis und Periode dort ebenfalls stehen.
//   2. Ein Vergleichs- oder Streichpreis ist gesperrt, egal in welcher Hoehe (TARIF-07 ungeklaert).
$fehler = [];
$agbHinweise = [];
$seitenMitPreis = 0;
foreach ($dateien as $p2) {
    if (!str_contains($p2, '/websites/') || !str_ends_with($p2, '.html')) {
        continue;
    }
    $t = (string)file_get_contents($p2);
    $rel = str_replace($root . '/websites/', '', $p2);
    $istAgb = (bool)preg_match('#(^|/)agb(/index)?\.html$#', $rel);

    // 2. Vergleichs- und Streichpreise: "bisher/statt/vorher/regulaer 50,00 EUR" oder durchgestrichener Betrag.
    $vergleich = [];
    if (preg_match_all('/(?:bisher|statt|vorher|regulär|anstatt|zuvor)\s*(?:nur\s*)?[^<>]{0,15}?\d{1,3}(?:,\d{2})?\s?(?:EUR|Euro|€)/ui', $t, $m)) {
        $vergleich = array_merge($vergleich, array_unique($m[0]));
    }
    if (preg_match_all('/<(?:s|del|strike)\b[^>]*>[^<]*(?:EUR|Euro|€)[^<]*<\/(?:s|del|strike)>/ui', $t, $m)) {
        $vergleich = array_merge($vergleich, array_unique($m[0]));
    }
    if (preg_match_all('/line-through[^>]*>[^<]*(?:EUR|Euro|€)/ui', $t, $m)) {
        $vergleich = array_merge($vergleich, array_unique($m[0]));
    }
    if ($vergleich && !$istAgb) {
        $fehler[] = "$rel: Vergleichs- oder Streichpreis gesperrt (" . implode(', ', array_slice($vergleich, 0, 2)) . ')';
    }

    // 1. Gueltiger Betrag: nur zusammen mit Steuerhinweis und Periode zulaessig.
    if (!preg_match('/(?<![\d.,])' . preg_quote($gueltigerBetrag, '/') . '\s?(?:EUR|Euro|€)/u', $t)) {
        continue;
    }
    $seitenMitPreis++;
    if ($istAgb) {
        $agbHinweise[] = $rel;
        continue;
    }
    if (!preg_match('/(netto|zzgl\.|zuzüglich)/u', $t)) {
        $fehler[] = "$rel: Betrag ohne Steuerhinweis (netto, zzgl. oder zuzueglich fehlt)";
    }
    if (!preg_match('/(vier Wochen|4 Wochen|28 Tage)/u', $t)) {
        $fehler[] = "$rel: Betrag ohne Angabe der Periode (vier Wochen)";
    }
}
$fehler ? $bad('fehlerhafte Preisangaben: ' . implode(' | ', $fehler))
        : $ok($seitenMitPreis === 0
            ? 'keine Marketingseite nennt den Produktpreis'
            : "$seitenMitPreis Seite(n) nennen den Produktpreis, alle mit Steuerhinweis und Periode; kein Vergleichspreis");
foreach ($agbHinweise as $h) {
    echo "  HINWEIS AGB (Vertragstext, Entscheidung Geschaeftsfuehrung/Anwalt ausstehend): $h\n";
}

// Die Preisseite muss den Betrag tatsaechlich nennen: Eine Preisseite ohne Preis war der Anlass der
// Entscheidung vom 13.09.2026 (externe Durchsicht: die Kaufentscheidung blieb unbeantwortet).
$preisseite = $root . '/websites/smart-einzug.de/preise/index.html';
$pt = is_file($preisseite) ? (string)file_get_contents($preisseite) : '';
str_contains($pt, $gueltigerBetrag . ' EUR')
    ? $ok('Preisseite nennt den Betrag')
    : $bad('Preisseite nennt keinen Betrag');

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
