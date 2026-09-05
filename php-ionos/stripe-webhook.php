<?php
/**
 * Stripe-Webhook-Endpunkt.
 * Multi-Tenant: tenant_id aus den Metadaten des Objekts (PaymentIntent bzw.
 * Checkout Session) lesen, dann die Signatur mit dem Webhook-Secret des
 * Mandanten prüfen. Antwortet immer mit HTTP 200, damit Stripe nicht endlos
 * wiederholt.
 *
 * Verarbeitete Ereignisse:
 *  - payment_intent.processing / succeeded / payment_failed: Einzugsstatus,
 *    bei Erfolg zusätzlich Charge und Stripe-Mandatsdaten speichern.
 *  - charge.dispute.created: Rücklastschrift, Rechnung wieder offen (failed).
 *  - checkout.session.completed (mode=setup): digitale Mandatserteilung,
 *    Zuordnung über metadata.tenant_id und metadata.mandate_request_id.
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
$isCheckout = $eventType === 'checkout.session.completed';

// Rücklastschriften (charge.dispute.created) tragen keine PaymentIntent-Metadaten:
// Firma über den PaymentIntent der gespeicherten Collection ermitteln.
$paymentIntentHint = $eventType === 'charge.dispute.created'
    ? ($obj['payment_intent'] ?? null)
    : ($isCheckout ? null : ($obj['id'] ?? null));
if (!$tenantId && is_string($paymentIntentHint) && $paymentIntentHint !== '') {
    try {
        $stmt = db()->prepare('SELECT tenant_id FROM payment_collections WHERE stripe_payment_intent_id = ? LIMIT 1');
        $stmt->execute([$paymentIntentHint]);
        $tenantId = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        webhook_exit('Datenbankfehler bei Firmenzuordnung: ' . $e->getMessage());
    }
}

if (!$tenantId || !is_string($tenantId)) {
    webhook_exit("Firma nicht ermittelbar (type=$eventType)");
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

    // --- Digitale Mandatserteilung (Checkout mode=setup) ---
    if ($isCheckout) {
        if (($obj['mode'] ?? '') !== 'setup') {
            webhook_exit('Checkout Session ohne mode=setup ignoriert');
        }
        $requestId = (string)($obj['metadata']['mandate_request_id'] ?? '');
        $sessionId = (string)($obj['id'] ?? '');
        $setupIntentId = is_array($obj['setup_intent'] ?? null) ? ($obj['setup_intent']['id'] ?? '') : (string)($obj['setup_intent'] ?? '');
        if ($requestId === '' || $setupIntentId === '') {
            webhook_exit('Checkout Session ohne mandate_request_id oder setup_intent');
        }
        require_once __DIR__ . '/app/mandate_requests.php';
        require_once __DIR__ . '/app/collections.php';
        $stmt = $pdo->prepare('SELECT r.*, c.name AS customer_name FROM mandate_requests r JOIN customers c ON c.id = r.customer_id WHERE r.id = ? AND r.tenant_id = ?');
        $stmt->execute([$requestId, $tenantId]);
        $req = $stmt->fetch();
        if (!$req) {
            webhook_exit("Mandatsanforderung $requestId nicht gefunden für tenant $tenantId");
        }
        if ($req['stripe_checkout_session_id'] && $req['stripe_checkout_session_id'] !== $sessionId) {
            webhook_exit("Checkout Session $sessionId passt nicht zur Anforderung $requestId");
        }
        if (!in_array($req['status'], ['requested', 'pending'], true)) {
            webhook_exit("Mandatsanforderung $requestId bereits verarbeitet (" . $req['status'] . ')');
        }
        $stripe = _get_stripe_client($tenantId);
        $setupIntent = $stripe->getSetupIntent($setupIntentId);
        if (($setupIntent['status'] ?? '') !== 'succeeded') {
            webhook_exit("SetupIntent $setupIntentId nicht erfolgreich (" . ($setupIntent['status'] ?? '?') . ')');
        }
        $granted = mandate_request_grant($req, $stripe, $setupIntent);
        webhook_exit($granted ? "Mandat digital erteilt (Anforderung $requestId)" : "Anforderung $requestId nicht erneut verarbeitet");
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
        // PaymentIntent aus einem Versuch, dessen Ergebnis lokal unbekannt blieb
        // (Zeitüberschreitung): Einzug anhand des Versuchsjournals nachtragen.
        $attemptKey = (string)($obj['metadata']['attempt_key'] ?? '');
        if ($attemptKey !== '' && str_starts_with($eventType, 'payment_intent.')) {
            require_once __DIR__ . '/app/collections.php';
            $stmt = $pdo->prepare('SELECT * FROM collection_attempts WHERE idempotency_key = ? AND tenant_id = ?');
            $stmt->execute([$attemptKey, $tenantId]);
            $attempt = $stmt->fetch();
            // Nur klar unbekannte oder ältere Versuche nachtragen: Bei einem laufenden
            // Sofort-Einzug kann der Webhook vor dem Commit der Einzugs-Transaktion
            // eintreffen; dann legt die Transaktion den Datensatz selbst an.
            $ageSec = $attempt ? time() - (int)strtotime((string)$attempt['created_at']) : 0;
            if ($attempt && in_array($attempt['status'], ['pending', 'unknown', 'succeeded'], true)
                && ($attempt['status'] === 'unknown' || $ageSec >= 120)) {
                $recoveredId = collection_attempt_recover($tenantId, $attempt, $obj);
                if ($recoveredId) {
                    $stmt = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ? AND tenant_id = ?');
                    $stmt->execute([$recoveredId, $tenantId]);
                    $collection = $stmt->fetch();
                }
            }
        }
        if (!$collection) {
            webhook_exit("PaymentCollection nicht gefunden für PI $paymentIntentId");
        }
    }

    switch ($eventType) {
        case 'payment_intent.processing':
            $pdo->prepare("UPDATE payment_collections SET stripe_status = 'processing' WHERE id = ? AND stripe_status <> 'succeeded'")
                ->execute([$collection['id']]);
            break;

        case 'payment_intent.succeeded':
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE id = ?"
            )->execute([$collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'collected' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            // Charge und Stripe-Mandatsdaten nachladen (reiner Lesezugriff, Fehler brechen nichts ab)
            try {
                require_once __DIR__ . '/app/collections.php';
                store_stripe_mandate_data(_get_stripe_client($tenantId), $collection, $obj);
            } catch (Throwable $e) {
                error_log('Stripe-Webhook: Mandatsdaten nicht gespeichert: ' . $e->getMessage());
            }
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
            require_once __DIR__ . '/app/audit.php';
            audit_log($tenantId, null, 'collection_disputed', 'collection', $collection['id'], [
                'amount_cents' => (int)$collection['amount_cents'], 'payment_intent' => $paymentIntentId,
            ]);
            break;

        default:
            // Unbekannte Event-Typen ignorieren
            break;
    }
} catch (Throwable $e) {
    error_log('Stripe-Webhook: unerwarteter Fehler: ' . $e->getMessage());
}

echo 'ok';
