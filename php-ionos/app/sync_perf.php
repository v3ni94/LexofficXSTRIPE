<?php
/**
 * Kennzahlen der Rechnungssynchronisation fuer den Adminbereich System, Reiter „Synchronisation & Performance“ (4.39).
 *
 * Nur lesend. Datenquellen: sync_runs (je Lauf: Dauer, Aufrufe, Drosselung, Einzelabrufe, Cursorgroesse, laengster
 * Aufruf; Migration 029), job_runs (Wartezeit in der Warteschlange), worker_heartbeats (Auslastung je Pool),
 * api_circuits (Zustand der Anbindungen) und die wirksame Konfiguration (Drosselung, Seitengroesse, Fairness,
 * Vollabgleichsfenster). Zahlen, fuer die es keine Daten gibt, werden als „keine Daten“ ausgewiesen, nie geschaetzt.
 */
if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/lexoffice.php';

/** Zusammenfassung aller Laeufe der letzten $hours Stunden. */
function sync_perf_overview(int $hours): array
{
    $out = ['runs' => 0, 'success' => 0, 'failed' => 0, 'avg_duration_ms' => null, 'max_duration_ms' => null, 'api_calls' => 0, 'api_ms' => 0,
            'throttle_ms' => 0, 'retries' => 0, 'detail_calls' => 0, 'contact_calls' => 0, 'skipped' => 0, 'checked' => 0,
            'api_ms_max' => 0, 'cursor_bytes_max' => 0, 'avg_ms_per_call' => null, 'queue_wait_avg_ms' => null, 'queue_wait_max_ms' => null, 'queue_wait_n' => 0];
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) AS runs, SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed, AVG(duration_ms) AS avg_d, MAX(duration_ms) AS max_d,
                    COALESCE(SUM(api_calls),0) AS api_calls, COALESCE(SUM(api_ms),0) AS api_ms, COALESCE(SUM(throttle_ms),0) AS throttle_ms,
                    COALESCE(SUM(retries),0) AS retries, COALESCE(SUM(detail_calls),0) AS detail_calls, COALESCE(SUM(contact_calls),0) AS contact_calls,
                    COALESCE(SUM(skipped),0) AS skipped, COALESCE(SUM(checked),0) AS checked, COALESCE(MAX(api_ms_max),0) AS api_ms_max,
                    COALESCE(MAX(cursor_bytes_max),0) AS cursor_bytes_max
             FROM sync_runs WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) AND finished_at IS NOT NULL"
        );
        $st->execute([$hours]);
        $r = $st->fetch() ?: [];
        $out['runs'] = (int)($r['runs'] ?? 0);
        $out['success'] = (int)($r['ok'] ?? 0);
        $out['failed'] = (int)($r['failed'] ?? 0);
        $out['avg_duration_ms'] = $r['avg_d'] !== null ? (int)round((float)$r['avg_d']) : null;
        $out['max_duration_ms'] = $r['max_d'] !== null ? (int)$r['max_d'] : null;
        foreach (['api_calls', 'api_ms', 'throttle_ms', 'retries', 'detail_calls', 'contact_calls', 'skipped', 'checked', 'api_ms_max', 'cursor_bytes_max'] as $k) {
            $out[$k] = (int)($r[$k] ?? 0);
        }
        $out['avg_ms_per_call'] = $out['api_calls'] > 0 ? (int)round($out['api_ms'] / $out['api_calls']) : null;
    } catch (Throwable $e) {
        $out['error'] = 'sync_runs nicht lesbar (Migration 029?)';
    }
    try {
        $st = db()->prepare(
            "SELECT COUNT(queue_wait_ms) AS n, AVG(queue_wait_ms) AS avg_w, MAX(queue_wait_ms) AS max_w FROM job_runs
             WHERE job_type IN ('queue:sync_run', 'queue:sync_run_sevdesk') AND started_at >= ? AND queue_wait_ms IS NOT NULL"
        );
        $st->execute([mon_utc(monitor_now() - $hours * 3600)]);
        $r = $st->fetch() ?: [];
        $out['queue_wait_n'] = (int)($r['n'] ?? 0);
        $out['queue_wait_avg_ms'] = $out['queue_wait_n'] > 0 ? (int)round((float)$r['avg_w']) : null;
        $out['queue_wait_max_ms'] = $out['queue_wait_n'] > 0 ? (int)$r['max_w'] : null;
    } catch (Throwable $e) {
        // Spalte fehlt vor Migration 029: keine Daten
    }
    return $out;
}

/** Firmen mit dem groessten Aufwand (Aufrufe, Dauer) im Zeitraum. */
function sync_perf_top_tenants(int $hours, int $limit = 10): array
{
    try {
        $st = db()->prepare(
            "SELECT r.tenant_id, o.name AS org_name, COALESCE(i.invoice_source, 'lexware_office') AS invoice_source,
                    COUNT(*) AS runs, SUM(r.status = 'failed') AS failed, SUM(r.duration_ms) AS duration_ms, SUM(r.api_calls) AS api_calls,
                    SUM(r.detail_calls) AS detail_calls, SUM(r.contact_calls) AS contact_calls, SUM(r.skipped) AS skipped, SUM(r.checked) AS checked,
                    SUM(r.throttle_ms) AS throttle_ms, MAX(r.api_ms_max) AS api_ms_max, MAX(r.cursor_bytes_max) AS cursor_bytes_max, MAX(r.finished_at) AS last_finished
             FROM sync_runs r JOIN organizations o ON o.id = r.tenant_id LEFT JOIN integrations i ON i.tenant_id = r.tenant_id
             WHERE r.started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) AND r.finished_at IS NOT NULL
             GROUP BY r.tenant_id, o.name, i.invoice_source ORDER BY api_calls DESC, duration_ms DESC LIMIT " . max(1, min(50, $limit))
        );
        $st->execute([$hours]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Auslastung der Worker je Pool (Momentaufnahme aus worker_heartbeats). */
function sync_perf_workers(): array
{
    $out = [];
    foreach (workers_list() as $w) {
        $pool = (string)$w['pool'];
        $out[$pool] = $out[$pool] ?? ['alive' => 0, 'busy' => 0, 'idle' => 0, 'stopping' => 0, 'jobs_done' => 0, 'jobs_failed' => 0];
        if (!$w['alive']) {
            continue;
        }
        $out[$pool]['alive']++;
        $status = (string)$w['status'];
        if (isset($out[$pool][$status])) {
            $out[$pool][$status]++;
        }
        $out[$pool]['jobs_done'] += (int)$w['jobs_done'];
        $out[$pool]['jobs_failed'] += (int)$w['jobs_failed'];
    }
    ksort($out);
    return $out;
}

/** Verteilung des naechtlichen Vollabgleichs: Anzahl Firmen je geplanter Stunde (Fenster ab full_sync_hour). */
function sync_perf_full_sync_plan(array $cfg): array
{
    $plan = [];
    try {
        $rows = db()->query(
            "SELECT o.id FROM organizations o JOIN integrations i ON i.tenant_id = o.id
             WHERE o.deleted_at IS NULL AND o.onboarding_completed = 1 AND (i.lexoffice_connected = 1 OR i.sevdesk_connected = 1)"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as $tid) {
        $h = scheduler_full_sync_hour((string)$tid, $cfg);
        $plan[$h] = ($plan[$h] ?? 0) + 1;
    }
    ksort($plan);
    return $plan;
}

/** Wirksame Konfiguration mit Herkunft (Vorgabe oder config.php) fuer die Anzeige. */
function sync_perf_config(): array
{
    $q = (array)config('queue', []);
    $s = (array)config('sync', []);
    $cfg = jobs_config();
    return [
        ['Drosselung Lexware je Firma', (string)($q['lexoffice_per_second'] ?? 2) . ' Aufrufe/s (zusätzlich 0,6 s Mindestabstand im Client)', 'Annahme 2/s, nicht am Primärtext verifiziert'],
        ['Drosselung Lexware gesamt', (string)($q['lexoffice_global_per_second'] ?? 50) . ' Aufrufe/s über alle Firmen', 'Schutz der eigenen Worker'],
        ['Drosselung sevdesk je Konto', (string)($q['sevdesk_per_second'] ?? 2) . ' Aufrufe/s, gesamt ' . (string)($q['sevdesk_global_per_second'] ?? 20) . '/s', 'Annahme, sevdesk nennt keine Grenze'],
        ['Seitengröße Belegliste (Lexware)', LexofficeClient::pageSize() . ' Einträge', 'sync.page_size, 1 bis 250, Maximum der API zu verifizieren'],
        ['Zeitbudget je Verarbeitungsversuch', $cfg['sync_attempt_seconds'] . ' s, höchstens ' . $cfg['sync_max_steps_attempt'] . ' Schritte', 'queue.sync_attempt_seconds'],
        ['Fairness zwischen Firmen', $cfg['sync_fair_seconds'] > 0 ? 'nach ' . $cfg['sync_fair_seconds'] . ' s Worker abgeben, wenn andere Firmen warten' : 'aus', 'queue.sync_fair_seconds'],
        ['Delta-Abgleich', 'alle ' . $cfg['auto_sync_hours'] . ' Stunden je Firma', 'queue.auto_sync_hours'],
        ['Vollabgleich', 'ab ' . $cfg['full_sync_hour'] . ':00 Uhr, verteilt über ' . $cfg['full_sync_window_hours'] . ' Stunde(n)', 'queue.full_sync_hour, full_sync_window_hours'],
        ['Änderungserkennung', !empty($s['skip_unchanged'] ?? true) ? 'aktiv (unveränderte Rechnungen ohne Detailabruf)' : 'aus', 'sync.skip_unchanged'],
        ['Kontaktaktualisierung', (string)($s['contact_refresh_hours'] ?? 24) . ' h', 'sync.contact_refresh_hours'],
        ['Schrittbudget', (string)($s['step_seconds'] ?? 8) . ' s, höchstens ' . (string)($s['step_max'] ?? 40) . ' Rechnungen, ' . (string)($s['step_max_api_calls'] ?? 60) . ' Aufrufe', 'sync.step_*'],
    ];
}

/** Zustaende der Circuit Breaker (api_circuits). */
function sync_perf_circuits(): array
{
    try {
        return db()->query('SELECT api, state, failures, opened_at, updated_at FROM api_circuits ORDER BY api')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function sync_perf_fmt_ms(?int $ms): string
{
    if ($ms === null) {
        return 'keine Daten';
    }
    if ($ms >= 60000) {
        return number_format($ms / 60000, 1, ',', '.') . ' min';
    }
    if ($ms >= 1000) {
        return number_format($ms / 1000, 1, ',', '.') . ' s';
    }
    return $ms . ' ms';
}

function sync_perf_fmt_bytes(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : ($b >= 1024 ? number_format($b / 1024, 0, ',', '.') . ' KB' : $b . ' B');
}

/** HTML des Reiters. */
function sync_perf_render(): string
{
    $cfg = jobs_config();
    $o24 = sync_perf_overview(24);
    $o7 = sync_perf_overview(168);
    $top = sync_perf_top_tenants(168, 10);
    $workers = sync_perf_workers();
    $plan = sync_perf_full_sync_plan($cfg);
    $circuits = sync_perf_circuits();
    ob_start();
    ?>
<div class="card">
    <h2>Synchronisation &amp; Performance</h2>
    <p class="hint">Messwerte aus abgeschlossenen Synchronisationsläufen (Tabelle sync_runs) und der Warteschlange. Grundlage der Performance-Überarbeitung
        (Bestandsaufnahme 07.09.2026). Felder ohne Daten zeigen „keine Daten“; nichts wird geschätzt.</p>
    <?php if (!empty($o24['error'])): ?><div class="flash flash-warn"><?= e($o24['error']) ?></div><?php endif; ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Kennzahl</th><th>Letzte 24 Stunden</th><th>Letzte 7 Tage</th><th>Erläuterung</th></tr></thead>
        <tbody>
        <tr><td>Läufe (erfolgreich / fehlgeschlagen)</td><td><?= (int)$o24['runs'] ?> (<?= (int)$o24['success'] ?> / <?= (int)$o24['failed'] ?>)</td><td><?= (int)$o7['runs'] ?> (<?= (int)$o7['success'] ?> / <?= (int)$o7['failed'] ?>)</td><td class="hint">abgeschlossene Läufe aller Firmen</td></tr>
        <tr><td>Dauer je Lauf (Durchschnitt / Maximum)</td><td><?= e(sync_perf_fmt_ms($o24['avg_duration_ms'])) ?> / <?= e(sync_perf_fmt_ms($o24['max_duration_ms'])) ?></td><td><?= e(sync_perf_fmt_ms($o7['avg_duration_ms'])) ?> / <?= e(sync_perf_fmt_ms($o7['max_duration_ms'])) ?></td><td class="hint">von Start bis Abschluss, inklusive Wartezeiten zwischen Versuchen</td></tr>
        <tr><td>API-Aufrufe gesamt</td><td><?= number_format($o24['api_calls'], 0, ',', '.') ?></td><td><?= number_format($o7['api_calls'], 0, ',', '.') ?></td><td class="hint">Liste, Detail, Kontakt, Zahlungsstand</td></tr>
        <tr><td>Detailabrufe / Kontaktabrufe</td><td><?= number_format($o24['detail_calls'], 0, ',', '.') ?> / <?= number_format($o24['contact_calls'], 0, ',', '.') ?></td><td><?= number_format($o7['detail_calls'], 0, ',', '.') ?> / <?= number_format($o7['contact_calls'], 0, ',', '.') ?></td><td class="hint">Einzelabrufe je Rechnung bzw. Kunde (größter Kostenblock, Engpass 1 und 2)</td></tr>
        <tr><td>Übersprungen dank Änderungserkennung</td><td><?= number_format($o24['skipped'], 0, ',', '.') ?> von <?= number_format($o24['checked'] + $o24['skipped'], 0, ',', '.') ?></td><td><?= number_format($o7['skipped'], 0, ',', '.') ?> von <?= number_format($o7['checked'] + $o7['skipped'], 0, ',', '.') ?></td><td class="hint">unveränderte Rechnungen ohne Detailabruf</td></tr>
        <tr><td>Mittlere Antwortzeit je Aufruf / längster Aufruf</td><td><?= e(sync_perf_fmt_ms($o24['avg_ms_per_call'])) ?> / <?= e(sync_perf_fmt_ms($o24['api_ms_max'] ?: null)) ?></td><td><?= e(sync_perf_fmt_ms($o7['avg_ms_per_call'])) ?> / <?= e(sync_perf_fmt_ms($o7['api_ms_max'] ?: null)) ?></td><td class="hint">reine HTTP-Zeit; Maximum seit Migration 029</td></tr>
        <tr><td>Wartezeit durch Drosselung</td><td><?= e(sync_perf_fmt_ms($o24['throttle_ms'])) ?></td><td><?= e(sync_perf_fmt_ms($o7['throttle_ms'])) ?></td><td class="hint">Summe der Pausen vor Aufrufen (Mindestabstand und Ratenbegrenzung)</td></tr>
        <tr><td>Wiederholungen (429, 5xx)</td><td><?= (int)$o24['retries'] ?></td><td><?= (int)$o7['retries'] ?></td><td class="hint">viele Wiederholungen deuten auf eine zu hohe Rate hin</td></tr>
        <tr><td>Wartezeit in der Warteschlange (Durchschnitt / Maximum)</td><td><?= e(sync_perf_fmt_ms($o24['queue_wait_avg_ms'])) ?> / <?= e(sync_perf_fmt_ms($o24['queue_wait_max_ms'])) ?> (n=<?= (int)$o24['queue_wait_n'] ?>)</td><td><?= e(sync_perf_fmt_ms($o7['queue_wait_avg_ms'])) ?> / <?= e(sync_perf_fmt_ms($o7['queue_wait_max_ms'])) ?> (n=<?= (int)$o7['queue_wait_n'] ?>)</td><td class="hint">Fälligkeit bis Reservierung durch einen Worker; steigt sie, fehlen Worker (Engpass 9)</td></tr>
        <tr><td>Größter Cursor</td><td><?= e(sync_perf_fmt_bytes($o24['cursor_bytes_max'])) ?></td><td><?= e(sync_perf_fmt_bytes($o7['cursor_bytes_max'])) ?></td><td class="hint">Zwischenstand eines Laufs in sync_state (Engpass 14)</td></tr>
        </tbody></table></div>
</div>

<div class="card">
    <h2>Worker je Pool (Momentaufnahme)</h2>
    <?php if (!$workers): ?><p class="hint">Keine Worker gemeldet (Warteschlange inaktiv oder Container gestoppt).</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Pool</th><th>Aktiv</th><th>Beschäftigt</th><th>Frei</th><th>Wird beendet</th><th>Jobs erledigt</th><th>Jobs fehlgeschlagen</th></tr></thead>
        <tbody><?php foreach ($workers as $pool => $w): ?>
        <tr><td><?= e($pool) ?></td><td><?= (int)$w['alive'] ?></td><td><?= (int)$w['busy'] ?></td><td><?= (int)$w['idle'] ?></td><td><?= (int)$w['stopping'] ?></td><td><?= (int)$w['jobs_done'] ?></td><td><?= (int)$w['jobs_failed'] ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <p class="hint">Sind alle Worker eines Pools dauerhaft beschäftigt und wächst die Wartezeit in der Warteschlange, ist der Pool zu klein: weiteren Worker-Container in deploy/vps/docker-compose.yml ergänzen (docs/vps/06-betrieb.md, Worker skalieren). Dabei die globale Drosselung beachten.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Firmen mit dem größten Aufwand (7 Tage)</h2>
    <?php if (!$top): ?><p class="hint">Keine abgeschlossenen Läufe im Zeitraum.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>System</th><th>Läufe</th><th>Fehler</th><th>Dauer gesamt</th><th>API-Aufrufe</th><th>Detail / Kontakt</th><th>Übersprungen</th><th>Drosselung</th><th>Längster Aufruf</th><th>Cursor</th><th>Zuletzt</th></tr></thead>
        <tbody><?php foreach ($top as $t): ?>
        <tr><td><?= e((string)$t['org_name']) ?></td><td><?= e(invoice_source_label((string)$t['invoice_source'])) ?></td><td><?= (int)$t['runs'] ?></td><td><?= (int)$t['failed'] ?></td>
            <td><?= e(sync_perf_fmt_ms((int)$t['duration_ms'])) ?></td><td><?= number_format((int)$t['api_calls'], 0, ',', '.') ?></td><td><?= (int)$t['detail_calls'] ?> / <?= (int)$t['contact_calls'] ?></td>
            <td><?= (int)$t['skipped'] ?></td><td><?= e(sync_perf_fmt_ms((int)$t['throttle_ms'])) ?></td><td><?= e(sync_perf_fmt_ms((int)$t['api_ms_max'] ?: null)) ?></td><td><?= e(sync_perf_fmt_bytes((int)$t['cursor_bytes_max'])) ?></td><td><?= e(format_datetime($t['last_finished'])) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Wirksame Konfiguration</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Größe</th><th>Wert</th><th>Quelle / Hinweis</th></tr></thead>
        <tbody><?php foreach (sync_perf_config() as [$k, $v, $h]): ?>
        <tr><td><?= e($k) ?></td><td><?= e($v) ?></td><td class="hint"><?= e($h) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <h3 class="mon-h3">Verteilung des nächtlichen Vollabgleichs</h3>
    <?php if (!$plan): ?><p class="hint">Keine Firma mit verbundenem Buchhaltungssystem.</p><?php else: ?>
    <p class="hint"><?php foreach ($plan as $h => $n): ?><span class="badge"><?= (int)$h ?>:00 Uhr: <?= (int)$n ?> Firma<?= $n === 1 ? '' : 'en' ?></span> <?php endforeach; ?></p>
    <p class="hint">Jede Firma behält ihre Stunde (stabiler Versatz aus der Firmenkennung). Fenster: queue.full_sync_window_hours.</p>
    <?php endif; ?>
    <?php if ($circuits): ?>
    <h3 class="mon-h3">Circuit Breaker</h3>
    <div class="table-wrap"><table><thead><tr><th>Anbindung</th><th>Zustand</th><th>Fehler in Folge</th><th>Geöffnet seit</th><th>Aktualisiert</th></tr></thead>
        <tbody><?php foreach ($circuits as $c): ?><tr><td><?= e((string)$c['api']) ?></td><td><?= e((string)$c['state']) ?></td><td><?= (int)$c['failures'] ?></td><td><?= $c['opened_at'] ? e(mon_local((string)$c['opened_at'])) : '-' ?></td><td><?= e(mon_local((string)$c['updated_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <p class="hint">Bewusst nicht umgesetzt (Stand 4.39): Lexware-Webhooks (Signaturverfahren und Ereigniskatalog nicht am Primärtext verifiziert), Sammelabrufe (kein belegter Endpunkt), Erhöhung der Seitengröße über 100 (Maximum unverifiziert). Details und Prüffragen: Entwicklerdokumentation, Kapitel Synchronisation und Performance.</p>
</div>
    <?php
    return (string)ob_get_clean();
}
