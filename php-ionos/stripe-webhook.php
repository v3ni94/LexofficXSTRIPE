<?php
/**
 * Stripe-Webhook-Endpunkt.
 * Multi-Tenant: tenant_id aus den PaymentIntent-Metadaten lesen, dann die
 * Signatur mit dem Webhook-Secret des Mandanten prüfen.
 * Antwortet immer mit HTTP 200, damit Stripe nicht endlos wiederholt.
 * Portiert aus routers/webhooks.py.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/crypto.php';
require_once __DIR__ . '/app/stripe.php';

http_response_code(200);
header('Content-Type: text/plain');

function webhook_exit(string $reason): void
{
    error_log('Stripe-Webhook: ' . $reason);
    echo 'ok';
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($sigHeader === '') {
    webhook_exit('fehlende Signatur');
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    webhook_exit('ungültiges JSON');
}

$eventType = $event['type'] ?? '';
$obj = $event['data']['object'] ?? [];
$tenantId = $obj['metadata']['tenant_id'] ?? null;

if (!$tenantId) {
    webhook_exit("tenant_id nicht in Metadaten gefunden (type=$eventType)");
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $integration = $stmt->fetch();

    if (!$integration || !(int)$integration['stripe_connected']) {
        webhook_exit("keine aktive Stripe-Integration für tenant $tenantId");
    }

    $webhookSecret = decrypt_value($integration['stripe_webhook_secret_encrypted']);
    if (!$webhookSecret) {
        webhook_exit("kein Webhook-Secret für tenant $tenantId");
    }

    if (!stripe_verify_webhook_signature($rawBody, $sigHeader, $webhookSecret)) {
        webhook_exit("Signaturprüfung fehlgeschlagen für tenant $tenantId");
    }

    // --- Collection zum PaymentIntent finden ---
    $paymentIntentId = $eventType === 'charge.dispute.created'
        ? ($obj['payment_intent'] ?? null)
        : ($obj['id'] ?? null);

    if (!$paymentIntentId) {
        webhook_exit('kein PaymentIntent im Event');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM payment_collections WHERE stripe_payment_intent_id = ? AND tenant_id = ?'
    );
    $stmt->execute([$paymentIntentId, $tenantId]);
    $collection = $stmt->fetch();

    if (!$collection) {
        webhook_exit("PaymentCollection nicht gefunden für PI $paymentIntentId");
    }

    switch ($eventType) {
        case 'payment_intent.processing':
            $pdo->prepare("UPDATE payment_collections SET stripe_status = 'processing' WHERE id = ?")
                ->execute([$collection['id']]);
            break;

        case 'payment_intent.succeeded':
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE id = ?"
            )->execute([$collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'collected' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            break;

        case 'payment_intent.payment_failed':
            $reason = $obj['last_payment_error']['message'] ?? 'Unbekannter Fehler';
            $pdo->prepare(
                "UPDATE payment_collections
                 SET stripe_status = 'failed', failure_reason = ?, completed_at = NOW() WHERE id = ?"
            )->execute([$reason, $collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'failed' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            break;

        case 'charge.dispute.created':
            $reason = 'SEPA-Lastschrift wurde vom Kunden widerrufen';
            $pdo->prepare(
                "UPDATE payment_collections
                 SET stripe_status = 'disputed', failure_reason = ?, completed_at = NOW() WHERE id = ?"
            )->execute([$reason, $collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'failed' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            break;

        default:
            // Unbekannte Event-Typen ignorieren
            break;
    }
} catch (Throwable $e) {
    error_log('Stripe-Webhook: unerwarteter Fehler: ' . $e->getMessage());
}

echo 'ok';
