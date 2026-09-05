<?php
require_once '../includes/security.php';
if (!isset($_SESSION['user_id'])) { header('Location: /Margaux_Collections/auth/login.php'); exit; }
require_once '../config/database.php';
$db     = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY order_date DESC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$allOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch the actual products bought per order, so the customer can see what
// they ordered without having to open each order separately.
//
// FIX: this was an INNER JOIN against products, which silently hid every
// item whose product had since been deleted (and, combined with a
// checkout.php bug that didn't check insert errors, also hid items from
// orders where the order_items insert itself had failed). Switched to a
// LEFT JOIN so an order's items always show up, with a fallback label
// for anything that no longer has a matching product row.
$itemsByOrder = [];
if (!empty($allOrders)) {
    $orderIds = array_column($allOrders, 'order_id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = str_repeat('i', count($orderIds));

    $itemStmt = $db->prepare("
        SELECT oi.order_id, oi.quantity, oi.price, p.product_name, p.image
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_id
    ");
    $itemStmt->bind_param($types, ...$orderIds);
    $itemStmt->execute();
    $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemStmt->close();

    foreach ($itemRows as $row) {
        $itemsByOrder[$row['order_id']][] = $row;
    }
}

$orderMethodIcons = ['pickup'=>'Pickup','dropoff'=>'Drop off','shipping'=>'Shipping','pickup_rider'=>'Pick-up Via Rider'];
$payIcons = ['cash_on_pickup'=>'Cash on Pickup','cash_on_delivery'=>'Cash on Delivery','gcash'=>'GCash'];

/**
 * Bucket an order into a tab that matches this system's actual flow:
 *   payment_status: pending -> pending_verification (gcash) -> paid
 *   order_status:   pending -> processing -> completed
 *
 * An order that isn't paid yet is always "To Pay", regardless of payment
 * method or order_status — the customer's next action is the same either
 * way: pay / wait for payment verification.
 *
 * Once paid, "Preparing" covers everything still being fulfilled
 * (pending or processing), and "Completed" is its own bucket.
 */
function bucketOf(array $o): string {
    if ($o['payment_status'] !== 'paid') {
        return 'to_pay';
    }
    return $o['order_status'] === 'completed' ? 'completed' : 'preparing';
}

$counts = ['to_pay'=>0,'preparing'=>0,'completed'=>0];
foreach ($allOrders as $o) { $counts[bucketOf($o)]++; }

$activeTab = $_GET['tab'] ?? 'all';
$validTabs = ['all','to_pay','preparing','completed'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'all';

$orders = ($activeTab === 'all')
    ? $allOrders
    : array_values(array_filter($allOrders, fn($o) => bucketOf($o) === $activeTab));

$tabs = [
    ['key'=>'all',       'label'=>'All'],
    ['key'=>'to_pay',    'label'=>'To Pay'],
    ['key'=>'preparing', 'label'=>'Preparing'],
    ['key'=>'completed', 'label'=>'Completed'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Order History — Margaux Collections</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
/* ── HARD RESET — beats style.css ── */
html { background: #1a0609 !important; }
body {
  background: #1a0609 !important;
  background-image: none !important;
  color: #f0e6da !important;
  font-family: 'Jost', sans-serif !important;
  min-height: 100vh !important;
  margin: 0 !important;
  padding: 0 !important;
}
body * { box-sizing: border-box; }

/* kill any pink/light section backgrounds from style.css */
section, .section, main, .main,
.hero, .page-hero-wrap, .banner, .header-banner,
[class*="hero"], [class*="banner"], [class*="header"] {
  background: transparent !important;
  background-image: none !important;
  background-color: transparent !important;
}

/* ── HERO ── */
.page-hero {
  background: #1a0609 !important;
  background-image: none !important;
  border-bottom: 1px solid rgba(196,80,100,.2) !important;
  padding: 60px 24px 48px !important;
  text-align: center !important;
  position: relative !important;
  overflow: hidden !important;
}
.page-hero::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 70% 55% at 50% 0%, rgba(196,80,100,.14) 0%, transparent 68%) !important;
  pointer-events: none;
}
.hero-eyebrow {
  display: inline-block !important;
  font-size: .65rem !important; font-weight: 600 !important;
  letter-spacing: .28em !important; text-transform: uppercase !important;
  color: #c45064 !important; padding: 5px 20px !important;
  border: 1px solid rgba(196,80,100,.35) !important; border-radius: 40px !important;
  margin-bottom: 18px !important; background: rgba(196,80,100,.07) !important;
  position: relative !important;
  -webkit-text-fill-color: #c45064 !important;
}
.page-hero h1 {
  font-family: 'Playfair Display', serif !important;
  font-size: clamp(2.4rem, 5vw, 3.6rem) !important;
  font-weight: 700 !important; color: #f0e6da !important;
  line-height: 1.08 !important; margin: 0 0 10px !important;
  position: relative !important;
  background: transparent !important;
  -webkit-text-fill-color: #f0e6da !important;
}
.page-hero h1 em {
  font-style: italic !important; color: #c45064 !important;
  -webkit-text-fill-color: #c45064 !important;
}
.page-hero p {
  color: #7a6058 !important; font-size: .9rem !important;
  font-weight: 300 !important; position: relative !important;
  background: transparent !important;
  -webkit-text-fill-color: #7a6058 !important;
}
.hero-divider {
  width: 48px !important; height: 2px !important;
  background: #c45064 !important;
  margin: 16px auto 0 !important; opacity: .65 !important;
  border: none !important;
}

/* ── PAGE WRAP ── */
.page-wrap {
  max-width: 1100px !important;
  margin: 0 auto !important;
  padding: 36px 24px 80px !important;
  background: transparent !important;
}

/* ── STATUS TABS (Shopee-style) ── */
.status-tabs {
  display: flex !important;
  background: #2e0c18 !important;
  border: 1px solid rgba(196,80,100,.22) !important;
  border-radius: 14px !important;
  overflow: hidden !important;
  margin-bottom: 20px !important;
}
.status-tab {
  flex: 1 1 0 !important;
  display: flex !important; flex-direction: column !important;
  align-items: center !important; gap: 8px !important;
  padding: 18px 10px 16px !important;
  text-decoration: none !important;
  color: #9a7878 !important;
  border-right: 1px solid rgba(196,80,100,.14) !important;
  position: relative !important;
  transition: background .2s, color .2s !important;
  -webkit-text-fill-color: #9a7878 !important;
}
.status-tab:last-child { border-right: none !important; }
.status-tab:hover { background: rgba(196,80,100,.07) !important; color: #e8a0a8 !important; -webkit-text-fill-color: #e8a0a8 !important; }
.status-tab.active {
  background: rgba(196,80,100,.13) !important;
  color: #f0e6da !important;
  -webkit-text-fill-color: #f0e6da !important;
  box-shadow: inset 0 -3px 0 #c45064 !important;
}
.status-tab-icon { font-size: 1.35rem !important; line-height: 1 !important; }
.status-tab-label {
  font-size: .8rem !important; font-weight: 600 !important;
  letter-spacing: .03em !important; text-transform: uppercase !important;
  white-space: nowrap !important;
}
.status-tab-count {
  position: absolute !important; top: 8px !important; right: 18%;
  background: #c45064 !important; color: #fff !important;
  font-size: .62rem !important; font-weight: 700 !important;
  min-width: 17px !important; height: 17px !important;
  border-radius: 50% !important;
  display: flex !important; align-items: center !important; justify-content: center !important;
  padding: 0 4px !important;
  -webkit-text-fill-color: #fff !important;
}
@media(max-width: 700px) {
  .status-tab-label { display: none !important; }
  .status-tab { padding: 14px 6px !important; }
  .status-tab-count { right: 22%; }
}

/* ── CARD ── */
.mg-card {
  background: #2e0c18 !important;
  border: 1px solid rgba(196,80,100,.22) !important;
  border-radius: 14px !important;
  overflow: hidden !important;
  box-shadow: none !important;
}
.mg-card-head {
  background: #1e0810 !important;
  padding: 15px 20px !important;
  border-bottom: 1px solid rgba(196,80,100,.16) !important;
  display: flex !important; align-items: center !important;
  justify-content: space-between !important;
}
.mg-card-head-title {
  font-family: 'Playfair Display', serif !important;
  font-size: 1.05rem !important; font-weight: 400 !important;
  color: #f0e6da !important;
  background: transparent !important;
  -webkit-text-fill-color: #f0e6da !important;
}
.mg-pill {
  background: rgba(196,80,100,.15) !important;
  color: #c45064 !important;
  border: 1px solid rgba(196,80,100,.3) !important;
  border-radius: 20px !important; padding: 4px 14px !important;
  font-size: .65rem !important; font-weight: 600 !important;
  letter-spacing: .1em !important;
  -webkit-text-fill-color: #c45064 !important;
}

/* ── TABLE ── */
.table-wrap { overflow-x: auto !important; background: transparent !important; }
table {
  width: 100% !important; border-collapse: collapse !important;
  background: transparent !important;
}
thead tr { border-bottom: 1px solid rgba(196,80,100,.18) !important; }
thead th {
  padding: 12px 18px !important; text-align: left !important;
  font-size: .72rem !important; font-weight: 600 !important;
  letter-spacing: .1em !important; text-transform: uppercase !important;
  color: #5a4048 !important; background: rgba(14,5,7,.35) !important;
  white-space: nowrap !important;
  -webkit-text-fill-color: #5a4048 !important;
}
tbody td {
  padding: 15px 18px !important;
  border-bottom: 1px solid rgba(196,80,100,.07) !important;
  vertical-align: middle !important;
  font-size: .92rem !important; color: #b09090 !important;
  background: transparent !important;
  -webkit-text-fill-color: #b09090 !important;
}
tbody tr:last-child td { border-bottom: none !important; }
tbody tr:hover td { background: rgba(196,80,100,.05) !important; }

.col-id {
  font-family: 'Playfair Display', serif !important;
  font-size: .98rem !important; color: #f0e6da !important;
  font-weight: 400 !important;
  -webkit-text-fill-color: #f0e6da !important;
}
.col-queue {
  font-family: 'Playfair Display', serif !important;
  font-size: 1.05rem !important; color: #c45064 !important;
  font-weight: 700 !important;
  -webkit-text-fill-color: #c45064 !important;
}
.col-date {
  white-space: nowrap !important; color: #9a7878 !important;
  font-size: .88rem !important;
  -webkit-text-fill-color: #9a7878 !important;
}
.col-total {
  font-family: 'Playfair Display', serif !important;
  font-size: .98rem !important; color: #f0e6da !important;
  -webkit-text-fill-color: #f0e6da !important;
}

/* ── ITEMS LIST ── */
.items-list {
  display: flex !important; flex-direction: column !important; gap: 8px !important;
  min-width: 180px !important;
}
.items-empty {
  font-size: .82rem !important; color: #5a4048 !important; font-style: italic !important;
  -webkit-text-fill-color: #5a4048 !important;
}
.items-row {
  display: flex !important; align-items: center !important; gap: 8px !important;
}
.items-row img {
  width: 32px !important; height: 32px !important;
  border-radius: 6px !important; object-fit: cover !important;
  flex-shrink: 0 !important;
  border: 1px solid rgba(196,80,100,.2) !important;
}
.items-name {
  font-size: .86rem !important; color: #d8bcc0 !important;
  -webkit-text-fill-color: #d8bcc0 !important;
  line-height: 1.3 !important;
}
.items-name.missing {
  color: #7a6058 !important; font-style: italic !important;
  -webkit-text-fill-color: #7a6058 !important;
}
.items-qty {
  color: #7a6058 !important; font-size: .8rem !important;
  -webkit-text-fill-color: #7a6058 !important;
}

/* ── BADGES ── */
.badge {
  display: inline-flex !important; align-items: center !important;
  padding: 5px 13px !important; border-radius: 20px !important;
  font-size: .7rem !important; font-weight: 600 !important;
  letter-spacing: .06em !important; text-transform: uppercase !important;
}
.badge-pending {
  background: rgba(196,80,100,.12) !important; color: #c45064 !important;
  border: 1px solid rgba(196,80,100,.28) !important;
  -webkit-text-fill-color: #c45064 !important;
}
.badge-processing {
  background: rgba(80,130,210,.12) !important; color: #7aaedd !important;
  border: 1px solid rgba(80,130,210,.28) !important;
  -webkit-text-fill-color: #7aaedd !important;
}
.badge-completed {
  background: rgba(80,190,120,.1) !important; color: #5dbf82 !important;
  border: 1px solid rgba(80,190,120,.28) !important;
  -webkit-text-fill-color: #5dbf82 !important;
}
.badge-cancelled {
  background: rgba(90,74,66,.2) !important; color: #7a6058 !important;
  border: 1px solid rgba(90,74,66,.3) !important;
  -webkit-text-fill-color: #7a6058 !important;
}
.badge-paid {
  background: rgba(80,190,120,.1) !important; color: #5dbf82 !important;
  border: 1px solid rgba(80,190,120,.28) !important;
  -webkit-text-fill-color: #5dbf82 !important;
}
.badge-unpaid {
  background: rgba(196,80,100,.12) !important; color: #c45064 !important;
  border: 1px solid rgba(196,80,100,.28) !important;
  -webkit-text-fill-color: #c45064 !important;
}
.badge-verification {
  background: rgba(80,130,210,.12) !important; color: #7aaedd !important;
  border: 1px solid rgba(80,130,210,.28) !important;
  -webkit-text-fill-color: #7aaedd !important;
}

/* ── EMPTY ── */
.order-empty { text-align: center !important; padding: 72px 24px !important; background: transparent !important; }
.order-empty h3 {
  font-family: 'Playfair Display', serif !important; font-size: 1.8rem !important;
  font-weight: 400 !important; color: #f0e6da !important; margin-bottom: 10px !important;
  -webkit-text-fill-color: #f0e6da !important;
}
.order-empty p { color: #6a5058 !important; font-weight: 300 !important; margin-bottom: 26px !important; }
.btn-primary {
  display: inline-flex !important; align-items: center !important;
  justify-content: center !important;
  background: #c45064 !important; color: #fff !important;
  border: none !important; border-radius: 10px !important;
  padding: 12px 36px !important; font-family: 'Jost', sans-serif !important;
  font-size: .7rem !important; font-weight: 600 !important;
  letter-spacing: .16em !important; text-transform: uppercase !important;
  cursor: pointer !important; text-decoration: none !important;
  transition: background .25s, transform .2s !important;
  box-shadow: 0 4px 18px rgba(196,80,100,.3) !important;
  -webkit-text-fill-color: #fff !important;
}
.btn-primary:hover {
  background: #a83d53 !important; transform: translateY(-2px) !important;
}

@media(max-width: 700px) {
  thead th, tbody td { padding: 10px 12px !important; }
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-hero">
  <div class="hero-eyebrow">Margaux Collections</div>
  <h1>Order <em>History</em></h1>
  <p>Track all your past and current orders</p>
  <div class="hero-divider"></div>
</div>

<div class="page-wrap">

  <?php if (empty($allOrders)): ?>
    <div class="mg-card">
      <div class="order-empty">
        <div style="font-size:2.4rem;margin-bottom:18px;opacity:.2;">◆</div>
        <h3>No orders yet</h3>
        <p>Start shopping to see your orders here</p>
        <a href="products.php" class="btn-primary">Browse Products →</a>
      </div>
    </div>
  <?php else: ?>

    <!-- Status tabs -->
    <div class="status-tabs">
      <?php foreach ($tabs as $t): ?>
        <a href="?tab=<?= $t['key'] ?>" class="status-tab <?= $activeTab === $t['key'] ? 'active' : '' ?>">
          <?php if ($t['key'] !== 'all' && $counts[$t['key']] > 0): ?>
            <span class="status-tab-count"><?= $counts[$t['key']] ?></span>
          <?php endif; ?>
          <span class="status-tab-label"><?= $t['label'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="mg-card">
      <div class="mg-card-head">
        <span class="mg-card-head-title">Order History</span>
        <span class="mg-pill"><?= count($orders) ?> order<?= count($orders)!=1?'s':'' ?></span>
      </div>

      <?php if (empty($orders)): ?>
        <div class="order-empty">
          <div style="font-size:2rem;margin-bottom:14px;opacity:.2;">◆</div>
          <h3 style="font-size:1.3rem;">No orders here</h3>
          <p>Nothing in this category yet.</p>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Items</th>
              <th>Method</th>
              <th>Payment</th>
              <th>Total</th>
              <th>Order Status</th>
              <th>Pay Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o):
              $statusClass = match($o['order_status']) {
                'pending'    => 'badge-pending',
                'processing' => 'badge-processing',
                'completed'  => 'badge-completed',
                'cancelled'  => 'badge-cancelled',
                default      => 'badge-cancelled'
              };
              $payClass = match($o['payment_status']) {
                'paid'                 => 'badge-paid',
                'pending_verification' => 'badge-verification',
                default                => 'badge-unpaid'
              };
              $payLabel = match($o['payment_status']) {
                'pending_verification' => 'For Verification',
                default                => ucfirst($o['payment_status'])
              };
              $orderItems = $itemsByOrder[$o['order_id']] ?? [];
            ?>
            <tr>
              <td class="col-date"><?= date('M d, Y g:i A', strtotime($o['order_date'])) ?></td>
              <td>
                <?php if (empty($orderItems)): ?>
                  <div class="items-empty">No item details recorded for this order.</div>
                <?php else: ?>
                <div class="items-list">
                  <?php foreach ($orderItems as $it):
                    $hasProduct = !empty($it['product_name']);
                  ?>
                  <div class="items-row">
                    <?php if ($hasProduct): ?>
                    <img src="../<?= htmlspecialchars($it['image']) ?>"
                         alt="<?= htmlspecialchars($it['product_name']) ?>"
                         onerror="this.src='../images/product-placeholder.jpg'">
                    <span class="items-name">
                      <?= htmlspecialchars($it['product_name']) ?>
                      <span class="items-qty">×<?= (int)$it['quantity'] ?></span>
                    </span>
                    <?php else: ?>
                    <span class="items-name missing">
                      Product no longer available
                      <span class="items-qty">×<?= (int)$it['quantity'] ?></span>
                    </span>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </td>
              <td><?= $orderMethodIcons[$o['order_method']] ?? htmlspecialchars($o['order_method']) ?></td>
              <td><?= $payIcons[$o['payment_method']] ?? htmlspecialchars($o['payment_method']) ?></td>
              <td><span class="col-total">₱<?= number_format((float)$o['total_amount'],2) ?></span></td>
              <td><span class="badge <?= $statusClass ?>"><?= ucfirst(htmlspecialchars($o['order_status'])) ?></span></td>
              <td><span class="badge <?= $payClass ?>"><?= htmlspecialchars($payLabel) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
