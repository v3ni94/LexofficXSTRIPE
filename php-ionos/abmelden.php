<?php
/**
 * Abmeldung von Werbenachrichten des Marketingmoduls (Version 4.63, app/marketing.php). Oeffentlich, ohne Anmeldung.
 *
 *   GET  abmelden.php?t=<token>                          Seite mit Schaltflaeche „Keine weiteren Nachrichten“
 *   POST abmelden.php?t=<token>, aktion=abmelden          Abmeldung ausfuehren (Sperrliste, dauerhaft)
 *   POST abmelden.php?t=<token>, List-Unsubscribe=One-Click   Abmeldung ohne Rueckfrage (RFC 8058, Postfachanbieter)
 *
 * Der Token stammt aus der Nachricht (je Empfaenger und Kampagne, nur als Hash gespeichert) und ist die einzige
 * Berechtigung. Kein Sitzungs-CSRF-Token (Aufruf aus dem Postfach, kein angemeldeter Benutzer); ein POST mit gueltigem
 * Token bewirkt hoechstens die vom Empfaenger gewuenschte Abmeldung. Keine Tracking-Skripte (Token in der Adresse).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/marketing.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function abmelden_page(string $title, array $paragraphs, ?array $form = null, int $status = 200): void
{
    http_response_code($status);
    layout_header($title, null);
    ?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title"><?= e($title) ?></h1>
        <?php foreach ($paragraphs as $p): ?>
            <p class="auth-sub"><?= e($p) ?></p>
        <?php endforeach; ?>
        <?php if ($form): ?>
        <form method="post" action="abmelden.php?t=<?= e($form['token']) ?>">
            <input type="hidden" name="aktion" value="abmelden">
            <button type="submit" class="btn btn-primary">Keine weiteren Nachrichten</button>
        </form>
        <?php endif; ?>
        <p class="auth-links"><a href="<?= e(marketing_url('/')) ?>">Zur Website</a></p>
    </div>
</div>
    <?php
    layout_footer();
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$send = $token !== '' ? marketing_send_by_token($token) : null;

if ($send === null) {
    abmelden_page('Link ungültig', [
        'Dieser Abmeldelink ist ungültig oder die Nachricht, aus der er stammt, ist nicht mehr bekannt.',
        'Wenn Sie keine Nachrichten mehr erhalten möchten, antworten Sie auf die Nachricht mit dem Wort „Abmelden“; wir sperren Ihre Adresse dann von Hand.',
    ], null, 410);
}

if ($method === 'POST' && (($_POST['aktion'] ?? '') === 'abmelden' || marketing_is_one_click($method, $_POST))) {
    $weg = marketing_is_one_click($method, $_POST) ? 'one-click' : 'link';
    $r = marketing_unsubscribe($token, $weg);
    if ($weg === 'one-click') {
        // Postfachanbieter erwarten nur eine erfolgreiche Antwort, keine Seite
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $r === 'invalid' ? 'invalid' : 'ok';
        exit;
    }
    abmelden_page('Abmeldung erfolgt', [
        'Sie erhalten von ' . product_name() . ' keine Werbe- oder Informationsnachrichten mehr. Die Abmeldung gilt dauerhaft für die Adresse ' . $send['email'] . ' und wird auch bei künftigen Adresslisten berücksichtigt.',
        'Nachrichten zu einem bestehenden Firmenaccount (Bestätigungen, Sicherheitshinweise, Vorabankündigungen) sind davon nicht betroffen.',
    ]);
}

if (marketing_is_suppressed((string)$send['email_norm'])) {
    abmelden_page('Bereits abgemeldet', ['Die Adresse ' . $send['email'] . ' ist bereits von Werbenachrichten abgemeldet. Es ist nichts weiter zu tun.']);
}

abmelden_page('Keine weiteren Nachrichten?', [
    'Möchten Sie für die Adresse ' . $send['email'] . ' keine Werbe- oder Informationsnachrichten von ' . product_name() . ' mehr erhalten? Die Abmeldung gilt dauerhaft.',
], ['token' => $token]);
