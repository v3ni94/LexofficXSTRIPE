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
require_once __DIR__ . '/profile.php';

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
    <link rel="icon" href="<?= e(asset_url('assets/img/favicon-32.png')) ?>" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <title><?= e($title) ?> | <?= e($titleSuffix) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    <?php if (!$useHvmCi): ?>
    <style>
        :root { --brand-accent: #E3AC48; --brand-dark: #2E2D2E; }
    </style>
    <?php endif; ?>
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
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
        <a class="brand" href="<?= $ctx ? (on_admin_host() ? 'admin.php' : 'dashboard.php') : 'login.php' ?>">
            <?php if ($hasLogo): ?>
                <img src="assets/img/logo.jpg" alt="<?= e($brandName) ?>" class="brand-logo">
            <?php elseif ($useHvmCi): ?>
                <span class="brand-mark">HVM</span>
            <?php else: ?>
                <img class="brand-image" src="<?= e(asset_url('assets/img/logo-horizontal.png')) ?>" srcset="<?= e(asset_url('assets/img/logo-horizontal.png')) ?> 1x, <?= e(asset_url('assets/img/logo-horizontal@2x.png')) ?> 2x" alt="<?= e($brandName) ?>" height="48">
            <?php endif; ?>
            <span class="brand-text">
                <?php if ($hasLogo || $useHvmCi): ?><span class="brand-name"><?= e($brandName) ?></span><?php endif; ?>
                <span class="brand-sub"><?= e($brandSub) ?></span>
            </span>
        </a>
        <?php if ($ctx && on_admin_host()): ?>
        <nav class="main-nav" aria-label="Hauptnavigation">
            <a href="admin.php" class="nav-admin">Plattform-Administration</a>
            <a href="admin-support.php" class="nav-admin">Support</a>
            <a href="<?= e(app_base_url()) ?>/dashboard.php" title="Zur Kundenanwendung">Kundenanwendung</a>
        </nav>
        <?php elseif ($ctx): ?>
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
            <a href="hilfe.php?von=<?= e(current_script()) ?>" title="Anleitungen, häufige Fragen, Support-Anfrage">Hilfe</a>
            <?php if (!empty($ctx['is_superadmin'])): ?>
                <a href="<?= e(admin_base_url() !== '' ? admin_base_url() . '/admin.php' : 'admin.php') ?>" class="nav-admin">Admin</a>
            <?php endif; ?>
        </nav>
        <div class="user-menu">
            <details class="profile-menu" id="profile-menu">
                <summary aria-label="Profilmenü öffnen" title="<?= e(user_display_name($ctx)) ?>">
                    <?php $avatarName = profile_avatar_name($ctx); ?>
                    <span class="avatar"><?php if ($avatarName !== null): ?><img src="avatar.php?u=<?= e($ctx['user_id']) ?>&amp;v=<?= e(substr(md5($avatarName . (string)@filemtime(avatar_dir() . '/' . $avatarName)), 0, 8)) ?>" alt="" width="36" height="36"><?php else: ?><span class="avatar-initials"><?= e(profile_initials($ctx)) ?></span><?php endif; ?></span>
                    <span class="user-email"><?= e(user_display_name($ctx)) ?></span>
                    <span class="menu-caret" aria-hidden="true">&#9662;</span>
                </summary>
                <div class="profile-dropdown">
                    <div class="profile-head">
                        <strong><?= e(user_display_name($ctx)) ?></strong>
                        <span class="hint"><?= e($ctx['email']) ?></span>
                        <span class="user-role"><?= e(role_label($ctx['role'])) ?><?= !on_admin_host() ? ' · ' . e($ctx['org_name']) : '' ?></span>
                    </div>
                    <?php if (!on_admin_host()): ?>
                        <?php if (can_manage_settings($ctx)): ?><a href="settings.php">Einstellungen</a><?php endif; ?>
                        <a href="team.php">Firma</a>
                        <a href="export.php">Export</a>
                    <?php endif; ?>
                    <a href="security.php">Sicherheit</a>
                    <?php if (!on_admin_host()): ?><a href="hilfe.php">Hilfe</a><?php endif; ?>
                    <?php if (!on_admin_host() && empty($ctx['support_mode'])): ?><a href="companies.php">Firmen</a><?php endif; ?>
                    <?php if (on_admin_host()): ?><a href="<?= e(app_base_url()) ?>/dashboard.php">Kundenanwendung</a><?php endif; ?>
                    <a href="logout.php" class="menu-logout">Abmelden</a>
                </div>
            </details>
        </div>
        <?php endif; ?>
    </div>
</header>
<main class="site-main">
    <?php foreach (flash_pull() as $msg): ?>
        <div class="flash flash-<?= e($msg['type']) ?>"><?= e($msg['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($ctx && !empty($ctx['support_mode'])): ?>
        <div class="flash flash-warn support-banner"><strong>SUPPORT-MODUS.</strong> Sie arbeiten als Plattform-Support in der Firma
            <strong><?= e($ctx['org_name']) ?></strong> (Rolle Administrator). Einzüge, IBAN-Änderungen und Zugangsdaten sind gesperrt,
            alle Aktionen werden mit Support-Vermerk protokolliert. Endet um <?= e(date('H:i', strtotime((string)$ctx['support_expires_at']))) ?> Uhr.
            <a href="support-end.php">Support beenden</a>
        </div>
    <?php endif; ?>
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
        'queued'        => ['info',    'Vorgemerkt'],
        'overdue'       => ['warn',    'Überfällig'],
        // Stripe-Status
        'submitting'    => ['info',    'Wird eingereicht'],
        'processing'    => ['info',    'In Bearbeitung'],
        'succeeded'     => ['success', 'Erfolgreich'],
        'disputed'      => ['danger',  'Rücklastschrift'],
        'refunded'      => ['warn',    'Erstattet'],
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
