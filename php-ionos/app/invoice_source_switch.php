<?php
/**
 * Buchhaltungssystem je Firma: Anzeige, Auswahl bei der Registrierung und Wechsel mit Sperrfrist (Migration 024).
 *
 * Grundsatz: genau EIN Buchhaltungssystem je Firma (integrations.invoice_source). Ein Wechsel ist erlaubt, danach gilt
 * eine Sperre von vier Wochen (INVOICE_SOURCE_LOCK_DAYS, entspricht der Abrechnungsperiode), damit niemand mit einem
 * Abonnement zwei Buchhaltungen abwechselnd bedient. Das Abonnement bleibt beim Wechsel unveraendert (gleiche Tarife
 * fuer beide Systeme, Tabelle plans). Wechseln duerfen Inhaber und Administratoren mit aktuellem 2FA-Code.
 *
 * Wirkung eines Wechsels: Die Verbindung zum bisherigen System wird getrennt (Schluessel geloescht, keine weitere
 * Synchronisation), vorhandene Rechnungen, Kunden, Mandate und Einzuege bleiben als Historie erhalten. Gesperrt ist der
 * Wechsel, solange Einzuege vorgemerkt, terminiert oder in Verarbeitung sind oder eine Synchronisation laeuft.
 */
declare(strict_types=1);

require_once __DIR__ . '/invoice_source.php';
require_once __DIR__ . '/integration_state.php';
require_once __DIR__ . '/audit.php';

const INVOICE_SOURCE_LOCK_DAYS = 28;
const INVOICE_SOURCE_CODES = ['lexware_office', 'sevdesk'];

/** Anzeigename eines Buchhaltungssystems. */
function invoice_source_label(string $code): string
{
    $p = integration_provider($code);
    return (string)($p['name'] ?? ($code === 'sevdesk' ? 'sevdesk' : 'Lexware Office'));
}

/** Aktuelles System der Firma mit Metadaten. */
function invoice_source_current(string $tenantId): array
{
    $st = db()->prepare('SELECT invoice_source, invoice_source_changed_at, invoice_source_switches FROM integrations WHERE tenant_id = ?');
    $st->execute([$tenantId]);
    $row = $st->fetch() ?: [];
    $code = (string)($row['invoice_source'] ?? 'lexware_office');
    return [
        'code' => $code,
        'label' => invoice_source_label($code),
        'changed_at' => $row['invoice_source_changed_at'] ?? null,
        'switches' => (int)($row['invoice_source_switches'] ?? 0),
    ];
}

/** Ist das Zielsystem fuer Firmen freigegeben? Lexware Office immer; sevdesk nur mit Schalter sevdesk_connect. */
function invoice_source_available(string $code): bool
{
    if ($code === 'lexware_office') {
        return true;
    }
    if ($code === 'sevdesk') {
        return integration_switch('sevdesk', 'connect');
    }
    return false;
}

/** Sperre: ['locked' => bool, 'until' => ?string (UTC), 'days_left' => int]. */
function invoice_source_lock(string $tenantId): array
{
    $cur = invoice_source_current($tenantId);
    if (empty($cur['changed_at'])) {
        return ['locked' => false, 'until' => null, 'days_left' => 0];
    }
    $changed = new DateTimeImmutable((string)$cur['changed_at'], new DateTimeZone('UTC'));
    $until = $changed->modify('+' . INVOICE_SOURCE_LOCK_DAYS . ' days');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $locked = $until > $now;
    $daysLeft = $locked ? (int)ceil(($until->getTimestamp() - $now->getTimestamp()) / 86400) : 0;
    return ['locked' => $locked, 'until' => $until->format('Y-m-d H:i:s'), 'days_left' => $daysLeft];
}

/** Anzahl Einzuege, die einen Wechsel verhindern (vorgemerkt, terminiert, in Einreichung oder Verarbeitung). */
function invoice_source_open_collections(string $tenantId): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM payment_collections WHERE tenant_id = ? AND stripe_status IN ('scheduled', 'submitting', 'processing')");
        $st->execute([$tenantId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Prueft, ob die Firma jetzt zum Zielsystem wechseln darf. Liefert null (erlaubt) oder den Hinderungsgrund.
 */
function invoice_source_switch_blocker(string $tenantId, string $target): ?string
{
    if (!in_array($target, INVOICE_SOURCE_CODES, true)) {
        return 'Unbekanntes Buchhaltungssystem.';
    }
    $cur = invoice_source_current($tenantId);
    if ($cur['code'] === $target) {
        return 'Dieses Buchhaltungssystem ist bereits eingestellt.';
    }
    if (!invoice_source_available($target)) {
        return invoice_source_label($target) . ' ist noch nicht für Firmen freigegeben. Sie können sich unverbindlich vormerken lassen.';
    }
    $lock = invoice_source_lock($tenantId);
    if ($lock['locked']) {
        return sprintf('Nach einem Wechsel gilt eine Sperre von vier Wochen. Der nächste Wechsel ist ab dem %s möglich (noch %d Tag(e)).',
            format_date($lock['until']), $lock['days_left']);
    }
    $open = invoice_source_open_collections($tenantId);
    if ($open > 0) {
        return sprintf('%d Einzug/Einzüge sind noch vorgemerkt, terminiert oder in Verarbeitung. Bitte warten Sie den Abschluss ab oder stornieren Sie vorgemerkte Einzüge, bevor Sie wechseln.', $open);
    }
    try {
        require_once __DIR__ . '/sync_state.php';
        if (function_exists('sync_state_get') && sync_state_is_running(sync_state_get($tenantId))) {
            return 'Eine Synchronisation läuft gerade. Bitte warten Sie, bis sie abgeschlossen ist.';
        }
    } catch (Throwable $e) {
        // ohne Synchronisationszustand kein Hindernis
    }
    return null;
}

/**
 * Wechsel ausfuehren. Voraussetzungen (Rolle, 2FA) prueft die Seite; hier werden Hinderungsgruende erneut geprueft.
 * Trennt die Verbindung zum bisherigen System, setzt Sperrfrist und Zaehler, schreibt ins Audit.
 */
function invoice_source_switch(array $ctx, string $target, string $reason = ''): void
{
    if (!in_array((string)($ctx['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new RuntimeException('Das Buchhaltungssystem dürfen nur Inhaber und Administratoren wechseln.');
    }
    $tenantId = (string)$ctx['org_id'];
    if ($blocker = invoice_source_switch_blocker($tenantId, $target)) {
        throw new RuntimeException($blocker);
    }
    $cur = invoice_source_current($tenantId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE integrations SET invoice_source = ?, invoice_source_changed_at = UTC_TIMESTAMP(), invoice_source_switches = invoice_source_switches + 1 WHERE tenant_id = ?')
            ->execute([$target, $tenantId]);
        if ($cur['code'] === 'lexware_office') {
            // Verbindung zum bisherigen System trennen: kein weiterer Abruf, Schluessel geloescht (Datenminimierung).
            $pdo->prepare('UPDATE integrations SET lexoffice_api_key_encrypted = NULL, lexoffice_connected = 0, lexoffice_disconnected_at = NOW() WHERE tenant_id = ?')
                ->execute([$tenantId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_log($tenantId, $ctx, 'invoice_source_switched', 'integration', $tenantId, [
        'from' => $cur['code'], 'to' => $target, 'reason' => mb_substr(trim($reason), 0, 200), 'lock_days' => INVOICE_SOURCE_LOCK_DAYS,
    ]);
}

/** Bei der Registrierung gewaehltes System setzen (nur feste Liste, sevdesk nur bei Freigabe). */
function invoice_source_apply_signup(string $tenantId, ?string $integration): void
{
    $code = (string)$integration;
    if ($code === '' || $code === 'lexware_office' || !in_array($code, INVOICE_SOURCE_CODES, true) || !invoice_source_available($code)) {
        return;
    }
    db()->prepare('UPDATE integrations SET invoice_source = ? WHERE tenant_id = ?')->execute([$code, $tenantId]);
}
