<?php
/**
 * Regressionstest fuer die Redis-Diagnose in bin/healthcheck.php (--redis).
 *
 * Hintergrund: Der erste produktive Einsatz der Candidate-Pruefung (deploy.sh, "docker compose run
 * --rm --no-deps -T php php bin/healthcheck.php --db --redis") scheiterte mit der unbrauchbaren Meldung
 * "UNGESUND: redis: other". Ursache (Teil 1): monitor_category() erkannte die tatsaechliche Fehlermeldung
 * nicht und fiel auf den generischen Sammelbegriff "other" zurueck (das PHP-Image ist Alpine/musl-basiert;
 * musl formuliert DNS-Fehler anders als glibc, z.B. "Try again" oder "Name does not resolve" statt
 * "Temporary failure in name resolution"/"Name or service not known").
 *
 * Ursache (Teil 2, per direktem Test gegen einen echten, temporaeren Redis-Server bestaetigt statt
 * vermutet): deploy/vps/redis/redis.conf setzte "protected-mode yes" OHNE ein Passwort (kein
 * requirepass). Redis' eigenes protected-mode lehnt in dieser Konstellation JEDEN Befehl (nicht die
 * TCP-Verbindung selbst) eines Clients ab, der NICHT ueber die Loopback-Adresse verbindet - das betrifft
 * ausnahmslos jeden Zugriff aus einem anderen Container (php, scheduler, worker, die isolierte
 * Candidate-Pruefung), unabhaengig vom konfigurierten "bind". Ein "docker exec ... redis-cli ping"
 * INNERHALB des redis-Containers selbst laeuft dagegen ueber Loopback und bleibt unberuehrt - genau das
 * hatte bei der Fehlersuche auf dem VPS faelschlich Gesundheit vorgetaeuscht. Behoben durch
 * "protected-mode no" in redis.conf (sicher, da der Dienst ohnehin nur ueber das interne, nicht
 * oeffentlich erreichbare Docker-Netz erreichbar ist) und eine eigene Diagnosekategorie
 * "redis_protected_mode" (statt des irrefuehrenden "auth", das faelschlich ein falsches Passwort in
 * UNSERER Konfiguration vermuten liesse, obwohl die Ursache eine Servereinstellung von Redis selbst ist).
 *
 * Dieses Skript prueft direkt (ohne Docker-Daemon), dass:
 *   1. monitor_category() sowohl glibc- als auch musl-typische DNS-Fehlermeldungen als "dns" erkennt
 *      (nicht mehr als "other").
 *   2. monitor_category() weitere, bislang unerkannte phpredis-Meldungen (u.a. "went away", "reset by
 *      peer") korrekt kategorisiert, und die tatsaechliche Redis-protected-mode-Meldung als eigene
 *      Kategorie "redis_protected_mode" erkennt (nicht als "auth" oder "other").
 *   3. bin/healthcheck.php --redis gegen einen NICHT AUFLOESBAREN Hostnamen (fehlendes/nicht erreichbares
 *      Docker-Netz, echte Netzwerkebene, kein Mock) eine eindeutige, kategorisierte Diagnose liefert statt
 *      "redis: other" oder "redis: nicht erreichbar".
 *   4. bin/healthcheck.php --redis gegen einen erreichbaren Host mit geschlossenem Port (Verbindung
 *      abgelehnt) ebenfalls eindeutig "connection_refused" meldet.
 *   5. bin/healthcheck.php --redis ohne konfiguriertes Redis weiterhin sofort (ohne Wiederholungen) "OK"
 *      liefert (Redis bleibt optional, kein Regressionsrisiko fuer Installationen ohne Redis).
 *   6. Redis bleibt fuer normale Aufrufer (redis_client() ohne Parameter) weiterhin genau einen Versuch
 *      je Prozess wert (kein ungewolltes Wiederholungsverhalten ausserhalb des Healthcheck-Aufrufs).
 *   7. Gegen einen ECHTEN, temporaeren lokalen Redis-Server (sofern "redis-server" verfuegbar ist, sonst
 *      wird dieser Teil uebersprungen): protected-mode yes + kein Passwort + Zugriff ueber eine echte,
 *      NICHT-Loopback-Adresse dieses Hosts liefert "redis: redis_protected_mode" statt "other"/"auth";
 *      derselbe Zugriffsweg mit protected-mode no (wie im ausgelieferten redis.conf) gelingt.
 *   8. deploy/vps/redis/redis.conf enthaelt tatsaechlich "protected-mode no" (Regression gegen genau
 *      diesen Fehler).
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
    [
        "DENIED Redis is running in protected mode because protected mode is enabled and no password is set for the default user. In this mode connections are only accepted from the loopback interface. If you want to connect from external computers to Redis you may adopt one of the following solutions: 1) Just disable protected mode sending the command 'CONFIG SET protected-mode no' from the loopback interface by connecting to Redis from the same host the server is running, however MAKE SURE Redis is not publicly accessible from internet if you do so. Use CONFIG REWRITE to make this change permanent. 2) Alternatively you can just disable the protected mode by editing the Redis configuration file, and setting the protected mode option to 'no', and then restarting the server. 3) If you started the server manually just for testing, restart it with the '--protected-mode no' option. 4) Setup a an authentication password for the default user. NOTE: You only need to do one of the above things in order for the server to start accepting connections from the outside.",
        'redis_protected_mode',
        'Redis protected-mode (echte phpredis-Meldung, siehe deploy/vps/redis/redis.conf)',
    ],
    ["protocol error, got 'H' as reply type byte", 'protocol', 'phpredis: Gegenstelle spricht kein Redis-Protokoll'],
    ['ERR unknown command `PING`, with args beginning with:', 'protocol', 'Redis-Server mit eingeschraenktem Befehlssatz'],
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
function runHealthcheckRedis(string $phpIonos, ?array $redisConfig, array $env = []): array
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
    // Vom VPS-Stack gesetzte Variablen (docker-compose.yml) werden fuer den Testprozess ausdruecklich
    // geleert bzw. gesetzt, damit kein Wert aus der aufrufenden Umgebung hineinwirkt.
    $envPrefix = 'env -u SMARTEINZUG_REDIS_HOST -u SMARTEINZUG_REDIS_EXPECTED_CIDR';
    foreach ($env as $k => $v) {
        $envPrefix .= ' ' . escapeshellarg($k . '=' . $v);
    }
    $cmd = sprintf(
        '%s SMARTEINZUG_CONFIG=%s php %s 2>&1',
        $envPrefix,
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

// --- Teil 7: echter, temporaerer Redis-Server, protected-mode yes/no, Zugriff ueber eine ECHTE,
// nicht-Loopback-Adresse dieses Hosts (entspricht dem Zugriffsweg eines anderen Containers) -----------
function hostNonLoopbackIp(): ?string
{
    $out = @shell_exec('hostname -I 2>/dev/null');
    foreach (preg_split('/\s+/', trim((string)$out)) as $ip) {
        if ($ip !== '' && $ip !== '127.0.0.1') {
            return $ip;
        }
    }
    return null;
}

function startTempRedis(string $dir, int $port, bool $protectedMode, ?string $requirepass = null): bool
{
    $conf = $dir . '/redis-' . $port . '.conf';
    $text = "bind 0.0.0.0 -::1\nprotected-mode " . ($protectedMode ? 'yes' : 'no') . "\nport $port\nappendonly no\nsave \"\"\n";
    if ($requirepass !== null) {
        $text .= "requirepass $requirepass\n";
    }
    file_put_contents($conf, $text);
    exec('redis-server ' . escapeshellarg($conf) . ' --daemonize yes 2>&1', $o, $rc);
    if ($rc !== 0) {
        return false;
    }
    for ($i = 0; $i < 20; $i++) {
        $pingOut = [];
        exec('redis-cli -p ' . (int)$port . ' ping 2>/dev/null', $pingOut);
        // Irgendeine Antwort (PONG, DENIED, NOAUTH) zeigt: der Server laeuft.
        if (trim(implode('', $pingOut)) !== '') {
            return true;
        }
        usleep(100_000);
    }
    return false;
}

function stopTempRedis(int $port, ?string $requirepass = null): void
{
    $auth = $requirepass !== null ? ' -a ' . escapeshellarg($requirepass) . ' --no-auth-warning' : '';
    exec('redis-cli -p ' . (int)$port . $auth . ' shutdown nosave 2>/dev/null');
}

if (@shell_exec('command -v redis-server 2>/dev/null') === null || trim((string)@shell_exec('command -v redis-server 2>/dev/null')) === '') {
    echo "redis-server nicht gefunden, Teil 7 (echter Redis-Server) uebersprungen.\n";
} else {
    $hostIp = hostNonLoopbackIp();
    if ($hostIp === null) {
        fail($errors, "Konnte keine nicht-Loopback-Adresse dieses Hosts ermitteln (hostname -I), Teil 7 uebersprungen.");
    } else {
        $tmpDir = sys_get_temp_dir() . '/se-redis-check-' . getmypid();
        @mkdir($tmpDir);
        $portProtected = 17000 + (getmypid() % 500);
        $portOpen = $portProtected + 1;

        if (!startTempRedis($tmpDir, $portProtected, true)) {
            fail($errors, "Konnte temporaeren Redis-Server (protected-mode yes) fuer Teil 7 nicht starten.");
        } else {
            [$outP, $rcP] = runHealthcheckRedis($phpIonos, ['host' => $hostIp, 'port' => $portProtected, 'password' => null, 'prefix' => 'se:']);
            if ($rcP === 0) {
                fail($errors, "protected-mode yes + kein Passwort + Nicht-Loopback-Zugriff meldete faelschlich Erfolg (erwartet: Fehlschlag).");
            } elseif (!str_contains($outP, 'redis_protected_mode')) {
                fail($errors, "protected-mode yes + kein Passwort + Nicht-Loopback-Zugriff lieferte keine 'redis_protected_mode'-Diagnose: " . trim($outP));
            } else {
                echo "echter Redis, protected-mode yes, Nicht-Loopback-Zugriff: eindeutige Diagnose (" . trim($outP) . ")\n";
            }
            stopTempRedis($portProtected);
        }

        if (!startTempRedis($tmpDir, $portOpen, false)) {
            fail($errors, "Konnte temporaeren Redis-Server (protected-mode no) fuer Teil 7 nicht starten.");
        } else {
            [$outO, $rcO] = runHealthcheckRedis($phpIonos, ['host' => $hostIp, 'port' => $portOpen, 'password' => null, 'prefix' => 'se:']);
            if ($rcO !== 0 || trim($outO) !== 'OK') {
                fail($errors, "protected-mode no + kein Passwort + Nicht-Loopback-Zugriff (wie im ausgelieferten redis.conf) schlug fehl: " . trim($outO));
            } else {
                echo "echter Redis, protected-mode no, Nicht-Loopback-Zugriff: OK (bestaetigt die Wirkung der redis.conf-Aenderung)\n";
            }
            stopTempRedis($portOpen);
        }
        @array_map('unlink', glob($tmpDir . '/*.conf') ?: []);
        @rmdir($tmpDir);
    }
}

// --- Teil 9: Stufenweise Diagnose gegen echte Server (Alias-Kollision mit Coolifys Redis nachgestellt) ---
// Hintergrund (Version 4.10): Der Hostname "redis" loeste auf dem Coolify-Server zu Coolifys EIGENEM,
// passwortgeschuetzten Redis im Netz "coolify" auf (gleicher Dienstname, Dockers DNS liefert das Netz mit
// Gateway zuerst). Die Folge "NOAUTH Authentication required" wurde als "redis: other" (alte Kategorien)
// bzw. "redis: auth" (neue Kategorien) gemeldet, obwohl UNSER Redis kein Passwort verlangt. Hier wird
// genau diese Gegenstelle nachgestellt: ein Redis MIT requirepass, gegen das wir ohne Passwort pruefen.
if (trim((string)@shell_exec('command -v redis-server 2>/dev/null')) !== '') {
    $tmpDir9 = sys_get_temp_dir() . '/se-redis-check9-' . getmypid();
    @mkdir($tmpDir9);
    $portAuth = 17600 + (getmypid() % 300);
    $portOpen = $portAuth + 1;
    $pw = 'nur-lokaler-testwert-' . getmypid();
    if (!startTempRedis($tmpDir9, $portAuth, false, $pw) || !startTempRedis($tmpDir9, $portOpen, false)) {
        fail($errors, 'Konnte temporaere Redis-Server fuer Teil 9 nicht starten.');
    } else {
        // 9a: fremdes Redis mit Passwort, wir senden keins (Coolify-Fall) -> "auth", Stufe redis, NOAUTH sichtbar,
        //     Passwortwert NIRGENDS in der Ausgabe.
        [$o, $rc] = runHealthcheckRedis($phpIonos, ['host' => '127.0.0.1', 'port' => $portAuth, 'password' => null, 'prefix' => 'se:']);
        if ($rc === 0 || !str_contains($o, 'redis: auth') || !str_contains($o, 'stufe=redis') || !str_contains($o, 'NOAUTH')) {
            fail($errors, "Passwortgeschuetztes fremdes Redis ohne Passwort lieferte nicht 'auth' mit NOAUTH-Diagnose: " . trim($o));
        } else {
            echo "fremdes Redis mit Passwort (Coolify-Fall): redis: auth, DIAGNOSE stufe=redis NOAUTH\n";
        }
        if (str_contains($o, $pw)) {
            fail($errors, 'Passwortwert erschien in der Healthcheck-Ausgabe (Geheimnis geleakt).');
        }
        // 9b: dasselbe Redis mit korrektem Passwort -> gesund, Passwort nicht in der Ausgabe.
        [$o, $rc] = runHealthcheckRedis($phpIonos, ['host' => '127.0.0.1', 'port' => $portAuth, 'password' => $pw, 'prefix' => 'se:']);
        if ($rc !== 0 || trim($o) !== 'OK') {
            fail($errors, "Redis mit Passwort und korrektem Passwort schlug fehl: " . trim($o));
        } else {
            echo "Redis mit Passwort + korrektes Passwort: OK\n";
        }
        if (str_contains($o, $pw)) {
            fail($errors, 'Passwortwert erschien in der Healthcheck-Ausgabe (Geheimnis geleakt).');
        }
        // 9c: Hostname loest in ein ANDERES Netz auf als vom Stack erwartet -> "network_mismatch", Stufe network,
        //     kein TCP-/Protokollversuch gegen die fremde Gegenstelle.
        [$o, $rc] = runHealthcheckRedis($phpIonos, ['host' => '127.0.0.1', 'port' => $portOpen, 'password' => null, 'prefix' => 'se:'],
            ['SMARTEINZUG_REDIS_EXPECTED_CIDR' => '172.28.0.0/24']);
        if ($rc === 0 || !str_contains($o, 'redis: network_mismatch') || !str_contains($o, 'stufe=network')) {
            fail($errors, "Aufloesung in ein fremdes Netz lieferte nicht 'network_mismatch': " . trim($o));
        } else {
            echo "Aufloesung ausserhalb des erwarteten Netzes: redis: network_mismatch (DIAGNOSE stufe=network)\n";
        }
        // 9d: passendes Netz -> gesund.
        [$o, $rc] = runHealthcheckRedis($phpIonos, ['host' => '127.0.0.1', 'port' => $portOpen, 'password' => null, 'prefix' => 'se:'],
            ['SMARTEINZUG_REDIS_EXPECTED_CIDR' => '127.0.0.0/8']);
        if ($rc !== 0 || trim($o) !== 'OK') {
            fail($errors, "Aufloesung im erwarteten Netz schlug fehl: " . trim($o));
        } else {
            echo "Aufloesung im erwarteten Netz: OK\n";
        }
        // 9e: einteiliger Docker-Alias fehlt -> "alias_missing" (Stufe resolve), im Unterschied zu "dns" fuer FQDN (Teil 3).
        [$o, $rc] = runHealthcheckRedis($phpIonos, ['host' => 'smarteinzug-redis-alias-fehlt', 'port' => $portOpen, 'password' => null, 'prefix' => 'se:']);
        if ($rc === 0 || !str_contains($o, 'redis: alias_missing') || !str_contains($o, 'stufe=resolve')) {
            fail($errors, "Fehlender einteiliger Alias lieferte nicht 'alias_missing': " . trim($o));
        } else {
            echo "fehlender Docker-Alias: redis: alias_missing (DIAGNOSE stufe=resolve)\n";
        }
        // 9f: SMARTEINZUG_REDIS_HOST (vom Stack) hat Vorrang vor config('redis')['host'].
        [$o, $rc] = runHealthcheckRedis($phpIonos, ['host' => 'smarteinzug-redis-alias-fehlt', 'port' => $portOpen, 'password' => null, 'prefix' => 'se:'],
            ['SMARTEINZUG_REDIS_HOST' => '127.0.0.1']);
        if ($rc !== 0 || trim($o) !== 'OK') {
            fail($errors, "SMARTEINZUG_REDIS_HOST hatte keinen Vorrang vor config.php: " . trim($o));
        } else {
            echo "SMARTEINZUG_REDIS_HOST hat Vorrang vor config('redis')['host']: OK\n";
        }
        stopTempRedis($portAuth, $pw);
        stopTempRedis($portOpen);
    }
    @array_map('unlink', glob($tmpDir9 . '/*.conf') ?: []);
    @rmdir($tmpDir9);
} else {
    echo "redis-server nicht gefunden, Teil 9 uebersprungen.\n";
}

// --- Teil 8: ausgeliefertes redis.conf enthaelt tatsaechlich "protected-mode no" -----------------------
$redisConf = $root . '/deploy/vps/redis/redis.conf';
if (!is_file($redisConf)) {
    fail($errors, "deploy/vps/redis/redis.conf nicht gefunden.");
} else {
    $confText = file_get_contents($redisConf);
    if (!preg_match('/^\s*protected-mode\s+no\s*$/mi', $confText)) {
        fail($errors, "deploy/vps/redis/redis.conf enthaelt kein 'protected-mode no'. Ohne requirepass lehnt Redis sonst jeden Befehl eines anderen Containers ab (siehe Teil 7).");
    } else {
        echo "deploy/vps/redis/redis.conf: 'protected-mode no' vorhanden.\n";
    }
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
