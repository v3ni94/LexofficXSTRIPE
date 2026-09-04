<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

if (!config('allow_registration')) {
    flash_set('info', 'Die Registrierung ist derzeit deaktiviert. Bitte wenden Sie sich an den Betreiber (siehe Impressum).');
    redirect('login.php');
}
if (current_user()) {
    redirect('dashboard.php');
}
signup_attribution_capture();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['signup']['started'])) {
    $_SESSION['signup']['started'] = true;
    funnel_event($_SESSION['signup']['domain'] ?? null, 'registration_started');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (empty($_POST['accept_terms'])) {
        $error = 'Bitte bestätigen Sie die AGB und die Datenschutzerklärung.';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        $error = auth_register(
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            $_POST['org_name'] ?? '',
            $_POST['first_name'] ?? null,
            $_POST['last_name'] ?? null,
            $_POST['mandate_prefix'] ?? ''
        );
        if ($error === null) {
            // Weiter: E-Mail bestätigen (falls Mailversand aktiv), dann 2FA, dann Einrichtung
            redirect('dashboard.php');
        }
    }
}

$mk = marketing_url();
layout_header('Firmenaccount registrieren');
?>
<div class="auth-wrap auth-wide">
    <div class="card">
        <h1 class="auth-title">Firmenaccount registrieren</h1>
        <p class="auth-sub"><?= e(product_name()) ?>: SEPA-Lastschriften für Rechnungen aus Lexware Office über Stripe einziehen.
            Sie werden Inhaber des Firmenaccounts und richten anschließend die verpflichtende Zwei-Faktor-Authentifizierung ein.</p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="register.php">
            <?= csrf_field() ?>
            <h2>Firma</h2>
            <label for="org_name">Firmenname (Zahlungsempfänger auf dem SEPA-Mandat)</label>
            <input type="text" id="org_name" name="org_name" required maxlength="255"
                   value="<?= e($_POST['org_name'] ?? '') ?>">
            <label for="mandate_prefix">Mandatspräfix (2 bis 10 Zeichen, z.B. Firmenkürzel)</label>
            <input type="text" id="mandate_prefix" name="mandate_prefix" required maxlength="10"
                   pattern="[A-Za-z0-9]{2,10}" style="text-transform: uppercase;"
                   value="<?= e($_POST['mandate_prefix'] ?? '') ?>">
            <p class="hint">Bildet den Anfang der SEPA-Mandatsreferenzen Ihrer Kunden (z.B. "MF10045"). Nach der
                Einrichtung nicht mehr änderbar.</p>

            <h2>Ihr persönlicher Zugang</h2>
            <div class="form-row">
                <div>
                    <label for="first_name">Vorname</label>
                    <input type="text" id="first_name" name="first_name" maxlength="100" autocomplete="given-name"
                           value="<?= e($_POST['first_name'] ?? '') ?>">
                </div>
                <div>
                    <label for="last_name">Nachname</label>
                    <input type="text" id="last_name" name="last_name" maxlength="100" autocomplete="family-name"
                           value="<?= e($_POST['last_name'] ?? '') ?>">
                </div>
            </div>
            <label for="email">E-Mail-Adresse (persönlich, kein Sammelpostfach)</label>
            <input type="email" id="email" name="email" required autocomplete="username"
                   value="<?= e($_POST['email'] ?? '') ?>">
            <label for="password">Passwort (mindestens 10 Zeichen)</label>
            <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
            <label for="password2">Passwort wiederholen</label>
            <input type="password" id="password2" name="password2" required minlength="10" autocomplete="new-password">

            <label class="checkbox-label" style="margin-top: 16px;">
                <input type="checkbox" name="accept_terms" value="1" required>
                <span>Ich akzeptiere die
                    <?php if ($mk !== ''): ?>
                        <a href="<?= e($mk) ?>/agb" target="_blank" rel="noopener">AGB</a> und habe die
                        <a href="<?= e($mk) ?>/datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a>
                    <?php else: ?>
                        AGB und habe die Datenschutzerklärung
                    <?php endif; ?>
                    zur Kenntnis genommen.</span>
            </label>
            <button type="submit" class="btn">Firmenaccount erstellen</button>
        </form>
        <p class="auth-links"><a href="login.php">Bereits registriert? Zur Anmeldung</a></p>
        <p class="hint" style="text-align:center;">Tarif UNLIMITED START: 25,00 EUR netto zzgl. USt. je 4 Wochen, unbegrenzte Einzüge, unbegrenzte Mitarbeiter.
            Unabhängige Softwarelösung mit Schnittstelle zu Lexware Office. Kein Produkt der Haufe-Lexware GmbH &amp; Co. KG.</p>
    </div>
</div>
<?php layout_footer(); ?>
