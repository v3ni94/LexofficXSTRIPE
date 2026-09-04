<?php
/**
 * Zwei-Faktor-Authentifizierung: TOTP (RFC 6238 auf Basis HOTP nach RFC 4226),
 * Base32-Kodierung (RFC 4648) sowie Erzeugung, Normalisierung und Hashing von Wiederherstellungscodes.
 * Reine Bibliothek ohne Datenbank-, Session- oder Dateizugriffe.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * Kodiert Binärdaten als Base32 (RFC 4648, Alphabet A-Z2-7) ohne Padding.
 */
function base32_encode(string $bin): string
{
    if ($bin === '') {
        return '';
    }
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($bin);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 8) | ord($bin[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= BASE32_ALPHABET[($buffer >> $bits) & 0x1F];
        }
        // Puffer auf die verbleibenden Bits begrenzen, damit kein Überlauf entsteht
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits > 0) {
        $out .= BASE32_ALPHABET[($buffer << (5 - $bits)) & 0x1F];
    }
    return $out;
}

/**
 * Dekodiert Base32 (RFC 4648). Toleriert Kleinbuchstaben, Leerzeichen und Padding "=".
 * Wirft InvalidArgumentException bei ungültigen Zeichen.
 */
function base32_decode(string $b32): string
{
    $clean = strtoupper(preg_replace('/\s+/', '', $b32) ?? '');
    $clean = rtrim($clean, '=');
    if ($clean === '') {
        return '';
    }
    if (!preg_match('/^[A-Z2-7]+$/', $clean)) {
        throw new InvalidArgumentException('Ungültige Base32-Zeichenfolge.');
    }
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($clean);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 5) | strpos(BASE32_ALPHABET, $clean[$i]);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 0xFF);
            $buffer &= (1 << $bits) - 1;
        }
    }
    return $out;
}

/**
 * Erzeugt ein zufälliges TOTP-Geheimnis als Base32 ohne Padding (Standard 20 Bytes = 160 Bit).
 */
function totp_generate_secret(int $bytes = 20): string
{
    if ($bytes < 1) {
        throw new InvalidArgumentException('Die Länge des Geheimnisses muss mindestens 1 Byte betragen.');
    }
    return base32_encode(random_bytes($bytes));
}

/**
 * Berechnet den TOTP-Code (RFC 6238) für den angegebenen Unix-Zeitstempel.
 * Zeitschritt = floor(timestamp / period), Counter als 8-Byte Big-Endian, dynamische Truncation nach RFC 4226.
 * Führende Nullen werden erhalten.
 */
function totp_code(string $secretBase32, int $timestamp, int $period = 30, int $digits = 6, string $algo = 'sha1'): string
{
    if ($period < 1) {
        throw new InvalidArgumentException('Die Periode muss mindestens 1 Sekunde betragen.');
    }
    if ($digits < 6 || $digits > 10) {
        throw new InvalidArgumentException('Die Stellenzahl muss zwischen 6 und 10 liegen.');
    }
    $step = intdiv($timestamp, $period);
    if ($timestamp < 0 && $timestamp % $period !== 0) {
        $step--; // floor statt Abschneiden bei negativen Zeitstempeln
    }
    return totp_code_for_step($secretBase32, $step, $digits, $algo);
}

/**
 * Berechnet den HOTP-Wert (RFC 4226) für einen Zähler (Zeitschritt).
 */
function totp_code_for_step(string $secretBase32, int $step, int $digits = 6, string $algo = 'sha1'): string
{
    $key = base32_decode($secretBase32);
    if ($key === '') {
        throw new InvalidArgumentException('Das Geheimnis darf nicht leer sein.');
    }
    $algo = strtolower($algo);
    if (!in_array($algo, hash_hmac_algos(), true)) {
        throw new InvalidArgumentException('Nicht unterstützter Hash-Algorithmus.');
    }
    $counter = pack('J', $step); // 8 Byte, Big-Endian
    $hmac = hash_hmac($algo, $counter, $key, true);
    $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
    $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
        | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
        | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
        | (ord($hmac[$offset + 3]) & 0xFF);
    $otp = $binary % (10 ** $digits);
    return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
}

/**
 * Prüft einen TOTP-Code gegen den aktuellen Zeitschritt und +-window Schritte.
 * Gibt den akzeptierten Zeitschritt zurück oder false.
 * Replay-Schutz: Ist $lastUsedStep gesetzt, werden Schritte <= $lastUsedStep abgelehnt.
 * Der Aufrufer speichert den zurückgegebenen Schritt als neuen $lastUsedStep.
 */
function totp_verify(string $secretBase32, string $code, ?int $now = null, int $window = 1, ?int $lastUsedStep = null, int $period = 30, int $digits = 6): int|false
{
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^[0-9]{' . $digits . '}$/', $code)) {
        return false;
    }
    if ($window < 0) {
        $window = 0;
    }
    $now ??= time();
    $currentStep = intdiv($now, $period);
    if ($now < 0 && $now % $period !== 0) {
        $currentStep--;
    }
    // Alle Kandidaten durchlaufen, um zeitliche Seitenkanäle zu begrenzen
    $accepted = false;
    for ($offset = -$window; $offset <= $window; $offset++) {
        $step = $currentStep + $offset;
        $expected = totp_code_for_step($secretBase32, $step, $digits);
        if (hash_equals($expected, $code)) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if ($accepted === false) {
                $accepted = $step;
            }
        }
    }
    return $accepted;
}

/**
 * Erzeugt die otpauth-URI für Authenticator-Apps.
 * Format: otpauth://totp/{issuer}%3A{account}?secret=...&issuer=...&algorithm=SHA1&digits=6&period=30
 */
function totp_otpauth_uri(string $issuer, string $accountName, string $secretBase32, int $period = 30, int $digits = 6, string $algo = 'SHA1'): string
{
    $label = rawurlencode($issuer) . '%3A' . rawurlencode($accountName);
    $query = http_build_query([
        'secret' => $secretBase32,
        'issuer' => $issuer,
        'algorithm' => strtoupper($algo),
        'digits' => $digits,
        'period' => $period,
    ], '', '&', PHP_QUERY_RFC3986);
    return 'otpauth://totp/' . $label . '?' . $query;
}

const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/**
 * Erzeugt eindeutige Wiederherstellungscodes im Format XXXX-XXXX-XXXX.
 * Zeichenvorrat ohne 0/O/1/I zur Vermeidung von Verwechslungen; Zufall über random_int.
 */
function recovery_codes_generate(int $count = 10): array
{
    if ($count < 1) {
        return [];
    }
    $codes = [];
    $max = strlen(RECOVERY_ALPHABET) - 1;
    while (count($codes) < $count) {
        $raw = '';
        for ($i = 0; $i < 12; $i++) {
            $raw .= RECOVERY_ALPHABET[random_int(0, $max)];
        }
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        $codes[$code] = true;
    }
    return array_keys($codes);
}

/**
 * Normalisiert einen Wiederherstellungscode: Großbuchstaben, nur Buchstaben und Ziffern,
 * Bindestriche nach jeweils 4 Zeichen.
 */
function recovery_code_normalize(string $code): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    if ($clean === '') {
        return '';
    }
    return implode('-', str_split($clean, 4));
}

/**
 * Bildet den HMAC-SHA256 (hex, 64 Zeichen) des normalisierten Wiederherstellungscodes.
 * Der Aufrufer übergibt den Anwendungsschlüssel.
 */
function recovery_code_hash(string $code, string $secretKey): string
{
    if ($secretKey === '') {
        throw new InvalidArgumentException('Der Schlüssel für den Code-Hash darf nicht leer sein.');
    }
    return hash_hmac('sha256', recovery_code_normalize($code), $secretKey);
}
