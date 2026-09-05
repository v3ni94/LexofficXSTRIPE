<?php
/**
 * Seitengerüst der Anwendung (Produktname aus config, Standard SmartEinzug,
 * Betreiber: Müller Holding AG).
 *
 * Firmen mit organizations.use_hvm_ci = 1 (Hausverwaltung Müller GmbH) sehen
 * ihr eigenes CI (Logo, Kennlinie, Pflichtangaben, Skill hvm-ci). Alle
 * anderen Firmen sehen den neutralen Produktauftritt mit ihrem Firmennamen.
 * Der Fußbereich nennt in jedem Fall den Plattformbetreiber mit Links auf
 * Impressum, Datenschutz und AGB sowie den Markenhinweis zu Lexware Office.
 */

declare(strict_types=1);

require_once __DIR__ . '/integrations.php';

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// product_name() ist in bootstrap.php definiert (Standard SmartEinzug).

/** Öffentliche Produktwebsite (public_base_url, sonst marketing_url) mit optionalem Pfad. */
function marketing_url(string $path = ''): string
{
    $base = public_base_url();
    return $base !== '' ? $base . $path : '';
}

function layout_header(string $title, ?array $ctx = null, array $opts = []): void
{
    $useHvmCi = $ctx && !empty($ctx['use_hvm_ci']);
    $orgName = $ctx['org_name'] ?? null;
    $logoPath = APP_ROOT . '/assets/img/logo.jpg';
    $hasLogo = $useHvmCi && is_file($logoPath);
    $product = product_name();

    $brandName = $useHvmCi ? 'Hausverwaltung Müller GmbH' : $product;
    $brandSub = $useHvmCi ? 'SEPA-Portal' : ($orgName ?? 'SEPA-Einzug für Lexware Office');
    $titleSuffix = $useHvmCi ? 'Hausverwaltung Müller GmbH' : $product;
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <title><?= e($title) ?> | <?= e($titleSuffix) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if (!$useHvmCi): ?>
    <style>
        :root { --brand-accent: #E3AC48; --brand-dark: #2E2D2E; }
    </style>
    <?php endif; ?>
    <?= $opts['head'] ?? '' ?>
</head>
<body class="<?= $useHvmCi ? 'theme-hvm' : 'theme-product' ?>">
<?php if ($useHvmCi): ?>
<div class="hvm-kennlinie" aria-hidden="true"></div>
<?php else: ?>
<div class="brand-seam" aria-hidden="true"><span></span></div>
<?php endif; ?>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="<?= $ctx ? 'dashboard.php' : 'login.php' ?>">
            <?php if ($hasLogo): ?>
                <img src="assets/img/logo.jpg" alt="<?= e($brandName) ?>" class="brand-logo">
            <?php elseif ($useHvmCi): ?>
                <span class="brand-mark">HVM</span>
            <?php else: ?>
                <img class="brand-euro" src="assets/img/favicon.svg" alt="" width="34" height="34">
            <?php endif; ?>
            <span class="brand-text">
                <span class="brand-name"><?= e($brandName) ?></span>
                <span class="brand-sub"><?= e($brandSub) ?></span>
            </span>
        </a>
        <?php if ($ctx): ?>
        <nav class="main-nav" aria-label="Hauptnavigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="invoices.php">Rechnungen</a>
            <a href="collections.php">Einzüge</a>
            <a href="export.php" title="Einzugsjournal als CSV">Export</a>
            <a href="customers.php">Kunden</a>
            <a href="sepa-pflegen.php">SEPA Pflegen</a>
            <a href="team.php">Firma</a>
            <?php if (can_manage_settings($ctx)): ?><a href="notstopp.php" title="Einzüge dieser Firma sofort anhalten">Not-Stopp</a><?php endif; ?>
            <?php if (can_manage_settings($ctx)): ?>
                <a href="settings.php">Einstellungen</a>
            <?php endif; ?>
            <?php if (!empty($ctx['is_superadmin'])): ?>
                <a href="admin.php" class="nav-admin">Admin</a>
            <?php endif; ?>
        </nav>
        <div class="user-menu">
            <span class="user-email"><?= e(user_display_name($ctx)) ?></span>
            <span class="user-role"><?= e(role_label($ctx['role'])) ?></span>
            <a class="btn btn-ghost btn-sm" href="security.php" title="Passwort und Zwei-Faktor-Authentifizierung">Sicherheit</a>
            <a class="btn btn-ghost btn-sm" href="companies.php" title="Firma wechseln oder anlegen">Firmen</a>
            <a class="btn btn-ghost btn-sm" href="logout.php">Abmelden</a>
        </div>
        <?php endif; ?>
    </div>
</header>
<main class="site-main">
    <?php foreach (flash_pull() as $msg): ?>
        <div class="flash flash-<?= e($msg['type']) ?>"><?= e($msg['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($ctx && integration_stripe_test_mode($ctx['org_id'])): ?>
        <div class="flash flash-warn testmode-banner"><strong>TESTMODUS.</strong> Diese Firma ist mit einem Stripe-Testschlüssel verbunden. Es werden keine echten Lastschriften ausgeführt.
            <?php if (can_manage_settings($ctx)): ?><a href="settings.php">Live-Schlüssel hinterlegen</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($ctx && billing_enabled() && !subscription_allows_operation($ctx) && current_script() !== 'subscription.php'): ?>
        <div class="flash flash-info">Für diese Firma liegt noch kein aktives Abonnement vor.
            <?php if ($ctx['role'] === 'owner'): ?><a href="subscription.php">Abonnement jetzt abschließen</a><?php else: ?>Bitte wenden Sie sich an den Inhaber des Firmenaccounts.<?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
}

function layout_footer(?array $ctx = null): void
{
    $useHvmCi = $ctx && !empty($ctx['use_hvm_ci']);
    $op = (array)config('operator', []);
    $product = product_name();
    $mk = marketing_url();
    ?>
</main>
<footer class="site-footer">
    <?php if ($useHvmCi): ?>
    <div class="hvm-kennlinie hvm-kennlinie-footer" aria-hidden="true"></div>
    <div class="footer-inner">
        <span>Hausverwaltung Müller GmbH | Rheinpromenade 13 | 40789 Monheim am Rhein</span>
        <span>Amtsgericht Düsseldorf, HRB 104762 | Geschäftsführer: Timo Müller | www.muellerhv.de</span>
    </div>
    <?php endif; ?>
    <div class="footer-inner footer-legal">
        <span><?= e($product) ?> ist ein Dienst der <?= e($op['name'] ?? 'Müller Holding AG') ?>,
            <?= e($op['street'] ?? '') ?>, <?= e($op['zip_city'] ?? '') ?>.
            <a href="impressum.php">Impressum</a>
            <?php if ($mk !== ''): ?>
                · <a href="<?= e($mk) ?>/datenschutz" rel="noopener">Datenschutz</a>
                · <a href="<?= e($mk) ?>/agb" rel="noopener">AGB</a>
            <?php endif; ?>
        </span>
        <span class="footer-disclaimer">Unabhängige Softwarelösung mit Schnittstelle zu Lexware Office. Kein Produkt der Haufe-Lexware GmbH &amp; Co. KG.</span>
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
        default  => 'Mitarbeiter',
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
        // Mandats-Status
        'draft'         => ['warn',    'Entwurf'],
        'active'        => ['success', 'Aktiv'],
        'expired'       => ['danger',  'Verfallen'],
        // Abo / Einladung
        'pending'       => ['warn',    'Ausstehend'],
        'accepted'      => ['success', 'Angenommen'],
        'revoked'       => ['neutral', 'Widerrufen'],
        'suspended'     => ['danger',  'Gesperrt'],
    ];
    [$class, $label] = $map[$status] ?? ['neutral', $status];
    $suffix = '';
    if ($status === 'scheduled' && $scheduledDate) {
        $suffix = ' ' . format_date($scheduledDate);
    }
    return '<span class="badge badge-' . $class . '">' . e($label . $suffix) . '</span>';
}

/** Lesbare Bezeichnung für den Rechnungsstatus aus Lexware Office (nicht den Einzugsstatus). */
function lexoffice_status_label(string $status): string
{
    $map = [
        'open'     => 'Offen',
        'overdue'  => 'Überfällig',
        'paid'     => 'Bezahlt',
        'voided'   => 'Storniert',
        'cancelled'=> 'Storniert',
        'draft'    => 'Entwurf',
        'not_open' => 'wird noch geprüft',
    ];
    return $map[$status] ?? $status;
}
