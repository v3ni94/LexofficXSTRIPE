<?php
/**
 * Redis (optional, ergänzend zu MariaDB): Sperren, Ratenbegrenzung, Cache, kurzlebiger Status.
 * Fehlt Redis oder die Erweiterung, arbeiten alle Aufrufer mit Datenbank-Fallback weiter.
 * Konfiguration: 'redis' => ['host' => 'redis', 'port' => 6379, 'password' => null, 'prefix' => 'se:']
 */
declare(strict_types=1);

/**
 * Letzter Fehlschlaggrund von redis_client() (Kategorie aus monitor_category(), keine Geheimnisse),
 * fuer Diagnosezwecke (bin/healthcheck.php --redis). null, solange noch kein Fehlschlag auftrat.
 */
function redis_last_error(): ?string
{
    return $GLOBALS['redis_last_error'] ?? null;
}

/**
 * Tatsaechlich verwendeter Redis-Hostname. Die Umgebungsvariable SMARTEINZUG_REDIS_HOST (gesetzt vom
 * VPS-Stack, deploy/vps/docker-compose.yml) hat Vorrang vor config('redis')['host']: Der in config.php
 * uebliche Hostname "redis" ist auf einem Coolify-Server mehrdeutig, weil Coolifys eigener Stack
 * ebenfalls einen Dienst "redis" (mit Passwort) im gemeinsam genutzten Netz "coolify" fuehrt und Dockers
 * DNS diesen zuerst liefert. Nur der Stack kennt den eindeutigen Alias ("smarteinzug-redis"), deshalb
 * entscheidet er. Ohne die Variable (IONOS-Webhosting, lokale Tests) gilt config.php unveraendert.
 * Liefert '' wenn Redis nicht konfiguriert ist.
 */
function redis_effective_host(): string
{
    $cfg = (array)config('redis', []);
    if (!$cfg) {
        return '';
    }
    $env = trim((string)getenv('SMARTEINZUG_REDIS_HOST'));
    if ($env !== '') {
        return $env;
    }
    return trim((string)($cfg['host'] ?? ''));
}

/**
 * Fehlermeldung fuer Protokoll/Diagnose bereinigen: konfiguriertes Passwort und jedes "AUTH <wert>" maskieren,
 * Leerraum verdichten, kuerzen. Eigenstaendig testbar (tools/healthcheck-redis-check.php).
 */
function redis_sanitize_message(string $message, ?string $password): string
{
    if ($password !== null && $password !== '') {
        $message = str_replace($password, '***', $message);
    }
    $message = preg_replace('/\bAUTH\s+\S+/i', 'AUTH ***', $message) ?? $message;
    $message = preg_replace('/\s+/', ' ', trim($message)) ?? $message;
    return mb_substr($message, 0, 300);
}

/**
 * @param bool $forceRetry Umgeht den einmaligen Verbindungsversuch je Prozess (static $tried) und
 *     versucht erneut zu verbinden. Fuer normale Aufrufer (Sperren, Ratenbegrenzung) bleibt es bei
 *     genau einem Versuch je Prozess; nur der Healthcheck (kurzlebiger CLI-Aufruf, soll eine
 *     transiente Stoerung z. B. beim Netzwerkaufbau eines frisch erzeugten Containers durch
 *     Wiederholung abfedern koennen) setzt bewusst true.
 */
function redis_client(bool $forceRetry = false): ?Redis
{
    static $client = null;
    static $tried = false;
    if ($tried && !$forceRetry) {
        return $client;
    }
    $tried = true;
    $cfg = (array)config('redis', []);
    $host = redis_effective_host();
    if ($host === '') {
        $GLOBALS['redis_last_error'] = 'nicht konfiguriert';
        return null;
    }
    if (!class_exists('Redis')) {
        $GLOBALS['redis_last_error'] = 'php-redis-Erweiterung fehlt';
        return null;
    }
    try {
        $r = new Redis();
        // 1.5 s Verbindungs- UND Lese-Timeout (6. Parameter): eine Gegenstelle, die verbindet, aber nie
        // antwortet, blockiert PING sonst fuer default_socket_timeout (60 s).
        if (!$r->connect($host, (int)($cfg['port'] ?? 6379), 1.5, null, 0, 1.5)) {
            $GLOBALS['redis_last_error'] = 'connection_refused';
            $GLOBALS['redis_last_message'] = 'connect() lieferte false';
            $client = null;
            return null;
        }
        if (!empty($cfg['password'])) {
            $r->auth((string)$cfg['password']);
        }
        $r->setOption(Redis::OPT_PREFIX, (string)($cfg['prefix'] ?? 'se:'));
        $client = $r;
        $GLOBALS['redis_last_error'] = null;
        $GLOBALS['redis_last_message'] = null;
    } catch (Throwable $e) {
        $client = null;
        $GLOBALS['redis_last_error'] = monitor_category($e);
        // Originalmeldung nur fuer redis_probe() (dort maskiert), nie direkt ausgeben.
        $GLOBALS['redis_last_message'] = $e->getMessage();
    }
    return $client;
}

function redis_available(): bool
{
    return redis_client() !== null;
}

/** IPv4-Adresse innerhalb eines CIDR-Bereichs? Ungueltige Eingaben liefern false. */
function redis_ip_in_cidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) {
        return false;
    }
    [$net, $bits] = explode('/', $cidr, 2);
    // Nur ein reines Dezimalpraefix und ein gueltiges IPv4-Netz zulassen: "abc", "" oder "24x" wuerden
    // per (int) zu 0 bzw. 24 und die Netzpruefung stillschweigend abschalten. IPv6 wird hier nicht
    // bewertet (false); redis_probe() ueberspringt die Netzpruefung dann ausdruecklich.
    if (!ctype_digit($bits) || filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $ipL = ip2long($ip);
    $netL = ip2long($net);
    $bits = (int)$bits;
    if ($ipL === false || $netL === false || $bits > 32) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
    return (($ipL & $mask) === ($netL & $mask));
}

/**
 * Stufenweise Redis-Diagnose ohne Geheimnisse (bin/healthcheck.php --redis, Candidate-Pruefung):
 *   1. Aufloesung des Hostnamens (Docker-DNS): fehlt der Alias im angehefteten Netz -> "alias_missing"
 *      (einteiliger Name) bzw. "dns" (voll qualifizierter Name);
 *   2. Netzpruefung: loest der Name in ein anderes Netz auf als erwartet (SMARTEINZUG_REDIS_EXPECTED_CIDR,
 *      vom Stack gesetzt) -> "network_mismatch". Genau so faellt auf, wenn "redis" auf einem
 *      Coolify-Server zu Coolifys eigenem Redis im Netz "coolify" aufloest statt zu unserem; mehrere
 *      Adressen -> "alias_ambiguous";
 *   3. TCP-Verbindung zur aufgeloesten Adresse -> "connection_refused", "timeout", "network_unreachable";
 *   4. Redis-Protokoll (connect + PING) -> "redis_protected_mode", "auth" (NOAUTH/WRONGPASS: die
 *      Gegenstelle verlangt ein Passwort, das wir nicht senden - bei unserem passwortlosen Redis ein
 *      sicheres Zeichen fuer eine FREMDE Gegenstelle), "protocol", sonst Kategorie aus monitor_category().
 * Liefert ok, category, stage (resolve|network|tcp|redis|ok), host, port, resolved (IPs), expected_cidr
 * und message (Originalmeldung, Passwoerter maskiert, gekuerzt).
 */
function redis_probe(): array
{
    $cfg = (array)config('redis', []);
    $host = redis_effective_host();
    $port = (int)($cfg['port'] ?? 6379);
    $cidr = trim((string)getenv('SMARTEINZUG_REDIS_EXPECTED_CIDR'));
    $res = ['ok' => false, 'category' => 'other', 'stage' => 'resolve', 'host' => $host, 'port' => $port,
            'resolved' => [], 'expected_cidr' => $cidr !== '' ? $cidr : null, 'message' => ''];
    $sanitize = static fn(string $m): string => redis_sanitize_message($m, (string)($cfg['password'] ?? ''));
    if ($host === '') {
        $res['category'] = 'nicht konfiguriert';
        return $res;
    }
    if (!class_exists('Redis')) {
        $res['category'] = 'php-redis-Erweiterung fehlt';
        return $res;
    }
    // Unix-Socket (phpredis: Pfad als Host, Port < 1): keine Aufloesung, kein Netz, kein TCP - direkt Protokoll.
    $unixSocket = str_starts_with($host, '/');

    // 1. Aufloesung
    if ($unixSocket) {
        $ips = [];
    } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host);
        if ($ips === false || !$ips) {
            $res['category'] = str_contains($host, '.') ? 'dns' : 'alias_missing';
            $res['message'] = 'Hostname "' . $host . '" nicht aufloesbar (kein DNS-Alias in einem angehefteten Docker-Netz?)';
            return $res;
        }
        $ips = array_values(array_unique($ips));
    }
    $res['resolved'] = $ips;

    // 2. Netzpruefung (nur IPv4; IPv6-Adressen werden von der CIDR-Pruefung ausgenommen, siehe redis_ip_in_cidr)
    $res['stage'] = 'network';
    if ($cidr !== '' && !$unixSocket) {
        $ipv4 = array_values(array_filter($ips, static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        $outside = array_values(array_filter($ipv4, static fn(string $ip): bool => !redis_ip_in_cidr($ip, $cidr)));
        if ($outside) {
            $res['category'] = 'network_mismatch';
            $res['message'] = 'Hostname "' . $host . '" loest nach ' . implode(',', $outside) . ' auf, erwartet wurde eine Adresse in ' . $cidr
                . ' (fremder Dienst gleichen Namens in einem anderen angehefteten Netz, z.B. Coolifys eigenes Redis?)';
            return $res;
        }
    }
    if (count($ips) > 1) {
        $res['category'] = 'alias_ambiguous';
        $res['message'] = 'Hostname "' . $host . '" loest nach mehreren Adressen auf (' . implode(',', $ips) . '), es wird genau eine erwartet';
        return $res;
    }

    // 3. TCP (IPv6-Adressen in eckigen Klammern; Unix-Socket ueberspringt diese Stufe)
    $res['stage'] = 'tcp';
    $errno = 0;
    $errstr = '';
    $ip = $ips[0] ?? '';
    $target = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
    $sock = $unixSocket ? @stream_socket_client('unix://' . $host, $errno, $errstr, 1.5)
                        : @stream_socket_client('tcp://' . $target . ':' . $port, $errno, $errstr, 1.5);
    if ($sock === false) {
        $low = mb_strtolower($errstr);
        if ($errno === 111 || str_contains($low, 'refused')) {
            $res['category'] = 'connection_refused';
        } elseif ($errno === 110 || str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
            $res['category'] = 'timeout';
        } elseif (in_array($errno, [101, 113], true) || str_contains($low, 'unreachable') || str_contains($low, 'no route')) {
            $res['category'] = 'network_unreachable';
        } else {
            $res['category'] = monitor_category($errstr !== '' ? $errstr : 'connection');
        }
        $res['message'] = $sanitize(($unixSocket ? 'Unix-Socket ' . $host : 'TCP ' . $target . ':' . $port) . ' fehlgeschlagen (' . $errno . '): ' . $errstr);
        return $res;
    }
    fclose($sock);

    // 4. Redis-Protokoll
    $res['stage'] = 'redis';
    $r = redis_client(true);
    if (!$r) {
        $res['category'] = redis_last_error() ?? 'connection';
        $res['message'] = $sanitize((string)($GLOBALS['redis_last_message'] ?? 'connect() fehlgeschlagen'));
        return $res;
    }
    try {
        $pong = $r->ping();
    } catch (Throwable $e) {
        $res['category'] = monitor_category($e);
        $res['message'] = $sanitize($e->getMessage());
        return $res;
    }
    if ($pong !== true && (!is_string($pong) || stripos($pong, 'PONG') === false)) {
        $res['category'] = 'protocol';
        $res['message'] = $sanitize('PING lieferte unerwartete Antwort: ' . var_export($pong, true));
        return $res;
    }
    $res['ok'] = true;
    $res['category'] = 'ok';
    $res['stage'] = 'ok';
    return $res;
}

/** Eine Zeile fuer das Deployment-Protokoll, ohne Geheimnisse (siehe redis_probe()). */
function redis_probe_describe(array $p): string
{
    return sprintf(
        'DIAGNOSE redis: host=%s port=%d aufgeloest=%s erwartet=%s stufe=%s kategorie=%s meldung="%s"',
        $p['host'] !== '' ? $p['host'] : '-',
        (int)$p['port'],
        $p['resolved'] ? implode(',', $p['resolved']) : 'keine',
        $p['expected_cidr'] ?? '-',
        $p['stage'],
        $p['category'],
        str_replace('"', "'", (string)$p['message'])
    );
}

/** Sperre mit Ablauf setzen (SET NX PX). Liefert true, wenn die Sperre gehört. */
function redis_lock(string $name, int $ttlSeconds, string $owner): bool
{
    $r = redis_client();
    if (!$r) {
        return false;
    }
    try {
        return (bool)$r->set('lock:' . $name, $owner, ['nx', 'px' => max(1000, $ttlSeconds * 1000)]);
    } catch (Throwable $e) {
        return false;
    }
}

function redis_unlock(string $name, string $owner): void
{
    $r = redis_client();
    if (!$r) {
        return;
    }
    try {
        // nur die eigene Sperre löschen
        $r->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end", ['lock:' . $name, $owner], 1);
    } catch (Throwable $e) {
        // ignorieren
    }
}

/**
 * Zentrale Ratenbegrenzung je Anbindung (feste Fenster von einer Sekunde). Liefert die Wartezeit in
 * Millisekunden, die der Aufrufer vor dem Request abwarten soll (0 = sofort). Ohne Redis: 0, die
 * bestehende Drosselung im Client greift weiterhin.
 */
function redis_rate_wait_ms(string $api, int $perSecond): int
{
    $r = redis_client();
    if (!$r || $perSecond <= 0) {
        return 0;
    }
    try {
        $slot = (int)floor(microtime(true));
        $key = 'rate:' . $api . ':' . $slot;
        $n = (int)$r->incr($key);
        if ($n === 1) {
            $r->expire($key, 3);
        }
        if ($n <= $perSecond) {
            return 0;
        }
        // Rest der aktuellen Sekunde plus Überlaufsekunden
        $over = intdiv($n - 1, $perSecond);
        return (int)round((($slot + $over + 1) - microtime(true)) * 1000);
    } catch (Throwable $e) {
        return 0;
    }
}
