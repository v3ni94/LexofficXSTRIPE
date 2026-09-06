<?php
/**
 * Hochgeladene Mandatsdokumente (unterschriebenes SEPA-Mandat als PDF oder Bild).
 *
 * Dateien liegen unter app/storage/mandates/<tenant_id>/<zufallsname> außerhalb des
 * Webzugriffs (app/ ist per .htaccess gesperrt, zusätzlich "Require all denied" im
 * Ordner). Auslieferung nur über mandate-file.php nach Mandantenprüfung.
 * Erlaubt: PDF, JPEG, PNG bis 10 MB; der Typ wird am Dateiinhalt geprüft, nicht an
 * der Endung. Jede Aktion wird protokolliert.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit; }

require_once __DIR__ . '/mandates.php';

const MANDATE_FILE_MAX_BYTES = 10 * 1024 * 1024;
const MANDATE_FILE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

function mandate_files_dir(string $tenantId): string
{
    if (!preg_match('/^[0-9a-f-]{36}$/', $tenantId)) {
        throw new RuntimeException('Ungültige Mandantenkennung.');
    }
    $base = (string)config('mandate_files_dir', storage_dir() . '/mandates');
    $dir = rtrim($base, '/') . '/' . $tenantId;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Ablageordner für Mandatsdateien kann nicht angelegt werden.');
    }
    return $dir;
}

/** Fehlermeldung zu PHP-Upload-Codes in verständlichem Deutsch. */
function mandate_file_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß (maximal 10 MB).',
        UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur unvollständig übertragen, bitte erneut versuchen.',
        UPLOAD_ERR_NO_FILE => 'Bitte eine Datei auswählen (PDF, JPG oder PNG).',
        default => 'Der Upload ist fehlgeschlagen (Code ' . $code . ').',
    };
}

/**
 * Datei aus $_FILES übernehmen, prüfen, ablegen und in der Datenbank verknüpfen.
 * @param array $file Eintrag aus $_FILES
 */
function mandate_file_store(string $tenantId, string $customerId, ?string $mandateId, array $file, ?string $userId, ?string $note = null): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Ungültiger Upload.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(mandate_file_upload_error((int)$file['error']));
    }
    $tmp = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Ungültiger Upload.');
    }
    $size = (int)filesize($tmp);
    if ($size <= 0) {
        throw new RuntimeException('Die Datei ist leer.');
    }
    if ($size > MANDATE_FILE_MAX_BYTES) {
        throw new RuntimeException('Die Datei ist zu groß (maximal 10 MB).');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!isset(MANDATE_FILE_TYPES[$mime])) {
        throw new RuntimeException('Nur PDF, JPG oder PNG sind erlaubt. Erkannter Typ: ' . ($mime ?: 'unbekannt') . '.');
    }
    if ($mime === 'application/pdf') {
        $head = (string)file_get_contents($tmp, false, null, 0, 5);
        if (strncmp($head, '%PDF-', 5) !== 0) {
            throw new RuntimeException('Die PDF-Datei ist beschädigt oder kein gültiges PDF.');
        }
    } elseif (@getimagesize($tmp) === false) {
        throw new RuntimeException('Die Bilddatei ist beschädigt oder kein gültiges Bild.');
    }

    // Kunde muss zum Mandanten gehören
    $stmt = db()->prepare('SELECT id FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerId, $tenantId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Kunde nicht gefunden.');
    }
    if ($mandateId !== null && $mandateId !== '') {
        $mandate = mandate_load($tenantId, $mandateId);
        if (!$mandate || $mandate['customer_id'] !== $customerId) {
            throw new RuntimeException('Das gewählte Mandat gehört nicht zu diesem Kunden.');
        }
    } else {
        $mandateId = null;
    }

    $originalName = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', (string)($file['name'] ?? 'mandat')) ?: 'mandat', 0, 255);
    $storedName = bin2hex(random_bytes(20)) . '.' . MANDATE_FILE_TYPES[$mime];
    $dir = mandate_files_dir($tenantId);
    $target = $dir . '/' . $storedName;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Die Datei konnte nicht gespeichert werden.');
    }
    @chmod($target, 0640);
    $sha = hash_file('sha256', $target);

    $id = uuid4();
    db()->prepare(
        'INSERT INTO mandate_files (id, tenant_id, customer_id, mandate_id, original_name, mime_type, size_bytes, sha256, stored_name, note, uploaded_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $tenantId, $customerId, $mandateId, $originalName, $mime, $size, $sha, $storedName, $note !== null ? mb_substr(trim($note), 0, 255) : null, $userId]);

    return mandate_file_load($tenantId, $id);
}

function mandate_file_load(string $tenantId, string $fileId): ?array
{
    $stmt = db()->prepare(
        'SELECT f.*, m.mandate_reference, u.email AS uploaded_by_email
         FROM mandate_files f
         LEFT JOIN sepa_mandates m ON m.id = f.mandate_id
         LEFT JOIN users u ON u.id = f.uploaded_by_user_id
         WHERE f.id = ? AND f.tenant_id = ?'
    );
    $stmt->execute([$fileId, $tenantId]);
    return $stmt->fetch() ?: null;
}

function mandate_files_for_customer(string $tenantId, string $customerId): array
{
    $stmt = db()->prepare(
        'SELECT f.*, m.mandate_reference, u.email AS uploaded_by_email
         FROM mandate_files f
         LEFT JOIN sepa_mandates m ON m.id = f.mandate_id
         LEFT JOIN users u ON u.id = f.uploaded_by_user_id
         WHERE f.tenant_id = ? AND f.customer_id = ?
         ORDER BY f.created_at DESC'
    );
    $stmt->execute([$tenantId, $customerId]);
    return $stmt->fetchAll();
}

function mandate_file_count_for_customer(string $tenantId, string $customerId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM mandate_files WHERE tenant_id = ? AND customer_id = ?');
    $stmt->execute([$tenantId, $customerId]);
    return (int)$stmt->fetchColumn();
}

function mandate_file_path(array $file): string
{
    return mandate_files_dir($file['tenant_id']) . '/' . basename($file['stored_name']);
}

function mandate_file_delete(string $tenantId, string $fileId): array
{
    $file = mandate_file_load($tenantId, $fileId);
    if (!$file) {
        throw new RuntimeException('Datei nicht gefunden.');
    }
    $path = mandate_file_path($file);
    db()->prepare('DELETE FROM mandate_files WHERE id = ? AND tenant_id = ?')->execute([$fileId, $tenantId]);
    if (is_file($path)) {
        @unlink($path);
    }
    return $file;
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
    return $bytes . ' Bytes';
}

/**
 * Gemeinsame Verarbeitung des Upload-Formulars (Kundendetail und SEPA Pflegen):
 * Datei speichern, optional dem Mandat zuordnen und die Unterschrift erfassen.
 * @return string Erfolgsmeldung
 */
function mandate_file_handle_upload(array $ctx, string $customerId, array $post, array $files): string
{
    $tenantId = $ctx['org_id'];
    $mandateId = trim((string)($post['mandate_id'] ?? ''));
    if ($mandateId === '') {
        // Aktives Mandat des Kunden automatisch zuordnen, falls vorhanden
        foreach (mandates_for_customer($tenantId, $customerId) as $m) {
            if ((int)$m['is_active'] === 1) {
                $mandateId = $m['id'];
                break;
            }
        }
    }
    $file = mandate_file_store($tenantId, $customerId, $mandateId ?: null, $files['mandate_file'] ?? [], $ctx['user_id'], $post['note'] ?? null);
    audit_log($tenantId, $ctx, 'mandate_file_uploaded', 'mandate_file', $file['id'], [
        'customer_id' => $customerId, 'mandate_reference' => $file['mandate_reference'], 'name' => $file['original_name'],
        'size' => (int)$file['size_bytes'], 'sha256' => $file['sha256'],
    ]);
    $msg = 'Mandatsdokument "' . $file['original_name'] . '" gespeichert' . ($file['mandate_reference'] ? ' und Mandat ' . $file['mandate_reference'] . ' zugeordnet' : '') . '.';

    if (!empty($post['mark_signed']) && $file['mandate_id']) {
        $mandate = mandate_mark_signed($tenantId, $file['mandate_id'], (string)($post['signed_date'] ?? ''), (string)($post['signed_place'] ?? ''));
        audit_log($tenantId, $ctx, 'mandate_signed', 'mandate', $mandate['id'], [
            'mandate_reference' => $mandate['mandate_reference'], 'signed_date' => $mandate['signed_date'], 'source' => 'upload',
        ]);
        $msg .= ' Unterschrift vom ' . format_date($mandate['signed_date']) . ' erfasst, das Mandat ist einsatzbereit.';
    }
    return $msg;
}
