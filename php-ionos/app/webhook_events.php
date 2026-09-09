<?php
/**
 * Gemeinsame Behandlung eingehender Stripe-Ereignisse für beide Webhook-Endpunkte
 * (billing-webhook.php für die Abonnements der Firmen, stripe-webhook.php für die SEPA-Einzüge).
 *
 * Drei Aufgaben, die beide Endpunkte brauchen:
 *  1. Doppelte Zustellung erkennen (Stripe stellt dasselbe Ereignis mehrfach zu),
 *  2. eine begonnene Verarbeitung wieder FREIGEBEN, wenn sie mit einem Fehler endet, damit die
 *     Wiederholung durch Stripe den Vorgang wirklich nachholt,
 *  3. veraltete Ereignisse erkennen (Stripe garantiert keine Reihenfolge).
 *
 * Befund der Gesamtprüfung vom 09.09.2026: Beide Endpunkte quittierten jeden Verarbeitungsfehler mit
 * HTTP 200 und hatten das Ereignis zu diesem Zeitpunkt bereits als verarbeitet vermerkt. Stripe
 * wiederholte deshalb nie, und ein späteres Nachsenden aus dem Dashboard lief in "bereits verarbeitet".
 * Ein Statuswechsel konnte damit dauerhaft verloren gehen: eine gekündigte Firma blieb aktiv, eine
 * zahlende Firma blieb gesperrt, eine Rücklastschrift wurde nicht vermerkt.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Ereignis für die Verarbeitung beanspruchen.
 * @return bool true = dieser Aufruf verarbeitet das Ereignis, false = bereits verarbeitet (nichts tun).
 */
function webhook_event_claim(string $source, string $eventId, string $type): bool
{
    if ($eventId === '') {
        return true; // ohne Kennung keine Erkennung möglich; die Verarbeitung selbst bleibt idempotent
    }
    $stmt = db()->prepare('INSERT IGNORE INTO webhook_events (id, source, event_type) VALUES (?, ?, ?)');
    $stmt->execute([mb_substr($eventId, 0, 255), mb_substr($source, 0, 20), mb_substr($type, 0, 60)]);
    return $stmt->rowCount() === 1;
}

/**
 * Beanspruchung zurücknehmen, weil die Verarbeitung fehlgeschlagen ist. Danach führt die Wiederholung
 * durch Stripe (oder ein Nachsenden aus dem Dashboard) den Vorgang erneut aus. Ein Fehler hier darf den
 * Ablauf nicht zusätzlich stören: dann bleibt es beim bisherigen Verhalten.
 */
function webhook_event_release(string $source, string $eventId): void
{
    if ($eventId === '') {
        return;
    }
    try {
        db()->prepare('DELETE FROM webhook_events WHERE id = ? AND source = ?')
            ->execute([mb_substr($eventId, 0, 255), mb_substr($source, 0, 20)]);
    } catch (Throwable $e) {
        error_log('webhook_event_release: ' . $e->getMessage());
    }
}

/**
 * Ist zu diesem Objekt bereits ein NEUERES Ereignis verarbeitet worden? Stripe garantiert keine
 * Reihenfolge; ein verspätetes älteres Ereignis darf einen neueren Stand nicht überschreiben.
 */
function webhook_event_is_stale(string $source, ?string $objectId, int $eventCreated): bool
{
    if (!is_string($objectId) || $objectId === '' || $eventCreated <= 0) {
        return false;
    }
    try {
        $stmt = db()->prepare('SELECT MAX(event_created) FROM webhook_events WHERE source = ? AND object_id = ?');
        $stmt->execute([mb_substr($source, 0, 20), mb_substr($objectId, 0, 255)]);
        $latest = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return false; // im Zweifel verarbeiten
    }
    return $latest > $eventCreated;
}

/** Objektbezug und Zeitpunkt des Ereignisses vermerken (Grundlage der Reihenfolgeprüfung). */
function webhook_event_mark_object(string $source, string $eventId, ?string $objectId, int $eventCreated): void
{
    if ($eventId === '') {
        return;
    }
    try {
        db()->prepare('UPDATE webhook_events SET object_id = ?, event_created = ? WHERE id = ? AND source = ?')
            ->execute([
                is_string($objectId) && $objectId !== '' ? mb_substr($objectId, 0, 255) : null,
                $eventCreated > 0 ? $eventCreated : null,
                mb_substr($eventId, 0, 255),
                mb_substr($source, 0, 20),
            ]);
    } catch (Throwable $e) {
        error_log('webhook_event_mark_object: ' . $e->getMessage());
    }
}
