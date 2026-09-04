<?php
/**
 * Einstellungen: API-Verbindungen zu Lexware Office und Stripe (je Firma).
 * Ändern dürfen Inhaber und Administratoren; Mitarbeiter sehen den Status.
 * Jede Änderung wird protokolliert und dem Inhaber gemeldet.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/crypto.php';
require_once __DIR__ . '/app/lexoffice.php';
require_once __DIR__ . '/app/stripe.php';
require_once __DIR__ . '/app/mailer.php';

$ctx = require_login();
$tenantId = $ctx['org_id'];
$pdo = db();
$canEdit = can_manage_settings($ctx);

function load_integration(string $tenantId): array
{
    $stmt = db()->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if (!$row) {
        $id = uuid4();
        db()->prepare('INSERT INTO integrations (id, tenant_id) VALUES (?, ?)')->execute([$id, $tenantId]);
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
    }
    return $row;
}

function notify_integration_change(array $ctx, string $what): void
{
    security_notify_owner($ctx['org_id'], $what, [
        sprintf('%s hat für die Firma %s folgende Änderung vorgenommen: %s.', $ctx['email'], $ctx['org_name'], $what),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        flash_set('error', 'Nur Inhaber und Administratoren dürfen API-Verbindungen ändern.');
        redirect('settings.php');
    }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_lexoffice') {
            $key = trim($_POST['lexoffice_api_key'] ?? '');
            if ($key === '') {
                throw new RuntimeException('Bitte einen API-Key eingeben.');
            }
            (new LexofficeClient($key))->getProfile();
            $pdo->prepare(
                'UPDATE integrations SET lexoffice_api_key_encrypted = ?, lexoffice_connected = 1 WHERE tenant_id = ?'
            )->execute([encrypt_value($key), $tenantId]);
            audit_log($tenantId, $ctx, 'lexoffice_connected', 'integration', $tenantId);
            funnel_event_once($tenantId, 'lexware_connected', $ctx['user_id']);
            notify_integration_change($ctx, 'Lexware-Office-Verbindung eingerichtet');
            flash_set('success', 'Lexware Office erfolgreich verbunden.');

        } elseif ($action === 'disconnect_lexoffice') {
            $pdo->prepare(
                'UPDATE integrations SET lexoffice_api_key_encrypted = NULL, lexoffice_connected = 0 WHERE tenant_id = ?'
            )->execute([$tenantId]);
            audit_log($tenantId, $ctx, 'lexoffice_disconnected', 'integration', $tenantId);
            notify_integration_change($ctx, 'Lexware-Office-Verbindung getrennt');
            flash_set('success', 'Lexware-Office-Verbindung getrennt.');

        } elseif ($action === 'save_stripe') {
            $secretKey = trim($_POST['stripe_secret_key'] ?? '');
            $webhookSecret = trim($_POST['stripe_webhook_secret'] ?? '');
            if ($secretKey === '') {
                throw new RuntimeException('Bitte den Stripe Secret Key eingeben.');
            }
            (new StripeClient($secretKey))->getAccount();
            if ($webhookSecret !== '') {
                $pdo->prepare(
                    'UPDATE integrations SET stripe_secret_key_encrypted = ?, stripe_webhook_secret_encrypted = ?, stripe_connected = 1 WHERE tenant_id = ?'
                )->execute([encrypt_value($secretKey), encrypt_value($webhookSecret), $tenantId]);
            } else {
                $pdo->prepare(
                    'UPDATE integrations SET stripe_secret_key_encrypted = ?, stripe_connected = 1 WHERE tenant_id = ?'
                )->execute([encrypt_value($secretKey), $tenantId]);
            }
            audit_log($tenantId, $ctx, 'stripe_connected', 'integration', $tenantId, ['webhook_secret' => $webhookSecret !== '']);
            funnel_event_once($tenantId, 'stripe_connected', $ctx['user_id']);
            notify_integration_change($ctx, 'Stripe-Verbindung eingerichtet');
            flash_set('success', 'Stripe erfolgreich verbunden.');

        } elseif ($action === 'save_webhook_secret') {
            $webhookSecret = trim($_POST['stripe_webhook_secret'] ?? '');
            if ($webhookSecret === '') {
                throw new RuntimeException('Bitte das Webhook-Secret eingeben.');
            }
            $pdo->prepare('UPDATE integrations SET stripe_webhook_secret_encrypted = ? WHERE tenant_id = ?')
                ->execute([encrypt_value($webhookSecret), $tenantId]);
            audit_log($tenantId, $ctx, 'stripe_connected', 'integration', $tenantId, ['webhook_secret_updated' => true]);
            flash_set('success', 'Webhook-Secret gespeichert.');

        } elseif ($action === 'disconnect_stripe') {
            $pdo->prepare(
                'UPDATE integrations SET stripe_secret_key_encrypted = NULL, stripe_webhook_secret_encrypted = NULL, stripe_connected = 0 WHERE tenant_id = ?'
            )->execute([$tenantId]);
            audit_log($tenantId, $ctx, 'stripe_disconnected', 'integration', $tenantId);
            notify_integration_change($ctx, 'Stripe-Verbindung getrennt');
            flash_set('success', 'Stripe-Verbindung getrennt.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('settings.php');
}

$integration = load_integration($tenantId);
$webhookUrl = rtrim((string)config('base_url'), '/') . '/stripe-webhook.php';

layout_header('Einstellungen', $ctx);
?>
<h1>Einstellungen</h1>
<p class="page-sub">API-Verbindungen für <?= e($ctx['org_name']) ?><?= $canEdit ? '' : ' (nur Ansicht; Änderungen durch Inhaber oder Administrator)' ?>
    · Firmendaten, Gläubiger-ID und SEPA-Regeln unter <a href="team.php">Firma</a></p>

<div class="card">
    <h2>Lexware Office
        <?= (int)$integration['lexoffice_connected']
            ? '<span class="badge badge-success">Verbunden</span>'
            : '<span class="badge badge-neutral">Nicht verbunden</span>' ?>
    </h2>
    <p class="hint">Lexware Office (ehemals lexoffice). Zugriff über die Lexware Public API
        (<?= e((string)config('lexware_api_base_url', LexofficeClient::DEFAULT_BASE_URL)) ?>); der API-Key wird in
        Lexware Office unter Einstellungen &gt; Erweiterungen &gt; Public API erzeugt und benötigt ein entsprechendes Lexware-Office-Abonnement.</p>
    <?php if ((int)$integration['lexoffice_connected']): ?>
        <p class="hint">Letzte Synchronisation: <?= format_datetime($integration['lexoffice_last_sync']) ?></p>
        <?php if ($canEdit): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disconnect_lexoffice">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Lexware-Office-Verbindung wirklich trennen?')">Verbindung trennen</button>
        </form>
        <?php endif; ?>
    <?php elseif ($canEdit): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_lexoffice">
            <label for="lexoffice_api_key">Lexware Office API-Key</label>
            <input type="password" id="lexoffice_api_key" name="lexoffice_api_key" required
                   autocomplete="off" placeholder="API-Key aus Lexware Office (Public API)">
            <p class="hint">Der Key wird vor dem Speichern getestet und verschlüsselt abgelegt.</p>
            <div class="form-actions">
                <button type="submit" class="btn">Verbinden</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Stripe
        <?= (int)$integration['stripe_connected']
            ? '<span class="badge badge-success">Verbunden</span>'
            : '<span class="badge badge-neutral">Nicht verbunden</span>' ?>
    </h2>
    <p class="hint">Ihr eigenes Stripe-Konto. Stripe verarbeitet die SEPA-Lastschriften; die Anwendung steuert den Ablauf.</p>
    <?php if ((int)$integration['stripe_connected']): ?>
        <p class="hint">Webhook-Endpunkt für das Stripe-Dashboard:</p>
        <p><code class="copy"><?= e($webhookUrl) ?></code></p>
        <p class="hint">Zu abonnierende Events: payment_intent.processing, payment_intent.succeeded,
            payment_intent.payment_failed, charge.dispute.created.
            Webhook-Secret: <?= $integration['stripe_webhook_secret_encrypted'] ? '<span class="badge badge-success">hinterlegt</span>' : '<span class="badge badge-warn">fehlt (Rücklastschriften werden nicht erkannt)</span>' ?></p>
        <?php if ($canEdit): ?>
        <form method="post" class="inline-form" style="margin-bottom: 12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_webhook_secret">
            <input type="password" name="stripe_webhook_secret" placeholder="whsec_..." autocomplete="off" style="max-width: 320px;">
            <button type="submit" class="btn btn-sm btn-secondary">Webhook-Secret speichern</button>
        </form>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disconnect_stripe">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Stripe-Verbindung wirklich trennen?')">Verbindung trennen</button>
        </form>
        <?php endif; ?>
    <?php elseif ($canEdit): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_stripe">
            <label for="stripe_secret_key">Stripe Secret Key</label>
            <input type="password" id="stripe_secret_key" name="stripe_secret_key" required
                   autocomplete="off" placeholder="sk_live_... oder sk_test_...">
            <label for="stripe_webhook_secret">Stripe Webhook-Secret (optional, später nachtragbar)</label>
            <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret"
                   autocomplete="off" placeholder="whsec_...">
            <p class="hint">Webhook-Endpunkt im Stripe-Dashboard anlegen:
                <code class="copy"><?= e($webhookUrl) ?></code></p>
            <div class="form-actions">
                <button type="submit" class="btn">Verbinden</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
