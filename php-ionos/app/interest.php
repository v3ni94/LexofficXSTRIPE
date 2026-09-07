<?php
/**
 * Vormerkungen (Warteliste) für angekündigte Integrationen, zuerst sevdesk.
 *
 * Öffentlicher Eingang ist vormerken.php (Formular auf smart-einzug.de/integrationen/sevdesk/). Ablauf:
 *   1. interest_register(): Adresse prüfen, Zeile anlegen oder vorhandene verwenden, Bestätigungs-E-Mail
 *      mit Bestätigungs- und Abmeldelink (Double-Opt-in). Ohne funktionierenden Mailversand gibt es KEINE
 *      Vormerkung (mail_failed), niemals eine stille Bestätigung.
 *   2. interest_confirm():   Bestätigung (nach Klick auf den Button der Bestätigungsseite), Status confirmed.
 *      Der Token bleibt ohne Ablauf gültig, aber nur noch für die Abmeldung.
 *   3. interest_unsubscribe(): Abmeldung, Status unsubscribed. Eine abgemeldete Zeile lässt sich über den
 *      alten Link nicht wieder bestätigen; erneute Vormerkung nur über das Formular mit neuer Mail.
 *   4. interest_cleanup() (Wartung, beide Betriebspfade cron.php und job_maintenance): löscht unbestätigte
 *      Zeilen 30 Tage nach Eintragung, abgemeldete 30 Tage nach Abmeldung und bestätigte 30 Tage nach der
 *      Startnachricht (notified_at). Das entspricht den Zusagen in Datenschutzerklärung 3a.
 *
 * Zeitstempel: durchgehend UTC (UTC_TIMESTAMP(), Vergleich in PHP mit ' UTC'); Anzeige rechnet um
 * (interest_local()). Schutz ohne Personenbezug (keine IP-Adressen): globale Obergrenze neuer Zeilen je Minute,
 * Wiederversand frühestens nach 10 Minuten je Adresse (auch nach fehlgeschlagenem Versuch), höchstens 3
 * Bestätigungsmails je Adresse in 24 Stunden, Honeypot im Formular. "mail_sent" bedeutet bei aktiver
 * Warteschlange: eingereiht, nicht zugestellt. Antworten für neu, unbestätigt und bereits bestätigt sind gleich,
 * damit sich über das Formular nicht ermitteln lässt, welche Adressen hinterlegt sind.
 *
 * Keine Preisangabe, kein Kaufbutton, kein Firmenaccount: Eine Vormerkung ist die Bitte um eine Nachricht
 * zum Start (oder zu einer wesentlichen Terminänderung) und nichts weiter.
 */
if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/invoice_source.php';

/**
 * Fassung des Einwilligungstextes. Wortlaut je Fassung ist in docs/einwilligungen.md archiviert; bei jeder
 * Textänderung auf der Seite hochzählen und dort ergänzen.
 */
const INTEREST_CONSENT_VERSION = 'vormerkung-v2';
const INTEREST_MAX_PER_MINUTE = 30;
const INTEREST_RESEND_SECONDS = 600;
const INTEREST_MAILS_PER_DAY = 3;
const INTEREST_TOKEN_DAYS = 7;
const INTEREST_RETENTION_DAYS = 30;

/** @return array<string, array> Rechnungssysteme, die noch nicht freigegeben sind (code => Zeile) */
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

/** Eingabe prüfen, ohne Datenbank. */
function interest_validate(array $input): array
{
    $email = mb_strtolower(trim((string)($input['email'] ?? '')));
    $company = trim(preg_replace('/\s+/u', ' ', (string)($input['company'] ?? '')) ?? '');
    $provider = trim((string)($input['provider'] ?? ''));
    if (trim((string)($input['website'] ?? '')) !== '') {
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
 * Herkunftsprüfung des Formulars (ohne Datenbank, testbar): erlaubt sind die Marketingdomains
 * (signup_domains, mit oder ohne www) und der Host der Anwendung. Fehlen Origin und Referer (Datenschutz-
 * Erweiterungen, "Origin: null"), gilt die Anfrage nicht als abgelehnt; die Prüfung schützt gegen
 * browsergestützte Einbettung fremder Seiten, nicht gegen Skripte. Dagegen wirken die Mengenbegrenzungen.
 */
function interest_origin_allowed(?string $origin, ?string $referer, array $allowedHosts): bool
{
    $host = '';
    foreach ([$origin, $referer] as $v) {
        if ($v !== null && $v !== '') {
            $host = strtolower((string)parse_url($v, PHP_URL_HOST));
            break;
        }
    }
    if ($host === '') {
        return true;
    }
    $allowed = array_map('strtolower', $allowedHosts);
    return in_array($host, $allowed, true) || in_array(preg_replace('/^www\./', '', $host), $allowed, true);
}

/** UTC-Zeitstempel der Datenbank als Unixzeit (0 bei leer). */
function interest_ts(?string $utc): int
{
    return $utc ? (int)strtotime($utc . ' UTC') : 0;
}

/** UTC-Zeitstempel in Ortszeit der Anwendung für format_datetime(). */
function interest_local(?string $utc): ?string
{
    return $utc ? date('Y-m-d H:i:s', interest_ts($utc)) : null;
}

/**
 * Vormerkung anlegen oder Bestätigungsmail erneut senden.
 * @return array ['ok' => bool, 'error' => ?string, 'state' => 'mail_sent'|'already'|'mail_failed'|null, 'id' => ?string]
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

    $domain = $sourceDomain !== null ? mb_substr(strtolower(preg_replace('/^www\./', '', $sourceDomain)), 0, 100) : null;
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = gmdate('Y-m-d H:i:s', time() + INTEREST_TOKEN_DAYS * 86400);

    $lade = static function () use ($pdo, $v): ?array {
        $s = $pdo->prepare('SELECT * FROM interest_registrations WHERE provider_code = ? AND email = ?');
        $s->execute([$v['provider'], $v['email']]);
        $r = $s->fetch();
        return $r ?: null;
    };
    $row = $lade();
    if (!$row) {
        try {
            $pdo->prepare(
                'INSERT INTO interest_registrations (id, provider_code, email, company, source_domain, status, consent_text, consent_at, token_hash, token_expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP())'
            )->execute([uuid4(), $v['provider'], $v['email'], $v['company'], $domain, 'pending', INTEREST_CONSENT_VERSION, $tokenHash, $expires]);
        } catch (PDOException $e) {
            // Wettlauf zweier gleichzeitiger Einsendungen (UNIQUE KEY): die andere Anfrage hat gewonnen.
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
        }
        $row = $lade();
        if (!$row) {
            throw new RuntimeException('Vormerkung konnte nicht angelegt werden.');
        }
        if ($row['token_hash'] !== $tokenHash) {
            // Zeile stammt aus der parallelen Anfrage; diese Anfrage sendet keine zweite Mail.
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
    } else {
        if ($row['status'] === 'confirmed') {
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
        $seitLetzterMail = time() - interest_ts($row['last_mail_at']);
        if ($row['last_mail_at'] !== null && $seitLetzterMail < INTEREST_RESEND_SECONDS) {
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
        $fensterAlt = interest_ts($row['mail_window_at']);
        if ($fensterAlt > 0 && time() - $fensterAlt < 86400 && (int)$row['mail_count'] >= INTEREST_MAILS_PER_DAY) {
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
        $pdo->prepare(
            'UPDATE interest_registrations
                SET status = ?, company = COALESCE(?, company), source_domain = COALESCE(?, source_domain),
                    consent_text = ?, consent_at = UTC_TIMESTAMP(), token_hash = ?, token_expires_at = ?
              WHERE id = ?'
        )->execute(['pending', $v['company'], $domain, INTEREST_CONSENT_VERSION, $tokenHash, $expires, $row['id']]);
    }
    $id = (string)$row['id'];

    // Versuch zählen, BEVOR gesendet wird: auch ein fehlgeschlagener Versuch sperrt die Wiederholung.
    $fensterAlt = interest_ts($row['mail_window_at'] ?? null);
    $neuesFenster = $fensterAlt === 0 || time() - $fensterAlt >= 86400;
    $pdo->prepare(
        'UPDATE interest_registrations
            SET last_mail_at = UTC_TIMESTAMP(),
                mail_window_at = ' . ($neuesFenster ? 'UTC_TIMESTAMP()' : 'mail_window_at') . ',
                mail_count = ' . ($neuesFenster ? '1' : 'mail_count + 1') . '
          WHERE id = ?'
    )->execute([$id]);

    $providerName = (string)($providers[$v['provider']]['name'] ?? $v['provider']);
    $confirmUrl = app_base_url() . '/vormerken.php?token=' . $token;
    $tpl = mail_tpl_interest_confirm($providerName, $confirmUrl, $confirmUrl . '&aktion=abmelden');
    if (mail_send($v['email'], $tpl['subject'], $tpl['text'], $tpl['html'])) {
        return ['ok' => true, 'error' => null, 'state' => 'mail_sent', 'id' => $id];
    }
    return ['ok' => true, 'error' => null, 'state' => 'mail_failed', 'id' => $id];
}

/** Zeile zu einem Klartext-Token oder null (auch bei abgelaufenem Link im Zustand pending). */
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
    if ($row['status'] === 'pending' && $row['token_expires_at'] !== null && interest_ts($row['token_expires_at']) < time()) {
        return null;
    }
    return $row;
}

/** Bestätigung: 'confirmed', 'already' oder 'invalid' (auch für abgemeldete Zeilen). */
function interest_confirm(string $token): string
{
    $row = interest_by_token($token);
    if (!$row || $row['status'] === 'unsubscribed') {
        return 'invalid';
    }
    if ($row['status'] === 'confirmed') {
        return 'already';
    }
    db()->prepare("UPDATE interest_registrations SET status = 'confirmed', confirmed_at = UTC_TIMESTAMP(), token_expires_at = NULL WHERE id = ?")
        ->execute([$row['id']]);
    return 'confirmed';
}

/** Abmeldung: 'unsubscribed' oder 'invalid'. */
function interest_unsubscribe(string $token): string
{
    $row = interest_by_token($token);
    if (!$row) {
        return 'invalid';
    }
    return interest_unsubscribe_id((string)$row['id']) ? 'unsubscribed' : 'invalid';
}

/** Abmeldung einer Zeile (Link oder Adminaktion). */
function interest_unsubscribe_id(string $id): bool
{
    $stmt = db()->prepare("UPDATE interest_registrations SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP() WHERE id = ? AND status <> 'unsubscribed'");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/** Löschung einer Zeile (Adminaktion, z. B. Auskunfts- oder Löschverlangen). */
function interest_delete_id(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM interest_registrations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Wartung: löscht unbestätigte Zeilen 30 Tage nach Eintragung, abgemeldete 30 Tage nach Abmeldung und
 * bestätigte 30 Tage nach der Startnachricht. @return int gelöschte Zeilen
 */
function interest_cleanup(): int
{
    $d = (int)INTEREST_RETENTION_DAYS;
    $stmt = db()->prepare(
        "DELETE FROM interest_registrations
          WHERE (status = 'pending' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $d DAY))
             OR (status = 'unsubscribed' AND unsubscribed_at IS NOT NULL AND unsubscribed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $d DAY))
             OR (status = 'confirmed' AND notified_at IS NOT NULL AND notified_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $d DAY))"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/** Kennzahlen je Anbieter für den Adminbereich. */
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

/** Jüngste Vormerkungen (Zeitstempel bereits in Ortszeit umgerechnet). */
function interest_recent(int $limit = 200): array
{
    $stmt = db()->prepare(
        'SELECT id, provider_code, email, company, source_domain, status, created_at, confirmed_at, unsubscribed_at
           FROM interest_registrations ORDER BY created_at DESC LIMIT ' . max(1, min(1000, $limit))
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        foreach (['created_at', 'confirmed_at', 'unsubscribed_at'] as $k) {
            $r[$k] = interest_local($r[$k]);
        }
    }
    return $rows;
}
