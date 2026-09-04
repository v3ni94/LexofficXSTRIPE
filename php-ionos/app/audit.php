<?php
/**
 * Audit-Log und Funnel-Ereignisse.
 *
 * Jede sicherheits- oder geldrelevante Aktion wird mit Benutzer, Firma,
 * Zeitpunkt und IP-Adresse protokolliert. Einträge werden nie gelöscht,
 * auch nicht, wenn Benutzer oder Firmen entfernt werden (keine
 * Fremdschlüssel auf audit_log). Fehler beim Protokollieren dürfen die
 * eigentliche Aktion nie abbrechen.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** IP-Adresse des Aufrufers (bei IONOS direkt REMOTE_ADDR, kein Proxy). */
function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    return substr($ip, 0, 45);
}

/**
 * Audit-Eintrag schreiben.
 *
 * @param array $details Beliebige Zusatzangaben (werden als JSON gespeichert).
 *                       Keine Passwörter, Codes, API-Keys oder vollständigen IBANs übergeben.
 */
function audit_log(
    ?string $tenantId,
    ?array $actor,
    string $action,
    ?string $targetType = null,
    ?string $targetId = null,
    array $details = []
): void {
    try {
        db()->prepare(
            'INSERT INTO audit_log (tenant_id, user_id, user_email, action, target_type, target_id, details_json, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tenantId,
            $actor['user_id'] ?? null,
            isset($actor['email']) ? mb_substr((string)$actor['email'], 0, 255) : null,
            mb_substr($action, 0, 60),
            $targetType !== null ? mb_substr($targetType, 0, 40) : null,
            $targetId !== null ? mb_substr($targetId, 0, 64) : null,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('Audit-Log fehlgeschlagen (' . $action . '): ' . $e->getMessage());
    }
}

/** Letzte Audit-Einträge einer Firma (für die Anzeige unter "Firma"). */
function audit_recent(string $tenantId, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT * FROM audit_log WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))
    );
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

/** Lesbare Bezeichnung für eine Audit-Aktion. */
function audit_action_label(string $action): string
{
    static $map = [
        'login_success'            => 'Anmeldung',
        'login_failed'             => 'Anmeldung fehlgeschlagen',
        'login_locked'             => 'Konto vorübergehend gesperrt',
        'logout'                   => 'Abmeldung',
        'register'                 => 'Firma registriert',
        'email_verified'           => 'E-Mail-Adresse bestätigt',
        'password_changed'         => 'Passwort geändert',
        'password_reset_requested' => 'Passwort-Zurücksetzung angefordert',
        'password_reset_done'      => 'Passwort zurückgesetzt',
        '2fa_enabled'              => '2FA eingerichtet',
        '2fa_reset'                => '2FA zurückgesetzt',
        '2fa_admin_reset'          => '2FA durch Support zurückgesetzt',
        'recovery_code_used'       => 'Recovery-Code verwendet',
        'recovery_codes_regenerated' => 'Recovery-Codes neu erzeugt',
        'invite_created'           => 'Mitarbeiter eingeladen',
        'invite_resent'            => 'Einladung erneut gesendet',
        'invite_revoked'           => 'Einladung widerrufen',
        'invite_accepted'          => 'Einladung angenommen',
        'member_removed'           => 'Mitarbeiter entfernt',
        'member_suspended'         => 'Mitarbeiter gesperrt',
        'member_unsuspended'       => 'Mitarbeiter entsperrt',
        'role_changed'             => 'Rolle geändert',
        'ownership_transferred'    => 'Inhaberschaft übertragen',
        'org_renamed'              => 'Firma umbenannt',
        'org_updated'              => 'Firmendaten geändert',
        'company_created'          => 'Firma angelegt',
        'lexoffice_connected'      => 'Lexware Office verbunden',
        'lexoffice_disconnected'   => 'Lexware Office getrennt',
        'lexoffice_verified'       => 'Lexware-Office-Verbindung geprüft',
        'stripe_connected'         => 'Stripe verbunden',
        'stripe_verified'          => 'Stripe-Verbindung geprüft',
        'stripe_disconnected'      => 'Stripe getrennt',
        'sepa_toggle'              => 'SEPA-Einzug je Kunde geändert',
        'iban_saved'               => 'IBAN hinterlegt',
        'iban_deactivated'         => 'IBAN deaktiviert',
        'mandate_document'         => 'Mandatsdokument erzeugt',
        'mandate_signed'           => 'Mandat als unterschrieben erfasst',
        'mandate_cancelled'        => 'Mandat widerrufen',
        'sync_requested'           => 'Synchronisation gestartet',
        'sync_completed'           => 'Synchronisation abgeschlossen',
        'sync_cancelled'           => 'Synchronisation abgebrochen',
        'collection_submitted'     => 'Lastschrift eingereicht',
        'collection_scheduled'     => 'Lastschrift terminiert',
        'collection_cancelled'     => 'Terminierte Lastschrift storniert',
        'collection_rescheduled'   => 'Lastschrift umterminiert',
        'collections_bulk'         => 'Sammel-Einzug ausgelöst',
        'collections_due_processed'=> 'Fällige Einzüge eingereicht',
        'collection_status_sync'   => 'Einzugsstatus abgeglichen',
        'collection_disputed'      => 'Rücklastschrift eingegangen',
        'subscription_checkout'    => 'Abo-Abschluss gestartet',
        'subscription_changed'     => 'Abo geändert',
        'subscription_cancelled'   => 'Abo gekündigt',
        'admin_plan_changed'       => 'Tarif durch Administrator geändert',
        'onboarding_completed'     => 'Einrichtung abgeschlossen',
    ];
    return $map[$action] ?? $action;
}

/**
 * Funnel-Ereignis je Herkunftsdomain speichern (Registrierung, 2FA,
 * Verbindungen, erste Synchronisation, erster Einzug). Cookielos, ohne IP.
 */
function funnel_event(?string $domain, string $event, ?string $tenantId = null, ?string $userId = null, ?string $path = null): void
{
    try {
        $domain = $domain ? mb_substr(mb_strtolower(trim($domain)), 0, 100) : 'direkt';
        db()->prepare(
            'INSERT INTO funnel_events (domain, event, path, tenant_id, user_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$domain, mb_substr($event, 0, 40), $path !== null ? mb_substr($path, 0, 255) : null, $tenantId, $userId]);
    } catch (Throwable $e) {
        error_log('Funnel-Ereignis fehlgeschlagen (' . $event . '): ' . $e->getMessage());
    }
}

/** Funnel-Ereignis für eine Firma anhand ihrer gespeicherten Herkunftsdomain. */
function funnel_event_for_org(string $tenantId, string $event, ?string $userId = null): void
{
    try {
        $stmt = db()->prepare('SELECT signup_domain FROM organizations WHERE id = ?');
        $stmt->execute([$tenantId]);
        $domain = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $domain = '';
    }
    funnel_event($domain !== '' ? $domain : null, $event, $tenantId, $userId);
}

/**
 * Funnel-Ereignis nur beim ersten Auftreten je Firma speichern
 * (z.B. erste Synchronisation, erster Einzug).
 */
function funnel_event_once(string $tenantId, string $event, ?string $userId = null): void
{
    try {
        $stmt = db()->prepare('SELECT 1 FROM funnel_events WHERE tenant_id = ? AND event = ? LIMIT 1');
        $stmt->execute([$tenantId, $event]);
        if ($stmt->fetch()) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    funnel_event_for_org($tenantId, $event, $userId);
}
