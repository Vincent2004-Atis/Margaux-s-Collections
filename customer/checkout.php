<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
require_once '../config/database.php';
$db     = getDB();
$userId = (int)$_SESSION['user_id'];

if (empty($_SESSION['cart'])) { header('Location: cart.php'); exit; }

$stmt = $db->prepare("SELECT * FROM users WHERE user_id=?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cart  = $_SESSION['cart'];
$total = 0;
foreach ($cart as $item) $total += $item['price'] * $item['qty'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['customer_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $method    = $_POST['order_method'] ?? 'pickup';
    $payment   = $_POST['payment_method'] ?? 'cash_on_pickup';
    $accId     = !empty($_POST['payment_account_id']) ? (int)$_POST['payment_account_id'] : null;
    $gcashRef  = trim($_POST['gcash_reference'] ?? '');
    $latitude  = (isset($_POST['latitude'])  && $_POST['latitude']  !== '') ? (float)$_POST['latitude']  : null;
    $longitude = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? (float)$_POST['longitude'] : null;

    if (empty($name))    $errors[] = 'Customer name is required.';
    if (empty($address)) $errors[] = 'Address is required.';
    if (empty($contact)) $errors[] = 'Contact number is required.';
    if (!in_array($method, ['pickup','shipping','dropoff','pickup_rider'])) $errors[] = 'Invalid order method.';
    if (!in_array($payment, ['cash_on_pickup','cash_on_delivery','gcash'])) $errors[] = 'Invalid payment method.';
    if ($method === 'pickup' && in_array($payment, ['cash_on_delivery','gcash'])) $errors[] = 'Only Cash on Pickup is available for Meet up orders.';
    if ($method === 'shipping' && $payment === 'cash_on_pickup') $errors[] = 'Cash on Pickup is not available for Shipping Delivery orders.';
    if ($method === 'dropoff' && $payment !== 'gcash') $errors[] = 'Only GCash is available for Drop off orders.';
    if ($method === 'pickup_rider' && $payment === 'cash_on_delivery') $errors[] = 'Cash on Delivery is not available for Pick-up Via Rider orders.';
    if ($payment === 'gcash' && empty($gcashRef)) $errors[] = 'GCash reference number is required to confirm your payment.';
    if (in_array($method, ['shipping','pickup_rider']) && ($latitude === null || $longitude === null)) {
        $errors[] = 'Please pin your exact location on the map for ' . ($method === 'shipping' ? 'Shipping Delivery' : 'Pick-up Via Rider') . ' orders.';
    }

    if ($payment === 'gcash' && $accId !== null) {
        $s = $db->prepare("SELECT account_id FROM user_payment_accounts WHERE account_id=? AND user_id=? AND account_type=?");
        $s->bind_param('iis', $accId, $userId, $payment);
        $s->execute();
        if ($s->get_result()->num_rows === 0) $accId = null;
        $s->close();
    } else { $accId = null; }

    if (empty($errors)) {
        $db->begin_transaction();
        try {
            $res = $db->query("SELECT IFNULL(MAX(queue_number),100)+1 AS next_q FROM orders");
            $queueNum = (int)$res->fetch_assoc()['next_q'];

            $s = $db->prepare("INSERT INTO orders (user_id,customer_name,address,contact_number,order_method,payment_method,payment_account_id,payment_status,gcash_reference,queue_number,total_amount,order_status,latitude,longitude) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $payStatus   = ($payment === 'gcash') ? 'pending_verification' : 'pending';
            $orderStatus = 'pending';
            $gcashRefVal = ($payment === 'gcash') ? $gcashRef : null;
            $s->bind_param('isssssissidsdd', $userId, $name, $address, $contact, $method, $payment, $accId, $payStatus, $gcashRefVal, $queueNum, $total, $orderStatus, $latitude, $longitude);

            // FIX: execute() failures were previously silent — a bad insert
            // here would leave $orderId unset/wrong but the code would carry
            // on and still commit. Throwing on failure lets the existing
            // catch block roll the whole transaction back instead.
            if (!$s->execute()) {
                throw new Exception('Could not save the order record: ' . $s->error);
            }
            $orderId = $db->insert_id;
            $s->close();

            // FIX: this is the insert that was silently failing for some
            // orders, leaving them with a blank "Items" column in Order
            // History. Each execute() is now checked — any failure aborts
            // the whole order (rollback) instead of leaving an order row
            // with no matching order_items rows.
            $s = $db->prepare("INSERT INTO order_items (order_id,product_id,quantity,price) VALUES (?,?,?,?)");
            foreach ($cart as $pid => $item) {
                $pid = (int)$pid;
                $qty = (int)$item['qty'];
                $price = (float)$item['price'];
                $s->bind_param('iiid', $orderId, $pid, $qty, $price);
                if (!$s->execute()) {
                    throw new Exception('Could not save order item (product #' . $pid . '): ' . $s->error);
                }
                $stockUpdate = $db->prepare("UPDATE products SET stock = stock - ? WHERE product_id = ? AND stock >= ?");
                $stockUpdate->bind_param('iii', $qty, $pid, $qty);
                if (!$stockUpdate->execute()) {
                    throw new Exception('Could not update stock for product #' . $pid . ': ' . $stockUpdate->error);
                }
                $stockUpdate->close();
            }
            $s->close();

            $db->commit();
            $_SESSION['cart'] = [];
            header("Location: order_confirmation.php?order_id=$orderId");
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $errors[] = 'Order failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Checkout — Margaux Collections</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
/* ── Base ── */
html, body {
  background: linear-gradient(to bottom right,#0e0507 0%,#1a0a0e 30%,#2a0d14 60%,#3d1020 100%) !important;
  color: #f0e6da !important;
  font-family: 'Jost', sans-serif !important;
  min-height: 100vh !important;
}

/* ── Hero ── */
.page-hero {
  background: transparent !important;
  border-bottom: 1px solid rgba(196,80,100,.15) !important;
  padding: 64px 40px 52px !important;
  text-align: center !important;
  position: relative !important;
}
.page-hero::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 80% 70% at 50% -10%, rgba(196,80,100,.13) 0%, transparent 70%);
  pointer-events: none;
}
.hero-eyebrow {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: .68rem; font-weight: 600; letter-spacing: .28em;
  text-transform: uppercase; color: #c45064;
  padding: 6px 20px;
  border: 1px solid rgba(196,80,100,.3); border-radius: 40px;
  margin-bottom: 20px;
  background: rgba(196,80,100,.06);
  animation: heroIn .7s cubic-bezier(.16,1,.3,1) both;
}
.page-hero h1 {
  font-family: 'Playfair Display', serif !important;
  font-size: clamp(2.4rem,5vw,4rem) !important;
  font-weight: 700 !important; color: #f0e6da !important;
  line-height: 1.05 !important; margin: 0 0 12px !important;
  animation: heroIn .8s cubic-bezier(.16,1,.3,1) both;
}
.page-hero h1 em { font-style: italic !important; color: #c45064 !important; }
.page-hero p { color: #7a6058 !important; font-size: .88rem !important; font-weight: 300 !important; margin: 0 !important; }
.hero-divider {
  width: 56px; height: 1px;
  background: linear-gradient(90deg,transparent,#c45064,transparent);
  margin: 20px auto 0;
}

/* ── Container ── */
.co-container {
  max-width: 1100px;
  margin: 0 auto;
  padding: 32px 24px 80px;
}

/* ── Grid ── */
.checkout-grid { display: flex; gap: 20px; flex-wrap: wrap; }
.col-left  { flex: 1 1 340px; display: flex; flex-direction: column; gap: 16px; }
.col-right { flex: 0 0 320px; }

/* ── Card ── */
.co-card {
  background: rgba(42,13,20,.7);
  border: 1px solid rgba(196,80,100,.14);
  border-radius: 16px; overflow: hidden;
  backdrop-filter: blur(4px);
  transition: border-color .3s;
}
.co-card:hover { border-color: rgba(196,80,100,.28); }
.co-card-header {
  padding: 14px 20px;
  border-bottom: 1px solid rgba(196,80,100,.12);
  display: flex; align-items: center; gap: 10px;
}
.co-card-header h3 {
  margin: 0; font-family: 'Jost', sans-serif;
  font-size: .72rem; font-weight: 600;
  letter-spacing: .18em; text-transform: uppercase;
  color: rgba(196,80,100,.8);
}
.co-card-body  { padding: 20px; }
.co-card-footer { padding: 16px 20px; border-top: 1px solid rgba(196,80,100,.12); display: flex; flex-direction: column; gap: 10px; }

/* ── Form Fields ── */
.form-stack { display: flex; flex-direction: column; gap: 16px; }
.form-field { display: flex; flex-direction: column; gap: 6px; }
.form-field label {
  font-size: .68rem; font-weight: 600; letter-spacing: .14em;
  text-transform: uppercase; color: #7a6058;
}
.form-field input,
.form-field textarea {
  width: 100%; padding: 11px 14px;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(196,80,100,.18);
  border-radius: 10px;
  font-size: .88rem; font-family: 'Jost', sans-serif;
  color: #f0e6da;
  transition: border-color .2s, background .2s, box-shadow .2s;
  box-sizing: border-box;
}
.form-field input::placeholder,
.form-field textarea::placeholder { color: #5a4a42; }
.form-field input:focus,
.form-field textarea:focus {
  outline: none;
  border-color: #c45064;
  background: rgba(196,80,100,.06);
  box-shadow: 0 0 0 3px rgba(196,80,100,.12);
}
.form-field textarea { resize: vertical; min-height: 80px; }
.field-hint { font-size: .72rem; color: #7a6058; margin-top: 2px; }

/* ── Map ── */
.map-search-row { display: flex; gap: 8px; margin-bottom: 8px; }
.map-search-row input {
  flex: 1; padding: 11px 14px;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(196,80,100,.18);
  border-radius: 10px;
  font-size: .85rem; font-family: 'Jost', sans-serif;
  color: #f0e6da; box-sizing: border-box;
  transition: border-color .2s, background .2s, box-shadow .2s;
}
.map-search-row input::placeholder { color: #5a4a42; }
.map-search-row input:focus {
  outline: none; border-color: #c45064;
  background: rgba(196,80,100,.06);
  box-shadow: 0 0 0 3px rgba(196,80,100,.12);
}
.map-search-btn {
  flex-shrink: 0; padding: 0 18px;
  background: rgba(196,80,100,.15);
  border: 1px solid rgba(196,80,100,.3);
  border-radius: 10px; color: #e8a0a8;
  cursor: pointer; font-size: .95rem;
  transition: background .2s;
}
.map-search-btn:hover { background: rgba(196,80,100,.28); }
.map-search-results {
  display: none; max-height: 220px; overflow-y: auto;
  border-radius: 10px; margin-bottom: 8px;
  background: rgba(20,8,12,.96);
  border: 1px solid rgba(196,80,100,.2);
}
.map-search-results.active { display: block; }
.map-search-result-item {
  padding: 10px 14px; font-size: .78rem; color: #f0e6da;
  cursor: pointer; border-bottom: 1px solid rgba(196,80,100,.08);
  line-height: 1.4;
}
.map-search-result-item:last-child { border-bottom: none; }
.map-search-result-item:hover { background: rgba(196,80,100,.14); }

.map-wrap { position: relative; }
#addressMap {
  height: 220px;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(196,80,100,.18);
}
.map-wrap.in-modal #addressMap { height: min(65vh, 620px); }
.map-expand-btn {
  position: absolute; top: 10px; right: 10px; z-index: 700;
  width: 34px; height: 34px; border-radius: 8px;
  background: rgba(20,8,12,.85);
  border: 1px solid rgba(196,80,100,.3);
  color: #f0e6da; font-size: 1rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .2s;
}
.map-expand-btn:hover { background: rgba(196,80,100,.3); }
.map-wrap.in-modal .map-expand-btn { display: none; }
.leaflet-control-attribution { font-size: 9px !important; }

/* ── Map Modal (own popup, centered) ── */
.map-modal {
  max-width: 640px;
  text-align: left;
}
.map-modal .qr-title, .map-modal .qr-sub { text-align: center; }
.map-modal .map-search-row { margin-top: 4px; }

/* ── Radio Options ── */
.radio-opt { display: none; }
.radio-label {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 16px;
  border: 1px solid rgba(196,80,100,.14);
  border-radius: 12px; cursor: pointer; margin-bottom: 8px;
  font-family: 'Jost', sans-serif; font-size: .82rem; font-weight: 500;
  color: #7a6058; background: rgba(255,255,255,.02);
  transition: all .25s; user-select: none;
}
.radio-label .opt-icon { font-size: 1.3rem; flex-shrink: 0; }
.radio-label:hover { border-color: rgba(196,80,100,.4); background: rgba(196,80,100,.06); color: #e8a0a8; }
.radio-opt:checked + .radio-label {
  border-color: #c45064;
  background: rgba(196,80,100,.12);
  color: #f0e6da;
  box-shadow: 0 0 0 1px rgba(196,80,100,.2);
}

/* ── GCash Payment Modal ── */
.gcash-modal-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(5,2,3,.75);
  backdrop-filter: blur(3px);
  z-index: 100000;
  align-items: center; justify-content: center;
  padding: 20px;
}
.gcash-modal-overlay.active { display: flex; }
.gcash-modal {
  position: relative;
  width: 100%; max-width: 380px;
  background: linear-gradient(160deg,#1a0a0e,#2a0d14);
  border: 1px solid rgba(196,80,100,.25);
  border-radius: 18px;
  padding: 32px 26px 26px;
  text-align: center;
  box-shadow: 0 30px 70px rgba(0,0,0,.6);
  animation: heroIn .3s cubic-bezier(.16,1,.3,1) both;
}
.gcash-modal-close {
  position: absolute; top: 14px; right: 14px;
  width: 30px; height: 30px;
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(196,80,100,.2);
  border-radius: 50%;
  color: #7a6058;
  font-size: .9rem;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: all .2s;
}
.gcash-modal-close:hover { background: rgba(196,80,100,.15); color: #e8a0a8; }
.qr-title {
  font-family: 'Playfair Display', serif;
  font-size: 1.15rem; font-style: italic;
  color: #f0e6da; margin-bottom: 4px;
}
.qr-sub { font-size: .8rem; color: #7a6058; margin-bottom: 18px; }
.qr-img-wrap {
  display: inline-flex; align-items: center; justify-content: center;
  width: 200px; height: 200px;
  background: white; border-radius: 12px; padding: 12px;
  box-shadow: 0 8px 26px rgba(0,0,0,.35);
  box-sizing: content-box;
}
.qr-img-wrap img { display: block; width: 100%; height: 100%; object-fit: contain; }
.qr-fallback {
  font-family: 'Jost', sans-serif; font-size: .8rem; color: #2a0d14;
  line-height: 1.5;
}
.qr-fallback strong { display: block; font-size: 1rem; margin-top: 6px; letter-spacing: .04em; }
.qr-note {
  margin: 16px 0 18px; font-size: .76rem; color: #e8a0a8;
  background: rgba(196,80,100,.1); border: 1px solid rgba(196,80,100,.2);
  border-radius: 8px; padding: 9px 14px;
}
.gcash-modal .btn-place { margin-bottom: 10px; }

/* ── Review Modal ── */
.review-modal {
  position: relative;
  width: 100%; max-width: 420px;
  background: linear-gradient(160deg,#1a0a0e,#2a0d14);
  border: 1px solid rgba(196,80,100,.25);
  border-radius: 18px;
  padding: 32px 26px 26px;
  text-align: center;
  box-shadow: 0 30px 70px rgba(0,0,0,.6);
  animation: heroIn .3s cubic-bezier(.16,1,.3,1) both;
  max-height: 90vh;
  overflow-y: auto;
}
.review-details {
  text-align: left;
  margin: 18px 0 22px;
  border-top: 1px solid rgba(196,80,100,.14);
}
.review-row {
  display: flex; justify-content: space-between; align-items: flex-start;
  gap: 16px;
  padding: 11px 2px;
  border-bottom: 1px solid rgba(196,80,100,.1);
  font-size: .82rem;
}
.review-label {
  color: #7a6058; font-weight: 600; letter-spacing: .06em;
  text-transform: uppercase; font-size: .68rem;
  flex-shrink: 0; padding-top: 2px;
}
.review-value { color: #f0e6da; text-align: right; }
.review-total-row { border-bottom: none; padding-top: 14px; }
.review-total-row .review-label { font-size: .72rem; align-self: center; }
.review-total-value {
  font-family: 'Playfair Display', serif; font-size: 1.3rem; color: #c45064;
}

/* ── Order Summary Items ── */
.order-item {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 0; border-bottom: 1px solid rgba(196,80,100,.1);
}
.order-item:last-of-type { border-bottom: none; }
.order-item img {
  width: 58px; height: 58px; border-radius: 10px;
  object-fit: cover; flex-shrink: 0;
  border: 1px solid rgba(196,80,100,.2);
  filter: brightness(.92) saturate(.9);
}
.order-item-name { font-family: 'Playfair Display', serif; font-size: .9rem; color: #f0e6da; }
.order-item-meta { font-size: .75rem; color: #7a6058; margin-top: 3px; }
.order-item-price { font-family: 'Playfair Display', serif; font-size: .95rem; color: #c45064; margin-top: 4px; }
.total-row {
  display: flex; justify-content: space-between; align-items: center;
  padding-top: 14px; margin-top: 8px;
  border-top: 1px solid rgba(196,80,100,.18);
}
.total-label { font-size: .72rem; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #7a6058; }
.total-amount { font-family: 'Playfair Display', serif; font-size: 1.6rem; color: #c45064; }

/* ── Buttons ── */
.btn-place {
  display: flex; align-items: center; justify-content: center;
  width: 100%; padding: 14px 18px;
  background: #c45064; color: #fff; border: none;
  border-radius: 10px; font-family: 'Jost', sans-serif;
  font-size: .74rem; font-weight: 600;
  letter-spacing: .14em; text-transform: uppercase;
  cursor: pointer; transition: background .25s, transform .2s, box-shadow .25s;
  box-shadow: 0 6px 20px rgba(196,80,100,.35);
}
.btn-place:hover { background: #a83d53; transform: translateY(-2px); box-shadow: 0 10px 28px rgba(196,80,100,.45); }
.btn-place:active { transform: scale(.98); }

.btn-back {
  display: flex; align-items: center; justify-content: center;
  width: 100%; padding: 13px 18px;
  background: transparent;
  color: #5a4a42; border: 1px solid rgba(196,80,100,.15);
  border-radius: 10px; font-family: 'Jost', sans-serif;
  font-size: .74rem; font-weight: 500;
  letter-spacing: .12em; text-transform: uppercase;
  cursor: pointer; text-decoration: none;
  transition: all .25s; box-sizing: border-box;
}
.btn-back:hover { border-color: rgba(196,80,100,.4); color: #e8a0a8; background: rgba(196,80,100,.06); }
.review-modal .btn-place { margin-bottom: 10px; }

/* ── Alert ── */
.alert-danger {
  background: rgba(196,80,100,.15); border: 1px solid rgba(196,80,100,.4);
  color: #e8a0a8; padding: 14px 18px; border-radius: 12px;
  margin-bottom: 20px; font-size: .85rem; font-family: 'Jost', sans-serif;
}
.alert-danger ul { margin: 6px 0 0; padding-left: 18px; }

/* ── Disabled ── */
.disabled-opt { opacity: .3; pointer-events: none; }

/* ── Keyframes ── */
@keyframes heroIn { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

@media(max-width:768px) {
  .checkout-grid { flex-direction: column; }
  .col-right { flex: 1 1 auto; }
  .co-container { padding: 20px 16px 60px; }
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<!-- Hero -->
<div class="page-hero">
  <div class="hero-eyebrow">Margaux Collections</div>
  <h1>Complete <em>Checkout</em></h1>
  <p>Review and confirm your order details below</p>
  <div class="hero-divider"></div>
</div>

<div class="co-container">

  <?php if (!empty($errors)): ?>
    <div class="alert-danger">
      ⚠️ <ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="POST" id="checkoutForm">
  <div class="checkout-grid">

    <!-- ── Left ── -->
    <div class="col-left">

      <!-- Customer Information -->
      <div class="co-card">
        <div class="co-card-header">
          <span>👤</span>
          <h3>Customer Information</h3>
        </div>
        <div class="co-card-body">
          <div class="form-stack">
            <div class="form-field">
              <label for="customer_name">Full Name *</label>
              <input type="text" id="customer_name" name="customer_name"
                     value="<?= htmlspecialchars($_POST['customer_name'] ?? $user['name']) ?>"
                     placeholder="Enter your full name" required>
            </div>
            <div class="form-field">
              <label for="contact_number">Contact Number *</label>
              <input type="text" id="contact_number" name="contact_number"
                     value="<?= htmlspecialchars($_POST['contact_number'] ?? $user['contact_number']) ?>"
                     placeholder="09XXXXXXXXX" required>
            </div>
            <div class="form-field">
              <label for="address">Delivery Address *</label>
              <textarea id="address" name="address" rows="3"
                        placeholder="House No., Street, Barangay, City, Province" required><?= htmlspecialchars($_POST['address'] ?? $user['address'] ?? '') ?></textarea>
            </div>
            <div class="form-field">
              <label>Pin Your Exact Location</label>
              <div class="map-search-row">
                <input type="text" id="mapSearchInput" placeholder="Search a place or address..." autocomplete="off">
                <button type="button" id="mapSearchBtn" class="map-search-btn">🔍</button>
              </div>
              <div id="mapSearchResults" class="map-search-results"></div>
              <div class="map-wrap" id="mapWrap">
                <div id="addressMap"></div>
                <button type="button" id="mapExpandBtn" class="map-expand-btn" title="Expand map">⛶</button>
              </div>
              <div class="field-hint">📍 Click on the map or drag the pin, or search above — this helps us find you accurately for delivery/rider pickup.</div>
              <input type="hidden" name="latitude"  id="latitudeInput"  value="<?= htmlspecialchars($_POST['latitude']  ?? '') ?>">
              <input type="hidden" name="longitude" id="longitudeInput" value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Order Method -->
      <div class="co-card">
        <div class="co-card-header">
          <span>🚚</span>
          <h3>Order Method</h3>
        </div>
        <div class="co-card-body">
          <input type="radio" class="radio-opt" name="order_method" id="methodPickup" value="pickup"
                 <?= (($_POST['order_method'] ?? 'pickup') === 'pickup') ? 'checked' : '' ?>>
          <label for="methodPickup" class="radio-label">
            <span class="opt-icon">👤</span> Meet up
          </label>

          <input type="radio" class="radio-opt" name="order_method" id="methodDropoff" value="dropoff"
                 <?= (($_POST['order_method'] ?? '') === 'dropoff') ? 'checked' : '' ?>>
          <label for="methodDropoff" class="radio-label">
            <span class="opt-icon">📦</span> Drop off at MJM BUILDING
          </label>

          <input type="radio" class="radio-opt" name="order_method" id="methodShipping" value="shipping"
                 <?= (($_POST['order_method'] ?? '') === 'shipping') ? 'checked' : '' ?>>
          <label for="methodShipping" class="radio-label">
            <span class="opt-icon">🚚</span> Shipping Delivery
          </label>

          <input type="radio" class="radio-opt" name="order_method" id="methodRider" value="pickup_rider"
                 <?= (($_POST['order_method'] ?? '') === 'pickup_rider') ? 'checked' : '' ?>>
          <label for="methodRider" class="radio-label">
            <span class="opt-icon">🏍️</span> Pick-up Via Rider
          </label>
        </div>
      </div>

      <!-- Payment Method -->
      <div class="co-card">
        <div class="co-card-header">
          <span>💳</span>
          <h3>Payment Method</h3>
        </div>
        <div class="co-card-body">

          <input type="radio" class="radio-opt" name="payment_method" id="payCOP" value="cash_on_pickup"
                 <?= (($_POST['payment_method'] ?? 'cash_on_pickup') === 'cash_on_pickup') ? 'checked' : '' ?>>
          <label for="payCOP" id="labelCOP" class="radio-label">
            <span class="opt-icon">💵</span> Cash on Pickup
          </label>

          <input type="radio" class="radio-opt" name="payment_method" id="payCOD" value="cash_on_delivery"
                 <?= (($_POST['payment_method'] ?? '') === 'cash_on_delivery') ? 'checked' : '' ?>>
          <label for="payCOD" id="labelCOD" class="radio-label">
            <span class="opt-icon">🏠</span> Cash on Delivery
          </label>

          <input type="radio" class="radio-opt" name="payment_method" id="payGcash" value="gcash"
                 <?= (($_POST['payment_method'] ?? '') === 'gcash') ? 'checked' : '' ?>>
          <label for="payGcash" id="labelGcash" class="radio-label">
            <span class="opt-icon">📱</span> GCash
          </label>

        </div>
      </div>

    </div><!-- /col-left -->

    <!-- ── Right: Order Summary ── -->
    <div class="col-right">
      <div class="co-card" style="position:sticky;top:80px;">
        <div class="co-card-header">
          <span>🛒</span>
          <h3>Order Summary</h3>
        </div>
        <div class="co-card-body">
          <?php foreach ($cart as $pid => $item): ?>
          <div class="order-item">
            <img src="../<?= htmlspecialchars($item['image']) ?>"
                 onerror="this.src='../images/product-placeholder.jpg'"
                 alt="<?= htmlspecialchars($item['product_name']) ?>">
            <div style="flex:1;">
              <div class="order-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
              <div class="order-item-meta">Qty: <?= $item['qty'] ?></div>
              <div class="order-item-price">₱<?= number_format($item['price'] * $item['qty'], 2) ?></div>
            </div>
          </div>
          <?php endforeach; ?>

          <div class="total-row">
            <span class="total-label">Total</span>
            <span class="total-amount">₱<?= number_format($total, 2) ?></span>
          </div>
        </div>
        <div class="co-card-footer">
          <button type="submit" class="btn-place">Place Order →</button>
          <a href="cart.php" class="btn-back">← Back to Cart</a>
        </div>
      </div>
    </div>

  </div>
  </form>

  <!-- Full Map Popup Modal -->
  <div class="gcash-modal-overlay" id="mapModalOverlay">
    <div class="review-modal map-modal">
      <button type="button" class="gcash-modal-close" onclick="closeMapModal()">✕</button>
      <div class="qr-title">Pin Your Exact Location</div>
      <div class="qr-sub">Click on the map or drag the pin, or search below</div>
      <div class="map-search-row">
        <input type="text" id="mapModalSearchInput" placeholder="Search a place or address..." autocomplete="off">
        <button type="button" id="mapModalSearchBtn" class="map-search-btn">🔍</button>
      </div>
      <div id="mapModalSearchResults" class="map-search-results"></div>
      <div id="mapModalMapHolder"></div>
    </div>
  </div>

  <!-- Order Review Confirmation Modal -->
  <div class="gcash-modal-overlay" id="reviewModalOverlay">
    <div class="review-modal">
      <button type="button" class="gcash-modal-close" onclick="closeReviewModal()">✕</button>
      <div class="qr-title">Confirm Your Order</div>
      <div class="qr-sub">Please double-check your details before placing the order</div>

      <div class="review-details">
        <div class="review-row"><span class="review-label">Name</span><span class="review-value" id="reviewName"></span></div>
        <div class="review-row"><span class="review-label">Contact</span><span class="review-value" id="reviewContact"></span></div>
        <div class="review-row"><span class="review-label">Address</span><span class="review-value" id="reviewAddress"></span></div>
        <div class="review-row"><span class="review-label">Pinned Location</span><span class="review-value" id="reviewPin"></span></div>
        <div class="review-row"><span class="review-label">Order Method</span><span class="review-value" id="reviewMethod"></span></div>
        <div class="review-row"><span class="review-label">Payment</span><span class="review-value" id="reviewPayment"></span></div>
        <div class="review-row review-total-row"><span class="review-label">Total</span><span class="review-value review-total-value" id="reviewTotal"></span></div>
      </div>

      <button type="button" class="btn-place" onclick="confirmReview()">CONFIRM & PLACE ORDER →</button>
      <button type="button" class="btn-back" onclick="closeReviewModal()">← Edit Details</button>
    </div>
  </div>

  <!-- GCash Payment Confirmation Modal -->
  <div class="gcash-modal-overlay" id="gcashModalOverlay">
    <div class="gcash-modal">
      <button type="button" class="gcash-modal-close" onclick="closeGcashModal()">✕</button>
      <div class="qr-title">Scan to Pay via GCash</div>
      <div class="qr-sub">Open your GCash app and scan the QR code below</div>
      <div class="qr-img-wrap" id="qrImgWrap">
        <img id="qrCodeImg" alt="GCash QR Code" style="display:none;">
        <div class="qr-fallback" id="qrFallback" style="display:none;">
          Can't load QR code.<br>Send payment manually to:
          <strong id="qrFallbackNumber"></strong>
        </div>
      </div>
      <div class="qr-note">📌 Please complete your payment first before confirming</div>

      <div class="form-field" style="text-align:left; margin-bottom:14px;">
        <label for="gcashRefInput">GCash Reference No. *</label>
        <input type="text" id="gcashRefInput" placeholder="e.g. 1234567890123" maxlength="30" autocomplete="off">
        <div id="gcashRefError" style="display:none; color:#e8a0a8; font-size:.72rem; margin-top:2px;">
          Please enter the reference number from your GCash receipt.
        </div>
      </div>

      <button type="button" class="btn-place" id="confirmGcashBtn" onclick="confirmGcashPaid()">PROCEED→</button>
      <button type="button" class="btn-back" onclick="closeGcashModal()">Cancel</button>
    </div>
  </div>

</div>

<!-- Page Transition (same as products.php) -->
<div class="page-transition" id="pageTransition">
  <div class="pt-panel"></div>
  <div class="pt-logo">
    <div class="pt-logo-text">Margaux Collections</div>
    <div class="pt-logo-bar"></div>
  </div>
</div>

<style>
.page-transition { position:fixed;inset:0;z-index:99998;pointer-events:none;display:flex;align-items:center;justify-content:center; }
.pt-panel { position:absolute;inset:0;background:linear-gradient(135deg,#0e0507,#2a0d14);transform:scaleY(0);transform-origin:bottom;transition:transform .5s cubic-bezier(.77,0,.18,1); }
.pt-logo { position:relative;z-index:2;opacity:0;transform:scale(.5);transition:all .4s ease .2s;text-align:center; }
.pt-logo-text { font-family:'Playfair Display',serif;font-size:1.6rem;color:#e8a0a8;letter-spacing:.15em;font-weight:400; }
.pt-logo-bar { width:0;height:1px;background:linear-gradient(90deg,transparent,#c45064,transparent);margin:12px auto 0;transition:width .5s ease .3s; }
.page-transition.active .pt-panel { transform:scaleY(1); }
.page-transition.active .pt-logo  { opacity:1;transform:scale(1); }
.page-transition.active .pt-logo-bar { width:120px; }
</style>

<script>
// ── Order Method / Payment Method UI Logic ────────
const methodPickup   = document.getElementById('methodPickup');
const methodDropoff  = document.getElementById('methodDropoff');
const methodShipping = document.getElementById('methodShipping');
const methodRider    = document.getElementById('methodRider');
const payCOP         = document.getElementById('payCOP');
const payCOD         = document.getElementById('payCOD');
const payGcash       = document.getElementById('payGcash');
const labelCOP       = document.getElementById('labelCOP');
const labelCOD       = document.getElementById('labelCOD');
const labelGcash     = document.getElementById('labelGcash');
let gcashConfirmed = false;
let orderReviewed  = false;

function updateUI() {
  const isPickup   = methodPickup.checked;   // Meet up
  const isShipping = methodShipping.checked; // Shipping Delivery
  const isDropoff  = methodDropoff.checked;  // Drop off
  const isRider    = methodRider.checked;    // Pick-up Via Rider

  labelCOD.classList.toggle('disabled-opt', isPickup || isRider || isDropoff);
  labelGcash.classList.toggle('disabled-opt', isPickup);
  if (isPickup && (payCOD.checked || payGcash.checked)) payCOP.checked = true;

  labelCOP.classList.toggle('disabled-opt', isShipping || isDropoff);
  if (isShipping && payCOP.checked) payCOD.checked = true;

  if (isDropoff && (payCOP.checked || payCOD.checked)) payGcash.checked = true;

  if (isRider && payCOD.checked) payCOP.checked = true;

  // Any change to method/payment invalidates prior confirmations
  gcashConfirmed = false;
  orderReviewed  = false;
}

[methodPickup, methodDropoff, methodShipping, methodRider, payCOP, payCOD, payGcash].forEach(el => {
  if (el) el.addEventListener('change', updateUI);
});
updateUI();

['customer_name', 'contact_number', 'address'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', () => { orderReviewed = false; });
});

// ── Location Map (Leaflet + OpenStreetMap, free) ──
const DEFAULT_LAT = 10.7202, DEFAULT_LNG = 122.5621; // Iloilo City center
const latInput = document.getElementById('latitudeInput');
const lngInput = document.getElementById('longitudeInput');
let map, marker, geocodeTimeout;

function updateLatLng(lat, lng) {
  latInput.value = lat.toFixed(7);
  lngInput.value = lng.toFixed(7);
  orderReviewed = false;
}

function reverseGeocode(lat, lng) {
  clearTimeout(geocodeTimeout);
  geocodeTimeout = setTimeout(() => {
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
      .then(res => res.json())
      .then(data => {
        if (data && data.display_name) {
          document.getElementById('address').value = data.display_name;
        }
      })
      .catch(err => console.error('Reverse geocode failed:', err));
  }, 500);
}

function initMap() {
  const savedLat = latInput.value ? parseFloat(latInput.value) : null;
  const savedLng = lngInput.value ? parseFloat(lngInput.value) : null;
  const startLat = savedLat ?? DEFAULT_LAT;
  const startLng = savedLng ?? DEFAULT_LNG;

  map = L.map('addressMap').setView([startLat, startLng], savedLat ? 16 : 13);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19
  }).addTo(map);

  marker = L.marker([startLat, startLng], { draggable: true }).addTo(map);
  updateLatLng(startLat, startLng);

  marker.on('dragend', () => {
    const pos = marker.getLatLng();
    updateLatLng(pos.lat, pos.lng);
    reverseGeocode(pos.lat, pos.lng);
  });

  map.on('click', (e) => {
    marker.setLatLng(e.latlng);
    updateLatLng(e.latlng.lat, e.latlng.lng);
    reverseGeocode(e.latlng.lat, e.latlng.lng);
  });

  // If no pin saved yet, try to auto-center on the customer's actual location
  if (!savedLat && navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude, lng = pos.coords.longitude;
        map.setView([lat, lng], 16);
        marker.setLatLng([lat, lng]);
        updateLatLng(lat, lng);
        reverseGeocode(lat, lng);
      },
      () => { /* permission denied or unavailable — keep default pin */ }
    );
  }
}

document.addEventListener('DOMContentLoaded', initMap);

// ── Map Location Search (Nominatim) — shared helpers ──
// Rough Iloilo province bounding box (left,top,right,bottom) — soft bias, not a hard filter
const ILOILO_VIEWBOX = '121.9,11.4,123.3,10.2';

function performSearch(query) {
  const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=6&countrycodes=ph&addressdetails=1&viewbox=${ILOILO_VIEWBOX}&bounded=0`;
  return fetch(url).then(res => res.json());
}

function runSearchFor(inputEl, resultsEl) {
  const q = inputEl.value.trim();
  if (!q) { resultsEl.classList.remove('active'); resultsEl.innerHTML = ''; return; }

  performSearch(q)
    .then(data => {
      if (data && data.length) { renderSearchResults(data, resultsEl); return; }
      // Barangay/street-only searches (e.g. "Bagaygay") often need extra
      // location context before Nominatim can match them — retry once.
      const hasContext = /iloilo|philippines/i.test(q);
      if (hasContext) { renderSearchResults([], resultsEl); return; }
      performSearch(`${q}, Iloilo, Philippines`)
        .then(d => renderSearchResults(d, resultsEl))
        .catch(() => renderSearchResults([], resultsEl));
    })
    .catch(err => { console.error('Map search failed:', err); renderSearchResults([], resultsEl); });
}

function renderSearchResults(data, resultsEl) {
  resultsEl.innerHTML = '';
  if (!data || !data.length) {
    const empty = document.createElement('div');
    empty.className = 'map-search-result-item';
    empty.style.cssText = 'cursor:default;color:#7a6058;';
    empty.textContent = 'No results found. Try adding the city/barangay (e.g. "Bagaygay, Sara, Iloilo").';
    resultsEl.appendChild(empty);
    resultsEl.classList.add('active');
    return;
  }
  data.forEach(place => {
    const item = document.createElement('div');
    item.className = 'map-search-result-item';
    item.textContent = place.display_name;
    item.addEventListener('click', () => {
      const lat = parseFloat(place.lat), lng = parseFloat(place.lon);
      map.setView([lat, lng], 17);
      marker.setLatLng([lat, lng]);
      updateLatLng(lat, lng);
      document.getElementById('address').value = place.display_name;
      // Keep both search boxes (inline + modal) in sync
      mapSearchInput.value = place.display_name;
      mapModalSearchInput.value = place.display_name;
      mapSearchResults.classList.remove('active');
      mapSearchResults.innerHTML = '';
      mapModalSearchResults.classList.remove('active');
      mapModalSearchResults.innerHTML = '';
    });
    resultsEl.appendChild(item);
  });
  resultsEl.classList.add('active');
}

function bindSearchBox(inputEl, btnEl, resultsEl) {
  let t;
  inputEl.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => runSearchFor(inputEl, resultsEl), 500);
  });
  btnEl.addEventListener('click', () => runSearchFor(inputEl, resultsEl));
  inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); runSearchFor(inputEl, resultsEl); }
  });
}

const mapSearchInput        = document.getElementById('mapSearchInput');
const mapSearchBtn          = document.getElementById('mapSearchBtn');
const mapSearchResults      = document.getElementById('mapSearchResults');
const mapModalSearchInput   = document.getElementById('mapModalSearchInput');
const mapModalSearchBtn     = document.getElementById('mapModalSearchBtn');
const mapModalSearchResults = document.getElementById('mapModalSearchResults');

bindSearchBox(mapSearchInput, mapSearchBtn, mapSearchResults);
bindSearchBox(mapModalSearchInput, mapModalSearchBtn, mapModalSearchResults);

document.addEventListener('click', (e) => {
  if (!mapSearchResults.contains(e.target) && e.target !== mapSearchInput) {
    mapSearchResults.classList.remove('active');
  }
  if (!mapModalSearchResults.contains(e.target) && e.target !== mapModalSearchInput) {
    mapModalSearchResults.classList.remove('active');
  }
});

// ── Map Popup Modal (own centered popup, not inline resize) ──
const mapWrap         = document.getElementById('mapWrap');
const mapExpandBtn    = document.getElementById('mapExpandBtn');
const mapModalOverlay = document.getElementById('mapModalOverlay');
const mapModalHolder  = document.getElementById('mapModalMapHolder');

// Anchor marks the map's original spot in the form so it can be moved back
const mapWrapAnchor = document.createComment('map-wrap-anchor');
mapWrap.parentNode.insertBefore(mapWrapAnchor, mapWrap);

function openMapModal() {
  mapModalHolder.appendChild(mapWrap);
  mapWrap.classList.add('in-modal');
  mapModalOverlay.classList.add('active');
  setTimeout(() => {
    if (map) {
      map.invalidateSize();
      map.setView(marker.getLatLng(), map.getZoom());
    }
  }, 50);
}

function closeMapModal() {
  mapWrapAnchor.parentNode.insertBefore(mapWrap, mapWrapAnchor.nextSibling);
  mapWrap.classList.remove('in-modal');
  mapModalOverlay.classList.remove('active');
  setTimeout(() => {
    if (map) {
      map.invalidateSize();
      map.setView(marker.getLatLng(), map.getZoom());
    }
  }, 50);
}

mapExpandBtn.addEventListener('click', openMapModal);
mapModalOverlay.addEventListener('click', (e) => {
  if (e.target === mapModalOverlay) closeMapModal();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && mapModalOverlay.classList.contains('active')) closeMapModal();
});

// ── Order Review Modal ────────────────────────────
const checkoutForm       = document.getElementById('checkoutForm');
const reviewModalOverlay = document.getElementById('reviewModalOverlay');

const methodLabels = {
  pickup: 'Meet up',
  dropoff: 'Drop off at MJM BUILDING',
  shipping: 'Shipping Delivery',
  pickup_rider: 'Pick-up Via Rider'
};
const paymentLabels = {
  cash_on_pickup: 'Cash on Pickup',
  cash_on_delivery: 'Cash on Delivery',
  gcash: 'GCash'
};

function openReviewModal() {
  document.getElementById('reviewName').textContent    = document.getElementById('customer_name').value;
  document.getElementById('reviewContact').textContent = document.getElementById('contact_number').value;
  document.getElementById('reviewAddress').textContent = document.getElementById('address').value;

  const lat = latInput.value, lng = lngInput.value;
  document.getElementById('reviewPin').textContent = (lat && lng) ? `${lat}, ${lng}` : 'Not pinned';

  const methodVal = document.querySelector('input[name="order_method"]:checked')?.value;
  document.getElementById('reviewMethod').textContent = methodLabels[methodVal] || methodVal;

  const payVal = document.querySelector('input[name="payment_method"]:checked')?.value;
  document.getElementById('reviewPayment').textContent = paymentLabels[payVal] || payVal;

  document.getElementById('reviewTotal').textContent = document.querySelector('.total-amount').textContent;

  reviewModalOverlay.classList.add('active');
}

function closeReviewModal() {
  reviewModalOverlay.classList.remove('active');
}

function confirmReview() {
  orderReviewed = true;
  closeReviewModal();

  const payMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
  if (payMethod === 'gcash' && !gcashConfirmed) {
    openGcashModal();
  } else {
    checkoutForm.submit();
  }
}

// ── GCash Payment Popup ──────────────────────────
const GCASH_NUMBER      = '09482841494';
const gcashModalOverlay = document.getElementById('gcashModalOverlay');
let qrGenerated    = false;

function generateQR() {
  if (qrGenerated) return;
  qrGenerated = true;

  const img      = document.getElementById('qrCodeImg');
  const fallback = document.getElementById('qrFallback');
  document.getElementById('qrFallbackNumber').textContent = GCASH_NUMBER;

  img.onload  = () => { img.style.display = 'block'; fallback.style.display = 'none'; };
  img.onerror = () => { img.style.display = 'none';  fallback.style.display = 'block';
    console.error('GCash QR image failed to load — check that ../images/gcash_qr.png exists.'); };

  img.src = '../images/gcash_qr.png';
}

function openGcashModal() {
  gcashModalOverlay.classList.add('active');
  try {
    generateQR();
  } catch (err) {
    console.error('QR generation error:', err);
    document.getElementById('qrCodeImg').style.display = 'none';
    document.getElementById('qrFallback').style.display = 'block';
    document.getElementById('qrFallbackNumber').textContent = GCASH_NUMBER;
  }
}

function closeGcashModal() {
  gcashModalOverlay.classList.remove('active');
}

document.getElementById('gcashRefInput').addEventListener('input', function () {
  if (this.value.trim()) document.getElementById('gcashRefError').style.display = 'none';
});

function confirmGcashPaid() {
  const refInput  = document.getElementById('gcashRefInput');
  const refError  = document.getElementById('gcashRefError');
  const refValue  = refInput.value.trim();

  if (!refValue) {
    refError.style.display = 'block';
    refInput.focus();
    return;
  }
  refError.style.display = 'none';

  let hidden = document.getElementById('gcashReferenceHidden');
  if (!hidden) {
    hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'gcash_reference';
    hidden.id   = 'gcashReferenceHidden';
    checkoutForm.appendChild(hidden);
  }
  hidden.value = refValue;

  gcashConfirmed = true;
  closeGcashModal();
  checkoutForm.submit();
}

// ── Master submit handler: review first, then GCash if needed ──
checkoutForm.addEventListener('submit', function (e) {
  if (!orderReviewed) {
    e.preventDefault();
    openReviewModal();
    return;
  }
  const payMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
  if (payMethod === 'gcash' && !gcashConfirmed) {
    e.preventDefault();
    openGcashModal();
  }
});

// Page transition
window.addEventListener('pageshow', () =>
  document.getElementById('pageTransition').classList.remove('active')
);
</script>
</body>
</html>
