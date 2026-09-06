<?php
/**
 * Redis (optional, ergänzend zu MariaDB): Sperren, Ratenbegrenzung, Cache, kurzlebiger Status.
 * Fehlt Redis oder die Erweiterung, arbeiten alle Aufrufer mit Datenbank-Fallback weiter.
 * Konfiguration: 'redis' => ['host' => 'redis', 'port' => 6379, 'password' => null, 'prefix' => 'se:']
 */
declare(strict_types=1);

function redis_client(): ?Redis
{
    static $client = null;
    static $tried = false;
    if ($tried) {
        return $client;
    }
    $tried = true;
    $cfg = (array)config('redis', []);
    if (!$cfg || empty($cfg['host']) || !class_exists('Redis')) {
        return null;
    }
    try {
        $r = new Redis();
        if (!$r->connect((string)$cfg['host'], (int)($cfg['port'] ?? 6379), 1.5)) {
            return null;
        }
        if (!empty($cfg['password'])) {
            $r->auth((string)$cfg['password']);
        }
        $r->setOption(Redis::OPT_PREFIX, (string)($cfg['prefix'] ?? 'se:'));
        $client = $r;
    } catch (Throwable $e) {
        $client = null;
    }
    return $client;
}

function redis_available(): bool
{
    return redis_client() !== null;
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
