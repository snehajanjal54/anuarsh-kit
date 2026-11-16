<?php
require_once __DIR__ . '/config.php';

function rzp_create_order(int $totalRupees, string $receipt): string {
    if (RAZORPAY_KEY_SECRET === 'PASTE_YOUR_REAL_SECRET_HERE' || RAZORPAY_KEY_SECRET === '') {
        throw new Exception('Secret missing in config.php');
    }
    $ch = curl_init(RAZORPAY_API . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['amount'=>$totalRupees*100,'currency'=>'INR','receipt'=>$receipt,'payment_capture'=>1]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    if ($res === false) { $e=curl_error($ch); curl_close($ch); throw new Exception('cURL: '.$e); }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if ($code !== 200 && $code !== 201) throw new Exception('Razorpay API '.$code.': '.($data['error']['description'] ?? $res));
    if (empty($data['id'])) throw new Exception('No order id: '.$res);
    return $data['id'];
}

function rzp_verify_signature(string $oid, string $pid, string $sig): bool {
    return hash_equals(hash_hmac('sha256', $oid.'|'.$pid, RAZORPAY_KEY_SECRET), $sig);
}