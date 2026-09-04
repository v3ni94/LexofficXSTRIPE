<?php
/**
 * Webhook des Plattform-Stripe-Kontos (Abonnements der Firmen).
 * Getrennt von stripe-webhook.php (SEPA-Einzüge der Firmen bei ihren Kunden).
 * Zu abonnierende Events: checkout.session.completed,
 * customer.subscription.created/updated/deleted, invoice.payment_failed.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/stripe.php';
require_once __DIR__ . '/app/billing.php';

http_response_code(200);
header('Content-Type: text/plain');

$b = (array)config('billing', []);
$secret = (string)($b['stripe_webhook_secret'] ?? '');
$rawBody = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($secret === '' || $sigHeader === '' || !stripe_verify_webhook_signature($rawBody, $sigHeader, $secret)) {
    error_log('Billing-Webhook: Signaturprüfung fehlgeschlagen oder nicht konfiguriert');
    http_response_code(400);
    echo 'invalid';
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    echo 'ok';
    exit;
}

try {
    $result = billing_handle_event($event);
    error_log('Billing-Webhook ' . ($event['type'] ?? '?') . ': ' . $result);
} catch (Throwable $e) {
    error_log('Billing-Webhook Fehler: ' . $e->getMessage());
}
echo 'ok';
