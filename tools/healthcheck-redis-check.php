<?php
/**
 * Regressionstest fuer die Redis-Diagnose in bin/healthcheck.php (--redis).
 *
 * Hintergrund: Der erste produktive Einsatz der Candidate-Pruefung (deploy.sh, "docker compose run
 * --rm --no-deps -T php php bin/healthcheck.php --db --redis") scheiterte mit der unbrauchbaren Meldung
 * "UNGESUND: redis: other". Ursache: monitor_category() erkannte die tatsaechliche Fehlermeldung nicht
 * und fiel auf den generischen Sammelbegriff "other" zurueck (das PHP-Image ist Alpine/musl-basiert;
 * musl formuliert DNS-Fehler anders als glibc, z.B. "Try again" oder "Name does not resolve" statt
 * "Temporary failure in name resolution"/"Name or service not known"). Dieses Skript prueft direkt (ohne
 * Docker-Daemon, ohne laufenden Redis-Server), dass:
 *   1. monitor_category() sowohl glibc- als auch musl-typische DNS-Fehlermeldungen als "dns" erkennt
 *      (nicht mehr als "other").
 *   2. monitor_category() weitere, bislang unerkannte phpredis-Meldungen (u.a. "went away", "reset by
 *      peer") korrekt kategorisiert.
 *   3. bin/healthcheck.php --redis gegen einen NICHT AUFLOESBAREN Hostnamen (fehlendes/nicht erreichbares
 *      Docker-Netz, echte Netzwerkebene, kein Mock) eine eindeutige, kategorisierte Diagnose liefert statt
 *      "redis: other" oder "redis: nicht erreichbar".
 *   4. bin/healthcheck.php --redis gegen einen erreichbaren Host mit geschlossenem Port (Verbindung
 *      abgelehnt) ebenfalls eindeutig "connection_refused" meldet.
 *   5. bin/healthcheck.php --redis ohne konfiguriertes Redis weiterhin sofort (ohne Wiederholungen) "OK"
 *      liefert (Redis bleibt optional, kein Regressionsrisiko fuer Installationen ohne Redis).
 *   6. Redis bleibt fuer normale Aufrufer (redis_client() ohne Parameter) weiterhin genau einen Versuch
 *      je Prozess wert (kein ungewolltes Wiederholungsverhalten ausserhalb des Healthcheck-Aufrufs).
 *
 * Aufruf: php tools/healthcheck-redis-check.php     Exit 0 = in Ordnung, 1 = Fehler
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$phpIonos = $root . '/php-ionos';
$errors = [];

function fail(array &$errors, string $msg): void
{
    $errors[] = $msg;
}

// --- Teil 1+2: monitor_category() direkt, ohne Bootstrap (keine Datenbank noetig) --------------------
function loadMonitorCategory(string $phpIonos): void
{
    // config() wird von monitor.php's Abhaengigkeitskette referenziert (nie am Modulanfang aufgerufen,
    // siehe app/bootstrap.php); diese Fake-Implementierung entspricht exakt der echten aus
    // app/bootstrap.php (liest $GLOBALS['config']), damit Teil 6 unten reale Redis-Konfiguration
    // hinterlegen kann, statt immer nur den Vorgabewert zu erhalten.
    if (!function_exists('config')) {
        function config(string $key, $default = null) { return $GLOBALS['config'][$key] ?? $default; }
    }
    $GLOBALS['config'] = [];
    require_once $phpIonos . '/app/monitor.php';
}
loadMonitorCategory($phpIonos);

$cases = [
    // [Meldungstext, erwartete Kategorie, Beschreibung]
    ['Temporary failure in name resolution', 'dns', 'glibc-DNS-Fehlertext'],
    ['php_network_getaddresses: getaddrinfo for redis failed: Name or service not known', 'dns', 'glibc-getaddrinfo-Fehlertext'],
    ['php_network_getaddresses: getaddrinfo for redis failed: Try again', 'dns', 'musl/Alpine-DNS-Fehlertext (EAI_AGAIN)'],
    ['php_network_getaddresses: getaddrinfo for redis failed: Name does not resolve', 'dns', 'musl/Alpine-DNS-Fehlertext (EAI_NONAME)'],
    ['Connection refused', 'connection_refused', 'TCP-Verbindung abgelehnt'],
    ['No route to host', 'connection_refused', 'Netzwerk nicht erreichbar (fehlendes Docker-Netz)'],
    ['redis server went away', 'connection', 'phpredis: Verbindung vom Server beendet'],
    ['connection reset by peer', 'connection', 'TCP-Verbindung zurueckgesetzt'],
    ['NOAUTH Authentication required.', 'auth', 'Redis-Authentifizierung fehlt'],
    ['WRONGPASS invalid username-password pair', 'auth', 'Redis-Authentifizierung falsch'],
];
foreach ($cases as [$msg, $expected, $desc]) {
    $got = monitor_category(new Exception($msg));
    if ($got !== $expected) {
        fail($errors, "monitor_category('$msg') [$desc] lieferte '$got', erwartet '$expected'.");
    }
    if ($got === 'other') {
        fail($errors, "monitor_category('$msg') [$desc] fiel auf 'other' zurueck, genau der urspruengliche Fehler.");
    }
}
echo count($cases) . " monitor_category()-Faelle geprueft.\n";

// --- Hilfsfunktion: bin/healthcheck.php --redis als eigener Prozess mit einer Wegwerf-Konfiguration ---
function runHealthcheckRedis(string $phpIonos, ?array $redisConfig): array
{
    $cfgFile = tempnam(sys_get_temp_dir(), 'se-hc-cfg-');
    $redisExport = $redisConfig === null ? 'null' : var_export($redisConfig, true);
    file_put_contents($cfgFile, "<?php\ndeclare(strict_types=1);\nif (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }\nreturn [\n"
        . "    'timezone' => 'Europe/Berlin',\n"
        . "    'app_secret' => str_repeat('a', 64),\n"
        . "    'cron_token' => str_repeat('b', 32),\n"
        . "    'db' => ['host' => 'unused', 'port' => 3306, 'name' => 'unused', 'user' => 'unused', 'pass' => 'unused', 'charset' => 'utf8mb4'],\n"
        . "    'redis' => $redisExport,\n"
        . "];\n");
    $cmd = sprintf(
        'SMARTEINZUG_CONFIG=%s php %s 2>&1',
        escapeshellarg($cfgFile),
        escapeshellarg($phpIonos . '/bin/healthcheck.php') . ' --redis'
    );
    $start = microtime(true);
    exec($cmd, $outLines, $exitCode);
    $duration = microtime(true) - $start;
    unlink($cfgFile);
    return [implode("\n", $outLines), $exitCode, $duration];
}

// --- Teil 3: nicht aufloesbarer Hostname (entspricht einem fehlenden/noch nicht angehefteten Docker-Netz) ---
[$out, $rc, $dur] = runHealthcheckRedis($phpIonos, ['host' => 'no-such-host-smarteinzug-test.invalid', 'port' => 6379, 'password' => null, 'prefix' => 'se:']);
if ($rc === 0) {
    fail($errors, "healthcheck.php --redis gegen einen nicht aufloesbaren Hostnamen meldete faelschlich Erfolg.");
} elseif (str_contains($out, 'redis: other') || str_contains($out, 'redis: nicht erreichbar')) {
    fail($errors, "healthcheck.php --redis gegen einen nicht aufloesbaren Hostnamen lieferte weiterhin eine unbrauchbare Diagnose: " . trim($out));
} elseif (!preg_match('/redis: \S+/', $out)) {
    fail($errors, "healthcheck.php --redis gegen einen nicht aufloesbaren Hostnamen lieferte keine erkennbare Kategorie: " . trim($out));
} else {
    echo "nicht aufloesbarer Hostname: eindeutige Diagnose (" . trim($out) . ")\n";
}

// --- Teil 4: erreichbarer Host, aber geschlossener Port (Verbindung abgelehnt) ------------------------
[$out2, $rc2, $dur2] = runHealthcheckRedis($phpIonos, ['host' => '127.0.0.1', 'port' => 1, 'password' => null, 'prefix' => 'se:']);
if ($rc2 === 0) {
    fail($errors, "healthcheck.php --redis gegen einen geschlossenen Port meldete faelschlich Erfolg.");
} elseif (!str_contains($out2, 'connection_refused') && !str_contains($out2, 'redis: connection')) {
    fail($errors, "healthcheck.php --redis gegen einen geschlossenen Port lieferte keine Verbindungsdiagnose: " . trim($out2));
} else {
    echo "geschlossener Port: eindeutige Diagnose (" . trim($out2) . ")\n";
}
// Beide Fehlerfaelle durchlaufen bis zu drei Versuche mit kurzer Pause (siehe bin/healthcheck.php); das
// muss spuerbar laenger dauern als der sofortige Erfolgsfall unten, sonst wuerde die Wiederholung, die
// eine rein transiente Netzwerkstoerung beim Containerstart abfedern soll, gar nicht laufen.
if ($dur < 0.9 || $dur2 < 0.9) {
    fail($errors, sprintf(
        "healthcheck.php --redis scheint bei einem Fehlschlag NICHT zu wiederholen (Dauer %.2fs/%.2fs, erwartet spuerbar laenger).",
        $dur,
        $dur2
    ));
}

// --- Teil 5: kein Redis konfiguriert, weiterhin sofortiger Erfolg ohne Wiederholungen -----------------
[$out3, $rc3, $dur3] = runHealthcheckRedis($phpIonos, null);
if ($rc3 !== 0 || trim($out3) !== 'OK') {
    fail($errors, "healthcheck.php --redis ohne konfiguriertes Redis schlug fehl (erwartet: sofortiges OK): " . trim($out3));
}
if ($dur3 > 0.5) {
    fail($errors, sprintf("healthcheck.php --redis ohne konfiguriertes Redis dauerte %.2fs, erwartet nahezu sofort (keine Wiederholungen noetig).", $dur3));
}
echo "kein Redis konfiguriert: weiterhin sofortiges OK ohne Wiederholungen\n";

// --- Teil 6: redis_client() ohne forceRetry bleibt genau ein Versuch je Prozess (normale Aufrufer) ----
require_once $phpIonos . '/app/redis.php';
$GLOBALS['config']['redis'] = ['host' => 'no-such-host-smarteinzug-test.invalid', 'port' => 6379, 'password' => null, 'prefix' => 'se:'];
// Zwei aufeinanderfolgende redis_client()-Aufrufe OHNE forceRetry duerfen den Verbindungsversuch nicht
// wiederholen (Sperren/Ratenbegrenzung sollen weiterhin hoechstens einen Versuch je Prozess kosten);
// beide muessen dasselbe (gecachte) Ergebnis liefern.
$first = redis_client();
$second = redis_client();
if ($first !== $second) {
    fail($errors, 'redis_client() ohne forceRetry lieferte bei zwei Aufrufen unterschiedliche Ergebnisse (Cache je Prozess erwartet).');
} elseif ($first !== null) {
    fail($errors, 'redis_client() gegen einen nicht aufloesbaren Host lieferte unerwartet einen Client zurueck.');
} else {
    echo "redis_client() cached weiterhin je Prozess, wenn forceRetry nicht gesetzt ist.\n";
}
if (redis_last_error() === null || redis_last_error() === 'other') {
    fail($errors, "redis_last_error() lieferte '" . (redis_last_error() ?? 'null') . "' statt einer verwertbaren Kategorie.");
}

echo "\n";
if ($errors) {
    foreach ($errors as $e) {
        echo "FEHLER: $e\n";
    }
    echo "\n" . count($errors) . " Fehler\n";
    exit(1);
}
echo "0 Fehler\n";
exit(0);
