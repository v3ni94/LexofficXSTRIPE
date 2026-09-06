<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

if (!config('allow_registration')) {
    flash_set('info', 'Die Registrierung ist derzeit deaktiviert. Bitte wenden Sie sich an den Betreiber (siehe Impressum).');
    redirect('login.php');
}
if (current_user()) {
    // Angemeldete Benutzer legen weitere Firmen über die Firmenübersicht an (Abschnitt 4.2:
    // eine vorhandene Sitzung desselben Benutzers wird verwendet, kein zweites Benutzerkonto).
    flash_set('info', 'Sie sind bereits angemeldet. Weitere Firmen legen Sie über die Firmenübersicht an.');
    redirect('companies.php');
}
signup_attribution_capture();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['signup']['started'])) {
    $_SESSION['signup']['started'] = true;
    funnel_event($_SESSION['signup']['domain'] ?? null, 'registration_started');
}

$error = null;
$existingSame = false; // bekannte E-Mail-Adresse und bereits zugeordnete Firma (Abschnitt 4.3)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $orgName = trim((string)($_POST['org_name'] ?? ''));
    if (empty($_POST['accept_terms'])) {
        $error = 'Bitte bestätigen Sie die AGB und die Datenschutzerklärung.';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
    } elseif ($orgName === '') {
        $error = 'Bitte einen Firmennamen angeben.';
    } else {
        $cls = registration_classify($email, $orgName);
        if ($cls['case'] === 'new') {
            $error = auth_register(
                $email,
                $_POST['password'] ?? '',
                $orgName,
                $_POST['first_name'] ?? null,
                $_POST['last_name'] ?? null,
                $_POST['mandate_prefix'] ?? ''
            );
            if ($error === null) {
                // Weiter: E-Mail bestätigen (falls Mailversand aktiv), dann 2FA, dann Einrichtung
                redirect('dashboard.php');
            }
        } elseif ($msg = register_throttle_check()) {
            $error = $msg;
        } else {
            // Bekannte E-Mail-Adresse: Eine E-Mail-Adresse gehört zu genau einer Benutzeridentität.
            // Ohne Anmeldung wird nichts am Konto geändert und keine Firma zugeordnet. Das hier
            // eingegebene Passwort wird weder geprüft noch gespeichert.
            login_record($email, false, 'register');
            $_SESSION['login_prefill_email'] = $email;
            if ($cls['case'] === 'existing_same') {
                $existingSame = true;
            } else {
                [$prefix, $prefixError] = validate_mandate_prefix((string)($_POST['mandate_prefix'] ?? ''));
                if ($prefixError) {
                    $error = $prefixError;
                } else {
                    $stmt = db()->prepare('SELECT 1 FROM organizations WHERE mandate_prefix = ?');
                    $stmt->execute([$prefix]);
                    if ($stmt->fetch()) {
                        $error = "Das Mandatspräfix \"$prefix\" wird bereits verwendet. Bitte ein anderes wählen.";
                    } else {
                        try {
                            $_SESSION['register_continue'] = registration_request_create($cls['user']['id'], $orgName, $prefix);
                        } catch (Throwable $e) {
                            error_log('Registrierungsvorgang speichern: ' . $e->getMessage());
                            $error = 'Die Registrierung kann derzeit nicht fortgesetzt werden. Bitte melden Sie sich an und legen Sie die Firma über die Firmenübersicht an.';
                        }
                        if ($error === null) {
                            flash_set('info', 'Bitte melden Sie sich mit Ihrem bestehenden Benutzerkonto an, um die weitere Firma anzulegen. Ihre bisherigen Zugangsdaten bleiben unverändert.');
                            redirect('login.php');
                        }
                    }
                }
            }
        }
    }
}

if ($existingSame) {
    layout_header('Benutzerkonto vorhanden');
    ?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Benutzerkonto vorhanden</h1>
        <div class="flash flash-info" data-countdown-redirect="login.php" data-countdown-seconds="5">
            Für diese E-Mail-Adresse und Firma besteht bereits ein Benutzerkonto. Bitte melden Sie sich an.
            Sie werden in <span data-countdown-value>5</span> Sekunden zur Anmeldung weitergeleitet.
        </div>
        <p class="auth-sub">Ihre E-Mail-Adresse ist auf der Anmeldeseite bereits eingetragen. Geben Sie dort nur noch Ihr bestehendes Passwort ein.</p>
        <a class="btn" href="login.php">Jetzt anmelden</a>
        <p class="auth-links"><a href="forgot-password.php">Passwort vergessen?</a></p>
    </div>
</div>
    <?php
    layout_footer();
    exit;
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
        <div class="flash flash-info">
            <strong>Voraussetzungen:</strong> Lexware Office XL (nach Angaben von Lexware für die Public API erforderlich, bitte im eigenen Konto prüfen)
            und ein für SEPA-Lastschriften freigeschaltetes eigenes Stripe-Konto.
        </div>
        <p class="hint">Bereits registriert? <a href="login.php">Anmelden</a></p>
        <form method="post" action="register.php">
            <?= csrf_field() ?>
            <h2>Firma</h2>
            <label for="org_name">Firmenname (Zahlungsempfänger auf dem SEPA-Mandat)</label>
            <input type="text" id="org_name" name="org_name" required maxlength="255" autocomplete="organization"
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
            <input type="email" id="email" name="email" required autocomplete="email"
                   value="<?= e($_POST['email'] ?? '') ?>">
            <label for="password">Passwort (mindestens 10 Zeichen)</label>
            <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
            <label for="password2">Passwort wiederholen</label>
            <input type="password" id="password2" name="password2" required minlength="10" autocomplete="new-password">
            <label class="checkbox-label">
                <input type="checkbox" data-toggle-password="password,password2">
                <span>Passwort anzeigen</span>
            </label>

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
        <p class="auth-links"><a href="login.php">Bereits registriert? Anmelden</a></p>
        <p class="hint" style="text-align:center;"><?= billing_enabled()
            ? 'Der Tarif wird erst mit Abschluss des Abonnements im Firmenbereich kostenpflichtig.'
            : 'Derzeit entsteht mit der Registrierung keine Zahlungspflicht.' ?></p>
        <p class="hint" style="text-align:center;">Tarif UNLIMITED START: 25,00 EUR netto zzgl. USt. je 4 Wochen, unbegrenzte Einzüge, unbegrenzte Mitarbeiter.
            Unabhängige Softwarelösung mit Schnittstelle zu Lexware Office. Kein Produkt der Haufe-Lexware GmbH &amp; Co. KG.</p>
    </div>
</div>
<?php layout_footer(); ?>
