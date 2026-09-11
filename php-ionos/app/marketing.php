<?php
/**
 * Marketingmodul (Version 4.63): Werbe- und Informationsnachrichten an Kunden und importierte Kontakte.
 *
 * Bausteine:
 *  - Empfaengerlisten (marketing_lists, marketing_recipients): Import aus CSV mit Rechtsgrundlage je Import,
 *    Systemliste aus den Firmenaccounts (Inhaber, optional Administratoren), CSV-Export.
 *  - Sperrliste (marketing_suppressions): dauerhaft, global ueber alle Listen; gefuellt durch Abmeldung (Link und
 *    One-Click), Beschwerden und harte Ruecklaeufer aus Amazon SES sowie von Hand. Eine gesperrte Adresse erhaelt nie
 *    wieder eine Werbenachricht, auch wenn sie erneut importiert wird.
 *  - Kampagnen (marketing_campaigns, marketing_sends): Entwurf im Design der Systemmails (mail_layout), Vorschau,
 *    Testversand an eine frei gewaehlte Adresse, Freigabe des Massenversands nur nach Testversand und mit 2FA-Code,
 *    Versand als Jobtyp marketing_send (Pool mail) mit Ratenbegrenzung je Sekunde und je 24 Stunden
 *    (platform_settings marketing_rate_per_second, marketing_rate_per_day; Vorgabe 1 und 200, Werte der SES-Sandbox).
 *  - Eigenes Versandprofil 'marketing' (config('mail_marketing'), app/mailer.php): eigener Absender auf eigener
 *    Subdomain und eigener SMTP-Weg (Amazon SES), getrennt von den Systemmails ueber IONOS.
 *  - Ruecklaeufer und Beschwerden: marketing-webhook.php nimmt SNS-Benachrichtigungen von SES an (Signaturpruefung),
 *    marketing_events protokolliert sie, harte Bounces und Complaints sperren die Adresse.
 *
 * Grenzen (bewusst): Die Kunden der Firmen (Rechnungsempfaenger, Mandatsinhaber) werden im Auftrag verarbeitet und
 * sind KEINE zulaessige Quelle; die Systemliste liest nur users/organization_members. Die Bewertung der Rechtsgrundlage
 * je Import trifft der Betreiber (Feld legal_basis, Vermerk); die Anwendung erzwingt nur die Angabe und die
 * Abmeldemoeglichkeit in jeder Nachricht.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';

const MARKETING_LEGAL_BASES = [
    'bestandskunde' => 'Bestandskunde (eigene Kunden, aehnliche Leistungen)',
    'einwilligung'  => 'Einwilligung liegt vor (Nachweis beim Betreiber)',
    'b2b_kontakt'   => 'Geschaeftskontakt B2B (Bewertung durch den Betreiber)',
    'sonstiges'     => 'Sonstiges (siehe Vermerk)',
];
const MARKETING_SUPPRESSION_REASONS = ['unsubscribe' => 'Abmeldung', 'complaint' => 'Beschwerde (SES)', 'bounce' => 'Unzustellbar (SES)', 'manual' => 'von Hand gesperrt'];
/** Sperrgruende, die im Adminbereich nicht aufgehoben werden koennen (Wunsch des Empfaengers bleibt immer beruecksichtigt). */
const MARKETING_SUPPRESSION_PERMANENT = ['unsubscribe', 'complaint'];
const MARKETING_CAMPAIGN_STATUS = ['draft' => 'Entwurf', 'queued' => 'Freigegeben, wartet', 'sending' => 'Versand laeuft', 'paused' => 'Angehalten', 'sent' => 'Versendet', 'cancelled' => 'Abgebrochen'];
const MARKETING_IMPORT_MAX_ROWS = 20000;
const MARKETING_RATE_DEFAULTS = ['per_second' => 1, 'per_day' => 200];
const MARKETING_JOB_TYPE = 'marketing_send';
const MARKETING_JOB_DEDUPE = 'marketing:send';
const MARKETING_JOB_BUDGET_SECONDS = 50;

// ---------------------------------------------------------------------------------------------------------------------
// Grundfunktionen
// ---------------------------------------------------------------------------------------------------------------------

function marketing_norm_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function marketing_require(array $ctx, string $permission = 'marketing.manage'): void
{
    if (!platform_can($ctx, $permission)) {
        throw new RuntimeException('Ihre Rolle hat für diese Aktion keine Berechtigung (' . $permission . ').');
    }
}

function marketing_setting(string $key, ?string $default = null): ?string
{
    try {
        $st = db()->prepare('SELECT `value` FROM platform_settings WHERE `key` = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
    } catch (Throwable $e) {
        return $default;
    }
    return $v === false || $v === null ? $default : (string)$v;
}

function marketing_setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$key, mb_substr($value, 0, 255)]);
}

/** Ratenbegrenzung: Nachrichten je Sekunde und je 24 Stunden (Vorgabe 1 und 200). */
function marketing_rates(): array
{
    $ps = (int)marketing_setting('marketing_rate_per_second', (string)MARKETING_RATE_DEFAULTS['per_second']);
    $pd = (int)marketing_setting('marketing_rate_per_day', (string)MARKETING_RATE_DEFAULTS['per_day']);
    return ['per_second' => max(1, min(100, $ps)), 'per_day' => max(1, min(1000000, $pd))];
}

function marketing_rates_save(array $ctx, int $perSecond, int $perDay): array
{
    marketing_require($ctx);
    if ($perSecond < 1 || $perSecond > 100) {
        throw new RuntimeException('Nachrichten je Sekunde: 1 bis 100.');
    }
    if ($perDay < 1 || $perDay > 1000000) {
        throw new RuntimeException('Nachrichten je 24 Stunden: 1 bis 1.000.000.');
    }
    $alt = marketing_rates();
    marketing_setting_set('marketing_rate_per_second', (string)$perSecond);
    marketing_setting_set('marketing_rate_per_day', (string)$perDay);
    audit_log(null, $ctx, 'marketing_rates_changed', 'platform_setting', 'marketing_rate', ['vorher' => $alt, 'nachher' => ['per_second' => $perSecond, 'per_day' => $perDay]]);
    return marketing_rates();
}

/** Versendete Werbenachrichten der letzten 24 Stunden (Kampagnen und Testversand), Grundlage der Tagesgrenze. */
function marketing_sent_last_24h(): int
{
    $a = (int)db()->query("SELECT COUNT(*) FROM marketing_sends WHERE status = 'sent' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR")->fetchColumn();
    $b = (int)db()->query("SELECT COUNT(*) FROM marketing_events WHERE source = 'app' AND event_type = 'test_send' AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR")->fetchColumn();
    return $a + $b;
}

/** Konfigurationsstand des Versandprofils fuer die Anzeige (ohne Geheimnisse). */
function marketing_profile_status(): array
{
    $cfg = mail_profile_config('marketing');
    $smtp = (array)($cfg['smtp'] ?? []);
    $host = (string)($smtp['host'] ?? '');
    $platzhalter = static fn(?string $v): bool => $v === null || trim($v) === '' || str_contains((string)$v, 'HIER-');
    return [
        'enabled'      => !empty($cfg['enabled']),
        'transport'    => (string)($cfg['transport'] ?? 'smtp'),
        'from'         => (string)($cfg['from_address'] ?? ''),
        'from_name'    => (string)($cfg['from_name'] ?? ''),
        'reply_to'     => (string)($cfg['reply_to'] ?? ''),
        'smtp_host'    => $host,
        'smtp_ok'      => !$platzhalter($host) && !$platzhalter($smtp['user'] ?? null) && !$platzhalter($smtp['pass'] ?? null),
        'webhook_ok'   => !$platzhalter($cfg['webhook_token'] ?? null),
        'ses'          => str_contains($host, 'amazonaws.com'),
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// Sperrliste
// ---------------------------------------------------------------------------------------------------------------------

function marketing_is_suppressed(string $emailNorm): bool
{
    $st = db()->prepare('SELECT 1 FROM marketing_suppressions WHERE email_norm = ?');
    $st->execute([$emailNorm]);
    return (bool)$st->fetchColumn();
}

/** Adresse sperren (idempotent; ein bestehender dauerhafter Grund wird nicht durch einen schwaecheren ersetzt). */
function marketing_suppress(string $email, string $reason, ?string $note = null, ?string $campaignId = null, ?array $actor = null): bool
{
    $norm = marketing_norm_email($email);
    if ($norm === '' || !isset(MARKETING_SUPPRESSION_REASONS[$reason])) {
        return false;
    }
    $st = db()->prepare('SELECT reason FROM marketing_suppressions WHERE email_norm = ?');
    $st->execute([$norm]);
    $bestehend = $st->fetchColumn();
    if ($bestehend !== false) {
        if (in_array((string)$bestehend, MARKETING_SUPPRESSION_PERMANENT, true) || !in_array($reason, MARKETING_SUPPRESSION_PERMANENT, true)) {
            return false;
        }
        db()->prepare('UPDATE marketing_suppressions SET reason = ?, note = ?, campaign_id = COALESCE(?, campaign_id) WHERE email_norm = ?')
            ->execute([$reason, $note !== null ? mb_substr($note, 0, 255) : null, $campaignId, $norm]);
    } else {
        db()->prepare('INSERT INTO marketing_suppressions (email_norm, reason, note, campaign_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$norm, $reason, $note !== null ? mb_substr($note, 0, 255) : null, $campaignId, $actor['email'] ?? null]);
    }
    db()->prepare("UPDATE marketing_recipients SET status = 'suppressed' WHERE email_norm = ? AND status = 'active'")->execute([$norm]);
    db()->prepare("UPDATE marketing_sends SET status = 'skipped', skip_reason = 'suppressed' WHERE email_norm = ? AND status = 'queued'")->execute([$norm]);
    audit_log(null, $actor, 'marketing_suppressed', 'marketing_suppression', mail_addr_ref($norm), ['grund' => $reason, 'kampagne' => $campaignId]);
    return true;
}

function marketing_suppress_manual(array $ctx, string $email, string $note): void
{
    marketing_require($ctx);
    if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
    }
    if (!marketing_suppress($email, 'manual', trim($note) !== '' ? trim($note) : 'im Adminbereich gesperrt', null, $ctx)) {
        throw new RuntimeException('Die Adresse steht bereits auf der Sperrliste.');
    }
}

/** Sperre aufheben: nur fuer bounce und manual, nie fuer Abmeldung oder Beschwerde. */
function marketing_unsuppress(array $ctx, string $email, string $reason): void
{
    marketing_require($ctx);
    $norm = marketing_norm_email($email);
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        throw new RuntimeException('Bitte einen Grund für das Aufheben angeben (mindestens 5 Zeichen).');
    }
    $st = db()->prepare('SELECT reason FROM marketing_suppressions WHERE email_norm = ?');
    $st->execute([$norm]);
    $r = $st->fetchColumn();
    if ($r === false) {
        throw new RuntimeException('Die Adresse steht nicht auf der Sperrliste.');
    }
    if (in_array((string)$r, MARKETING_SUPPRESSION_PERMANENT, true)) {
        throw new RuntimeException('Eine Abmeldung oder Beschwerde des Empfängers wird nicht aufgehoben. Der Empfänger kann sich nur selbst erneut eintragen.');
    }
    db()->prepare('DELETE FROM marketing_suppressions WHERE email_norm = ?')->execute([$norm]);
    db()->prepare("UPDATE marketing_recipients SET status = 'active' WHERE email_norm = ? AND status = 'suppressed'")->execute([$norm]);
    audit_log(null, $ctx, 'marketing_unsuppressed', 'marketing_suppression', mail_addr_ref($norm), ['vorheriger_grund' => $r, 'grund' => mb_substr($reason, 0, 255)]);
}

function marketing_suppressions(string $q = '', int $limit = 200): array
{
    $sql = 'SELECT email_norm, reason, note, campaign_id, created_by, created_at FROM marketing_suppressions';
    $params = [];
    if (trim($q) !== '') {
        $sql .= ' WHERE email_norm LIKE ?';
        $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], marketing_norm_email($q)) . '%';
    }
    $st = db()->prepare($sql . ' ORDER BY created_at DESC LIMIT ' . max(1, min(1000, $limit)));
    $st->execute($params);
    return $st->fetchAll();
}

function marketing_suppression_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM marketing_suppressions')->fetchColumn();
}

// ---------------------------------------------------------------------------------------------------------------------
// Listen und Empfaenger
// ---------------------------------------------------------------------------------------------------------------------

function marketing_lists(): array
{
    return db()->query(
        "SELECT l.*,
                (SELECT COUNT(*) FROM marketing_recipients r WHERE r.list_id = l.id AND r.status = 'active') AS active_count,
                (SELECT COUNT(*) FROM marketing_recipients r WHERE r.list_id = l.id AND r.status = 'suppressed') AS suppressed_count,
                (SELECT COUNT(*) FROM marketing_recipients r WHERE r.list_id = l.id) AS total_count
         FROM marketing_lists l ORDER BY l.name"
    )->fetchAll();
}

function marketing_list_load(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM marketing_lists WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function marketing_list_create(array $ctx, string $name, string $description, string $source = 'import', ?string $systemScope = null): string
{
    marketing_require($ctx);
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 120) {
        throw new RuntimeException('Bitte einen Listennamen mit höchstens 120 Zeichen angeben.');
    }
    if (!in_array($source, ['import', 'system'], true)) {
        throw new RuntimeException('Unbekannte Listenart.');
    }
    if ($source === 'system' && !in_array($systemScope, ['owners', 'owners_admins'], true)) {
        throw new RuntimeException('Systemliste: Umfang Inhaber oder Inhaber und Administratoren wählen.');
    }
    $id = uuid4();
    try {
        db()->prepare('INSERT INTO marketing_lists (id, name, description, source, system_scope, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$id, $name, mb_substr(trim($description), 0, 500) ?: null, $source, $source === 'system' ? $systemScope : null, $ctx['email'] ?? null]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            throw new RuntimeException('Eine Liste mit diesem Namen besteht bereits.');
        }
        throw $e;
    }
    audit_log(null, $ctx, 'marketing_list_created', 'marketing_list', $id, ['name' => $name, 'source' => $source, 'scope' => $systemScope]);
    return $id;
}

function marketing_list_delete(array $ctx, string $id): void
{
    marketing_require($ctx);
    $l = marketing_list_load($id);
    if ($l === null) {
        throw new RuntimeException('Liste nicht gefunden.');
    }
    $aktiv = (int)db()->query("SELECT COUNT(*) FROM marketing_campaigns WHERE status IN ('queued', 'sending', 'paused') AND JSON_CONTAINS(list_ids, " . db()->quote(json_encode($id)) . ")")->fetchColumn();
    if ($aktiv > 0) {
        throw new RuntimeException('Die Liste wird von einer laufenden Kampagne verwendet.');
    }
    db()->prepare('DELETE FROM marketing_lists WHERE id = ?')->execute([$id]);
    audit_log(null, $ctx, 'marketing_list_deleted', 'marketing_list', $id, ['name' => $l['name']]);
}

/** Zeilen einer CSV-Datei in Empfaenger umsetzen. Erkennt Trennzeichen und Kopfzeile; ohne Kopfzeile gilt Spalte 1 als E-Mail. */
function marketing_csv_parse(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $csv = str_replace(["\r\n", "\r"], "\n", $csv);
    $lines = array_values(array_filter(explode("\n", $csv), static fn(string $l): bool => trim($l) !== ''));
    if ($lines === []) {
        return ['rows' => [], 'header' => null, 'delimiter' => ';'];
    }
    $first = $lines[0];
    $delims = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($delims);
    $delim = (string)array_key_first($delims);
    if ($delims[$delim] === 0) {
        $delim = ';';
    }
    $head = array_map(static fn($c) => mb_strtolower(trim((string)$c, " \"'")), str_getcsv($first, $delim, '"', '\\'));
    $map = ['email' => null, 'name' => null, 'first' => null, 'last' => null, 'company' => null];
    foreach ($head as $i => $h) {
        if ($map['email'] === null && in_array($h, ['email', 'e-mail', 'mail', 'e_mail', 'emailadresse', 'e-mail-adresse', 'email address'], true)) { $map['email'] = $i; }
        elseif ($map['name'] === null && in_array($h, ['name', 'ansprechpartner', 'kontakt', 'contact'], true)) { $map['name'] = $i; }
        elseif ($map['first'] === null && in_array($h, ['vorname', 'first_name', 'firstname', 'first name'], true)) { $map['first'] = $i; }
        elseif ($map['last'] === null && in_array($h, ['nachname', 'last_name', 'lastname', 'last name', 'familienname'], true)) { $map['last'] = $i; }
        elseif ($map['company'] === null && in_array($h, ['firma', 'company', 'unternehmen', 'organisation', 'organization', 'firmenname'], true)) { $map['company'] = $i; }
    }
    $hasHeader = $map['email'] !== null;
    if (!$hasHeader) {
        $map = ['email' => 0, 'name' => 1, 'first' => null, 'last' => null, 'company' => 2];
    }
    $rows = [];
    foreach ($lines as $i => $line) {
        if ($i === 0 && $hasHeader) {
            continue;
        }
        $cells = str_getcsv($line, $delim, '"', '\\');
        $cell = static fn(?int $idx): string => $idx !== null && isset($cells[$idx]) ? trim((string)$cells[$idx], " \"'\t") : '';
        $name = $cell($map['name']);
        if ($name === '' && ($map['first'] !== null || $map['last'] !== null)) {
            $name = trim($cell($map['first']) . ' ' . $cell($map['last']));
        }
        $rows[] = ['email' => $cell($map['email']), 'name' => $name, 'company' => $cell($map['company'])];
    }
    return ['rows' => $rows, 'header' => $hasHeader ? $head : null, 'delimiter' => $delim];
}

/**
 * CSV-Import in eine Liste. Liefert Zaehler: importiert, doppelt (bereits in der Liste), ungueltig, gesperrt (auf der
 * Sperrliste, wird mit Status suppressed aufgenommen und nie angeschrieben).
 */
function marketing_list_import(array $ctx, string $listId, string $csv, string $legalBasis, string $legalNote): array
{
    marketing_require($ctx);
    $list = marketing_list_load($listId);
    if ($list === null) {
        throw new RuntimeException('Liste nicht gefunden.');
    }
    if ($list['source'] !== 'import') {
        throw new RuntimeException('In eine Systemliste wird nicht importiert; sie wird aus den Firmenaccounts aufgebaut.');
    }
    if (!isset(MARKETING_LEGAL_BASES[$legalBasis])) {
        throw new RuntimeException('Bitte die Rechtsgrundlage des Imports auswählen.');
    }
    $legalNote = mb_substr(trim($legalNote), 0, 255);
    if ($legalBasis === 'sonstiges' && $legalNote === '') {
        throw new RuntimeException('Bei „Sonstiges“ ist ein Vermerk zur Rechtsgrundlage Pflicht.');
    }
    $parsed = marketing_csv_parse($csv);
    if (count($parsed['rows']) > MARKETING_IMPORT_MAX_ROWS) {
        throw new RuntimeException('Höchstens ' . number_format(MARKETING_IMPORT_MAX_ROWS, 0, ',', '.') . ' Zeilen je Import.');
    }
    $z = ['importiert' => 0, 'doppelt' => 0, 'ungueltig' => 0, 'gesperrt' => 0, 'zeilen' => count($parsed['rows'])];
    $ins = db()->prepare('INSERT IGNORE INTO marketing_recipients (id, list_id, email, email_norm, name, company, source, legal_basis, legal_note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
    $seen = [];
    foreach ($parsed['rows'] as $r) {
        $email = trim($r['email']);
        $norm = marketing_norm_email($email);
        if ($norm === '' || !filter_var($norm, FILTER_VALIDATE_EMAIL) || mb_strlen($norm) > 255) {
            $z['ungueltig']++;
            continue;
        }
        if (isset($seen[$norm])) {
            $z['doppelt']++;
            continue;
        }
        $seen[$norm] = true;
        $gesperrt = marketing_is_suppressed($norm);
        $ins->execute([uuid4(), $listId, mb_substr($email, 0, 255), $norm, mb_substr($r['name'], 0, 200) ?: null, mb_substr($r['company'], 0, 255) ?: null,
            'import', $legalBasis, $legalNote ?: null, $gesperrt ? 'suppressed' : 'active']);
        if ($ins->rowCount() === 0) {
            $z['doppelt']++;
        } elseif ($gesperrt) {
            $z['gesperrt']++;
        } else {
            $z['importiert']++;
        }
    }
    db()->prepare('UPDATE marketing_lists SET updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$listId]);
    audit_log(null, $ctx, 'marketing_list_imported', 'marketing_list', $listId, $z + ['rechtsgrundlage' => $legalBasis, 'vermerk' => $legalNote, 'kopfzeile' => $parsed['header'] !== null]);
    return $z;
}

/**
 * Systemliste aus den Firmenaccounts aufbauen (Inhaber, optional Administratoren aktiver Firmen). Additiv und
 * idempotent; Mitglieder, die nicht mehr bestehen, werden auf removed gesetzt. Rechtsgrundlage Bestandskunde.
 * Quelle sind ausschliesslich users und organization_members, nie die Kunden der Firmen.
 */
function marketing_list_sync_system(array $ctx, string $listId): array
{
    marketing_require($ctx);
    $list = marketing_list_load($listId);
    if ($list === null || $list['source'] !== 'system') {
        throw new RuntimeException('Keine Systemliste.');
    }
    $roles = $list['system_scope'] === 'owners_admins' ? "('owner', 'admin')" : "('owner')";
    $rows = db()->query(
        "SELECT u.id AS user_id, u.email, u.display_name, u.first_name, u.last_name, o.name AS company
         FROM organization_members m JOIN users u ON u.id = m.user_id JOIN organizations o ON o.id = m.organization_id
         WHERE m.role IN $roles AND m.status = 'active' AND u.is_active = 1 AND o.deleted_at IS NULL
         ORDER BY u.email"
    )->fetchAll();
    $z = ['neu' => 0, 'bestehend' => 0, 'gesperrt' => 0, 'entfernt' => 0];
    $ins = db()->prepare('INSERT INTO marketing_recipients (id, list_id, email, email_norm, name, company, source, legal_basis, legal_note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                          ON DUPLICATE KEY UPDATE name = VALUES(name), company = VALUES(company), status = IF(status = \'removed\', VALUES(status), status)');
    $aktuell = [];
    foreach ($rows as $r) {
        $norm = marketing_norm_email((string)$r['email']);
        if (isset($aktuell[$norm]) || !filter_var($norm, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $aktuell[$norm] = true;
        $name = trim(((string)($r['first_name'] ?? '')) . ' ' . ((string)($r['last_name'] ?? ''))) ?: (string)($r['display_name'] ?? '');
        $gesperrt = marketing_is_suppressed($norm);
        $ins->execute([uuid4(), $listId, (string)$r['email'], $norm, mb_substr($name, 0, 200) ?: null, mb_substr((string)$r['company'], 0, 255) ?: null,
            'system', 'bestandskunde', 'Firmenaccount (' . ($list['system_scope'] === 'owners_admins' ? 'Inhaber und Administratoren' : 'Inhaber') . ')', $gesperrt ? 'suppressed' : 'active']);
        $rc = $ins->rowCount(); // 1 = neu, 2 = aktualisiert, 0 = unveraendert
        if ($rc === 1) {
            $z[$gesperrt ? 'gesperrt' : 'neu']++;
        } else {
            $z['bestehend']++;
        }
    }
    $alle = db()->prepare("SELECT email_norm FROM marketing_recipients WHERE list_id = ? AND status <> 'removed'");
    $alle->execute([$listId]);
    $rm = db()->prepare("UPDATE marketing_recipients SET status = 'removed' WHERE list_id = ? AND email_norm = ?");
    foreach ($alle->fetchAll(PDO::FETCH_COLUMN) as $norm) {
        if (!isset($aktuell[$norm])) {
            $rm->execute([$listId, $norm]);
            $z['entfernt']++;
        }
    }
    db()->prepare('UPDATE marketing_lists SET updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$listId]);
    audit_log(null, $ctx, 'marketing_list_synced', 'marketing_list', $listId, $z);
    return $z;
}

function marketing_recipients(string $listId, string $q = '', int $limit = 500): array
{
    $sql = 'SELECT * FROM marketing_recipients WHERE list_id = ?';
    $params = [$listId];
    if (trim($q) !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($q)) . '%';
        $sql .= ' AND (email_norm LIKE ? OR name LIKE ? OR company LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $st = db()->prepare($sql . ' ORDER BY email_norm LIMIT ' . max(1, min(5000, $limit)));
    $st->execute($params);
    return $st->fetchAll();
}

function marketing_recipient_remove(array $ctx, string $recipientId): void
{
    marketing_require($ctx);
    $st = db()->prepare('SELECT list_id, email_norm FROM marketing_recipients WHERE id = ?');
    $st->execute([$recipientId]);
    $r = $st->fetch();
    if (!$r) {
        throw new RuntimeException('Empfänger nicht gefunden.');
    }
    db()->prepare('DELETE FROM marketing_recipients WHERE id = ?')->execute([$recipientId]);
    audit_log(null, $ctx, 'marketing_recipient_removed', 'marketing_list', (string)$r['list_id'], ['empfaenger' => mail_addr_ref((string)$r['email_norm'])]);
}

function marketing_csv_cell($value): string
{
    $v = str_replace(["\r", "\n"], ' ', (string)$value);
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t"], true)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/** CSV-Export einer Liste (Semikolon, UTF-8 mit BOM), formelsicher wie interest_export_csv(). */
function marketing_list_export_csv(array $ctx, string $listId): string
{
    marketing_require($ctx, 'marketing.view');
    $cols = ['email', 'name', 'company', 'source', 'legal_basis', 'legal_note', 'status', 'created_at'];
    $lines = [implode(';', array_map('marketing_csv_cell', $cols))];
    foreach (marketing_recipients($listId, '', 50000) as $r) {
        $lines[] = implode(';', array_map(static fn($c) => marketing_csv_cell($r[$c] ?? ''), $cols));
    }
    audit_log(null, $ctx, 'marketing_list_exported', 'marketing_list', $listId, ['rows' => count($lines) - 1]);
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

// ---------------------------------------------------------------------------------------------------------------------
// Kampagnen
// ---------------------------------------------------------------------------------------------------------------------

function marketing_campaigns(): array
{
    return db()->query('SELECT * FROM marketing_campaigns ORDER BY created_at DESC')->fetchAll();
}

function marketing_campaign_load(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM marketing_campaigns WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $r['list_ids_arr'] = array_values(array_filter(array_map('strval', (array)json_decode((string)$r['list_ids'], true))));
    return $r;
}

/** Entwurf anlegen oder aendern (nur im Status draft). */
function marketing_campaign_save(array $ctx, ?string $id, array $in): string
{
    marketing_require($ctx);
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')) ?? '');
    $subject = trim(preg_replace('/\s+/', ' ', (string)($in['subject'] ?? '')) ?? '');
    $title = trim(preg_replace('/\s+/', ' ', (string)($in['title'] ?? '')) ?? '');
    $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($in['body_text'] ?? '')));
    $buttonLabel = mb_substr(trim((string)($in['button_label'] ?? '')), 0, 80);
    $buttonUrl = trim((string)($in['button_url'] ?? ''));
    $footer = mb_substr(trim((string)($in['footer_note'] ?? '')), 0, 500);
    $lists = array_values(array_unique(array_filter(array_map('strval', (array)($in['list_ids'] ?? [])))));
    if ($name === '' || mb_strlen($name) > 120) {
        throw new RuntimeException('Bitte einen internen Namen der Kampagne angeben (höchstens 120 Zeichen).');
    }
    if ($subject === '' || mb_strlen($subject) > 200) {
        throw new RuntimeException('Bitte einen Betreff angeben (höchstens 200 Zeichen).');
    }
    if ($title === '' || mb_strlen($title) > 200) {
        throw new RuntimeException('Bitte eine Überschrift angeben (höchstens 200 Zeichen).');
    }
    if ($body === '' || mb_strlen($body) > 20000) {
        throw new RuntimeException('Bitte den Text der Nachricht angeben (höchstens 20.000 Zeichen, Absätze durch Leerzeilen).');
    }
    if (str_contains($body, '<') && preg_match('/<\s*[a-z!\/]/i', $body)) {
        throw new RuntimeException('HTML ist im Text nicht erlaubt; die Gestaltung übernimmt die zentrale Vorlage.');
    }
    if (($buttonLabel === '') !== ($buttonUrl === '')) {
        throw new RuntimeException('Schaltfläche: Beschriftung und Adresse gehören zusammen (beides ausfüllen oder beides leer lassen).');
    }
    if ($buttonUrl !== '' && (!preg_match('~^https://[^\s<>"]+$~', $buttonUrl) || mb_strlen($buttonUrl) > 500)) {
        throw new RuntimeException('Die Adresse der Schaltfläche muss mit https:// beginnen.');
    }
    if ($lists === []) {
        throw new RuntimeException('Bitte mindestens eine Empfängerliste auswählen.');
    }
    foreach ($lists as $lid) {
        if (marketing_list_load($lid) === null) {
            throw new RuntimeException('Eine gewählte Liste besteht nicht mehr.');
        }
    }
    foreach ([$subject, $title, $body, $footer, $buttonLabel] as $t) {
        if (str_contains($t, "\u{2014}")) {
            throw new RuntimeException('Bitte keine Gedankenstriche verwenden (Hausregel), stattdessen Komma oder Punkt.');
        }
    }
    $listsJson = json_encode($lists);
    if ($id === null || $id === '') {
        $id = uuid4();
        db()->prepare('INSERT INTO marketing_campaigns (id, name, subject, title, body_text, button_label, button_url, footer_note, list_ids, status, created_by, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$id, $name, $subject, $title, $body, $buttonLabel ?: null, $buttonUrl ?: null, $footer ?: null, $listsJson, $ctx['email'] ?? null]);
        audit_log(null, $ctx, 'marketing_campaign_created', 'marketing_campaign', $id, ['name' => $name, 'listen' => $lists]);
        return $id;
    }
    $c = marketing_campaign_load($id);
    if ($c === null) {
        throw new RuntimeException('Kampagne nicht gefunden.');
    }
    if ($c['status'] !== 'draft') {
        throw new RuntimeException('Nur Entwürfe sind änderbar. Eine freigegebene Kampagne wird abgebrochen und als neue Kampagne angelegt.');
    }
    $inhaltGeaendert = $c['subject'] !== $subject || $c['title'] !== $title || $c['body_text'] !== $body || (string)$c['button_label'] !== $buttonLabel
        || (string)$c['button_url'] !== $buttonUrl || (string)$c['footer_note'] !== $footer || (string)$c['list_ids'] !== $listsJson;
    db()->prepare('UPDATE marketing_campaigns SET name = ?, subject = ?, title = ?, body_text = ?, button_label = ?, button_url = ?, footer_note = ?, list_ids = ?,
                   test_sent_at = IF(?, NULL, test_sent_at), test_sent_ref = IF(?, NULL, test_sent_ref), updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$name, $subject, $title, $body, $buttonLabel ?: null, $buttonUrl ?: null, $footer ?: null, $listsJson, $inhaltGeaendert ? 1 : 0, $inhaltGeaendert ? 1 : 0, $id]);
    audit_log(null, $ctx, 'marketing_campaign_updated', 'marketing_campaign', $id, ['name' => $name, 'inhalt_geaendert' => $inhaltGeaendert, 'listen' => $lists]);
    return $id;
}

function marketing_campaign_delete(array $ctx, string $id): void
{
    marketing_require($ctx);
    $c = marketing_campaign_load($id);
    if ($c === null) {
        throw new RuntimeException('Kampagne nicht gefunden.');
    }
    if (!in_array($c['status'], ['draft', 'cancelled'], true)) {
        throw new RuntimeException('Nur Entwürfe und abgebrochene Kampagnen werden gelöscht; versendete Kampagnen bleiben als Nachweis.');
    }
    db()->prepare('DELETE FROM marketing_campaigns WHERE id = ?')->execute([$id]);
    audit_log(null, $ctx, 'marketing_campaign_deleted', 'marketing_campaign', $id, ['name' => $c['name'], 'status' => $c['status']]);
}

/** Platzhalter {{name}} und {{firma}} ersetzen; Leerwerte entfernen ueberfluessige Leerzeichen vor Satzzeichen. */
function marketing_personalize(string $text, ?string $name, ?string $company): string
{
    $t = str_replace(['{{name}}', '{{firma}}'], [trim((string)$name), trim((string)$company)], $text);
    $t = preg_replace('/[ \t]+([,.;:!?])/', '$1', $t) ?? $t;
    return preg_replace('/ {2,}/', ' ', $t) ?? $t;
}

/**
 * Nachricht einer Kampagne fuer einen Empfaenger erzeugen (mail_layout, Zweitlink Abmelden, Hinweis auf den Grund der
 * Zusendung im Fusstext). Liefert subject, text, html.
 */
function marketing_campaign_render(array $c, ?string $name, ?string $company, string $unsubscribeUrl): array
{
    $subject = marketing_personalize((string)$c['subject'], $name, $company);
    $title = marketing_personalize((string)$c['title'], $name, $company);
    $paragraphs = array_values(array_filter(array_map(static fn(string $p): string => trim(preg_replace('/\s*\n\s*/', ' ', $p) ?? $p),
        preg_split('/\n\s*\n/', (string)$c['body_text']) ?: []), static fn(string $p): bool => $p !== ''));
    $paragraphs = array_map(static fn(string $p): string => marketing_personalize($p, $name, $company), $paragraphs);
    $button = !empty($c['button_label']) && !empty($c['button_url']) ? ['label' => (string)$c['button_label'], 'url' => (string)$c['button_url']] : null;
    $footer = trim((string)($c['footer_note'] ?? ''));
    $footer = ($footer !== '' ? marketing_personalize($footer, $name, $company) . ' ' : '')
        . 'Sie erhalten diese Nachricht, weil Sie Kunde von ' . mail_product_name() . ' sind oder uns Ihre Kontaktdaten geschäftlich vorliegen. '
        . 'Über den Link „Keine weiteren Nachrichten“ beenden Sie den Empfang jederzeit; die Abmeldung wird dauerhaft berücksichtigt.';
    $layout = mail_layout($title, $paragraphs, $button, $footer, ['label' => 'Keine weiteren Nachrichten', 'url' => $unsubscribeUrl]);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/** Vorschau mit Musterdaten (Abmeldelink ohne gueltigen Token). */
function marketing_campaign_preview(array $c): array
{
    return marketing_campaign_render($c, 'Erika Muster', 'Muster GmbH', app_base_url() . '/abmelden.php?t=VORSCHAU');
}

/** Testversand an eine frei gewaehlte Adresse ueber das Marketingprofil, Betreff mit Vorsatz TEST. */
function marketing_campaign_test_send(array $ctx, string $id, string $to): void
{
    marketing_require($ctx);
    $c = marketing_campaign_load($id);
    if ($c === null) {
        throw new RuntimeException('Kampagne nicht gefunden.');
    }
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Bitte eine gültige Empfängeradresse für den Test angeben.');
    }
    if (!mail_profile_enabled('marketing')) {
        throw new RuntimeException('Das Versandprofil mail_marketing ist nicht aktiv (shared/config.php).');
    }
    $rates = marketing_rates();
    if (marketing_sent_last_24h() >= $rates['per_day']) {
        throw new RuntimeException('Tagesgrenze erreicht (' . $rates['per_day'] . ' Nachrichten je 24 Stunden). Testversand später wiederholen oder Grenze anpassen.');
    }
    $m = marketing_campaign_render($c, 'Erika Muster', 'Muster GmbH', app_base_url() . '/abmelden.php?t=TEST');
    $ok = mail_send_direct($to, 'TEST: ' . $m['subject'], $m['text'], $m['html'], ['profile' => 'marketing', 'unsubscribe_url' => app_base_url() . '/abmelden.php?t=TEST']);
    if (!$ok) {
        $err = mail_last_error();
        throw new RuntimeException('Testversand fehlgeschlagen: ' . ($err['text'] ?? 'Versandweg hat die Nachricht nicht angenommen') . '.');
    }
    db()->prepare('INSERT INTO marketing_events (source, event_type, email_norm, message_id, details_json, created_at) VALUES (\'app\', \'test_send\', ?, NULL, ?, UTC_TIMESTAMP())')
        ->execute([marketing_norm_email($to), json_encode(['campaign_id' => $id, 'by' => $ctx['email'] ?? null])]);
    db()->prepare('UPDATE marketing_campaigns SET test_sent_at = UTC_TIMESTAMP(), test_sent_ref = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([mail_addr_ref($to), $id]);
    audit_log(null, $ctx, 'marketing_campaign_test_sent', 'marketing_campaign', $id, ['an' => mail_addr_ref($to)]);
}

/**
 * Massenversand freigeben: nur nach Testversand, mit aktivem Profil. Baut die Versandzeilen (eine je Adresse ueber alle
 * Listen, gesperrte Adressen als skipped) und reiht den Job ein. Die Zweitbestaetigung per 2FA-Code prueft die Seite.
 */
function marketing_campaign_start(array $ctx, string $id): array
{
    marketing_require($ctx);
    $c = marketing_campaign_load($id);
    if ($c === null) {
        throw new RuntimeException('Kampagne nicht gefunden.');
    }
    if ($c['status'] !== 'draft') {
        throw new RuntimeException('Die Kampagne ist bereits freigegeben oder beendet.');
    }
    if (empty($c['test_sent_at'])) {
        throw new RuntimeException('Bitte zuerst einen Testversand an eine eigene Adresse durchführen und die Nachricht prüfen.');
    }
    if (!mail_profile_enabled('marketing')) {
        throw new RuntimeException('Das Versandprofil mail_marketing ist nicht aktiv (shared/config.php).');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $claimed = $pdo->prepare("UPDATE marketing_campaigns SET status = 'queued', started_by = ?, started_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'draft'");
        $claimed->execute([$ctx['email'] ?? null, $id]);
        if ($claimed->rowCount() !== 1) {
            throw new RuntimeException('Die Kampagne wurde gerade von jemand anderem freigegeben.');
        }
        $ph = implode(',', array_fill(0, count($c['list_ids_arr']), '?'));
        $st = $pdo->prepare("SELECT r.id, r.email, r.email_norm, r.name, r.company, r.status FROM marketing_recipients r WHERE r.list_id IN ($ph) AND r.status IN ('active', 'suppressed') ORDER BY r.email_norm");
        $st->execute($c['list_ids_arr']);
        $ins = $pdo->prepare('INSERT IGNORE INTO marketing_sends (id, campaign_id, recipient_id, email, email_norm, name, company, status, skip_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $z = ['total' => 0, 'skipped' => 0];
        foreach ($st->fetchAll() as $r) {
            $skip = $r['status'] === 'suppressed' || marketing_is_suppressed((string)$r['email_norm']);
            $ins->execute([uuid4(), $id, $r['id'], $r['email'], $r['email_norm'], $r['name'], $r['company'], $skip ? 'skipped' : 'queued', $skip ? 'suppressed' : null]);
            if ($ins->rowCount() === 1) {
                $z['total']++;
                if ($skip) {
                    $z['skipped']++;
                }
            }
        }
        $pdo->prepare('UPDATE marketing_campaigns SET total_count = ?, skipped_count = ? WHERE id = ?')->execute([$z['total'], $z['skipped'], $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_log(null, $ctx, 'marketing_campaign_started', 'marketing_campaign', $id, $z + ['name' => $c['name']]);
    marketing_enqueue_job('high');
    return $z;
}

/** Versandjob einreihen (ein Job fuer alle Kampagnen, dedupe). Ohne Warteschlange (Webhosting) verarbeitet cron.php inline. */
function marketing_enqueue_job(string $priority = 'low'): void
{
    require_once __DIR__ . '/queue.php';
    if (!queue_available()) {
        return;
    }
    try {
        queue_push(MARKETING_JOB_TYPE, [], ['priority' => $priority, 'dedupe_key' => MARKETING_JOB_DEDUPE]);
    } catch (Throwable $e) {
        app_log('warning', 'Marketing-Versandjob konnte nicht eingereiht werden', ['error' => $e->getMessage()]);
    }
}

function marketing_campaign_set_status(array $ctx, string $id, string $to): void
{
    marketing_require($ctx);
    $c = marketing_campaign_load($id);
    if ($c === null) {
        throw new RuntimeException('Kampagne nicht gefunden.');
    }
    $erlaubt = [
        'paused'    => ['queued', 'sending'],
        'queued'    => ['paused'],
        'cancelled' => ['queued', 'sending', 'paused'],
    ];
    if (!isset($erlaubt[$to]) || !in_array($c['status'], $erlaubt[$to], true)) {
        throw new RuntimeException('Dieser Wechsel ist im Status „' . (MARKETING_CAMPAIGN_STATUS[$c['status']] ?? $c['status']) . '“ nicht möglich.');
    }
    db()->prepare('UPDATE marketing_campaigns SET status = ?, updated_at = UTC_TIMESTAMP(), finished_at = IF(? = \'cancelled\', UTC_TIMESTAMP(), finished_at) WHERE id = ?')->execute([$to, $to, $id]);
    if ($to === 'cancelled') {
        db()->prepare("UPDATE marketing_sends SET status = 'skipped', skip_reason = 'cancelled' WHERE campaign_id = ? AND status IN ('queued', 'sending')")->execute([$id]);
        marketing_campaign_refresh_counts($id);
    }
    audit_log(null, $ctx, 'marketing_campaign_' . $to, 'marketing_campaign', $id, ['von' => $c['status']]);
    if ($to === 'queued') {
        marketing_enqueue_job('high');
    }
}

function marketing_campaign_refresh_counts(string $id): void
{
    db()->prepare("UPDATE marketing_campaigns c SET
        sent_count = (SELECT COUNT(*) FROM marketing_sends s WHERE s.campaign_id = c.id AND s.status = 'sent'),
        failed_count = (SELECT COUNT(*) FROM marketing_sends s WHERE s.campaign_id = c.id AND s.status = 'failed'),
        skipped_count = (SELECT COUNT(*) FROM marketing_sends s WHERE s.campaign_id = c.id AND s.status = 'skipped'),
        updated_at = UTC_TIMESTAMP() WHERE c.id = ?")->execute([$id]);
}

function marketing_campaign_sends(string $id, ?string $status = null, int $limit = 200): array
{
    $sql = 'SELECT id, email, name, company, status, skip_reason, error, sent_at, created_at FROM marketing_sends WHERE campaign_id = ?';
    $params = [$id];
    if ($status !== null) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $st = db()->prepare($sql . ' ORDER BY sent_at DESC, email_norm LIMIT ' . max(1, min(5000, $limit)));
    $st->execute($params);
    return $st->fetchAll();
}

/** Stehen Kampagnen zum Versand an (queued oder sending)? Grundlage fuer Scheduler und cron.php. */
function marketing_campaigns_pending(): bool
{
    try {
        return (int)db()->query("SELECT COUNT(*) FROM marketing_campaigns WHERE status IN ('queued', 'sending')")->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Versand abarbeiten: naechste Kampagne (sending vor queued), Adressen einzeln beanspruchen, mit Ratenbegrenzung
 * senden, Zaehler pflegen. Liefert Statistik; 'daily_limit' => true, wenn die Tagesgrenze den Lauf beendet hat,
 * 'remaining' => offene Adressen ueber alle Kampagnen. $tick wird nach jeder Nachricht aufgerufen (Heartbeat, Abbruch:
 * liefert true, wenn der Lauf enden soll).
 */
function marketing_send_process(float $budgetSeconds, ?callable $tick = null): array
{
    $start = microtime(true);
    $rates = marketing_rates();
    $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'daily_limit' => false, 'stopped' => false, 'remaining' => 0, 'campaigns' => []];
    $sentToday = marketing_sent_last_24h();
    $lastSend = 0.0;
    $minInterval = 1.0 / $rates['per_second'];
    $pdo = db();
    while (true) {
        if (!mail_profile_enabled('marketing')) {
            break;
        }
        $c = $pdo->query("SELECT * FROM marketing_campaigns WHERE status IN ('sending', 'queued') ORDER BY FIELD(status, 'sending', 'queued'), started_at LIMIT 1")->fetch();
        if (!$c) {
            break;
        }
        if ($c['status'] === 'queued') {
            $pdo->prepare("UPDATE marketing_campaigns SET status = 'sending', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'queued'")->execute([$c['id']]);
        }
        $stats['campaigns'][$c['id']] = $c['name'];
        $fertig = false;
        while (true) {
            if (microtime(true) - $start > $budgetSeconds) {
                $stats['remaining'] = marketing_sends_remaining();
                marketing_campaign_refresh_counts((string)$c['id']);
                return $stats;
            }
            if ($sentToday >= $rates['per_day']) {
                $stats['daily_limit'] = true;
                $stats['remaining'] = marketing_sends_remaining();
                marketing_campaign_refresh_counts((string)$c['id']);
                return $stats;
            }
            // Kampagne zwischenzeitlich angehalten oder abgebrochen?
            $status = (string)$pdo->query('SELECT status FROM marketing_campaigns WHERE id = ' . $pdo->quote((string)$c['id']))->fetchColumn();
            if ($status !== 'sending') {
                break;
            }
            $row = $pdo->prepare("SELECT * FROM marketing_sends WHERE campaign_id = ? AND status = 'queued' ORDER BY email_norm LIMIT 1");
            $row->execute([$c['id']]);
            $s = $row->fetch();
            if (!$s) {
                $fertig = true;
                break;
            }
            $claim = $pdo->prepare("UPDATE marketing_sends SET status = 'sending', claimed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'queued'");
            $claim->execute([$s['id']]);
            if ($claim->rowCount() !== 1) {
                continue; // anderer Prozess war schneller
            }
            if (marketing_is_suppressed((string)$s['email_norm'])) {
                $pdo->prepare("UPDATE marketing_sends SET status = 'skipped', skip_reason = 'suppressed' WHERE id = ?")->execute([$s['id']]);
                $stats['skipped']++;
                continue;
            }
            // Ratenbegrenzung je Sekunde: Abstand zwischen zwei Uebergaben
            $wait = $minInterval - (microtime(true) - $lastSend);
            if ($lastSend > 0 && $wait > 0) {
                usleep((int)ceil($wait * 1000000));
            }
            $token = bin2hex(random_bytes(24));
            $pdo->prepare('UPDATE marketing_sends SET unsubscribe_token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $s['id']]);
            $url = app_base_url() . '/abmelden.php?t=' . $token;
            $m = marketing_campaign_render($c, $s['name'], $s['company'], $url);
            $ok = mail_send_direct((string)$s['email'], $m['subject'], $m['text'], $m['html'], ['profile' => 'marketing', 'unsubscribe_url' => $url]);
            $lastSend = microtime(true);
            if ($ok) {
                $pdo->prepare("UPDATE marketing_sends SET status = 'sent', sent_at = UTC_TIMESTAMP(), error = NULL WHERE id = ?")->execute([$s['id']]);
                $stats['sent']++;
                $sentToday++;
            } else {
                $err = mail_last_error();
                $kind = (string)($err['kind'] ?? 'transport');
                $text = mb_substr((string)($err['text'] ?? 'Versandweg hat die Nachricht nicht angenommen'), 0, 255);
                if ($kind === 'rejected') {
                    $pdo->prepare("UPDATE marketing_sends SET status = 'failed', error = ? WHERE id = ?")->execute([$text, $s['id']]);
                    $stats['failed']++;
                } else {
                    // Transportproblem: Adresse zurueckstellen, Lauf beenden, Fortsetzung mit Abstand ueber den Scheduler
                    $pdo->prepare("UPDATE marketing_sends SET status = 'queued', unsubscribe_token_hash = NULL, error = ? WHERE id = ?")->execute([$text, $s['id']]);
                    marketing_campaign_refresh_counts((string)$c['id']);
                    $stats['remaining'] = marketing_sends_remaining();
                    $stats['transport_error'] = $text;
                    return $stats;
                }
            }
            if ($tick !== null && $tick($stats)) {
                $stats['stopped'] = true;
                $stats['remaining'] = marketing_sends_remaining();
                marketing_campaign_refresh_counts((string)$c['id']);
                return $stats;
            }
        }
        marketing_campaign_refresh_counts((string)$c['id']);
        if ($fertig) {
            $pdo->prepare("UPDATE marketing_campaigns SET status = 'sent', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'sending'")->execute([$c['id']]);
            audit_log(null, null, 'marketing_campaign_sent', 'marketing_campaign', (string)$c['id'], ['name' => $c['name']]);
        }
    }
    $stats['remaining'] = marketing_sends_remaining();
    return $stats;
}

function marketing_sends_remaining(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM marketing_sends s JOIN marketing_campaigns c ON c.id = s.campaign_id WHERE s.status = 'queued' AND c.status IN ('queued', 'sending')")->fetchColumn();
}

// ---------------------------------------------------------------------------------------------------------------------
// Abmeldung (abmelden.php)
// ---------------------------------------------------------------------------------------------------------------------

/** Versandzeile zu einem Abmeldetoken; null, wenn unbekannt. */
function marketing_send_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT s.id, s.email, s.email_norm, s.campaign_id, c.name AS campaign_name FROM marketing_sends s JOIN marketing_campaigns c ON c.id = s.campaign_id WHERE s.unsubscribe_token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Abmeldung ueber Token: sperrt die Adresse dauerhaft. Liefert 'unsubscribed', 'already' oder 'invalid'. */
function marketing_unsubscribe(string $token, string $weg = 'link'): string
{
    $s = marketing_send_by_token($token);
    if ($s === null) {
        return 'invalid';
    }
    if (marketing_is_suppressed((string)$s['email_norm'])) {
        return 'already';
    }
    marketing_suppress((string)$s['email'], 'unsubscribe', 'Abmeldung per ' . $weg, (string)$s['campaign_id'], null);
    db()->prepare('INSERT INTO marketing_events (source, event_type, email_norm, message_id, details_json, created_at) VALUES (\'app\', \'unsubscribe\', ?, NULL, ?, UTC_TIMESTAMP())')
        ->execute([(string)$s['email_norm'], json_encode(['campaign_id' => $s['campaign_id'], 'weg' => $weg])]);
    return 'unsubscribed';
}

/** One-Click-Abmeldung (RFC 8058): POST mit Feld List-Unsubscribe=One-Click. */
function marketing_is_one_click(string $method, array $post): bool
{
    return strtoupper($method) === 'POST' && (string)($post['List-Unsubscribe'] ?? '') === 'One-Click';
}

// ---------------------------------------------------------------------------------------------------------------------
// Amazon SES ueber SNS: Ruecklaeufer und Beschwerden (marketing-webhook.php)
// ---------------------------------------------------------------------------------------------------------------------

/** Zertifikat einer SNS-Nachricht laden: nur https und Host sns.<region>.amazonaws.com, zwischengespeichert. */
function marketing_sns_certificate(string $url): ?string
{
    if (isset($GLOBALS['marketing_sns_cert_fetcher']) && is_callable($GLOBALS['marketing_sns_cert_fetcher'])) {
        return ($GLOBALS['marketing_sns_cert_fetcher'])($url);
    }
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || !preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', (string)($p['host'] ?? '')) || !str_ends_with((string)($p['path'] ?? ''), '.pem')) {
        return null;
    }
    $dir = storage_dir() . '/sns-certs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $file = $dir . '/' . sha1($url) . '.pem';
    if (is_file($file) && filemtime($file) > time() - 86400 * 7) {
        return (string)file_get_contents($file);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false]);
    $pem = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($pem) || $code !== 200 || !str_contains($pem, 'BEGIN CERTIFICATE')) {
        return null;
    }
    @file_put_contents($file, $pem, LOCK_EX);
    return $pem;
}

/** Zu signierende Zeichenkette einer SNS-Nachricht (Felder in fester Reihenfolge, je Name und Wert eine Zeile). */
function marketing_sns_string_to_sign(array $msg): ?string
{
    $type = (string)($msg['Type'] ?? '');
    if ($type === 'Notification') {
        $felder = isset($msg['Subject']) ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'] : ['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type'];
    } elseif ($type === 'SubscriptionConfirmation' || $type === 'UnsubscribeConfirmation') {
        $felder = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
    } else {
        return null;
    }
    $s = '';
    foreach ($felder as $f) {
        if (!array_key_exists($f, $msg)) {
            return null;
        }
        $s .= $f . "\n" . (string)$msg[$f] . "\n";
    }
    return $s;
}

/** Signatur einer SNS-Nachricht pruefen (SignatureVersion 1 = SHA1withRSA, 2 = SHA256withRSA). */
function marketing_sns_verify(array $msg): bool
{
    $sts = marketing_sns_string_to_sign($msg);
    if ($sts === null || empty($msg['Signature']) || empty($msg['SigningCertURL'])) {
        return false;
    }
    $pem = marketing_sns_certificate((string)$msg['SigningCertURL']);
    if ($pem === null) {
        return false;
    }
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        return false;
    }
    $sig = base64_decode((string)$msg['Signature'], true);
    if ($sig === false) {
        return false;
    }
    $algo = (string)($msg['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
    return openssl_verify($sts, $sig, $key, $algo) === 1;
}

/**
 * SES-Ereignis (Inhalt von Message) auswerten: harte Ruecklaeufer und Beschwerden sperren die Adresse, alles andere wird
 * nur protokolliert. Liefert ['type' => ..., 'suppressed' => [...]].
 */
function marketing_ses_handle(array $ev): array
{
    $type = (string)($ev['notificationType'] ?? $ev['eventType'] ?? '');
    $mail = (array)($ev['mail'] ?? []);
    $messageId = mb_substr((string)($mail['messageId'] ?? ''), 0, 120);
    $out = ['type' => $type, 'suppressed' => [], 'logged' => 0];
    $log = db()->prepare('INSERT INTO marketing_events (source, event_type, email_norm, message_id, details_json, created_at) VALUES (\'ses\', ?, ?, ?, ?, UTC_TIMESTAMP())');
    if ($type === 'Bounce') {
        $b = (array)($ev['bounce'] ?? []);
        $hart = (string)($b['bounceType'] ?? '') === 'Permanent';
        foreach ((array)($b['bouncedRecipients'] ?? []) as $r) {
            $email = (string)($r['emailAddress'] ?? '');
            $norm = marketing_norm_email($email);
            $log->execute([$hart ? 'bounce' : 'bounce_transient', $norm ?: null, $messageId ?: null, json_encode(['bounceType' => $b['bounceType'] ?? null, 'subType' => $b['bounceSubType'] ?? null, 'status' => $r['status'] ?? null, 'diagnostic' => mb_substr((string)($r['diagnosticCode'] ?? ''), 0, 200)])]);
            $out['logged']++;
            if ($hart && $norm !== '') {
                marketing_suppress($email, 'bounce', 'SES ' . (string)($b['bounceSubType'] ?? 'Permanent'), null, null);
                $out['suppressed'][] = $norm;
            }
        }
    } elseif ($type === 'Complaint') {
        $c = (array)($ev['complaint'] ?? []);
        foreach ((array)($c['complainedRecipients'] ?? []) as $r) {
            $email = (string)($r['emailAddress'] ?? '');
            $norm = marketing_norm_email($email);
            $log->execute(['complaint', $norm ?: null, $messageId ?: null, json_encode(['feedbackType' => $c['complaintFeedbackType'] ?? null])]);
            $out['logged']++;
            if ($norm !== '') {
                marketing_suppress($email, 'complaint', 'SES Beschwerde ' . (string)($c['complaintFeedbackType'] ?? ''), null, null);
                $out['suppressed'][] = $norm;
            }
        }
    } else {
        $dest = (array)($mail['destination'] ?? []);
        $log->execute([mb_substr($type !== '' ? strtolower($type) : 'unknown', 0, 40), isset($dest[0]) ? marketing_norm_email((string)$dest[0]) : null, $messageId ?: null, json_encode(['keys' => array_keys($ev)])]);
        $out['logged']++;
    }
    return $out;
}

function marketing_events_recent(int $limit = 100): array
{
    return db()->query('SELECT * FROM marketing_events ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)))->fetchAll();
}
