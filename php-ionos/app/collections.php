<?php
/**
 * SEPA-Einzüge: sofortiger Einzug, Terminierung, Stornierung, Umterminierung
 * sowie Verarbeitung fälliger terminierter Einzüge (Cron).
 *
 * Jeder Einzug wird der auslösenden Person zugeordnet (created_by_user_id
 * und Audit-Log), geprüft gegen Tarifkontingent, Mandatsstatus (Unterschrift,
 * 36-Monats-Verfall) und Vorabankündigungsregel der Firma.
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
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/audit.php';

class CollectionException extends RuntimeException {}

/** Mandat nicht verwendbar (z.B. verfallen); trägt die Mandats-ID für die Nachbehandlung außerhalb der Transaktion. */
class MandateUnusableException extends CollectionException
{
    public ?string $mandateId = null;
}

/**
 * Platzhalter-Kontakt-E-Mail für Stripe, falls der Kunde keine E-Mail
 * hinterlegt hat (Stripe verlangt für das SEPA-Mandat eine E-Mail-Adresse).
 */
function _fallback_contact_email(): string
{
    $host = parse_url((string)config('base_url'), PHP_URL_HOST);
    return 'noreply@' . ($host ?: 'example.invalid');
}

function _collection_org(string $tenantId): array
{
    $stmt = db()->prepare('SELECT * FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $org = $stmt->fetch();
    if (!$org) {
        throw new CollectionException('Firma nicht gefunden.');
    }
    return $org;
}

/**
 * Kunde und IBAN sofort bei Stripe registrieren (SEPA-Zahlungsmethode
 * anlegen und anhängen), OHNE eine Zahlung auszulösen.
 *
 * @return array{registered:bool,reason:?string}
 */
function register_iban_with_stripe(string $tenantId, string $customerId, string $customerIbanId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        return ['registered' => false, 'reason' => 'Kunde nicht gefunden.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM customer_ibans WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerIbanId, $tenantId]);
    $iban = $stmt->fetch();
    if (!$iban) {
        return ['registered' => false, 'reason' => 'IBAN nicht gefunden.'];
    }

    try {
        $stripe = _get_stripe_client($tenantId);
    } catch (Throwable $e) {
        return ['registered' => false, 'reason' => $e->getMessage()];
    }

    $mandate = get_or_create_mandate($tenantId, $customerId, $customerIbanId);

    if (!empty($mandate['stripe_customer_id']) && !empty($mandate['stripe_payment_method_id'])) {
        return ['registered' => true, 'reason' => null];
    }

    $contactEmail = $customer['email'] ?: _fallback_contact_email();

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

    $pdo->prepare(
        'UPDATE sepa_mandates SET stripe_payment_method_id = ?, stripe_customer_id = ? WHERE id = ?'
    )->execute([$paymentMethod['id'], $stripeCustomer['id'], $mandate['id']]);

    return ['registered' => true, 'reason' => null];
}

function validate_scheduled_date(string $scheduledDate, int $minLeadDays = 1): void
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
    if ($minLeadDays > 1 && $date < $today->modify('+' . $minLeadDays . ' days')) {
        throw new CollectionException(sprintf(
            'Wegen der Vorabankündigungsfrist von %d Tagen ist der früheste Termin der %s.',
            $minLeadDays,
            $today->modify('+' . $minLeadDays . ' days')->format('d.m.Y')
        ));
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

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ?' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
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
            'Rechnung kann nicht eingezogen werden (Status in Lexware Office: ' . $invoice['lexoffice_status'] . ').'
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

function _build_collection_description(string $tenantId, array $invoice, array $customer): string
{
    $keywordSepa = $invoice['keyword_sepa'];
    if (!$keywordSepa && $invoice['line_items_json']) {
        $lineItems = json_decode($invoice['line_items_json'], true) ?: [];
        [, $keywordSepa] = extract_keyword($lineItems);
    }
    if (!$keywordSepa) {
        $keywordSepa = 'Sonstiges';
    }
    $stmt = db()->prepare('SELECT name FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $orgName = (string)($stmt->fetchColumn() ?: 'SEPA-Einzug');
    return build_description($invoice['voucher_number'], $customer['customer_number'], $keywordSepa, $orgName);
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
    $contactEmail = $customer['email'] ?: _fallback_contact_email();

    if (!empty($mandate['stripe_customer_id']) && !empty($mandate['stripe_payment_method_id'])) {
        $stripeCustomer = ['id' => $mandate['stripe_customer_id']];
        $paymentMethod = ['id' => $mandate['stripe_payment_method_id']];
    } else {
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
    }

    // Optional: Stripe-Mandatsreferenz mit dem Firmenpräfix beginnen lassen
    $prefix = null;
    if (config('stripe_mandate_reference_prefix', false)) {
        $stmt = db()->prepare('SELECT mandate_prefix FROM organizations WHERE id = ?');
        $stmt->execute([$tenantId]);
        $prefix = (string)($stmt->fetchColumn() ?: '') ?: null;
    }

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
        ],
        $prefix
    );

    return [$stripeCustomer, $paymentMethod, $paymentIntent];
}

/**
 * Vorabankündigung (Pre-Notification) per E-Mail an den Kunden senden, sofern
 * die Firma das aktiviert hat und eine E-Mail-Adresse vorliegt.
 * Inhalt: Betrag, Fälligkeit, Mandatsreferenz, Gläubiger-ID, Zahlungsempfänger.
 */
function _send_prenotification(array $org, array $customer, array $invoice, array $mandate, int $amountCents, string $dueDate): bool
{
    require_once __DIR__ . '/mailer.php';
    if (!(int)($org['send_pre_notification'] ?? 0) || !mail_enabled() || empty($customer['email'])) {
        return false;
    }
    $lines = [
        sprintf('Sehr geehrte Damen und Herren, wir kündigen hiermit den Einzug folgender Lastschrift an:'),
        sprintf('Rechnung %s über %s, Fälligkeit/Einzug am %s.', $invoice['voucher_number'], format_eur_cents($amountCents), format_date($dueDate)),
        sprintf('Zahlungsempfänger: %s. Mandatsreferenz: %s.%s', $org['name'], $mandate['mandate_reference'],
            !empty($org['creditor_identifier']) ? ' Gläubiger-Identifikationsnummer: ' . $org['creditor_identifier'] . '.' : ''),
        'Der Einzug erfolgt über den Zahlungsdienstleister Stripe. Bitte sorgen Sie für ausreichende Kontodeckung.',
    ];
    $tpl = mail_layout('Vorabankündigung SEPA-Lastschrift', $lines, null, $org['name']);
    return mail_send($customer['email'], 'Vorabankündigung SEPA-Lastschrift ' . $invoice['voucher_number'], $tpl['text'], $tpl['html']);
}

/**
 * Einzug einreichen. Mit $scheduledDate wird nur terminiert (kein Stripe-Aufruf),
 * ohne wird sofort bei Stripe eingereicht. Gibt die Collection-ID zurück.
 */
function submit_collection(string $tenantId, string $invoiceId, ?string $scheduledDate = null, ?array $actor = null): string
{
    $pdo = db();
    // Firmenzeile sperren: verhindert doppelte Einzüge derselben Rechnung und
    // Kontingentüberschreitungen durch parallele Anfragen. Die Sperre gilt bis
    // zum Commit am Ende (auch der Stripe-Aufruf liegt darin, er dauert nur
    // wenige Sekunden).
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE')->execute([$tenantId]);
        $collectionId = _submit_collection_locked($tenantId, $invoiceId, $scheduledDate, $actor);
        if ($ownTransaction) {
            $pdo->commit();
        }
        return $collectionId;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Verfall eines Mandats dauerhaft festhalten, auch wenn die Transaktion
        // des Einzugs zurückgerollt wurde.
        if ($e instanceof MandateUnusableException && $e->mandateId && !$pdo->inTransaction()) {
            $m = mandate_load($tenantId, $e->mandateId);
            if ($m) {
                mandate_check_usable($m, _collection_org($tenantId));
            }
        }
        throw $e;
    }
}

function _submit_collection_locked(string $tenantId, string $invoiceId, ?string $scheduledDate, ?array $actor): string
{
    $pdo = db();
    $org = _collection_org($tenantId);
    $preDays = max(0, (int)($org['pre_notification_days'] ?? 14));
    $preNotify = (int)($org['send_pre_notification'] ?? 0) === 1;

    if ($scheduledDate !== null) {
        validate_scheduled_date($scheduledDate, $preNotify ? $preDays : 1);
    } elseif ($preNotify && $preDays > 0) {
        throw new CollectionException(sprintf(
            'Die Vorabankündigung per E-Mail ist für Ihre Firma aktiviert (%d Tage). Bitte den Einzug terminieren; '
            . 'die Ankündigung wird beim Terminieren versendet.',
            $preDays
        ));
    }

    $quota = collections_quota_check($tenantId);
    if (!$quota['allowed']) {
        throw new CollectionException($quota['reason']);
    }

    [$invoice, $customer, $iban] = _load_and_validate($tenantId, $invoiceId);
    if (mandate_requires_manual_renewal($tenantId, $customer['id'])) {
        throw new CollectionException(
            'Das bisherige SEPA-Mandat dieses Kunden ist widerrufen oder verfallen. Bitte ein neues Mandat einholen '
            . 'und unter Kundendetails erfassen (Mandatsdokument erzeugen, Unterschrift erfassen).'
        );
    }
    $mandate = get_or_create_mandate($tenantId, $customer['id'], $iban['id']);
    if ($problem = mandate_check_usable($mandate, $org)) {
        $ex = new MandateUnusableException($problem);
        $ex->mandateId = $mandate['id'];
        throw $ex;
    }
    if ($preNotify && $scheduledDate !== null) {
        require_once __DIR__ . '/mailer.php';
        if (!mail_enabled()) {
            throw new CollectionException('Die Vorabankündigung per E-Mail ist aktiviert, aber der E-Mail-Versand der Anwendung ist nicht eingerichtet.');
        }
        if (empty($customer['email'])) {
            throw new CollectionException('Die Vorabankündigung per E-Mail ist aktiviert, aber für diesen Kunden ist keine E-Mail-Adresse hinterlegt.');
        }
    }
    $description = _build_collection_description($tenantId, $invoice, $customer);
    $amountCents = (int)round((float)$invoice['total_gross_amount'] * 100);
    $userId = $actor['user_id'] ?? null;

    $collectionId = uuid4();

    if ($scheduledDate !== null) {
        // --- Terminiert: noch kein Stripe-Aufruf ---
        $pdo->prepare(
            'INSERT INTO payment_collections
                (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency,
                 stripe_status, description, is_scheduled, scheduled_date, scheduled_submitted, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?)'
        )->execute([
            $collectionId, $tenantId, $invoice['id'], $mandate['id'], $iban['id'],
            $amountCents, 'EUR', 'scheduled', $description, $scheduledDate, $userId,
        ]);
        $pdo->prepare("UPDATE invoices SET collection_status = 'scheduled' WHERE id = ?")
            ->execute([$invoice['id']]);

        if ($preNotify) {
            if (!_send_prenotification($org, $customer, $invoice, $mandate, $amountCents, $scheduledDate)) {
                throw new CollectionException('Die Vorabankündigung konnte nicht versendet werden; der Einzug wurde nicht terminiert.');
            }
            $pdo->prepare('UPDATE payment_collections SET prenotified_at = NOW() WHERE id = ?')->execute([$collectionId]);
        }

        audit_log($tenantId, $actor, 'collection_scheduled', 'collection', $collectionId, [
            'voucher_number' => $invoice['voucher_number'], 'amount_cents' => $amountCents,
            'scheduled_date' => $scheduledDate, 'customer_number' => $customer['customer_number'],
        ]);
    } else {
        // --- Sofort: Stripe jetzt aufrufen ---
        $stripe = _get_stripe_client($tenantId);
        [$stripeCustomer, $paymentMethod, $paymentIntent] = _execute_stripe_collection(
            $stripe, $tenantId, $invoice, $customer, $iban, $mandate, $description, $amountCents
        );

        $pdo->prepare(
            'INSERT INTO payment_collections
                (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency,
                 stripe_payment_intent_id, stripe_status, description, submitted_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        )->execute([
            $collectionId, $tenantId, $invoice['id'], $mandate['id'], $iban['id'],
            $amountCents, 'EUR', $paymentIntent['id'], 'processing', $description, $userId,
        ]);
        $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")
            ->execute([$invoice['id']]);
        $pdo->prepare(
            'UPDATE sepa_mandates SET stripe_payment_method_id = ?, stripe_customer_id = ? WHERE id = ?'
        )->execute([$paymentMethod['id'], $stripeCustomer['id'], $mandate['id']]);
        mandate_touch_used($mandate['id']);

        audit_log($tenantId, $actor, 'collection_submitted', 'collection', $collectionId, [
            'voucher_number' => $invoice['voucher_number'], 'amount_cents' => $amountCents,
            'customer_number' => $customer['customer_number'], 'payment_intent' => $paymentIntent['id'],
        ]);
        funnel_event_once($tenantId, 'first_collection', $userId);
    }

    return $collectionId;
}

function cancel_scheduled_collection(string $tenantId, string $collectionId, ?array $actor = null): void
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
    audit_log($tenantId, $actor, 'collection_cancelled', 'collection', $collectionId, [
        'amount_cents' => (int)$collection['amount_cents'],
    ]);
}

function reschedule_collection(string $tenantId, string $collectionId, string $newDate, ?array $actor = null): void
{
    $org = _collection_org($tenantId);
    $preNotify = (int)($org['send_pre_notification'] ?? 0) === 1;
    validate_scheduled_date($newDate, $preNotify ? max(1, (int)$org['pre_notification_days']) : 1);

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

    // Vorabankündigung mit neuem Termin wiederholen
    if ($preNotify) {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$collection['invoice_id']]);
        $invoice = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$invoice['customer_id'] ?? '']);
        $customer = $stmt->fetch();
        $mandate = mandate_load($tenantId, $collection['mandate_id']);
        if ($invoice && $customer && $mandate) {
            if (_send_prenotification($org, $customer, $invoice, $mandate, (int)$collection['amount_cents'], $newDate)) {
                $pdo->prepare('UPDATE payment_collections SET prenotified_at = NOW() WHERE id = ?')->execute([$collectionId]);
            } else {
                $pdo->prepare('UPDATE payment_collections SET scheduled_date = ? WHERE id = ?')
                    ->execute([$collection['scheduled_date'], $collectionId]);
                throw new CollectionException('Die Vorabankündigung für den neuen Termin konnte nicht versendet werden; der Termin bleibt unverändert.');
            }
        }
    }
    audit_log($tenantId, $actor, 'collection_rescheduled', 'collection', $collectionId, [
        'old_date' => $collection['scheduled_date'], 'new_date' => $newDate,
    ]);
}

/**
 * Status laufender Einzüge ("processing") bei Stripe abfragen und lokal
 * nachziehen. Reiner Lesezugriff, löst keine Zahlung aus.
 *
 * Eine spätere SEPA-Rücklastschrift (bis zu 8 Wochen nach Belastung) wird
 * nur über den Stripe-Webhook (charge.dispute.created) erkannt.
 *
 * @return array{checked:int,succeeded:int,failed:int,unchanged:int}
 */
function sync_collection_statuses(string $tenantId, ?array $actor = null): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM payment_collections
         WHERE tenant_id = ? AND stripe_status = 'processing' AND stripe_payment_intent_id IS NOT NULL"
    );
    $stmt->execute([$tenantId]);
    $pending = $stmt->fetchAll();

    $result = ['checked' => count($pending), 'succeeded' => 0, 'failed' => 0, 'unchanged' => 0];
    if (!$pending) {
        return $result;
    }

    $stripe = _get_stripe_client($tenantId);

    foreach ($pending as $collection) {
        try {
            $pi = $stripe->getPaymentIntent($collection['stripe_payment_intent_id']);
        } catch (Throwable $e) {
            error_log(
                'Statusabgleich für PaymentIntent ' . $collection['stripe_payment_intent_id']
                . ' fehlgeschlagen: ' . $e->getMessage()
            );
            $result['unchanged']++;
            continue;
        }

        $piStatus = $pi['status'] ?? '';

        if ($piStatus === 'succeeded') {
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE id = ?"
            )->execute([$collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'collected' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            $result['succeeded']++;
        } elseif (in_array($piStatus, ['canceled', 'requires_payment_method'], true)) {
            $reason = $pi['last_payment_error']['message'] ?? 'Lastschrift konnte nicht eingezogen werden.';
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'failed', failure_reason = ?, completed_at = NOW() WHERE id = ?"
            )->execute([$reason, $collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'failed' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            $result['failed']++;
        } else {
            $result['unchanged']++;
        }
    }

    audit_log($tenantId, $actor, 'collection_status_sync', 'organization', $tenantId, $result);
    return $result;
}

/**
 * Alle offenen Rechnungen mit aktiver IBAN und gewünschtem SEPA-Einzug
 * sofort bei Stripe einreichen (Sammel-Einzug).
 *
 * @return array{submitted:int,failed:int,candidates:int,amount_cents:int,errors:array}
 */
function submit_all_ready_collections(string $tenantId, ?array $actor = null): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT i.id, i.voucher_number FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.tenant_id = ?
           AND i.lexoffice_status IN ('open', 'overdue')
           AND i.collection_status NOT IN ('in_collection', 'scheduled', 'collected')
           AND c.sepa_debit_enabled = 1
           AND EXISTS (
               SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1
           )"
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();

    $submitted = 0;
    $failed = 0;
    $amount = 0;
    $errors = [];
    foreach ($rows as $row) {
        try {
            $collectionId = submit_collection($tenantId, $row['id'], null, $actor);
            $submitted++;
            $s = $pdo->prepare('SELECT amount_cents FROM payment_collections WHERE id = ?');
            $s->execute([$collectionId]);
            $amount += (int)$s->fetchColumn();
        } catch (Throwable $e) {
            error_log('Sammel-Einzug für Rechnung ' . $row['id'] . ' fehlgeschlagen: ' . $e->getMessage());
            $failed++;
            if (count($errors) < 10) {
                $errors[] = $row['voucher_number'] . ': ' . $e->getMessage();
            }
        }
    }

    audit_log($tenantId, $actor, 'collections_bulk', 'organization', $tenantId, [
        'submitted' => $submitted, 'failed' => $failed, 'candidates' => count($rows), 'amount_cents' => $amount,
    ]);
    return ['submitted' => $submitted, 'failed' => $failed, 'candidates' => count($rows), 'amount_cents' => $amount, 'errors' => $errors];
}

/**
 * Anzahl und Summe der Rechnungen ermitteln, die submit_all_ready_collections()
 * jetzt einreichen würde.
 *
 * @return array{count:int,amount:string}
 */
function count_ready_for_collection(string $tenantId): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(i.total_gross_amount), 0) AS total
         FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.tenant_id = ?
           AND i.lexoffice_status IN ('open', 'overdue')
           AND i.collection_status NOT IN ('in_collection', 'scheduled', 'collected')
           AND c.sepa_debit_enabled = 1
           AND EXISTS (
               SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1
           )"
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    return ['count' => (int)$row['cnt'], 'amount' => (string)$row['total']];
}

/**
 * Fällige terminierte Einzüge bei Stripe einreichen.
 * Ohne $tenantId (cron.php): alle Firmen. Mit $tenantId (Button): nur die eigene.
 *
 * @return array{submitted:int,failed:int}
 */
function process_scheduled_collections(?string $tenantId = null, ?array $actor = null): array
{
    $pdo = db();
    $sql = "SELECT * FROM payment_collections
            WHERE is_scheduled = 1 AND scheduled_submitted = 0
              AND stripe_status = 'scheduled' AND scheduled_date <= CURDATE()";
    $params = [];
    if ($tenantId !== null) {
        $sql .= ' AND tenant_id = ?';
        $params[] = $tenantId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

    if ($due) {
        audit_log($tenantId, $actor, 'collections_due_processed', 'organization', $tenantId, [
            'submitted' => $submitted, 'failed' => $failed, 'source' => $actor ? 'button' : 'cron',
        ]);
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
    if (!in_array($invoice['lexoffice_status'], ['open', 'overdue'], true)) {
        throw new RuntimeException('Rechnung ist in Lexware Office nicht mehr offen (' . $invoice['lexoffice_status'] . ')');
    }

    $stmt = $pdo->prepare('SELECT * FROM sepa_mandates WHERE id = ?');
    $stmt->execute([$collection['mandate_id']]);
    $mandate = $stmt->fetch();
    if (!$mandate) {
        throw new RuntimeException('Mandat nicht gefunden');
    }
    if ($problem = mandate_check_usable($mandate, _collection_org($tenantId))) {
        throw new RuntimeException($problem);
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$invoice['customer_id'], $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Kunde nicht gefunden');
    }
    if ((int)($customer['sepa_debit_enabled'] ?? 1) === 0) {
        throw new RuntimeException('SEPA-Einzug wurde für diesen Kunden inzwischen deaktiviert');
    }

    // Immer die aktuell aktive IBAN verwenden: Wurde die Bankverbindung seit der
    // Terminierung ersetzt oder deaktiviert, darf die alte nicht belastet werden.
    $stmt = $pdo->prepare(
        'SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$customer['id'], $tenantId]);
    $iban = $stmt->fetch();
    if (!$iban) {
        throw new RuntimeException('Für diesen Kunden ist keine aktive IBAN mehr hinterlegt');
    }
    if ($iban['id'] !== $collection['customer_iban_id']) {
        if (mandate_requires_manual_renewal($tenantId, $customer['id'])) {
            throw new RuntimeException('Das SEPA-Mandat ist widerrufen oder verfallen; ein neues Mandat muss erfasst werden');
        }
        $mandate = get_or_create_mandate($tenantId, $customer['id'], $iban['id']);
        if ($problem = mandate_check_usable($mandate, _collection_org($tenantId))) {
            throw new RuntimeException($problem);
        }
        $pdo->prepare('UPDATE payment_collections SET customer_iban_id = ?, mandate_id = ? WHERE id = ?')
            ->execute([$iban['id'], $mandate['id'], $collection['id']]);
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
    mandate_touch_used($mandate['id']);

    $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")
        ->execute([$invoice['id']]);

    audit_log($tenantId, $collection['created_by_user_id'] ? ['user_id' => $collection['created_by_user_id']] : null,
        'collection_submitted', 'collection', $collection['id'], [
            'voucher_number' => $invoice['voucher_number'], 'amount_cents' => (int)$collection['amount_cents'],
            'scheduled' => true, 'payment_intent' => $paymentIntent['id'],
        ]);
    funnel_event_once($tenantId, 'first_collection', $collection['created_by_user_id']);
}
