<?php
/**
 * Ruecklaeufer und Beschwerden aus Amazon SES ueber Amazon SNS (Version 4.63, app/marketing.php).
 *
 *   POST marketing-webhook.php?token=<mail_marketing.webhook_token>
 *
 * Schutz in zwei Stufen: (1) der Token aus shared/config.php muss in der Adresse stehen (sonst 404, kein Hinweis auf den
 * Endpunkt); (2) jede SNS-Nachricht wird gegen das von Amazon veroeffentlichte Zertifikat geprueft (Signature,
 * SigningCertURL nur https://sns.<region>.amazonaws.com/...pem). SubscriptionConfirmation wird durch Aufruf der
 * SubscribeURL bestaetigt (nur amazonaws.com), Notification an marketing_ses_handle() gegeben: harte Bounces und
 * Complaints sperren die Adresse dauerhaft, alles andere wird nur protokolliert (marketing_events).
 *
 * Antwort: 200 bei verarbeitet oder bewusst ignoriert, 400 bei ungueltigem Inhalt, 403 bei falscher Signatur. SNS
 * wiederholt bei Fehlern; Sperren sind idempotent, eine Wiederholung schadet nicht.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/marketing.php';

header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=UTF-8');

$cfg = mail_profile_config('marketing');
$expected = (string)($cfg['webhook_token'] ?? '');
$given = (string)($_GET['token'] ?? '');
if ($expected === '' || str_contains($expected, 'HIER-') || strlen($expected) < 32 || !hash_equals($expected, $given)) {
    http_response_code(404);
    exit('Not found');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 512 * 1024) {
    http_response_code(413);
    exit('too large');
}
$msg = json_decode($raw, true);
if (!is_array($msg) || empty($msg['Type'])) {
    http_response_code(400);
    exit('invalid');
}
if (!marketing_sns_verify($msg)) {
    app_log('warning', 'SNS-Nachricht mit ungueltiger Signatur abgewiesen', ['type' => (string)$msg['Type'], 'topic' => mb_substr((string)($msg['TopicArn'] ?? ''), 0, 120)]);
    http_response_code(403);
    exit('signature');
}

$type = (string)$msg['Type'];
if ($type === 'SubscriptionConfirmation') {
    $url = (string)($msg['SubscribeURL'] ?? '');
    $p = parse_url($url);
    $ok = false;
    if (is_array($p) && ($p['scheme'] ?? '') === 'https' && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', (string)($p['host'] ?? ''))) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        $ok = is_string($body) && (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
    }
    db()->prepare('INSERT INTO marketing_events (source, event_type, email_norm, message_id, details_json, created_at) VALUES (\'ses\', \'subscription\', NULL, ?, ?, UTC_TIMESTAMP())')
        ->execute([mb_substr((string)($msg['MessageId'] ?? ''), 0, 120), json_encode(['topic' => mb_substr((string)($msg['TopicArn'] ?? ''), 0, 200), 'confirmed' => $ok])]);
    app_log('info', 'SNS-Abonnement ' . ($ok ? 'bestaetigt' : 'NICHT bestaetigt'), ['topic' => mb_substr((string)($msg['TopicArn'] ?? ''), 0, 120)]);
    http_response_code($ok ? 200 : 502);
    exit($ok ? 'subscribed' : 'subscribe failed');
}
if ($type === 'UnsubscribeConfirmation') {
    http_response_code(200);
    exit('ignored');
}
if ($type !== 'Notification') {
    http_response_code(400);
    exit('unknown type');
}
$ev = json_decode((string)($msg['Message'] ?? ''), true);
if (!is_array($ev)) {
    http_response_code(400);
    exit('invalid message');
}
try {
    $r = marketing_ses_handle($ev);
} catch (Throwable $e) {
    app_log('error', 'SES-Ereignis nicht verarbeitet', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('error');
}
http_response_code(200);
echo $r['type'] . ' ' . count($r['suppressed']) . ' suppressed';
