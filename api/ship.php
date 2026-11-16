<?php
// Called by the website right after an order is saved. Sends that one order to Shipfigo.
require __DIR__ . '/config.php';
require __DIR__ . '/shipfigo.php';

header('Content-Type: application/json; charset=utf-8');
ignore_user_abort(true);   // keep going even if the visitor's page has already moved on
set_time_limit(60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || SHIPFIGO_USER === '') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$d  = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($d['id'] ?? 0);
if ($id < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $db = db();

    // Only orders that were never sent and never failed (failed ones are retried from the admin page),
    // and online orders only after they are paid.
    $st = $db->prepare("SELECT COUNT(*) FROM orders WHERE id = ? AND ship_shipment_id IS NULL AND ship_error IS NULL AND (payment_method = 'COD' OR payment_status = 'paid')");
    $st->execute([$id]);
    if (!$st->fetchColumn()) {
        echo json_encode(['ok' => true, 'skipped' => true]);
        exit;
    }

    sf_send($db, $id);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('Shipfigo Error [Order ' . $id . ']: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}