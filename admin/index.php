<?php
// Admin panel: login, view orders, change status, delete, export CSV.
require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/shipfigo.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();
header('X-Robots-Tag: noindex, nofollow');

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$or = fn($v) => ($v === null || $v === '') ? '–' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$statuses = ['New', 'Confirmed', 'Shipped', 'Delivered', 'Cancelled'];

/* ---------- Logout ---------- */
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

/* ---------- Login (username + password are stored in the `admins` table) ---------- */
$err = '';
if (isset($_POST['login'])) {
    $user = trim((string)($_POST['u'] ?? ''));
    $hash = false;
    try {
        $st = db()->prepare('SELECT password_hash FROM admins WHERE username = ?');
        $st->execute([$user]);
        $hash = $st->fetchColumn();
    } catch (Throwable $x) {
        $err = 'Cannot read the admins table. Check api/config.php and run the admins SQL.';
    }
    if ($hash && password_verify((string)($_POST['p'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['ok'] = 1;
        $_SESSION['u'] = $user;
        $_SESSION['t'] = bin2hex(random_bytes(16));
        header('Location: ./');
        exit;
    }
    if (!$err) {
        sleep(1);
        $err = 'Wrong username or password.';
    }
}
$in = !empty($_SESSION['ok']);

/* ---------- Everything below runs only when logged in ---------- */
if ($in) {
    $db = db();

    /* ---------- Actions (POST + CSRF token) ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals((string)($_SESSION['t'] ?? ''), (string)($_POST['t'] ?? ''))) {

        // Change password
        if (isset($_POST['newpass'])) {
            $st = $db->prepare('SELECT password_hash FROM admins WHERE username = ?');
            $st->execute([$_SESSION['u'] ?? '']);
            $new = (string)($_POST['new'] ?? '');

            if (!password_verify((string)($_POST['old'] ?? ''), (string)$st->fetchColumn())) {
                $_SESSION['msg'] = 'Current password is wrong.';
            } elseif (strlen($new) < 8) {
                $_SESSION['msg'] = 'New password must be at least 8 characters.';
            } else {
                $db->prepare('UPDATE admins SET password_hash = ? WHERE username = ?')
                   ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['u']]);
                $_SESSION['msg'] = 'Password changed.';
            }
            header('Location: ./');
            exit;
        }

        // Sync shipping status from Shipfigo now
        if (isset($_POST['sync'])) {
            try {
                $_SESSION['msg'] = 'Shipfigo: ' . sf_sync_all($db);
                $_SESSION['synced'] = time();
            } catch (Throwable $x) {
                $_SESSION['msg'] = 'Shipfigo: ' . $x->getMessage();
            }
            header('Location: ./');
            exit;
        }

        // Change order status / delete order
        $id = (int)($_POST['id'] ?? 0);

        // Shipfigo: send an order / refresh its shipping status
        if (isset($_POST['send']) || isset($_POST['refresh'])) {
            try {
                $_SESSION['msg'] = 'Order #' . $id . ': ' . (isset($_POST['send']) ? sf_send($db, $id) : sf_refresh($db, $id));
            } catch (Throwable $x) {
                $_SESSION['msg'] = 'Shipfigo: ' . $x->getMessage();
            }
            header('Location: ' . ($_POST['back'] ?? './'));
            exit;
        }
        if (isset($_POST['status']) && in_array($_POST['status'], $statuses, true)) {
            $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
        }
        if (isset($_POST['delete'])) {
            $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        }
        header('Location: ' . ($_POST['back'] ?? './'));
        exit;
    }

    /* ---------- Auto-sync shipping status (at most once every 5 minutes) ---------- */
    if (SHIPFIGO_USER !== '' && !isset($_GET['csv']) && time() - (int)($_SESSION['synced'] ?? 0) > 300) {
        $_SESSION['synced'] = time();
        try {
            sf_sync_all($db);
        } catch (Throwable $x) {
            // ignore here; the "Sync now" button shows the error
        }
    }

    /* ---------- Load orders (filter + search) ---------- */
    $f = in_array($_GET['s'] ?? '', $statuses, true) ? $_GET['s'] : '';
    $q = trim($_GET['q'] ?? '');

    $sql  = 'SELECT * FROM orders WHERE 1';
    $args = [];
    if ($f) {
        $sql .= ' AND status = ?';
        $args[] = $f;
    }
    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR phone LIKE ? OR pincode LIKE ?)';
        array_push($args, "%$q%", "%$q%", "%$q%");
    }
    $sql .= ' ORDER BY id DESC LIMIT 1000';

    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    /* ---------- CSV export ---------- */
    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Date', 'Name', 'Phone', 'Address', 'City', 'State', 'Pincode', 'Qty', 'Total', 'Payment', 'Note', 'Status', 'Shipping', 'AWB']);
        $safe = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['created_at'], $safe($r['name']), $r['phone'],
                $safe($r['address']), $safe($r['city']), $safe($r['state']), $r['pincode'], $r['qty'], $r['total'],
                $r['payment_method'] . ' ' . $r['payment_status'],
                $safe($r['note']), $r['status'], $r['ship_status'], $r['awb'],
            ]);
        }
        exit;
    }

    /* ---------- Counts for the filter tabs ---------- */
    $counts = array_column(
        $db->query('SELECT status, COUNT(*) c FROM orders GROUP BY status')->fetchAll(),
        'c',
        'status'
    );
    $all  = array_sum($counts);
    $back = $e($_SERVER['REQUEST_URI']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Orders | Anuarsh Admin</title>
 <style>
* { box-sizing: border-box; }

body {
  font-family: system-ui, sans-serif;
  margin: 0;
  background: #f3f5f1;
  color: #1c2a22;
}

/* TOP BAR */
.top {
  background: #0e3b2e;
  color: #d4af37;
  padding: 16px 22px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.top a {
  color: #fff;
  margin-left: 14px;
}

.wrap {
  padding: 20px;
  max-width: 1300px;
  margin: auto;
}

/* LOGIN */
.login {
  max-width: 340px;
  margin: 80px auto;
  background: #fff;
  padding: 26px;
  border-radius: 14px;
  box-shadow: 0 6px 24px rgba(0,0,0,.1);
}

.login input {
  width: 100%;
  margin: 6px 0 12px;
}

/* INPUTS */
input, select, button {
  font: inherit;
  padding: 10px 12px;
  border: 1px solid #cfd8cf;
  border-radius: 8px;
}

button {
  cursor: pointer;
  background: #0e3b2e;
  color: #fff;
  border: 0;
  font-weight: 600;
  transition: .2s;
}

button:hover {
  background: #195640;
}

summary {
  cursor: pointer;
}

.box {
  margin-bottom: 14px;
}

.box form {
  margin-top: 8px;
}

/* MESSAGES */
.err {
  color: #b3261e;
}

.msg {
  background: #ebf1e6;
  padding: 12px 15px;
  border-radius: 8px;
  border-left: 4px solid #0e3b2e;
}

/* STATUS TABS */
.stats {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}

.stats a {
  background: #fff;
  padding: 11px 17px;
  border-radius: 10px;
  text-decoration: none;
  color: #0e3b2e;
  border: 2px solid transparent;
  box-shadow: 0 2px 8px rgba(0,0,0,.04);
}

.stats a.on {
  border-color: #d4af37;
  background: #fffdf5;
}

/* ORDERS TABLE */
.tw {
  overflow-x: auto;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 3px 14px rgba(0,0,0,.05);
}

table {
  border-collapse: collapse;
  width: 100%;
  font-size: 14px;
}

th, td {
  padding: 12px 10px;
  border-bottom: 1px solid #e6ece6;
  text-align: left;
  vertical-align: top;
}

th {
  background: #ebf1e6;
  white-space: nowrap;
}

.s-New {
  background: #fffdf5;
}

.s-Cancelled {
  opacity: .65;
}

/* DETAILS */
.vd summary {
  cursor: pointer;
  color: #0e3b2e;
  font-weight: 700;
  padding: 5px 0;
}

.vd dl {
  margin: 8px 0 0;
  padding: 12px;
  background: #f6f9f4;
  border-radius: 8px;
  min-width: 260px;
  font-size: 13px;
}

.vd dt {
  font-weight: 700;
  color: #5b6b5f;
  margin-top: 10px;
}

.vd dt:first-child {
  margin-top: 0;
}

.vd dd {
  margin: 0;
  word-break: break-word;
}

.del {
  background: #b3261e;
  padding: 8px 12px;
}

.del:hover {
  background: #8f1c17;
}

/* MOBILE UI */
@media (max-width: 760px) {

  body {
    background: #f0f3ee;
  }

  .wrap {
    padding: 12px;
  }

  /* HEADER */
  .top {
    padding: 15px;
    gap: 12px;
  }

  .top b {
    font-size: 17px;
  }

  .top span {
    display: flex;
    gap: 16px;
  }

  .top a {
    margin: 0;
    font-size: 14px;
  }

  /* LOGIN */
  .login {
    margin: 45px 16px;
    max-width: none;
    padding: 24px;
  }

  /* STATUS TABS */
  .stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }

  .stats a {
    padding: 11px 9px;
    text-align: center;
    font-size: 13px;
    border-radius: 10px;
  }

  /* FORMS */
  .box {
    background: #fff;
    padding: 13px;
    border-radius: 12px;
    margin-bottom: 12px;
  }

  .box input:not([type="hidden"]),
  .box select {
    width: 100%;
    margin-bottom: 9px;
  }

  .box button {
    width: 100%;
    min-height: 44px;
  }

  input, select, button {
    font-size: 16px;
  }

  /* ORDER CARDS */
  .tw {
    background: transparent;
    overflow: visible;
    box-shadow: none;
  }

  table,
  tbody,
  tr,
  td {
    display: block;
    width: 100%;
  }

  tr:first-child {
    display: none;
  }

  tr {
    background: #fff;
    border-radius: 15px;
    margin-bottom: 15px;
    padding: 10px 0;
    box-shadow: 0 3px 13px rgba(0,0,0,.06);
    border: 1px solid #e7ece5;
    overflow: hidden;
  }

  td {
    border: 0;
    padding: 9px 15px;
    font-size: 14px;
    line-height: 1.5;
    overflow-wrap: anywhere;
  }

  td[data-label]::before {
    content: attr(data-label);
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #718075;
    margin-bottom: 4px;
  }

  /* CUSTOMER */
  td:nth-child(3) {
    background: #f7faf5;
    padding: 14px 15px;
    font-size: 15px;
  }

  td:nth-child(3) b {
    font-size: 17px;
    color: #0e3b2e;
  }

  td:nth-child(3) a {
    display: inline-block;
    margin-top: 5px;
    color: #176bba;
    font-weight: 600;
  }

  /* ADDRESS */
  td:nth-child(4) {
    line-height: 1.7;
  }

  /* PRICE */
  td:nth-child(5) {
    background: #f6f9f4;
    font-size: 14px;
  }

  td:nth-child(5) b {
    color: #0e3b2e;
    font-size: 19px;
  }

  /* DETAILS */
  .vd {
    border-top: 1px solid #e8eee7;
    padding-top: 5px;
  }

  .vd summary {
    padding: 10px 0;
    font-size: 14px;
  }

  .vd dl {
    min-width: 0;
    padding: 13px;
    font-size: 13px;
  }

  /* SHIPPING */
  td:nth-child(7) {
    background: #f7faf5;
    border-top: 1px solid #e8eee7;
    margin-top: 5px;
  }

  td:nth-child(7) form {
    margin-top: 10px;
  }

  td:nth-child(7) button {
    width: 100%;
    min-height: 45px;
  }

  /* DELETE */
  td:last-child {
    padding: 12px 15px;
  }

  td .del {
    width: 100%;
    min-height: 45px;
    border-radius: 9px;
  }

  /* MESSAGE */
  .msg {
    font-size: 14px;
    overflow-wrap: anywhere;
  }

}

/* SMALL MOBILE */
@media (max-width: 380px) {

  .top b {
    font-size: 15px;
  }

  .stats a {
    font-size: 12px;
    padding: 10px 5px;
  }

  td {
    padding-left: 12px;
    padding-right: 12px;
  }

}
</style>
</head>
<body>

<?php if (!$in): ?>

  <form class="login" method="post">
    <h2>Admin login</h2>
    <?php if ($err): ?><p class="err"><?= $e($err) ?></p><?php endif; ?>
    <label>Username <input name="u" autocomplete="username" required></label>
    <label>Password <input name="p" type="password" autocomplete="current-password" required></label>
    <button name="login" value="1">Login</button>
  </form>

<?php else: ?>

  <div class="top">
    <b>Anuarsh Kit of 5 – Orders</b>
    <span>
      <a href="?csv=1<?= $f ? '&s=' . $e($f) : '' ?>">Download CSV</a>
      <a href="?logout=1">Logout</a>
    </span>
  </div>

  <div class="wrap">
    <div class="stats">
      <a href="./" class="<?= $f ? '' : 'on' ?>">All: <b><?= $all ?></b></a>
      <?php foreach ($statuses as $s): ?>
        <a href="?s=<?= $e($s) ?>" class="<?= $f === $s ? 'on' : '' ?>"><?= $e($s) ?>: <b><?= (int)($counts[$s] ?? 0) ?></b></a>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($_SESSION['msg'])): ?>
      <p class="msg"><?= $e($_SESSION['msg']) ?></p>
      <?php unset($_SESSION['msg']); ?>
    <?php endif; ?>

    <details class="box">
      <summary>Change password</summary>
      <form method="post">
        <input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>">
        <input name="old" type="password" placeholder="Current password" autocomplete="current-password" required>
        <input name="new" type="password" placeholder="New password (min 8)" autocomplete="new-password" minlength="8" required>
        <button name="newpass" value="1">Save</button>
      </form>
    </details>

    <form class="box" method="get">
      <?php if ($f): ?><input type="hidden" name="s" value="<?= $e($f) ?>"><?php endif; ?>
      <input name="q" value="<?= $e($q) ?>" placeholder="Search name / phone / pincode">
      <button>Search</button>
    </form>

    <form class="box" method="post">
      <input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>">
      <button name="sync" value="1">Sync shipping status now</button>
      <small>Also updates by itself every 5 minutes while this page is open.</small>
    </form>

    <div class="tw">
      <table>
        <tr>
          <th>#</th><th>Date</th><th>Customer</th><th>Address</th>
          <th>Qty / Total</th><th>Details</th><th>Shipping</th><th></th>
        </tr>

        <?php foreach ($rows as $r): ?>
          <tr class="s-<?= $e($r['status']) ?>">
            <td><?= (int)$r['id'] ?></td>
            <td><?= $e($r['created_at']) ?></td>
            <td>
              <b><?= $e($r['name']) ?></b><br>
              <a href="tel:<?= $e($r['phone']) ?>"><?= $e($r['phone']) ?></a>
            </td>
            <td><?= nl2br($e($r['address'])) ?><br><?= $e($r['city']) ?>, <?= $e($r['state']) ?> <?= $e($r['pincode']) ?></td>
            <td><?= (int)$r['qty'] ?> × ₹<?= PRICE ?><br><b>₹<?= (int)$r['total'] ?></b><br><?= $e($r['payment_method']) ?> · <?= $e($r['payment_status']) ?></td>
            <td>
              <details class="vd">
                <summary>View details</summary>
                <dl>
                  <dt>Order</dt>
                  <dd>#<?= (int)$r['id'] ?> · <?= $e($r['created_at']) ?></dd>
                  <dt>Customer</dt>
                  <dd><?= $e($r['name']) ?><br><?= $e($r['phone']) ?></dd>
                  <dt>Address</dt>
                  <dd><?= nl2br($e($r['address'])) ?><br><?= $e($r['city']) ?>, <?= $e($r['state']) ?> – <?= $e($r['pincode']) ?></dd>
                  <dt>Items</dt>
                  <dd><?= (int)$r['qty'] ?> × ₹<?= PRICE ?> = <b>₹<?= (int)$r['total'] ?></b></dd>
                  <dt>Payment</dt>
                  <dd>
                    <?= $e($r['payment_method']) ?> · <?= $e($r['payment_status']) ?>
                    <?php if ($r['rzp_payment_id']): ?><br>Razorpay payment: <?= $e($r['rzp_payment_id']) ?><?php endif; ?>
                    <?php if ($r['rzp_order_id']): ?><br>Razorpay order: <?= $e($r['rzp_order_id']) ?><?php endif; ?>
                  </dd>
                  <dt>Note</dt>
                  <dd><?= $or($r['note']) ?></dd>
                  <dt>Shipping</dt>
                  <dd>
                    Status: <?= $or($r['ship_status']) ?><br>
                    Shipment: <?= $or($r['ship_shipment_id']) ?><br>
                    AWB: <?= $or($r['awb']) ?>
                    <?php if ($r['ship_error']): ?><br><span class="err"><?= $e($r['ship_error']) ?></span><?php endif; ?>
                  </dd>
                </dl>
              </details>
            </td>

            <td>
              <?php if ($r['ship_shipment_id']): ?>
                <b><?= $e($r['ship_status'] ?: 'created') ?></b><br>
                <?php if ($r['awb']): ?>AWB: <?= $e($r['awb']) ?><br><?php endif; ?>
                <?php if (preg_match('#^https://#', (string)$r['track_url'])): ?>
                  <a href="<?= $e($r['track_url']) ?>" target="_blank" rel="noopener">Track</a><br>
                <?php endif; ?>
              <?php elseif ($r['ship_error']): ?>
                <span class="err"><?= $e($r['ship_error']) ?></span><br>
              <?php else: ?>–<br><?php endif; ?>
              <form method="post">
                <input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="back" value="<?= $back ?>">
                <?php if ($r['ship_shipment_id']): ?>
                  <button name="refresh" value="1">Refresh status</button>
                <?php else: ?>
                  <button name="send" value="1">Send to Shipfigo</button>
                <?php endif; ?>
              </form>
            </td>

            <td>
              <form method="post" onsubmit="return confirm('Delete order #<?= (int)$r['id'] ?>?')">
                <input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="back" value="<?= $back ?>">
                <button class="del" name="delete" value="1">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!$rows): ?>
          <tr><td colspan="8">No orders yet.</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

<?php endif; ?>

</body>
</html>
