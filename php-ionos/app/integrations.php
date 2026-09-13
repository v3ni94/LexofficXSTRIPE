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
require_once __DIR__ . '/invoice_source.php';
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

/** Zulaessige Werte von integrations.stripe_sepa_capability (Stripe: capabilities.sepa_debit_payments). */
const STRIPE_SEPA_CAPABILITY_VALUES = ['active', 'inactive', 'pending', 'unrequested', 'unknown'];

/**
 * Verbindungszustaende zu Stripe (seit 4.79). Kontoverbindung ist ein eigener Zustandsbereich, getrennt von Einzug,
 * Mandat, Abonnement und Auszahlung; eine Rueckleitung oder ein gespeicherter Schluessel allein heisst nicht "bereit".
 * ready: Einzuege moeglich. Alle anderen Zustaende sperren neue Einzuege (siehe _get_stripe_client()), ausser
 * unverified (Verbindung aus der Zeit vor 4.79, noch nicht neu geprueft) und sepa_pending (Stripe prueft noch).
 */
const STRIPE_CONNECTION_STATES = [
    'not_connected'      => ['label' => 'Nicht verbunden',            'ready' => false, 'badge' => 'neutral'],
    'disconnected'       => ['label' => 'Getrennt',                   'ready' => false, 'badge' => 'neutral'],
    'auth_failed'        => ['label' => 'Schlüssel ungültig',         'ready' => false, 'badge' => 'danger'],
    'permission_missing' => ['label' => 'Berechtigung fehlt',         'ready' => false, 'badge' => 'danger'],
    'degraded'           => ['label' => 'Vorübergehend gestört',      'ready' => false, 'badge' => 'warn'],
    'charges_disabled'   => ['label' => 'Konto nimmt keine Zahlungen an', 'ready' => false, 'badge' => 'danger'],
    'sepa_unavailable'   => ['label' => 'SEPA-Lastschrift nicht verfügbar', 'ready' => false, 'badge' => 'danger'],
    'sepa_pending'       => ['label' => 'SEPA-Lastschrift in Prüfung', 'ready' => true,  'badge' => 'warn'],
    'unverified'         => ['label' => 'Verbunden, Fähigkeiten nicht geprüft', 'ready' => true, 'badge' => 'warn'],
    'ready'              => ['label' => 'Bereit',                     'ready' => true,  'badge' => 'success'],
];

/**
 * Stripe-Konto mit dem Schlüssel abrufen und die Kontoinformationen speichern.
 * Wirft eine Exception, wenn der Schlüssel ungültig ist oder Stripe nicht antwortet; der Aufrufer vermerkt den
 * Fehler mit integration_stripe_verify_failed(), damit der Verbindungszustand ihn zeigt.
 *
 * Seit 4.79 werden zusaetzlich charges_enabled und capabilities.sepa_debit_payments uebernommen: Ein Konto ohne
 * aktive SEPA-Faehigkeit gilt als verbunden, aber nicht bereit (STRIPE_CONNECTION_STATES), und _get_stripe_client()
 * verweigert den Einzug mit klarer Meldung statt eines Stripe-Rohtexts.
 * @return array{account_id:string, business_name:?string, mode:string, charges_enabled:?bool, sepa_capability:string}
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
    $chargesEnabled = array_key_exists('charges_enabled', $account) ? (bool)$account['charges_enabled'] : null;
    $sepa = 'unknown';
    if (isset($account['capabilities']) && is_array($account['capabilities'])) {
        $raw = strtolower((string)($account['capabilities']['sepa_debit_payments'] ?? ''));
        $sepa = $raw === '' ? 'unrequested' : (in_array($raw, STRIPE_SEPA_CAPABILITY_VALUES, true) ? $raw : 'unknown');
    }
    db()->prepare(
        'UPDATE integrations SET stripe_account_id = ?, stripe_business_name = ?, stripe_mode = ?, stripe_last_verified_at = NOW(),
                stripe_charges_enabled = ?, stripe_sepa_capability = ?, stripe_verify_error = NULL
          WHERE tenant_id = ?'
    )->execute([$accountId, $businessName !== null ? mb_substr((string)$businessName, 0, 255) : null, $mode,
        $chargesEnabled === null ? null : ($chargesEnabled ? 1 : 0), $sepa, $tenantId]);
    return ['account_id' => $accountId, 'business_name' => $businessName, 'mode' => $mode, 'charges_enabled' => $chargesEnabled, 'sepa_capability' => $sepa];
}

/**
 * Fehlgeschlagene Kontopruefung einer BESTEHENDEN Verbindung vermerken (nur die Fehlerklasse, nie der Text mit
 * Schluesselteilen): 401 = Schluessel ungueltig oder widerrufen, 403 = eingeschraenkter Schluessel ohne Recht,
 * alles andere technisch (Netz, 5xx). Liefert die Klasse zurueck.
 */
function integration_stripe_verify_failed(string $tenantId, Throwable $e): string
{
    $status = $e instanceof StripeException ? (int)($e->httpStatus ?? 0) : 0;
    $code = $e instanceof StripeException ? strtolower((string)($e->stripeCode ?? '')) : '';
    $class = $status === 401 ? 'auth' : (($status === 403 || str_contains($code, 'permission')) ? 'permission' : 'technical');
    db()->prepare('UPDATE integrations SET stripe_verify_error = ? WHERE tenant_id = ? AND stripe_connected = 1')->execute([$class, $tenantId]);
    return $class;
}

/**
 * Verbindungszustand zu Stripe aus der Integrationszeile ableiten (Schluessel STRIPE_CONNECTION_STATES).
 * @return array{code:string,label:string,ready:bool,badge:string,hint:string}
 */
function stripe_connection_state(array $integration): array
{
    $code = 'ready';
    $hint = 'Konto geprüft, SEPA-Lastschrift aktiv.';
    if ((int)($integration['stripe_connected'] ?? 0) !== 1) {
        $code = !empty($integration['stripe_disconnected_at']) ? 'disconnected' : 'not_connected';
        $hint = $code === 'disconnected' ? 'Die Verbindung wurde getrennt; neue Einzüge sind bis zur erneuten Verbindung nicht möglich.' : 'Noch kein Stripe-Schlüssel hinterlegt.';
    } elseif (($integration['stripe_verify_error'] ?? null) === 'auth') {
        $code = 'auth_failed';
        $hint = 'Stripe lehnt den hinterlegten Schlüssel ab (ungültig oder widerrufen). Bitte neuen Schlüssel eintragen.';
    } elseif (($integration['stripe_verify_error'] ?? null) === 'permission') {
        $code = 'permission_missing';
        $hint = 'Der eingeschränkte Schlüssel hat nicht die nötigen Rechte (Konto lesen; Customers, Payment Methods, Payment Intents schreiben; Charges und Disputes lesen).';
    } elseif (($integration['stripe_verify_error'] ?? null) === 'technical') {
        $code = 'degraded';
        $hint = 'Die letzte Prüfung scheiterte technisch (Stripe nicht erreichbar). Bitte später erneut prüfen.';
    } elseif (isset($integration['stripe_charges_enabled']) && (int)$integration['stripe_charges_enabled'] === 0) {
        $code = 'charges_disabled';
        $hint = 'Stripe hat für dieses Konto keine Zahlungen freigeschaltet (charges_enabled = false). Bitte die Kontoverifizierung im Stripe-Dashboard abschließen.';
    } elseif (in_array($integration['stripe_sepa_capability'] ?? null, ['inactive', 'unrequested'], true)) {
        $code = 'sepa_unavailable';
        $hint = 'SEPA-Lastschrift ist in diesem Stripe-Konto nicht aktiv. Im Stripe-Dashboard unter Zahlungsmethoden SEPA-Lastschrift aktivieren, danach hier „Verbindung prüfen“.';
    } elseif (($integration['stripe_sepa_capability'] ?? null) === 'pending') {
        $code = 'sepa_pending';
        $hint = 'Stripe prüft die Freischaltung der SEPA-Lastschrift noch. Einzüge können bis zum Abschluss scheitern.';
    } elseif (empty($integration['stripe_sepa_capability']) || $integration['stripe_sepa_capability'] === 'unknown') {
        $code = 'unverified';
        $hint = 'Die Verbindung stammt aus einer Zeit vor der Fähigkeitsprüfung. Bitte einmal „Verbindung prüfen“ ausführen.';
    }
    $meta = STRIPE_CONNECTION_STATES[$code];
    return ['code' => $code, 'label' => $meta['label'], 'ready' => $meta['ready'], 'badge' => $meta['badge'], 'hint' => $hint];
}

/**
 * Lexware-Office-Profil mit dem Schlüssel abrufen und Firmennamen speichern.
 * @return array{company_name:?string}
 */
function integration_verify_lexoffice(string $tenantId, string $apiKey): array
{
    $profile = invoice_source_from_key(LexwareOfficeSource::CODE, $apiKey)->getProfile();
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

/**
 * sevdesk-Verbindungstest mit dem Token (Erreichbarkeit und Berechtigung, siehe SevdeskSource::getProfile) und
 * Pruefzeitpunkt speichern. Ein Firmenname liegt erst vor, wenn der passende Endpunkt verifiziert ist.
 * @return array{company_name:?string}
 */
function integration_verify_sevdesk(string $tenantId, string $apiKey): array
{
    $profile = invoice_source_from_key('sevdesk', $apiKey)->getProfile();
    $company = $profile['companyName'] ?? null;
    db()->prepare(
        'UPDATE integrations SET sevdesk_company_name = ?, sevdesk_last_verified_at = NOW() WHERE tenant_id = ?'
    )->execute([$company !== null ? mb_substr((string)$company, 0, 255) : null, $tenantId]);
    return ['company_name' => $company];
}

function integration_sevdesk_key(array $integration): ?string
{
    return !empty($integration['sevdesk_api_key_encrypted']) ? decrypt_value($integration['sevdesk_api_key_encrypted']) : null;
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
