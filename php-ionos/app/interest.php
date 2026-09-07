<?php
/**
 * Vormerkungen (Warteliste) für angekündigte Integrationen, zuerst sevdesk.
 *
 * Öffentlicher Eingang ist vormerken.php (Formular auf smart-einzug.de/integrationen/sevdesk/). Ablauf:
 *   1. interest_register(): Adresse prüfen, Zeile anlegen oder vorhandene verwenden, Bestätigungs-E-Mail
 *      mit Link (Double-Opt-in). Ohne aktiven Mailversand wird die Vormerkung direkt bestätigt.
 *   2. interest_confirm():   Link einlösen, Status confirmed. Der Token bleibt für die Abmeldung gültig.
 *   3. interest_unsubscribe(): Abmeldung über denselben Link, Status unsubscribed (Zeile bleibt als
 *      Nachweis der Abmeldung, ohne weitere Verwendung).
 *
 * Schutz ohne Personenbezug: kein Speichern von IP-Adressen. Missbrauch wird über eine globale Obergrenze
 * je Minute (neue Zeilen), einen Wiederversand-Abstand je Adresse und das Honeypot-Feld des Formulars
 * begrenzt. Antworten sind für "neu", "bereits vorgemerkt" und "bereits bestätigt" bewusst gleich
 * formuliert, damit sich über das Formular nicht ermitteln lässt, welche Adressen hinterlegt sind.
 *
 * Es gibt keine Preisangabe, keinen Kaufbutton und keinen Firmenaccount: Eine Vormerkung ist die Bitte um
 * eine Nachricht zum Start und nichts weiter.
 */
if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/invoice_source.php';

/** Fassung des Einwilligungstextes; bei Textänderung hochzählen, damit nachvollziehbar bleibt, wem was vorlag. */
const INTEREST_CONSENT_VERSION = 'vormerkung-v1';
/** Neue Vormerkungen je Minute über alle Anbieter (Missbrauchsgrenze ohne IP-Speicherung). */
const INTEREST_MAX_PER_MINUTE = 30;
/** Frühester Wiederversand der Bestätigungs-E-Mail je Adresse in Sekunden. */
const INTEREST_RESEND_SECONDS = 600;
/** Gültigkeit des Bestätigungslinks in Tagen. */
const INTEREST_TOKEN_DAYS = 7;

/**
 * Anbieter, für die eine Vormerkung möglich ist: alle Rechnungssysteme, die noch nicht freigegeben sind.
 * @return array<string, array> code => Zeile aus integration_providers
 */
function interest_open_providers(): array
{
    $out = [];
    foreach (integration_providers('invoice_system') as $p) {
        if (($p['status'] ?? '') !== 'released') {
            $out[(string)$p['code']] = $p;
        }
    }
    return $out;
}

/** Eingabe prüfen, ohne die Datenbank zu berühren. Liefert ['ok' => bool, 'error' => code, 'email', 'company']. */
function interest_validate(array $input): array
{
    $email = mb_strtolower(trim((string)($input['email'] ?? '')));
    $company = trim(preg_replace('/\s+/u', ' ', (string)($input['company'] ?? '')) ?? '');
    $provider = trim((string)($input['provider'] ?? ''));
    if (trim((string)($input['website'] ?? '')) !== '') {
        // Honeypot: Menschen sehen das Feld nicht, Skripte füllen es aus.
        return ['ok' => false, 'error' => 'honeypot'];
    }
    if (!preg_match('/^[a-z0-9_]{2,32}$/', $provider)) {
        return ['ok' => false, 'error' => 'provider'];
    }
    if ($email === '' || mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'email'];
    }
    if (mb_strlen($company) > 160) {
        return ['ok' => false, 'error' => 'company'];
    }
    if (empty($input['consent'])) {
        return ['ok' => false, 'error' => 'consent'];
    }
    return ['ok' => true, 'error' => null, 'email' => $email, 'company' => $company !== '' ? $company : null, 'provider' => $provider];
}

/**
 * Vormerkung anlegen oder Bestätigungsmail erneut senden.
 * @return array ['ok' => bool, 'error' => ?string, 'state' => 'mail_sent'|'confirmed'|'already'|'mail_failed', 'id' => ?string]
 */
function interest_register(array $input, ?string $sourceDomain = null): array
{
    $v = interest_validate($input);
    if (!$v['ok']) {
        return ['ok' => false, 'error' => $v['error'], 'state' => null, 'id' => null];
    }
    $providers = interest_open_providers();
    if (!isset($providers[$v['provider']])) {
        return ['ok' => false, 'error' => 'provider', 'state' => null, 'id' => null];
    }
    $pdo = db();

    $recent = (int)$pdo->query(
        'SELECT COUNT(*) FROM interest_registrations WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)'
    )->fetchColumn();
    if ($recent >= INTEREST_MAX_PER_MINUTE) {
        return ['ok' => false, 'error' => 'busy', 'state' => null, 'id' => null];
    }

    $stmt = $pdo->prepare('SELECT * FROM interest_registrations WHERE provider_code = ? AND email = ?');
    $stmt->execute([$v['provider'], $v['email']]);
    $row = $stmt->fetch();

    $domain = $sourceDomain !== null ? mb_substr(strtolower(preg_replace('/^www\./', '', $sourceDomain)), 0, 100) : null;

    if ($row && $row['status'] === 'confirmed') {
        // Bereits bestätigt: nichts senden, nach außen dieselbe Antwort wie bei einer neuen Vormerkung.
        return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
    }
    if ($row && $row['last_mail_at'] !== null
        && (time() - strtotime((string)$row['last_mail_at'] . ' UTC')) < INTEREST_RESEND_SECONDS) {
        return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = gmdate('Y-m-d H:i:s', time() + INTEREST_TOKEN_DAYS * 86400);

    if ($row) {
        $id = (string)$row['id'];
        $pdo->prepare(
            'UPDATE interest_registrations SET status = ?, company = COALESCE(?, company), source_domain = COALESCE(?, source_domain),
                    consent_text = ?, token_hash = ?, token_expires_at = ?, unsubscribed_at = NULL WHERE id = ?'
        )->execute(['pending', $v['company'], $domain, INTEREST_CONSENT_VERSION, $tokenHash, $expires, $id]);
    } else {
        $id = uuid4();
        $pdo->prepare(
            'INSERT INTO interest_registrations (id, provider_code, email, company, source_domain, status, consent_text, token_hash, token_expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$id, $v['provider'], $v['email'], $v['company'], $domain, 'pending', INTEREST_CONSENT_VERSION, $tokenHash, $expires]);
    }

    if (!mail_enabled()) {
        // Kein Mailversand konfiguriert: Double-Opt-in nicht möglich, Vormerkung gilt direkt.
        $pdo->prepare("UPDATE interest_registrations SET status = 'confirmed', confirmed_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'error' => null, 'state' => 'confirmed', 'id' => $id];
    }

    $providerName = (string)($providers[$v['provider']]['name'] ?? $v['provider']);
    $confirmUrl = app_base_url() . '/vormerken.php?token=' . $token;
    $tpl = mail_tpl_interest_confirm($providerName, $confirmUrl);
    $sent = mail_send($v['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
    if ($sent) {
        $pdo->prepare('UPDATE interest_registrations SET last_mail_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
        return ['ok' => true, 'error' => null, 'state' => 'mail_sent', 'id' => $id];
    }
    return ['ok' => true, 'error' => null, 'state' => 'mail_failed', 'id' => $id];
}

/** Zeile zu einem Klartext-Token oder null (auch bei abgelaufenem Bestätigungslink im Zustand pending). */
function interest_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM interest_registrations WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($row['status'] === 'pending' && $row['token_expires_at'] !== null
        && strtotime((string)$row['token_expires_at'] . ' UTC') < time()) {
        return null;
    }
    return $row;
}

/** Bestätigungslink einlösen. Liefert 'confirmed', 'already' oder 'invalid'. */
function interest_confirm(string $token): string
{
    $row = interest_by_token($token);
    if (!$row) {
        return 'invalid';
    }
    if ($row['status'] === 'confirmed') {
        return 'already';
    }
    // Der Token bleibt gespeichert und verliert sein Ablaufdatum: Er dient ab jetzt der Abmeldung.
    db()->prepare("UPDATE interest_registrations SET status = 'confirmed', confirmed_at = UTC_TIMESTAMP(), token_expires_at = NULL, unsubscribed_at = NULL WHERE id = ?")
        ->execute([$row['id']]);
    return 'confirmed';
}

/** Abmeldung über den Link. Liefert 'unsubscribed' oder 'invalid'. */
function interest_unsubscribe(string $token): string
{
    $row = interest_by_token($token);
    if (!$row) {
        return 'invalid';
    }
    db()->prepare("UPDATE interest_registrations SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP() WHERE id = ?")
        ->execute([$row['id']]);
    return 'unsubscribed';
}

/** Kennzahlen je Anbieter für den Adminbereich: [code => ['name','pending','confirmed','unsubscribed']]. */
function interest_stats(): array
{
    $rows = db()->query(
        'SELECT r.provider_code, p.name, r.status, COUNT(*) AS n
           FROM interest_registrations r LEFT JOIN integration_providers p ON p.code = r.provider_code
          GROUP BY r.provider_code, p.name, r.status'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $c = (string)$r['provider_code'];
        $out[$c] ??= ['name' => (string)($r['name'] ?? $c), 'pending' => 0, 'confirmed' => 0, 'unsubscribed' => 0];
        $out[$c][(string)$r['status']] = (int)$r['n'];
    }
    return $out;
}

/** Jüngste Vormerkungen für den Adminbereich. */
function interest_recent(int $limit = 200): array
{
    $stmt = db()->prepare(
        'SELECT id, provider_code, email, company, source_domain, status, created_at, confirmed_at, unsubscribed_at
           FROM interest_registrations ORDER BY created_at DESC LIMIT ' . max(1, min(1000, $limit))
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Wartung (job_maintenance): Unbestätigte Vormerkungen, deren Link seit mehr als 23 Tagen abgelaufen ist
 * (also 30 Tage nach Eintragung), werden gelöscht. Bestätigte und abgemeldete Einträge bleiben.
 * @return int gelöschte Zeilen
 */
function interest_cleanup(): int
{
    $stmt = db()->prepare(
        "DELETE FROM interest_registrations WHERE status = 'pending' AND token_expires_at IS NOT NULL
            AND token_expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 23 DAY)"
    );
    $stmt->execute();
    return $stmt->rowCount();
}
