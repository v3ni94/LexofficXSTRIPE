<?php
/**
 * Stichwort-Erkennung für Rechnungspositionen und Aufbau des
 * SEPA-Verwendungszwecks. Portiert aus invoice_keyword_service.py.
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

const KEYWORD_CATALOG = [
    [
        'display_name' => 'Vermietung',
        'sepa_name'    => 'Vermietung',
        'search_terms' => ['vermietung', 'miete', 'monatsmiete', 'kaltmiete', 'warmmiete',
                           'mieteinnahme', 'wohnungsmiete', 'mietobjekt'],
    ],
    [
        'display_name' => 'Verkauf',
        'sepa_name'    => 'Verkauf',
        'search_terms' => ['verkauf', 'kaufpreis', 'veräußerung', 'immobilienverkauf', 'objektverkauf'],
    ],
    [
        'display_name' => 'Verwaltung',
        'sepa_name'    => 'Verwaltung',
        'search_terms' => ['verwaltung', 'hausverwaltung', 'objektverwaltung', 'weg-verwaltung',
                           'sondereigentumsverwaltung', 'verwaltergebühr'],
    ],
    [
        'display_name' => 'Mieterhöhung',
        'sepa_name'    => 'Mieterhoehung',
        'search_terms' => ['mieterhöhung', 'mietanpassung', 'mietsteigerung', 'staffelmiete', 'indexmiete'],
    ],
    [
        'display_name' => 'Nebenkostenabrechnung',
        'sepa_name'    => 'Nebenkostenabr.',
        'search_terms' => ['nebenkosten', 'betriebskosten', 'betriebskostenabrechnung',
                           'nebenkostenabrechnung', 'hausgeld'],
    ],
    [
        'display_name' => 'Kaution',
        'sepa_name'    => 'Kaution',
        'search_terms' => ['kaution', 'mietkaution', 'sicherheitsleistung', 'kautionseinbehalt'],
    ],
    [
        'display_name' => 'Provision',
        'sepa_name'    => 'Provision',
        'search_terms' => ['provision', 'maklergebühr', 'maklerprovision', 'courtage', 'vermittlungsprovision'],
    ],
    [
        'display_name' => 'Instandhaltung',
        'sepa_name'    => 'Instandhaltung',
        'search_terms' => ['instandhaltung', 'reparatur', 'sanierung', 'renovierung',
                           'modernisierung', 'wartung', 'instandsetzung'],
    ],
    [
        'display_name' => 'Sonstiges',
        'sepa_name'    => 'Sonstiges',
        'search_terms' => [],
    ],
];

/** Nicht erlaubte SEPA-Zeichen ersetzen bzw. entfernen. */
function sanitize_for_sepa(string $text): string
{
    $replacements = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a',
        'ñ' => 'n', 'ç' => 'c',
    ];
    $text = strtr($text, $replacements);
    // Erlaubt: a-z A-Z 0-9 Leerzeichen / - ? : ( ) . , ' +
    return preg_replace("/[^a-zA-Z0-9 \\/\\-?:().,'+]/", '', $text);
}

function sepa_name_for(string $displayName): string
{
    foreach (KEYWORD_CATALOG as $entry) {
        if ($entry['display_name'] === $displayName) {
            return $entry['sepa_name'];
        }
    }
    return 'Sonstiges';
}

/**
 * Analysiert Lexoffice-lineItems und gibt [display_name, sepa_name] zurück.
 * Wortgrenzen-Suche (\b), damit z.B. "miete" nicht in "indexmiete" trifft.
 */
function extract_keyword(array $lineItems): array
{
    $matches = []; // display_name => höchster Betrag

    foreach ($lineItems as $item) {
        $itemText = mb_strtolower(
            (($item['name'] ?? '') . ' ' . ($item['description'] ?? ''))
        );
        $amount = (float)($item['totalPrice']['totalGrossAmount'] ?? 0);

        foreach (KEYWORD_CATALOG as $entry) {
            if (empty($entry['search_terms'])) {
                continue; // Sonstiges überspringen
            }
            foreach ($entry['search_terms'] as $term) {
                // (*UCP): Wortgrenzen auch bei Umlauten korrekt (wie Python \b)
                $pattern = '/(*UCP)\b' . preg_quote(mb_strtolower($term), '/') . '\b/u';
                if (preg_match($pattern, $itemText)) {
                    $name = $entry['display_name'];
                    if (!isset($matches[$name]) || $amount > $matches[$name]) {
                        $matches[$name] = $amount;
                    }
                    break;
                }
            }
        }
    }

    if (!$matches) {
        return ['Sonstiges', 'Sonstiges'];
    }

    if (count($matches) === 1) {
        $name = array_key_first($matches);
        return [$name, sepa_name_for($name)];
    }

    if (count($matches) === 2) {
        $names = array_keys($matches);
        sort($names);
        $display = implode('/', $names);
        $sepa = implode('/', array_map('sepa_name_for', $names));
        return [$display, $sepa];
    }

    // 3+ Treffer: Stichwort mit höchstem Betrag
    arsort($matches);
    $top = array_key_first($matches);
    return [$top, sepa_name_for($top)];
}

/** SEPA-konformer Verwendungszweck (max. 140 Zeichen). */
function build_description(string $voucherNumber, string $customerNumber, string $keywordSepa): string
{
    $raw = "SEPA LS RE $voucherNumber KD $customerNumber - $keywordSepa";
    $sanitized = sanitize_for_sepa($raw);

    if (strlen($sanitized) > 140) {
        $prefix = sanitize_for_sepa("SEPA LS RE $voucherNumber KD $customerNumber - ");
        $maxKwLen = 140 - strlen($prefix) - 1;
        $kw = substr(sanitize_for_sepa($keywordSepa), 0, max(0, $maxKwLen)) . '.';
        $sanitized = $prefix . $kw;
    }

    return substr($sanitized, 0, 140);
}
