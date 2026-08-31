<?php
/**
 * Verschlüsselung der API-Keys in der Datenbank (AES-256-GCM).
 * Schlüssel wird aus app_secret abgeleitet. Format: base64(iv | tag | ciphertext).
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

function crypto_key(): string
{
    $secret = (string)config('app_secret');
    if (strlen($secret) < 32) {
        throw new RuntimeException('app_secret ist zu kurz (mindestens 32 Zeichen).');
    }
    return hash('sha256', $secret, true);
}

function encrypt_value(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_value(?string $encoded): ?string
{
    if ($encoded === null || $encoded === '') {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) { // 12 IV + 16 Tag + min. 1 Byte
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}
