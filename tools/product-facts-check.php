<?php
/**
 * Pruefung der Produktfaktenquelle (php-ionos/app/product_facts.php), des Snapshots (docs/contracts/product-facts.snapshot.json)
 * und des oeffentlichen Endpunkts (php-ionos/fakten.php). Ohne Datenbank, ohne Netz.
 *
 *  A) Register: eindeutige Schluessel, zulaessige Zustaende, Quelle je Aussage, Pruefdatum TT.MM.JJJJ oder null bei ungeklaert
 *  B) Snapshot: aktuell (entspricht dem Register), nur freigegebene Zustaende, keine Quellen, keine Geheimnismuster,
 *     keine Serverpfade, kein Preis als 0 oder kostenlos, keine Preisbetraege solange die Veroeffentlichung gesperrt ist
 *  C) Konsistenz: Tarifwerte gegen die Tabelle plans (schema.sql), Stichtag gegen pricing.php, Webhook-Ereignisse gegen
 *     stripe-webhook.php und BILLING/Einzugs-Endpunkt
 *  D) Endpunkt fakten.php: nur GET, Cache-Control public, X-Robots-Tag noindex, ohne Sitzung, ohne Tracking, ohne Stripe-Client
 *
 * Aufruf: php tools/product-facts-check.php     Exit 0 = alle Faelle bestanden
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/php-ionos/app/product_facts.php';

$pass = 0;
$fail = 0;
$ok = static function (string $t) use (&$pass): void { $pass++; echo "  OK    $t\n"; };
$bad = static function (string $t) use (&$fail): void { $fail++; echo "  FAIL  $t\n"; };

echo "A) Register\n";
$facts = product_facts();
$keys = array_column($facts, 'key');
count($keys) === count(array_unique($keys)) ? $ok('Schluessel eindeutig (' . count($keys) . ' Aussagen)') : $bad('doppelte Schluessel: ' . implode(', ', array_diff_assoc($keys, array_unique($keys))));
$fehler = [];
foreach ($facts as $f) {
    if (!in_array($f['status'], PRODUCT_FACT_STATES, true)) { $fehler[] = $f['key'] . ': Status ' . $f['status']; }
    if (!$f['quelle']) { $fehler[] = $f['key'] . ': keine Quelle'; }
    if ($f['geprueft_am'] !== null && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $f['geprueft_am'])) { $fehler[] = $f['key'] . ': Datum ' . $f['geprueft_am']; }
    if ($f['status'] === 'ungeklaert' && $f['veroeffentlichen']) { $fehler[] = $f['key'] . ': ungeklaert darf nicht veroeffentlicht werden'; }
    if ($f['status'] === 'technisch_getestet' && $f['geprueft_am'] === null) { $fehler[] = $f['key'] . ': technisch_getestet ohne Pruefdatum'; }
    if (!preg_match('/^[a-z_]+\.[a-z0-9_.]+$/', $f['key'])) { $fehler[] = $f['key'] . ': Schluesselformat'; }
}
$fehler ? $bad(implode(' | ', $fehler)) : $ok('Zustaende, Quellen, Pruefdaten und Schluesselformat gueltig');
in_array('ungeklaert', array_column($facts, 'status'), true) ? $ok('Register kennt ungeklaerte Aussagen (ehrliche Kennzeichnung)') : $ok('keine ungeklaerten Aussagen');

echo "\nB) Snapshot\n";
$snapPath = $root . '/docs/contracts/product-facts.snapshot.json';
$json = product_facts_snapshot_json(product_facts_snapshot());
is_file($snapPath) ? $ok('Snapshot vorhanden') : $bad('Snapshot fehlt: php php-ionos/bin/product-facts.php --export');
(is_file($snapPath) && (string)file_get_contents($snapPath) === $json) ? $ok('Snapshot entspricht dem Register') : $bad('Snapshot veraltet: php php-ionos/bin/product-facts.php --export');
$snap = json_decode($json, true);
($snap['schema'] ?? '') === PRODUCT_FACTS_SCHEMA && ($snap['version'] ?? '') === PRODUCT_FACTS_VERSION ? $ok('Schema und Version gesetzt') : $bad('Schema oder Version fehlt');
$public = $snap['facts'] ?? [];
count($public) > 0 ? $ok(count($public) . ' oeffentliche Aussagen') : $bad('keine oeffentlichen Aussagen');
$verboten = [];
foreach ($public as $p) {
    if (!in_array($p['status'], PRODUCT_FACT_PUBLIC_STATES, true)) { $verboten[] = $p['key'] . ' Status'; }
    if (isset($p['quelle']) || isset($p['hinweis'])) { $verboten[] = $p['key'] . ' Quelle/Hinweis im Snapshot'; }
}
$verboten ? $bad(implode(', ', $verboten)) : $ok('nur freigegebene Zustaende, keine Quellen oder Hinweise im Snapshot');
$flat = mb_strtolower($json);
$geheim = ['sk_live', 'sk_test', 'rk_live', 'rk_test', 'whsec_', 'akia', 'passwort', 'password', 'bearer ', '/opt/smarteinzug', 'shared/config.php', 'mysql://', '@smtp'];
$treffer = array_values(array_filter($geheim, static fn($g) => str_contains($flat, $g)));
$treffer ? $bad('Geheimnis- oder Pfadmuster im Snapshot: ' . implode(', ', $treffer)) : $ok('keine Geheimnis-, Zugangs- oder Serverpfadmuster');
preg_match('/\b0,00\s*eur|kostenlos|gratis|0 euro/u', $flat) ? $bad('Preis als 0 oder kostenlos ausgegeben') : $ok('kein Preis als 0 EUR oder kostenlos');
// Produktpreis oeffentlich (Vorgabe 13.09.2026): Betrag, Steuerhinweis und Periode zusammen; kein Vergleichs- oder Streichpreis
$preisPublic = array_values(array_filter($public, static fn($p) => $p['key'] === 'tarif.preis'));
$pw = mb_strtolower((string)($preisPublic[0]['wert'] ?? ''));
($preisPublic && str_contains($pw, '25,00 eur') && preg_match('/netto|zzgl|zuzüglich/u', $pw) && preg_match('/vier wochen|28 tage/u', $pw))
    ? $ok('tarif.preis oeffentlich mit Betrag, Steuerhinweis und Periode (Vorgabe 13.09.2026)') : $bad('tarif.preis fehlt oeffentlich oder ohne Betrag, Steuerhinweis oder Periode');
preg_match('/(bisher|statt|vorher|regulär|anstatt|zuvor)\s*(nur\s*)?[^"]{0,15}?\d{1,3}(,\d{2})?\s?(eur|euro|€)/u', $flat) ? $bad('Vergleichs- oder Streichpreis im oeffentlichen Snapshot (TARIF-07 gesperrt)') : $ok('kein Vergleichs- oder Streichpreis im oeffentlichen Snapshot');
preg_match_all('/\d{1,3},\d{2}\s*eur/u', $flat, $mm); (array_values(array_unique($mm[0])) === ['25,00 eur']) ? $ok('einziger oeffentlicher Betrag ist 25,00 EUR') : $bad('abweichende Betraege im Snapshot: ' . implode(', ', array_unique($mm[0])));

echo "\nC) Konsistenz mit Code\n";
$schema = (string)file_get_contents($root . '/php-ionos/sql/schema.sql');
preg_match("/\('unlimited_start',\s*'([^']+)',\s*(\d+),\s*(\d+),\s*([^,]+),\s*([^,]+),\s*(\d)/", $schema, $m) ? $ok('plans-Seed gelesen') : $bad('plans-Seed nicht gefunden');
$name = $m[1] ?? ''; $cents = (int)($m[2] ?? 0); $days = (int)($m[3] ?? 0); $maxColl = trim($m[4] ?? ''); $unlimited = (int)($m[6] ?? 0);
$by = [];
foreach ($facts as $f) { $by[$f['key']] = $f; }
($by['tarif.name']['wert'] ?? '') === $name ? $ok("tarif.name = $name") : $bad('tarif.name weicht vom plans-Seed ab');
str_contains((string)$by['tarif.preis']['wert'], number_format($cents / 100, 2, ',', '.') . ' EUR') ? $ok('tarif.preis entspricht plans.price_cents') : $bad('tarif.preis weicht von plans.price_cents ab');
(!str_contains(mb_strtolower((string)$by['tarif.preis']['wert']), '50,00') && !$by['tarif.vergleichspreis']['veroeffentlichen']) ? $ok('Vergleichspreis nur intern (tarif.vergleichspreis, ungeklaert)') : $bad('Vergleichspreis in tarif.preis oder veroeffentlicht');
(str_contains((string)$by['tarif.periode']['wert'], "$days Tage") && str_contains((string)$by['tarif.preis']['wert'], "($days Tage)")) ? $ok("Periode $days Tage") : $bad('Periode weicht von plans.period_days ab');
($maxColl === 'NULL' && $unlimited === 1) ? $ok('tarif.begrenzung: Seed ohne Grenzen') : $bad('plans-Seed hat Grenzen, tarif.begrenzung pruefen');
$by['tarif.stichtag']['wert'] === intro_price_deadline_sentence() ? $ok('tarif.stichtag aus pricing.php') : $bad('tarif.stichtag nicht aus pricing.php');
$hook = (string)file_get_contents($root . '/php-ionos/stripe-webhook.php');
$fehlend = array_values(array_filter((array)$by['zahlung.webhook_ereignisse']['wert'], static fn($e) => !str_contains($hook, "'$e'")));
$fehlend ? $bad('Webhook-Ereignisse nicht im Endpunkt: ' . implode(', ', $fehlend)) : $ok('Webhook-Ereignisse im Endpunkt vorhanden');
$coll = (string)file_get_contents($root . '/php-ionos/app/collections.php');
(str_contains($coll, "'sepa_debit'") && !preg_match('/mandate_options.*b2b|sepa_debit.*business/i', $coll)) ? $ok('Einzug ueber sepa_debit ohne B2B-Kennzeichen') : $bad('B2B-Kennzeichen oder fehlendes sepa_debit in collections.php');
str_contains($schema, 'require_signed_mandate') && preg_match('/require_signed_mandate\s+TINYINT\(1\)\s+NOT NULL DEFAULT 1/', $schema) ? $ok('mandat.nachweis: handschriftlicher Nachweis Vorgabe 1') : $bad('require_signed_mandate Vorgabe geaendert, mandat.nachweis pruefen');
preg_match('/send_pre_notification\s+TINYINT\(1\)\s+NOT NULL DEFAULT 0/', $schema) && preg_match('/pre_notification_days\s+INT\s+NOT NULL DEFAULT 14/', $schema) ? $ok('mandat.vorabankuendigung: Vorgaben aus 14 Tage') : $bad('Vorabankuendigungs-Vorgaben geaendert');
str_contains((string)file_get_contents($root . '/php-ionos/app/invoice_source_switch.php'), 'INVOICE_SOURCE_LOCK_DAYS = 28') ? $ok('Wechselsperre 28 Tage') : $bad('INVOICE_SOURCE_LOCK_DAYS geaendert');
str_contains($coll, "'sync_lookback_days' => max(1, min(400, (int)(\$c['sync_lookback_days'] ?? 70)))") ? $ok('Rueckschau 70 Tage Vorgabe') : $bad('sync_lookback_days Vorgabe geaendert, zahlung.ruecklastschrift pruefen');

echo "\nD) Endpunkt fakten.php\n";
$ep = (string)file_get_contents($root . '/php-ionos/fakten.php');
str_contains($ep, "define('SKIP_SESSION', true)") ? $ok('ohne Sitzung') : $bad('Sitzung nicht abgeschaltet');
str_contains($ep, 'product_facts_snapshot()') ? $ok('liefert den Snapshot aus dem Register') : $bad('Endpunkt nutzt das Register nicht');
str_contains($ep, "'GET'") && str_contains($ep, 'http_response_code(405)') ? $ok('nur GET, sonst 405') : $bad('Methodenpruefung fehlt');
str_contains($ep, 'Cache-Control: public, max-age=300') ? $ok('oeffentlich zwischenspeicherbar (300 s)') : $bad('Cache-Control fehlt');
str_contains($ep, 'X-Robots-Tag: noindex') ? $ok('nicht indexierbar') : $bad('X-Robots-Tag fehlt');
(!str_contains($ep, 'StripeClient') && !str_contains($ep, 'layout_header') && !str_contains($ep, 'tracking')) ? $ok('kein Stripe-Aufruf, kein Layout, kein Tracking') : $bad('Endpunkt bindet Stripe, Layout oder Tracking ein');
str_contains($ep, "config('signup_domains'") && str_contains($ep, "=== 'https'") ? $ok('CORS nur fuer eigene Domains ueber https') : $bad('CORS-Regel fehlt oder zu weit');
$boot = (string)file_get_contents($root . '/php-ionos/app/bootstrap.php');
!str_contains($boot, "'fakten.php'") ? $ok('fakten.php nicht in der Adminhost-Positivliste (auf dem Adminhost 404)') : $bad('fakten.php auf dem Adminhost erreichbar');
$tracking = (string)file_get_contents($root . '/php-ionos/app/tracking.php');
!str_contains($tracking, 'fakten.php') ? $ok('fakten.php nicht in TRACKING_PUBLIC_PAGES') : $bad('fakten.php in der Trackingliste');

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
