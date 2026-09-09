<?php
/**
 * Zeitraumsteuerung des Adminbereichs (app/admin_period.php) ohne Datenbank pruefen: Voreinstellungen, freier Bereich,
 * Rueckfall bei Unsinn, Obergrenze, Aufloesung, Zeitfaecher passend zu den SQL-Buckets, Vergleichstext, Auswahlleiste,
 * Einbindung in admin.php und admin-system.php. Aufruf: php tools/admin-period-check.php   (Exit 0 = gruen)
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');
$root = dirname(__DIR__);
require $root . '/php-ionos/app/admin_period.php';
if (!function_exists('e')) { function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
$pass = 0; $fail = 0;
$ok = static function (string $n, bool $c, string $extra = '') use (&$pass, &$fail): void { $c ? $pass++ : $fail++; echo ($c ? '  OK    ' : '  FAIL  ') . $n . ($c ? '' : ' ' . $extra) . "\n"; };
$now = new DateTimeImmutable('2026-09-08 14:30:00');
$p = static fn(array $get, string $def = '30t') => admin_period_from_request($get, $def, $now);
$r = static fn(array $x): string => $x['from']->format('Y-m-d') . '..' . $x['to']->format('Y-m-d');

echo "1) Voreinstellungen\n";
$ok('heute', $r($p(['zeitraum' => 'heute'])) === '2026-09-08..2026-09-09', $r($p(['zeitraum' => 'heute'])));
$ok('gestern', $r($p(['zeitraum' => 'gestern'])) === '2026-09-07..2026-09-08');
$x = $p(['zeitraum' => '7t']); $ok('7 Tage: 7 Tage bis einschliesslich heute', $r($x) === '2026-09-02..2026-09-09' && $x['days'] === 7, $r($x));
$ok('30 Tage', $p(['zeitraum' => '30t'])['days'] === 30);
$ok('dieser Monat', $r($p(['zeitraum' => 'monat'])) === '2026-09-01..2026-10-01');
$ok('letzter Monat', $r($p(['zeitraum' => 'vormonat'])) === '2026-08-01..2026-09-01');
$ok('Quartal (Q3)', $r($p(['zeitraum' => 'quartal'])) === '2026-07-01..2026-10-01');
$ok('Jahr', $r($p(['zeitraum' => 'jahr'])) === '2026-01-01..2027-01-01');
$ok('12 Monate', $r($p(['zeitraum' => '12m'])) === '2025-10-01..2026-10-01');
$ok('Vorgabe ohne Angabe', $p([])['key'] === '30t');
$ok('unbekannte Kennung -> Vorgabe', $p(['zeitraum' => 'x'])['key'] === '30t');

echo "2) Freier Bereich\n";
$x = $p(['von' => '2026-08-01', 'bis' => '2026-08-15']);
$ok('von/bis einschliesslich', $x['key'] === 'frei' && $r($x) === '2026-08-01..2026-08-16' && $x['days'] === 15, $r($x));
$ok('Beschriftung', $x['label'] === '01.08.2026 bis 15.08.2026', $x['label']);
$ok('deutsches Datumsformat', $r($p(['von' => '01.08.2026', 'bis' => '15.08.2026'])) === '2026-08-01..2026-08-16');
$ok('Vorzeitraum gleich lang, endet am Beginn', $x['prev_from']->format('Y-m-d') === '2026-07-17' && $x['prev_to']->format('Y-m-d') === '2026-08-01');
$ok('bis vor von -> Rueckfall 30 Tage', $p(['von' => '2026-08-15', 'bis' => '2026-08-01'])['key'] === '30t');
$ok('Unsinn -> Rueckfall', $p(['zeitraum' => 'frei', 'von' => 'abc', 'bis' => '2026-08-01'])['key'] === '30t');
$ok('ungueltiges Datum 31.02. -> Rueckfall', $p(['von' => '2026-02-31', 'bis' => '2026-03-01'])['key'] === '30t');
$x = $p(['von' => '2020-01-01', 'bis' => '2026-09-08']);
$ok('Obergrenze drei Jahre', $x['days'] === ADMIN_PERIOD_MAX_DAYS, (string)$x['days']);
$ok('Query fuer Links', $p(['von' => '2026-08-01', 'bis' => '2026-08-15'])['query'] === 'zeitraum=frei&von=2026-08-01&bis=2026-08-15');

echo "3) Aufloesung und Zeitfaecher\n";
$ok('bis 31 Tage je Tag', $p(['zeitraum' => '30t'])['resolution'] === 'day');
$ok('90 Tage je Woche', $p(['zeitraum' => '90t'])['resolution'] === 'week');
$ok('Jahr je Monat', $p(['zeitraum' => 'jahr'])['resolution'] === 'month');
$s = admin_period_slots($p(['zeitraum' => '30t']));
$ok('30 Tagesfaecher, erstes 10.08.', count($s) === 30 && array_key_first($s) === '2026-08-10' && $s['2026-08-10'] === '10.08.', (string)count($s) . ' ' . array_key_first($s));
$s = admin_period_slots($p(['zeitraum' => 'jahr']));
$ok('12 Monatsfaecher', count($s) === 12 && array_key_first($s) === '2026-01' && $s['2026-01'] === 'Jan 26');
$s = admin_period_slots($p(['zeitraum' => '90t']));
$ok('Wochenfaecher decken den Zeitraum (13 oder 14)', in_array(count($s), [13, 14], true) && str_starts_with((string)array_key_first($s), '2026-W'), (string)count($s));
// Bucket-Ausdruecke muessen dieselben Schluessel liefern wie die Faecher (PHP gegen MariaDB-Formate)
$ok('Tages-Bucket', admin_period_sql_bucket('c', 'day') === "DATE_FORMAT(c, '%Y-%m-%d')");
$ok('Wochen-Bucket ISO-Jahr und -Woche', admin_period_sql_bucket('c', 'week') === "DATE_FORMAT(c, '%x-W%v')");
$ok('Monats-Bucket', admin_period_sql_bucket('c', 'month') === "DATE_FORMAT(c, '%Y-%m')");
$d = new DateTimeImmutable('2026-01-01'); // Donnerstag, ISO-Woche 1 von 2026
$ok('PHP-Wochenschluessel entspricht ISO (2026-W01 fuer 01.01.2026)', $d->format('o-\WW') === '2026-W01', $d->format('o-\WW'));
$d = new DateTimeImmutable('2024-12-30'); // Montag, ISO-Woche 1 von 2025
$ok('Jahreswechsel: 30.12.2024 liegt in 2025-W01', $d->format('o-\WW') === '2025-W01', $d->format('o-\WW'));

echo "4) Vergleich und Auswahlleiste\n";
$ok('Vergleich +', admin_period_compare(120, 100) === '+20,0 % zum Vorzeitraum', admin_period_compare(120, 100));
$ok('Vergleich -', admin_period_compare(80, 100) === '-20,0 % zum Vorzeitraum');
$ok('Vergleich neu', admin_period_compare(5, 0) === 'neu (Vorzeitraum 0)');
$ok('Vergleich unveraendert', admin_period_compare(0, 0) === 'unverändert');
$html = admin_period_selector('admin-system.php?tab=performance', $p(['zeitraum' => '7t']));
$ok('Leiste: aktiver Eintrag', str_contains($html, 'zeitraum=7t" class="active"'));
$ok('Leiste: Formular behaelt tab', str_contains($html, 'name="tab" value="performance"') && str_contains($html, 'action="admin-system.php"'));
$ok('Leiste: Datumsfelder mit Vorbelegung', str_contains($html, 'name="von" value="2026-09-02"') && str_contains($html, 'name="bis" value="2026-09-08"'));
$ok('Leiste: keine Gedankenstriche', !str_contains($html, '—') && !str_contains($html, '–'));

echo "5) Einbindung\n";
$adm = file_get_contents($root . '/php-ionos/admin.php'); $sys = file_get_contents($root . '/php-ionos/admin-system.php'); $perf = file_get_contents($root . '/php-ionos/app/sync_perf.php');
$ok('admin.php nutzt den Zeitraum fuer Kennzahlen, Funnel und Diagramme', str_contains($adm, 'admin_period_from_request($_GET') && substr_count($adm, 'created_at >= ? AND created_at < ?') >= 3 && str_contains($adm, 'admin_period_slots($period)'));
$ok('admin.php: Vergleich zum Vorzeitraum', substr_count($adm, 'admin_period_compare(') === 3);
$ok('admin.php: keine feste 12-Wochen-Sicht mehr', !str_contains($adm, 'chart_week_slots(12)') && !str_contains($adm, 'INTERVAL 13 WEEK'));
$ok('admin-system.php: Verfuegbarkeit und Performance mit Zeitraum', str_contains($sys, "admin_period_selector('admin-system.php?tab=verfuegbarkeit'") && str_contains($sys, 'sync_perf_render($period)'));
$ok('sync_perf: Zeitraum und Vorzeitraum statt fester 24 h / 7 Tage', str_contains($perf, "sync_perf_overview(\$period['from'], \$period['to'])") && str_contains($perf, "sync_perf_overview(\$period['prev_from'], \$period['prev_to'])") && !str_contains($perf, 'DATE_SUB(NOW(), INTERVAL ? HOUR)'));
echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
