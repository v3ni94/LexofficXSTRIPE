<?php
/**
 * Vorregistrierung (unverbindliche Warteliste) für angekündigte Integrationen, zuerst sevdesk.
 *
 * Eingang ist vormerken.php (Formular auf smart-einzug.de/integrationen/sevdesk/). Ablauf:
 *   1. interest_register(): prüfen, Zeile anlegen oder vorhandene verwenden, Bestätigungs-E-Mail mit
 *      Bestätigungslink (Token A, 7 Tage) und Abmeldelink (Token B, dauerhaft). Kann die Mail nicht erzeugt werden
 *      (Versand nicht aktiv oder gestört), bleibt der Eintrag pending und wird als wartend markiert (mail_deferred);
 *      interest_send_pending() sendet sie in der Wartung nach. Niemals eine stille Bestätigung.
 *   2. interest_confirm():   nach Klick auf den Button der Bestätigungsseite (kein Auslösen per bloßem GET,
 *      damit Linkscanner keinen Einwilligungsnachweis vortäuschen). Token A wird danach gelöscht.
 *   3. interest_unsubscribe(): über Token B. Eine erneute Eintragung nach Abmeldung erzeugt eine neue
 *      Bestätigungsrunde; die Abmeldung wird nie automatisch aufgehoben.
 *   4. interest_block_id(): Sperrvermerk (Admin): kein Versand, Klartextangaben außer E-Mail entfernt, von der
 *      Löschung ausgenommen, damit die Sperre wirkt. interest_optional_update(): freiwillige Angaben nach
 *      Bestätigung (Betatest-Interesse, Rechnungen je Monat, Stripe- und API-Zugang), nur über Token B.
 *   5. interest_cleanup() (cron.php und job_maintenance): unbestätigt 30 Tage nach Eintragung, abgemeldet 30
 *      Tage nach Abmeldung, bestätigt 30 Tage nach der Startnachricht; gesperrte Einträge bleiben.
 *
 * Zeitstempel in UTC. Kein Speichern von IP-Adressen; Mengenbegrenzung: globale Obergrenze neuer Zeilen je
 * Minute, Wiederversand frühestens nach 10 Minuten je Adresse (auch nach Fehlversuch), höchstens 3 Mails je
 * Adresse in 24 Stunden, Honeypot. Antworten für neu, unbestätigt, bestätigt, gesperrt sind gleich.
 * Kennzahlen: funnel_events interest_submitted (jede gültige Absendung) und interest_confirmed (Bestätigung).
 *
 * Kein Preis, kein Kaufbutton, kein Firmenaccount, kein Abonnement: eine Vormerkung ist die Bitte um Nachrichten
 * zu Entwicklungsstand und Start (Zweck launch_info), einschließlich einer möglichen Betaeinladung.
 */
if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/invoice_source.php';
require_once __DIR__ . '/integration_state.php';

/** Fassung des Einwilligungstextes; Wortlaut je Fassung in docs/einwilligungen.md. */
const INTEREST_CONSENT_VERSION = 'vormerkung-v3';
const INTEREST_PURPOSE = 'launch_info';
const INTEREST_MAX_PER_MINUTE = 30;
const INTEREST_RESEND_SECONDS = 600;
const INTEREST_MAILS_PER_DAY = 3;
const INTEREST_TOKEN_DAYS = 7;
const INTEREST_RETENTION_DAYS = 30;
const INTEREST_INVOICE_RANGES = ['bis_20', '21_100', '101_500', 'ueber_500'];

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

function interest_validate(array $input): array
{
    $email = mb_strtolower(trim((string)($input['email'] ?? '')));
    $clean = static fn(string $v): string => trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    $company = $clean((string)($input['company'] ?? ''));
    $name = $clean((string)($input['name'] ?? ''));
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
    if (mb_strlen($company) > 160 || mb_strlen($name) > 120) {
        return ['ok' => false, 'error' => 'company'];
    }
    if (empty($input['consent'])) {
        return ['ok' => false, 'error' => 'consent'];
    }
    return ['ok' => true, 'error' => null, 'email' => $email, 'company' => $company !== '' ? $company : null,
        'name' => $name !== '' ? $name : null, 'provider' => $provider];
}

/** Herkunftsprüfung ohne Datenbank (nur gegen browsergestützte Einbettung fremder Seiten). */
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

function interest_ts(?string $utc): int
{
    return $utc ? (int)strtotime($utc . ' UTC') : 0;
}

function interest_local(?string $utc): ?string
{
    return $utc ? date('Y-m-d H:i:s', interest_ts($utc)) : null;
}

/**
 * @return array ['ok' => bool, 'error' => ?string, 'state' => 'mail_sent'|'already'|'mail_failed'|null, 'id' => ?string]
 */
function interest_register(array $input, ?string $sourceDomain = null): array
{
    $v = interest_validate($input);
    if (!$v['ok']) {
        return ['ok' => false, 'error' => $v['error'], 'state' => null, 'id' => null];
    }
    $providers = interest_open_providers();
    if (!isset($providers[$v['provider']]) || !integration_switch($v['provider'], 'waitlist')) {
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
    funnel_event($domain, 'interest_submitted', null, null, $v['provider']);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = gmdate('Y-m-d H:i:s', time() + INTEREST_TOKEN_DAYS * 86400);
    $manage = bin2hex(random_bytes(32));

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
                'INSERT INTO interest_registrations (id, provider_code, email, name, company, source_domain, purpose, status, consent_text, consent_at,
                        token_hash, token_expires_at, manage_token_hash, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, UTC_TIMESTAMP())'
            )->execute([uuid4(), $v['provider'], $v['email'], $v['name'], $v['company'], $domain, INTEREST_PURPOSE, 'pending',
                INTEREST_CONSENT_VERSION, $tokenHash, $expires, hash('sha256', $manage)]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
        }
        $row = $lade();
        if (!$row) {
            throw new RuntimeException('Vormerkung konnte nicht angelegt werden.');
        }
        if ($row['token_hash'] !== $tokenHash) {
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
    } else {
        if ($row['status'] === 'confirmed' || $row['blocked_at'] !== null) {
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
        if ($row['last_mail_at'] !== null && time() - interest_ts($row['last_mail_at']) < INTEREST_RESEND_SECONDS) {
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
        $fensterAlt = interest_ts($row['mail_window_at']);
        if ($fensterAlt > 0 && time() - $fensterAlt < 86400 && (int)$row['mail_count'] >= INTEREST_MAILS_PER_DAY) {
            return ['ok' => true, 'error' => null, 'state' => 'already', 'id' => $row['id']];
        }
        // Token B bleibt über Wiederholungen hinweg stabil (Abmeldelinks älterer Mails gelten weiter).
        if ($row['manage_token_hash'] === null) {
            $pdo->prepare('UPDATE interest_registrations SET manage_token_hash = ? WHERE id = ?')->execute([hash('sha256', $manage), $row['id']]);
        } else {
            $manage = null;
        }
        $pdo->prepare(
            'UPDATE interest_registrations
                SET status = ?, name = COALESCE(?, name), company = COALESCE(?, company), source_domain = COALESCE(?, source_domain),
                    consent_text = ?, consent_at = UTC_TIMESTAMP(), token_hash = ?, token_expires_at = ?
              WHERE id = ?'
        )->execute(['pending', $v['name'], $v['company'], $domain, INTEREST_CONSENT_VERSION, $tokenHash, $expires, $row['id']]);
    }
    $id = (string)$row['id'];

    $fensterAlt = interest_ts($row['mail_window_at'] ?? null);
    $neuesFenster = $fensterAlt === 0 || time() - $fensterAlt >= 86400;
    $pdo->prepare(
        'UPDATE interest_registrations
            SET last_mail_at = UTC_TIMESTAMP(),
                mail_window_at = ' . ($neuesFenster ? 'UTC_TIMESTAMP()' : 'mail_window_at') . ',
                mail_count = ' . ($neuesFenster ? '1' : 'mail_count + 1') . '
          WHERE id = ?'
    )->execute([$id]);

    if ($manage === null) {
        // Vorhandener Token B ist nur als Hash gespeichert: Für den Abmeldelink dieser Mail wird ein neuer
        // Token B erzeugt; damit bleibt genau ein gültiger Abmeldetoken je Eintrag (der aus der jüngsten Mail).
        $manage = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE interest_registrations SET manage_token_hash = ? WHERE id = ?')->execute([hash('sha256', $manage), $id]);
    }
    $providerName = (string)($providers[$v['provider']]['name'] ?? $v['provider']);
    $confirmUrl = app_base_url() . '/vormerken.php?token=' . $token;
    $unsubscribeUrl = app_base_url() . '/vormerken.php?abmelden=' . $manage;
    $tpl = mail_tpl_interest_confirm($providerName, $confirmUrl, $unsubscribeUrl);
    if (mail_send($v['email'], $tpl['subject'], $tpl['text'], $tpl['html'])) {
        return ['ok' => true, 'error' => null, 'state' => 'mail_sent', 'id' => $id];
    }
    // Versand nicht aktiv oder gestoert: Eintrag bleibt pending und wird als wartend markiert; die Wartung sendet die
    // Bestaetigungsmail nach, sobald der Versand verfuegbar ist (interest_send_pending). Nichts geht verloren.
    $pdo->prepare('UPDATE interest_registrations SET mail_pending = 1, mail_pending_since = COALESCE(mail_pending_since, UTC_TIMESTAMP()) WHERE id = ?')->execute([$id]);
    return ['ok' => true, 'error' => null, 'state' => 'mail_deferred', 'id' => $id];
}

/** Anzahl wartender Bestaetigungsmails (Adminanzeige). */
function interest_pending_mail_count(): int
{
    try {
        return (int)db()->query("SELECT COUNT(*) FROM interest_registrations WHERE mail_pending = 1 AND status = 'pending' AND blocked_at IS NULL")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Wartung: wartende Bestaetigungsmails nachsenden, sobald der Mailversand aktiv ist. Token A und B werden neu
 * erzeugt (die alten wurden nie verschickt), die Gueltigkeit von 7 Tagen beginnt mit dem tatsaechlichen Versand.
 * @return int Anzahl nachgesendeter Mails
 */
function interest_send_pending(int $limit = 50): int
{
    if (!mail_enabled()) {
        return 0;
    }
    $pdo = db();
    $rows = $pdo->query("SELECT * FROM interest_registrations WHERE mail_pending = 1 AND status = 'pending' AND blocked_at IS NULL ORDER BY mail_pending_since ASC LIMIT " . max(1, min(500, $limit)))->fetchAll();
    $n = 0;
    foreach ($rows as $row) {
        $provider = integration_provider((string)$row['provider_code']);
        $token = bin2hex(random_bytes(32));
        $manage = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE interest_registrations SET token_hash = ?, token_expires_at = ?, manage_token_hash = ? WHERE id = ?')
            ->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + INTEREST_TOKEN_DAYS * 86400), hash('sha256', $manage), $row['id']]);
        $tpl = mail_tpl_interest_confirm(
            (string)($provider['name'] ?? $row['provider_code']),
            app_base_url() . '/vormerken.php?token=' . $token,
            app_base_url() . '/vormerken.php?abmelden=' . $manage
        );
        if (mail_send((string)$row['email'], $tpl['subject'], $tpl['text'], $tpl['html'])) {
            $pdo->prepare('UPDATE interest_registrations SET mail_pending = 0, mail_pending_since = NULL, last_mail_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
            $n++;
        }
    }
    return $n;
}

/** Zeile zu Token A (Bestätigung) oder null (auch abgelaufen). */
function interest_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM interest_registrations WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row || ($row['token_expires_at'] !== null && interest_ts($row['token_expires_at']) < time())) {
        return null;
    }
    return $row;
}

/** Zeile zu Token B (Abmeldung, freiwillige Angaben) oder null. */
function interest_by_manage_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM interest_registrations WHERE manage_token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Bestätigung über Token A: 'confirmed', 'already' oder 'invalid' (auch für abgemeldete und gesperrte Zeilen).
 * Bei Erfolg wird ein frischer Token B erzeugt (Rückgabe über $manageToken, nur der Hash wird gespeichert) und
 * die Bestätigungsmail mit Abmeldelink versendet; jede Vormerkung erhält damit immer eine Bestätigung per E-Mail.
 */
function interest_confirm(string $token, ?string &$manageToken = null): string
{
    $row = interest_by_token($token);
    if (!$row || $row['status'] === 'unsubscribed' || $row['blocked_at'] !== null) {
        return 'invalid';
    }
    if ($row['status'] === 'confirmed') {
        return 'already';
    }
    $manageToken = bin2hex(random_bytes(32));
    db()->prepare("UPDATE interest_registrations SET status = 'confirmed', confirmed_at = UTC_TIMESTAMP(), token_hash = NULL, token_expires_at = NULL, manage_token_hash = ? WHERE id = ?")
        ->execute([hash('sha256', $manageToken), $row['id']]);
    funnel_event($row['source_domain'], 'interest_confirmed', null, null, (string)$row['provider_code']);
    $provider = integration_provider((string)$row['provider_code']);
    $tpl = mail_tpl_interest_confirmed(
        (string)($provider['name'] ?? $row['provider_code']),
        app_base_url() . '/vormerken.php?abmelden=' . $manageToken,
        public_base_url() . ($row['provider_code'] === 'sevdesk' ? '/integrationen/sevdesk/' : '/integrationen/')
    );
    mail_send((string)$row['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
    return 'confirmed';
}

/** Abmeldung über Token B: 'unsubscribed' oder 'invalid'. */
function interest_unsubscribe(string $manageToken): string
{
    $row = interest_by_manage_token($manageToken);
    if (!$row) {
        return 'invalid';
    }
    interest_unsubscribe_id((string)$row['id']);
    return 'unsubscribed';
}

function interest_unsubscribe_id(string $id): bool
{
    $stmt = db()->prepare("UPDATE interest_registrations SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP(), token_hash = NULL, token_expires_at = NULL WHERE id = ? AND status <> 'unsubscribed'");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/** Sperrvermerk (Admin): kein Versand mehr, keine Reaktivierung über das Formular, Klartext außer E-Mail entfernt. */
function interest_block_id(string $id): bool
{
    $stmt = db()->prepare(
        "UPDATE interest_registrations SET status = 'unsubscribed', blocked_at = UTC_TIMESTAMP(), unsubscribed_at = COALESCE(unsubscribed_at, UTC_TIMESTAMP()),
                name = NULL, company = NULL, source_domain = NULL, token_hash = NULL, token_expires_at = NULL,
                beta_interest = 0, invoices_per_month = NULL, has_stripe = NULL, has_api_access = NULL, invited_at = NULL
          WHERE id = ? AND blocked_at IS NULL"
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function interest_delete_id(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM interest_registrations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/** Betaeinladung vormerken: nur bestätigt und nicht gesperrt (Einwilligungsstatus getrennt vom Vertriebsstand). */
function interest_invite_id(string $id): bool
{
    $stmt = db()->prepare("UPDATE interest_registrations SET invited_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'confirmed' AND blocked_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/** Freiwillige Angaben nach Bestätigung (Token B, nur bestätigte Zeilen). */
function interest_optional_update(string $manageToken, array $input): bool
{
    $row = interest_by_manage_token($manageToken);
    if (!$row || $row['status'] !== 'confirmed' || $row['blocked_at'] !== null) {
        return false;
    }
    $range = (string)($input['invoices_per_month'] ?? '');
    $range = in_array($range, INTEREST_INVOICE_RANGES, true) ? $range : null;
    $flag = static fn(string $k) => isset($input[$k]) && $input[$k] !== '' ? (int)((string)$input[$k] === '1') : null;
    db()->prepare('UPDATE interest_registrations SET beta_interest = ?, invoices_per_month = ?, has_stripe = ?, has_api_access = ? WHERE id = ?')
        ->execute([!empty($input['beta_interest']) ? 1 : 0, $range, $flag('has_stripe'), $flag('has_api_access'), $row['id']]);
    return true;
}

/** Wartung; gesperrte Einträge bleiben, damit der Sperrvermerk wirkt. */
function interest_cleanup(): int
{
    $d = (int)INTEREST_RETENTION_DAYS;
    $stmt = db()->prepare(
        "DELETE FROM interest_registrations
          WHERE blocked_at IS NULL AND (
                (status = 'pending' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $d DAY))
             OR (status = 'unsubscribed' AND unsubscribed_at IS NOT NULL AND unsubscribed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $d DAY))
             OR (status = 'confirmed' AND notified_at IS NOT NULL AND notified_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $d DAY)))"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

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

/** Getrennt ermittelte Kennzahlen je Anbieter (Masterplan, Abschnitt 8). */
function interest_metrics(string $code): array
{
    $pdo = db();
    $n = static function (string $sql, array $p = []) use ($pdo): int {
        try {
            $s = $pdo->prepare($sql);
            $s->execute($p);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };
    return [
        'submitted'  => $n("SELECT COUNT(*) FROM funnel_events WHERE event = 'interest_submitted' AND path = ?", [$code]),
        'confirmed'  => $n("SELECT COUNT(*) FROM interest_registrations WHERE provider_code = ? AND status = 'confirmed'", [$code]),
        'beta'       => $n("SELECT COUNT(*) FROM interest_registrations WHERE provider_code = ? AND status = 'confirmed' AND beta_interest = 1", [$code]),
        'invited'    => $n("SELECT COUNT(*) FROM interest_registrations WHERE provider_code = ? AND invited_at IS NOT NULL", [$code]),
        'activated'  => $n("SELECT COUNT(*) FROM interest_registrations WHERE provider_code = ? AND activated_org_id IS NOT NULL", [$code]),
        'connected'  => $n("SELECT COUNT(*) FROM integrations WHERE invoice_source = ?", [$code]),
        'collected'  => $n("SELECT COUNT(*) FROM payment_collections pc JOIN integrations i ON i.tenant_id = pc.tenant_id WHERE i.invoice_source = ? AND pc.stripe_status = 'succeeded'", [$code]),
    ];
}

/**
 * Suche und Filter für den Adminbereich. $filter: status, source, q (E-Mail, Firma, Name).
 * Zeitstempel in Ortszeit.
 */
function interest_search(array $filter, int $limit = 200): array
{
    $where = [];
    $params = [];
    $status = (string)($filter['status'] ?? '');
    if (in_array($status, ['pending', 'confirmed', 'unsubscribed', 'blocked', 'invited'], true)) {
        $where[] = ['blocked' => 'blocked_at IS NOT NULL', 'invited' => 'invited_at IS NOT NULL'][$status] ?? 'status = ?';
        if (!in_array($status, ['blocked', 'invited'], true)) {
            $params[] = $status;
        }
    }
    $source = (string)($filter['source'] ?? '');
    if ($source !== '' && preg_match('/^[a-z0-9.-]{3,100}$/', $source)) {
        $where[] = 'source_domain = ?';
        $params[] = $source;
    }
    $q = trim((string)($filter['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
        $where[] = '(email LIKE ? OR company LIKE ? OR name LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $sql = 'SELECT id, provider_code, email, name, company, source_domain, purpose, status, consent_text, consent_at, created_at, confirmed_at,
                   unsubscribed_at, blocked_at, beta_interest, invoices_per_month, has_stripe, has_api_access, invited_at, activated_org_id, notified_at
              FROM interest_registrations' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY created_at DESC LIMIT ' . max(1, min(5000, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        foreach (['consent_at', 'created_at', 'confirmed_at', 'unsubscribed_at', 'blocked_at', 'invited_at', 'notified_at'] as $k) {
            $r[$k] = interest_local($r[$k]);
        }
    }
    return $rows;
}

function interest_recent(int $limit = 200): array
{
    return interest_search([], $limit);
}

/** CSV-Zelle mit Schutz gegen Formelausführung in Tabellenprogrammen. */
function interest_csv_cell($value): string
{
    $v = str_replace(["\r", "\n"], ' ', (string)$value);
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t"], true)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/** CSV-Export (Semikolon, UTF-8 mit BOM für Tabellenprogramme). */
function interest_export_csv(array $rows): string
{
    $cols = ['provider_code', 'email', 'name', 'company', 'source_domain', 'purpose', 'status', 'consent_text', 'consent_at', 'created_at',
        'confirmed_at', 'unsubscribed_at', 'blocked_at', 'beta_interest', 'invoices_per_month', 'has_stripe', 'has_api_access', 'invited_at', 'activated_org_id'];
    $lines = [implode(';', array_map('interest_csv_cell', $cols))];
    foreach ($rows as $r) {
        $lines[] = implode(';', array_map(static fn($c) => interest_csv_cell($r[$c] ?? ''), $cols));
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}
