<?php
/**
 * SEPA-Einzüge: sofortiger Einzug, Terminierung, Stornierung, Umterminierung
 * sowie Verarbeitung fälliger terminierter Einzüge (Cron).
 * Portiert aus collection_service.py und scheduled_collections_task.py.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/keywords.php';
require_once __DIR__ . '/mandates.php';

class CollectionException extends RuntimeException {}

function validate_scheduled_date(string $scheduledDate): void
{
    $today = new DateTimeImmutable('today');
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $scheduledDate);
    if (!$date || $date->format('Y-m-d') !== $scheduledDate) {
        throw new CollectionException('Ungültiges Datum.');
    }
    $date = $date->setTime(0, 0);

    if ($date <= $today) {
        throw new CollectionException('Terminiertes Datum muss in der Zukunft liegen (mindestens morgen).');
    }
    if ((int)$today->diff($date)->days > 365) {
        throw new CollectionException('Terminiertes Datum darf maximal 365 Tage in der Zukunft liegen.');
    }
    if ((int)$date->format('N') >= 6) { // 6 = Samstag, 7 = Sonntag
        throw new CollectionException('SEPA-Einzüge können nur an Werktagen (Mo-Fr) terminiert werden.');
    }
}

function _get_stripe_client(string $tenantId): StripeClient
{
    $stmt = db()->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $integration = $stmt->fetch();

    if (!$integration || !(int)$integration['stripe_connected']) {
        throw new CollectionException('Stripe ist nicht verbunden.');
    }
    $secretKey = decrypt_value($integration['stripe_secret_key_encrypted']);
    if (!$secretKey) {
        throw new CollectionException('Stripe Secret Key fehlt.');
    }
    return new StripeClient($secretKey);
}

/** Rechnung, Kunde und aktive IBAN laden und für Einzug validieren. */
function _load_and_validate(string $tenantId, string $invoiceId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$invoiceId, $tenantId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        throw new CollectionException('Rechnung nicht gefunden.');
    }
    if (in_array($invoice['collection_status'], ['in_collection', 'scheduled'], true)) {
        throw new CollectionException('Rechnung befindet sich bereits im Einzugsverfahren.');
    }
    if (!in_array($invoice['lexoffice_status'], ['open', 'overdue'], true)) {
        throw new CollectionException(
            'Rechnung kann nicht eingezogen werden (Lexoffice-Status: ' . $invoice['lexoffice_status'] . ').'
        );
    }
    if (!$invoice['customer_id']) {
        throw new CollectionException('Rechnung hat keinen verknüpften Kunden.');
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$invoice['customer_id'], $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new CollectionException('Kunde nicht gefunden.');
    }
    if ((int)($customer['sepa_debit_enabled'] ?? 1) === 0) {
        throw new CollectionException('SEPA-Einzug ist für diesen Kunden deaktiviert.');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$customer['id'], $tenantId]);
    $iban = $stmt->fetch();
    if (!$iban) {
        throw new CollectionException('Für diesen Kunden ist keine IBAN hinterlegt.');
    }

    return [$invoice, $customer, $iban];
}

function _build_collection_description(array $invoice, array $customer): string
{
    $keywordSepa = $invoice['keyword_sepa'];
    if (!$keywordSepa && $invoice['line_items_json']) {
        $lineItems = json_decode($invoice['line_items_json'], true) ?: [];
        [, $keywordSepa] = extract_keyword($lineItems);
    }
    if (!$keywordSepa) {
        $keywordSepa = 'Sonstiges';
    }
    return build_description($invoice['voucher_number'], $customer['customer_number'], $keywordSepa);
}

/** Stripe-Aufrufe für einen Einzug ausführen. Gibt [customer, paymentMethod, paymentIntent] zurück. */
function _execute_stripe_collection(
    StripeClient $stripe,
    string $tenantId,
    array $invoice,
    array $customer,
    array $iban,
    array $mandate,
    string $description,
    int $amountCents
): array {
    $contactEmail = $customer['email'] ?: 'noreply@muellerhv.de';

    $stripeCustomer = $stripe->findOrCreateCustomer(
        $customer['name'],
        $customer['email'] ?: null,
        [
            'tenant_id'       => $tenantId,
            'customer_id'     => $customer['id'],
            'customer_number' => $customer['customer_number'],
        ]
    );

    $paymentMethod = $stripe->createSepaPaymentMethod(
        $iban['iban'],
        $iban['account_holder_name'],
        $contactEmail
    );
    $stripe->attachPaymentMethod($paymentMethod['id'], $stripeCustomer['id']);

    $paymentIntent = $stripe->createPaymentIntent(
        $amountCents,
        $stripeCustomer['id'],
        $paymentMethod['id'],
        $description,
        [
            'tenant_id'         => $tenantId,
            'invoice_id'        => $invoice['id'],
            'mandate_reference' => $mandate['mandate_reference'],
            'voucher_number'    => $invoice['voucher_number'],
            'customer_number'   => $customer['customer_number'],
        ]
    );

    return [$stripeCustomer, $paymentMethod, $paymentIntent];
}

/**
 * Einzug einreichen. Mit $scheduledDate wird nur terminiert (kein Stripe-Aufruf),
 * ohne wird sofort bei Stripe eingereicht. Gibt die Collection-ID zurück.
 */
function submit_collection(string $tenantId, string $invoiceId, ?string $scheduledDate = null): string
{
    if ($scheduledDate !== null) {
        validate_scheduled_date($scheduledDate);
    }

    $pdo = db();
    [$invoice, $customer, $iban] = _load_and_validate($tenantId, $invoiceId);
    $mandate = get_or_create_mandate($tenantId, $customer['id'], $iban['id']);
    $description = _build_collection_description($invoice, $customer);
    $amountCents = (int)round((float)$invoice['total_gross_amount'] * 100);

    $collectionId = uuid4();

    if ($scheduledDate !== null) {
        // --- Terminiert: noch kein Stripe-Aufruf ---
        $pdo->prepare(
            'INSERT INTO payment_collections
                (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency,
                 stripe_status, description, is_scheduled, scheduled_date, scheduled_submitted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0)'
        )->execute([
            $collectionId, $tenantId, $invoice['id'], $mandate['id'], $iban['id'],
            $amountCents, 'EUR', 'scheduled', $description, $scheduledDate,
        ]);
        $pdo->prepare("UPDATE invoices SET collection_status = 'scheduled' WHERE id = ?")
            ->execute([$invoice['id']]);
    } else {
        // --- Sofort: Stripe jetzt aufrufen ---
        $stripe = _get_stripe_client($tenantId);
        [$stripeCustomer, $paymentMethod, $paymentIntent] = _execute_stripe_collection(
            $stripe, $tenantId, $invoice, $customer, $iban, $mandate, $description, $amountCents
        );

        $pdo->prepare(
            'INSERT INTO payment_collections
                (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency,
                 stripe_payment_intent_id, stripe_status, description, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $collectionId, $tenantId, $invoice['id'], $mandate['id'], $iban['id'],
            $amountCents, 'EUR', $paymentIntent['id'], 'processing', $description,
        ]);
        $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")
            ->execute([$invoice['id']]);
        $pdo->prepare(
            'UPDATE sepa_mandates SET stripe_payment_method_id = ?, stripe_customer_id = ? WHERE id = ?'
        )->execute([$paymentMethod['id'], $stripeCustomer['id'], $mandate['id']]);
    }

    return $collectionId;
}

function cancel_scheduled_collection(string $tenantId, string $collectionId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$collectionId, $tenantId]);
    $collection = $stmt->fetch();

    if (!$collection) {
        throw new CollectionException('Einzug nicht gefunden.');
    }
    if (!(int)$collection['is_scheduled']) {
        throw new CollectionException('Nur terminierte Einzüge können storniert werden.');
    }
    if ((int)$collection['scheduled_submitted']) {
        throw new CollectionException('Einzug wurde bereits bei Stripe eingereicht und kann nicht mehr storniert werden.');
    }

    $pdo->prepare("UPDATE payment_collections SET stripe_status = 'cancelled' WHERE id = ?")
        ->execute([$collectionId]);
    $pdo->prepare("UPDATE invoices SET collection_status = 'open' WHERE id = ?")
        ->execute([$collection['invoice_id']]);
}

function reschedule_collection(string $tenantId, string $collectionId, string $newDate): void
{
    validate_scheduled_date($newDate);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$collectionId, $tenantId]);
    $collection = $stmt->fetch();

    if (!$collection) {
        throw new CollectionException('Einzug nicht gefunden.');
    }
    if (!(int)$collection['is_scheduled']) {
        throw new CollectionException('Nur terminierte Einzüge können umterminiert werden.');
    }
    if ((int)$collection['scheduled_submitted']) {
        throw new CollectionException('Einzug wurde bereits bei Stripe eingereicht.');
    }

    $pdo->prepare('UPDATE payment_collections SET scheduled_date = ? WHERE id = ?')
        ->execute([$newDate, $collectionId]);
}

/**
 * Fällige terminierte Einzüge bei Stripe einreichen (Aufruf über cron.php).
 * @return array{submitted:int,failed:int}
 */
function process_scheduled_collections(): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM payment_collections
         WHERE is_scheduled = 1 AND scheduled_submitted = 0
           AND stripe_status = 'scheduled' AND scheduled_date <= CURDATE()"
    );
    $stmt->execute();
    $due = $stmt->fetchAll();

    $submitted = 0;
    $failed = 0;

    foreach ($due as $collection) {
        try {
            _submit_single_scheduled($collection);
            $submitted++;
        } catch (Throwable $e) {
            error_log('Terminierte Lastschrift ' . $collection['id'] . ' fehlgeschlagen: ' . $e->getMessage());
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'failed', failure_reason = ? WHERE id = ?"
            )->execute([$e->getMessage(), $collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'failed' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            $failed++;
        }
    }

    return ['submitted' => $submitted, 'failed' => $failed];
}

function _submit_single_scheduled(array $collection): void
{
    $pdo = db();
    $tenantId = $collection['tenant_id'];

    $stripe = _get_stripe_client($tenantId);

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$collection['invoice_id']]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        throw new RuntimeException('Rechnung nicht gefunden');
    }

    $stmt = $pdo->prepare('SELECT * FROM sepa_mandates WHERE id = ?');
    $stmt->execute([$collection['mandate_id']]);
    $mandate = $stmt->fetch();
    if (!$mandate) {
        throw new RuntimeException('Mandat nicht gefunden');
    }

    $stmt = $pdo->prepare('SELECT * FROM customer_ibans WHERE id = ?');
    $stmt->execute([$collection['customer_iban_id']]);
    $iban = $stmt->fetch();
    if (!$iban) {
        throw new RuntimeException('IBAN nicht gefunden');
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$invoice['customer_id'], $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Kunde nicht gefunden');
    }

    [$stripeCustomer, $paymentMethod, $paymentIntent] = _execute_stripe_collection(
        $stripe, $tenantId, $invoice, $customer, $iban, $mandate,
        $collection['description'] ?? '', (int)$collection['amount_cents']
    );

    $pdo->prepare(
        "UPDATE payment_collections
         SET scheduled_submitted = 1, stripe_payment_intent_id = ?, stripe_status = 'processing', submitted_at = NOW()
         WHERE id = ?"
    )->execute([$paymentIntent['id'], $collection['id']]);

    $pdo->prepare(
        'UPDATE sepa_mandates SET stripe_payment_method_id = ?, stripe_customer_id = ? WHERE id = ?'
    )->execute([$paymentMethod['id'], $stripeCustomer['id'], $mandate['id']]);

    $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")
        ->execute([$invoice['id']]);
}
