<?php
/**
 * Adminbereich: Kundenprofil (Version 4.62). Alle Daten einer Firma oder eines Benutzers auf einer Seite:
 * Stammdaten, Anschrift, Ansprechpartner mit Telefonnummern, Tarif und Abonnement, Verbindungen, Mitglieder,
 * Support-Anfragen, Support-Sitzungen und Protokoll. Der Support pflegt Kontaktdaten (Name, Anschrift, Telefon);
 * Felder mit Geld- oder Zugangsbezug sind nur lesbar (app/customer_profile.php, CUSTOMER_PROFILE_LOCKED_FIELDS).
 *
 *   admin-kunde.php?org=<id>    Firma            admin-kunde.php?user=<id>   Benutzer
 *   POST org_update / user_update (Recht support.customers, Grund Pflicht, Audit, Sicherheitsmail)
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/platform.php';
require_once __DIR__ . '/app/customer_profile.php';
require_once __DIR__ . '/app/plans.php';
require_once __DIR__ . '/app/support_tickets.php';
require_once __DIR__ . '/app/invoice_source_switch.php';

if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

$ctx = require_platform('support.view');
$canEdit = platform_can($ctx, 'support.customers');
$orgId = trim((string)($_GET['org'] ?? $_POST['org_id'] ?? ''));
$userId = trim((string)($_GET['user'] ?? $_POST['user_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $back = $orgId !== '' ? 'admin-kunde.php?org=' . urlencode($orgId) : ($userId !== '' ? 'admin-kunde.php?user=' . urlencode($userId) : 'admin-support.php');
    try {
        if (!platform_can($ctx, 'support.customers')) {
            throw new RuntimeException('Ihre Rolle hat für diese Aktion keine Berechtigung (support.customers).');
        }
        if ($action === 'org_update') {
            $diff = customer_org_update($ctx, $orgId, $_POST, (string)($_POST['reason'] ?? ''));
            flash_set('success', $diff === [] ? 'Keine Änderung: Die Firmendaten waren bereits so gespeichert.'
                : 'Firmendaten gespeichert (' . implode(', ', array_keys($diff)) . '). Der Inhaber wurde per Sicherheits-E-Mail informiert, die Änderung ist protokolliert.');
        } elseif ($action === 'user_update') {
            $diff = customer_user_update($ctx, $userId, $_POST, (string)($_POST['reason'] ?? ''));
            flash_set('success', $diff === [] ? 'Keine Änderung: Die Profildaten waren bereits so gespeichert.'
                : 'Profildaten gespeichert (' . implode(', ', array_keys($diff)) . '). Der Benutzer wurde per Sicherheits-E-Mail informiert, die Änderung ist protokolliert.');
            $back = 'admin-kunde.php?user=' . urlencode($userId) . ($orgId !== '' ? '&org=' . urlencode($orgId) : '');
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect($back);
}

$org = $orgId !== '' ? customer_org_load($orgId) : null;
$user = $userId !== '' ? customer_user_load($userId) : null;
if ($orgId !== '' && $org === null) {
    flash_set('error', 'Firma nicht gefunden oder gelöscht.');
    redirect('admin-support.php');
}
if ($userId !== '' && $user === null) {
    flash_set('error', 'Benutzer nicht gefunden.');
    redirect('admin-support.php');
}
$plans = plan_list();
$planByCode = [];
foreach ($plans as $p) {
    $planByCode[$p['code']] = $p;
}
$kontostatus = static function (array $u): string {
    $b = [];
    if ((int)$u['is_active'] !== 1) {
        $b[] = '<span class="badge badge-warn">deaktiviert</span>';
    }
    if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) {
        $b[] = '<span class="badge badge-warn">gesperrt bis ' . e(date('H:i', strtotime((string)$u['locked_until']))) . '</span>';
    }
    $b[] = (int)$u['totp_enabled'] === 1 ? '<span class="badge badge-success">2FA aktiv</span>' : '<span class="badge badge-warn">2FA fehlt</span>';
    $b[] = !empty($u['email_verified_at']) ? '<span class="badge badge-success">E-Mail bestätigt</span>' : '<span class="badge badge-warn">E-Mail unbestätigt</span>';
    if (!empty($u['platform_role']) || (int)($u['is_superadmin'] ?? 0) === 1) {
        $b[] = '<span class="badge">Plattform-Benutzer</span>';
    }
    return implode(' ', $b);
};
$detailsKurz = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return '';
    }
    unset($d['support_session']);
    $s = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return mb_strlen((string)$s) > 160 ? mb_substr((string)$s, 0, 157) . '...' : (string)$s;
};
$roleLabel = ['owner' => 'Inhaber', 'admin' => 'Administrator', 'member' => 'Mitarbeiter'];

layout_header($org ? 'Kunde: ' . $org['name'] : 'Benutzer: ' . ($user['email'] ?? ''), $ctx);
?>
<h1><?= $org ? 'Kundenprofil: ' . e($org['name']) : 'Benutzerprofil: ' . e((string)($user['email'] ?? '')) ?></h1>
<p class="page-sub"><a href="admin-support.php">Zurück zum Support</a><?php if ($org && $user): ?> · <a href="admin-kunde.php?org=<?= e($org['id']) ?>">Firma <?= e($org['name']) ?></a><?php endif; ?>
    · Kontaktdaten pflegt der Support mit Grund und Protokoll<?= $canEdit ? '' : ' (Ihre Rolle: nur lesend)' ?>. Gläubiger-Identifikationsnummer, SEPA-Einstellungen, Tarif, Verbindungen, E-Mail-Adresse und Zugangsdaten bleiben der Firma vorbehalten.</p>
<?= layout_subnav(admin_subnav_items($ctx), 'support', 'Adminbereiche') ?>

<?php if ($org && !$user): ?>
<?php $members = customer_org_members($org['id']); $lockUntil = invoice_source_lock_until($org['invoice_source_changed_at'] ?? null); ?>
<div class="card" id="firma">
    <h2>Firma</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="org_update">
        <input type="hidden" name="org_id" value="<?= e($org['id']) ?>">
        <div class="form-row">
            <div>
                <label for="name">Firmenname</label>
                <input type="text" id="name" name="name" value="<?= e((string)$org['name']) ?>" maxlength="255" required <?= $canEdit ? '' : 'readonly' ?>>
            </div>
            <div>
                <label for="street">Straße und Hausnummer</label>
                <input type="text" id="street" name="street" value="<?= e((string)($org['street'] ?? '')) ?>" maxlength="255" <?= $canEdit ? '' : 'readonly' ?>>
            </div>
        </div>
        <div class="form-row">
            <div>
                <label for="zip">PLZ</label>
                <input type="text" id="zip" name="zip" value="<?= e((string)($org['zip'] ?? '')) ?>" maxlength="20" <?= $canEdit ? '' : 'readonly' ?>>
            </div>
            <div>
                <label for="city">Ort</label>
                <input type="text" id="city" name="city" value="<?= e((string)($org['city'] ?? '')) ?>" maxlength="100" <?= $canEdit ? '' : 'readonly' ?>>
            </div>
            <div>
                <label for="country">Land (Code)</label>
                <input type="text" id="country" name="country" value="<?= e((string)($org['country'] ?? 'DE')) ?>" maxlength="2" pattern="[A-Za-z]{2}" <?= $canEdit ? '' : 'readonly' ?>>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <div class="form-row">
            <div>
                <label for="reason">Grund der Änderung (Pflicht, wird protokolliert und dem Inhaber mitgeteilt)</label>
                <input type="text" id="reason" name="reason" required minlength="5" maxlength="255" placeholder="Ticketnummer, Anruf vom ..., Schreiben des Kunden">
            </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn">Firmendaten speichern</button></div>
        <?php endif; ?>
    </form>
    <div class="table-wrap" style="margin-top: 14px;">
        <table class="table-sm">
            <tbody>
            <tr><th style="width: 260px;">Inhaber</th><td><?= e((string)($org['owner_email'] ?? '-')) ?></td></tr>
            <tr><th>Registriert</th><td><?= format_datetime($org['created_at']) ?> · Herkunft <?= e((string)($org['signup_domain'] ?: 'direkt')) ?><?= $org['utm_source'] ? ' · ' . e((string)$org['utm_source']) : '' ?></td></tr>
            <tr><th>Tarif und Abonnement</th><td><?= e($planByCode[$org['plan_code']]['name'] ?? (string)$org['plan_code']) ?> · <?= e(subscription_status_label((string)$org['subscription_status'])) ?><?= (int)$org['billing_exempt'] ? ' · abrechnungsbefreit' : '' ?><?= $org['subscription_period_end'] ? ' · Periode bis ' . format_date($org['subscription_period_end']) : '' ?><?= (int)$org['cancel_at_period_end'] ? ' · gekündigt zum Periodenende' : '' ?> <span class="hint">(Tarif nur unter Plattform-Administration änderbar)</span></td></tr>
            <tr><th>Einrichtung</th><td><?= (int)$org['onboarding_completed'] ? 'abgeschlossen' : 'offen (Schritt ' . (int)$org['onboarding_step'] . ')' ?><?= (int)$org['collections_paused'] ? ' · <span class="badge badge-warn">Not-Stopp aktiv</span>' : '' ?></td></tr>
            <tr><th>Buchhaltungssystem</th><td><?= e(invoice_source_label((string)($org['invoice_source'] ?? 'lexware_office'))) ?><?php if ($lockUntil !== null && $lockUntil > gmdate('Y-m-d H:i:s')): ?> <span class="hint">(Wechselsperre bis <?= e(format_date($lockUntil)) ?>)</span><?php endif; ?>
                · Lexware Office: <?= (int)($org['lexoffice_connected'] ?? 0) ? 'verbunden' . ($org['lexoffice_company_name'] ? ' (' . e((string)$org['lexoffice_company_name']) . ')' : '') : 'nicht verbunden' ?>
                · sevdesk: <?= (int)($org['sevdesk_connected'] ?? 0) ? 'verbunden' : 'nicht verbunden' ?>
                · Stripe: <?= (int)($org['stripe_connected'] ?? 0) ? 'verbunden' . ($org['stripe_mode'] ? ' (' . e((string)$org['stripe_mode']) . ')' : '') . ($org['stripe_business_name'] ? ', ' . e((string)$org['stripe_business_name']) : '') : 'nicht verbunden' ?>
                · letzter Sync <?= format_datetime($org['lexoffice_last_sync'] ?: $org['sevdesk_last_sync']) ?></td></tr>
            <tr><th>SEPA (nur lesend)</th><td>Gläubiger-ID <?= $org['creditor_identifier'] ? e((string)$org['creditor_identifier']) : '<span class="hint">nicht hinterlegt</span>' ?> · Mandatspräfix <?= e((string)($org['mandate_prefix'] ?: '-')) ?>
                · Vorabankündigung <?= (int)$org['send_pre_notification'] ? 'ja, ' . (int)$org['pre_notification_days'] . ' Tage' : 'nein' ?> · unterschriebenes Mandat <?= (int)$org['require_signed_mandate'] ? 'Pflicht' : 'nicht verlangt' ?>
                <?= (int)$org['professional_secrecy'] ? ' · Verschwiegenheitspflicht (' . e((string)($org['professional_secrecy_kind'] ?? '')) . ')' : '' ?></td></tr>
            <tr><th>Bestand</th><td><?= (int)$org['customers_count'] ?> Kunden · <?= (int)$org['mandates_active'] ?> aktive Mandate · <?= (int)$org['collections_count'] ?> Einzüge · <?= (int)$org['tickets_open'] ?> offene Support-Anfragen</td></tr>
            <tr><th>Kennung</th><td><code><?= e($org['id']) ?></code></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="benutzer">
    <h2>Benutzer der Firma (<?= count($members) ?>)</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>E-Mail</th><th>Name</th><th>Telefon</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['email']) ?></td>
                    <td><?= e(trim(((string)($m['first_name'] ?? '')) . ' ' . ((string)($m['last_name'] ?? ''))) ?: (string)($m['display_name'] ?? '')) ?><?= $m['display_name'] && ($m['first_name'] || $m['last_name']) ? '<br><small class="hint">' . e((string)$m['display_name']) . '</small>' : '' ?></td>
                    <td><?= $m['phone_business'] ? e((string)$m['phone_business']) . '<br><small class="hint">geschäftlich</small>' : '' ?><?= $m['phone_private'] ? ($m['phone_business'] ? '<br>' : '') . e((string)$m['phone_private']) . '<br><small class="hint">privat</small>' : '' ?><?= !$m['phone_business'] && !$m['phone_private'] ? '<span class="hint">keine</span>' : '' ?></td>
                    <td><?= e($roleLabel[$m['role']] ?? (string)$m['role']) ?><?= $m['member_status'] !== 'active' ? ' <span class="badge badge-warn">' . e((string)$m['member_status']) . '</span>' : '' ?></td>
                    <td><?= $kontostatus($m) ?></td>
                    <td><?= $m['last_login_at'] ? format_datetime($m['last_login_at']) : '<span class="hint">noch nie</span>' ?></td>
                    <td><a class="btn btn-sm btn-secondary" href="admin-kunde.php?user=<?= e($m['id']) ?>&amp;org=<?= e($org['id']) ?>">Profil</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$members): ?><tr><td colspan="7" class="hint">Keine Mitglieder.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="anfragen">
    <h2>Support-Anfragen und Sitzungen</h2>
    <?php $tickets = customer_org_tickets($org['id']); $sessions = customer_org_support_sessions($org['id']); ?>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Anfrage</th><th>Von</th><th>Status</th><th>Zuletzt</th></tr></thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
                <tr><td><a href="admin-support.php?ticket=<?= e($t['id']) ?>"><?= e($t['subject']) ?></a> <small class="hint"><?= e((string)$t['category']) ?></small></td><td><?= e($t['user_email']) ?></td><td><?= e(TICKET_STATUS_LABEL[$t['status']] ?? (string)$t['status']) ?></td><td><?= format_datetime($t['last_message_at']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$tickets): ?><tr><td colspan="4" class="hint">Keine Anfragen.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="table-wrap" style="margin-top: 12px;">
        <table class="table-sm">
            <thead><tr><th>Support-Sitzung</th><th>Mitarbeiter</th><th>Grund</th><th>Eingelöst</th><th>Beendet</th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr><td><?= format_datetime($s['created_at']) ?></td><td><?= e($s['admin_email']) ?></td><td><?= e($s['reason']) ?></td><td><?= $s['redeemed_at'] ? format_datetime($s['redeemed_at']) : 'nein' ?></td><td><?= $s['ended_at'] ? format_datetime($s['ended_at']) . ' (' . e((string)$s['ended_by']) . ')' : (strtotime((string)$s['expires_at']) > time() ? 'läuft' : 'abgelaufen') ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$sessions): ?><tr><td colspan="5" class="hint">Keine Support-Sitzungen.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="protokoll">
    <h2>Protokoll der Firma (letzte 25 Einträge, Aufbewahrung 90 Tage)</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Zeit</th><th>Wer</th><th>Aktion</th><th>Ziel</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach (customer_org_audit($org['id']) as $a): ?>
                <tr><td><?= format_datetime($a['created_at']) ?></td><td><?= e((string)($a['user_email'] ?? 'System')) ?></td><td><code><?= e($a['action']) ?></code></td><td><?= e((string)($a['target_type'] ?? '')) ?></td><td class="hint"><?= e($detailsKurz($a['details_json'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($user): ?>
<?php $orgsOfUser = customer_user_orgs($user['id']); $istPlattform = !empty($user['platform_role']) || (int)$user['is_superadmin'] === 1; $userEdit = $canEdit && (!$istPlattform || platform_actor_is_admin($ctx)) && (int)$user['is_superadmin'] !== 1; ?>
<div class="card" id="benutzerprofil">
    <h2>Benutzer</h2>
    <p><?= $kontostatus($user) ?></p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="user_update">
        <input type="hidden" name="user_id" value="<?= e($user['id']) ?>">
        <?php if ($org): ?><input type="hidden" name="org_id" value="<?= e($org['id']) ?>"><?php endif; ?>
        <div class="form-row">
            <div>
                <label for="email">E-Mail-Adresse (Anmeldename, nur der Benutzer selbst kann sie ändern)</label>
                <input type="email" id="email" value="<?= e($user['email']) ?>" readonly>
            </div>
            <div>
                <label for="display_name">Anzeigename</label>
                <input type="text" id="display_name" name="display_name" value="<?= e((string)($user['display_name'] ?: user_display_name($user))) ?>" maxlength="100" required <?= $userEdit ? '' : 'readonly' ?>>
            </div>
        </div>
        <div class="form-row">
            <div>
                <label for="first_name">Vorname</label>
                <input type="text" id="first_name" name="first_name" value="<?= e((string)($user['first_name'] ?? '')) ?>" maxlength="100" <?= $userEdit ? '' : 'readonly' ?>>
            </div>
            <div>
                <label for="last_name">Nachname</label>
                <input type="text" id="last_name" name="last_name" value="<?= e((string)($user['last_name'] ?? '')) ?>" maxlength="100" <?= $userEdit ? '' : 'readonly' ?>>
            </div>
        </div>
        <div class="form-row">
            <div>
                <label for="phone_business">Telefon geschäftlich</label>
                <input type="text" id="phone_business" name="phone_business" value="<?= e((string)($user['phone_business'] ?? '')) ?>" maxlength="40" placeholder="+49 ..." <?= $userEdit ? '' : 'readonly' ?>>
            </div>
            <div>
                <label for="phone_private">Telefon privat</label>
                <input type="text" id="phone_private" name="phone_private" value="<?= e((string)($user['phone_private'] ?? '')) ?>" maxlength="40" placeholder="+49 ..." <?= $userEdit ? '' : 'readonly' ?>>
            </div>
        </div>
        <?php if ($userEdit): ?>
        <div class="form-row">
            <div>
                <label for="reason_user">Grund der Änderung (Pflicht, wird protokolliert und dem Benutzer mitgeteilt)</label>
                <input type="text" id="reason_user" name="reason" required minlength="5" maxlength="255" placeholder="Ticketnummer, Anruf vom ..., Schreiben des Kunden">
            </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn">Profildaten speichern</button></div>
        <?php elseif ($istPlattform): ?>
        <p class="hint">Plattform-Benutzer pflegen ihre Daten selbst; Eingriffe nur durch einen Administrator.</p>
        <?php endif; ?>
    </form>
    <div class="table-wrap" style="margin-top: 14px;">
        <table class="table-sm">
            <tbody>
            <tr><th style="width: 260px;">Konto angelegt</th><td><?= format_datetime($user['created_at']) ?></td></tr>
            <tr><th>Letzte Anmeldung</th><td><?= $user['last_login_at'] ? format_datetime($user['last_login_at']) : 'noch nie' ?> · Fehlversuche <?= (int)$user['failed_login_count'] ?></td></tr>
            <tr><th>Zwei-Faktor</th><td><?= (int)$user['totp_enabled'] === 1 ? 'aktiv seit ' . format_datetime($user['totp_confirmed_at']) : 'nicht eingerichtet' ?> <span class="hint">(Zurücksetzen und Entsperren unter Support, Abschnitt Benutzer)</span></td></tr>
            <tr><th>E-Mail bestätigt</th><td><?= $user['email_verified_at'] ? format_datetime($user['email_verified_at']) : 'nein' ?></td></tr>
            <tr><th>Mehrere Firmen</th><td><?= count($orgsOfUser) > 1 ? 'ja (' . count($orgsOfUser) . ')' : ((int)$user['multiaccount_enabled'] ? 'manuell aktiviert' : 'nein') ?></td></tr>
            <tr><th>Kennung</th><td><code><?= e($user['id']) ?></code></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="firmen">
    <h2>Firmen dieses Benutzers (<?= count($orgsOfUser) ?>)</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Firma</th><th>Rolle</th><th>Mitglied seit</th><th>Tarif / Abo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orgsOfUser as $o): ?>
                <tr><td><?= e($o['name']) ?><?= (int)$o['collections_paused'] ? ' <span class="badge badge-warn">Not-Stopp</span>' : '' ?></td><td><?= e($roleLabel[$o['role']] ?? (string)$o['role']) ?><?= $o['member_status'] !== 'active' ? ' (' . e((string)$o['member_status']) . ')' : '' ?></td><td><?= format_date($o['member_since']) ?></td><td><?= e($planByCode[$o['plan_code']]['name'] ?? (string)$o['plan_code']) ?> · <?= e(subscription_status_label((string)$o['subscription_status'])) ?></td><td><a class="btn btn-sm btn-secondary" href="admin-kunde.php?org=<?= e($o['id']) ?>">Firma</a></td></tr>
            <?php endforeach; ?>
            <?php if (!$orgsOfUser): ?><tr><td colspan="5" class="hint">Keine Firmenmitgliedschaft<?= $istPlattform ? ' (Plattform-Benutzer)' : '' ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="protokoll-benutzer">
    <h2>Protokoll des Benutzers (letzte 25 Einträge)</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Zeit</th><th>Aktion</th><th>Ziel</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach (customer_user_audit($user['id']) as $a): ?>
                <tr><td><?= format_datetime($a['created_at']) ?></td><td><code><?= e($a['action']) ?></code></td><td><?= e((string)($a['target_type'] ?? '')) ?></td><td class="hint"><?= e($detailsKurz($a['details_json'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
