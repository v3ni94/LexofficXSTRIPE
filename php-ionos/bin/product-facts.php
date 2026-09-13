<?php
/**
 * Produktfakten-Register anzeigen, Snapshot exportieren oder pruefen (Version 4.79).
 *
 *   php bin/product-facts.php              Register mit Status anzeigen (intern, mit Quellen)
 *   php bin/product-facts.php --export     Snapshot nach docs/contracts/product-facts.snapshot.json schreiben
 *   php bin/product-facts.php --check      Snapshot mit dem Register vergleichen (Exit 1 bei Abweichung)
 *
 * Ohne Datenbank und ohne Konfiguration lauffaehig (laedt nur app/product_facts.php). Zielpfad ist das Repository
 * (dirname(__DIR__, 2)); auf dem Server ohne docs/-Ordner --export=PFAD verwenden.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur in der Kommandozeile.');
}
require_once dirname(__DIR__) . '/app/product_facts.php';

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
        $opts[$k] = $v;
    }
}
$default = dirname(__DIR__, 2) . '/docs/contracts/product-facts.snapshot.json';
$json = product_facts_snapshot_json(product_facts_snapshot());

if (isset($opts['export'])) {
    $path = is_string($opts['export']) ? $opts['export'] : $default;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, $json);
    echo "Snapshot geschrieben: $path (" . count(product_facts_public()) . " oeffentliche Aussagen)\n";
    exit(0);
}
if (isset($opts['check'])) {
    $path = is_string($opts['check']) ? $opts['check'] : $default;
    $alt = is_file($path) ? (string)file_get_contents($path) : '';
    if ($alt === $json) {
        echo "Snapshot aktuell: $path\n";
        exit(0);
    }
    echo "Snapshot veraltet oder fehlt: $path. Bitte php bin/product-facts.php --export ausfuehren.\n";
    exit(1);
}

$zaehler = [];
foreach (product_facts() as $f) {
    $zaehler[$f['status']] = ($zaehler[$f['status']] ?? 0) + 1;
    $wert = is_array($f['wert']) ? implode(', ', $f['wert']) : $f['wert'];
    printf("%-42s %-22s %-6s %s\n", $f['key'], $f['status'], $f['veroeffentlichen'] ? 'public' : 'intern', mb_strimwidth($wert, 0, 90, '…'));
    printf("%-42s Quelle: %s%s\n", '', implode('; ', $f['quelle']), $f['hinweis'] !== '' ? ' | ' . $f['hinweis'] : '');
}
echo "\nRegister " . PRODUCT_FACTS_VERSION . ", geprueft " . PRODUCT_FACTS_CHECKED . ": ";
foreach ($zaehler as $s => $n) {
    echo "$s=$n ";
}
echo "| oeffentlich: " . count(product_facts_public()) . "\n";
