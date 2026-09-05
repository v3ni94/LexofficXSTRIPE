<?php
/**
 * SEPA-Mandate: Referenzvergabe, Mandatsdokument, Unterschrift, Verfall.
 *
 * Fachliche Regeln (SEPA-Basislastschrift, Stand der Recherche, vor
 * Produktivstart durch Rechtsberatung verifizieren):
 *  - Mandatsreferenz: max. 35 Zeichen, eingeschränkter SEPA-Zeichensatz,
 *    je Gläubiger eindeutig. Vergabe hier: "<Firmenpräfix><Kundennummer>",
 *    Laufkunden "<Präfix><JJJJMMTT><lfd. Nr.>".
 *  - Pflichtangaben im Mandat: Name und Anschrift des Zahlungsempfängers,
 *    Gläubiger-Identifikationsnummer, Mandatsreferenz, Zahlungsart
 *    (wiederkehrend/einmalig), Name und Anschrift des Zahlungspflichtigen,
 *    IBAN (BIC optional), Autorisierungstext mit Hinweis auf das
 *    Erstattungsrecht (acht Wochen), Ort, Datum, Unterschrift.
 *  - Ein Mandat erlischt, wenn 36 Monate lang keine Lastschrift eingezogen
 *    wurde (gerechnet ab Fälligkeit der letzten Lastschrift).
 *  - Vorabankündigung (Pre-Notification) grundsätzlich 14 Kalendertage vor
 *    Fälligkeit, durch Vereinbarung verkürzbar (z.B. im Mandat).
 *  - Mandate sind aufzubewahren (mindestens 14 Monate nach der letzten
 *    Einreichung, Empfehlung 36 Monate; handels- und steuerrechtliche
 *    Fristen gehen vor). Mandate werden hier nie gelöscht, nur widerrufen.
 *
 * Hinweis Stripe: Der technische Einzug erfolgt über Stripe als
 * Zahlungsdienstleister. Auf dem Kontoauszug des Zahlungspflichtigen
 * können deshalb die Gläubiger-ID und Mandatsreferenz von Stripe erscheinen.
 * Das Mandatsdokument weist darauf hin.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

const MANDATE_EXPIRY_MONTHS = 36;

/** Mandatsreferenz auf den zulässigen SEPA-Zeichensatz und 35 Zeichen prüfen. */
function validate_mandate_reference(string $ref): ?string
{
    if ($ref === '' || strlen($ref) > 35) {
        return 'Mandatsreferenz muss 1 bis 35 Zeichen lang sein.';
    }
    if (!preg_match("#^[A-Za-z0-9+?/\\-:().,' ]+$#", $ref)) {
        return 'Mandatsreferenz enthält unzulässige Zeichen (erlaubt: Buchstaben, Ziffern, + ? / - : ( ) . , \' Leerzeichen).';
    }
    return null;
}

/**
 * Gläubiger-Identifikationsnummer prüfen (ISO 7064 Mod 97-10, die
 * Geschäftsbereichskennung an Stelle 5 bis 7 bleibt unberücksichtigt).
 * Gibt [true, normalisierte ID] oder [false, Fehlermeldung] zurück.
 */
function validate_creditor_identifier(string $raw): array
{
    $ci = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
    if ($ci === '') {
        return [false, 'Gläubiger-Identifikationsnummer darf nicht leer sein.'];
    }
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/', $ci)) {
        return [false, 'Gläubiger-Identifikationsnummer hat ein ungültiges Format (z.B. DE98ZZZ09999999999).'];
    }
    if (str_starts_with($ci, 'DE') && strlen($ci) !== 18) {
        return [false, 'Eine deutsche Gläubiger-Identifikationsnummer hat genau 18 Stellen.'];
    }

    // Prüfsumme: nationale Kennung (ab Stelle 8) + Ländercode + Prüfziffern, Buchstaben -> Zahlen, mod 97 = 1
    $numeric = '';
    foreach (str_split(substr($ci, 7) . substr($ci, 0, 4)) as $char) {
        $numeric .= ctype_digit($char) ? $char : (string)(ord($char) - 55);
    }
    $remainder = 0;
    foreach (str_split($numeric, 7) as $chunk) {
        $remainder = (int)(($remainder . $chunk)) % 97;
    }
    if ($remainder !== 1) {
        return [false, 'Die Prüfziffer der Gläubiger-Identifikationsnummer ist ungültig.'];
    }
    return [true, $ci];
}

/** Firma laden (für Präfix, Gläubiger-ID, Anschrift). */
function _mandate_org(string $tenantId): array
{
    $stmt = db()->prepare('SELECT * FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $org = $stmt->fetch();
    if (!$org) {
        throw new RuntimeException('Firma nicht gefunden');
    }
    return $org;
}

/** Neue, eindeutige Mandatsreferenz für einen Kunden erzeugen. */
function mandate_next_reference(string $tenantId, array $customer, array $org): string
{
    $pdo = db();
    $orgPrefix = (string)($org['mandate_prefix'] ?: 'FIRMA');

    if (!(int)$customer['is_walk_in']) {
        $base = $orgPrefix . preg_replace('/[^A-Za-z0-9]/', '', (string)$customer['customer_number']);
        $ref = $base;
        // Wurde für diese Kundennummer bereits ein Mandat vergeben (z.B. nach
        // Widerruf), erhält das neue eine laufende Endung.
        $n = 1;
        while (true) {
            $stmt = $pdo->prepare('SELECT 1 FROM sepa_mandates WHERE tenant_id = ? AND mandate_reference = ?');
            $stmt->execute([$tenantId, $ref]);
            if (!$stmt->fetch()) {
                break;
            }
            $n++;
            $ref = $base . '-' . $n;
        }
    } else {
        $prefix = $orgPrefix . date('Ymd');
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS c FROM sepa_mandates WHERE tenant_id = ? AND mandate_reference LIKE ?'
        );
        $stmt->execute([$tenantId, $prefix . '%']);
        $count = (int)$stmt->fetch()['c'];
        $ref = $prefix . str_pad((string)$count, 3, '0', STR_PAD_LEFT);
    }

    if ($err = validate_mandate_reference($ref)) {
        throw new RuntimeException('Mandatsreferenz konnte nicht gebildet werden: ' . $err);
    }
    return $ref;
}

/**
 * Aktives Mandat des Kunden verwenden oder neu anlegen. Mit IBAN wird ein
 * vorhandener Entwurf (Dokument erzeugt, IBAN noch offen) an die IBAN
 * gebunden und aktiv. Ohne IBAN entsteht ein Entwurf für das Dokument.
 */
function get_or_create_mandate(string $tenantId, string $customerId, ?string $customerIbanId, ?string $userId = null): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT * FROM sepa_mandates WHERE tenant_id = ? AND customer_id = ? AND is_active = 1
         AND status IN ('draft', 'active') ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$tenantId, $customerId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($customerIbanId !== null && $existing['customer_iban_id'] !== $customerIbanId) {
            // IBAN neu oder gewechselt: Zahlungsmethode muss neu erstellt werden
            $pdo->prepare(
                "UPDATE sepa_mandates SET customer_iban_id = ?, stripe_payment_method_id = NULL,
                        status = IF(status = 'draft', 'active', status) WHERE id = ?"
            )->execute([$customerIbanId, $existing['id']]);
            $existing['customer_iban_id'] = $customerIbanId;
            $existing['stripe_payment_method_id'] = null;
            if ($existing['status'] === 'draft') {
                $existing['status'] = 'active';
            }
        }
        return $existing;
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Kunde nicht gefunden');
    }
    $org = _mandate_org($tenantId);
    $mandateRef = mandate_next_reference($tenantId, $customer, $org);

    $id = uuid4();
    $pdo->prepare(
        'INSERT INTO sepa_mandates
            (id, tenant_id, customer_id, customer_iban_id, mandate_reference, mandate_date, is_active,
             status, mandate_type, creditor_identifier)
         VALUES (?, ?, ?, ?, ?, CURDATE(), 1, ?, ?, ?)'
    )->execute([
        $id, $tenantId, $customerId, $customerIbanId, $mandateRef,
        $customerIbanId === null ? 'draft' : 'active', 'recurrent', $org['creditor_identifier'] ?: null,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM sepa_mandates WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Braucht der Kunde ein ausdrücklich neu eingeholtes Mandat? Ja, wenn kein
 * aktives Mandat besteht, aber ein früheres widerrufen oder verfallen ist.
 * In diesem Fall darf der Einzug kein Mandat still neu anlegen; das neue
 * Mandat wird über die Kundendetails (Dokument, Unterschrift) erfasst.
 */
function mandate_requires_manual_renewal(string $tenantId, string $customerId): bool
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sepa_mandates WHERE tenant_id = ? AND customer_id = ? AND is_active = 1 AND status IN ('draft', 'active')"
    );
    $stmt->execute([$tenantId, $customerId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sepa_mandates WHERE tenant_id = ? AND customer_id = ? AND status IN ('cancelled', 'expired')"
    );
    $stmt->execute([$tenantId, $customerId]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Mandat laden (mandantensicher). */
function mandate_load(string $tenantId, string $mandateId): ?array
{
    $stmt = db()->prepare('SELECT * FROM sepa_mandates WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$mandateId, $tenantId]);
    return $stmt->fetch() ?: null;
}

/** Alle Mandate eines Kunden (neueste zuerst). */
function mandates_for_customer(string $tenantId, string $customerId): array
{
    $stmt = db()->prepare(
        'SELECT m.*, ci.iban, ci.account_holder_name FROM sepa_mandates m
         LEFT JOIN customer_ibans ci ON ci.id = m.customer_iban_id
         WHERE m.tenant_id = ? AND m.customer_id = ? ORDER BY m.created_at DESC'
    );
    $stmt->execute([$tenantId, $customerId]);
    return $stmt->fetchAll();
}

/** Zeitpunkt der Dokumenterzeugung festhalten. */
function mandate_mark_document_generated(string $mandateId, ?string $userId): void
{
    db()->prepare(
        'UPDATE sepa_mandates SET document_generated_at = NOW(), document_generated_by = ? WHERE id = ?'
    )->execute([$userId, $mandateId]);
}

/** Unterschrift erfassen (Datum, Ort). Aktiviert einen Entwurf, sofern eine IBAN vorliegt. */
function mandate_mark_signed(string $tenantId, string $mandateId, string $signedDate, string $signedPlace): array
{
    $mandate = mandate_load($tenantId, $mandateId);
    if (!$mandate) {
        throw new RuntimeException('Mandat nicht gefunden.');
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $signedDate);
    if (!$d || $d->format('Y-m-d') !== $signedDate) {
        throw new RuntimeException('Ungültiges Unterschriftsdatum.');
    }
    if ($d > new DateTimeImmutable('today')) {
        throw new RuntimeException('Das Unterschriftsdatum darf nicht in der Zukunft liegen.');
    }
    $place = trim($signedPlace);
    if ($place === '') {
        throw new RuntimeException('Bitte den Ort der Unterschrift angeben.');
    }
    db()->prepare(
        "UPDATE sepa_mandates SET signed_date = ?, signed_place = ?, mandate_date = ?,
                status = IF(status = 'draft' AND customer_iban_id IS NOT NULL, 'active', status)
         WHERE id = ?"
    )->execute([$signedDate, mb_substr($place, 0, 100), $signedDate, $mandateId]);
    return mandate_load($tenantId, $mandateId);
}

/** Mandat widerrufen/beenden. Bleibt zur Aufbewahrung erhalten. */
function mandate_cancel(string $tenantId, string $mandateId, string $reason): void
{
    $mandate = mandate_load($tenantId, $mandateId);
    if (!$mandate) {
        throw new RuntimeException('Mandat nicht gefunden.');
    }
    db()->prepare(
        "UPDATE sepa_mandates SET is_active = 0, status = 'cancelled', cancelled_at = NOW(), cancel_reason = ? WHERE id = ?"
    )->execute([mb_substr(trim($reason), 0, 255) ?: null, $mandateId]);
}

/** Letzte Nutzung nach einer Einreichung fortschreiben. */
function mandate_touch_used(string $mandateId): void
{
    db()->prepare('UPDATE sepa_mandates SET last_used_at = NOW() WHERE id = ?')->execute([$mandateId]);
}

/**
 * Ist das Mandat für einen Einzug verwendbar? Gibt null zurück oder die
 * Begründung, warum nicht. Verfallene Mandate werden dabei als 'expired'
 * markiert.
 */
function mandate_check_usable(array $mandate, array $org): ?string
{
    if (!(int)$mandate['is_active'] || in_array($mandate['status'], ['cancelled', 'expired'], true)) {
        return 'Das SEPA-Mandat dieses Kunden ist widerrufen oder verfallen. Bitte ein neues Mandat einholen.';
    }
    if (empty($mandate['customer_iban_id'])) {
        return 'Für das SEPA-Mandat ist noch keine IBAN hinterlegt.';
    }

    // 36-Monats-Regel: letzte Nutzung bzw. Unterschrift/Erteilung
    $anchor = $mandate['last_used_at'] ?: ($mandate['signed_date'] ?: $mandate['mandate_date']);
    if ($anchor) {
        $limit = (new DateTimeImmutable($anchor))->modify('+' . MANDATE_EXPIRY_MONTHS . ' months');
        if ($limit < new DateTimeImmutable('today')) {
            db()->prepare("UPDATE sepa_mandates SET is_active = 0, status = 'expired' WHERE id = ?")
                ->execute([$mandate['id']]);
            return sprintf(
                'Das SEPA-Mandat wurde seit mehr als %d Monaten nicht genutzt und ist damit verfallen. Bitte ein neues Mandat einholen.',
                MANDATE_EXPIRY_MONTHS
            );
        }
    }

    if ((int)($org['require_signed_mandate'] ?? 1) === 1 && empty($mandate['signed_date'])) {
        return 'Für diesen Kunden ist noch kein unterschriebenes SEPA-Mandat erfasst (Einstellung "Handschriftlicher Nachweis erforderlich" ist aktiv; '
            . 'Kundendetails > Mandat > "Unterschrift erfassen" oder Mandat digital anfordern).';
    }
    return null;
}

/**
 * Texte für das Mandatsdokument. Standardwortlaut der SEPA-Basislastschrift
 * (Deutsche Kreditwirtschaft) ergänzt um den Hinweis auf Stripe als
 * Zahlungsdienstleister und die vereinbarte Vorabankündigungsfrist.
 */
function mandate_texts(array $org): array
{
    $name = (string)$org['name'];
    $days = max(1, (int)($org['pre_notification_days'] ?? 14));

    $authorization = sprintf(
        'Ich ermächtige / Wir ermächtigen %s, Zahlungen von meinem / unserem Konto mittels Lastschrift einzuziehen. '
        . 'Zugleich weise ich mein / weisen wir unser Kreditinstitut an, die von %s auf mein / unser Konto gezogenen '
        . 'Lastschriften einzulösen.',
        $name, $name
    );
    $refund = 'Hinweis: Ich kann / Wir können innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die '
        . 'Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem / unserem Kreditinstitut '
        . 'vereinbarten Bedingungen.';
    $psp = sprintf(
        'Die Zahlungsabwicklung erfolgt über den Zahlungsdienstleister Stripe (Stripe Payments Europe, Ltd.). '
        . 'Mit Erteilung dieses Mandats ermächtige ich / ermächtigen wir zugleich %s und Stripe als dessen '
        . 'Zahlungsdienstleister, meiner / unserer Bank Anweisungen zur Belastung meines / unseres Kontos zu '
        . 'übermitteln, und meine / unsere Bank, mein / unser Konto gemäß diesen Anweisungen zu belasten. Auf dem '
        . 'Kontoauszug können die von Stripe verwendete Gläubiger-Identifikationsnummer und Mandatsreferenz '
        . 'ausgewiesen sein.',
        $name
    );
    $prenotification = $days < 14
        ? sprintf(
            'Die Frist für die Vorabankündigung (Pre-Notification) von Lastschriften wird auf %d Tag(e) vor Fälligkeit '
            . 'verkürzt. Die Rechnung mit Angabe von Betrag, Fälligkeit und Mandatsreferenz gilt als Vorabankündigung.',
            $days
        )
        : 'Lastschriften werden spätestens 14 Kalendertage vor Fälligkeit angekündigt. Die Rechnung mit Angabe von '
        . 'Betrag, Fälligkeit und Mandatsreferenz gilt als Vorabankündigung.';

    return [
        'authorization'   => $authorization,
        'refund'          => $refund,
        'psp'             => $psp,
        'prenotification' => $prenotification,
        'expiry'          => sprintf(
            'Das Mandat erlischt, wenn %d Monate lang keine Lastschrift eingezogen wurde.',
            MANDATE_EXPIRY_MONTHS
        ),
    ];
}

/**
 * Prüfliste der Pflichtangaben für das Dokument: fehlende Angaben werden
 * im Dokument als Platzhalter dargestellt und dem Bearbeiter gemeldet.
 */
function mandate_document_missing(array $org, array $customer): array
{
    $missing = [];
    if (empty($org['creditor_identifier'])) {
        $missing[] = 'Gläubiger-Identifikationsnummer der Firma (Einstellungen > Firmendaten)';
    }
    if (empty($org['street']) || empty($org['zip']) || empty($org['city'])) {
        $missing[] = 'Anschrift der Firma (Einstellungen > Firmendaten)';
    }
    return $missing;
}
