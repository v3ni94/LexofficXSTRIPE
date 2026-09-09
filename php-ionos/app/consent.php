<?php
/**
 * Zustimmungsnachweis fuer AGB und Datenschutzerklaerung (Migration 025, Tabelle consent_records).
 *
 * Jede Registrierung speichert, wann welche Fassung der AGB und der Datenschutzerklaerung auf welchem Weg akzeptiert
 * wurde (Benutzer, E-Mail, UTC-Zeit, Quellseite). Die Fassungen sind Konstanten dieser Datei; bei Textaenderung der
 * Website hochzaehlen und in docs/einwilligungen.md archivieren. Anzeige: rechtliches.php (Firma) und security.php (Benutzer).
 * Vertragsdokumente mit Volltext (AVV, Verschwiegenheit) laufen ueber app/legal.php; die Vorregistrierung ueber app/interest.php.
 */
declare(strict_types=1);

const AGB_VERSION = 'agb-2026-09';
const DATENSCHUTZ_VERSION = 'datenschutz-2026-09';
const CONSENT_SUBJECTS = [
    'agb' => 'Allgemeine Geschäftsbedingungen',
    'datenschutz' => 'Datenschutzerklärung',
    // Zustimmung zur zahlungspflichtigen Bestellung (Abonnement). Bis 4.54 stand sie nur im Protokoll
    // (audit_log), das nach 90 Tagen geloescht wird; als Nachweis gegenueber dem Kunden war das zu wenig
    // (Befund der Gesamtpruefung 09.09.2026).
    'bestellung' => 'Zahlungspflichtige Bestellung (Abonnement)',
];

/** Zustimmung speichern (idempotent je Benutzer, Gegenstand und Fassung). */
function consent_record(?string $userId, ?string $orgId, string $email, string $subject, string $version, string $method = 'registration', ?string $sourceUrl = null, ?string $details = null): void
{
    if (!isset(CONSENT_SUBJECTS[$subject])) {
        throw new InvalidArgumentException('Unbekannter Zustimmungsgegenstand.');
    }
    try {
        $pdo = db();
        // Eine zahlungspflichtige Bestellung ist jedes Mal ein eigener Vorgang (Neubestellung nach Kuendigung,
        // Tarifwechsel) und wird deshalb nie zusammengefasst; AGB und Datenschutz bleiben je Fassung einmalig.
        if ($userId !== null && $subject !== 'bestellung') {
            $st = $pdo->prepare('SELECT id FROM consent_records WHERE user_id = ? AND subject = ? AND version = ? LIMIT 1');
            $st->execute([$userId, $subject, $version]);
            if ($st->fetch()) {
                return;
            }
        }
        $pdo->prepare('INSERT INTO consent_records (id, user_id, organization_id, user_email, subject, version, details, method, source_url, accepted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([
                uuid4(), $userId, $orgId, mb_strtolower(trim($email)), $subject, mb_substr($version, 0, 60),
                $details !== null ? mb_substr($details, 0, 255) : null,
                $method, $sourceUrl !== null ? mb_substr($sourceUrl, 0, 255) : null,
            ]);
    } catch (Throwable $e) {
        error_log('consent_record fehlgeschlagen: ' . $e->getMessage()); // Tabelle fehlt bis Migration 025; Registrierung nicht blockieren
    }
}

/** Zustimmungen zu AGB und Datenschutz bei der Registrierung erfassen. */
function consent_record_registration(string $userId, string $orgId, string $email): void
{
    $base = function_exists('marketing_url') ? marketing_url('') : '';
    consent_record($userId, $orgId, $email, 'agb', AGB_VERSION, 'registration', $base !== '' ? $base . '/agb' : null);
    consent_record($userId, $orgId, $email, 'datenschutz', DATENSCHUTZ_VERSION, 'registration', $base !== '' ? $base . '/datenschutz' : null);
}

function consent_list_for_user(string $userId): array
{
    try {
        $st = db()->prepare('SELECT * FROM consent_records WHERE user_id = ? ORDER BY accepted_at DESC');
        $st->execute([$userId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function consent_list_for_org(string $orgId): array
{
    try {
        $st = db()->prepare('SELECT * FROM consent_records WHERE organization_id = ? ORDER BY accepted_at DESC LIMIT 200');
        $st->execute([$orgId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function consent_subject_label(string $subject): string
{
    return CONSENT_SUBJECTS[$subject] ?? $subject;
}

function consent_method_label(string $method): string
{
    return ['registration' => 'Registrierung', 'backend' => 'Kundenanwendung', 'import' => 'Übernahme'][$method] ?? $method;
}
