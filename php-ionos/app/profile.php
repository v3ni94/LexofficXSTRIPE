<?php
/**
 * Benutzerprofil: Anzeigename, Telefonnummern (freiwillig) und Profilbild.
 *
 * Profilbilder liegen unter app/storage/avatars/<user_id>.png außerhalb des
 * direkten Webzugriffs (siehe .htaccess in storage) und werden über avatar.php
 * nur an angemeldete Benutzer derselben Firma ausgeliefert. Bilder werden mit
 * GD geprüft, quadratisch beschnitten, auf 256 Pixel verkleinert und als PNG
 * neu gespeichert (entfernt Metadaten und eingebettete Fremdinhalte).
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_SIZE = 256;

function avatar_dir(): string
{
    $dir = (string)config('avatar_files_dir', storage_dir() . '/avatars');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

/**
 * Dateiname des Profilbilds des angemeldeten Benutzers oder null. Bewusst in einer
 * eigenen, abgesicherten Abfrage: Fehlt die Spalte noch (Migration 010 nicht
 * eingespielt), zeigt die Kopfzeile Initialen, die Anwendung bleibt nutzbar.
 */
function profile_avatar_name(array $ctx): ?string
{
    static $cache = [];
    $id = (string)($ctx['user_id'] ?? '');
    if ($id === '') {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        try {
            $stmt = db()->prepare('SELECT avatar_path FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $name = (string)($stmt->fetchColumn() ?: '');
            $cache[$id] = $name !== '' ? $name : null;
        } catch (Throwable $e) {
            $cache[$id] = null;
        }
    }
    return $cache[$id];
}

/** Initialen für den Platzhalterkreis (max. zwei Zeichen). */
function profile_initials(array $user): string
{
    $name = trim(user_display_name($user));
    if ($name === '') {
        return '?';
    }
    if (str_contains($name, '@')) {
        return mb_strtoupper(mb_substr($name, 0, 2));
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $ini = mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '');
    return mb_strtoupper($ini);
}

/** Telefonnummer prüfen und normalisieren (Ziffern, Leerzeichen, +, /, -, Klammern). */
function profile_normalize_phone(string $raw): ?string
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^\+?[0-9 \/\-().]{5,40}$/', $raw) || preg_match_all('/[0-9]/', $raw) < 5) {
        throw new RuntimeException('Bitte eine gültige Telefonnummer eingeben (Ziffern, Leerzeichen, +, /, - erlaubt).');
    }
    return $raw;
}

/** Name und Telefonnummern speichern. */
function profile_update(array $ctx, string $displayName, string $phonePrivate, string $phoneBusiness): void
{
    $displayName = trim(preg_replace('/\s+/', ' ', $displayName));
    if ($displayName === '' || mb_strlen($displayName) > 100) {
        throw new RuntimeException('Bitte einen Anzeigenamen mit höchstens 100 Zeichen eingeben.');
    }
    $pp = profile_normalize_phone($phonePrivate);
    $pb = profile_normalize_phone($phoneBusiness);
    db()->prepare('UPDATE users SET display_name = ?, phone_private = ?, phone_business = ? WHERE id = ?')
        ->execute([$displayName, $pp, $pb, $ctx['user_id']]);
    audit_log($ctx['org_id'] ?? null, $ctx, 'profile_updated', 'user', (string)$ctx['user_id'], [
        'display_name' => $displayName, 'phone_private' => $pp !== null, 'phone_business' => $pb !== null,
    ]);
}

/**
 * Profilbild aus einem Upload übernehmen (JPEG, PNG, WebP, GIF; bis 2 MB).
 * @return string Dateiname
 */
function profile_avatar_store(array $ctx, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Das Bild ist zu groß (höchstens 2 MB).' : 'Der Upload ist fehlgeschlagen. Bitte erneut versuchen.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Ungültiger Upload.');
    }
    if ((int)($file['size'] ?? 0) > AVATAR_MAX_BYTES) {
        throw new RuntimeException('Das Bild ist zu groß (höchstens 2 MB).');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new RuntimeException('Bitte ein Bild im Format JPG, PNG, WebP oder GIF hochladen.');
    }
    $info = @getimagesize($tmp);
    if (!$info || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) {
        throw new RuntimeException('Das Bild konnte nicht gelesen werden oder hat ungeeignete Abmessungen (16 bis 6000 Pixel).');
    }
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default => @imagecreatefromgif($tmp),
    };
    if (!$src) {
        throw new RuntimeException('Das Bild konnte nicht verarbeitet werden.');
    }
    $w = imagesx($src); $h = imagesy($src);
    $side = min($w, $h);
    $sx = (int)(($w - $side) / 2); $sy = (int)(($h - $side) / 2);
    $dst = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
    imagefill($dst, 0, 0, $transparent);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, AVATAR_SIZE, AVATAR_SIZE, $side, $side);
    imagedestroy($src);

    $name = preg_replace('/[^a-f0-9-]/', '', (string)$ctx['user_id']) . '.png';
    $path = avatar_dir() . '/' . $name;
    if (!imagepng($dst, $path, 6)) {
        imagedestroy($dst);
        throw new RuntimeException('Das Bild konnte nicht gespeichert werden.');
    }
    imagedestroy($dst);
    @chmod($path, 0640);
    db()->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$name, $ctx['user_id']]);
    audit_log($ctx['org_id'] ?? null, $ctx, 'profile_updated', 'user', (string)$ctx['user_id'], ['avatar' => 'gesetzt']);
    return $name;
}

/** Profilbild entfernen. */
function profile_avatar_delete(array $ctx): void
{
    $stmt = db()->prepare('SELECT avatar_path FROM users WHERE id = ?');
    $stmt->execute([$ctx['user_id']]);
    $name = (string)($stmt->fetchColumn() ?: '');
    if ($name !== '' && preg_match('/^[a-f0-9-]+\.png$/', $name)) {
        @unlink(avatar_dir() . '/' . $name);
    }
    db()->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?')->execute([$ctx['user_id']]);
    audit_log($ctx['org_id'] ?? null, $ctx, 'profile_updated', 'user', (string)$ctx['user_id'], ['avatar' => 'entfernt']);
}

/** Pfad zum Profilbild eines Benutzers, wenn der Betrachter es sehen darf (selbst oder gemeinsame Firma). */
function profile_avatar_path_for(array $viewerCtx, string $userId): ?string
{
    if (!preg_match('/^[a-f0-9-]{36}$/', $userId)) {
        return null;
    }
    $pdo = db();
    if ($userId !== (string)$viewerCtx['user_id']) {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM organization_members a JOIN organization_members b ON b.organization_id = a.organization_id
             WHERE a.user_id = ? AND b.user_id = ? LIMIT 1'
        );
        $stmt->execute([$viewerCtx['user_id'], $userId]);
        if (!$stmt->fetchColumn() && empty($viewerCtx['is_superadmin'])) {
            return null;
        }
    }
    $stmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $name = (string)($stmt->fetchColumn() ?: '');
    if ($name === '' || !preg_match('/^[a-f0-9-]+\.png$/', $name)) {
        return null;
    }
    $path = avatar_dir() . '/' . $name;
    return is_file($path) ? $path : null;
}
