<?php
/**
 * Impressum der Anwendung (Betreiber: Müller Holding AG). Daten stammen aus
 * config('operator'); leere Felder werden als Platzhalter angezeigt.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = current_user();
$op = (array)config('operator', []);
$mk = marketing_url();
$v = fn(string $k, string $ph) => trim((string)($op[$k] ?? '')) !== '' ? e((string)$op[$k]) : '<span class="placeholder">[' . e($ph) . ']</span>';

layout_header('Impressum', $ctx);
?>
<h1>Impressum</h1>
<p class="page-sub"><?= e(product_name()) ?> wird betrieben von der <?= e($op['name'] ?? 'Müller Holding AG') ?>.</p>

<div class="card">
    <h2>Anbieter</h2>
    <p>
        <strong><?= e($op['name'] ?? 'Müller Holding AG') ?></strong><br>
        <?= e($op['street'] ?? '') ?><br>
        <?= e($op['zip_city'] ?? '') ?>
    </p>
    <p>
        E-Mail: <?= $v('email', 'E-Mail-Adresse') ?><br>
        Telefon: <?= $v('phone', 'Telefonnummer eintragen') ?><br>
        Web: <?= $v('web', 'Webadresse') ?>
    </p>
    <p>
        Vertreten durch den Vorstand: <?= $v('board', 'Vorstand') ?><br>
        Vorsitzender des Aufsichtsrats: <?= $v('supervisory', 'Aufsichtsratsvorsitzender') ?><br>
        Registereintrag: <?= $v('register', 'Registergericht und Registernummer') ?><br>
        Umsatzsteuer-Identifikationsnummer: <?= $v('vat_id', 'USt-IdNr., falls vorhanden') ?>
    </p>
</div>

<div class="card">
    <h2>Hinweis zu Marken</h2>
    <p>Lexware, Lexware Office und lexoffice sind Marken der Haufe-Lexware GmbH &amp; Co. KG bzw. ihrer Rechteinhaber.
        Stripe ist eine Marke von Stripe, Inc. Die Nennung dient ausschließlich der Beschreibung der Kompatibilität.
        <?= e(product_name()) ?> ist eine unabhängige Softwarelösung mit Schnittstelle zu Lexware Office und kein
        Produkt der Haufe-Lexware GmbH &amp; Co. KG.</p>
</div>

<?php if ($mk !== ''): ?>
<div class="card">
    <h2>Rechtstexte</h2>
    <p><a href="<?= e($mk) ?>/datenschutz">Datenschutzerklärung</a> · <a href="<?= e($mk) ?>/agb">Allgemeine Geschäftsbedingungen</a></p>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
