<?php
// Shipfigo API helper: https://shipfigo.com/panel/custom/api/v1
function sf_cut(string $s, int $n = 250): string {
    return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : '';
}

function sf_call(string $path, array $body = [], ?string $token = null): array {
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: ' . $token;

    $ch = curl_init(SHIPFIGO_BASE . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body ?: new stdClass()),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Shipfigo not reachable: ' . $err);
    return [$code, json_decode($raw, true) ?: []];
}

function sf_error(array $d, int $code): string {
    $msg = $d['meta']['message'] ?? $d['message'] ?? ('HTTP ' . $code);
    if (!empty($d['errors']) && is_array($d['errors'])) {
        $parts = [];
        foreach ($d['errors'] as $k => $v) $parts[] = $k . ': ' . (is_array($v) ? implode(' ', $v) : $v);
        $msg .= ' (' . implode('; ', $parts) . ')';
    }
    return $msg;
}

function sf_token(): string {
    [$code, $d] = sf_call('auth-token', ['username' => SHIPFIGO_USER, 'password' => SHIPFIGO_PASS]);
    if ($code !== 200 || empty($d['token'])) throw new RuntimeException('Shipfigo login failed: ' . sf_error($d, $code));
    return $d['token'];
}

function sf_create_order(array $o): string {
    $token = sf_token();
    $name  = preg_split('/\s+/', trim($o['name']), 2);
    $prepaid = ($o['payment_method'] ?? 'COD') === 'ONLINE';   // paid online = PREPAID, otherwise cash on delivery

    [$code, $d] = sf_call('forward-order', [
        'order_id'              => 'ANU-' . $o['id'],
        'order_date'            => date('Y-m-d H:i'),
        'billing_customer_name' => $name[0],
        'billing_last_name'     => $name[1] ?? '',
        'billing_address'       => $o['address'],
        'billing_city'          => $o['city'],
        'billing_pincode'       => $o['pincode'],
        'billing_state'         => $o['state'],
        'billing_country'       => 'India',
        'billing_phone'         => $o['phone'],
        'shipping_is_billing'   => true,
        'order_items'           => [[
            'name' => 'Anuarsh Kit', 'sku' => 'ANUARSH-KIT',
            'units' => (int)$o['qty'], 'selling_price' => PRICE, 'discount' => 0, 'tax' => 0,
        ]],
        'payment_method'        => $prepaid ? 'PREPAID' : 'COD',
        'sub_total'             => (int)$o['total'],
        'cod_collectible'       => $prepaid ? 0 : (int)$o['total'],
        'length'                => SHIP_LENGTH_CM,
        'breadth'               => SHIP_BREADTH_CM,
        'height'                => SHIP_HEIGHT_CM,
        'weight'                => SHIP_WEIGHT_KG * (int)$o['qty'],
        'pickup_location'       => SHIPFIGO_PICKUP,
        'warehouse_id'          => SHIPFIGO_WAREHOUSE_ID,
    ], $token);

    if ($code !== 200 || (int)($d['status'] ?? 0) !== 1 || empty($d['payload']['shipment_id'])) {
        throw new RuntimeException(sf_error($d, $code));
    }
    return (string)$d['payload']['shipment_id'];
}

function sf_send(PDO $db, int $id): string {
    $st = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) throw new RuntimeException('Order not found');
    if (!empty($o['ship_shipment_id'])) return 'already sent to Shipfigo';
    if (($o['payment_method'] ?? 'COD') === 'ONLINE' && $o['payment_status'] !== 'paid') {
        throw new RuntimeException('Online payment not received yet');
    }

    try {
        $sid = sf_create_order($o);
        $db->prepare("UPDATE orders SET ship_shipment_id = ?, ship_status = 'created', ship_error = NULL WHERE id = ?")
           ->execute([$sid, $id]);
        return 'sent to Shipfigo (shipment ' . $sid . ')';
    } catch (Throwable $e) {
        $db->prepare('UPDATE orders SET ship_error = ? WHERE id = ?')->execute([sf_cut($e->getMessage()), $id]);
        throw $e;
    }
}

function sf_refresh(PDO $db, int $id): string {
    $key = 'ANU-' . $id;
    [$code, $d] = sf_call('order-tracking-by-order-id', ['order_ids' => [$key]], sf_token());
    if ($code !== 200) throw new RuntimeException(sf_error($d, $code));

    $t = $d['result'][$key]['tracking_data'] ?? null;
    if (!$t) return 'no tracking data yet';

    $status = (string)($t['shipment_status'] ?? '');
    $awb    = $t['shipment_track'][0]['awb_code'] ?? null;
    $url    = $t['track_url'] ?? null;
    $db->prepare('UPDATE orders SET ship_status = ?, awb = ?, track_url = ? WHERE id = ?')
       ->execute([sf_cut($status, 30), $awb, $url, $id]);
    return 'status: ' . ($status ?: 'unknown');
}

// Shipfigo status name -> the status shown in the admin (null = leave the admin status as it is)
function sf_map_status(string $s): ?string {
    $s = str_replace('-', '_', strtolower(trim($s)));
    return [
        'ready_for_pickup'  => 'Confirmed',
        'pickup_scheduled'  => 'Confirmed',
        'in_transit'        => 'Shipped',
        'out_for_delivery'  => 'Shipped',
        'rto_in_transit'    => 'Shipped',
        'delivered'         => 'Delivered',
        'cancelled'         => 'Cancelled',
    ][$s] ?? null;
}

// Update the shipping status of all open orders with ONE Shipfigo call (9b, up to 50 orders).
function sf_sync_all(PDO $db): string {
    $ids = $db->query(
        "SELECT id FROM orders
         WHERE ship_shipment_id IS NOT NULL AND (ship_status IS NULL OR ship_status NOT IN ('delivered', 'cancelled'))
         ORDER BY id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return 'nothing to update';

    $keys = array_map(fn($i) => 'ANU-' . $i, $ids);
    [$code, $d] = sf_call('order-tracking-by-order-id', ['order_ids' => $keys], sf_token());
    if ($code !== 200) throw new RuntimeException(sf_error($d, $code));

    $up = $db->prepare('UPDATE orders SET ship_status = ?, awb = ?, track_url = ?, status = COALESCE(?, status) WHERE id = ?');
    $updated = 0;
    foreach ($ids as $i) {
        $t = $d['result']['ANU-' . $i]['tracking_data'] ?? null;
        $status = $t ? str_replace('-', '_', strtolower((string)($t['shipment_status'] ?? ''))) : '';
        if ($status === '') continue;
        $up->execute([
            sf_cut($status, 30),
            $t['shipment_track'][0]['awb_code'] ?? null,
            $t['track_url'] ?? null,
            sf_map_status($status),
            $i,
        ]);
        $updated++;
    }
    return 'updated ' . $updated . ' of ' . count($ids) . ' orders';
}