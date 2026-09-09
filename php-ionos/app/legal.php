<?php
/**
 * Rechtsdokumente mit Zustimmungsnachweis (Migration 023).
 *
 * Auftragsverarbeitungsvertrag (Art. 28 DSGVO), Verschwiegenheitsvereinbarung fuer Berufsgeheimnistraeger
 * (§ 203 StGB) und weitere Dokumente liegen versioniert in legal_documents. Kunden sehen und akzeptieren
 * ausschliesslich veroeffentlichte Fassungen (published_at gesetzt, retired_at leer). Der Nachweis je Firma
 * und Fassung steht in legal_acceptances (Benutzer, E-Mail, Zeitpunkt, Weg); IP-Adressen werden nicht gespeichert.
 *
 * Texte kommen aus app/legal_drafts.php (im Repository versioniert) und werden im Adminbereich als Fassung
 * uebernommen und nach anwaltlicher Pruefung veroeffentlicht. Nie eigenes HTML: body_md ist ein eingeschraenktes
 * Markdown (Ueberschriften, Absaetze, Aufzaehlungen), das legal_render_md() escaped ausgibt.
 */
declare(strict_types=1);

const LEGAL_CODES = ['avv' => 'Auftragsverarbeitungsvertrag', 'secrecy' => 'Verschwiegenheitsvereinbarung (§ 203 StGB)'];

/** Alle Fassungen (neueste zuerst), optional je Code. Fuer den Adminbereich. */
function legal_documents_all(?string $code = null): array
{
    try {
        $sql = 'SELECT * FROM legal_documents' . ($code !== null ? ' WHERE code = ?' : '') . ' ORDER BY code, created_at DESC';
        $st = db()->prepare($sql);
        $st->execute($code !== null ? [$code] : []);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Aktuell veroeffentlichte Fassung je Code (neueste published_at, nicht zurueckgezogen). */
function legal_active_documents(): array
{
    try {
        $rows = db()->query('SELECT * FROM legal_documents WHERE published_at IS NOT NULL AND retired_at IS NULL ORDER BY published_at DESC, created_at DESC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!isset($out[$r['code']])) {
            $out[$r['code']] = $r;
        }
    }
    return $out;
}

function legal_document_load(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM legal_documents WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Firma ist Berufsgeheimnistraeger (organizations.professional_secrecy). */
function legal_org_secrecy(string $orgId): bool
{
    try {
        $st = db()->prepare('SELECT professional_secrecy FROM organizations WHERE id = ?');
        $st->execute([$orgId]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Stand je veroeffentlichtem Dokument fuer eine Firma:
 * ['doc' => Zeile, 'required' => bool, 'acceptance' => ?Zeile, 'accepted_other_version' => ?Zeile]
 */
function legal_status_for_org(string $orgId): array
{
    $active = legal_active_documents();
    if (!$active) {
        return [];
    }
    $secrecy = legal_org_secrecy($orgId);
    $st = db()->prepare('SELECT a.*, d.version AS doc_version, d.code AS doc_code FROM legal_acceptances a JOIN legal_documents d ON d.id = a.document_id WHERE a.organization_id = ? ORDER BY a.accepted_at DESC');
    $st->execute([$orgId]);
    $acc = $st->fetchAll();
    $out = [];
    foreach ($active as $code => $doc) {
        $required = $doc['required_for'] === 'all' || ($doc['required_for'] === 'secrecy' && $secrecy);
        $current = null;
        $older = null;
        foreach ($acc as $a) {
            if ($a['document_id'] === $doc['id']) {
                $current = $a;
            } elseif ($a['doc_code'] === $code && $older === null) {
                $older = $a;
            }
        }
        $out[$code] = ['doc' => $doc, 'required' => $required, 'acceptance' => $current, 'accepted_other_version' => $older];
    }
    return $out;
}

/** Codes der Pflichtdokumente, die fuer die Firma noch nicht in der aktuellen Fassung akzeptiert sind. */
function legal_pending_for_org(string $orgId): array
{
    $pending = [];
    foreach (legal_status_for_org($orgId) as $code => $s) {
        if ($s['required'] && $s['acceptance'] === null) {
            $pending[] = $code;
        }
    }
    return $pending;
}

/**
 * Zustimmung erfassen. Nur Inhaber und Administratoren der Firma; nur veroeffentlichte Fassungen.
 * Idempotent je Firma und Fassung. Schreibt einen Audit-Eintrag.
 */
function legal_accept(array $ctx, string $documentId, string $method = 'backend'): void
{
    // Im Support-Modus keine rechtsverbindliche Erklaerung im Namen der Firma (Befund 09.09.2026).
    if (!empty($ctx['support_mode']) || (function_exists('support_mode') && support_mode())) {
        throw new RuntimeException('Im Support-Modus koennen keine Vertragsdokumente im Namen der Firma akzeptiert werden. Diese Erklaerung muss die Firma selbst abgeben.');
    }
    $doc = legal_document_load($documentId);
    if (!$doc || $doc['published_at'] === null || $doc['retired_at'] !== null) {
        throw new RuntimeException('Dieses Dokument ist nicht in einer gueltigen Fassung veroeffentlicht.');
    }
    if (!in_array((string)($ctx['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new RuntimeException('Nur Inhaber und Administratoren der Firma koennen Vertragsdokumente akzeptieren.');
    }
    $orgId = (string)$ctx['org_id'];
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM legal_acceptances WHERE organization_id = ? AND document_id = ?');
    $st->execute([$orgId, $documentId]);
    if ($st->fetch()) {
        return;
    }
    $pdo->prepare('INSERT INTO legal_acceptances (id, organization_id, document_id, user_id, user_email, method, accepted_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([uuid4(), $orgId, $documentId, (string)($ctx['user_id'] ?? '') ?: null, (string)($ctx['email'] ?? ''), $method]);
    require_once __DIR__ . '/audit.php';
    audit_log($orgId, $ctx, 'legal_accepted', 'legal_document', $documentId, ['code' => $doc['code'], 'version' => $doc['version'], 'method' => $method]);
}

/** Verschwiegenheitspflicht der Firma setzen (Inhaber/Admin). */
function legal_set_secrecy(array $ctx, bool $flag, ?string $kind): void
{
    if (!empty($ctx['support_mode']) || (function_exists('support_mode') && support_mode())) {
        throw new RuntimeException('Im Support-Modus kann die Angabe zur Verschwiegenheitspflicht nicht geaendert werden.');
    }
    if (!in_array((string)($ctx['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new RuntimeException('Nur Inhaber und Administratoren koennen diese Angabe aendern.');
    }
    $kind = $kind !== null ? mb_substr(trim($kind), 0, 80) : null;
    db()->prepare('UPDATE organizations SET professional_secrecy = ?, professional_secrecy_kind = ? WHERE id = ?')
        ->execute([$flag ? 1 : 0, $flag ? ($kind !== '' ? $kind : null) : null, (string)$ctx['org_id']]);
    require_once __DIR__ . '/audit.php';
    audit_log((string)$ctx['org_id'], $ctx, 'legal_secrecy_set', 'organization', (string)$ctx['org_id'], ['flag' => $flag ? 1 : 0, 'kind' => $kind]);
}

/** Neue Fassung anlegen (unveroeffentlicht). Adminbereich. */
function legal_document_create(array $ctx, string $code, string $version, string $title, ?string $summary, string $bodyMd, string $requiredFor): string
{
    $code = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim($code))) ?? '';
    $version = preg_replace('/[^A-Za-z0-9._-]/', '', trim($version)) ?? '';
    if ($code === '' || $version === '' || trim($title) === '' || trim($bodyMd) === '') {
        throw new RuntimeException('Code, Fassung, Titel und Text sind Pflichtangaben.');
    }
    if (!in_array($requiredFor, ['all', 'secrecy', 'none'], true)) {
        throw new RuntimeException('Ungueltiger Geltungsbereich.');
    }
    $id = uuid4();
    db()->prepare('INSERT INTO legal_documents (id, code, version, title, summary, body_md, required_for, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $code, $version, mb_substr(trim($title), 0, 200), $summary !== null ? mb_substr(trim($summary), 0, 500) : null, $bodyMd, $requiredFor, (string)($ctx['email'] ?? '')]);
    require_once __DIR__ . '/audit.php';
    audit_log(null, $ctx, 'legal_document_created', 'legal_document', $id, ['code' => $code, 'version' => $version]);
    return $id;
}

/** Fassung veroeffentlichen (setzt aeltere Fassungen desselben Codes zurueck) oder zurueckziehen. */
function legal_document_publish(array $ctx, string $id, bool $publish): void
{
    $doc = legal_document_load($id);
    if (!$doc) {
        throw new RuntimeException('Dokument nicht gefunden.');
    }
    $pdo = db();
    if ($publish) {
        if (strpos($doc['body_md'], '[Platzhalter') !== false) {
            throw new RuntimeException('Der Text enthaelt noch Platzhalter und kann nicht veroeffentlicht werden.');
        }
        $pdo->prepare('UPDATE legal_documents SET retired_at = UTC_TIMESTAMP() WHERE code = ? AND id <> ? AND published_at IS NOT NULL AND retired_at IS NULL')->execute([$doc['code'], $id]);
        $pdo->prepare('UPDATE legal_documents SET published_at = UTC_TIMESTAMP(), retired_at = NULL WHERE id = ?')->execute([$id]);
    } else {
        $pdo->prepare('UPDATE legal_documents SET retired_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }
    require_once __DIR__ . '/audit.php';
    audit_log(null, $ctx, $publish ? 'legal_document_published' : 'legal_document_retired', 'legal_document', $id, ['code' => $doc['code'], 'version' => $doc['version']]);
}

/** Unveroeffentlichte Fassung loeschen (nur ohne Nachweise). */
function legal_document_delete(array $ctx, string $id): void
{
    $doc = legal_document_load($id);
    if (!$doc) {
        return;
    }
    if ($doc['published_at'] !== null) {
        throw new RuntimeException('Veroeffentlichte Fassungen werden nicht geloescht, nur zurueckgezogen (Nachweispflicht).');
    }
    db()->prepare('DELETE FROM legal_documents WHERE id = ? AND published_at IS NULL')->execute([$id]);
    require_once __DIR__ . '/audit.php';
    audit_log(null, $ctx, 'legal_document_deleted', 'legal_document', $id, ['code' => $doc['code'], 'version' => $doc['version']]);
}

/** Nachweise zu einer Firma (Adminbereich, Firmendetail). */
function legal_acceptances_for_org(string $orgId): array
{
    try {
        $st = db()->prepare('SELECT a.*, d.code, d.version, d.title FROM legal_acceptances a JOIN legal_documents d ON d.id = a.document_id WHERE a.organization_id = ? ORDER BY a.accepted_at DESC');
        $st->execute([$orgId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Anzahl Firmen ohne Zustimmung zur aktuellen Pflichtfassung (Adminbereich). */
function legal_missing_counts(): array
{
    $out = [];
    foreach (legal_active_documents() as $code => $doc) {
        if ($doc['required_for'] === 'none') {
            continue;
        }
        $where = $doc['required_for'] === 'secrecy' ? ' AND o.professional_secrecy = 1' : '';
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM organizations o WHERE o.deleted_at IS NULL$where AND NOT EXISTS (SELECT 1 FROM legal_acceptances a WHERE a.organization_id = o.id AND a.document_id = ?)");
            $st->execute([$doc['id']]);
            $out[$code] = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $out[$code] = -1;
        }
    }
    return $out;
}

/**
 * Eingeschraenktes Markdown nach HTML, vollstaendig escaped:
 * "# ", "## ", "### " Ueberschriften, "- " Aufzaehlung, "1. " Nummerierung, Leerzeile trennt Absaetze,
 * "**fett**" innerhalb einer Zeile. Keine Links, kein Roh-HTML.
 */
function legal_render_md(string $md): string
{
    $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];
    $html = '';
    $para = [];
    $list = null; // 'ul' | 'ol'
    $flushPara = static function () use (&$para, &$html): void {
        if ($para) {
            $html .= '<p>' . legal_inline(implode(' ', $para)) . "</p>\n";
            $para = [];
        }
    };
    $closeList = static function () use (&$list, &$html): void {
        if ($list !== null) {
            $html .= "</$list>\n";
            $list = null;
        }
    };
    foreach ($lines as $raw) {
        $line = rtrim($raw);
        if (trim($line) === '') {
            $flushPara();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
            $flushPara();
            $closeList();
            $lvl = strlen($m[1]) + 1; // # -> h2, damit die Seite genau eine h1 behaelt
            $html .= "<h$lvl>" . legal_inline($m[2]) . "</h$lvl>\n";
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($list !== 'ul') {
                $closeList();
                $list = 'ul';
                $html .= "<ul>\n";
            }
            $html .= '<li>' . legal_inline($m[1]) . "</li>\n";
            continue;
        }
        if (preg_match('/^\d+[.)]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($list !== 'ol') {
                $closeList();
                $list = 'ol';
                $html .= "<ol>\n";
            }
            $html .= '<li>' . legal_inline($m[1]) . "</li>\n";
            continue;
        }
        if ($list !== null && preg_match('/^\s{2,}(\S.*)$/', $line, $m)) {
            // Fortsetzungszeile eines Listenpunkts
            $html = preg_replace('/<\/li>\n$/', ' ' . legal_inline($m[1]) . "</li>\n", $html, 1) ?? $html;
            continue;
        }
        $closeList();
        $para[] = trim($line);
    }
    $flushPara();
    $closeList();
    return $html;
}

/** Inline: escapen, dann **fett** und [Platzhalter: ...] hervorheben. */
function legal_inline(string $text): string
{
    $t = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t) ?? $t;
    $t = preg_replace('/\[Platzhalter:([^\]]*)\]/', '<mark class="placeholder">[Platzhalter:$1]</mark>', $t) ?? $t;
    return $t;
}

/** Fassung als Klartext (Nachweis, Druck). */
function legal_plain_text(array $doc): string
{
    return $doc['title'] . "\nFassung " . $doc['version'] . "\n\n" . preg_replace('/\*\*(.+?)\*\*/', '$1', $doc['body_md']);
}
