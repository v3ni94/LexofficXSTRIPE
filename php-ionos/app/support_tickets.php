<?php
/**
 * Support-Anfragen (Tickets) aus dem Hilfe-Center.
 *
 * Eine Firma stellt eine Anfrage (Betreff, Text, Kategorie, aufrufende Seite);
 * der Betreiber antwortet im Adminbereich (admin-support.php). Jede Nachricht
 * landet im Verlauf, Kunde und Betreiber werden per E-Mail informiert (sofern
 * der Mailversand eingerichtet ist). Mandantentrennung in jeder Abfrage über
 * tenant_id. Keine Zugangsdaten oder IBANs in Tickets speichern: der Text wird
 * auf offensichtliche Schlüssel (sk_live_, sk_test_, whsec_) geprüft.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

const TICKET_CATEGORIES = [
    'allgemein'   => 'Allgemeine Frage',
    'einrichtung' => 'Einrichtung (Lexware, Stripe, Webhook)',
    'einzug'      => 'Einzug, Mandat, Rücklastschrift',
    'abo'         => 'Abonnement und Rechnung',
    'fehler'      => 'Fehlermeldung',
    'wunsch'      => 'Verbesserungsvorschlag',
];

const TICKET_STATUS_LABEL = ['open' => 'Offen', 'answered' => 'Beantwortet', 'closed' => 'Geschlossen'];

/** Betreiberadresse für Ticket-Benachrichtigungen (support_email, sonst operator.email). */
function ticket_support_address(): string
{
    $addr = trim((string)config('support_email', ''));
    if ($addr === '') {
        $addr = trim((string)((array)config('operator', []))['email'] ?? '');
    }
    return $addr;
}

/** Text auf offensichtliche Zugangsdaten prüfen (werden nie gespeichert). */
function ticket_reject_secrets(string $text): void
{
    if (preg_match('/\b(sk|rk)_(live|test)_[A-Za-z0-9]{8,}|\bwhsec_[A-Za-z0-9]{8,}|\b[A-Za-z0-9]{8}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{12}\b(?=.*(api|schl))/i', $text)) {
        throw new RuntimeException('Bitte keine API-Schlüssel oder Secrets in die Anfrage schreiben. Der Support benötigt sie nicht.');
    }
    if (preg_match('/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}\s?[A-Z0-9]{1,4}\b/', $text) && preg_match('/\bDE\d{2}\s?\d{4}\s?\d{4}\s?\d{4}\s?\d{4}\s?\d{2}\b/', $text)) {
        throw new RuntimeException('Bitte keine vollständige IBAN in die Anfrage schreiben. Nennen Sie stattdessen Kundennummer oder Rechnungsnummer.');
    }
}

/** Neue Anfrage anlegen; liefert das Ticket. */
function ticket_create(array $ctx, string $subject, string $body, string $category, ?string $page): array
{
    $subject = trim(preg_replace('/\s+/', ' ', $subject));
    $body = trim($body);
    if (mb_strlen($subject) < 5 || mb_strlen($subject) > 160) {
        throw new RuntimeException('Bitte einen Betreff mit 5 bis 160 Zeichen angeben.');
    }
    if (mb_strlen($body) < 20 || mb_strlen($body) > 5000) {
        throw new RuntimeException('Bitte die Frage mit mindestens 20 und höchstens 5.000 Zeichen beschreiben.');
    }
    if (!array_key_exists($category, TICKET_CATEGORIES)) {
        $category = 'allgemein';
    }
    ticket_reject_secrets($subject . ' ' . $body);
    $pdo = db();
    // Schutz vor Massenanfragen: höchstens 10 offene Anfragen je Firma
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE tenant_id = ? AND status <> 'closed'");
    $stmt->execute([$ctx['org_id']]);
    if ((int)$stmt->fetchColumn() >= 10) {
        throw new RuntimeException('Es sind bereits 10 Anfragen offen. Bitte warten Sie auf die Antwort oder ergänzen Sie eine bestehende Anfrage.');
    }
    $id = uuid4();
    $pdo->prepare(
        'INSERT INTO support_tickets (id, tenant_id, user_id, user_email, subject, category, page, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'open\')'
    )->execute([$id, $ctx['org_id'], $ctx['user_id'], $ctx['email'], $subject, $category, $page !== null ? mb_substr($page, 0, 120) : null]);
    ticket_add_message($id, (string)$ctx['org_id'], 'customer', (string)$ctx['email'], $body);
    audit_log($ctx['org_id'], $ctx, 'support_ticket_created', 'support_ticket', $id, ['betreff' => $subject, 'kategorie' => $category]);
    ticket_notify_support($id, $ctx, $subject, $body, true);
    return ticket_load_for_tenant((string)$ctx['org_id'], $id) ?? ['id' => $id];
}

function ticket_add_message(string $ticketId, string $tenantId, string $authorType, ?string $authorEmail, string $body): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO support_ticket_messages (id, ticket_id, tenant_id, author_type, author_email, body) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([uuid4(), $ticketId, $tenantId, $authorType === 'support' ? 'support' : 'customer', $authorEmail !== null ? mb_substr($authorEmail, 0, 255) : null, mb_substr($body, 0, 5000)]);
    $pdo->prepare('UPDATE support_tickets SET last_message_at = NOW() WHERE id = ?')->execute([$ticketId]);
}

/** Kunde ergänzt eine bestehende Anfrage (öffnet sie wieder). */
function ticket_customer_reply(array $ctx, string $ticketId, string $body): void
{
    $body = trim($body);
    if (mb_strlen($body) < 5 || mb_strlen($body) > 5000) {
        throw new RuntimeException('Bitte eine Ergänzung mit 5 bis 5.000 Zeichen eingeben.');
    }
    ticket_reject_secrets($body);
    $ticket = ticket_load_for_tenant((string)$ctx['org_id'], $ticketId);
    if (!$ticket) {
        throw new RuntimeException('Anfrage nicht gefunden.');
    }
    ticket_add_message($ticketId, (string)$ctx['org_id'], 'customer', (string)$ctx['email'], $body);
    db()->prepare("UPDATE support_tickets SET status = 'open', closed_at = NULL WHERE id = ? AND tenant_id = ?")->execute([$ticketId, $ctx['org_id']]);
    audit_log($ctx['org_id'], $ctx, 'support_ticket_replied', 'support_ticket', $ticketId, ['von' => 'kunde']);
    ticket_notify_support($ticketId, $ctx, (string)$ticket['subject'], $body, false);
}

/** Betreiber antwortet (Adminbereich); Kunde wird per E-Mail informiert. */
function ticket_support_reply(array $adminCtx, string $ticketId, string $body, bool $close): array
{
    $body = trim($body);
    if (!$close && mb_strlen($body) < 2) {
        throw new RuntimeException('Bitte eine Antwort eingeben.');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT t.*, o.name AS org_name FROM support_tickets t JOIN organizations o ON o.id = t.tenant_id WHERE t.id = ?');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        throw new RuntimeException('Anfrage nicht gefunden.');
    }
    if ($body !== '') {
        ticket_add_message($ticketId, (string)$ticket['tenant_id'], 'support', (string)$adminCtx['email'], $body);
    }
    if ($close) {
        $pdo->prepare("UPDATE support_tickets SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$ticketId]);
    } else {
        $pdo->prepare("UPDATE support_tickets SET status = 'answered', answered_at = NOW(), closed_at = NULL WHERE id = ?")->execute([$ticketId]);
    }
    audit_log($ticket['tenant_id'], $adminCtx, $close ? 'support_ticket_closed' : 'support_ticket_replied', 'support_ticket', $ticketId, ['von' => 'support']);
    if ($body !== '') {
        ticket_notify_customer($ticket, $body, $close);
    }
    return $ticket;
}

function ticket_load_for_tenant(string $tenantId, string $ticketId): ?array
{
    $stmt = db()->prepare('SELECT * FROM support_tickets WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$ticketId, $tenantId]);
    return $stmt->fetch() ?: null;
}

function ticket_messages(string $tenantId, string $ticketId): array
{
    $stmt = db()->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id = ? AND tenant_id = ? ORDER BY created_at ASC');
    $stmt->execute([$ticketId, $tenantId]);
    return $stmt->fetchAll();
}

/** Anfragen einer Firma, neueste zuerst. */
function tickets_for_tenant(string $tenantId, int $limit = 50): array
{
    $stmt = db()->prepare('SELECT * FROM support_tickets WHERE tenant_id = ? ORDER BY last_message_at DESC LIMIT ' . max(1, min(200, $limit)));
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

/** Anfragen aller Firmen (Adminbereich), optional nach Status. */
function tickets_all(?string $status = null, int $limit = 100): array
{
    $sql = 'SELECT t.*, o.name AS org_name FROM support_tickets t JOIN organizations o ON o.id = t.tenant_id';
    $params = [];
    if ($status !== null && isset(TICKET_STATUS_LABEL[$status])) {
        $sql .= ' WHERE t.status = ?';
        $params[] = $status;
    }
    $sql .= " ORDER BY FIELD(t.status, 'open', 'answered', 'closed'), t.last_message_at DESC LIMIT " . max(1, min(500, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function tickets_open_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'")->fetchColumn();
}

function ticket_notify_support(string $ticketId, array $ctx, string $subject, string $body, bool $isNew): void
{
    require_once __DIR__ . '/mailer.php';
    $to = ticket_support_address();
    if (!mail_enabled() || $to === '') {
        return;
    }
    $lines = [
        sprintf('%s Anfrage von %s (%s), Firma %s.', $isNew ? 'Neue' : 'Ergänzte', user_display_name($ctx), $ctx['email'], $ctx['org_name'] ?? ''),
        'Betreff: ' . $subject,
        $body,
    ];
    $url = (admin_base_url() !== '' ? admin_base_url() : app_base_url()) . '/admin-support.php#tickets';
    $tpl = mail_layout($isNew ? 'Neue Support-Anfrage' : 'Ergänzung zu einer Support-Anfrage', $lines, ['label' => 'Im Adminbereich beantworten', 'url' => $url]);
    mail_send($to, '[Support] ' . $subject, $tpl['text'], $tpl['html']);
}

function ticket_notify_customer(array $ticket, string $body, bool $closed): void
{
    require_once __DIR__ . '/mailer.php';
    if (!mail_enabled() || empty($ticket['user_email'])) {
        return;
    }
    $lines = [
        sprintf('Zu Ihrer Anfrage "%s" liegt eine Antwort des Supports vor:', $ticket['subject']),
        $body,
        $closed ? 'Die Anfrage wurde damit geschlossen. Sie können sie im Hilfe-Center jederzeit ergänzen, dann wird sie wieder geöffnet.' : 'Sie können im Hilfe-Center antworten oder Rückfragen stellen.',
    ];
    $tpl = mail_layout('Antwort auf Ihre Support-Anfrage', $lines, ['label' => 'Zum Hilfe-Center', 'url' => app_base_url() . '/hilfe.php#anfragen'], (string)($ticket['org_name'] ?? ''));
    mail_send((string)$ticket['user_email'], 'Antwort: ' . $ticket['subject'], $tpl['text'], $tpl['html']);
}
