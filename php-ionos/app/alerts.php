<?php
/**
 * Alarmierung: Zustände, die ein Eingreifen erfordern, je Firma und
 * plattformweit. Reine Leseprüfungen ohne Nebenwirkungen; die Ausgabe enthält
 * keine Zugangsdaten oder Geheimnisse.
 *
 * Stufen: 'hoch' (per Cron einmal je Kalendertag an den Inhaber gemailt),
 * 'mittel' (nur Anzeige).
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/collections.php';

/** Grenze in Stunden, ab der eine fehlende Synchronisation als Alarm gilt. */
const ALERT_SYNC_MAX_HOURS = 48;

/**
 * Alarme einer Firma.
 *
 * @return array<int, array{level:string,text:string,link:string}>
 */
function alerts_for_tenant(string $tenantId): array
{
    $pdo = db();
    $alerts = [];

    // 1. Letzte Synchronisation älter als 48 Stunden bei verbundenem Lexware Office
    // Alter der Synchronisation in der Datenbank berechnen (gleiche Zeitbasis wie NOW() beim Speichern)
    $stmt = $pdo->prepare(
        'SELECT lexoffice_connected, lexoffice_last_sync, stripe_connected, stripe_webhook_secret_encrypted,
                TIMESTAMPDIFF(HOUR, lexoffice_last_sync, NOW()) AS sync_age_hours
         FROM integrations WHERE tenant_id = ?'
    );
    $stmt->execute([$tenantId]);
    $integration = $stmt->fetch() ?: [];
    if ((int)($integration['lexoffice_connected'] ?? 0) === 1) {
        $last = !empty($integration['lexoffice_last_sync']);
        $age = $integration['sync_age_hours'] !== null ? (int)$integration['sync_age_hours'] : null;
        if (!$last || $age === null || $age >= ALERT_SYNC_MAX_HOURS) {
            $alerts[] = [
                'level' => 'hoch',
                'text'  => $last
                    ? sprintf('Die letzte Synchronisation mit Lexware Office liegt mehr als %d Stunden zurück (Stand %s). Rechnungsstatus und Restbeträge können veraltet sein.', ALERT_SYNC_MAX_HOURS, format_datetime((string)$integration['lexoffice_last_sync']))
                    : 'Lexware Office ist verbunden, es wurde aber noch keine Synchronisation abgeschlossen.',
                'link'  => 'invoices.php',
            ];
        }
    }

    // 2. Unklare Einzugsversuche
    $open = collection_attempts_open($tenantId);
    if ($open) {
        $alerts[] = [
            'level' => 'hoch',
            'text'  => sprintf('%d Einzugsversuch(e) mit unklarem Ergebnis. Die betroffenen Rechnungen werden bis zur Klärung nicht erneut eingereicht.', count($open)),
            'link'  => 'collections.php',
        ];
    }

    // 3. Not-Stopp (Firma oder Plattform)
    if ($reason = collections_pause_reason($tenantId)) {
        $alerts[] = ['level' => 'mittel', 'text' => $reason, 'link' => 'notstopp.php'];
    }

    // 4. Stripe verbunden, aber kein Webhook-Secret
    if ((int)($integration['stripe_connected'] ?? 0) === 1 && empty($integration['stripe_webhook_secret_encrypted'])) {
        $alerts[] = [
            'level' => 'mittel',
            'text'  => 'Stripe ist verbunden, aber es ist kein Webhook-Secret hinterlegt. Rücklastschriften und Erstattungen werden dann nicht automatisch erkannt.',
            'link'  => 'settings.php',
        ];
    }

    // 5. Terminierte Einzüge mit Fälligkeit in der Vergangenheit
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM payment_collections
         WHERE tenant_id = ? AND is_scheduled = 1 AND scheduled_submitted = 0 AND stripe_status = 'scheduled' AND scheduled_date < CURDATE()"
    );
    $stmt->execute([$tenantId]);
    $overdue = (int)$stmt->fetchColumn();
    if ($overdue > 0) {
        $alerts[] = [
            'level' => 'mittel',
            'text'  => sprintf('%d terminierte(r) Einzug/Einzüge mit Fälligkeit in der Vergangenheit wurde(n) noch nicht eingereicht (Einreichfenster abwarten, Cron prüfen; überfällige Termine unter Einzüge neu terminieren oder stornieren).', $overdue),
            'link'  => 'collections.php',
        ];
    }

    // 6. Rechnungen mit Klärungsbedarf (z. B. nach Erstattung)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND requires_review = 1');
    $stmt->execute([$tenantId]);
    $review = (int)$stmt->fetchColumn();
    if ($review > 0) {
        $alerts[] = [
            'level' => 'mittel',
            'text'  => sprintf('%d Rechnung(en) mit Klärungsbedarf (z. B. nach Erstattung). Sie werden nicht eingezogen, bis die Klärung abgeschlossen ist.', $review),
            'link'  => 'invoices.php',
        ];
    }

    return $alerts;
}

/** Nur Alarme der Stufe 'hoch'. */
function alerts_high(array $alerts): array
{
    return array_values(array_filter($alerts, fn(array $a) => ($a['level'] ?? '') === 'hoch'));
}

/**
 * Plattformweite Alarme für admin.php.
 *
 * @return array<int, array{level:string,text:string,link:string}>
 */
function alerts_platform(): array
{
    $pdo = db();
    $alerts = [];

    if (platform_collections_paused()) {
        $alerts[] = ['level' => 'mittel', 'text' => 'Plattformweiter Not-Stopp aktiv: keine Firma reicht neue Einzüge ein.', 'link' => '#notstopp'];
    }

    $stale = (int)$pdo->query(
        "SELECT COUNT(*) FROM integrations i JOIN organizations o ON o.id = i.tenant_id AND o.deleted_at IS NULL
         WHERE i.lexoffice_connected = 1 AND (i.lexoffice_last_sync IS NULL OR i.lexoffice_last_sync < DATE_SUB(NOW(), INTERVAL " . ALERT_SYNC_MAX_HOURS . " HOUR))"
    )->fetchColumn();
    if ($stale > 0) {
        $alerts[] = ['level' => 'hoch', 'text' => sprintf('%d Firma/Firmen mit verbundenem Lexware Office ohne Synchronisation in den letzten %d Stunden.', $stale, ALERT_SYNC_MAX_HOURS), 'link' => '#firmen'];
    }

    $attempts = (int)$pdo->query("SELECT COUNT(*) FROM collection_attempts WHERE status IN ('pending', 'unknown')")->fetchColumn();
    if ($attempts > 0) {
        $alerts[] = ['level' => 'hoch', 'text' => sprintf('%d offene unklare Einzugsversuch(e) über alle Firmen (Status pending oder unknown).', $attempts), 'link' => '#firmen'];
    }

    // Webhook-Ereignisse mit Fehlern: nur, wenn die Tabelle eine Fehlerspalte hat
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM webhook_events")->fetchAll(PDO::FETCH_COLUMN);
        $errCol = null;
        foreach (['error', 'error_text', 'last_error'] as $c) {
            if (in_array($c, $cols, true)) {
                $errCol = $c;
                break;
            }
        }
        if ($errCol !== null) {
            $errs = (int)$pdo->query("SELECT COUNT(*) FROM webhook_events WHERE `$errCol` IS NOT NULL AND `$errCol` <> ''")->fetchColumn();
            if ($errs > 0) {
                $alerts[] = ['level' => 'mittel', 'text' => sprintf('%d Webhook-Ereignis(se) mit Fehler.', $errs), 'link' => '#firmen'];
            }
        }
    } catch (Throwable $e) {
        // Tabelle fehlt oder nicht lesbar: weglassen
    }

    return $alerts;
}

/**
 * Cron: einmal je Kalendertag pro Firma eine E-Mail an den Inhaber, wenn
 * Alarme der Stufe 'hoch' vorliegen. Merker in platform_settings
 * (alerts_sent_<tenant> = Datum). Ohne aktiven Mailversand wird nichts
 * versendet und kein Merker gesetzt.
 *
 * @return array{checked:int,sent:int,skipped:int}
 */
function alerts_cron_notify(): array
{
    require_once __DIR__ . '/mailer.php';
    $result = ['checked' => 0, 'sent' => 0, 'skipped' => 0];
    if (!mail_enabled()) {
        return $result;
    }
    $pdo = db();
    $today = date('Y-m-d');
    $orgs = $pdo->query('SELECT id, name FROM organizations WHERE deleted_at IS NULL')->fetchAll();
    foreach ($orgs as $org) {
        $result['checked']++;
        $tenantId = (string)$org['id'];
        $key = 'alerts_sent_' . $tenantId;
        if (platform_setting($key) === $today) {
            $result['skipped']++;
            continue;
        }
        $high = alerts_high(alerts_for_tenant($tenantId));
        if (!$high) {
            continue;
        }
        $stmt = $pdo->prepare(
            "SELECT u.email FROM organization_members m JOIN users u ON u.id = m.user_id
             WHERE m.organization_id = ? AND m.role = 'owner' AND m.status = 'active' AND u.is_active = 1 LIMIT 1"
        );
        $stmt->execute([$tenantId]);
        $ownerEmail = (string)($stmt->fetchColumn() ?: '');
        if ($ownerEmail === '') {
            $result['skipped']++;
            continue;
        }
        $lines = [sprintf('Für den Firmenaccount "%s" liegen Hinweise vor, die eine Prüfung erfordern:', $org['name'])];
        foreach ($high as $a) {
            $lines[] = $a['text'];
        }
        $lines[] = 'Bitte melden Sie sich an und prüfen Sie Dashboard, Rechnungen und Einzüge. Diese Nachricht wird höchstens einmal je Kalendertag versendet.';
        $tpl = mail_layout('Hinweise zu Ihrem Firmenaccount', $lines, ['label' => 'Zum Dashboard', 'url' => app_base_url() . '/dashboard.php'], $org['name']);
        if (mail_send($ownerEmail, 'Hinweise zu ' . $org['name'], $tpl['text'], $tpl['html'])) {
            platform_setting_set($key, $today);
            audit_log($tenantId, ['user_id' => null, 'email' => 'cron'], 'alerts_mailed', 'organization', $tenantId, [
                'count' => count($high), 'levels' => 'hoch',
            ]);
            $result['sent']++;
        } else {
            $result['skipped']++;
        }
    }
    return $result;
}
