<?php
/**
 * Zentraler Test-Schutz (Audit 09.09.2026, Abschnitt 2D des Pruefauftrags).
 *
 * Jede Pruefsuite, die Anwendungscode mit Nebenwirkungen ausfuehrt (Datenbank, HTTP an Stubs, Mail), laedt
 * diese Datei direkt nach bin/_cli.php und VOR dem ersten Datenbank- oder Netzwerkzugriff. Der Schutz prueft
 * die geladene Konfiguration und bricht mit Exit 64 ab, sobald etwas auf Produktion oder auf echte externe
 * Konten hindeutet. Ein Datenbankname mit "test" allein genuegt nicht; verlangt werden mehrere unabhaengige
 * Merkmale zugleich.
 *
 * Regeln (alle muessen gelten):
 *  1. SMARTEINZUG_CONFIG ist gesetzt, zeigt auf eine bestehende Datei ausserhalb von /opt/smarteinzug und
 *     ausserhalb von php-ionos/app/ (die produktive shared/config.php und die lokale Entwicklerkonfiguration
 *     sind damit ausgeschlossen).
 *  2. Datenbank: Host 127.0.0.1, localhost oder ::1, Port ungleich 3306 (die Sandbox aus
 *     tools/lib/mariadb-sandbox.sh nutzt zufaellige Ports ab 23000) und ein Name, der "test" enthaelt.
 *  3. Kein Mailversand (mail.enabled leer) und kein Totmannschalter (monitoring.heartbeat_url leer).
 *  4. Plattform-Abrechnung aus (billing.enabled leer), kein Live-Schluessel (sk_live_, rk_live_) in der Konfiguration.
 *  5. Stripe erreicht nur den lokalen Stub: stripe_api_base_url MUSS gesetzt sein und mit http://127.0.0.1 beginnen.
 *     Lexware und sevdesk duerfen, wenn konfiguriert, ebenfalls nur 127.0.0.1 ansprechen.
 *  6. Redis, falls konfiguriert, nur lokal.
 *  7. Nach dem Verbinden (test_guard_assert_db) darf keine integrations-Zeile einen Live-Schluessel enthalten.
 *
 * Die Pruefung des Schutzes selbst: bash tools/test-guard-check.sh (synthetische "Live"-Konfigurationen, keine
 * echten Zugangsdaten).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur in der Kommandozeile.');
}

function test_guard_fail(string $reason): never
{
    fwrite(STDERR, "TEST-GUARD: Abbruch vor jeder Nebenwirkung: $reason\n");
    exit(64);
}

function test_guard_is_local_host(?string $host): bool
{
    return in_array(strtolower(trim((string)$host)), ['127.0.0.1', 'localhost', '::1'], true);
}

function test_guard_is_local_url(string $url): bool
{
    $p = parse_url($url);
    return is_array($p) && (($p['scheme'] ?? '') === 'http') && test_guard_is_local_host($p['host'] ?? null);
}

/** Sucht rekursiv nach Live-Schluesselpraefixen in einem Konfigurationsarray. */
function test_guard_contains_live_key($value, string $path = 'config'): ?string
{
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if ($hit = test_guard_contains_live_key($v, $path . '.' . $k)) {
                return $hit;
            }
        }
        return null;
    }
    if (is_string($value) && preg_match('/\b(sk|rk)_live_[A-Za-z0-9]/', $value)) {
        return $path;
    }
    return null;
}

/** Prueft die geladene Konfiguration; wird beim Laden dieser Datei ausgefuehrt. */
function test_guard_assert_config(array $cfg, ?string $configFile): void
{
    $configFile = (string)$configFile;
    if ($configFile === '' || !is_file($configFile)) {
        test_guard_fail('SMARTEINZUG_CONFIG fehlt oder zeigt auf keine Datei (Pruefsuiten laufen nur gegen eine eigene Testkonfiguration).');
    }
    $real = (string)realpath($configFile);
    if (str_starts_with($real, '/opt/smarteinzug')) {
        test_guard_fail('Konfiguration liegt unter /opt/smarteinzug (Produktions- oder Staging-Server).');
    }
    if (preg_match('#/php-ionos/app/config\.php$#', $real)) {
        test_guard_fail('Konfiguration ist php-ionos/app/config.php (lokale Entwicklerkonfiguration, kein Pruefstand).');
    }

    $db = (array)($cfg['db'] ?? []);
    if (!test_guard_is_local_host($db['host'] ?? null)) {
        test_guard_fail('Datenbank-Host ist nicht lokal (' . (string)($db['host'] ?? '?') . ').');
    }
    if ((int)($db['port'] ?? 3306) === 3306) {
        test_guard_fail('Datenbank-Port 3306: die Sandbox nutzt einen eigenen Port, ein Standardport deutet auf eine dauerhafte Instanz.');
    }
    if (!preg_match('/(^|_)test(_|$)/', strtolower((string)($db['name'] ?? '')))) {
        test_guard_fail('Datenbankname traegt kein eigenstaendiges Wort "test" (' . (string)($db['name'] ?? '?') . ').');
    }

    $mail = $cfg['mail'] ?? null;
    if (is_array($mail) && !empty($mail['enabled'])) {
        test_guard_fail('mail.enabled ist gesetzt; Pruefsuiten duerfen keine E-Mails versenden.');
    }
    $mon = (array)($cfg['monitoring'] ?? []);
    if (trim((string)($mon['heartbeat_url'] ?? '')) !== '') {
        test_guard_fail('monitoring.heartbeat_url ist gesetzt (externer Totmannschalter).');
    }
    $billing = (array)($cfg['billing'] ?? []);
    if (!empty($billing['enabled'])) {
        test_guard_fail('billing.enabled ist gesetzt (Plattform-Abrechnung gegen ein echtes Stripe-Konto).');
    }
    if ($hit = test_guard_contains_live_key($cfg)) {
        test_guard_fail('Live-Schluessel in der Konfiguration (' . $hit . ').');
    }

    $stripeUrl = trim((string)($cfg['stripe_api_base_url'] ?? ''));
    if ($stripeUrl === '') {
        test_guard_fail('stripe_api_base_url fehlt: ohne lokalen Stub wuerde jeder Stripe-Aufruf api.stripe.com erreichen.');
    }
    if (!test_guard_is_local_url($stripeUrl)) {
        test_guard_fail('stripe_api_base_url zeigt nicht auf 127.0.0.1 (' . $stripeUrl . ').');
    }
    $lexUrl = trim((string)($cfg['lexware_api_base_url'] ?? ''));
    if ($lexUrl === '') {
        test_guard_fail('lexware_api_base_url fehlt: ohne lokale Adresse wuerde ein Abruf api.lexware.io erreichen (Gegenpruefung F-16).');
    }
    if (!test_guard_is_local_url($lexUrl)) {
        test_guard_fail('lexware_api_base_url zeigt nicht auf 127.0.0.1 (' . $lexUrl . ').');
    }
    $sev = (array)($cfg['sevdesk'] ?? []);
    $sevUrl = trim((string)($sev['base_url'] ?? ''));
    if ($sevUrl !== '' && !test_guard_is_local_url($sevUrl)) {
        test_guard_fail('sevdesk.base_url zeigt nicht auf 127.0.0.1 (' . $sevUrl . ').');
    }
    $redis = $cfg['redis'] ?? null;
    if (is_array($redis) && !test_guard_is_local_host($redis['host'] ?? '127.0.0.1')) {
        test_guard_fail('redis.host ist nicht lokal.');
    }
    if ((string)getenv('SMARTEINZUG_REDIS_HOST') !== '' && !test_guard_is_local_host(getenv('SMARTEINZUG_REDIS_HOST'))) {
        test_guard_fail('SMARTEINZUG_REDIS_HOST ist nicht lokal.');
    }
}

/** Zweite Stufe nach dem Verbinden: gespeicherte Zugangsdaten der Firmen duerfen keine Live-Schluessel sein. */
function test_guard_assert_db(PDO $pdo): void
{
    try {
        $rows = $pdo->query('SELECT tenant_id, stripe_secret_key_encrypted FROM integrations WHERE stripe_secret_key_encrypted IS NOT NULL')->fetchAll();
    } catch (Throwable $e) {
        return; // Tabelle fehlt noch (Schema wird gerade aufgebaut)
    }
    foreach ($rows as $r) {
        $plain = function_exists('decrypt_value') ? (string)decrypt_value((string)$r['stripe_secret_key_encrypted']) : '';
        if ($plain !== '' && preg_match('/^(sk|rk)_live_/', $plain)) {
            test_guard_fail('integrations enthaelt einen Live-Schluessel (Firma ' . (string)$r['tenant_id'] . ').');
        }
    }
}

// Beim Laden sofort pruefen. config() stammt aus app/bootstrap.php (ueber bin/_cli.php geladen).
if (!function_exists('config')) {
    test_guard_fail('Bootstrap nicht geladen; test-guard.php nach bin/_cli.php einbinden.');
}
test_guard_assert_config((array)($GLOBALS['config'] ?? []), (string)getenv('SMARTEINZUG_CONFIG'));
