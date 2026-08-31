<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/crypto.php';
require_once __DIR__ . '/app/lexoffice.php';
require_once __DIR__ . '/app/stripe.php';

$ctx = require_role(['owner', 'admin']);
$tenantId = $ctx['org_id'];
$pdo = db();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_lexoffice') {
            $key = trim($_POST['lexoffice_api_key'] ?? '');
            if ($key === '') {
                throw new RuntimeException('Bitte einen API-Key eingeben.');
            }
            // Verbindungstest vor dem Speichern
            (new LexofficeClient($key))->getProfile();
            $pdo->prepare(
                'UPDATE integrations SET lexoffice_api_key_encrypted = ?, lexoffice_connected = 1 WHERE tenant_id = ?'
            )->execute([encrypt_value($key), $tenantId]);
            flash_set('success', 'Lexoffice erfolgreich verbunden.');

        } elseif ($action === 'disconnect_lexoffice') {
            $pdo->prepare(
                'UPDATE integrations SET lexoffice_api_key_encrypted = NULL, lexoffice_connected = 0 WHERE tenant_id = ?'
            )->execute([$tenantId]);
            flash_set('success', 'Lexoffice-Verbindung getrennt.');

        } elseif ($action === 'save_stripe') {
            $secretKey = trim($_POST['stripe_secret_key'] ?? '');
            $webhookSecret = trim($_POST['stripe_webhook_secret'] ?? '');
            if ($secretKey === '') {
                throw new RuntimeException('Bitte den Stripe Secret Key eingeben.');
            }
            // Verbindungstest vor dem Speichern
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
            flash_set('success', 'Stripe erfolgreich verbunden.');

        } elseif ($action === 'disconnect_stripe') {
            $pdo->prepare(
                'UPDATE integrations SET stripe_secret_key_encrypted = NULL, stripe_webhook_secret_encrypted = NULL, stripe_connected = 0 WHERE tenant_id = ?'
            )->execute([$tenantId]);
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
<p class="page-sub">API-Verbindungen für <?= e($ctx['org_name']) ?></p>

<div class="card">
    <h2>Lexoffice
        <?= (int)$integration['lexoffice_connected']
            ? '<span class="badge badge-success">Verbunden</span>'
            : '<span class="badge badge-neutral">Nicht verbunden</span>' ?>
    </h2>
    <?php if ((int)$integration['lexoffice_connected']): ?>
        <p class="hint">Letzte Synchronisation:
            <?= format_datetime($integration['lexoffice_last_sync']) ?></p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disconnect_lexoffice">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Lexoffice-Verbindung wirklich trennen?')">Verbindung trennen</button>
        </form>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_lexoffice">
            <label for="lexoffice_api_key">Lexoffice API-Key</label>
            <input type="password" id="lexoffice_api_key" name="lexoffice_api_key" required
                   autocomplete="off" placeholder="API-Key aus app.lexoffice.de/addons/public-api">
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
    <?php if ((int)$integration['stripe_connected']): ?>
        <p class="hint">Webhook-Endpunkt für das Stripe-Dashboard:</p>
        <p><code class="copy"><?= e($webhookUrl) ?></code></p>
        <p class="hint">Zu abonnierende Events: payment_intent.processing, payment_intent.succeeded,
            payment_intent.payment_failed, charge.dispute.created</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disconnect_stripe">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Stripe-Verbindung wirklich trennen?')">Verbindung trennen</button>
        </form>
    <?php else: ?>
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
