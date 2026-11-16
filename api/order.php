<?php
/**
 * api/orders.php
 * Creates an order and, for online payment, a Razorpay order.
 *
 * Folder layout expected on the server:
 *   /index.html
 *   /thank-you.html
 *   /api/config.php
 *   /api/razorpay.php
 *   /api/orders.php   <- this file
 *   /api/verify.php
 *
 * Request  (JSON): { name, phone, address, city, state, pincode, qty, payment: "online"|"cod", website }
 * Response (JSON): { ok: true, id: 12 }                       for COD
 *                  { ok: true, id: 12, online: true, rzp_order_id, key_id, amount } for online
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/razorpay.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (!defined('PRICE')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server price is not configured. Please call us to order.']);
    exit;
}

function orders_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    orders_fail(405, 'Invalid method');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    orders_fail(400, 'Invalid request body');
}

// Honeypot: pretend success to bots, create nothing.
if (!empty($data['website'])) {
    echo json_encode(['ok' => true, 'id' => 0]);
    exit;
}

$name  = trim((string)($data['name'] ?? ''));
$phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
$addr  = trim((string)($data['address'] ?? ''));
$city  = trim((string)($data['city'] ?? ''));
$state = trim((string)($data['state'] ?? ''));
$pin   = trim((string)($data['pincode'] ?? ''));

$qty   = max(1, min(10, (int)($data['qty'] ?? 1)));
$total = $qty * (int)PRICE;
$online = ((string)($data['payment'] ?? 'cod')) === 'online';

$keyId = '';
foreach (['RAZORPAY_KEY_ID', 'RAZORPAY_KEY', 'RZP_KEY_ID'] as $constant) {
    if (defined($constant) && trim((string)constant($constant)) !== '') {
        $keyId = trim((string)constant($constant));
        break;
    }
}
if ($keyId === '') {
    $fromEnv = getenv('RAZORPAY_KEY_ID');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        $keyId = trim($fromEnv);
    }
}
if ($online && $keyId === '') {
    orders_fail(500, 'Online payment is not configured yet. Please choose Cash on delivery or call us.');
}

if (
    $name === '' ||
    strlen($name) > 100 ||
    !preg_match('/^[6-9]\d{9}$/', $phone) ||
    $addr === '' ||
    strlen($addr) > 500 ||
    $city === '' ||
    $state === '' ||
    !preg_match('/^\d{6}$/', $pin)
) {
    orders_fail(422, 'Please enter name, valid 10-digit mobile, address, city, state, 6-digit pincode.');
}

try {
    $db = db();
    $db->prepare(
        'INSERT INTO orders (name, phone, address, city, state, pincode, qty, total, payment_method, payment_status)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $name,
        $phone,
        $addr,
        $city,
        $state,
        $pin,
        $qty,
        $total,
        $online ? 'ONLINE' : 'COD',
        $online ? 'pending' : 'cod',
    ]);
    $id = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log('orders.php DB error: ' . $e->getMessage());
    orders_fail(500, 'We could not save your order. Please try again or call us.');
}

if (!$online) {
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

try {
    $rzpOrderId = rzp_create_order($total, 'ANU-' . $id);
    $db->prepare('UPDATE orders SET rzp_order_id = ? WHERE id = ?')->execute([$rzpOrderId, $id]);
} catch (Throwable $e) {
    error_log('orders.php Razorpay error: ' . $e->getMessage());
    try {
        $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
    } catch (Throwable $cleanupError) {
        error_log('orders.php cleanup error: ' . $cleanupError->getMessage());
    }
    orders_fail(502, 'Online payment could not be started. Please choose Cash on delivery or call us.');
}

echo json_encode([
    'ok' => true,
    'id' => $id,
    'online' => true,
    'rzp_order_id' => $rzpOrderId,
    'key_id' => $keyId,
    'amount' => $total * 100,
]);
