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
        $used = seats_used($tenantId);
        $reason = 'Ihr Tarif erlaubt keine zusätzlichen Benutzer.';
        $cand = plan_upgrade_candidate($plan, 'users', $used + 1);
        if ($cand) {
            $reason .= ' ' . plan_upsell_text($cand);
        }
        return ['allowed' => false, 'reason' => $reason, 'used' => $used, 'limit' => seats_limit($plan), 'upgrade' => $cand];
    }
    $used = seats_used($tenantId);
    $limit = seats_limit($plan);
    if ($limit !== null && $used >= $limit) {
        $reason = sprintf(
            'Das Benutzerlimit Ihres Tarifs (%d) ist erreicht. Offene Einladungen zählen mit. '
            . 'Bitte zunächst eine Einladung widerrufen oder einen Benutzer entfernen.',
            $limit
        );
        $cand = plan_upgrade_candidate($plan, 'users', $used + 1);
        if ($cand) {
            $reason .= ' ' . plan_upsell_text($cand);
        }
        return ['allowed' => false, 'reason' => $reason, 'used' => $used, 'limit' => $limit, 'upgrade' => $cand];
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
    $percent = $limit > 0 ? (int)floor($used * 100 / $limit) : 0;
    $warn = $percent >= PLAN_QUOTA_WARN_PERCENT;
    if ($used >= $limit) {
        $reason = sprintf('Das Einzugskontingent Ihres Tarifs (%d je Abrechnungsperiode) ist ausgeschöpft.', $limit);
        if ($cand = plan_upgrade_candidate($plan, 'collections', $used + 1)) {
            $reason .= ' ' . plan_upsell_text($cand);
        }
        return ['allowed' => false, 'reason' => $reason, 'used' => $used, 'limit' => $limit, 'percent' => $percent, 'warn' => true, 'period_start' => $since];
    }
    return ['allowed' => true, 'reason' => null, 'used' => $used, 'limit' => $limit, 'percent' => $percent, 'warn' => $warn, 'period_start' => $since];
}

// ---------------------------------------------------------------------------
// Tarifwechsel und Upsell (Paket 4b). Wirkt nur, wenn mindestens zwei Tarife aktiv und öffentlich sind.
// ---------------------------------------------------------------------------

/** Ab diesem Anteil des Einzugskontingents wird auf den nächsthöheren Tarif hingewiesen. */
const PLAN_QUOTA_WARN_PERCENT = 80;

/** Aktive, öffentlich sichtbare Tarife in Sortierreihenfolge. */
function plans_public(): array
{
    return array_values(array_filter(plan_list(true), static fn(array $p): bool => (int)($p['public_visible'] ?? 0) === 1));
}

/** Gibt es überhaupt eine Wahl (mindestens zwei aktive, öffentliche Tarife)? */
function plan_upsell_available(): bool
{
    // Ohne freigeschaltete Abrechnung gibt es nichts zu wechseln; Hinweise blieben sonst ins Leere.
    return billing_enabled() && count(plans_public()) >= 2;
}

/** Grenze eines Tarifs für 'users' oder 'collections' (null = unbegrenzt). */
function plan_limit(array $plan, string $need): ?int
{
    if ($need === 'users') {
        return seats_limit($plan);
    }
    return $plan['max_collections_per_period'] === null ? null : (int)$plan['max_collections_per_period'];
}

/** Lesbare Grenze ("unbegrenzt" oder Zahl). */
function plan_limit_label(array $plan, string $need): string
{
    $l = plan_limit($plan, $need);
    return $l === null ? 'unbegrenzt' : (string)$l;
}

/**
 * Nächsthöherer aktiver, öffentlicher Tarif, der den Bedarf deckt ($need 'users' oder 'collections',
 * $required = benötigte Anzahl). Kandidaten sind Tarife mit höherem Preis als der aktuelle (Upgrade),
 * geordnet nach sort_order und Preis; der günstigste passende gewinnt. null, wenn es keine Wahl gibt,
 * kein Tarif passt oder der aktuelle Tarif für den Bedarf bereits unbegrenzt ist.
 */
function plan_upgrade_candidate(array $current, string $need, int $required = 1): ?array
{
    if (!plan_upsell_available() || plan_limit($current, $need) === null) {
        return null;
    }
    foreach (plans_public() as $p) {
        if ($p['code'] === $current['code'] || (int)$p['price_cents'] <= (int)$current['price_cents']) {
            continue;
        }
        if ($need === 'users' && (int)($p['user_invites_enabled'] ?? 1) !== 1) {
            continue;
        }
        $limit = plan_limit($p, $need);
        if ($limit === null || $limit >= $required) {
            return $p;
        }
    }
    return null;
}

/** Kurzer Hinweistext auf einen Tarif (für Meldungen ohne HTML). */
function plan_upsell_text(array $plan): string
{
    return sprintf(
        'Im Tarif %s (%s netto je %d Tage) stehen Ihnen %s Benutzer und %s Einzüge je Abrechnungsperiode zur Verfügung; der Wechsel ist unter Firma > Abonnement möglich.',
        $plan['name'], format_eur_cents((int)$plan['price_cents']), (int)$plan['period_days'],
        plan_limit_label($plan, 'users'), plan_limit_label($plan, 'collections')
    );
}

/** Richtung eines Tarifwechsels nach Preis. */
function plan_change_direction(array $from, array $to): string
{
    return (int)$to['price_cents'] > (int)$from['price_cents'] ? 'upgrade' : 'downgrade';
}

/**
 * Einmal je Abrechnungsperiode den Inhaber per E-Mail auf ein zu 80 Prozent oder vollständig
 * ausgeschöpftes Kontingent hinweisen (mit Upsell, sofern ein Tarif passt). Liefert true, wenn gesendet.
 */
function plan_quota_warning_maybe_send(string $tenantId): bool
{
    $quota = collections_quota_check($tenantId);
    if ($quota['limit'] === null || empty($quota['warn']) || empty($quota['period_start'])) {
        return false;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
    $st->execute([$tenantId]);
    $org = $st->fetch();
    if (!$org || !array_key_exists('quota_warning_period_start', $org)) {
        return false; // Migration 019 fehlt
    }
    $periodStart = substr((string)$quota['period_start'], 0, 10);
    if ($org['quota_warning_period_start'] !== null && substr((string)$org['quota_warning_period_start'], 0, 10) === $periodStart) {
        return false;
    }
    // Zuerst markieren (verhindert Doppelversand bei parallelen Läufen), dann senden.
    $upd = $pdo->prepare('UPDATE organizations SET quota_warning_period_start = ? WHERE id = ? AND (quota_warning_period_start IS NULL OR quota_warning_period_start <> ?)');
    $upd->execute([$quota['period_start'], $tenantId, $quota['period_start']]);
    if ($upd->rowCount() !== 1) {
        return false;
    }
    $owner = $pdo->prepare("SELECT u.email, u.first_name FROM organization_members m JOIN users u ON u.id = m.user_id WHERE m.organization_id = ? AND m.role = 'owner' LIMIT 1");
    $owner->execute([$tenantId]);
    $o = $owner->fetch();
    if (!$o || empty($o['email'])) {
        return false;
    }
    $plan = plan_for_org($org);
    $lines = [
        'Guten Tag' . (!empty($o['first_name']) ? ' ' . $o['first_name'] : '') . ',',
        '',
        sprintf('für die Firma %s sind in der laufenden Abrechnungsperiode %d von %d Einzügen Ihres Tarifs %s belegt (%d Prozent).',
            $org['name'], (int)$quota['used'], (int)$quota['limit'], $plan['name'], (int)$quota['percent']),
    ];
    if ($cand = plan_upgrade_candidate($plan, 'collections', (int)$quota['limit'] + 1)) {
        $lines[] = '';
        $lines[] = plan_upsell_text($cand);
        $lines[] = app_base_url() . '/subscription.php#tarif';
    } else {
        $lines[] = '';
        $lines[] = 'Ist das Kontingent ausgeschöpft, lassen sich bis zum Beginn der nächsten Periode keine weiteren Einzüge vormerken.';
    }
    $lines[] = '';
    $lines[] = 'Diese Nachricht erhalten Sie einmal je Abrechnungsperiode.';
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/audit.php';
    $subject = sprintf('%s: Einzugskontingent zu %d Prozent belegt', product_name(), (int)$quota['percent']);
    try {
        mail_send((string)$o['email'], $subject, implode("\n", $lines));
    } catch (Throwable $e) {
        error_log('Kontingenthinweis: ' . $e->getMessage());
    }
    audit_log($tenantId, null, 'quota_warning_sent', 'organization', $tenantId, ['used' => (int)$quota['used'], 'limit' => (int)$quota['limit'], 'percent' => (int)$quota['percent']]);
    return true;
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

/** Hinweis auf USt und Bruttobetrag für die Anzeige, z. B. ", zzgl. 19 % USt. (29,75 EUR brutto)". */
function billing_vat_hint(int $netCents): string
{
    $rate = (float)(config('billing', [])['vat_rate_percent'] ?? 19);
    if ($rate <= 0) {
        return '';
    }
    $gross = (int)round($netCents * (1 + $rate / 100));
    return sprintf(', zzgl. %s %% USt. (%s brutto)', rtrim(rtrim(number_format($rate, 2, ',', '.'), '0'), ','), format_eur_cents($gross));
}
