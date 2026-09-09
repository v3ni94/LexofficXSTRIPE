<?php
/**
 * Lokaler HTTP-Stub der sevdesk-API fuer tools/sevdesk-check.sh (php -S). Liefert aufgezeichnete, anonymisierte
 * Antwortformen nach dem Endpunktregister in docs/sevdesk.md (Sekundaerquellen, nicht die offizielle API).
 * Verhalten wird ueber den Token gesteuert: TOKEN-OK normal, TOKEN-401 verweigert, TOKEN-429 Drosselung,
 * TOKEN-500 Serverfehler, TOKEN-HTML unerwartete Antwort. Der Pfad /Invoice/... usw. wird ohne Basis ausgewertet.
 */
declare(strict_types=1);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/api/v1#', '', $path);
header('Content-Type: application/json');
$log = getenv('SEVDESK_STUB_LOG');
if ($log) {
    file_put_contents($log, json_encode(['path' => $path, 'query' => $_GET, 'auth_prefix' => substr($auth, 0, 6), 'x_version' => $_SERVER['HTTP_X_VERSION'] ?? null]) . "\n", FILE_APPEND);
}
if ($auth === 'TOKEN-401') { http_response_code(401); echo json_encode(['error' => ['message' => 'Unauthorized']]); exit; }
if ($auth === 'TOKEN-429') { http_response_code(429); echo json_encode(['error' => ['message' => 'Too many requests']]); exit; }
if ($auth === 'TOKEN-500') { http_response_code(500); echo json_encode(['error' => ['message' => 'Server error']]); exit; }
if ($auth === 'TOKEN-HTML') { header('Content-Type: text/html'); echo '<html>Wartung</html>'; exit; }
if ($auth !== 'TOKEN-OK') { http_response_code(403); echo json_encode(['error' => ['message' => 'Forbidden']]); exit; }

$today = new DateTimeImmutable('today');
$contacts = [
    '100' => ['id' => '100', 'objectName' => 'Contact', 'name' => 'Musterfirma GmbH', 'surename' => null, 'familyname' => null, 'customerNumber' => '20001'],
    '101' => ['id' => '101', 'objectName' => 'Contact', 'name' => null, 'surename' => 'Erika', 'familyname' => 'Beispiel', 'customerNumber' => '20002'],
    '102' => ['id' => '102', 'objectName' => 'Contact', 'name' => 'Ohne Nummer e. K.', 'surename' => null, 'familyname' => null, 'customerNumber' => null],
];
$invoices = [
    // offen, faellig in 10 Tagen (payDate), Typ RE
    '5001' => ['id' => '5001', 'objectName' => 'Invoice', 'invoiceNumber' => 'RE-2026-001', 'invoiceType' => 'RE', 'status' => '200', 'invoiceDate' => $today->modify('-4 days')->format('Y-m-d H:i:s'),
               'payDate' => $today->modify('+10 days')->format('Y-m-d H:i:s'), 'sumGross' => '119.00', 'paidAmount' => '0', 'currency' => 'EUR', 'update' => '2026-09-01 10:00:00', 'contact' => ['id' => '100', 'objectName' => 'Contact']],
    // offen, ueberfaellig (invoiceDate + timeToPay in der Vergangenheit), Person
    '5002' => ['id' => '5002', 'objectName' => 'Invoice', 'invoiceNumber' => 'RE-2026-002', 'invoiceType' => 'RE', 'status' => 200, 'invoiceDate' => $today->modify('-40 days')->format('Y-m-d H:i:s'),
               'timeToPay' => 14, 'sumGross' => 250.5, 'paidAmount' => 0, 'currency' => 'EUR', 'update' => '2026-09-02 10:00:00', 'contact' => ['id' => '101', 'objectName' => 'Contact']],
    // Mahnung (Typ MA): darf nicht in der Liste erscheinen
    '5003' => ['id' => '5003', 'objectName' => 'Invoice', 'invoiceNumber' => 'MA-2026-003', 'invoiceType' => 'MA', 'status' => 200, 'invoiceDate' => $today->format('Y-m-d H:i:s'), 'sumGross' => '10.00', 'currency' => 'EUR', 'contact' => ['id' => '100', 'objectName' => 'Contact']],
    // teilbezahlt (750)
    '5004' => ['id' => '5004', 'objectName' => 'Invoice', 'invoiceNumber' => 'RE-2026-004', 'invoiceType' => 'RE', 'status' => 750, 'invoiceDate' => $today->modify('-10 days')->format('Y-m-d H:i:s'),
               'payDate' => $today->modify('+5 days')->format('Y-m-d H:i:s'), 'sumGross' => '300.00', 'paidAmount' => '100.00', 'currency' => 'EUR', 'update' => '2026-09-03 10:00:00', 'contact' => ['id' => '102', 'objectName' => 'Contact']],
    // bezahlt (1000): nur per Detail erreichbar
    '5005' => ['id' => '5005', 'objectName' => 'Invoice', 'invoiceNumber' => 'RE-2026-005', 'invoiceType' => 'RE', 'status' => 1000, 'invoiceDate' => $today->modify('-30 days')->format('Y-m-d H:i:s'), 'sumGross' => '50.00', 'paidAmount' => '50.00', 'currency' => 'EUR', 'contact' => ['id' => '100', 'objectName' => 'Contact']],
    // Entwurf (100)
    '5006' => ['id' => '5006', 'objectName' => 'Invoice', 'invoiceNumber' => 'RE-2026-006', 'invoiceType' => 'RE', 'status' => 100, 'invoiceDate' => $today->format('Y-m-d H:i:s'), 'sumGross' => '1.00', 'currency' => 'EUR', 'contact' => ['id' => '100', 'objectName' => 'Contact']],
    // offen ohne paidAmount-Feld (Feld fehlt): Restbetrag darf auch mit Freigabe null bleiben
    '5007' => ['id' => '5007', 'objectName' => 'Invoice', 'invoiceNumber' => 'RE-2026-007', 'invoiceType' => 'RE', 'status' => 200, 'invoiceDate' => $today->format('Y-m-d H:i:s'), 'sumGross' => '80.00', 'currency' => 'EUR', 'contact' => ['id' => '100', 'objectName' => 'Contact']],
];
$positions = [
    '5001' => [['id' => '1', 'objectName' => 'InvoicePos', 'name' => 'Beratung September', 'text' => 'Projekt Alpha', 'quantity' => 2, 'price' => 50, 'taxRate' => 19]],
    '5002' => [['id' => '2', 'objectName' => 'InvoicePos', 'name' => 'Wartung', 'text' => '', 'quantity' => 1, 'price' => 210.5, 'taxRate' => 19]],
];
$out = static function (array $objects, ?int $total = null): void {
    echo json_encode(['objects' => $objects] + ($total !== null ? ['total' => (string)$total] : []));
    exit;
};
if ($path === '/Contact') {
    $limit = (int)($_GET['limit'] ?? 100);
    $out(array_slice(array_values($contacts), 0, $limit), count($contacts));
}
if (preg_match('#^/Contact/(\d+)$#', $path, $m)) {
    if (!isset($contacts[$m[1]])) { http_response_code(404); echo '{}'; exit; }
    $out([$contacts[$m[1]]]);
}
if ($path === '/CommunicationWay') {
    $cid = (string)($_GET['contact']['id'] ?? '');
    $mails = ['100' => ['rechnung@musterfirma.test', 'zweite@musterfirma.test'], '101' => ['erika.beispiel@example.test']];
    $rows = [];
    foreach ($mails[$cid] ?? [] as $i => $mail) {
        $rows[] = ['id' => (string)(900 + $i), 'objectName' => 'CommunicationWay', 'type' => 'EMAIL', 'value' => $mail, 'communicationWayKey' => ['id' => '2']];
    }
    if (($_GET['type'] ?? '') !== 'EMAIL') { $rows[] = ['id' => '999', 'objectName' => 'CommunicationWay', 'type' => 'PHONE', 'value' => '+49 000', 'communicationWayKey' => ['id' => '2']]; }
    $out($rows);
}
if ($path === '/Invoice') {
    $status = (int)($_GET['status'] ?? 0);
    $limit = max(1, (int)($_GET['limit'] ?? 100));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $rows = array_values(array_filter($invoices, static fn(array $i): bool => (int)$i['status'] === $status));
    if (getenv('SEVDESK_STUB_MANY') && $status === 200) {
        // Seitennavigation: 205 offene Rechnungen erzeugen
        $rows = [];
        for ($i = 1; $i <= 205; $i++) {
            $rows[] = ['id' => (string)(7000 + $i), 'objectName' => 'Invoice', 'invoiceNumber' => 'RE-M-' . $i, 'invoiceType' => 'RE', 'status' => 200, 'invoiceDate' => $today->format('Y-m-d H:i:s'), 'sumGross' => '1.00', 'currency' => 'EUR', 'update' => '2026-09-01 00:00:00'];
        }
    }
    $total = count($rows);
    $out(array_slice($rows, $offset, $limit), isset($_GET['countAll']) ? $total : null);
}
if (preg_match('#^/Invoice/(\d+)$#', $path, $m)) {
    if (!isset($invoices[$m[1]])) { http_response_code(404); echo '{}'; exit; }
    $inv = $invoices[$m[1]];
    if (($_GET['embed'] ?? '') === 'contact' && isset($contacts[$inv['contact']['id']])) {
        $inv['contact'] = $contacts[$inv['contact']['id']];
    }
    $out([$inv]);
}
if ($path === '/InvoicePos') {
    $iid = (string)($_GET['invoice']['id'] ?? '');
    $out($positions[$iid] ?? []);
}
http_response_code(404);
echo json_encode(['error' => ['message' => 'unknown path ' . $path]]);
