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
require_once __DIR__ . '/app/alerts.php';
require_once __DIR__ . '/app/admin_charts.php';

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

/**
 * Tarifwerte aus dem Formular lesen und prüfen. Preis als Dezimalbetrag (z.B. 25,00),
 * leere Limits bedeuten "unbegrenzt". Wirft bei ungültigen Angaben eine Exception.
 */
function plan_input_from_post(array $post, array $old): array
{
    $name = trim((string)($post['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) {
        throw new RuntimeException('Der Tarifname muss zwischen 1 und 60 Zeichen lang sein.');
    }
    $priceRaw = str_replace([' ', 'EUR', '€'], '', (string)($post['price'] ?? ''));
    $priceRaw = str_replace('.', '', $priceRaw);   // Tausenderpunkt
    $priceRaw = str_replace(',', '.', $priceRaw);  // Dezimalkomma
    if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0 || (float)$priceRaw > 100000) {
        throw new RuntimeException('Der Preis muss ein Betrag zwischen 0,00 und 100.000,00 EUR sein (z.B. 25,00).');
    }
    $priceCents = (int)round((float)$priceRaw * 100);
    $period = (int)($post['period_days'] ?? 0);
    if ($period < 1 || $period > 366) {
        throw new RuntimeException('Die Periode muss zwischen 1 und 366 Tagen liegen.');
    }
    $limit = static function (string $raw, string $label): ?int {
        $raw = trim($raw);
        if ($raw === '' || mb_strtolower($raw) === 'unbegrenzt') {
            return null;
        }
        if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 1000000) {
            throw new RuntimeException($label . ': bitte eine ganze Zahl ab 1 eingeben oder leer lassen für unbegrenzt.');
        }
        return (int)$raw;
    };
    $maxCollections = $limit((string)($post['max_collections'] ?? ''), 'Einzüge je Periode');
    $maxUsers = $limit((string)($post['max_users'] ?? ''), 'Benutzer');
    $sort = (int)($post['sort_order'] ?? $old['sort_order'] ?? 0);
    $stripePrice = trim((string)($post['stripe_price_id'] ?? ''));
    if ($stripePrice !== '' && !preg_match('/^price_[A-Za-z0-9]+$/', $stripePrice)) {
        throw new RuntimeException('Die Stripe-Preis-ID beginnt mit "price_" und enthält nur Buchstaben und Ziffern.');
    }
    return [
        'name' => $name,
        'price_cents' => $priceCents,
        'period_days' => $period,
        'max_collections_per_period' => $maxCollections,
        'max_users' => $maxUsers,
        'unlimited_users' => $maxUsers === null ? 1 : 0,
        'user_invites_enabled' => ($maxUsers === null || $maxUsers > 1) ? 1 : 0,
        'active' => !empty($post['active']) ? 1 : 0,
        'public_visible' => !empty($post['public_visible']) ? 1 : 0,
        'sort_order' => max(0, min(9999, $sort)),
        'stripe_price_id' => $stripePrice !== '' ? $stripePrice : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'platform_pause') {
            // Zweitbestätigung: aktueller 2FA-Code des Administrators
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
            $pause = ($_POST['pause'] ?? '') === '1';
            platform_setting_set('collections_paused', $pause ? '1' : '0');
            audit_log(null, $ctx, $pause ? 'collections_paused' : 'collections_resumed', 'platform', 'collections_paused', ['scope' => 'platform']);
            flash_set('success', $pause ? 'Plattformweiter Not-Stopp aktiv: keine neuen Einzüge für alle Firmen.' : 'Plattformweiter Not-Stopp aufgehoben.');
        } elseif ($action === 'plan_update') {
            // Zweitbestätigung: aktueller 2FA-Code des Administrators (Preise und Limits sind geldrelevant)
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
            $code = (string)($_POST['plan_code'] ?? '');
            $stmt = $pdo->prepare('SELECT * FROM plans WHERE code = ?');
            $stmt->execute([$code]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new RuntimeException('Tarif nicht gefunden.');
            }
            $new = plan_input_from_post($_POST, $old);
            $pdo->prepare(
                'UPDATE plans SET name = ?, price_cents = ?, period_days = ?, max_collections_per_period = ?, max_users = ?,
                        unlimited_users = ?, user_invites_enabled = ?, active = ?, public_visible = ?, sort_order = ?, stripe_price_id = ?
                 WHERE code = ?'
            )->execute([
                $new['name'], $new['price_cents'], $new['period_days'], $new['max_collections_per_period'], $new['max_users'],
                $new['unlimited_users'], $new['user_invites_enabled'], $new['active'], $new['public_visible'], $new['sort_order'],
                $new['stripe_price_id'], $code,
            ]);
            plan_get(''); // Cache leeren
            $changes = [];
            foreach ($new as $k => $v) {
                if ((string)($old[$k] ?? '') !== (string)($v ?? '')) {
                    $changes[$k] = ['alt' => $old[$k] ?? null, 'neu' => $v];
                }
            }
            audit_log(null, $ctx, 'admin_plan_changed', 'plan', $code, ['aenderungen' => $changes]);
            flash_set('success', $changes
                ? 'Tarif ' . $code . ' aktualisiert (' . implode(', ', array_keys($changes)) . ').'
                : 'Tarif ' . $code . ': keine Änderungen.');

        } elseif ($action === 'org_plan') {
            // Zweitbestätigung: aktueller 2FA-Code des Administrators
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
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

// --- Diagrammdaten: letzte 12 Kalenderwochen ---
$weekSlots = chart_week_slots(12);
$regByWeek = array_fill_keys(array_keys($weekSlots), 0);
foreach ($pdo->query("SELECT YEARWEEK(created_at, 3) AS wk, COUNT(*) AS cnt FROM organizations WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 WEEK) GROUP BY wk")->fetchAll() as $r) {
    if (isset($regByWeek[$r['wk']])) { $regByWeek[$r['wk']] = (int)$r['cnt']; }
}
$volByWeek = array_fill_keys(array_keys($weekSlots), 0);
$cntByWeek = array_fill_keys(array_keys($weekSlots), 0);
foreach ($pdo->query("SELECT YEARWEEK(COALESCE(completed_at, submitted_at, created_at), 3) AS wk, SUM(amount_cents - COALESCE(refunded_cents, 0)) AS cents, COUNT(*) AS cnt
    FROM payment_collections WHERE stripe_status = 'succeeded' AND COALESCE(completed_at, submitted_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 13 WEEK) GROUP BY wk")->fetchAll() as $r) {
    if (isset($volByWeek[$r['wk']])) { $volByWeek[$r['wk']] = (int)$r['cents']; $cntByWeek[$r['wk']] = (int)$r['cnt']; }
}
$chartRows = static fn(array $byWeek): array => array_map(static fn(string $wk, string $label): array => ['label' => $label, 'value' => $byWeek[$wk]], array_keys($weekSlots), $weekSlots);
$funnelTotals = [];
foreach ($funnelSteps as $ev => $label) {
    $sum = 0;
    foreach ($funnelMap as $d => $events) { $sum += (int)($events[$ev] ?? 0); }
    $funnelTotals[] = ['label' => $label, 'value' => $sum];
}
$regByDomain = array_map(static fn(array $d): array => ['label' => $d['domain'], 'value' => (int)$d['registrations']], $byDomain);
$fmtEur = static fn(float $v): string => number_format($v / 100, 0, ',', '.');
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

$platformAlerts = alerts_platform();

layout_header('Administration', $ctx);
?>
<h1>Administration</h1>
<p class="page-sub">Plattform <?= e(product_name()) ?> · Betreiber <?= e((string)(config('operator')['name'] ?? 'Müller Holding AG')) ?></p>
<nav class="admin-subnav" aria-label="Adminbereiche">
    <a href="#kennzahlen">Kennzahlen</a> · <a href="#diagramme">Diagramme</a> · <a href="#notstopp">Not-Stopp</a> · <a href="#tarife">Tarife</a> · <a href="#firmen">Firmen</a> · <a href="admin-support.php">Support</a> · <a href="admin-system.php" title="Technische Betriebsübersicht">System</a>
</nav>

<?php if ($platformAlerts): ?>
<div class="flash flash-warn">
    <strong>Hinweise (<?= count($platformAlerts) ?>)</strong>
    <ul style="margin: 6px 0 0 18px; padding: 0;">
    <?php foreach ($platformAlerts as $a): ?>
        <li><?= $a['level'] === 'hoch' ? '<strong>Wichtig:</strong> ' : '' ?><?= e($a['text']) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card-grid">
    <div class="stat-card"><div class="stat-value"><?= (int)$totals['orgs'] ?></div><div class="stat-label">Firmenaccounts</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)$totals['users'] ?></div><div class="stat-label">Benutzer</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)$totals['succeeded'] ?></div><div class="stat-label">Erfolgreiche SEPA-Einzüge (alle Firmen)</div></div>
    <div class="stat-card"><div class="stat-value" style="font-size: 20px;"><?= format_eur_cents((int)$totals['succeeded_cents']) ?></div><div class="stat-label">Eingezogenes Volumen (alle Firmen)</div></div>
</div>

<div class="card" id="kennzahlen">
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

<div class="card" id="diagramme">
    <h2>Diagramme</h2>
    <div class="chart-grid">
        <div><?= chart_bars($chartRows($regByWeek), 'Registrierungen je Kalenderwoche (12 Wochen)') ?></div>
        <div><?= chart_bars($chartRows($volByWeek), 'Eingezogenes Volumen je Kalenderwoche in EUR, netto nach Erstattungen', $fmtEur, '#2E2D2E') ?></div>
        <div><?= chart_bars($chartRows($cntByWeek), 'Erfolgreiche Einzüge je Kalenderwoche', null, '#9F9F9F') ?></div>
        <div><?= chart_hbars($regByDomain, 'Registrierungen je Herkunft (gesamt)') ?></div>
        <div class="chart-wide"><?= chart_hbars($funnelTotals, 'Funnel über alle Herkünfte (gesamt)', null, '#E3AC48') ?></div>
    </div>
    <p class="hint">Serverseitig erzeugte Grafiken aus den Tabellen organizations, payment_collections und funnel_events, keine Datenübertragung an Dritte.</p>
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

<div class="card" id="notstopp">
    <h2>Not-Stopp (Plattform)</h2>
    <?php $paused = platform_setting('collections_paused', '0') === '1'; ?>
    <p><?= $paused ? '<span class="badge badge-danger">Aktiv: alle neuen Einzüge sind angehalten.</span>' : '<span class="badge badge-success">Nicht aktiv, Einzüge laufen.</span>' ?>
        Bereits bei Stripe eingereichte Zahlungen sind davon nicht betroffen.</p>
    <form method="post" class="inline-form" onsubmit="return confirm(<?= e(json_encode($paused ? 'Not-Stopp wirklich aufheben? Firmen können danach wieder einziehen.' : 'Plattformweiten Not-Stopp aktivieren? Keine Firma kann danach neue Einzüge einreichen.', JSON_UNESCAPED_UNICODE)) ?>)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="platform_pause">
        <input type="hidden" name="pause" value="<?= $paused ? '0' : '1' ?>">
        <input type="text" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code" placeholder="Aktueller 2FA-Code" aria-label="Aktueller 2FA-Code" style="max-width: 190px;">
        <button type="submit" class="btn <?= $paused ? 'btn-secondary' : 'btn-danger' ?>"><?= $paused ? 'Not-Stopp aufheben' : 'Not-Stopp aktivieren' ?></button>
    </form>
    <p class="hint">Zweitbestätigung: Aktivieren und Aufheben erfordern den aktuellen Code aus Ihrer Authenticator-App.</p>
</div>

<div class="card" id="tarife">
    <h2>Tarife</h2>
    <p class="hint">Name, Preis (netto je Periode), Limits und Sichtbarkeit lassen sich hier direkt ändern. Leere Limits bedeuten
        unbegrenzt. Der angezeigte Preis muss zum hinterlegten Stripe-Preis passen, abgerechnet wird der Stripe-Preis.
        Bestandskunden behalten ihren Tarifcode, geänderte Preise und Limits gelten für sie ab der nächsten Periode
        bzw. sofort bei den Limits. Jede Änderung erfordert den aktuellen 2FA-Code und wird protokolliert.</p>
    <div class="table-wrap">
        <table class="plan-table">
            <thead><tr><th>Code</th><th>Name</th><th>Preis netto (EUR)</th><th>Periode (Tage)</th><th>Einzüge/Periode</th><th>Benutzer</th><th>Sortierung</th><th>Aktiv</th><th>Öffentlich</th><th>Stripe-Preis-ID</th><th>2FA-Code</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
                <tr>
                    <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="plan_update">
                    <input type="hidden" name="plan_code" value="<?= e($p['code']) ?>">
                    <td><code><?= e($p['code']) ?></code></td>
                    <td><input type="text" name="name" value="<?= e($p['name']) ?>" maxlength="60" required class="plan-input" style="min-width: 150px;"></td>
                    <td><input type="text" name="price" inputmode="decimal" value="<?= e(number_format((int)$p['price_cents'] / 100, 2, ',', '.')) ?>" required class="plan-input" style="max-width: 100px;"></td>
                    <td><input type="number" name="period_days" min="1" max="366" value="<?= (int)$p['period_days'] ?>" required class="plan-input" style="max-width: 80px;"></td>
                    <td><input type="text" name="max_collections" inputmode="numeric" value="<?= $p['max_collections_per_period'] === null ? '' : (int)$p['max_collections_per_period'] ?>" placeholder="unbegrenzt" class="plan-input" style="max-width: 110px;"></td>
                    <td><input type="text" name="max_users" inputmode="numeric" value="<?= ($p['max_users'] === null || (int)$p['unlimited_users']) ? '' : (int)$p['max_users'] ?>" placeholder="unbegrenzt" class="plan-input" style="max-width: 110px;"></td>
                    <td><input type="number" name="sort_order" min="0" max="9999" value="<?= (int)$p['sort_order'] ?>" class="plan-input" style="max-width: 80px;"></td>
                    <td><input type="checkbox" name="active" value="1" <?= (int)$p['active'] ? 'checked' : '' ?>></td>
                    <td><input type="checkbox" name="public_visible" value="1" <?= (int)$p['public_visible'] ? 'checked' : '' ?>></td>
                    <td><input type="text" name="stripe_price_id" value="<?= e($p['stripe_price_id'] ?? '') ?>" placeholder="price_..." class="plan-input" style="max-width: 220px;"></td>
                    <td><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Aktueller 2FA-Code" required class="plan-input" style="max-width: 130px;"></td>
                    <td><button type="submit" class="btn btn-sm btn-secondary">Speichern</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <style>.plan-table .plan-input { padding: 5px 8px; font-size: 13px; width: 100%; box-sizing: border-box; }</style>
</div>

<div class="card" id="firmen">
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
                            <input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" placeholder="Aktueller 2FA-Code" aria-label="Aktueller 2FA-Code" style="max-width: 150px; padding: 5px 8px; font-size: 13px;">
                            <button type="submit" class="btn btn-sm btn-secondary">OK</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Grandfathering: Bestehende Firmen behalten ihren Tarif, bis er hier geändert wird. Ein Wechsel auf einen Tarif mit
        weniger Benutzern wird abgelehnt, solange mehr Benutzer bzw. offene Einladungen vorhanden sind. Jede Tarifänderung erfordert
        als Zweitbestätigung den aktuellen 2FA-Code.</p>
</div>

<div class="card" id="support">
    <h2>Support</h2>
    <p>Firmenzugriff ("Auf Firma wechseln"), Konten entsperren, 2FA zurücksetzen und das Protokoll der Support-Zugriffe finden Sie im
        Bereich <a href="admin-support.php">Support</a>.</p>
</div>
<?php layout_footer($ctx); ?>
