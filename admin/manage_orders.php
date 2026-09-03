<?php
require_once '../includes/security.php';
require_once '../middleware/auth.php';
requireAdmin();
require_once '../config/database.php';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    csrf_verify();
    require_once '../includes/notify_helper.php';   // ← ADD THIS

    $oid    = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $status = clean($_POST['order_status'] ?? '', 20);
    $pay    = clean($_POST['payment_status'] ?? '', 20);

    if ($oid && in_array($status, ['pending','processing','completed']) && in_array($pay, ['pending','pending_verification','paid'])) {
        // Fetch state BEFORE updating, so we can tell whether payment_status
        // is actually transitioning INTO 'paid' (vs. already being paid).
        $r = $db->prepare("SELECT user_id, payment_status FROM orders WHERE order_id=?");
        $r->bind_param('i', $oid);
        $r->execute();
        $row = $r->get_result()->fetch_assoc();
        $r->close();

        $wasPaid = $row && $row['payment_status'] === 'paid';

        $s = $db->prepare("UPDATE orders SET order_status=?, payment_status=? WHERE order_id=?");
        $s->bind_param('ssi', $status, $pay, $oid);
        $s->execute();
        $s->close();

        // ── Notify the customer ──────────────────────────────────────────
        if ($row) {
            createNotification($db, (int)$row['user_id'], $oid, $status);

            // Fire only on the pending -> paid transition, so re-saving an
            // already-paid order (e.g. just updating order_status) doesn't
            // spam a duplicate "Payment Verified" notification.
            if (!$wasPaid && $pay === 'paid') {
                createPaymentNotification($db, (int)$row['user_id'], $oid);
            }
        }
        // ────────────────────────────────────────────────────────────────
    }
    header('Location: manage_orders.php?updated=1');
    exit;
}

$filterStatus  = clean($_GET['status'] ?? '', 20);
$filterPayment = clean($_GET['payment'] ?? '', 20);
$search        = clean($_GET['search'] ?? '', 100);

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filterStatus)  { $where[] = 'o.order_status=?'; $types.='s'; $params[] = $filterStatus; }
if ($filterPayment) { $where[] = 'o.payment_status=?'; $types.='s'; $params[] = $filterPayment; }
if ($search) {
    $where[] = '(u.name LIKE ? OR o.order_id LIKE ? OR o.contact_number LIKE ?)';
    $types  .= 'sss';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$sql = "SELECT o.*, u.name AS uname, u.email AS uemail FROM orders o
        JOIN users u ON u.user_id=o.user_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY o.order_date DESC";
$stmt = $db->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Fetch order items for every order currently shown ────────────────────
// Grouped by order_id so each row/modal can look up its own items array
// without running a query per row. Empty result set just leaves $itemsByOrder = [].
$itemsByOrder = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'order_id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemTypes = str_repeat('i', count($orderIds));

    $itemSql = "SELECT oi.order_id, oi.product_id, oi.quantity, oi.price, p.product_name, p.image
                FROM order_items oi
                JOIN products p ON p.product_id = oi.product_id
                WHERE oi.order_id IN ($placeholders)
                ORDER BY oi.item_id ASC";
    $itemStmt = $db->prepare($itemSql);
    $itemStmt->bind_param($itemTypes, ...$orderIds);
    $itemStmt->execute();
    $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemStmt->close();

    foreach ($itemRows as $ir) {
        $itemsByOrder[$ir['order_id']][] = $ir;
    }
}

$statusBadge = ['pending'=>'badge-amber','processing'=>'badge-blue','completed'=>'badge-green'];
$payBadge    = ['pending'=>'badge-amber','pending_verification'=>'badge-blue','paid'=>'badge-green'];
$payLabels   = ['cash_on_pickup'=>'💵 Cash Pickup','cash_on_delivery'=>'🏠 Cash Delivery','gcash'=>'📱 GCash','bank_transfer'=>'🏦 Cash On Delivery'];
$payStatusLabels = ['pending'=>'Pending','pending_verification'=>'For Verification','paid'=>'Paid'];

/**
 * Renders the <table>...</table> markup for the orders list.
 * Shared between the normal full-page load and the AJAX auto-filter
 * response, so the two never fall out of sync.
 */
function renderOrdersTable(array $orders, array $itemsByOrder, array $statusBadge, array $payBadge, array $payLabels, array $payStatusLabels): void {
?>
<table>
  <thead><tr><th>Customer</th><th>Items</th><th>Method</th><th>Payment</th><th>Total</th><th>Order Status</th><th>Pay Status</th><th>Date</th><th>Actions</th></tr></thead>
  <tbody>
    <?php if (empty($orders)): ?>
    <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-3);">No orders found.</td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $o): ?>
    <?php $items = $itemsByOrder[$o['order_id']] ?? []; ?>
    <tr>
      <td><div style="font-weight:600;"><?= e($o['uname']) ?></div><div style="font-size:.75rem;color:var(--text-3);"><?= e($o['contact_number']) ?></div></td>
      <td style="font-size:.8rem;max-width:220px;">
        <?php if (empty($items)): ?>
          <span style="color:var(--text-3);">No items on file</span>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <?php $imgSrc = '../' . ($it['image'] ?: 'images/product-placeholder.jpg'); ?>
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
              <img src="<?= e($imgSrc) ?>" alt="<?= e($it['product_name']) ?>"
                   style="width:32px;height:32px;object-fit:cover;border-radius:6px;cursor:pointer;flex-shrink:0;border:1px solid var(--border);"
                   onclick="openImageLightbox('<?= e($imgSrc) ?>', '<?= e(addslashes($it['product_name'])) ?>')">
              <span><?= e($it['product_name']) ?> <span style="color:var(--text-3);">×<?= (int)$it['quantity'] ?></span></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>
      <td><?= $o['order_method']==='pickup'?' PICKUP':' SHIPPING' ?></td>
      <td style="font-size:.82rem;">
        <?= $payLabels[$o['payment_method']]??e($o['payment_method']) ?>
        <?php if ($o['payment_method']==='gcash' && !empty($o['gcash_reference'])): ?>
          <div style="font-size:.72rem;color:var(--text-3);margin-top:2px;">Ref: <strong><?= e($o['gcash_reference']) ?></strong></div>
        <?php endif; ?>
      </td>
      <td><strong>₱<?= number_format((float)$o['total_amount'],2) ?></strong></td>
      <td><span class="badge <?= $statusBadge[$o['order_status']]??'badge-gray' ?>"><?= ucfirst(e($o['order_status'])) ?></span></td>
      <td><span class="badge <?= $payBadge[$o['payment_status']]??'badge-gray' ?>"><?= e($payStatusLabels[$o['payment_status']] ?? ucfirst($o['payment_status'])) ?></span></td>
      <td style="font-size:.78rem;"><?= date('M d, Y H:i', strtotime($o['order_date'])) ?></td>
      <td>
        <?php
          $modalData = $o;
          $modalData['items'] = $items;
        ?>
        <button class="btn btn-outline btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($modalData), ENT_QUOTES) ?>)">✏️ Edit</button>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
}

// ── AJAX auto-filter endpoint ────────────────────────────────────────────
// Same file, same query logic above — just returns a JSON fragment instead
// of the full page when ?ajax=1 is present. This is what makes the search
// box / dropdowns auto-update without a page reload or clicking Filter.
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    ob_start();
    renderOrdersTable($orders, $itemsByOrder, $statusBadge, $payBadge, $payLabels, $payStatusLabels);
    $tableHtml = ob_get_clean();
    echo json_encode([
        'count' => count($orders),
        'html'  => $tableHtml,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Orders — Margaux Collections Admin</title>
<link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="admin-layout">
  <?php include 'includes/sidebar.php'; ?>
  <div class="admin-content">
    <div class="admin-topbar">
      <span class="admin-topbar-title">📋 Manage Orders</span>
      <div class="admin-topbar-actions">
        <span class="badge badge-blue" id="resultCount"><?= count($orders) ?> result<?= count($orders)!=1?'s':'' ?></span>
      </div>
    </div>
    <div class="admin-page">
      <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">✅ Order updated successfully.</div>
      <?php endif; ?>
      <form method="GET" class="card mb-24" id="filterForm">
        <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
          <div class="form-group" style="margin:0;flex:1;min-width:180px;">
            <label class="form-label">Search</label>
            <input type="text" name="search" id="searchInput" class="form-control" placeholder="Name, order #, phone..." value="<?= e($search) ?>" maxlength="100">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Order Status</label>
            <select name="status" id="statusSelect" class="form-control">
              <option value="">All Statuses</option>
              <option value="pending"    <?= $filterStatus==='pending'   ?'selected':'' ?>>Pending</option>
              <option value="processing" <?= $filterStatus==='processing'?'selected':'' ?>>Processing</option>
              <option value="completed"  <?= $filterStatus==='completed' ?'selected':'' ?>>Completed</option>
            </select>
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Payment Status</label>
            <select name="payment" id="paymentSelect" class="form-control">
              <option value="">All</option>
              <option value="pending" <?= $filterPayment==='pending'?'selected':'' ?>>Pending</option>
              <option value="pending_verification" <?= $filterPayment==='pending_verification'?'selected':'' ?>>For Verification</option>
              <option value="paid"    <?= $filterPayment==='paid'   ?'selected':'' ?>>Paid</option>
            </select>
          </div>
         
        </div>
      </form>
      <div class="card">
        <div class="table-wrap" id="ordersTableWrap">
          <?php renderOrdersTable($orders, $itemsByOrder, $statusBadge, $payBadge, $payLabels, $payStatusLabels); ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>✏️ Update Order <span id="modalOrderId"></span></h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="order_id" id="editOrderId">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="form-group">
          <label class="form-label">Customer</label>
          <input type="text" id="editCustomer" class="form-control" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea id="editAddress" class="form-control" rows="2" disabled></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Items Ordered</label>
          <div id="editItemsList" style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:.85rem;max-height:220px;overflow-y:auto;">
            <!-- populated by JS -->
          </div>
        </div>
        <div class="form-group" id="editGcashRefGroup" style="display:none;">
          <label class="form-label">📱 GCash Reference No.</label>
          <input type="text" id="editGcashRef" class="form-control" disabled style="font-weight:700;">
          <div style="font-size:.75rem;color:var(--text-3);margin-top:4px;">
            Match this against your GCash transaction history before marking as Paid.
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Order Status</label>
            <select name="order_status" id="editOrderStatus" class="form-control">
              <option value="pending">Pending</option>
              <option value="processing">Processing</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Payment Status</label>
            <select name="payment_status" id="editPayStatus" class="form-control">
              <option value="pending">Pending</option>
              <option value="pending_verification">For Verification</option>
              <option value="paid">Paid</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="imageLightbox" onclick="closeImageLightbox()" style="cursor:zoom-out;">
  <div style="max-width:90vw;max-height:90vh;display:flex;flex-direction:column;align-items:center;gap:10px;" onclick="event.stopPropagation()">
    <img id="lightboxImg" src="" alt="" style="max-width:90vw;max-height:80vh;object-fit:contain;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.5);">
    <div id="lightboxCaption" style="color:#fff;font-size:.9rem;"></div>
    <button type="button" class="btn btn-outline btn-sm" onclick="closeImageLightbox()">✕ Close</button>
  </div>
</div>

<script>
function openImageLightbox(src, caption) {
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightboxImg').alt = caption || '';
  document.getElementById('lightboxCaption').textContent = caption || '';
  document.getElementById('imageLightbox').classList.add('open');
}
function closeImageLightbox() {
  document.getElementById('imageLightbox').classList.remove('open');
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str ?? '';
  return d.innerHTML;
}

function openEdit(order) {
  document.getElementById('modalOrderId').textContent  = '#'+order.order_id;
  document.getElementById('editOrderId').value         = order.order_id;
  document.getElementById('editCustomer').value        = order.customer_name;
  document.getElementById('editAddress').value         = order.address;
  document.getElementById('editOrderStatus').value     = order.order_status;
  document.getElementById('editPayStatus').value       = order.payment_status;

  const itemsList = document.getElementById('editItemsList');
  const items = order.items || [];
  if (items.length === 0) {
    itemsList.innerHTML = '<span style="color:var(--text-3);">No items on file for this order.</span>';
  } else {
    itemsList.innerHTML = items.map(function (it) {
      const lineTotal = (parseFloat(it.price) * parseInt(it.quantity, 10)).toFixed(2);
      const imgSrc = '../' + (it.image || 'images/product-placeholder.jpg');
      const nameEsc = escapeHtml(it.product_name);
      return '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border);">'
        + '<img src="' + imgSrc + '" alt="' + nameEsc + '" '
        + 'style="width:36px;height:36px;object-fit:cover;border-radius:6px;cursor:pointer;flex-shrink:0;border:1px solid var(--border);" '
        + 'onclick="openImageLightbox(\'' + imgSrc.replace(/'/g, "\\'") + '\', \'' + nameEsc.replace(/'/g, "\\'") + '\')">'
        + '<span style="flex:1;">' + nameEsc + ' <span style="color:var(--text-3);">×' + parseInt(it.quantity, 10) + '</span></span>'
        + '<span>₱' + lineTotal + '</span>'
        + '</div>';
    }).join('');
  }

  const refGroup = document.getElementById('editGcashRefGroup');
  const refInput = document.getElementById('editGcashRef');
  if (order.payment_method === 'gcash' && order.gcash_reference) {
    refInput.value = order.gcash_reference;
    refGroup.style.display = 'block';
  } else {
    refInput.value = '';
    refGroup.style.display = 'none';
  }

  document.getElementById('editModal').classList.add('open');
}
function closeModal() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click',function(e){ if(e.target===this) closeModal(); });

// ── Auto-filter (no more clicking the Filter button) ─────────────────────
(function () {
  const searchInput   = document.getElementById('searchInput');
  const statusSelect  = document.getElementById('statusSelect');
  const paymentSelect = document.getElementById('paymentSelect');
  const tableWrap     = document.getElementById('ordersTableWrap');
  const resultCount   = document.getElementById('resultCount');

  let debounceTimer = null;
  let currentRequest = null;

  function buildParams() {
    const params = new URLSearchParams();
    if (searchInput.value.trim())  params.set('search', searchInput.value.trim());
    if (statusSelect.value)        params.set('status', statusSelect.value);
    if (paymentSelect.value)       params.set('payment', paymentSelect.value);
    return params;
  }

  function runFilter() {
    const params = buildParams();

    // Keep the address bar / refresh / back-button in sync, without reloading.
    const plainUrl = 'manage_orders.php' + (params.toString() ? '?' + params.toString() : '');
    history.replaceState(null, '', plainUrl);

    // Abort a stale in-flight request if the user kept typing/filtering.
    if (currentRequest) currentRequest.abort();
    const controller = new AbortController();
    currentRequest = controller;

    const ajaxParams = new URLSearchParams(params);
    ajaxParams.set('ajax', '1');

    fetch('manage_orders.php?' + ajaxParams.toString(), { signal: controller.signal })
      .then(res => res.json())
      .then(data => {
        tableWrap.innerHTML = data.html;
        resultCount.textContent = data.count + ' result' + (data.count != 1 ? 's' : '');
      })
      .catch(err => {
        if (err.name !== 'AbortError') console.error('Filter request failed:', err);
      });
  }

  // Dropdowns apply immediately.
  statusSelect.addEventListener('change', runFilter);
  paymentSelect.addEventListener('change', runFilter);

  // Search box waits until the user pauses typing (500ms).
  searchInput.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runFilter, 500);
  });

  // Prevent the visible Filter button from doing a full reload too
  // (still works fine if JS is disabled, since it's a real <form>).
  document.getElementById('filterForm').addEventListener('submit', function (e) {
    e.preventDefault();
    clearTimeout(debounceTimer);
    runFilter();
  });
})();
</script>
</body>
</html>
