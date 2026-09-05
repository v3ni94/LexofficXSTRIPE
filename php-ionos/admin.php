<?php
/**
 * Superadmin (Plattformbetreiber): Kennzahlen je Akquisitionsquelle,
 * Firmen, Tarife und Support-Funktionen (2FA-Reset, Entsperren).
 * Zugriff nur für users.is_superadmin = 1 mit aktiver 2FA. Alle Aktionen
 * werden protokolliert. Vorgesehen für admin.smart-einzug.de (gleiches
 * Verzeichnis, eigene Subdomain, siehe ANLEITUNG-IONOS.md).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/collections.php';

// Host-Prüfung: ist admin_base_url gesetzt, antwortet diese Seite nur auf dem
// Adminhost (bootstrap.php prüft dies bereits zentral, hier zusätzlich als
// zweite Sicherung, falls die Datei ohne bootstrap-Regeln ausgeliefert wird).
if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

$ctx = require_superadmin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'platform_pause') {
            $pause = ($_POST['pause'] ?? '') === '1';
            platform_setting_set('collections_paused', $pause ? '1' : '0');
            audit_log(null, $ctx, $pause ? 'collections_paused' : 'collections_resumed', 'platform', 'collections_paused', ['scope' => 'platform']);
            flash_set('success', $pause ? 'Plattformweiter Not-Stopp aktiv: keine neuen Einzüge für alle Firmen.' : 'Plattformweiter Not-Stopp aufgehoben.');
        } elseif ($action === 'plan_update') {
            $code = $_POST['code'] ?? '';
            $stmt = $pdo->prepare('SELECT * FROM plans WHERE code = ?');
            $stmt->execute([$code]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('Tarif nicht gefunden.');
            }
            $pdo->prepare('UPDATE plans SET active = ?, public_visible = ?, stripe_price_id = ? WHERE code = ?')
                ->execute([
                    !empty($_POST['active']) ? 1 : 0,
                    !empty($_POST['public_visible']) ? 1 : 0,
                    trim($_POST['stripe_price_id'] ?? '') ?: null,
                    $code,
                ]);
            audit_log(null, $ctx, 'admin_plan_changed', 'plan', $code, [
                'active' => !empty($_POST['active']), 'public_visible' => !empty($_POST['public_visible']),
            ]);
            flash_set('success', 'Tarif ' . $code . ' aktualisiert.');

        } elseif ($action === 'org_plan') {
            $orgId = $_POST['org_id'] ?? '';
            $stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
            $stmt->execute([$orgId]);
            $org = $stmt->fetch();
            if (!$org) {
                throw new RuntimeException('Firma nicht gefunden.');
            }
            $plan = plan_get($_POST['plan_code'] ?? '');
            $check = plan_change_allowed($orgId, $plan);
            if (!$check['allowed']) {
                throw new RuntimeException($check['reason']);
            }
            $pdo->prepare('UPDATE organizations SET plan_code = ?, billing_exempt = ? WHERE id = ?')
                ->execute([$plan['code'], !empty($_POST['billing_exempt']) ? 1 : 0, $orgId]);
            if (!empty($_POST['billing_exempt'])) {
                $pdo->prepare("UPDATE organizations SET subscription_status = 'exempt' WHERE id = ?")->execute([$orgId]);
            } elseif ($org['subscription_status'] === 'exempt') {
                $pdo->prepare("UPDATE organizations SET subscription_status = 'pending' WHERE id = ?")->execute([$orgId]);
            }
            audit_log($orgId, $ctx, 'admin_plan_changed', 'organization', $orgId, [
                'plan' => $plan['code'], 'billing_exempt' => !empty($_POST['billing_exempt']),
            ]);
            flash_set('success', 'Tarif der Firma ' . $org['name'] . ' auf ' . $plan['name'] . ' gesetzt.');

        } elseif ($action === 'user_reset_2fa' || $action === 'user_unlock') {
            $me = user_load($ctx['user_id']);
            if ($err = verify_password_and_2fa($me, $_POST['password'] ?? '', $_POST['code'] ?? '')) {
                throw new RuntimeException($err);
            }
            $email = mb_strtolower(trim($_POST['email'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new RuntimeException('Benutzer nicht gefunden.');
            }
            if ($action === 'user_reset_2fa') {
                twofa_reset($user, true, $ctx);
                flash_set('success', '2FA für ' . $email . ' zurückgesetzt (Support-Reset, protokolliert). Der Benutzer richtet 2FA bei der nächsten Anmeldung neu ein.');
            } else {
                $pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?')->execute([$user['id']]);
                $pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND success = 0')->execute([$email]);
                audit_log(null, $ctx, 'login_locked', 'user', $user['id'], ['unlocked_by_admin' => true, 'email' => $email]);
                flash_set('success', 'Konto ' . $email . ' entsperrt.');
            }
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('admin.php');
}

// --- Kennzahlen je Akquisitionsquelle ---
$byDomain = $pdo->query(
    "SELECT COALESCE(NULLIF(signup_domain, ''), 'direkt') AS domain,
            COUNT(*) AS registrations,
            SUM(subscription_status = 'active') AS active_subs,
            SUM(subscription_status = 'canceled') AS canceled,
            SUM(subscription_status = 'exempt') AS exempt,
            SUM(onboarding_completed) AS onboarded
     FROM organizations WHERE deleted_at IS NULL
     GROUP BY COALESCE(NULLIF(signup_domain, ''), 'direkt') ORDER BY registrations DESC"
)->fetchAll();

$funnel = $pdo->query(
    "SELECT domain, event, COUNT(*) AS cnt FROM funnel_events GROUP BY domain, event"
)->fetchAll();
$funnelMap = [];
foreach ($funnel as $f) {
    $funnelMap[$f['domain']][$f['event']] = (int)$f['cnt'];
}
$funnelSteps = [
    'page_view' => 'Besucher (Seitenaufrufe)', 'cta_click' => 'CTA geklickt', 'registration_started' => 'Registrierung begonnen',
    'registration_completed' => 'Registrierung abgeschlossen', 'subscription_active' => 'Abo abgeschlossen',
    '2fa_enabled' => '2FA eingerichtet', 'lexware_connected' => 'Lexware Office verbunden', 'stripe_connected' => 'Stripe verbunden',
    'onboarding_completed' => 'Onboarding abgeschlossen', 'first_sync' => 'Erste Synchronisation', 'first_collection' => 'Erster SEPA-Einzug',
];
$domains = array_unique(array_merge(array_column($byDomain, 'domain'), array_keys($funnelMap)));

$plans = plan_list();
$planByCode = [];
foreach ($plans as $p) {
    $planByCode[$p['code']] = $p;
}

$orgs = $pdo->query(
    "SELECT o.*,
            (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'active') AS members,
            (SELECT COUNT(*) FROM payment_collections pc WHERE pc.tenant_id = o.id AND pc.stripe_status <> 'cancelled') AS collections,
            (SELECT lexoffice_last_sync FROM integrations i WHERE i.tenant_id = o.id) AS last_sync,
            (SELECT u.email FROM organization_members m JOIN users u ON u.id = m.user_id WHERE m.organization_id = o.id AND m.role = 'owner' LIMIT 1) AS owner_email
     FROM organizations o WHERE o.deleted_at IS NULL ORDER BY o.created_at DESC LIMIT 500"
)->fetchAll();

$totals = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM users WHERE is_active = 1) AS users,
            (SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL) AS orgs,
            (SELECT COUNT(*) FROM payment_collections WHERE stripe_status = 'succeeded') AS succeeded,
            (SELECT COALESCE(SUM(amount_cents),0) FROM payment_collections WHERE stripe_status = 'succeeded') AS succeeded_cents"
)->fetch();

layout_header('Administration', $ctx);
?>
<h1>Administration</h1>
<p class="page-sub">Plattform <?= e(product_name()) ?> · Betreiber <?= e((string)(config('operator')['name'] ?? 'Müller Holding AG')) ?></p>

<div class="card-grid">
    <div class="stat-card"><div class="stat-value"><?= (int)$totals['orgs'] ?></div><div class="stat-label">Firmenaccounts</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)$totals['users'] ?></div><div class="stat-label">Benutzer</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)$totals['succeeded'] ?></div><div class="stat-label">Erfolgreiche SEPA-Einzüge (alle Firmen)</div></div>
    <div class="stat-card"><div class="stat-value" style="font-size: 20px;"><?= format_eur_cents((int)$totals['succeeded_cents']) ?></div><div class="stat-label">Eingezogenes Volumen (alle Firmen)</div></div>
</div>

<div class="card">
    <h2>Kennzahlen je Akquisitionsquelle</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Domain</th><th>Registrierungen</th><th>Onboarding fertig</th><th>Zahlende Kunden</th><th>Conversion</th><th>Umsatz je 4 Wochen (Schätzung)</th><th>Gekündigt (Churn)</th><th>Befreit</th></tr></thead>
            <tbody>
            <?php foreach ($byDomain as $d):
                $reg = (int)$d['registrations'];
                $active = (int)$d['active_subs'];
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.price_cents),0) FROM organizations o JOIN plans p ON p.code = o.plan_code WHERE o.subscription_status = 'active' AND COALESCE(NULLIF(o.signup_domain,''),'direkt') = ?");
                $stmt->execute([$d['domain']]);
                $revenue = (int)$stmt->fetchColumn();
            ?>
                <tr>
                    <td><?= e($d['domain']) ?></td>
                    <td><?= $reg ?></td>
                    <td><?= (int)$d['onboarded'] ?></td>
                    <td><?= $active ?></td>
                    <td><?= $reg > 0 ? number_format($active / $reg * 100, 1, ',', '.') . ' %' : '-' ?></td>
                    <td><?= format_eur_cents($revenue) ?></td>
                    <td><?= (int)$d['canceled'] ?><?= $reg > 0 ? ' (' . number_format((int)$d['canceled'] / $reg * 100, 1, ',', '.') . ' %)' : '' ?></td>
                    <td><?= (int)$d['exempt'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Funnel je Domain</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Schritt</th><?php foreach ($domains as $dm): ?><th><?= e($dm) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($funnelSteps as $key => $label): ?>
                <tr><td><?= e($label) ?></td>
                <?php foreach ($domains as $dm): ?><td><?= (int)($funnelMap[$dm][$key] ?? 0) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Seitenaufrufe und CTA-Klicks kommen cookielos von den Marketingseiten (track.php), alle weiteren Schritte aus der Anwendung.</p>
</div>

<div class="card">
    <h2>Not-Stopp (Plattform)</h2>
    <?php $paused = platform_setting('collections_paused', '0') === '1'; ?>
    <p><?= $paused ? '<span class="badge badge-danger">Aktiv: alle neuen Einzüge sind angehalten.</span>' : '<span class="badge badge-success">Nicht aktiv, Einzüge laufen.</span>' ?>
        Bereits bei Stripe eingereichte Zahlungen sind davon nicht betroffen.</p>
    <form method="post" class="inline-form" onsubmit="return confirm(<?= e(json_encode($paused ? 'Not-Stopp wirklich aufheben? Firmen können danach wieder einziehen.' : 'Plattformweiten Not-Stopp aktivieren? Keine Firma kann danach neue Einzüge einreichen.', JSON_UNESCAPED_UNICODE)) ?>)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="platform_pause">
        <input type="hidden" name="pause" value="<?= $paused ? '0' : '1' ?>">
        <button type="submit" class="btn <?= $paused ? 'btn-secondary' : 'btn-danger' ?>"><?= $paused ? 'Not-Stopp aufheben' : 'Not-Stopp aktivieren' ?></button>
    </form>
</div>

<div class="card">
    <h2>Tarife</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Code</th><th>Name</th><th>Preis</th><th>Einzüge/Periode</th><th>Benutzer</th><th>Aktiv</th><th>Öffentlich</th><th>Stripe-Preis-ID</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
                <tr>
                    <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="plan_update">
                    <input type="hidden" name="code" value="<?= e($p['code']) ?>">
                    <td><?= e($p['code']) ?></td>
                    <td><?= e($p['name']) ?></td>
                    <td><?= format_eur_cents((int)$p['price_cents']) ?></td>
                    <td><?= $p['max_collections_per_period'] === null ? 'unbegrenzt' : (int)$p['max_collections_per_period'] ?></td>
                    <td><?= $p['max_users'] === null || (int)$p['unlimited_users'] ? 'unbegrenzt' : (int)$p['max_users'] ?></td>
                    <td><input type="checkbox" name="active" value="1" <?= (int)$p['active'] ? 'checked' : '' ?>></td>
                    <td><input type="checkbox" name="public_visible" value="1" <?= (int)$p['public_visible'] ? 'checked' : '' ?>></td>
                    <td><input type="text" name="stripe_price_id" value="<?= e($p['stripe_price_id'] ?? '') ?>" placeholder="price_..." style="max-width: 220px; padding: 5px 8px; font-size: 13px;"></td>
                    <td><button type="submit" class="btn btn-sm btn-secondary">Speichern</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Firmenaccounts</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Firma</th><th>Inhaber</th><th>Herkunft</th><th>Registriert</th><th>Tarif</th><th>Abo</th><th>Benutzer</th><th>Einzüge</th><th>Letzter Sync</th><th>Tarif setzen</th></tr></thead>
            <tbody>
            <?php foreach ($orgs as $o): ?>
                <tr>
                    <td><?= e($o['name']) ?></td>
                    <td class="hint"><?= e($o['owner_email'] ?? '-') ?></td>
                    <td><?= e($o['signup_domain'] ?: 'direkt') ?></td>
                    <td><?= format_date($o['created_at']) ?></td>
                    <td><?= e($planByCode[$o['plan_code']]['name'] ?? $o['plan_code']) ?></td>
                    <td><?= e(subscription_status_label((string)$o['subscription_status'])) ?></td>
                    <td><?= (int)$o['members'] ?></td>
                    <td><?= (int)$o['collections'] ?></td>
                    <td><?= format_datetime($o['last_sync']) ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="org_plan">
                            <input type="hidden" name="org_id" value="<?= e($o['id']) ?>">
                            <select name="plan_code" style="max-width: 170px; padding: 5px 8px; font-size: 13px;">
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= e($p['code']) ?>" <?= $p['code'] === $o['plan_code'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="inline-check"><input type="checkbox" name="billing_exempt" value="1" <?= (int)$o['billing_exempt'] ? 'checked' : '' ?>> befreit</label>
                            <button type="submit" class="btn btn-sm btn-secondary">OK</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Grandfathering: Bestehende Firmen behalten ihren Tarif, bis er hier geändert wird. Ein Wechsel auf einen Tarif mit
        weniger Benutzern wird abgelehnt, solange mehr Benutzer bzw. offene Einladungen vorhanden sind.</p>
</div>

<div class="card">
    <h2>Support: Benutzer</h2>
    <p class="hint">2FA-Reset nur nach eindeutiger Identitätsprüfung des Nutzers (z.B. Rückruf über bekannte Firmennummer). Wird als
        Support-Reset besonders protokolliert; der Nutzer erhält eine Sicherheits-E-Mail. Zur Bestätigung sind Ihr Passwort und 2FA-Code nötig.</p>
    <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px;">
        <?= csrf_field() ?>
        <input type="email" name="email" placeholder="E-Mail des Benutzers" required style="max-width: 280px;">
        <input type="password" name="password" placeholder="Ihr Passwort" required autocomplete="current-password" style="max-width: 200px;">
        <input type="text" name="code" placeholder="Ihr 2FA-Code" required class="code-input" style="max-width: 140px;" autocomplete="one-time-code">
        <button type="submit" name="action" value="user_unlock" class="btn btn-sm btn-secondary">Konto entsperren</button>
        <button type="submit" name="action" value="user_reset_2fa" class="btn btn-sm btn-danger"
                onclick="return confirm('2FA dieses Benutzers wirklich zurücksetzen?')">2FA zurücksetzen (Support)</button>
    </form>
</div>
<?php layout_footer($ctx); ?>
