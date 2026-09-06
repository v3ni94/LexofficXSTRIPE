<?php
/**
 * Darstellungshilfen für den Adminbereich "System" (HTML-Fragmente, Beschriftungen).
 * Zustände werden immer mit Symbol und Text ausgegeben, nicht nur farblich.
 */
declare(strict_types=1);

require_once __DIR__ . '/monitor.php';

function monitor_state_badge(string $state, ?string $text = null): string
{
    $cls = ['ok' => 'badge-success', 'degraded' => 'badge-warn', 'fail' => 'badge-danger', 'maintenance' => 'badge-neutral', 'unknown' => 'badge-neutral'][$state] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . ' mon-badge" data-state="' . e($state) . '">' . e(MONITOR_STATE_SYMBOLS[$state] ?? '?') . ' ' . e($text ?? (MONITOR_STATES[$state] ?? $state)) . '</span>';
}

/** Verständliche Beschriftung der bereinigten Fehlerkategorien. */
function monitor_category_label(?string $c): string
{
    if ($c === null || $c === '') {
        return '';
    }
    $map = [
        'timeout' => 'Zeitüberschreitung', 'dns' => 'Namensauflösung', 'tls' => 'TLS-Verbindung', 'auth' => 'Anmeldung/Schlüssel abgelehnt',
        'throttled' => 'Drosselung (429)', 'http_5xx' => 'Serverfehler (5xx)', 'http_4xx' => 'Clientfehler (4xx)', 'redirect' => 'Unerwartete Weiterleitung',
        'connection' => 'Verbindungsfehler', 'database' => 'Datenbankfehler', 'other' => 'Sonstiger Fehler', 'slow' => 'Langsam (über Schwelle)',
        'not_dynamic' => 'Keine dynamische PHP-Antwort', 'stale_response' => 'Antwort mit veraltetem Zeitstempel', 'health_db' => 'PHP läuft, Datenbank laut health.php nicht lesbar',
        'marker_missing' => 'Inhaltsmerkmal fehlt', 'asset' => 'CSS/JavaScript nicht ausgeliefert', 'cert_expired' => 'Zertifikat abgelaufen', 'cert_expiring' => 'Zertifikat läuft bald ab',
        'cert_unreadable' => 'Zertifikat nicht lesbar', 'not_configured' => 'Nicht eingerichtet', 'no_data' => 'Noch keine Messdaten', 'not_checked' => 'Nicht geprüft',
        'not_available' => 'Vom Hosting nicht bereitgestellt', 'cron_late' => 'Cron verspätet', 'api_errors' => 'Erhöhte technische Fehlerquote',
        'api_errors_small_sample' => 'Nur Fehler bei kleiner Stichprobe', 'migration_failed' => 'Migration fehlgeschlagen (manuelle Klärung)', 'migration_unknown' => 'Migrationszustand ungeklärt',
        'migration_running' => 'Migration läuft', 'send_failed' => 'Übergabe an Versandweg fehlgeschlagen', 'mail_function_false' => 'mail() hat die Nachricht nicht angenommen',
        'log_write_failed' => 'Testprotokoll nicht beschreibbar', 'heartbeat_stale' => 'Ausführung unbestätigt (Heartbeat abgelaufen)', 'lock_lost' => 'Sperre während des Schritts verloren',
        'platform_paused' => 'Not-Stopp der Plattform aktiv', 'no_activity' => 'Keine Aktivität im Zeitraum', 'stale' => 'Messung veraltet', 'signature' => 'Signaturprüfung fehlgeschlagen (Firmenkonfiguration)',
        'tenant_unknown' => 'Firma nicht zuordenbar', 'double_start' => 'Doppelstart übersprungen', 'backup_failed' => 'Letzte Sicherung fehlgeschlagen', 'backup_stale' => 'Letzte Sicherung älter als 26 Stunden', 'local_only' => 'Nur lokale Sicherung, kein externes Ziel', 'unreadable' => 'Ergebnisdatei nicht lesbar', 'information_schema' => 'information_schema', 'mandate_files' => 'mandate_files',
    ];
    return $map[$c] ?? e($c);
}

/**
 * Zeitreihe eines einzelnen Messwerts (monitor_checks.value_num) für ein Balkendiagramm im
 * Adminbereich Server (z.B. host_cpu, db_qps). Je Zeitfenster (bucketSeconds) der zuletzt
 * gemessene Wert; Zeitfenster ohne Messung erscheinen nicht (kein erfundener Nullwert).
 */
function monitor_view_series(string $component, int $from, int $to, int $bucketSeconds): array
{
    if (!monitor_available() || $bucketSeconds < 1) {
        return [];
    }
    $st = db()->prepare('SELECT checked_at, value_num FROM monitor_checks WHERE component = ? AND checked_at >= ? AND checked_at < ? ORDER BY checked_at ASC');
    $st->execute([$component, mon_utc($from), mon_utc($to)]);
    $buckets = [];
    foreach ($st->fetchAll() as $row) {
        if ($row['value_num'] === null) {
            continue;
        }
        $ts = mon_ts($row['checked_at']);
        if ($ts === null) {
            continue;
        }
        $slot = $ts - ($ts % $bucketSeconds);
        $buckets[$slot] = (float)$row['value_num']; // letzter Messwert je Zeitfenster gewinnt
    }
    ksort($buckets);
    $rows = [];
    foreach ($buckets as $slot => $v) {
        $rows[] = ['label' => date('H:i', $slot), 'value' => $v];
    }
    return $rows;
}

function monitor_ms(?int $ms): string
{
    return $ms === null ? 'Keine Daten' : number_format($ms, 0, ',', '.') . ' ms';
}

function monitor_pct(?float $pct): string
{
    return $pct === null ? 'Keine ausreichenden Messdaten' : number_format($pct, 3, ',', '.') . ' %';
}

function monitor_bytes(?int $bytes): string
{
    return $bytes === null ? 'Keine Daten' : number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

/** Tagesleiste (90 Tage): Zustand je Tag als Symbol und Farbe, nicht beobachtete Tage grau. */
function monitor_history_bar(array $days): string
{
    $out = '<div class="mon-history" role="img" aria-label="Tagesverlauf">';
    foreach ($days as $d) {
        $title = date('d.m.Y', strtotime($d['day'] . ' UTC')) . ': ' . ($d['state'] === 'nodata' ? 'Keine Daten' : (MONITOR_STATES[$d['state']] ?? $d['state']));
        $out .= '<span class="mon-day mon-day-' . e($d['state']) . '" title="' . e($title) . '"><span class="sr-only">' . e($title) . '</span></span>';
    }
    return $out . '</div>';
}

/** Kopfbereich: zusammengefasster Betriebszustand, Alter der Messwerte, Jobs, Warteschlange, Warnungen. */
function monitor_render_head(): string
{
    $cfg = monitor_config();
    $state = monitor_public_state();
    $overview = monitor_components_overview();
    $stats = monitor_job_stats(monitor_now() - 600, monitor_now());
    $queue = monitor_queue();
    $collectAt = mon_ts(monitor_mark_get('collect_last_at'));
    $collectAge = $collectAt !== null ? monitor_now() - $collectAt : null;
    $warnings = [];
    foreach ($overview as $c) {
        if (in_array($c['state'], ['fail', 'degraded'], true) || ($c['stale'] && !in_array($c['key'], ['sftp'], true))) {
            $warnings[] = $c['name'] . ': ' . ($c['stale'] ? 'Messung veraltet (' . mon_age_label($c['age']) . ')' : (monitor_category_label($c['reason']) ?: MONITOR_STATES[$c['state']]));
        }
    }
    ob_start();
    ?>
    <div class="mon-head" id="system-head" data-system-refresh="30" data-generated="<?= e(mon_iso(monitor_now())) ?>">
        <div class="mon-head-main">
            <div class="mon-overall">
                <?= monitor_state_badge($state['overall'], $state['overall_label']) ?>
                <?php if (!empty($state['uncertain'])): ?><span class="hint">Mindestens eine kritische Nutzerfunktion kann derzeit nicht geprüft werden.</span><?php endif; ?>
            </div>
            <div class="mon-meta">
                <span>Messwerte: <?= $collectAt !== null ? 'Sammler zuletzt ' . mon_age_label($collectAge) . ' (' . e(mon_local(monitor_mark_get('collect_last_at'))) . ')' : 'noch kein Sammellauf' ?><?php if ($collectAge !== null && $collectAge > $cfg['collect_interval_seconds'] * 3): ?> <strong>veraltet</strong><?php endif; ?></span>
                <span>Erfasster Umfang: eigene SmartEinzug-Jobs und instrumentierte PHP-Anfragen dieser Anwendung, keine fremden Prozesse des Hosts.</span>
            </div>
        </div>
        <div class="card-grid stat-row mon-stats">
            <div class="stat-card"><div class="stat-value"><?= (int)$stats['active_now'] ?></div><div class="stat-label">Aktive Jobs<span class="stat-sub">(laufend, Heartbeat frisch)</span></div></div>
            <div class="stat-card"><div class="stat-value"><?= (int)$stats['unconfirmed_now'] ?></div><div class="stat-label">Ausführung unbestätigt<span class="stat-sub">(Heartbeat abgelaufen)</span></div></div>
            <div class="stat-card"><div class="stat-value"><?= (int)$queue['sync_waiting'] + (int)$queue['collections_due'] ?></div><div class="stat-label">Wartende Aufgaben<span class="stat-sub">(<?= (int)$queue['sync_waiting'] ?> Sync, <?= (int)$queue['collections_due'] ?> fällige Einzüge<?= $queue['collections_oldest_age'] !== null ? ', älteste ' . e(mon_age_label((int)$queue['collections_oldest_age'])) : '' ?>)</span></div></div>
            <div class="stat-card"><div class="stat-value"><?= count($warnings) ?></div><div class="stat-label">Warnungen<span class="stat-sub">(Komponenten nicht in Ordnung oder veraltet)</span></div></div>
            <div class="stat-card"><div class="stat-value"><?= (int)$queue['incidents_open'] ?></div><div class="stat-label">Offene Störungen/Wartungen</div></div>
        </div>
        <?php if ($warnings): ?>
            <ul class="mon-warnings"><?php foreach ($warnings as $w): ?><li>▲ <?= e($w) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
