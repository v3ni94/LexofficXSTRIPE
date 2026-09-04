<?php
/**
 * Tarife, Sitzlimits, Einzugslimits und Abo-Status.
 *
 * Sämtliche Limits stammen aus der Tabelle plans (nichts ist im Frontend
 * fest verdrahtet). Bestandskunden des Starttarifs behalten unbegrenzte
 * Benutzer, solange ihr Tarif administrativ nicht geändert wird
 * (Grandfathering über plan_code).
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** Tarif laden; unbekannter Code fällt auf den Starttarif zurück. */
function plan_get(string $code): array
{
    static $cache = [];
    if ($code === '') { // interner Aufruf zum Leeren des Caches (Tests, Admin nach Tarifänderung)
        $cache = [];
        return [];
    }
    if (isset($cache[$code])) {
        return $cache[$code];
    }
    $stmt = db()->prepare('SELECT * FROM plans WHERE code = ?');
    $stmt->execute([$code]);
    $plan = $stmt->fetch();
    if (!$plan) {
        $stmt->execute(['unlimited_start']);
        $plan = $stmt->fetch() ?: [
            'code' => 'unlimited_start', 'name' => 'UNLIMITED START', 'price_cents' => 2500,
            'period_days' => 28, 'max_collections_per_period' => null, 'max_users' => null,
            'unlimited_users' => 1, 'user_invites_enabled' => 1, 'active' => 1, 'public_visible' => 1,
            'stripe_price_id' => null,
        ];
    }
    $cache[$code] = $plan;
    return $plan;
}

/** Alle Tarife (für Superadmin und Abo-Seite). */
function plan_list(bool $onlyActive = false): array
{
    $sql = 'SELECT * FROM plans' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, price_cents';
    return db()->query($sql)->fetchAll();
}

/** Tarif der Firma. */
function plan_for_org(array|string $org): array
{
    if (is_string($org)) {
        $stmt = db()->prepare('SELECT plan_code FROM organizations WHERE id = ?');
        $stmt->execute([$org]);
        $code = (string)($stmt->fetchColumn() ?: 'unlimited_start');
    } else {
        $code = (string)($org['plan_code'] ?? 'unlimited_start');
    }
    return plan_get($code);
}

/**
 * Belegte Sitze: Inhaber + Mitglieder (auch vorübergehend gesperrte, damit
 * das Limit nicht über Sperren/Entsperren umgangen wird) + offene, gültige
 * Einladungen. Abgelaufene oder widerrufene Einladungen zählen nicht.
 */
function seats_used(string $tenantId): int
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM organization_members WHERE organization_id = ? AND status IN ('active', 'suspended')"
    );
    $stmt->execute([$tenantId]);
    $members = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM invitations
         WHERE organization_id = ? AND status = 'pending' AND expires_at > NOW()"
    );
    $stmt->execute([$tenantId]);
    $pending = (int)$stmt->fetchColumn();

    return $members + $pending;
}

/** Sitzlimit des Tarifs (null = unbegrenzt). */
function seats_limit(array $plan): ?int
{
    if ((int)($plan['unlimited_users'] ?? 0) === 1 || $plan['max_users'] === null) {
        return null;
    }
    return (int)$plan['max_users'];
}

/**
 * Prüft, ob eine weitere Einladung möglich ist.
 * @return array{allowed:bool,reason:?string,used:int,limit:?int}
 */
function seats_can_invite(string $tenantId, array $plan): array
{
    if ((int)($plan['user_invites_enabled'] ?? 1) !== 1) {
        return ['allowed' => false, 'reason' => 'Ihr Tarif erlaubt keine zusätzlichen Benutzer.',
                'used' => seats_used($tenantId), 'limit' => seats_limit($plan)];
    }
    $used = seats_used($tenantId);
    $limit = seats_limit($plan);
    if ($limit !== null && $used >= $limit) {
        return [
            'allowed' => false,
            'reason'  => sprintf(
                'Das Benutzerlimit Ihres Tarifs (%d) ist erreicht. Offene Einladungen zählen mit. '
                . 'Bitte zunächst eine Einladung widerrufen oder einen Benutzer entfernen.',
                $limit
            ),
            'used' => $used, 'limit' => $limit,
        ];
    }
    return ['allowed' => true, 'reason' => null, 'used' => $used, 'limit' => $limit];
}

/**
 * Prüft einen Tarifwechsel gegen die aktuellen Benutzer (Downgrade-Schutz).
 * @return array{allowed:bool,reason:?string}
 */
function plan_change_allowed(string $tenantId, array $newPlan): array
{
    $limit = seats_limit($newPlan);
    if ($limit === null) {
        return ['allowed' => true, 'reason' => null];
    }
    $used = seats_used($tenantId);
    if ($used > $limit) {
        return [
            'allowed' => false,
            'reason'  => sprintf(
                'Bitte entfernen Sie zunächst %d Benutzer bzw. offene Einladungen, bevor Sie diesen Tarif wählen können.',
                $used - $limit
            ),
        ];
    }
    return ['allowed' => true, 'reason' => null];
}

/**
 * Beginn der laufenden Abrechnungsperiode für die Zählung der Einzüge.
 * Ohne Stripe-Periodenende: rollierendes Fenster von period_days Tagen.
 */
function plan_period_start(array $org, array $plan): DateTimeImmutable
{
    $days = max(1, (int)($plan['period_days'] ?? 28));
    if (!empty($org['subscription_period_end'])) {
        $end = new DateTimeImmutable($org['subscription_period_end']);
        $start = $end->modify('-' . $days . ' days');
        if ($start <= new DateTimeImmutable('now')) {
            return $start;
        }
    }
    return (new DateTimeImmutable('now'))->modify('-' . $days . ' days');
}

/**
 * Prüft, ob in der laufenden Periode noch ein Einzug erlaubt ist.
 * Terminierte Einzüge zählen ab Terminierung (belegen ein Kontingent).
 * @return array{allowed:bool,reason:?string,used:int,limit:?int}
 */
function collections_quota_check(string $tenantId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $org = $stmt->fetch();
    if (!$org) {
        return ['allowed' => false, 'reason' => 'Firma nicht gefunden.', 'used' => 0, 'limit' => null];
    }
    $plan = plan_for_org($org);
    $limit = $plan['max_collections_per_period'] === null ? null : (int)$plan['max_collections_per_period'];
    if ($limit === null) {
        return ['allowed' => true, 'reason' => null, 'used' => 0, 'limit' => null];
    }
    $since = plan_period_start($org, $plan)->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM payment_collections
         WHERE tenant_id = ? AND created_at >= ? AND stripe_status <> 'cancelled'"
    );
    $stmt->execute([$tenantId, $since]);
    $used = (int)$stmt->fetchColumn();
    if ($used >= $limit) {
        return [
            'allowed' => false,
            'reason'  => sprintf(
                'Das Einzugskontingent Ihres Tarifs (%d je Abrechnungsperiode) ist ausgeschöpft.',
                $limit
            ),
            'used' => $used, 'limit' => $limit,
        ];
    }
    return ['allowed' => true, 'reason' => null, 'used' => $used, 'limit' => $limit];
}

/** Ist die Plattform-Abrechnung aktiv konfiguriert? */
function billing_enabled(): bool
{
    $b = config('billing', []);
    return !empty($b['enabled']) && !empty($b['stripe_secret_key']);
}

/**
 * Darf die Firma die operativen Funktionen nutzen? Ohne aktive Abrechnung
 * oder bei Befreiung immer ja. Sonst nur bei aktivem Abo (auch bei
 * Zahlungsverzug bleibt der Zugriff bis zum Periodenende erhalten).
 */
function subscription_allows_operation(array $org): bool
{
    if (!billing_enabled() || (int)($org['billing_exempt'] ?? 0) === 1) {
        return true;
    }
    $status = (string)($org['subscription_status'] ?? 'pending');
    if (in_array($status, ['active', 'exempt'], true)) {
        return true;
    }
    if ($status === 'past_due' && !empty($org['subscription_period_end'])) {
        return new DateTimeImmutable($org['subscription_period_end']) > new DateTimeImmutable('now');
    }
    return false;
}

/** Lesbarer Abo-Status. */
function subscription_status_label(string $status): string
{
    return match ($status) {
        'active'   => 'Aktiv',
        'exempt'   => 'Befreit',
        'past_due' => 'Zahlung überfällig',
        'canceled' => 'Gekündigt',
        default    => 'Noch nicht abgeschlossen',
    };
}
