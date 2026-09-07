<?php
/**
 * Vorregistrierung (unverbindliche Warteliste) für angekündigte Integrationen, zuerst sevdesk.
 *
 *   POST provider, email, name?, company?, consent, src, website (Honeypot)  -> Bestätigungsmail (neutrale Antwort)
 *   GET  ?token=A                    -> Seite "Vormerkung bestätigen" mit Button (keine Aktion per GET)
 *   POST token=A, aktion=bestaetigen -> Bestätigung, danach Seite mit freiwilligen Angaben
 *   GET  ?abmelden=B                 -> Seite "Abmelden" mit Button;  POST abmelden=B, aktion=abmelden -> Abmeldung
 *   POST abmelden=B, aktion=angaben  -> freiwillige Angaben (nur bestätigte Einträge)
 *
 * Token A (Bestätigung, 7 Tage) und Token B (Abmeldung und Angaben, dauerhaft) sind getrennt und nur als SHA-256
 * gespeichert. Links aus E-Mails führen nur auf Seiten mit Button, damit Linkvorschauen und Sicherheitsscanner
 * weder bestätigen noch abmelden. Kein Sitzungs-CSRF-Token (Formular auf anderem Host); Schutz siehe app/interest.php.
 * Keine IP-Speicherung. Eine Wartelistenbestätigung ist kein Login und erteilt keine Rechte an Firmenkonten.
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

/**
 * @param array<int, string> $paragraphs
 * @param ?array $form ['hidden' => [name => value], 'label' => Buttontext, 'fields' => html]
 */
function vormerken_page(string $title, array $paragraphs, string $backPath, ?string $backLabel = null, ?array $form = null, int $status = 200): void
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
        <?php if ($form): ?>
        <form method="post" action="vormerken.php">
            <?php foreach ($form['hidden'] as $k => $v): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
            <?php endforeach; ?>
            <?= $form['fields'] ?? '' ?>
            <button type="submit" class="btn btn-primary"><?= e($form['label']) ?></button>
        </form>
        <?php endif; ?>
        <p class="auth-links"><a href="<?= e(marketing_url($backPath)) ?>"><?= e($backLabel ?? 'Zurück zur Produktseite') ?></a></p>
    </div>
</div>
    <?php
    layout_footer();
    exit;
}

/** Freiwillige Angaben nach Bestätigung (Masterplan 7): keine Pflicht, keine Umsatzdaten. */
function vormerken_angaben_form(string $manage): array
{
    $ranges = ['bis_20' => 'bis 20', '21_100' => '21 bis 100', '101_500' => '101 bis 500', 'ueber_500' => 'mehr als 500'];
    $opts = '<option value="">keine Angabe</option>';
    foreach ($ranges as $k => $l) {
        $opts .= '<option value="' . e($k) . '">' . e($l) . '</option>';
    }
    $fields = '<div class="form-group"><label for="ipm">Rechnungen je Monat (ungefähr)</label><select id="ipm" name="invoices_per_month">' . $opts . '</select></div>'
        . '<div class="form-group"><label for="hs">Eigenes Stripe-Konto vorhanden?</label><select id="hs" name="has_stripe"><option value="">keine Angabe</option><option value="1">ja</option><option value="0">nein</option></select></div>'
        . '<div class="form-group"><label for="ha">API-Zugang bei sevdesk vorhanden?</label><select id="ha" name="has_api_access"><option value="">keine Angabe</option><option value="1">ja</option><option value="0">nein</option></select></div>'
        . '<div class="form-group"><label class="inline-check"><input type="checkbox" name="beta_interest" value="1"> Ich habe Interesse an einem Betatest vor dem allgemeinen Start.</label></div>';
    return ['hidden' => ['abmelden' => $manage, 'aktion' => 'angaben'], 'label' => 'Angaben speichern (freiwillig)', 'fields' => $fields];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$tokenA = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenB = trim((string)($_GET['abmelden'] ?? $_POST['abmelden'] ?? ''));
$aktion = (string)($_POST['aktion'] ?? '');

// --- Token B: Abmeldung und freiwillige Angaben ---------------------------------------------------------
if ($tokenB !== '') {
    $row = interest_by_manage_token($tokenB);
    $back = vormerken_public_path((string)($row['provider_code'] ?? ''));
    if (!$row) {
        vormerken_page('Link ungültig', ['Dieser Link ist ungültig oder der Eintrag wurde bereits gelöscht.'], $back, null, null, 410);
    }
    if ($method === 'POST' && $aktion === 'abmelden') {
        interest_unsubscribe($tokenB);
        vormerken_page('Abmeldung erfolgt', [
            'Ihre Vormerkung wurde beendet. Sie erhalten zu dieser Integration keine Nachricht mehr; Ihre Angaben werden nach 30 Tagen gelöscht.',
            'Sie können sich jederzeit über die Produktseite erneut vormerken; dafür ist eine neue Bestätigung nötig.',
        ], $back);
    }
    if ($method === 'POST' && $aktion === 'angaben') {
        $ok = interest_optional_update($tokenB, $_POST);
        vormerken_page($ok ? 'Vielen Dank' : 'Angaben nicht gespeichert', [
            $ok ? 'Ihre freiwilligen Angaben sind gespeichert. Sie helfen uns, den Start der sevdesk-Anbindung zu planen.'
                : 'Freiwillige Angaben sind nur für bestätigte Vormerkungen möglich.',
        ], $back);
    }
    if ($row['status'] === 'unsubscribed') {
        vormerken_page('Bereits abgemeldet', ['Diese Vormerkung ist bereits beendet. Es ist nichts weiter zu tun.'], $back);
    }
    vormerken_page('Vormerkung abmelden', [
        'Möchten Sie die Vormerkung für die Adresse ' . $row['email'] . ' beenden? Sie erhalten dann keine Nachrichten mehr zu dieser Integration.',
    ], $back, 'Abbrechen', ['hidden' => ['abmelden' => $tokenB, 'aktion' => 'abmelden'], 'label' => 'Jetzt abmelden']);
}

// --- Token A: Bestätigung -------------------------------------------------------------------------------
if ($tokenA !== '') {
    $row = interest_by_token($tokenA);
    $back = vormerken_public_path((string)($row['provider_code'] ?? ''));
    if (!$row) {
        vormerken_page('Link ungültig oder abgelaufen', [
            'Der Bestätigungslink ist ungültig, älter als 7 Tage oder wurde bereits verwendet.',
            'Bitte tragen Sie sich auf der Produktseite erneut ein; Sie erhalten dann einen neuen Link.',
        ], $back, 'Erneut vormerken', null, 410);
    }
    if ($method === 'POST' && $aktion === 'bestaetigen') {
        $manage = null;
        $mailSent = false;
        $ergebnis = interest_confirm($tokenA, $manage, $mailSent);
        if ($ergebnis === 'invalid') {
            vormerken_page('Bestätigung nicht möglich', ['Diese Vormerkung wurde abgemeldet oder der Link ist nicht mehr gültig.'], $back, 'Erneut vormerken', null, 410);
        }
        if (!$mailSent || $manage === null) {
            // Mail nach der Bestaetigung konnte nicht erzeugt werden: nichts behaupten, Nachsenden uebernimmt die Wartung;
            // der Abmeldelink der ersten E-Mail bleibt gueltig. Freiwillige Angaben brauchen einen frischen Token und
            // entfallen hier.
            vormerken_page('Ihre Vormerkung ist bestätigt', [
                'Ihre Einwilligung: Fassung ' . (string)$row['consent_text'] . ' vom ' . date('d.m.Y, H:i', interest_ts((string)($row['consent_at'] ?? $row['created_at']))) . ' Uhr (UTC), bestätigt am ' . date('d.m.Y, H:i') . ' Uhr.',
                'Wir informieren Sie über die sevdesk-Anbindung und den geplanten Start. Derzeit müssen Sie noch kein sevdesk- oder Stripe-Konto verbinden.',
                'Eine Bestätigung per E-Mail folgt, sobald der Versand verfügbar ist. Der Abmeldelink aus Ihrer ersten E-Mail bleibt gültig; es entsteht kein Abonnement und keine Zahlungspflicht.',
            ], $back, 'Zur Produktseite');
        }
        // Freiwillige Angaben laufen über den frischen Token B aus interest_confirm (nur als Hash gespeichert).
        vormerken_page('Ihre Vormerkung ist bestätigt', [
            'Ihre Einwilligung: Fassung ' . (string)$row['consent_text'] . ' vom ' . date('d.m.Y, H:i', interest_ts((string)($row['consent_at'] ?? $row['created_at']))) . ' Uhr (UTC), bestätigt am ' . date('d.m.Y, H:i') . ' Uhr.',
            'Wir informieren Sie über die sevdesk-Anbindung und den geplanten Start. Derzeit müssen Sie noch kein sevdesk- oder Stripe-Konto verbinden. Eine Bestätigung mit Abmeldelink ist an Ihre Adresse unterwegs.',
            'Es entsteht kein Abonnement und keine Zahlungspflicht. Abmelden können Sie sich jederzeit über den Link in jeder E-Mail.',
            'Wenn Sie möchten, helfen uns die folgenden freiwilligen Angaben bei der Planung. Sie können diesen Schritt auch überspringen.',
        ], $back, 'Zur Produktseite', vormerken_angaben_form($manage));
    }
    $einwilligung = 'Ihre Einwilligung: Fassung ' . (string)$row['consent_text'] . ' vom ' . date('d.m.Y, H:i', interest_ts((string)($row['consent_at'] ?? $row['created_at']))) . ' Uhr (UTC).';
    if ($row['status'] === 'confirmed') {
        vormerken_page('Vormerkung bereits bestätigt', ['Die Adresse ' . $row['email'] . ' ist bereits vorgemerkt. Es ist nichts weiter zu tun.', $einwilligung], $back);
    }
    vormerken_page('Vormerkung bestätigen', [
        'Bitte bestätigen Sie, dass Sie mit der Adresse ' . $row['email'] . ' über Entwicklungsstand und Start der sevdesk-Anbindung informiert werden möchten.',
        'Durch die Bestätigung entsteht kein kostenpflichtiges Abonnement.',
    ], $back, 'Abbrechen', ['hidden' => ['token' => $tokenA, 'aktion' => 'bestaetigen'], 'label' => 'E-Mail-Adresse bestätigen']);
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
    vormerken_page('Anfrage nicht angenommen', ['Die Anfrage kam nicht von einer bekannten Seite. Bitte nutzen Sie das Formular auf der Produktseite.'], '/integrationen/', null, null, 403);
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
    vormerken_page('Vormerkung derzeit nicht möglich', ['Die Anfrage konnte gerade nicht verarbeitet werden. Bitte versuchen Sie es in einigen Minuten erneut.'], $back, 'Zurück zum Formular', null, 503);
}
if (!$r['ok']) {
    $texte = [
        'email'    => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
        'consent'  => 'Ohne Ihre Einwilligung zur Benachrichtigung können wir Sie nicht vormerken.',
        'company'  => 'Name oder Firmenname ist zu lang.',
        'provider' => 'Für diese Integration ist derzeit keine Vormerkung möglich.',
        'busy'     => 'Zurzeit gehen sehr viele Anfragen ein. Bitte versuchen Sie es in wenigen Minuten erneut.',
        'honeypot' => 'Die Anfrage konnte nicht verarbeitet werden.',
    ];
    vormerken_page('Vormerkung nicht möglich', [$texte[$r['error']] ?? 'Die Anfrage konnte nicht verarbeitet werden.'], $back, 'Zurück zum Formular', null, $r['error'] === 'busy' ? 429 : 422);
}
if ($r['state'] === 'mail_deferred') {
    // Eintrag ist gespeichert; die Bestaetigungsmail wird von der Wartung nachgesendet, sobald der Versand verfuegbar ist.
    vormerken_page('Vormerkung gespeichert, Bestätigungs-E-Mail folgt', [
        'Ihre Angaben sind gespeichert. Die Bestätigungs-E-Mail konnte in diesem Moment nicht versendet werden und wird automatisch nachgesendet, sobald der Versand wieder verfügbar ist.',
        'Erst mit dem Klick auf den Bestätigungslink wird die Vormerkung wirksam; der Link ist ab Versand 7 Tage gültig. Sie müssen nichts weiter tun.',
    ], $back);
}
vormerken_page('Bitte bestätigen Sie Ihre E-Mail-Adresse', [
    'Wir haben Ihnen dazu einen Link geschickt. Erst mit der Bestätigung ist die Vormerkung wirksam; der Link ist 7 Tage gültig.',
    'Prüfen Sie bei Bedarf auch den Spam-Ordner. Durch die Vormerkung entsteht kein Abonnement.',
], $back);
