<?php
/**
 * Vormerkung für eine angekündigte Integration (zuerst sevdesk). Eingang für das Formular auf
 * smart-einzug.de/integrationen/sevdesk/ und für die Links aus der Bestätigungs-E-Mail.
 *
 *   POST provider, email, company?, consent, src, website (Honeypot)   -> Bestätigungsmail (neutrale Antwort)
 *   GET  ?token=...                    -> Seite "Vormerkung bestätigen" mit Button (noch keine Aktion)
 *   GET  ?token=...&aktion=abmelden    -> Seite "Abmelden" mit Button
 *   POST token, aktion=bestaetigen|abmelden -> Aktion ausführen
 *
 * Der Link aus der E-Mail führt bewusst nur auf eine Seite mit Button: Linkvorschauen und Sicherheitsscanner
 * rufen Links per GET auf und dürfen weder bestätigen noch abmelden.
 *
 * Kein Sitzungs-CSRF-Token: Das Formular liegt auf der statischen Produktwebsite (anderer Host). Die
 * Herkunftsprüfung (interest_origin_allowed) schützt nur gegen browsergestützte Einbettung fremder Seiten;
 * gegen Skripte wirken Honeypot, Wiederversand-Abstand, Tagesgrenze je Adresse und die globale Grenze je
 * Minute (app/interest.php). Die einzige Wirkung eines Aufrufs ist eine Bestätigungs-E-Mail an die eingegebene
 * Adresse. Es werden keine IP-Adressen gespeichert. Für Bestätigung und Abmeldung ist der Token selbst das
 * Geheimnis (64 Hexzeichen, nur als SHA-256 gespeichert).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/interest.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function vormerken_public_path(string $provider): string
{
    return $provider === 'sevdesk' ? '/integrationen/sevdesk/' : '/integrationen/';
}

/** @param array<int, string> $paragraphs */
function vormerken_page(string $title, array $paragraphs, string $backPath, ?string $backLabel = null, ?array $button = null, int $status = 200): void
{
    http_response_code($status);
    layout_header($title);
    ?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title"><?= e($title) ?></h1>
        <?php foreach ($paragraphs as $p): ?>
            <p class="auth-sub"><?= e($p) ?></p>
        <?php endforeach; ?>
        <?php if ($button): ?>
        <form method="post" action="vormerken.php">
            <input type="hidden" name="token" value="<?= e($button['token']) ?>">
            <input type="hidden" name="aktion" value="<?= e($button['aktion']) ?>">
            <button type="submit" class="btn btn-primary"><?= e($button['label']) ?></button>
        </form>
        <?php endif; ?>
        <p class="auth-links"><a href="<?= e(marketing_url($backPath)) ?>"><?= e($backLabel ?? 'Zurück zur Produktseite') ?></a></p>
    </div>
</div>
    <?php
    layout_footer();
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$aktion = (string)($_GET['aktion'] ?? $_POST['aktion'] ?? '');

// --- Links aus der E-Mail: GET zeigt nur die Seite mit Button, POST führt aus ---------------------------
if ($token !== '') {
    $row = interest_by_token($token);
    $back = vormerken_public_path((string)($row['provider_code'] ?? ''));
    if (!$row) {
        vormerken_page('Link ungültig oder abgelaufen', [
            'Der Link ist ungültig, älter als 7 Tage oder wurde bereits verwendet.',
            'Bitte tragen Sie sich auf der Produktseite erneut ein; Sie erhalten dann einen neuen Link.',
        ], $back, 'Erneut vormerken', null, 410);
    }
    if ($method === 'POST' && $aktion === 'abmelden') {
        interest_unsubscribe($token);
        vormerken_page('Abmeldung erfolgt', [
            'Ihre Vormerkung wurde beendet. Sie erhalten zu dieser Integration keine Nachricht mehr; Ihre Angaben werden nach 30 Tagen gelöscht.',
            'Sie können sich jederzeit über die Produktseite erneut vormerken.',
        ], $back);
    }
    if ($method === 'POST' && $aktion === 'bestaetigen') {
        $ergebnis = interest_confirm($token);
        if ($ergebnis === 'invalid') {
            vormerken_page('Bestätigung nicht möglich', [
                'Diese Vormerkung wurde abgemeldet oder der Link ist nicht mehr gültig.',
                'Bitte tragen Sie sich auf der Produktseite erneut ein.',
            ], $back, 'Erneut vormerken', null, 410);
        }
        vormerken_page('Vormerkung bestätigt', [
            'Vielen Dank. Ihre Vormerkung ist wirksam; wir informieren Sie per E-Mail, sobald die Integration verfügbar ist oder sich der geplante Starttermin wesentlich ändert.',
            'Die Vormerkung ist kostenlos und unverbindlich. Über den Abmeldelink in der Bestätigungs-E-Mail können Sie sie jederzeit beenden.',
        ], $back);
    }
    if ($aktion === 'abmelden') {
        vormerken_page('Vormerkung abmelden', [
            'Möchten Sie die Vormerkung für die Adresse ' . $row['email'] . ' beenden? Sie erhalten dann keine Nachricht zum Start.',
        ], $back, 'Abbrechen', ['token' => $token, 'aktion' => 'abmelden', 'label' => 'Jetzt abmelden']);
    }
    if ($row['status'] === 'confirmed') {
        vormerken_page('Vormerkung bereits bestätigt', [
            'Die Adresse ' . $row['email'] . ' ist bereits vorgemerkt. Es ist nichts weiter zu tun.',
        ], $back);
    }
    if ($row['status'] === 'unsubscribed') {
        vormerken_page('Vormerkung abgemeldet', [
            'Diese Vormerkung wurde abgemeldet. Wenn Sie erneut informiert werden möchten, tragen Sie sich bitte auf der Produktseite neu ein.',
        ], $back, 'Erneut vormerken');
    }
    vormerken_page('Vormerkung bestätigen', [
        'Bitte bestätigen Sie, dass Sie mit der Adresse ' . $row['email'] . ' über den Start der Integration informiert werden möchten.',
    ], $back, 'Abbrechen', ['token' => $token, 'aktion' => 'bestaetigen', 'label' => 'Vormerkung bestätigen']);
}

if ($method === 'GET') {
    redirect(marketing_url('/integrationen/'));
}
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method Not Allowed');
}

// --- Formular der Produktseite ------------------------------------------------------------------------
$signupDomains = array_map('strtolower', (array)config('signup_domains', []));
$allowedHosts = $signupDomains;
$appHost = base_url_host(app_base_url());
if ($appHost !== '') {
    $allowedHosts[] = $appHost;
}
if (!interest_origin_allowed($_SERVER['HTTP_ORIGIN'] ?? null, $_SERVER['HTTP_REFERER'] ?? null, $allowedHosts)) {
    vormerken_page('Anfrage nicht angenommen', [
        'Die Anfrage kam nicht von einer bekannten Seite. Bitte nutzen Sie das Formular auf der Produktseite.',
    ], '/integrationen/', null, null, 403);
}

$provider = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['provider'] ?? '')));
$back = vormerken_public_path($provider);
$src = strtolower(preg_replace('/^www\./', '', trim((string)($_POST['src'] ?? ''))));
if (!in_array($src, $signupDomains, true)) {
    $originHost = strtolower(preg_replace('/^www\./', '', (string)parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST)));
    $src = in_array($originHost, $signupDomains, true) ? $originHost : null;
}

try {
    $r = interest_register($_POST, $src);
} catch (Throwable $e) {
    error_log('vormerken: ' . get_class($e));
    vormerken_page('Vormerkung derzeit nicht möglich', [
        'Die Anfrage konnte gerade nicht verarbeitet werden. Bitte versuchen Sie es in einigen Minuten erneut.',
    ], $back, 'Zurück zum Formular', null, 503);
}
if (!$r['ok']) {
    $texte = [
        'email'    => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
        'consent'  => 'Ohne Ihre Einwilligung zur Benachrichtigung können wir Sie nicht vormerken.',
        'company'  => 'Der Firmenname ist zu lang (höchstens 160 Zeichen).',
        'provider' => 'Für diese Integration ist derzeit keine Vormerkung möglich.',
        'busy'     => 'Zurzeit gehen sehr viele Anfragen ein. Bitte versuchen Sie es in wenigen Minuten erneut.',
        'honeypot' => 'Die Anfrage konnte nicht verarbeitet werden.',
    ];
    vormerken_page('Vormerkung nicht möglich', [$texte[$r['error']] ?? 'Die Anfrage konnte nicht verarbeitet werden.'], $back, 'Zurück zum Formular', null, $r['error'] === 'busy' ? 429 : 422);
}
if ($r['state'] === 'mail_failed') {
    vormerken_page('E-Mail konnte nicht gesendet werden', [
        'Die Bestätigungs-E-Mail konnte gerade nicht versendet werden. Ohne bestätigte E-Mail-Adresse wird keine Vormerkung wirksam.',
        'Bitte versuchen Sie es in etwa zehn Minuten erneut.',
    ], $back, 'Zurück zum Formular', null, 503);
}
// mail_sent und already: bewusst dieselbe Antwort, damit hinterlegte Adressen nicht ermittelbar sind.
vormerken_page('Bitte E-Mail bestätigen', [
    'Vielen Dank. Wenn diese Adresse noch nicht bestätigt ist, erhalten Sie in Kürze eine E-Mail mit einem Bestätigungslink.',
    'Erst mit der Bestätigung ist die Vormerkung wirksam. Der Link ist 7 Tage gültig. Prüfen Sie bei Bedarf auch den Spam-Ordner.',
], $back);
