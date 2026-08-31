<?php
/**
 * Seitengerüst im verbindlichen CI der Hausverwaltung Müller GmbH (Skill hvm-ci).
 *
 * Farben, Schrift und Logo sind zentral in assets/css/style.css als
 * CSS-Variablen hinterlegt (Abschnitt 2 und 3 des CI-Handbuchs). Die
 * HVM-Kennlinie (vierfarbiges Band, Abschnitt 5.1) wird hier als Balken
 * über Kopf- und Fußzeile jeder Seite ausgegeben. Logo: assets/img/logo.jpg
 * (offizielle Datei aus dem CI-Handbuch, 1320 x 1143 px).
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

function layout_header(string $title, ?array $ctx = null): void
{
    $logoPath = APP_ROOT . '/assets/img/logo.jpg';
    $hasLogo = is_file($logoPath);
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> | SEPA-Portal | Hausverwaltung Müller GmbH</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="hvm-kennlinie" aria-hidden="true"></div>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="dashboard.php">
            <?php if ($hasLogo): ?>
                <img src="assets/img/logo.jpg" alt="Hausverwaltung Müller GmbH" class="brand-logo">
            <?php else: ?>
                <span class="brand-mark">HVM</span>
            <?php endif; ?>
            <span class="brand-text">
                <span class="brand-name">Hausverwaltung Müller GmbH</span>
                <span class="brand-sub">SEPA-Portal</span>
            </span>
        </a>
        <?php if ($ctx): ?>
        <nav class="main-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="invoices.php">Rechnungen</a>
            <a href="collections.php">Einzüge</a>
            <a href="customers.php">Kunden</a>
            <a href="sepa-pflegen.php">SEPA Pflegen</a>
            <?php if (can_manage($ctx)): ?>
                <a href="team.php">Team</a>
                <a href="settings.php">Einstellungen</a>
            <?php endif; ?>
        </nav>
        <div class="user-menu">
            <span class="user-email"><?= e($ctx['display_name'] ?: $ctx['email']) ?></span>
            <span class="user-role"><?= e(role_label($ctx['role'])) ?></span>
            <a class="btn btn-ghost btn-sm" href="logout.php">Abmelden</a>
        </div>
        <?php endif; ?>
    </div>
</header>
<main class="site-main">
    <?php foreach (flash_pull() as $msg): ?>
        <div class="flash flash-<?= e($msg['type']) ?>"><?= e($msg['message']) ?></div>
    <?php endforeach; ?>
    <?php
}

function layout_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <div class="hvm-kennlinie hvm-kennlinie-footer" aria-hidden="true"></div>
    <div class="footer-inner">
        <span>Hausverwaltung Müller GmbH | Rheinpromenade 13 | 40789 Monheim am Rhein</span>
        <span>Amtsgericht Düsseldorf, HRB 104762 | Geschäftsführer: Timo Müller | www.muellerhv.de</span>
    </div>
</footer>
</body>
</html>
    <?php
}

function role_label(string $role): string
{
    return match ($role) {
        'owner'  => 'Inhaber',
        'admin'  => 'Administrator',
        default  => 'Mitglied',
    };
}

/** Status eines Einzugs / einer Rechnung als Badge rendern. */
function status_badge(string $status, ?string $scheduledDate = null): string
{
    $map = [
        // Rechnungs-Status
        'none'          => ['neutral', 'Kein Einzug'],
        'open'          => ['neutral', 'Offen'],
        'in_collection' => ['info',    'Im Einzug'],
        'collected'     => ['success', 'Eingezogen'],
        'failed'        => ['danger',  'Fehlgeschlagen'],
        'scheduled'     => ['info',    'Terminiert'],
        // Stripe-Status
        'processing'    => ['info',    'In Bearbeitung'],
        'succeeded'     => ['success', 'Erfolgreich'],
        'disputed'      => ['danger',  'Rücklastschrift'],
        'cancelled'     => ['neutral', 'Storniert'],
    ];
    [$class, $label] = $map[$status] ?? ['neutral', $status];
    $suffix = '';
    if ($status === 'scheduled' && $scheduledDate) {
        $suffix = ' ' . format_date($scheduledDate);
    }
    return '<span class="badge badge-' . $class . '">' . e($label . $suffix) . '</span>';
}

/** Lesbare Bezeichnung für den Lexoffice-eigenen Rechnungsstatus (nicht den Einzugsstatus). */
function lexoffice_status_label(string $status): string
{
    $map = [
        'open'     => 'Offen',
        'overdue'  => 'Überfällig',
        'paid'     => 'Bezahlt',
        'voided'   => 'Storniert',
        'cancelled'=> 'Storniert',
        'draft'    => 'Entwurf',
    ];
    return $map[$status] ?? $status;
}
