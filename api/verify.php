<?php
/**
 * api/verify.php
 * Verifies a Razorpay payment signature and marks the order as paid.
 *
 * Upload next to orders.php (i.e. /api/verify.php).
 * Requires the same config.php, which must define RAZORPAY_KEY_SECRET.
 *
 * Request  (JSON): { order_id, razorpay_order_id, razorpay_payment_id, razorpay_signature }
 * Response (JSON): { ok: true, id: 12 }  or  { ok: false, error: "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/razorpay.php')) {
    require_once __DIR__ . '/razorpay.php';
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function verify_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    verify_fail(405, 'Invalid method');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    verify_fail(400, 'Invalid request body');
}

$orderId    = (int)($data['order_id'] ?? 0);
$rzpOrderId = trim((string)($data['razorpay_order_id'] ?? ''));
$paymentId  = trim((string)($data['razorpay_payment_id'] ?? ''));
$signature  = trim((string)($data['razorpay_signature'] ?? ''));

if ($orderId < 1 || $rzpOrderId === '' || $paymentId === '' || $signature === '') {
    verify_fail(422, 'Missing payment details');
}

if (strlen($rzpOrderId) > 100 || strlen($paymentId) > 100 || strlen($signature) > 256) {
    verify_fail(422, 'Invalid payment details');
}

$secret = '';
foreach (['RAZORPAY_KEY_SECRET', 'RAZORPAY_SECRET', 'RZP_KEY_SECRET', 'RAZORPAY_SECRET_KEY'] as $constant) {
    if (defined($constant) && trim((string)constant($constant)) !== '') {
        $secret = trim((string)constant($constant));
        break;
    }
}
if ($secret === '') {
    $fromEnv = getenv('RAZORPAY_KEY_SECRET');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        $secret = trim($fromEnv);
    }
}
if ($secret === '') {
    verify_fail(500, 'Razorpay secret is not configured');
}

try {
    $db = db();
    $lookup = $db->prepare('SELECT id, rzp_order_id, payment_status FROM orders WHERE id = ? LIMIT 1');
    $lookup->execute([$orderId]);
    $order = $lookup->fetch();
} catch (Throwable $error) {
    verify_fail(500, 'Could not read the order');
}

if (!$order) {
    verify_fail(404, 'Order not found');
}

if (!hash_equals((string)$order['rzp_order_id'], $rzpOrderId)) {
    verify_fail(409, 'Payment does not belong to this order');
}

$expected = hash_hmac('sha256', $rzpOrderId . '|' . $paymentId, $secret);
if (!hash_equals($expected, $signature)) {
    verify_fail(400, 'Payment signature verification failed');
}

if (($order['payment_status'] ?? '') === 'paid') {
    echo json_encode(['ok' => true, 'id' => $orderId, 'already' => true]);
    exit;
}

try {
    $update = $db->prepare(
        "UPDATE orders
            SET payment_status = 'paid',
                rzp_payment_id = ?,
                status = CASE WHEN status = 'New' THEN 'Confirmed' ELSE status END
          WHERE id = ? AND payment_status <> 'paid'"
    );
    $update->execute([$paymentId, $orderId]);
} catch (Throwable $error) {
    verify_fail(500, 'Payment verified but the order could not be updated');
}

echo json_encode(['ok' => true, 'id' => $orderId]);
