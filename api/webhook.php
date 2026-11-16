<?php
require __DIR__ . '/config.php';

http_response_code(200);
echo json_encode(['ok' => true]);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!$payload || empty($payload['data'])) exit;

$data = $payload['data'];
$orderRef = $data['order_external_id'] ?? $data['order_id'] ?? '';

if (!preg_match('/^ANU-(\d+)$/', $orderRef, $m)) exit;
$id = (int)$m[1];

try {
    $db = db();
    $status = substr((string)($data['status'] ?? ''), 0, 30);
    $awb    = $data['tracking_number'] ?? null;
    $url    = $data['tracking_url'] ?? null;

    $db->prepare("
        UPDATE orders
        SET ship_status = ?, awb = COALESCE(?, awb), track_url = COALESCE(?, track_url)
        WHERE id = ?
    ")->execute([$status, $awb ?: null, $url ?: null, $id]);

} catch (Throwable $e) {
    error_log('Webhook error: ' . $e->getMessage());
}