<?php
/**
 * IBAN-Validierung (ISO 13616, Modulo 97), Formatierung und Maskierung.
 * Portiert aus backend/app/utils/iban.py.
 */

declare(strict_types=1);

const IBAN_LENGTHS = [
    'DE' => 22, 'AT' => 20, 'CH' => 21, 'NL' => 18, 'FR' => 27,
    'BE' => 16, 'LU' => 20, 'IT' => 27, 'ES' => 24,
];

/** Gibt [true, bereinigte IBAN] oder [false, Fehlermeldung] zurück. */
function validate_iban(string $iban): array
{
    $cleaned = strtoupper(preg_replace('/[\s\-]/', '', $iban));

    if ($cleaned === '') {
        return [false, 'IBAN darf nicht leer sein'];
    }
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $cleaned)) {
        return [false, 'IBAN hat ein ungültiges Format'];
    }

    $country = substr($cleaned, 0, 2);
    if (!isset(IBAN_LENGTHS[$country])) {
        return [false, "Ländercode '$country' wird nicht unterstützt"];
    }
    $expected = IBAN_LENGTHS[$country];
    if (strlen($cleaned) !== $expected) {
        return [false, "IBAN für $country muss $expected Zeichen haben, hat aber " . strlen($cleaned)];
    }

    // Modulo-97-Prüfung mit bcmod-freier Implementierung (chunkweise)
    $rearranged = substr($cleaned, 4) . substr($cleaned, 0, 4);
    $numeric = '';
    foreach (str_split($rearranged) as $char) {
        $numeric .= ctype_digit($char) ? $char : (string)(ord($char) - 55);
    }

    $remainder = 0;
    foreach (str_split($numeric, 7) as $chunk) {
        $remainder = (int)(($remainder . $chunk)) % 97;
    }
    if ($remainder !== 1) {
        return [false, 'IBAN-Prüfsumme ist ungültig'];
    }

    return [true, $cleaned];
}

/** 'DE89 3704 0044 0532 0130 00' */
function format_iban(string $iban): string
{
    $cleaned = strtoupper(preg_replace('/[\s\-]/', '', $iban));
    return implode(' ', str_split($cleaned, 4));
}

/** Erste 4 + letzte 4 Zeichen sichtbar, Rest maskiert. */
function mask_iban(string $iban): string
{
    $cleaned = strtoupper(preg_replace('/[\s\-]/', '', $iban));
    if (strlen($cleaned) <= 8) {
        return format_iban($cleaned);
    }
    $masked = substr($cleaned, 0, 4)
        . str_repeat('*', strlen($cleaned) - 8)
        . substr($cleaned, -4);
    return implode(' ', str_split($masked, 4));
}
