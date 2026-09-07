<?php
/**
 * Vormerkung für eine angekündigte Integration (zuerst sevdesk), Eingang für das Formular auf
 * smart-einzug.de/integrationen/sevdesk/ (POST) und für die Links aus der Bestätigungs-E-Mail (GET).
 *
 *   POST email, company (optional), provider, consent, src, website (Honeypot)  -> Bestätigungsmail
 *   GET  ?token=...                    -> Vormerkung bestätigen (Double-Opt-in)
 *   GET  ?token=...&aktion=abmelden    -> Abmelden
 *
 * Kein CSRF-Token: Das Formular liegt auf der statischen Produktwebsite (anderer Host), ein Sitzungstoken
 * ist dort nicht verfügbar. Schutz stattdessen: Origin/Referer muss zu einer erlaubten Herkunftsdomain
 * (signup_domains) oder zur Anwendung selbst gehören, Honeypot-Feld, Wiederversand-Abstand je Adresse und
 * globale Obergrenze je Minute (app/interest.php). Die einzige Wirkung eines Aufrufs ist eine
 * Bestätigungs-E-Mail an die eingegebene Adresse; gespeichert wird erst dauerhaft, was bestätigt wurde.
 * Es werden keine IP-Adressen gespeichert.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/interest.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/** Öffentliche Seite des Anbieters für Rücklinks. */
function vormerken_public_path(string $provider): string
{
    return $provider === 'sevdesk' ? '/integrationen/sevdesk/' : '/integrationen/';
}

/** Host aus Origin oder Referer; leer, wenn beides fehlt. */
function vormerken_source_host(): string
{
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $v = (string)($_SERVER[$h] ?? '');
        if ($v !== '') {
            return strtolower((string)parse_url($v, PHP_URL_HOST));
        }
    }
    return '';
}

function vormerken_page(string $title, array $paragraphs, string $backPath, ?string $backLabel = null): void
{
    layout_header($title);
    ?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title"><?= e($title) ?></h1>
        <?php foreach ($paragraphs as $p): ?>
            <p class="auth-sub"><?= e($p) ?></p>
        <?php endforeach; ?>
        <p class="auth-links"><a href="<?= e(marketing_url($backPath)) ?>"><?= e($backLabel ?? 'Zurück zur Produktseite') ?></a></p>
    </div>
</div>
    <?php
    layout_footer();
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        redirect(marketing_url('/integrationen/'));
    }
    $row = interest_by_token($token);
    $back = vormerken_public_path((string)($row['provider_code'] ?? ''));
    if (($_GET['aktion'] ?? '') === 'abmelden') {
        if (interest_unsubscribe($token) === 'unsubscribed') {
            vormerken_page('Abmeldung erfolgt', [
                'Ihre Vormerkung wurde beendet. Sie erhalten zu dieser Integration keine Nachricht mehr.',
                'Sie können sich jederzeit über die Produktseite erneut vormerken.',
            ], $back);
        }
        vormerken_page('Link ungültig', [
            'Dieser Abmeldelink ist ungültig oder wurde bereits verwendet.',
        ], $back);
    }
    $ergebnis = interest_confirm($token);
    if ($ergebnis === 'confirmed' || $ergebnis === 'already') {
        vormerken_page('Vormerkung bestätigt', [
            'Vielen Dank. Ihre Vormerkung ist wirksam; wir informieren Sie per E-Mail, sobald die Integration verfügbar ist.',
            'Die Vormerkung ist kostenlos und unverbindlich. Über den Link in der Bestätigungs-E-Mail können Sie sich jederzeit abmelden.',
        ], $back);
    }
    vormerken_page('Link ungültig oder abgelaufen', [
        'Der Bestätigungslink ist ungültig oder älter als 7 Tage.',
        'Bitte tragen Sie sich auf der Produktseite erneut ein; Sie erhalten dann einen neuen Link.',
    ], $back, 'Erneut vormerken');
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method Not Allowed');
}

// Herkunft prüfen: erlaubte Marketingdomains oder die Anwendung selbst. Fehlen Origin und Referer
// (Datenschutz-Erweiterungen), gilt die Anfrage nicht als abgelehnt; dann greifen Honeypot und Grenzen.
$allowedHosts = array_map('strtolower', (array)config('signup_domains', []));
$appHost = base_url_host(app_base_url());
if ($appHost !== '') {
    $allowedHosts[] = $appHost;
}
$srcHost = vormerken_source_host();
$srcHostBare = preg_replace('/^www\./', '', $srcHost);
if ($srcHost !== '' && !in_array($srcHost, $allowedHosts, true) && !in_array($srcHostBare, $allowedHosts, true)) {
    http_response_code(403);
    vormerken_page('Anfrage nicht angenommen', [
        'Die Anfrage kam nicht von einer bekannten Seite. Bitte nutzen Sie das Formular auf der Produktseite.',
    ], '/integrationen/');
}

$provider = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['provider'] ?? '')));
$back = vormerken_public_path($provider);
$src = strtolower(preg_replace('/^www\./', '', trim((string)($_POST['src'] ?? ''))));
if (!in_array($src, array_map('strtolower', (array)config('signup_domains', [])), true)) {
    $src = $srcHostBare !== '' ? $srcHostBare : null;
}

$r = interest_register($_POST, $src);
if (!$r['ok']) {
    $texte = [
        'email'    => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
        'consent'  => 'Ohne Ihre Einwilligung zur Benachrichtigung können wir Sie nicht vormerken.',
        'company'  => 'Der Firmenname ist zu lang (höchstens 160 Zeichen).',
        'provider' => 'Für diese Integration ist derzeit keine Vormerkung möglich.',
        'busy'     => 'Zurzeit gehen sehr viele Anfragen ein. Bitte versuchen Sie es in wenigen Minuten erneut.',
        'honeypot' => 'Die Anfrage konnte nicht verarbeitet werden.',
    ];
    http_response_code($r['error'] === 'busy' ? 429 : 422);
    vormerken_page('Vormerkung nicht möglich', [$texte[$r['error']] ?? 'Die Anfrage konnte nicht verarbeitet werden.'], $back, 'Zurück zum Formular');
}

switch ($r['state']) {
    case 'confirmed':
        vormerken_page('Vormerkung eingetragen', [
            'Vielen Dank. Wir informieren Sie per E-Mail, sobald die Integration verfügbar ist.',
            'Die Vormerkung ist kostenlos und unverbindlich.',
        ], $back);
        // kein break nötig, vormerken_page beendet
    case 'mail_failed':
        http_response_code(503);
        vormerken_page('E-Mail konnte nicht gesendet werden', [
            'Ihre Angaben sind eingetragen, die Bestätigungs-E-Mail konnte aber gerade nicht versendet werden.',
            'Bitte versuchen Sie es in einigen Minuten erneut; ohne bestätigte E-Mail-Adresse wird die Vormerkung nicht wirksam.',
        ], $back, 'Zurück zum Formular');
    default:
        // mail_sent und already: bewusst dieselbe Antwort, damit hinterlegte Adressen nicht ermittelbar sind.
        vormerken_page('Bitte E-Mail bestätigen', [
            'Vielen Dank. Wenn diese Adresse noch nicht bestätigt ist, erhalten Sie in Kürze eine E-Mail mit einem Bestätigungslink.',
            'Erst mit dem Klick auf diesen Link ist die Vormerkung wirksam. Der Link ist 7 Tage gültig. Prüfen Sie bei Bedarf auch den Spam-Ordner.',
        ], $back);
}
