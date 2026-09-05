<?php
require_once '../includes/security.php';
if (!isset($_SESSION['user_id'])) { header('Location: /Margaux_Collections/auth/login.php'); exit; }
require_once '../config/database.php';
$db     = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $db->prepare("SELECT name FROM users WHERE user_id=?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { session_destroy(); header('Location: /Margaux_Collections/auth/login.php'); exit; }

$categories = $db->query("
    SELECT * FROM categories
    ORDER BY CASE name
        WHEN 'Accessories' THEN 1
        WHEN 'Bikini'      THEN 2
        WHEN 'Bottom'      THEN 3
        WHEN 'Top'         THEN 4
        WHEN 'Dress'       THEN 5
        WHEN 'Pair'        THEN 6
        ELSE 99
    END, name
")->fetch_all(MYSQLI_ASSOC);

$categoryFilter  = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$conditionFilter = in_array($_GET['condition'] ?? '', ['new','preloved']) ? $_GET['condition'] : '';
$search          = trim($_GET['search'] ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

// Hide products that have been sold out (stock=0) for more than 24 hours.
// sold_out_at is auto-managed by a DB trigger whenever stock changes, so
// this stays accurate no matter where the stock update happens (checkout,
// admin restock, etc.). Restocking clears sold_out_at automatically, which
// brings the product right back into this list.
$where[] = "(p.stock > 0 OR p.sold_out_at IS NULL OR p.sold_out_at > NOW() - INTERVAL 24 HOUR)";

if ($categoryFilter > 0) {
    $where[]  = "p.category_id = ?";
    $types   .= 'i';
    $params[] = $categoryFilter;
}
if ($conditionFilter !== '') {
    $where[]  = "p.condition_type = ?";
    $types   .= 's';
    $params[] = $conditionFilter;
}
if (!empty($search)) {
    $where[]  = "(p.product_name LIKE ? OR p.description LIKE ?)";
    $types   .= 'ss';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql  = "SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.category_id
         WHERE " . implode(' AND ', $where) . " ORDER BY p.product_name";
$stmt = $db->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Product Catalog — Margaux Collections</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
/* ============================================================
   NUCLEAR OVERRIDE — kills every pink !important from style.css
   Matches login.php dark burgundy theme exactly
   ============================================================ */

/* 1. Root canvas — same gradient as login.php */
html, body {
  background: linear-gradient(to bottom right, #0e0507 0%, #1a0a0e 30%, #2a0d14 60%, #3d1020 100%) !important;
  color: #f0e6da !important;
  font-family: 'Jost', sans-serif !important;
  min-height: 100vh !important;
}

/* 2. Kill the pink page-hero */
.page-hero {
  background: transparent !important;
  border-bottom: 1px solid rgba(196,80,100,.15) !important;
  color: #f0e6da !important;
  padding: 72px 40px 56px !important;
  text-align: center !important;
  position: relative !important;
}
.page-hero::before {
  content: '' !important;
  position: absolute !important;
  inset: 0 !important;
  background: radial-gradient(ellipse 80% 70% at 50% -10%, rgba(196,80,100,.13) 0%, transparent 70%) !important;
  pointer-events: none !important;
}
.page-hero h1 {
  font-family: 'Playfair Display', serif !important;
  font-size: clamp(2.6rem, 6vw, 4.8rem) !important;
  font-weight: 700 !important;
  color: #f0e6da !important;
  line-height: 1.05 !important;
  letter-spacing: -.5px !important;
  margin: 0 0 14px !important;
  animation: heroIn .8s cubic-bezier(.16,1,.3,1) both !important;
}
.page-hero h1 em {
  font-style: italic !important;
  color: #c45064 !important;
}
.page-hero p {
  color: #7a6058 !important;
  font-size: .92rem !important;
  font-weight: 300 !important;
  margin: 0 !important;
  animation: heroIn .8s .12s cubic-bezier(.16,1,.3,1) both !important;
}
.page-hero p strong { color: #e8a0a8 !important; font-weight: 500 !important; }

/* Hero eyebrow pill */
.hero-eyebrow {
  display: inline-flex !important;
  align-items: center !important;
  gap: 8px !important;
  font-size: .68rem !important;
  font-weight: 600 !important;
  letter-spacing: .28em !important;
  text-transform: uppercase !important;
  color: #c45064 !important;
  padding: 6px 20px !important;
  border: 1px solid rgba(196,80,100,.3) !important;
  border-radius: 40px !important;
  margin-bottom: 22px !important;
  background: rgba(196,80,100,.06) !important;
  animation: heroIn .7s cubic-bezier(.16,1,.3,1) both !important;
}
.hero-divider {
  width: 56px !important; height: 1px !important;
  background: linear-gradient(90deg, transparent, #c45064, transparent) !important;
  margin: 22px auto 0 !important;
  animation: heroIn .7s .2s cubic-bezier(.16,1,.3,1) both !important;
}

/* 3. Container */
.container {
  max-width: 1280px !important;
  margin: 0 auto !important;
  padding: 0 36px !important;
  background: transparent !important;
}

/* 4. Filter section */
.filter-section {
  padding: 32px 0 18px !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  gap: 10px !important;
  margin-bottom: 8px !important;
  animation: fadeUp .6s .3s cubic-bezier(.16,1,.3,1) both !important;
}
.filter-bar {
  display: flex !important;
  gap: 8px !important;
  flex-wrap: wrap !important;
  justify-content: center !important;
}
.filter-divider {
  width: 100% !important;
  height: 1px !important;
  background: rgba(196,80,100,.12) !important;
  margin: 4px 0 !important;
}
.filter-tab {
  padding: 9px 22px !important;
  border-radius: 40px !important;
  font-size: .7rem !important;
  font-weight: 500 !important;
  letter-spacing: .14em !important;
  text-transform: uppercase !important;
  color: #7a6058 !important;
  background: rgba(255,255,255,.03) !important;
  border: 1px solid rgba(196,80,100,.12) !important;
  text-decoration: none !important;
  display: inline-block !important;
  transition: color .25s, border-color .25s, background .25s, transform .2s !important;
  font-family: 'Jost', sans-serif !important;
  box-shadow: none !important;
}
.filter-tab:hover {
  color: #e8a0a8 !important;
  border-color: rgba(196,80,100,.4) !important;
  background: rgba(196,80,100,.06) !important;
  transform: translateY(-2px) !important;
  box-shadow: none !important;
}
.filter-tab.active {
  background: #c45064 !important;
  color: #fff !important;
  border-color: #c45064 !important;
  font-weight: 600 !important;
  box-shadow: 0 6px 18px rgba(196,80,100,.35) !important;
  transform: translateY(-1px) !important;
}

/* 5. Product grid */
.product-grid {
  display: grid !important;
  grid-template-columns: repeat(auto-fill, minmax(285px, 1fr)) !important;
  gap: 20px !important;
  padding: 28px 0 80px !important;
}

/* 6. Product card — full dark override */
.product-card {
  background: rgba(42,13,20,.7) !important;
  border: 1px solid rgba(196,80,100,.12) !important;
  border-radius: 16px !important;
  overflow: hidden !important;
  display: flex !important;
  flex-direction: column !important;
  position: relative !important;
  transition: transform .4s cubic-bezier(.16,1,.3,1), border-color .3s, box-shadow .4s !important;
  animation: cardIn .55s cubic-bezier(.16,1,.3,1) both !important;
  backdrop-filter: blur(4px) !important;
}
.product-card:hover {
  transform: translateY(-7px) !important;
  border-color: rgba(196,80,100,.4) !important;
  box-shadow: 0 28px 56px rgba(0,0,0,.55), 0 0 0 1px rgba(196,80,100,.15) !important;
}

/* stagger */
.product-card:nth-child(1)  { animation-delay:.05s !important }
.product-card:nth-child(2)  { animation-delay:.10s !important }
.product-card:nth-child(3)  { animation-delay:.15s !important }
.product-card:nth-child(4)  { animation-delay:.20s !important }
.product-card:nth-child(5)  { animation-delay:.25s !important }
.product-card:nth-child(6)  { animation-delay:.30s !important }
.product-card:nth-child(7)  { animation-delay:.35s !important }
.product-card:nth-child(8)  { animation-delay:.40s !important }
.product-card:nth-child(9)  { animation-delay:.45s !important }
.product-card:nth-child(10) { animation-delay:.50s !important }

/* 7. Product image */
.product-img {
  position: relative !important;
  height: 272px !important;
  overflow: hidden !important;
  background: #1a0a0e !important;
}
.product-img img {
  width: 100% !important; height: 100% !important;
  object-fit: cover !important;
  display: block !important;
  border-bottom: none !important;
  filter: brightness(.92) saturate(.88) !important;
  transition: transform .65s cubic-bezier(.16,1,.3,1), filter .4s !important;
}
.product-card:hover .product-img img {
  transform: scale(1.07) !important;
  filter: brightness(1.04) saturate(1.04) !important;
}

/* 8. Badges (stock status only) */
.product-type-badge {
  position: absolute !important;
  top: 12px !important; left: 12px !important;
  font-size: .62rem !important;
  font-weight: 600 !important;
  letter-spacing: .13em !important;
  text-transform: uppercase !important;
  padding: 4px 12px !important;
  border-radius: 20px !important;
  backdrop-filter: blur(10px) !important;
  z-index: 3 !important;
  box-shadow: none !important;
  animation: none !important;
}

/* 9. Product info */
.product-info {
  padding: 20px 20px 14px !important;
  flex: 1 !important;
  display: flex !important;
  flex-direction: column !important;
  gap: 5px !important;
  background: transparent !important;
}
.product-name {
  font-family: 'Playfair Display', serif !important;
  font-size: 1.15rem !important;
  font-weight: 400 !important;
  color: #f0e6da !important;
  line-height: 1.3 !important;
  transition: color .25s !important;
}
.product-card:hover .product-name { color: #e8a0a8 !important; }
.category-tag {
  font-size: .67rem !important;
  color: rgba(196,80,100,.6) !important;
  letter-spacing: .1em !important;
  text-transform: uppercase !important;
  font-weight: 500 !important;
  margin-bottom: 0 !important;
}
.product-desc {
  font-size: .8rem !important;
  color: #5a4a42 !important;
  line-height: 1.65 !important;
  flex: 1 !important;
  height: auto !important;
  overflow: hidden !important;
  display: -webkit-box !important;
  -webkit-line-clamp: 3 !important;
  -webkit-box-orient: vertical !important;
  margin-top: 4px !important;
}
.product-price {
  font-family: 'Playfair Display', serif !important;
  font-size: 1.45rem !important;
  font-weight: 400 !important;
  color: #c45064 !important;
  margin-top: 10px !important;
  filter: none !important;
  letter-spacing: .02em !important;
}

/* 10. Actions */
.product-actions {
  padding: 0 20px 18px !important;
  display: flex !important;
  flex-direction: column !important;
  gap: 8px !important;
  background: transparent !important;
}
.qty-control {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
}
.qty-btn {
  width: 34px !important; height: 34px !important;
  background: rgba(196,80,100,.08) !important;
  border: 1px solid rgba(196,80,100,.22) !important;
  color: #c45064 !important;
  font-size: 1rem !important;
  border-radius: 8px !important;
  cursor: pointer !important;
  display: flex !important; align-items: center !important; justify-content: center !important;
  transition: background .2s, transform .15s !important;
  padding: 0 !important;
  font-family: 'Jost', sans-serif !important;
}
.qty-btn:hover {
  background: #c45064 !important;
  color: #fff !important;
  border-color: #c45064 !important;
  transform: scale(1.1) !important;
}
.qty-input {
  width: 46px !important;
  text-align: center !important;
  background: rgba(255,255,255,.04) !important;
  border: 1px solid rgba(196,80,100,.18) !important;
  color: #f0e6da !important;
  border-radius: 8px !important;
  padding: 6px 4px !important;
  font-size: .88rem !important;
  font-family: 'Jost', sans-serif !important;
}
.qty-input:focus {
  outline: none !important;
  border-color: #c45064 !important;
  box-shadow: 0 0 0 3px rgba(196,80,100,.12) !important;
}

/* 11. Buttons — full override */
.btn-primary {
  display: flex !important; align-items: center !important; justify-content: center !important;
  width: 100% !important;
  background:rgba(255,255,255,.04) !important;
  color: #fff !important;
  border: none !important;
  border-radius: 9px !important;
  padding: 13px 18px !important;
  font-family: 'Jost', sans-serif !important;
  font-size: .74rem !important;
  font-weight: 600 !important;
  letter-spacing: .14em !important;
  text-transform: uppercase !important;
  cursor: pointer !important;
  text-decoration: none !important;
  transition: background .25s, transform .2s, box-shadow .25s !important;
  gap: 6px !important;
}
.btn-primary:hover {
  background: #ffffff !important;
  transform: translateY(-2px) !important;
  box-shadow: 0 10px 24px rgba(196,80,100,.35) !important;
}
.btn-primary:active { transform: scale(.98) !important; }

.btn-outline {
  display: flex !important; align-items: center !important; justify-content: center !important;
  width: 100% !important;
  background: transparent !important;
  color: #5a4a42 !important;
  border: 1px solid rgba(196,80,100,.15) !important;
  border-radius: 9px !important;
  padding: 13px 18px !important;
  font-family: 'Jost', sans-serif !important;
  font-size: .74rem !important;
  font-weight: 500 !important;
  letter-spacing: .12em !important;
  text-transform: uppercase !important;
  cursor: not-allowed !important;
}

/* 12. Empty state */
.card {
  background: rgba(42,13,20,.6) !important;
  border: 1px solid rgba(196,80,100,.18) !important;
  color: #f0e6da !important;
  border-radius: 16px !important;
  box-shadow: none !important;
}
.card h3 { color: #f0e6da !important; }
.card p  { color: #7a6058 !important; }

/* 13. Footer */
footer {
  background: #09040a !important;
  color: rgba(240,230,218,.5) !important;
  padding: 72px 36px 40px !important;
  border-top: 1px solid rgba(196,80,100,.1) !important;
  position: relative !important;
}
footer::before {
  content: '' !important;
  position: absolute !important;
  top: 0 !important; left: 0 !important; right: 0 !important; height: 1px !important;
  background: linear-gradient(90deg, transparent, rgba(196,80,100,.4), transparent) !important;
}
footer h4 {
  color: #c45064 !important;
  font-family: 'Jost', sans-serif !important;
  font-size: .72rem !important;
  letter-spacing: .2em !important;
  text-transform: uppercase !important;
  font-weight: 500 !important;
  margin-bottom: 16px !important;
}
footer a {
  color: rgba(240,230,218,.35) !important;
  text-decoration: none !important;
  font-family: 'Jost', sans-serif !important;
  font-size: .82rem !important;
  font-weight: 300 !important;
  transition: color .2s, padding-left .2s !important;
}
footer a:hover { color: #e8a0a8 !important; padding-left: 4px !important; }

/* 14. Toast */
#toast-container {
  position: fixed !important;
  bottom: 28px !important; right: 28px !important;
  z-index: 9999 !important;
  display: flex !important; flex-direction: column !important; gap: 10px !important;
}
.toast {
  background: #2a0d14 !important;
  border: 1px solid rgba(196,80,100,.3) !important;
  color: #f0e6da !important;
  border-radius: 12px !important;
  padding: 14px 18px !important;
  font-family: 'Jost', sans-serif !important;
  font-size: .84rem !important;
  min-width: 240px !important;
  animation: toastIn .35s cubic-bezier(.34,1.56,.64,1) both !important;
  box-shadow: 0 16px 40px rgba(0,0,0,.6) !important;
}
.toast.error { border-color: rgba(196,80,100,.6) !important; }

/* 15. Ripple */
.ripple-effect {
  position: fixed !important;
  border-radius: 50% !important;
  background: rgba(196,80,100,.12) !important;
  transform: scale(0) !important;
  animation: rippleOut .6s ease-out forwards !important;
  pointer-events: none !important;
  z-index: 99999 !important;
}

/* 16. Keyframes */
@keyframes heroIn  { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeUp  { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
@keyframes cardIn  { from { opacity:0; transform:translateY(32px) scale(.96); } to { opacity:1; transform:translateY(0) scale(1); } }
@keyframes toastIn { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:translateX(0); } }
@keyframes rippleOut { to { transform:scale(8); opacity:0; } }

/* 17. Mobile */
@media(max-width:768px){
  .product-grid { grid-template-columns:1fr 1fr !important; gap:12px !important; }
  .page-hero { padding:48px 20px 40px !important; }
  .container { padding:0 16px !important; }
  .filter-tab { font-size:.62rem !important; padding:8px 14px !important; }
}
@media(max-width:480px){
  .product-grid { grid-template-columns:1fr !important; }
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-hero">
  <div class="hero-eyebrow">Margaux Collections</div>
  <h1>Our <em>Collection</em></h1>
  <p>Discover every product in the Margaux Collections range</p>
  <div class="hero-divider"></div>
</div>

<div class="container">

  <div class="filter-section">

    <div class="filter-bar">
      <a href="products.php?condition=<?= urlencode($conditionFilter) ?>" class="filter-tab <?= $categoryFilter===0 ? 'active' : '' ?>">
        All
      </a>
      <?php foreach ($categories as $cat): ?>
      <a href="products.php?category=<?= $cat['category_id'] ?>&condition=<?= urlencode($conditionFilter) ?>"
         class="filter-tab <?= $categoryFilter===(int)$cat['category_id'] ? 'active' : '' ?>">
        <?= htmlspecialchars($cat['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="filter-bar" style="margin-top:10px;">
      <?php
        $condLinkBase = 'products.php?' . ($categoryFilter > 0 ? 'category=' . $categoryFilter . '&' : '');
      ?>
      <a href="<?= $condLinkBase ?>condition=" class="filter-tab <?= $conditionFilter==='' ? 'active' : '' ?>">
        All Conditions
      </a>
      <a href="<?= $condLinkBase ?>condition=new" class="filter-tab <?= $conditionFilter==='new' ? 'active' : '' ?>">
         New
      </a>
      <a href="<?= $condLinkBase ?>condition=preloved" class="filter-tab <?= $conditionFilter==='preloved' ? 'active' : '' ?>">
         Preloved
      </a>
    </div>
  </div>

  <?php if (empty($products)): ?>
  <div class="card" style="text-align:center;padding:70px 24px;">
    <div style="font-size:2.4rem;margin-bottom:18px;opacity:.25;">◆</div>
    <h3 style="font-family:'Playfair Display',serif;font-size:1.9rem;font-weight:400;margin-bottom:10px;">Nothing found</h3>
    <p style="margin-bottom:26px;">Try adjusting your filters or search terms.</p>
    <a href="products.php" class="btn-primary" style="display:inline-flex;width:auto;padding:13px 40px;">Clear Filters</a>
  </div>
  <?php else: ?>
  <div class="product-grid">
    <?php foreach ($products as $p): ?>
    <div class="product-card">
      <div class="product-img">
        <img src="../<?= htmlspecialchars($p['image']) ?>"
             alt="<?= htmlspecialchars($p['product_name']) ?>"
             onerror="this.src='../images/product-placeholder.jpg'">
        <?php if ($p['condition_type'] === 'preloved'): ?>
          <span class="product-type-badge" style="background:rgba(180,83,9,.85)!important;color:#fff!important;border:none!important;"> Preloved</span>
        <?php else: ?>
          <span class="product-type-badge" style="background:rgba(21,128,61,.85)!important;color:#fff!important;border:none!important;"> New</span>
        <?php endif; ?>
        <?php if ($p['stock'] <= 10 && $p['stock'] > 0): ?>

        <?php elseif ($p['stock'] == 0): ?>
          <span class="product-type-badge" style="left:auto!important;right:12px!important;background:rgba(42,13,20,.7)!important;color:#5a4a42!important;border:1px solid rgba(196,80,100,.1)!important;">Sold Out</span>
        <?php endif; ?>
      </div>

      <div class="product-info">
        <div class="product-name"><?= htmlspecialchars($p['product_name']) ?></div>
        <?php if (!empty($p['category_name'])): ?>
        <div class="category-tag"><?= htmlspecialchars($p['category_name']) ?></div>
        <?php endif; ?>
        <div class="product-desc"><?= htmlspecialchars($p['description']) ?></div>
        <div class="product-price">₱ <?= number_format($p['price'],2) ?></div>
      </div>

      <?php if ($p['stock'] > 0): ?>
      <div class="product-actions">
        <div class="qty-control">
          <button class="qty-btn" onclick="changeQty(<?= $p['product_id'] ?>, -1)">−</button>
          <input type="number" class="qty-input" id="qty-<?= $p['product_id'] ?>" value="1" min="1" max="<?= $p['stock'] ?>">
          <button class="qty-btn" onclick="changeQty(<?= $p['product_id'] ?>, 1)">+</button>
          <span style="font-size:.7rem;color:#5a4a42;">/ <?= $p['stock'] ?> left</span>
        </div>
        <button class="btn-primary"
                onclick="addToCart(<?= $p['product_id'] ?>, '<?= htmlspecialchars(addslashes($p['product_name'])) ?>', <?= $p['price'] ?>)">
          Add to Cart
        </button>
      </div>
      <?php else: ?>
      <div class="product-actions">
        <button class="btn-outline" disabled>Out of Stock</button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<!-- FOOTER -->
<footer>
  <div style="max-width:1280px;margin:auto;">
    <div style="display:grid;grid-template-columns:2.2fr 1fr 1fr 1fr;gap:48px;margin-bottom:52px;">

      <!-- Brand Column -->
      <div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
          <svg width="40" height="40" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="24" cy="24" r="23" fill="rgba(196,80,100,0.1)" stroke="rgba(196,80,100,0.3)" stroke-width="1"/>
            <path d="M10 34V14L18 27L24 16L30 27L38 14V34" stroke="url(#fLogoGradP)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <circle cx="24" cy="10" r="2.5" fill="#c45064"/>
            <defs>
              <linearGradient id="fLogoGradP" x1="10" y1="14" x2="38" y2="34" gradientUnits="userSpaceOnUse">
                <stop stop-color="#e8c87a"/><stop offset="0.5" stop-color="#c45064"/><stop offset="1" stop-color="#c9a0a8"/>
              </linearGradient>
            </defs>
          </svg>
          <div>
            <div style="font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;color:#f0e6da;line-height:1.1;">
              Margaux <em style="font-style:italic;color:#c45064;">Collections</em>
            </div>
            <div style="font-size:.58rem;color:#c8a96a;letter-spacing:.18em;text-transform:uppercase;margin-top:2px;">✦ Fashion Boutique</div>
          </div>
        </div>
        <p style="font-size:.82rem;line-height:1.85;max-width:270px;color:rgba(240,230,218,.4);font-family:'Jost',sans-serif;font-weight:300;">
          Margaux Collections — bringing premium Ardeur de France products and wellness solutions to Filipino families.
        </p>
        <!-- Social Icons -->
        <div style="display:flex;gap:10px;margin-top:20px;">
          <!-- Facebook -->
          <a href="https://www.facebook.com/gilian.legaspi.1" target="_blank"
             style="width:36px;height:36px;border-radius:8px;background:rgba(196,80,100,.07);border:1px solid rgba(196,80,100,.2);display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background .2s,transform .2s;"
             onmouseover="this.style.background='rgba(196,80,100,.2)';this.style.transform='translateY(-2px)'"
             onmouseout="this.style.background='rgba(196,80,100,.07)';this.style.transform=''">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z" stroke="#c45064" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
          <!-- Instagram -->
          <a href="https://www.instagram.com/mennggayyy/"
             style="width:36px;height:36px;border-radius:8px;background:rgba(196,80,100,.07);border:1px solid rgba(196,80,100,.2);display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background .2s,transform .2s;"
             onmouseover="this.style.background='rgba(196,80,100,.2)';this.style.transform='translateY(-2px)'"
             onmouseout="this.style.background='rgba(196,80,100,.07)';this.style.transform=''">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect x="2" y="2" width="20" height="20" rx="5" ry="5" stroke="#c45064" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <circle cx="12" cy="12" r="4" stroke="#c45064" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <circle cx="17.5" cy="6.5" r="1" fill="#c45064"/>
            </svg>
          </a>
        </div>
      </div>

      <!-- Shop Column -->
      <div>
        <h4>Shop</h4>
        <a href="products.php">All Products</a>
      </div>

      <!-- Account Column -->
      <div>
        <h4>Account</h4>
        <a href="https://www.facebook.com/gilian.legaspi.1" target="_blank">Facebook</a>
        <a href="https://www.instagram.com/mennggayyy/" target="_blank">Instagram</a>
      </div>

      <!-- Info Column -->
      <div>
        <h4>Info</h4>
        <a href="https://www.MargauxCollections.com" target="_blank">www.MargauxCollections.com</a>
      </div>

    </div>

    <!-- Bottom Bar -->
    <div style="border-top:1px solid rgba(196,80,100,.1);padding-top:26px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;font-size:.75rem;color:rgba(240,230,218,.25);font-family:'Jost',sans-serif;">
      <span>© 2026 Margaux Collections. All rights reserved.</span>
      <div style="display:flex;align-items:center;gap:6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z" stroke="rgba(196,80,100,.5)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span>MargauxCollection</span>
        <span style="color:rgba(196,80,100,.3);">·</span>
        <span>🌐 www.MargauxCollections.com</span>
      </div>
    </div>
  </div>
</footer>

<div id="toast-container"></div>
<script>
function changeQty(id, delta) {
  const input = document.getElementById('qty-' + id);
  let val = parseInt(input.value) + delta;
  input.value = Math.max(1, Math.min(val, parseInt(input.max)));
}
function addToCart(productId, name, price) {
  const qty = parseInt(document.getElementById('qty-' + productId).value) || 1;
  fetch('/Margaux_Collections/customer/cart_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=add&product_id=${productId}&qty=${qty}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showToast('✦ ' + name + ' added to cart', 'success');
      const badge = document.querySelector('.cart-badge');
      if (badge) badge.textContent = data.cart_count;
    } else {
      showToast('✕ ' + (data.message || 'Failed to add'), 'error');
    }
  })
  .catch(() => showToast('✕ Network error', 'error'));
}
function showToast(msg, type = '') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(() => {
    t.style.transition = 'opacity .3s, transform .3s';
    t.style.opacity = '0'; t.style.transform = 'translateX(14px)';
    setTimeout(() => t.remove(), 320);
  }, 3200);
}
document.addEventListener('click', e => {
  const r = document.createElement('div');
  const s = 60;
  r.className = 'ripple-effect';
  r.style.cssText = `width:${s}px;height:${s}px;left:${e.clientX-s/2}px;top:${e.clientY-s/2}px`;
  document.body.appendChild(r);
  setTimeout(() => r.remove(), 620);
});
</script>
</body>
</html>
