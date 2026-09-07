<?php
/**
 * Stichtag des Einführungspreises (rollierend).
 *
 * Der Einführungspreis des Starttarifs gilt für Firmenaccounts, die bis zum Ende des LAUFENDEN
 * Kalendermonats angelegt werden. Der Stichtag verschiebt sich damit von selbst: Am 30.09. lautet er
 * 30.09.2026, am 01.10. bereits 31.10.2026. Ein festes Datum (früher 31.12.2026) müsste dagegen von Hand
 * gepflegt werden und wäre nach seinem Ablauf falsch.
 *
 * Bewusst ohne Datenbank und ohne weitere Abhängigkeiten, damit die Hilfetexte (app/help_content.php)
 * die Datei einbinden können und tools/pricing-check.php sie ohne Anwendung prüfen kann. Beträge und
 * Perioden stehen weiterhin ausschließlich in der Tabelle plans (app/plans.php), hier steht kein Preis.
 *
 * Die Angabe ist eine Preisangabe gegenüber Verbrauchern und Unternehmern: Sie muss auf den
 * Marketingseiten und in der Anwendung übereinstimmen. Die statischen Marketingseiten nennen deshalb
 * keinen berechneten Tag, sondern die gleichlautende Formulierung "bis zum Ende des laufenden
 * Kalendermonats" (siehe websites/, tools/pricing-check.php prüft beides).
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** Letzter Tag des laufenden Kalendermonats als TT.MM.JJJJ (Zeitzone der Anwendung). */
function intro_price_deadline(?DateTimeImmutable $now = null): string
{
    $now = $now ?? new DateTimeImmutable('now');
    return $now->modify('last day of this month')->format('d.m.Y');
}

/** Standardsatz zum Stichtag für Hilfetexte und Oberfläche. */
function intro_price_deadline_sentence(?DateTimeImmutable $now = null): string
{
    return sprintf(
        'Der Einführungspreis gilt für Firmenaccounts, die bis zum %s angelegt werden (Ende des laufenden '
        . 'Kalendermonats); für diese Accounts bleibt er bestehen, solange das Abonnement läuft.',
        intro_price_deadline($now)
    );
}
