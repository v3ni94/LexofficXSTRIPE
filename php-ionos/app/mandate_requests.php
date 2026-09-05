<?php
/**
 * Digitale Mandatsanforderung (Feature-Schalter config 'features' => ['mandate_request' => true]).
 *
 * Ablauf:
 *  1. Mitarbeiter fordert in den Kundendetails ein Mandat an. Es entsteht ein
 *     zufälliges Token (32 Byte), von dem nur der SHA-256-Hash gespeichert
 *     wird. Der Kunde erhält per E-Mail den Link mandat.php?t=<token>
 *     (Gültigkeit 14 Tage).
 *  2. Die öffentliche Seite mandat.php zeigt Zahlungsempfänger und
 *     Mandatstext. Erst ein POST mit CSRF-Token startet eine Stripe Checkout
 *     Session im Modus "setup" (SEPA-Lastschrift, keine Zahlung).
 *  3. Stripe meldet checkout.session.completed an stripe-webhook.php. Dort
 *     werden Zahlungsmethode und Stripe-Mandat gespeichert und das lokale
 *     SEPA-Mandat aktiviert (signed_place "digital (Stripe)").
 *
 * Die IBAN liegt bei digitaler Erteilung nur maskiert vor (Stripe liefert
 * Land, Bankleitzahl und die letzten vier Stellen); die Bankverbindung wird
 * mit source = stripe_digital gespeichert und die Zahlungsmethode bei Stripe
 * direkt für Einzüge verwendet.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/mandates.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/audit.php';

const MANDATE_REQUEST_DAYS = 14;
const MANDATE_REQUEST_MAX_REMINDERS = 2;
const MANDATE_REQUEST_REMIND_AFTER_DAYS = 4;

/** Feature-Schalter aus config.php ('features' => ['mandate_request' => true]). Standard: aus. */
function mandate_request_feature_enabled(): bool
{
    $features = config('features', []);
    return is_array($features) && !empty($features['mandate_request']);
}

/** Öffentliche Adresse der Mandatsseite für ein Token. */
function mandate_request_url(string $rawToken): string
{
    $base = function_exists('app_base_url') ? app_base_url() : rtrim((string)config('base_url', ''), '/');
    return $base . '/mandat.php?t=' . $rawToken;
}

/** Aktive Anforderung (requested oder pending, nicht abgelaufen) eines Kunden oder null. */
function mandate_request_active(string $tenantId, string $customerId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM mandate_requests WHERE tenant_id = ? AND customer_id = ? AND status IN ('requested', 'pending')
           AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$tenantId, $customerId]);
    return $stmt->fetch() ?: null;
}

/** Alle Anforderungen eines Kunden (neueste zuerst). Abgelaufene werden dabei markiert. */
function mandate_requests_for_customer(string $tenantId, string $customerId): array
{
    $pdo = db();
    $pdo->prepare(
        "UPDATE mandate_requests SET status = 'expired' WHERE tenant_id = ? AND customer_id = ?
           AND status IN ('requested', 'pending') AND expires_at <= NOW()"
    )->execute([$tenantId, $customerId]);
    $stmt = $pdo->prepare(
        'SELECT r.*, u.email AS created_by_email FROM mandate_requests r
         LEFT JOIN users u ON u.id = r.created_by_user_id
         WHERE r.tenant_id = ? AND r.customer_id = ? ORDER BY r.created_at DESC LIMIT 20'
    );
    $stmt->execute([$tenantId, $customerId]);
    return $stmt->fetchAll();
}

/** E-Mail mit dem Link an den Kunden senden. */
function _mandate_request_mail(array $org, array $customer, string $url, string $expiresAt, bool $reminder): bool
{
    require_once __DIR__ . '/mailer.php';
    if (!mail_enabled() || empty($customer['email'])) {
        return false;
    }
    $lines = [
        'Sehr geehrte Damen und Herren,',
        sprintf(
            '%s bittet Sie, ein SEPA-Lastschriftmandat für künftige Rechnungen digital zu erteilen. '
            . 'Über den folgenden Link gelangen Sie zu einer Seite mit dem vollständigen Mandatstext. '
            . 'Ihre Bankverbindung geben Sie anschließend direkt beim Zahlungsdienstleister Stripe ein; '
            . 'es wird dabei keine Zahlung ausgelöst.',
            $org['name']
        ),
        sprintf('Der Link ist bis zum %s gültig. Wenn Sie kein Mandat erteilen möchten, ignorieren Sie diese E-Mail.', format_date($expiresAt)),
    ];
    $title = ($reminder ? 'Erinnerung: ' : '') . 'SEPA-Lastschriftmandat für ' . $org['name'] . ' bestätigen';
    $tpl = mail_layout($title, $lines, ['label' => 'Mandat prüfen und bestätigen', 'url' => $url], 'Zahlungsempfänger: ' . $org['name']);
    return mail_send($customer['email'], $title, $tpl['text'], $tpl['html']);
}

/**
 * Anforderung erzeugen und versenden. Eine bestehende aktive Anforderung des
 * Kunden wird zuvor widerrufen (nur ein gültiger Link je Kunde).
 *
 * @return array{request:array,url:string,mailed:bool}
 */
function mandate_request_create(string $tenantId, array $customer, ?array $actor = null): array
{
    if (!mandate_request_feature_enabled()) {
        throw new RuntimeException('Die digitale Mandatsanforderung ist nicht freigeschaltet.');
    }
    if ((int)($customer['is_walk_in'] ?? 0) === 1) {
        throw new RuntimeException('Für Laufkunden (Sammel-Kundennummer) kann kein personenbezogenes Mandat angefordert werden.');
    }
    $pdo = db();
    $org = _mandate_org($tenantId);

    // Stripe muss verbunden sein, sonst kann der Kunde den Vorgang nicht abschließen.
    require_once __DIR__ . '/collections.php';
    _get_stripe_client($tenantId);

    if ($existing = mandate_request_active($tenantId, $customer['id'])) {
        $pdo->prepare("UPDATE mandate_requests SET status = 'revoked', revoked_at = NOW() WHERE id = ?")->execute([$existing['id']]);
    }

    $rawToken = bin2hex(random_bytes(32));
    $id = uuid4();
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . MANDATE_REQUEST_DAYS . ' days')->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO mandate_requests (id, tenant_id, customer_id, token_hash, status, expires_at, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $tenantId, $customer['id'], hash('sha256', $rawToken), 'requested', $expiresAt, $actor['user_id'] ?? null]);

    $url = mandate_request_url($rawToken);
    $mailed = _mandate_request_mail($org, $customer, $url, $expiresAt, false);

    audit_log($tenantId, $actor, 'mandate_request_sent', 'mandate_request', $id, [
        'customer_number' => $customer['customer_number'], 'mailed' => $mailed, 'expires_at' => $expiresAt,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM mandate_requests WHERE id = ?');
    $stmt->execute([$id]);
    return ['request' => $stmt->fetch(), 'url' => $url, 'mailed' => $mailed];
}

/** Anforderung widerrufen (Link sofort ungültig). */
function mandate_request_revoke(string $tenantId, string $requestId, ?array $actor = null): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM mandate_requests WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$requestId, $tenantId]);
    $req = $stmt->fetch();
    if (!$req) {
        throw new RuntimeException('Mandatsanforderung nicht gefunden.');
    }
    if (!in_array($req['status'], ['requested', 'pending'], true)) {
        throw new RuntimeException('Diese Mandatsanforderung ist bereits abgeschlossen (' . $req['status'] . ').');
    }
    $pdo->prepare("UPDATE mandate_requests SET status = 'revoked', revoked_at = NOW() WHERE id = ?")->execute([$requestId]);
    audit_log($tenantId, $actor, 'mandate_request_revoked', 'mandate_request', $requestId, ['customer_id' => $req['customer_id']]);
    return $req;
}

/**
 * Anforderung zum Token laden (öffentliche Seite). Liefert null, wenn Token
 * unbekannt, widerrufen, erteilt oder abgelaufen. Enthält Firma und Kunde.
 */
function mandate_request_load_by_token(string $rawToken): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT r.*, o.name AS org_name, o.street AS org_street, o.zip AS org_zip, o.city AS org_city,
                o.creditor_identifier AS org_creditor_identifier, o.pre_notification_days AS org_pre_notification_days,
                c.name AS customer_name, c.email AS customer_email, c.customer_number
         FROM mandate_requests r
         JOIN organizations o ON o.id = r.tenant_id
         JOIN customers c ON c.id = r.customer_id
         WHERE r.token_hash = ? AND o.deleted_at IS NULL'
    );
    $stmt->execute([hash('sha256', $rawToken)]);
    $req = $stmt->fetch();
    if (!$req) {
        return null;
    }
    if (in_array($req['status'], ['requested', 'pending'], true) && strtotime($req['expires_at']) <= time()) {
        $pdo->prepare("UPDATE mandate_requests SET status = 'expired' WHERE id = ?")->execute([$req['id']]);
        $req['status'] = 'expired';
    }
    return $req;
}

/**
 * Stripe Checkout Session (mode=setup) starten und Adresse zurückgeben.
 * Nur aus mandat.php nach POST mit CSRF-Token.
 */
function mandate_request_start_checkout(array $req, string $rawToken): string
{
    if (!in_array($req['status'], ['requested', 'pending'], true)) {
        throw new RuntimeException('Diese Mandatsanforderung ist nicht mehr gültig.');
    }
    require_once __DIR__ . '/collections.php';
    $stripe = _get_stripe_client($req['tenant_id']);

    $stripeCustomer = $stripe->findOrCreateCustomer(
        (string)$req['customer_name'],
        $req['customer_email'] ?: null,
        [
            'tenant_id'       => $req['tenant_id'],
            'customer_id'     => $req['customer_id'],
            'customer_number' => (string)$req['customer_number'],
        ]
    );
    $base = mandate_request_url($rawToken);
    $session = $stripe->createSetupCheckoutSession(
        $stripeCustomer['id'],
        $base . '&done=1',
        $base,
        [
            'tenant_id'          => $req['tenant_id'],
            'mandate_request_id' => $req['id'],
            'customer_id'        => $req['customer_id'],
        ],
        $req['customer_email'] ?: null
    );
    if (empty($session['url'])) {
        throw new RuntimeException('Stripe hat keine Checkout-Adresse geliefert.');
    }
    db()->prepare(
        "UPDATE mandate_requests SET status = 'pending', stripe_checkout_session_id = ? WHERE id = ?"
    )->execute([$session['id'], $req['id']]);
    audit_log($req['tenant_id'], null, 'mandate_request_started', 'mandate_request', $req['id'], [
        'checkout_session' => $session['id'],
    ]);
    return (string)$session['url'];
}

/**
 * Nach checkout.session.completed: Zahlungsmethode und Stripe-Mandat
 * übernehmen, lokales SEPA-Mandat aktivieren. Mehrfachverarbeitung wird über
 * den Status verhindert (nur pending oder requested wird verarbeitet).
 */
function mandate_request_grant(array $req, StripeClient $stripe, array $setupIntent): bool
{
    $pdo = db();
    // Status atomar umstellen: verhindert doppelte Verarbeitung bei wiederholten Webhooks
    $claim = $pdo->prepare(
        "UPDATE mandate_requests SET status = 'granted', granted_at = NOW() WHERE id = ? AND tenant_id = ? AND status IN ('requested', 'pending')"
    );
    $claim->execute([$req['id'], $req['tenant_id']]);
    if ($claim->rowCount() !== 1) {
        return false;
    }
    $tenantId = $req['tenant_id'];
    $customerId = $req['customer_id'];

    $pmId = is_array($setupIntent['payment_method'] ?? null) ? ($setupIntent['payment_method']['id'] ?? null) : ($setupIntent['payment_method'] ?? null);
    $stripeMandateId = is_array($setupIntent['mandate'] ?? null) ? ($setupIntent['mandate']['id'] ?? null) : ($setupIntent['mandate'] ?? null);
    $stripeCustomerId = is_array($setupIntent['customer'] ?? null) ? ($setupIntent['customer']['id'] ?? null) : ($setupIntent['customer'] ?? null);
    if (!$pmId) {
        $pdo->prepare("UPDATE mandate_requests SET status = 'unusable' WHERE id = ?")->execute([$req['id']]);
        error_log('Mandatsanforderung ' . $req['id'] . ': SetupIntent ohne Zahlungsmethode.');
        return false;
    }

    $pm = [];
    try {
        $pm = $stripe->getPaymentMethod((string)$pmId);
    } catch (Throwable $e) {
        error_log('Zahlungsmethode ' . $pmId . ' nicht abrufbar: ' . $e->getMessage());
    }
    $sepa = $pm['sepa_debit'] ?? [];
    $last4 = preg_replace('/\D/', '', (string)($sepa['last4'] ?? ''));
    $country = strtoupper(substr((string)($sepa['country'] ?? 'DE'), 0, 2));
    $holder = trim((string)($pm['billing_details']['name'] ?? '')) ?: (string)($req['customer_name'] ?? 'Kontoinhaber');

    $reference = null;
    if ($stripeMandateId) {
        try {
            $m = $stripe->getMandate((string)$stripeMandateId);
            $reference = $m['payment_method_details']['sepa_debit']['reference'] ?? null;
        } catch (Throwable $e) {
            error_log('Stripe-Mandat ' . $stripeMandateId . ' nicht abrufbar: ' . $e->getMessage());
        }
    }

    // Bankverbindung: passende aktive IBAN weiterverwenden, sonst maskierte
    // Bankverbindung aus Stripe anlegen und bisherige deaktivieren.
    $stmt = $pdo->prepare('SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? AND is_active = 1');
    $stmt->execute([$customerId, $tenantId]);
    $active = $stmt->fetchAll();
    $ibanId = null;
    foreach ($active as $row) {
        if ($last4 !== '' && substr((string)$row['iban'], -4) === $last4 && str_starts_with((string)$row['iban'], $country)) {
            $ibanId = $row['id'];
            break;
        }
    }
    if ($ibanId === null) {
        require_once __DIR__ . '/iban.php';
        $length = IBAN_LENGTHS[$country] ?? 22;
        $masked = $country . str_repeat('*', max(0, $length - 2 - 4)) . ($last4 !== '' ? $last4 : '****');
        $ibanId = uuid4();
        $pdo->beginTransaction();
        try {
            foreach ($active as $old) {
                $pdo->prepare('UPDATE customer_ibans SET is_active = 0 WHERE id = ?')->execute([$old['id']]);
                $pdo->prepare(
                    'INSERT INTO iban_history (id, tenant_id, customer_iban_id, action, old_iban, new_iban, changed_by, change_reason)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([uuid4(), $tenantId, $old['id'], 'deactivated', $old['iban'], $masked, 'system', 'Ersetzt durch digital erteiltes Mandat (Stripe)']);
            }
            $pdo->prepare(
                'INSERT INTO customer_ibans (id, tenant_id, customer_id, iban, bic, account_holder_name, source, is_active)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, 1)'
            )->execute([$ibanId, $tenantId, $customerId, $masked, mb_substr($holder, 0, 255), 'stripe_digital']);
            $pdo->prepare(
                'INSERT INTO iban_history (id, tenant_id, customer_iban_id, action, new_iban, changed_by, change_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([uuid4(), $tenantId, $ibanId, 'created', $masked, 'system', 'Digital erteiltes Mandat (Stripe), IBAN nur maskiert bekannt']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $mandate = get_or_create_mandate($tenantId, $customerId, $ibanId);
    $pdo->prepare(
        "UPDATE sepa_mandates
         SET stripe_payment_method_id = ?, stripe_customer_id = COALESCE(?, stripe_customer_id),
             stripe_mandate_id = ?, stripe_mandate_reference = ?, status = 'active', is_active = 1,
             signed_date = CURDATE(), mandate_date = CURDATE(), signed_place = 'digital (Stripe)'
         WHERE id = ?"
    )->execute([(string)$pmId, $stripeCustomerId, $stripeMandateId ? (string)$stripeMandateId : null,
        $reference !== null ? mb_substr((string)$reference, 0, 64) : null, $mandate['id']]);
    $pdo->prepare('UPDATE customers SET sepa_debit_enabled = 1 WHERE id = ? AND tenant_id = ?')->execute([$customerId, $tenantId]);

    $pdo->prepare(
        'UPDATE mandate_requests SET stripe_setup_intent_id = ?, stripe_payment_method_id = ?, stripe_mandate_id = ?, mandate_id = ? WHERE id = ?'
    )->execute([(string)($setupIntent['id'] ?? ''), (string)$pmId, $stripeMandateId ? (string)$stripeMandateId : null, $mandate['id'], $req['id']]);

    audit_log($tenantId, null, 'mandate_granted_digital', 'mandate', $mandate['id'], [
        'mandate_reference' => $mandate['mandate_reference'], 'stripe_mandate_id' => $stripeMandateId,
        'stripe_mandate_reference' => $reference, 'customer_id' => $customerId, 'request_id' => $req['id'],
    ]);
    return true;
}

/**
 * Erinnerungen versenden (höchstens 2 je Anforderung, frühestens nach
 * MANDATE_REQUEST_REMIND_AFTER_DAYS Tagen). Da nur der Hash des Links
 * gespeichert ist, wird mit jeder Erinnerung ein neues Token erzeugt; der
 * bisherige Link wird ungültig, die Gültigkeit verlängert sich nicht.
 * Noch ohne Cron-Anbindung (siehe docs/payment-safety.md).
 *
 * @return array{checked:int,sent:int}
 */
function mandate_request_remind(?string $tenantId = null): array
{
    $pdo = db();
    $sql = "SELECT r.*, c.name AS customer_name, c.email AS customer_email, c.customer_number, c.is_walk_in
            FROM mandate_requests r JOIN customers c ON c.id = r.customer_id
            WHERE r.status = 'requested' AND r.expires_at > NOW() AND r.reminders_sent < ?
              AND COALESCE(r.last_reminded_at, r.created_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params = [MANDATE_REQUEST_MAX_REMINDERS, MANDATE_REQUEST_REMIND_AFTER_DAYS];
    if ($tenantId !== null) {
        $sql .= ' AND r.tenant_id = ?';
        $params[] = $tenantId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $result = ['checked' => count($rows), 'sent' => 0];
    foreach ($rows as $req) {
        if (empty($req['customer_email'])) {
            continue;
        }
        $org = _mandate_org($req['tenant_id']);
        $rawToken = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE mandate_requests SET token_hash = ? WHERE id = ?')->execute([hash('sha256', $rawToken), $req['id']]);
        if (_mandate_request_mail($org, $req, mandate_request_url($rawToken), $req['expires_at'], true)) {
            $pdo->prepare('UPDATE mandate_requests SET reminders_sent = reminders_sent + 1, last_reminded_at = NOW() WHERE id = ?')->execute([$req['id']]);
            audit_log($req['tenant_id'], null, 'mandate_request_reminded', 'mandate_request', $req['id'], [
                'reminder_no' => (int)$req['reminders_sent'] + 1,
            ]);
            $result['sent']++;
        }
    }
    return $result;
}

/** Lesbarer Status einer Anforderung. */
function mandate_request_status_label(string $status): string
{
    return match ($status) {
        'requested' => 'Link versendet',
        'pending'   => 'Bestätigung bei Stripe begonnen',
        'granted'   => 'Digital erteilt',
        'unusable'  => 'Nicht verwendbar',
        'revoked'   => 'Widerrufen',
        'expired'   => 'Abgelaufen',
        default     => $status,
    };
}
