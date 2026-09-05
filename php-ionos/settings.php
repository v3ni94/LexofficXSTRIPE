<?php
/**
 * Einstellungen: API-Verbindungen zu Lexware Office und Stripe (je Firma).
 * Ändern dürfen Inhaber und Administratoren; Mitarbeiter sehen den Status.
 * Jede Änderung wird protokolliert und dem Inhaber gemeldet. Zugangsdaten
 * werden vor dem Speichern gegen die jeweilige API geprüft, verschlüsselt
 * abgelegt und nie wieder im Browser angezeigt.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/integrations.php';
require_once __DIR__ . '/app/mailer.php';

$ctx = require_login();
$tenantId = $ctx['org_id'];
$pdo = db();
$canEdit = can_manage_settings($ctx);

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
    $integration = integration_load($tenantId);

    try {
        if ($action === 'save_lexoffice') {
            support_guard();
            $key = trim($_POST['lexoffice_api_key'] ?? '');
            if ($key === '') {
                throw new RuntimeException('Bitte einen API-Schlüssel eingeben.');
            }
            $info = integration_verify_lexoffice($tenantId, $key);
            $pdo->prepare(
                'UPDATE integrations SET lexoffice_api_key_encrypted = ?, lexoffice_connected = 1, lexoffice_disconnected_at = NULL WHERE tenant_id = ?'
            )->execute([encrypt_value($key), $tenantId]);
            audit_log($tenantId, $ctx, 'lexoffice_connected', 'integration', $tenantId, ['company' => $info['company_name']]);
            funnel_event_once($tenantId, 'lexware_connected', $ctx['user_id']);
            notify_integration_change($ctx, 'Lexware-Office-Verbindung eingerichtet');
            flash_set('success', 'Lexware Office erfolgreich verbunden' . ($info['company_name'] ? ' (' . $info['company_name'] . ')' : '') . '.');

        } elseif ($action === 'verify_lexoffice') {
            $key = integration_lexoffice_key($integration);
            if ($key === null) {
                throw new RuntimeException('Es ist kein Lexware-Office-Schlüssel hinterlegt.');
            }
            $info = integration_verify_lexoffice($tenantId, $key);
            audit_log($tenantId, $ctx, 'lexoffice_verified', 'integration', $tenantId);
            flash_set('success', 'Lexware-Office-Verbindung geprüft: Zugriff funktioniert' . ($info['company_name'] ? ' (' . $info['company_name'] . ')' : '') . '.');

        } elseif ($action === 'disconnect_lexoffice') {
            support_guard();
            $pdo->prepare(
                'UPDATE integrations SET lexoffice_api_key_encrypted = NULL, lexoffice_connected = 0, lexoffice_disconnected_at = NOW() WHERE tenant_id = ?'
            )->execute([$tenantId]);
            audit_log($tenantId, $ctx, 'lexoffice_disconnected', 'integration', $tenantId);
            notify_integration_change($ctx, 'Lexware-Office-Verbindung getrennt');
            flash_set('success', 'Lexware-Office-Verbindung getrennt. Bereits synchronisierte Rechnungen und Kunden bleiben erhalten.');

        } elseif ($action === 'save_stripe') {
            support_guard();
            $secretKey = trim($_POST['stripe_secret_key'] ?? '');
            $webhookSecret = trim($_POST['stripe_webhook_secret'] ?? '');
            if ($secretKey === '') {
                throw new RuntimeException('Bitte den Stripe Secret Key eingeben.');
            }
            if (!preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $secretKey)) {
                throw new RuntimeException('Das Format entspricht keinem Stripe Secret Key oder Restricted Key (sk_… oder rk_…). Publishable Keys (pk_…) sind hier nicht verwendbar.');
            }
            $info = integration_verify_stripe($tenantId, $secretKey);
            if ($webhookSecret !== '') {
                $pdo->prepare(
                    'UPDATE integrations SET stripe_secret_key_encrypted = ?, stripe_webhook_secret_encrypted = ?, stripe_connected = 1, stripe_disconnected_at = NULL WHERE tenant_id = ?'
                )->execute([encrypt_value($secretKey), encrypt_value($webhookSecret), $tenantId]);
            } else {
                $pdo->prepare(
                    'UPDATE integrations SET stripe_secret_key_encrypted = ?, stripe_connected = 1, stripe_disconnected_at = NULL WHERE tenant_id = ?'
                )->execute([encrypt_value($secretKey), $tenantId]);
            }
            audit_log($tenantId, $ctx, 'stripe_connected', 'integration', $tenantId, [
                'webhook_secret' => $webhookSecret !== '', 'account' => $info['account_id'], 'mode' => $info['mode'],
            ]);
            funnel_event_once($tenantId, 'stripe_connected', $ctx['user_id']);
            notify_integration_change($ctx, 'Stripe-Verbindung eingerichtet (' . $info['mode'] . ')');
            flash_set('success', 'Stripe erfolgreich verbunden: ' . ($info['business_name'] ?: $info['account_id'])
                . ($info['mode'] === 'test' ? '. Achtung: Testmodus, es werden keine echten Lastschriften ausgeführt.' : '.'));

        } elseif ($action === 'verify_stripe') {
            $key = integration_stripe_key($integration);
            if ($key === null) {
                throw new RuntimeException('Es ist kein Stripe-Schlüssel hinterlegt.');
            }
            $info = integration_verify_stripe($tenantId, $key);
            audit_log($tenantId, $ctx, 'stripe_verified', 'integration', $tenantId, ['account' => $info['account_id']]);
            flash_set('success', 'Stripe-Verbindung geprüft: Zugriff auf ' . ($info['business_name'] ?: $info['account_id']) . ' funktioniert.');

        } elseif ($action === 'save_webhook_secret') {
            support_guard();
            $webhookSecret = trim($_POST['stripe_webhook_secret'] ?? '');
            if ($webhookSecret === '') {
                throw new RuntimeException('Bitte das Webhook-Secret eingeben.');
            }
            $pdo->prepare('UPDATE integrations SET stripe_webhook_secret_encrypted = ? WHERE tenant_id = ?')
                ->execute([encrypt_value($webhookSecret), $tenantId]);
            audit_log($tenantId, $ctx, 'stripe_connected', 'integration', $tenantId, ['webhook_secret_updated' => true]);
            flash_set('success', 'Webhook-Secret gespeichert und verschlüsselt hinterlegt. Stripe-Meldungen an den Webhook werden ab jetzt geprüft und verarbeitet.');

        } elseif ($action === 'disconnect_stripe') {
            support_guard();
            $pdo->prepare(
                'UPDATE integrations SET stripe_secret_key_encrypted = NULL, stripe_webhook_secret_encrypted = NULL, stripe_connected = 0, stripe_disconnected_at = NOW() WHERE tenant_id = ?'
            )->execute([$tenantId]);
            audit_log($tenantId, $ctx, 'stripe_disconnected', 'integration', $tenantId);
            notify_integration_change($ctx, 'Stripe-Verbindung getrennt');
            flash_set('success', 'Stripe-Verbindung getrennt. Vorhandene Einzüge und Mandate bleiben erhalten, neue Einzüge sind bis zur erneuten Verbindung nicht möglich.');
        }
    } catch (Throwable $e) {
        // Fehlermeldungen externer APIs können Details enthalten; Schlüssel selbst tauchen darin nicht auf.
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('settings.php');
}

$integration = integration_load($tenantId);
$webhookUrl = app_base_url() . '/stripe-webhook.php';
$productName = product_name();

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
        (<?= e((string)config('lexware_api_base_url', LexofficeClient::DEFAULT_BASE_URL)) ?>).
        Der Schlüssel wird vor dem Speichern getestet, verschlüsselt abgelegt und danach nicht mehr angezeigt.</p>

    <?php if ((int)$integration['lexoffice_connected']): ?>
        <dl class="kv">
            <dt>Konto</dt><dd><?= e($integration['lexoffice_company_name'] ?: 'Firmenname nicht übermittelt') ?></dd>
            <dt>Zuletzt geprüft</dt><dd><?= $integration['lexoffice_last_verified_at'] ? e(format_datetime($integration['lexoffice_last_verified_at'])) : 'noch nicht geprüft' ?></dd>
            <dt>Letzte Synchronisation</dt><dd><?= $integration['lexoffice_last_sync'] ? e(format_datetime($integration['lexoffice_last_sync'])) : 'noch keine' ?></dd>
        </dl>
        <?php if ($canEdit): ?>
        <div class="form-actions" style="gap: 8px; display: flex; flex-wrap: wrap;">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_lexoffice">
                <button type="submit" class="btn btn-secondary btn-sm">Verbindung prüfen</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="disconnect_lexoffice">
                <button type="submit" class="btn btn-danger btn-sm"
                        onclick="return confirm('Lexware-Office-Verbindung wirklich trennen? Bereits synchronisierte Daten bleiben erhalten.')">Verbindung trennen</button>
            </form>
        </div>
        <?php endif; ?>
    <?php elseif ($canEdit): ?>
        <details class="guide" open>
            <summary>So erstellen Sie den API-Schlüssel in Lexware Office</summary>
            <ol>
                <li>In Lexware Office anmelden und <span class="path">Einstellungen</span> öffnen.</li>
                <li>Zu <span class="path">Erweiterungen</span> wechseln, dort <span class="path">Weitere Apps</span> beziehungsweise <span class="path">Public API</span> wählen.</li>
                <li>Bei <span class="path">Public API</span> auf <span class="path">Verwalten</span> klicken.</li>
                <li><span class="path">Schlüssel erstellen</span> wählen und eine Bezeichnung vergeben, zum Beispiel <span class="path"><?= e($productName) ?></span>.</li>
                <li>Den erzeugten Schlüssel sofort kopieren. Lexware Office zeigt ihn nur einmal an.</li>
                <li>Den Schlüssel unten einfügen und auf <span class="path">Verbindung herstellen</span> klicken.</li>
            </ol>
            <p class="hint" style="margin-top:10px">Hinweis: Nach Angaben von Lexware setzt die Public API derzeit den Tarif Lexware Office XL voraus.
                Fehlt der Menüpunkt in Ihrem Konto, prüfen Sie zunächst den gebuchten Tarif. Menüführung und Tarifbedingungen können sich ändern.</p>
        </details>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_lexoffice">
            <label for="lexoffice_api_key">Lexware Office API-Schlüssel</label>
            <input type="password" id="lexoffice_api_key" name="lexoffice_api_key" required
                   autocomplete="off" placeholder="Schlüssel aus Lexware Office (Public API)">
            <div class="form-actions">
                <button type="submit" class="btn">Verbindung herstellen</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Stripe
        <?php if ((int)$integration['stripe_connected']): ?>
            <span class="badge badge-success">Verbunden</span>
            <?php if (($integration['stripe_mode'] ?? '') === 'test'): ?><span class="badge badge-warn">Testmodus</span><?php endif; ?>
        <?php else: ?>
            <span class="badge badge-neutral">Nicht verbunden</span>
        <?php endif; ?>
    </h2>
    <p class="hint">Ihr eigenes Stripe-Konto. Stripe verarbeitet die SEPA-Lastschriften, <?= e($productName) ?> steuert den Ablauf.
        Zahlungen laufen ausschließlich über Ihr Konto.</p>

    <?php if ((int)$integration['stripe_connected']): ?>
        <dl class="kv">
            <dt>Business Name</dt><dd><?= e($integration['stripe_business_name'] ?: 'nicht übermittelt') ?></dd>
            <dt>Konto-ID</dt><dd><code><?= e($integration['stripe_account_id'] ?: 'unbekannt') ?></code></dd>
            <dt>Modus</dt><dd><?= ($integration['stripe_mode'] ?? '') === 'test' ? 'Test (keine echten Zahlungen)' : (($integration['stripe_mode'] ?? '') === 'live' ? 'Live' : 'unbekannt, bitte Verbindung prüfen') ?></dd>
            <dt>Zuletzt geprüft</dt><dd><?= $integration['stripe_last_verified_at'] ? e(format_datetime($integration['stripe_last_verified_at'])) : 'noch nicht geprüft' ?></dd>
            <dt>Webhook-Secret</dt><dd><?= $integration['stripe_webhook_secret_encrypted'] ? '<span class="badge badge-success">hinterlegt</span>' : '<span class="badge badge-warn">fehlt (Statusänderungen und Rücklastschriften werden nur beim manuellen Abgleich erkannt)</span>' ?></dd>
        </dl>
        <details class="guide" <?= $integration['stripe_webhook_secret_encrypted'] ? '' : 'open' ?>>
            <summary>Webhook einrichten: so meldet Stripe den Einzugsstatus automatisch zurück</summary>
            <p class="hint" style="margin-top:8px">Ohne Webhook erfährt <?= e($productName) ?> nur beim manuellen Abgleich und über den Cronjob, ob eine Lastschrift erfolgreich war, fehlgeschlagen ist oder zurückgebucht wurde. Mit Webhook kommt die Rückmeldung sofort. Der Webhook wird in Ihrem eigenen Stripe-Konto angelegt, einmalig, in etwa drei Minuten.</p>
            <ol>
                <li>Im Stripe-Dashboard <span class="path">Entwickler</span> &gt; <span class="path">Webhooks</span> öffnen und <span class="path">Endpunkt hinzufügen</span> wählen. Prüfen Sie, dass der Modus (Test oder Live) zu Ihrem hinterlegten Schlüssel passt.</li>
                <li>Als Endpunkt-URL genau diese Adresse eintragen: <code class="copy"><?= e($webhookUrl) ?></code></li>
                <li>Unter <span class="path">Ereignisse auswählen</span> nur die folgenden sieben Ereignisse anhaken. Bitte nicht "alle Ereignisse" wählen: Stripe würde dann bei jeder Kontobewegung Meldungen schicken, die verworfen werden und den Abgleich nur verlangsamen.
                    <ul style="margin:8px 0 0 18px">
                        <li><span class="path">payment_intent.processing</span> (Lastschrift eingereicht)</li>
                        <li><span class="path">payment_intent.succeeded</span> (Lastschrift erfolgreich)</li>
                        <li><span class="path">payment_intent.payment_failed</span> (Lastschrift fehlgeschlagen)</li>
                        <li><span class="path">charge.dispute.created</span> (Rücklastschrift)</li>
                        <li><span class="path">charge.refunded</span> (Erstattung ausgeführt)</li>
                        <li><span class="path">charge.refund.updated</span> (Erstattungsstand geändert)</li>
                        <li><span class="path">checkout.session.completed</span> (digital erteiltes Mandat)</li>
                    </ul>
                </li>
                <li><span class="path">Endpunkt hinzufügen</span> klicken. Auf der Detailseite <span class="path">Signing Secret</span> anzeigen lassen (beginnt mit <span class="path">whsec_</span>) und kopieren.</li>
                <li>Das Secret unten in das Feld eintragen und speichern. Es wird verschlüsselt abgelegt und dient nur zur Prüfung, dass Meldungen wirklich von Stripe stammen.</li>
                <li>Prüfen: In Stripe auf der Endpunkt-Seite <span class="path">Testereignis senden</span> wählen. Der Endpunkt muss mit Status 200 antworten.</li>
            </ol>
            <p class="hint">Wechseln Sie später den Stripe-Schlüssel oder das Konto, legen Sie den Webhook im neuen Konto erneut an und tragen das neue Secret hier ein.</p>
        </details>
        <?php if ($canEdit): ?>
        <?php if ($integration['stripe_webhook_secret_encrypted']): ?>
        <details class="secret-change" style="margin-bottom: 12px;">
            <summary class="btn btn-sm btn-ghost">Webhook-Secret ändern</summary>
            <form method="post" class="inline-form" style="margin-top: 8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_webhook_secret">
                <input type="password" name="stripe_webhook_secret" placeholder="neues Secret, whsec_..." autocomplete="off" style="max-width: 320px;">
                <button type="submit" class="btn btn-sm btn-secondary">Neues Webhook-Secret speichern</button>
            </form>
            <p class="hint">Das gespeicherte Secret wird nicht angezeigt. Nur nötig, wenn Sie den Webhook in Stripe neu angelegt haben.</p>
        </details>
        <?php else: ?>
        <form method="post" class="inline-form" style="margin-bottom: 12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_webhook_secret">
            <input type="password" name="stripe_webhook_secret" placeholder="whsec_..." autocomplete="off" style="max-width: 320px;">
            <button type="submit" class="btn btn-sm btn-secondary">Webhook-Secret speichern</button>
        </form>
        <?php endif; ?>
        <div class="form-actions" style="gap: 8px; display: flex; flex-wrap: wrap;">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_stripe">
                <button type="submit" class="btn btn-secondary btn-sm">Verbindung prüfen</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="disconnect_stripe">
                <button type="submit" class="btn btn-danger btn-sm"
                        onclick="return confirm('Stripe-Verbindung wirklich trennen? Vorhandene Einzüge und Mandate bleiben erhalten.')">Verbindung trennen</button>
            </form>
        </div>
        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-border);">
            <a class="btn btn-secondary btn-sm" href="stripe-import.php">Bestehende Einzüge aus Stripe übernehmen</a>
            <p class="hint">Einmalig nach einem Neuaufbau oder Wechsel der Installation: Lastschriften, die bereits über dieses Stripe-Konto eingereicht wurden, werden den Rechnungen zugeordnet, damit nichts doppelt eingezogen wird. Nur Lesezugriff auf Stripe.</p>
        </div>
        <?php endif; ?>
    <?php elseif ($canEdit): ?>
        <details class="guide" open>
            <summary>So finden Sie den Stripe-Schlüssel</summary>
            <ol>
                <li>Im Stripe-Dashboard anmelden und <span class="path">Developers</span> &gt; <span class="path">API keys</span> öffnen.</li>
                <li>Oben rechts prüfen, ob der Testmodus aktiv ist. Für echte Lastschriften wird ein Live-Schlüssel (<span class="path">sk_live_…</span>) benötigt, zum Ausprobieren ein Testschlüssel (<span class="path">sk_test_…</span>).</li>
                <li>Empfohlen: unter <span class="path">Restricted keys</span> einen eingeschränkten Schlüssel (<span class="path">rk_…</span>) mit Schreibrecht für Customers, Payment Methods, Payment Intents und Leserecht für Charges und Disputes anlegen.</li>
                <li>Den Schlüssel direkt nach dem Erstellen kopieren. Stripe zeigt Secret Keys nur einmal vollständig an.</li>
                <li>Den Schlüssel unten einfügen. Das Webhook-Secret (<span class="path">whsec_…</span>) erhalten Sie nach dem Anlegen des Endpunkts unter <span class="path">Developers</span> &gt; <span class="path">Webhooks</span> und können es später nachtragen.</li>
            </ol>
        </details>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_stripe">
            <label for="stripe_secret_key">Stripe Secret Key oder Restricted Key</label>
            <input type="password" id="stripe_secret_key" name="stripe_secret_key" required
                   autocomplete="off" placeholder="sk_live_… / rk_live_… (Test: sk_test_…)">
            <label for="stripe_webhook_secret">Stripe Webhook-Secret (optional, später nachtragbar)</label>
            <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret"
                   autocomplete="off" placeholder="whsec_...">
            <p class="hint">Webhook-Endpunkt im Stripe-Dashboard anlegen:
                <code class="copy"><?= e($webhookUrl) ?></code></p>
            <div class="form-actions">
                <button type="submit" class="btn">Verbindung herstellen</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
