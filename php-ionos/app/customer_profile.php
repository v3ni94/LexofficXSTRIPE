<?php
/**
 * Kundenprofil im Adminbereich (Version 4.62): Alle Daten einer Firma und ihrer Benutzer fuer den Support an einem Ort
 * (admin-kunde.php), mit Pflege der Stammdaten OHNE Geldbezug.
 *
 * Regeln:
 *  - Lesen verlangt support.view, Aendern verlangt support.customers (Berechtigungskatalog app/platform.php).
 *  - Aenderbar sind nur Kontaktdaten: Firma (Name, Strasse, PLZ, Ort, Land) und Benutzer (Anzeigename, Vor- und Nachname,
 *    Telefonnummern). Felder mit Geldbezug oder Zugangsbezug bleiben der Firma vorbehalten (CUSTOMER_PROFILE_LOCKED_FIELDS):
 *    Glaeubiger-Identifikationsnummer, Mandatspraefix, Vorabankuendigung, Mandatspflicht, E-Mail-Adresse (Anmeldename),
 *    Passwort, 2FA, Rollen, Tarif, Verbindungen. Die Sperre liegt in der Funktion, nicht in der Seite (Projektregel).
 *  - Jede Aenderung braucht einen Grund (5 bis 255 Zeichen), wird mit Vorher/Nachher im Audit festgehalten
 *    (org_updated_support, profile_updated_support) und dem Inhaber beziehungsweise dem Benutzer per Sicherheitsmail angezeigt.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/profile.php';

/** Felder, die der Support nie aendert (Dokumentation und Pruefstand tools/platform-roles-check.sh). */
const CUSTOMER_PROFILE_LOCKED_FIELDS = [
    'organization' => ['creditor_identifier', 'mandate_prefix', 'pre_notification_days', 'send_pre_notification', 'require_signed_mandate',
                       'professional_secrecy', 'plan_code', 'subscription_status', 'billing_exempt', 'collections_paused'],
    'user'         => ['email', 'password_hash', 'totp_secret_encrypted', 'totp_enabled', 'is_superadmin', 'platform_role', 'is_active'],
];

/** Aenderbare Felder je Objekt (Positivliste; alles andere wird ignoriert). */
const CUSTOMER_PROFILE_EDITABLE = [
    'organization' => ['name', 'street', 'zip', 'city', 'country'],
    'user'         => ['display_name', 'first_name', 'last_name', 'phone_private', 'phone_business'],
];

/** Firma mit Verbindungen, Inhaber und Kennzahlen; null, wenn unbekannt oder geloescht. */
function customer_org_load(string $orgId): ?array
{
    $stmt = db()->prepare(
        "SELECT o.*,
                i.invoice_source, i.lexoffice_connected, i.lexoffice_company_name, i.lexoffice_last_sync, i.lexoffice_last_verified_at,
                i.sevdesk_connected, i.sevdesk_company_name, i.sevdesk_last_sync,
                i.stripe_connected, i.stripe_business_name, i.stripe_mode, i.stripe_last_verified_at,
                i.invoice_source_changed_at,
                (SELECT u.email FROM organization_members m JOIN users u ON u.id = m.user_id WHERE m.organization_id = o.id AND m.role = 'owner' LIMIT 1) AS owner_email,
                (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'active') AS members,
                (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = o.id) AS customers_count,
                (SELECT COUNT(*) FROM sepa_mandates sm WHERE sm.tenant_id = o.id AND sm.status = 'active') AS mandates_active,
                (SELECT COUNT(*) FROM payment_collections pc WHERE pc.tenant_id = o.id) AS collections_count,
                (SELECT COUNT(*) FROM support_tickets t WHERE t.tenant_id = o.id AND t.status <> 'closed') AS tickets_open
         FROM organizations o LEFT JOIN integrations i ON i.tenant_id = o.id
         WHERE o.id = ? AND o.deleted_at IS NULL"
    );
    $stmt->execute([$orgId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Mitglieder einer Firma mit Kontostatus. */
function customer_org_members(string $orgId): array
{
    $stmt = db()->prepare(
        "SELECT u.id, u.email, u.display_name, u.first_name, u.last_name, u.phone_private, u.phone_business, u.is_active,
                u.totp_enabled, u.email_verified_at, u.last_login_at, u.locked_until, u.failed_login_count, u.platform_role, u.is_superadmin,
                m.role, m.status AS member_status, m.created_at AS member_since
         FROM organization_members m JOIN users u ON u.id = m.user_id
         WHERE m.organization_id = ?
         ORDER BY FIELD(m.role, 'owner', 'admin', 'member'), u.email"
    );
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

/** Benutzer mit seinen Firmen; null, wenn unbekannt. */
function customer_user_load(string $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, email, display_name, first_name, last_name, phone_private, phone_business, avatar_path, is_active, totp_enabled,
                totp_confirmed_at, email_verified_at, last_login_at, locked_until, failed_login_count, platform_role, is_superadmin,
                multiaccount_enabled, created_at
         FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Firmen eines Benutzers (Rolle und Status je Mitgliedschaft). */
function customer_user_orgs(string $userId): array
{
    $stmt = db()->prepare(
        'SELECT o.id, o.name, o.plan_code, o.subscription_status, o.collections_paused, m.role, m.status AS member_status, m.created_at AS member_since
         FROM organization_members m JOIN organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND o.deleted_at IS NULL ORDER BY o.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Juengste Protokolleintraege zu einer Firma (Aufbewahrung 90 Tage, audit_cleanup). */
function customer_org_audit(string $orgId, int $limit = 25): array
{
    $stmt = db()->prepare('SELECT id, user_email, action, target_type, target_id, details_json, created_at FROM audit_log WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

/** Juengste Protokolleintraege zu einem Benutzer (als Handelnder oder Ziel). */
function customer_user_audit(string $userId, int $limit = 25): array
{
    $stmt = db()->prepare("SELECT id, tenant_id, user_email, action, target_type, target_id, details_json, created_at FROM audit_log
                           WHERE user_id = ? OR (target_type = 'user' AND target_id = ?) ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}

/** Support-Anfragen einer Firma. */
function customer_org_tickets(string $orgId, int $limit = 20): array
{
    $stmt = db()->prepare('SELECT id, user_email, subject, category, status, created_at, last_message_at FROM support_tickets WHERE tenant_id = ? ORDER BY last_message_at DESC LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

/** Support-Sitzungen einer Firma. */
function customer_org_support_sessions(string $orgId, int $limit = 20): array
{
    $stmt = db()->prepare('SELECT id, admin_email, reason, created_at, redeemed_at, ended_at, ended_by, expires_at FROM support_sessions WHERE organization_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

/** Grund einer Support-Aenderung pruefen und normalisieren. */
function customer_profile_reason(string $reason): string
{
    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
        throw new RuntimeException('Bitte einen Grund für die Änderung angeben (5 bis 255 Zeichen, zum Beispiel Ticketnummer oder Anruf).');
    }
    return $reason;
}

function customer_profile_require(array $ctx): void
{
    if (!platform_can($ctx, 'support.customers')) {
        throw new RuntimeException('Ihre Rolle hat für diese Aktion keine Berechtigung (support.customers).');
    }
}

/**
 * Firmenanschrift und Name durch den Support aendern. Liefert die geaenderten Felder (vorher/nachher).
 * Geldrelevante Felder (CUSTOMER_PROFILE_LOCKED_FIELDS) werden auch dann nicht angefasst, wenn sie in $input stehen.
 */
function customer_org_update(array $ctx, string $orgId, array $input, string $reason): array
{
    customer_profile_require($ctx);
    $reason = customer_profile_reason($reason);
    $org = customer_org_load($orgId);
    if ($org === null) {
        throw new RuntimeException('Firma nicht gefunden.');
    }
    $name = trim(preg_replace('/\s+/', ' ', (string)($input['name'] ?? '')) ?? '');
    if ($name === '' || mb_strlen($name) > 255) {
        throw new RuntimeException('Der Firmenname darf nicht leer sein (höchstens 255 Zeichen).');
    }
    $country = strtoupper(trim((string)($input['country'] ?? 'DE')));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new RuntimeException('Das Land wird als zweistelliger Code angegeben (zum Beispiel DE, AT, CH).');
    }
    $neu = [
        'name'    => $name,
        'street'  => mb_substr(trim((string)($input['street'] ?? '')), 0, 255) ?: null,
        'zip'     => mb_substr(trim((string)($input['zip'] ?? '')), 0, 20) ?: null,
        'city'    => mb_substr(trim((string)($input['city'] ?? '')), 0, 100) ?: null,
        'country' => $country,
    ];
    $diff = [];
    foreach (CUSTOMER_PROFILE_EDITABLE['organization'] as $f) {
        $alt = $org[$f] ?? null;
        if ((string)$alt !== (string)$neu[$f]) {
            $diff[$f] = ['vorher' => $alt, 'nachher' => $neu[$f]];
        }
    }
    if ($diff === []) {
        return [];
    }
    db()->prepare('UPDATE organizations SET name = ?, street = ?, zip = ?, city = ?, country = ? WHERE id = ? AND deleted_at IS NULL')
        ->execute([$neu['name'], $neu['street'], $neu['zip'], $neu['city'], $neu['country'], $orgId]);
    audit_log($orgId, $ctx, 'org_updated_support', 'organization', $orgId, ['grund' => $reason, 'felder' => $diff, 'support_edit' => true]);
    require_once __DIR__ . '/auth.php';
    security_notify_owner($orgId, 'Firmendaten durch den Support geändert', [
        sprintf('Der Support von %s (%s) hat Firmenname oder Anschrift Ihrer Firma %s geändert: %s.', product_name(), (string)$ctx['email'], $name, implode(', ', array_keys($diff))),
        'Grund laut Support: ' . $reason,
        'Die Änderung ist im Protokoll unter Firmendaten sichtbar. Gläubiger-Identifikationsnummer, SEPA-Einstellungen und Zugangsdaten kann der Support nicht ändern.',
    ]);
    return $diff;
}

/**
 * Kontaktdaten eines Benutzers durch den Support aendern (Anzeigename, Vor- und Nachname, Telefonnummern).
 * E-Mail-Adresse, Passwort, 2FA und Rollen bleiben ausgeschlossen. Liefert die geaenderten Felder.
 */
function customer_user_update(array $ctx, string $userId, array $input, string $reason): array
{
    customer_profile_require($ctx);
    $reason = customer_profile_reason($reason);
    $user = customer_user_load($userId);
    if ($user === null) {
        throw new RuntimeException('Benutzer nicht gefunden.');
    }
    if ((int)$user['is_superadmin'] === 1 || (!empty($user['platform_role']) && !platform_actor_is_admin($ctx))) {
        // Plattform-Benutzer pflegen ihre Daten selbst; nur ein Administrator darf hier eingreifen.
        throw new RuntimeException('Daten von Plattform-Benutzern ändert nur ein Administrator über deren eigenes Konto.');
    }
    $displayName = trim(preg_replace('/\s+/', ' ', (string)($input['display_name'] ?? '')) ?? '');
    if ($displayName === '' || mb_strlen($displayName) > 100) {
        throw new RuntimeException('Bitte einen Anzeigenamen mit höchstens 100 Zeichen eingeben.');
    }
    $neu = [
        'display_name'   => $displayName,
        'first_name'     => mb_substr(trim((string)($input['first_name'] ?? '')), 0, 100) ?: null,
        'last_name'      => mb_substr(trim((string)($input['last_name'] ?? '')), 0, 100) ?: null,
        'phone_private'  => profile_normalize_phone((string)($input['phone_private'] ?? '')),
        'phone_business' => profile_normalize_phone((string)($input['phone_business'] ?? '')),
    ];
    $diff = [];
    foreach (CUSTOMER_PROFILE_EDITABLE['user'] as $f) {
        $alt = $user[$f] ?? null;
        if ((string)$alt !== (string)$neu[$f]) {
            // Telefonnummern nicht im Klartext ins Protokoll (Datensparsamkeit wie profile_update)
            $diff[$f] = str_starts_with($f, 'phone_')
                ? ['vorher' => $alt !== null && $alt !== '', 'nachher' => $neu[$f] !== null]
                : ['vorher' => $alt, 'nachher' => $neu[$f]];
        }
    }
    if ($diff === []) {
        return [];
    }
    db()->prepare('UPDATE users SET display_name = ?, first_name = ?, last_name = ?, phone_private = ?, phone_business = ? WHERE id = ?')
        ->execute([$neu['display_name'], $neu['first_name'], $neu['last_name'], $neu['phone_private'], $neu['phone_business'], $userId]);
    audit_log(null, $ctx, 'profile_updated_support', 'user', $userId, ['grund' => $reason, 'felder' => $diff, 'support_edit' => true]);
    require_once __DIR__ . '/auth.php';
    security_notify_user($user, 'Profildaten durch den Support geändert', [
        sprintf('Der Support von %s (%s) hat Ihre Profildaten geändert: %s.', product_name(), (string)$ctx['email'], implode(', ', array_keys($diff))),
        'Grund laut Support: ' . $reason,
        'Ihre E-Mail-Adresse, Ihr Passwort und Ihre Zwei-Faktor-Einrichtung wurden nicht verändert; das kann der Support nicht.',
    ]);
    return $diff;
}

/** Suche nach Firma oder Benutzer (Name, E-Mail, Ort) fuer die Support-Startseite. */
function customer_search(string $q, int $limit = 30): array
{
    $q = trim($q);
    if ($q === '') {
        return ['orgs' => [], 'users' => []];
    }
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $orgs = db()->prepare('SELECT id, name, city, plan_code, subscription_status FROM organizations WHERE deleted_at IS NULL AND (name LIKE ? OR city LIKE ? OR zip LIKE ?) ORDER BY name LIMIT ' . $limit);
    $orgs->execute([$like, $like, $like]);
    $users = db()->prepare('SELECT id, email, display_name, first_name, last_name, is_active FROM users WHERE email LIKE ? OR display_name LIKE ? OR CONCAT_WS(\' \', first_name, last_name) LIKE ? ORDER BY email LIMIT ' . $limit);
    $users->execute([$like, $like, $like]);
    return ['orgs' => $orgs->fetchAll(), 'users' => $users->fetchAll()];
}
