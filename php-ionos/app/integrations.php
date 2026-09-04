<?php
/**
 * Integrationen (Lexware Office, Stripe): Laden, Prüfen, Kontoinformationen.
 *
 * Zugangsdaten werden ausschließlich verschlüsselt gespeichert und niemals
 * protokolliert oder an den Browser ausgegeben. Beim Verbinden und bei jeder
 * manuellen Prüfung werden die geprüften Stammdaten (Kontoname, Konto-ID,
 * Modus) mit Zeitstempel abgelegt, damit die Oberfläche anzeigen kann, mit
 * welchem Konto die Firma tatsächlich verbunden ist.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit; }

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/lexoffice.php';
require_once __DIR__ . '/stripe.php';

function integration_load(string $tenantId): array
{
    $stmt = db()->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if (!$row) {
        db()->prepare('INSERT INTO integrations (id, tenant_id) VALUES (?, ?)')->execute([uuid4(), $tenantId]);
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
    }
    return $row;
}

/** Modus aus dem Schlüsselpräfix ableiten (sk_test_, rk_test_ = Testmodus). */
function stripe_mode_from_key(string $secretKey): string
{
    return preg_match('/^(sk|rk)_test_/', $secretKey) ? 'test' : 'live';
}

/**
 * Stripe-Konto mit dem Schlüssel abrufen und die Kontoinformationen speichern.
 * Wirft eine Exception, wenn der Schlüssel ungültig ist oder Stripe nicht antwortet.
 * @return array{account_id:string, business_name:?string, mode:string}
 */
function integration_verify_stripe(string $tenantId, string $secretKey): array
{
    $account = (new StripeClient($secretKey))->getAccount();
    $accountId = (string)($account['id'] ?? '');
    if ($accountId === '') {
        throw new RuntimeException('Stripe hat kein Konto zu diesem Schlüssel geliefert.');
    }
    $businessName = $account['business_profile']['name']
        ?? $account['settings']['dashboard']['display_name']
        ?? null;
    $mode = stripe_mode_from_key($secretKey);
    db()->prepare(
        'UPDATE integrations SET stripe_account_id = ?, stripe_business_name = ?, stripe_mode = ?, stripe_last_verified_at = NOW() WHERE tenant_id = ?'
    )->execute([$accountId, $businessName !== null ? mb_substr((string)$businessName, 0, 255) : null, $mode, $tenantId]);
    return ['account_id' => $accountId, 'business_name' => $businessName, 'mode' => $mode];
}

/**
 * Lexware-Office-Profil mit dem Schlüssel abrufen und Firmennamen speichern.
 * @return array{company_name:?string}
 */
function integration_verify_lexoffice(string $tenantId, string $apiKey): array
{
    $profile = (new LexofficeClient($apiKey))->getProfile();
    $company = $profile['companyName'] ?? null;
    db()->prepare(
        'UPDATE integrations SET lexoffice_company_name = ?, lexoffice_last_verified_at = NOW() WHERE tenant_id = ?'
    )->execute([$company !== null ? mb_substr((string)$company, 0, 255) : null, $tenantId]);
    return ['company_name' => $company];
}

/** Entschlüsselten Stripe-Schlüssel der Firma liefern (nur serverseitig verwenden). */
function integration_stripe_key(array $integration): ?string
{
    return !empty($integration['stripe_secret_key_encrypted']) ? decrypt_value($integration['stripe_secret_key_encrypted']) : null;
}

function integration_lexoffice_key(array $integration): ?string
{
    return !empty($integration['lexoffice_api_key_encrypted']) ? decrypt_value($integration['lexoffice_api_key_encrypted']) : null;
}

/** Liefert true, wenn die Firma mit einem Stripe-Testschlüssel verbunden ist (Banner im Layout). */
function integration_stripe_test_mode(string $tenantId): bool
{
    static $cache = [];
    if (!array_key_exists($tenantId, $cache)) {
        $stmt = db()->prepare('SELECT stripe_connected, stripe_mode FROM integrations WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        $cache[$tenantId] = $row && (int)$row['stripe_connected'] === 1 && ($row['stripe_mode'] ?? '') === 'test';
    }
    return $cache[$tenantId];
}
