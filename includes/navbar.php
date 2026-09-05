<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: /Margaux_Collections/auth/login.php');
    exit;
}
require_once __DIR__ . '/../config/database.php';
$db = getDB();

/**
 * Safely add a column only if it doesn't already exist.
 * Works on any MySQL/MariaDB version (doesn't rely on "ADD COLUMN IF NOT EXISTS" syntax).
 */
function ensureColumnExists($db, $table, $column, $definition) {
    $check = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $check->bind_param('ss', $table, $column);
    $check->execute();
    $exists = (int)($check->get_result()->fetch_assoc()['cnt'] ?? 0);
    $check->close();

    if ($exists === 0) {
        $db->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

ensureColumnExists($db, 'users', 'address', '`address` TEXT AFTER `contact_number`');
ensureColumnExists($db, 'users', 'profile_photo', '`profile_photo` VARCHAR(255) DEFAULT NULL AFTER `address`');

$db->query("CREATE TABLE IF NOT EXISTS user_payment_accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_type ENUM('gcash') NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB");

$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: /Margaux_Collections/auth/login.php');
    exit;
}

$initials    = strtoupper(substr($user['name'] ?? 'U', 0, 1));
$currentPage = basename($_SERVER['PHP_SELF']);

$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += (int)($item['qty'] ?? 0);
    }
}

$isProductsActive = $currentPage === 'products.php';
$isOrderActive    = $currentPage === 'my_orders.php';
?>

<style>
/* ══════════════════════════════════════
   NAVBAR — BABY PINK THEME
══════════════════════════════════════ */
.navbar {
  background: linear-gradient(135deg, rgba(196,80,100,.3) 0%, rgba(196,80,100,.3) 100%);
  border-bottom: 1px solid rgba(231,84,128,0.2);
  position: sticky; top: 0; z-index: 1000;
  box-shadow: 0 2px 24px rgba(231,84,128,0.25);
}
.navbar-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  height: 66px;
  max-width: 100%;
  gap: 12px;
}

/* ── LEFT: Brand ── */
.navbar-brand {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none; flex-shrink: 0;
  transition: opacity 0.2s;
}
.navbar-brand:hover { opacity: 0.85; }
.brand-text { display: flex; flex-direction: column; line-height: 1.2; }
.brand-name {
  font-family: 'Sora', sans-serif;
  font-weight: 800; font-size: .88rem;
  color: #ffffff; letter-spacing: .04em;
  white-space: nowrap;
}
.brand-sub {
  font-size: .58rem; font-weight: 700;
  color: rgba(255,255,255,0.85); letter-spacing: .1em;
  text-transform: uppercase;
}

/* ── Hamburger (mobile nav toggle) ── */
.navbar-hamburger {
  display: none;
  align-items: center; justify-content: center;
  background: rgba(255,255,255,0.25);
  border: 1px solid rgba(255,255,255,0.4);
  border-radius: 10px;
  width: 38px; height: 38px;
  cursor: pointer; color: #fff; font-size: 1.05rem;
  flex-shrink: 0; transition: all 0.25s;
}
.navbar-hamburger:hover {
  background: rgba(255,255,255,0.4);
  border-color: rgba(255,255,255,0.7);
}

/* ── CENTER: Nav Links (desktop) ── */
.navbar-nav {
  display: flex; align-items: center;
  list-style: none; margin: 0; padding: 0;
  gap: 2px; flex: 1; justify-content: center;
}
.navbar-nav li { display: flex; }
.nav-link {
  padding: 9px 15px; border-radius: 9px;
  color: #ffffff !important;
  font-size: .78rem; font-weight: 700;
  text-decoration: none !important;
  letter-spacing: .05em; text-transform: uppercase;
  transition: all 0.25s ease; white-space: nowrap;
  position: relative;
}
.nav-link::after {
  content: '';
  position: absolute; bottom: 4px; left: 50%; right: 50%;
  height: 2px; border-radius: 2px;
  background: #fff;
  transition: all 0.3s ease;
}
.nav-link:hover {
  color: #2a0d14 !important;
  background: rgba(255,255,255,0.2);
}
.nav-link:hover::after { left: 14px; right: 14px; }
.nav-link.active {
  color: #2a0d14 !important;
  background: rgba(255,255,255,0.25);
}
.nav-link.active::after { left: 14px; right: 14px; }

/* ── Mobile off-canvas nav panel ── */
.mobile-nav-panel {
  display: none;
  position: absolute; top: 100%; left: 0; right: 0;
  background: #2a0d14;
  border-top: 1px solid rgba(255,255,255,.15);
  box-shadow: 0 12px 30px rgba(0,0,0,.4);
  padding: 10px 16px 16px;
  flex-direction: column;
  gap: 4px;
  z-index: 1050;
}
.mobile-nav-panel.open { display: flex; }
.mobile-nav-panel .nav-link {
  padding: 13px 14px;
  font-size: .8rem;
}
.mobile-nav-panel .nav-link::after { display: none; }
.mobile-nav-panel .nav-link.active { background: rgba(255,255,255,.15); }

/* ── RIGHT: Search + Cart + Profile ── */
.navbar-right {
  display: flex; align-items: center;
  gap: 8px; flex-shrink: 0;
}

/* Search (desktop inline) */
.navbar-search {
  display: flex; align-items: center;
  background: rgba(255,255,255,0.25);
  border: 1px solid rgba(255,255,255,0.4);
  border-radius: 10px; overflow: hidden;
  transition: all 0.25s ease;
}
.navbar-search:focus-within {
  border-color: rgba(255,255,255,0.8);
  background: rgba(255,255,255,0.35);
  box-shadow: 0 0 0 3px rgba(255,255,255,0.2);
}
.navbar-search input {
  background: transparent; border: none; outline: none;
  color: #ffffff; font-size: .8rem; font-family: inherit;
  padding: 8px 12px; width: 190px;
}
.navbar-search input::placeholder { color: rgba(255,255,255,0.6); }
.search-btn {
  background: transparent; border: none;
  color: rgba(255,255,255,0.7); padding: 8px 10px;
  cursor: pointer; font-size: .85rem;
  transition: color 0.2s; line-height: 1;
}
.search-btn:hover { color: #fff; }

/* Search toggle (mobile icon-only button) */
.search-toggle-btn {
  display: none;
  align-items: center; justify-content: center;
  background: rgba(255,255,255,0.25);
  border: 1px solid rgba(255,255,255,0.4);
  border-radius: 10px;
  width: 38px; height: 38px;
  cursor: pointer; color: #fff; font-size: 1rem;
  flex-shrink: 0; transition: all 0.25s;
}
.search-toggle-btn:hover {
  background: rgba(255,255,255,0.4);
  border-color: rgba(255,255,255,0.7);
}

/* Mobile search dropdown panel */
.mobile-search-panel {
  display: none;
  position: absolute; top: 100%; left: 0; right: 0;
  background: #2a0d14;
  padding: 12px 16px;
  border-top: 1px solid rgba(255,255,255,.15);
  z-index: 1050;
}
.mobile-search-panel.open { display: block; }
.mobile-search-panel form {
  display: flex; align-items: center;
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.3);
  border-radius: 10px; overflow: hidden;
}
.mobile-search-panel input {
  flex: 1; background: transparent; border: none; outline: none;
  color: #fff; padding: 11px 14px; font-size: .85rem; font-family: inherit;
}
.mobile-search-panel input::placeholder { color: rgba(255,255,255,.6); }
.mobile-search-panel button {
  background: transparent; border: none; color: #fff;
  padding: 10px 16px; cursor: pointer; font-size: .95rem;
}

/* Cart */
.cart-btn {
  display: flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,0.25);
  border: 1px solid rgba(255,255,255,0.4);
  border-radius: 10px; padding: 8px 14px;
  color: #ffffff !important; text-decoration: none !important;
  font-size: .82rem; font-weight: 700;
  transition: all 0.25s; position: relative;
  white-space: nowrap;
}
.cart-btn:hover {
  background: rgba(255,255,255,0.4);
  border-color: rgba(255,255,255,0.7);
}
.cart-badge {
  background: #a6446a; color: #fff;
  font-size: .62rem; font-weight: 800;
  padding: 2px 6px; border-radius: 10px;
  min-width: 18px; text-align: center;
  line-height: 1.4;
}
.cart-label { font-size: .78rem; color: rgba(255,255,255,0.85); }

/* Profile Button */
.profile-btn {
  display: flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,0.25);
  border: 1px solid rgba(255,255,255,0.4);
  border-radius: 10px; padding: 6px 12px;
  cursor: pointer; color: #fff;
  transition: all 0.25s; font-family: inherit;
}
.profile-btn:hover {
  background: rgba(255,255,255,0.4);
  border-color: rgba(255,255,255,0.7);
}
.profile-avatar {
  width: 30px; height: 30px; border-radius: 50%;
  background: linear-gradient(135deg, #e75480, #c9185b);
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: .8rem; color: #fff;
  overflow: hidden; flex-shrink: 0;
  border: 1.5px solid rgba(255,255,255,0.5);
}
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-info { display: flex; flex-direction: column; text-align: left; }
.profile-name { font-size: .8rem; font-weight: 700; color: #fff; line-height: 1.2; }
.profile-caret { font-size: .55rem; opacity: 0.6; margin-left: 2px; }

/* Dropdown Menu */
.profile-dropdown { position: relative; }
.dropdown-menu {
  position: absolute; right: 0; top: calc(100% + 10px);
  background: #ffffff; border-radius: 14px;
  box-shadow: 0 12px 40px rgba(231,84,128,0.2);
  min-width: 230px; padding: 8px 0;
  opacity: 0; pointer-events: none;
  transform: translateY(-6px) scale(0.98);
  transition: all 0.2s ease; z-index: 1100;
  border: 1px solid #f9c8d8;
}
.profile-dropdown.open .dropdown-menu {
  opacity: 1; pointer-events: all;
  transform: translateY(0) scale(1);
}
.dropdown-header {
  padding: 14px 18px; border-bottom: 1px solid #fce7f3; margin-bottom: 4px;
}
.dh-name  { font-weight: 700; font-size: .9rem; color: #4a0020; }
.dh-email { font-size: .75rem; color: #f9a8c9; margin-top: 2px; }
.dropdown-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 18px; font-size: .85rem; color: #9d174d;
  transition: all 0.2s; text-decoration: none;
}
.dropdown-item:hover { background: #fff0f5; color: #4a0020; padding-left: 22px; }
.dropdown-item.danger { color: #ef4444; }
.dropdown-item.danger:hover { background: #fff1f2; color: #dc2626; }
.dropdown-divider { height: 1px; background: #fce7f3; margin: 4px 0; }

/* ── Notification Bell ── */
.notif-wrapper   { position: relative; display: inline-block; }
.notif-bell {
  background: rgba(255,255,255,0.25);
  border: 1px solid rgba(255,255,255,0.4);
  border-radius: 10px; padding: 8px 12px;
  cursor: pointer; font-size: 1.1rem;
  position: relative; color: #fff;
  transition: all 0.25s;
}
.notif-bell:hover {
  background: rgba(255,255,255,0.4);
  border-color: rgba(255,255,255,0.7);
}
.notif-badge {
  position: absolute; top: 2px; right: 2px;
  background: #c9185b; color: #fff;
  font-size: .62rem; font-weight: 800;
  border-radius: 999px; padding: 1px 5px;
  min-width: 17px; text-align: center; line-height: 1.4;
}
.notif-dropdown {
  position: absolute; right: 0; top: calc(100% + 8px);
  width: 320px; background: #fff;
  border: 1px solid #f9c8d8; border-radius: 12px;
  box-shadow: 0 8px 30px rgba(231,84,128,.2);
  z-index: 1100; overflow: hidden;
}
.notif-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 16px; border-bottom: 1px solid #fce7f3; font-size: .9rem;
}
.notif-mark-all {
  background: none; border: none; cursor: pointer;
  font-size: .78rem; color: #e75480; text-decoration: underline;
}
.notif-list      { max-height: 320px; overflow-y: auto; }
.notif-item {
  padding: 12px 16px; border-bottom: 1px solid #fdf2f8;
  cursor: pointer; transition: background .15s;
}
.notif-item:hover  { background: #fff0f5; }
.notif-item.unread { background: #fce7f3; }
.notif-item-title  { font-weight: 600; font-size: .85rem; margin-bottom: 3px; color: #4a0020; }
.notif-item-msg    { font-size: .78rem; color: #9d174d; }
.notif-item-time   { font-size: .72rem; color: #f9a8c9; margin-top: 4px; }
.notif-empty       { padding: 32px; text-align: center; color: #f9a8c9; font-size: .85rem; }

/* ── New-conversation compose (added) ── */
.chat-new-btn {
  background: #fdf2f8; border: 1px solid #f9c8d8; color: #e75480;
  font-size: .74rem; font-weight: 700; padding: 5px 10px;
  border-radius: 8px; cursor: pointer; transition: background .15s;
}
.chat-new-btn:hover { background: #fce7f3; }
.chat-empty-cta {
  padding: 28px 20px; text-align: center;
}
.chat-empty-cta-btn {
  margin-top: 10px; background: linear-gradient(135deg,#f9b8cc,#e75480);
  color: #fff; border: none; border-radius: 9px; padding: 9px 18px;
  font-weight: 700; font-size: .82rem; cursor: pointer;
}

/* ══════════════════════════════════════
   RESPONSIVE — MOBILE
══════════════════════════════════════ */
@media (max-width: 860px) {
  .navbar-inner { padding: 0 14px; gap: 6px; }
  .navbar-nav { display: none; }
  .navbar-hamburger { display: flex; }
  .navbar-search { display: none; }
  .search-toggle-btn { display: flex; }
  .navbar-right { gap: 6px; }
  .cart-btn { padding: 8px 10px; }
  .cart-label { display: none; }
  .profile-btn { padding: 6px 8px; }
  .profile-info { display: none; }
  .profile-caret { display: none; }
  .brand-name { font-size: .76rem; }
  .navbar-brand img { width: 34px; height: 34px; }
  .notif-dropdown { width: 280px; right: -60px; }
  .notif-dropdown::before { content: none; }
}
@media (max-width: 380px) {
  .brand-text { display: none; }
}
</style>

<nav class="navbar">
  <div class="navbar-inner">

    <!-- ── Mobile hamburger (nav links) ── -->
    <button type="button" class="navbar-hamburger" id="navHamburger" onclick="toggleMobileMenu(event)" title="Menu">
      ☰
    </button>

    <!-- ── LEFT: Logo + Brand ── -->
    <a href="/Margaux_Collections/customer/products.php" class="navbar-brand">
      <img src="/Margaux_Collections/images/logo.jpg"
           alt="Margaux Collections Logo"
           style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.4);flex-shrink:0;">
      <div class="brand-text">
        <div class="brand-name">Margaux Collections</div>
        <div class="brand-sub"></div>
      </div>
    </a>

    <!-- ── CENTER: Nav Links (desktop only) ── -->
    <ul class="navbar-nav">
      <li>
        <a href="/Margaux_Collections/customer/products.php"
           class="nav-link <?= $isProductsActive ? 'active' : '' ?>">
          PRODUCTS
        </a>
      </li>
      <li>
        <a href="/Margaux_Collections/customer/my_orders.php"
           class="nav-link <?= $isOrderActive ? 'active' : '' ?>">
          ORDER HISTORY
        </a>
      </li>
    </ul>

    <!-- ── RIGHT: Search + Cart + Profile ── -->
    <div class="navbar-right">

      <!-- Search (desktop inline) -->
      <form method="GET" action="/Margaux_Collections/customer/products.php" class="navbar-search">
        <input type="text" name="search" placeholder="Search products..."
               value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        <button type="submit" class="search-btn">🔍</button>
      </form>

      <!-- Search toggle (mobile icon-only) -->
      <button type="button" class="search-toggle-btn" id="searchToggleBtn" onclick="toggleMobileSearch(event)" title="Search">
        🔍
      </button>

      <!-- Notification Bell -->
      <div class="notif-wrapper" id="notifWrapper">
        <button class="notif-bell" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifications">
          🔔
          <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
        </button>
        <div class="notif-dropdown" id="notifDropdown" style="display:none;">
          <div class="notif-header">
            <strong>🔔 Notifications</strong>
            <button onclick="markAllNotifRead()" class="notif-mark-all">Mark all read</button>
          </div>
          <div class="notif-list" id="notifList">
            <div class="notif-empty">No notifications yet.</div>
          </div>
        </div>
      </div>

      <!-- Cart -->
      <a href="/Margaux_Collections/customer/cart.php" class="cart-btn">
        🛒
        <?php if ($cartCount > 0): ?>
          <span class="cart-badge"><?= $cartCount ?></span>
        <?php else: ?>
          <span class="cart-label">Cart</span>
        <?php endif; ?>
      </a>

      <!-- Profile Dropdown -->
      <div class="profile-dropdown" id="profileDropdown">
        <button class="profile-btn" id="profileBtn" type="button" aria-expanded="false">
          <div class="profile-avatar">
            <?php if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../' . $user['profile_photo'])): ?>
              <img src="/Margaux_Collections/<?= htmlspecialchars($user['profile_photo']) ?>" alt="Profile">
            <?php else: ?>
              <?= $initials ?>
            <?php endif; ?>
          </div>
          <div class="profile-info">
            <div class="profile-name"><?= htmlspecialchars($user['name']) ?></div>
          </div>
          <span class="profile-caret">▼</span>
        </button>

        <div class="dropdown-menu" id="dropdownMenu" role="menu">
          <div class="dropdown-header">
            <div class="dh-name"><?= htmlspecialchars($user['name']) ?></div>
            <div class="dh-email"><?= htmlspecialchars($user['email']) ?></div>
          </div>
          <a href="/Margaux_Collections/customer/profile.php" class="dropdown-item">👤 My Profile</a>
          <div class="dropdown-divider"></div>
          <a href="/Margaux_Collections/auth/logout.php" class="dropdown-item danger">🚪 Logout</a>
        </div>
      </div>

    </div><!-- /.navbar-right -->
  </div>

  <!-- ── Mobile off-canvas nav panel (PRODUCTS / ORDER HISTORY) ── -->
  <div class="mobile-nav-panel" id="mobileNavPanel">
    <a href="/Margaux_Collections/customer/products.php"
       class="nav-link <?= $isProductsActive ? 'active' : '' ?>">
      PRODUCTS
    </a>
    <a href="/Margaux_Collections/customer/my_orders.php"
       class="nav-link <?= $isOrderActive ? 'active' : '' ?>">
      ORDER HISTORY
    </a>
  </div>

  <!-- ── Mobile search panel ── -->
  <div class="mobile-search-panel" id="mobileSearchPanel">
    <form method="GET" action="/Margaux_Collections/customer/products.php">
      <input type="text" name="search" placeholder="Search products..."
             value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
      <button type="submit">🔍</button>
    </form>
  </div>
</nav>

<!-- ================================================================
  CUSTOMER SUPPORT CHAT BUBBLE — BABY PINK
================================================================ -->
<div id="supportChatWidget" style="position:fixed;bottom:24px;right:24px;z-index:9000;font-family:inherit;">

  <!-- Toggle button -->
  <button id="chatBubbleBtn" onclick="chatToggle()" title="Customer Support"
    style="width:58px;height:58px;border-radius:50%;
           background:linear-gradient(135deg,#f9b8cc 0%,#e75480 100%);
           border:none;cursor:pointer;
           box-shadow:0 4px 20px rgba(231,84,128,.4),0 0 0 0 rgba(231,84,128,.4);
           font-size:1.5rem;color:#fff;position:relative;
           transition:transform .2s,box-shadow .2s;
           animation:chatPulse 3s infinite;">
    <span id="chatBubbleIcon">💬</span>
    <span id="chatBubbleDot" style="display:none;position:absolute;top:2px;right:2px;
          background:#c9185b;color:#fff;font-size:.6rem;font-weight:800;
          border-radius:999px;padding:1px 5px;min-width:17px;
          text-align:center;line-height:1.4;">0</span>
  </button>

  <!-- Chat window -->
  <div id="chatWindow"
    style="display:none;position:absolute;bottom:70px;right:0;
           width:350px;max-width:calc(100vw - 32px);background:#fff;border-radius:18px;
           box-shadow:0 16px 50px rgba(231,84,128,.2);
           border:1px solid #f9c8d8;overflow:hidden;">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#f9b8cc 0%,#e75480 100%);
                padding:16px 18px;display:flex;justify-content:space-between;align-items:center;">
      <div>
        <div style="font-weight:800;color:#fff;font-size:.92rem;letter-spacing:.01em;">
          💬 Customer Support
        </div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.75);margin-top:2px;">
          Margaux Collections
        </div>
      </div>
      <button onclick="chatToggle()"
        style="background:rgba(255,255,255,.2);border:none;color:#fff;
               width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:.9rem;
               display:flex;align-items:center;justify-content:center;transition:.2s;">✕</button>
    </div>

    <!-- View: Conversation list -->
    <div id="chatViewList">
      <div style="padding:12px 16px;border-bottom:1px solid #fce7f3;
                  display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:.82rem;font-weight:700;color:#4a0020;">Your Messages</span>
        <button type="button" class="chat-new-btn" onclick="chatShowNewForm()">+ New</button>
      </div>
      <div id="chatConvoList" style="max-height:280px;overflow-y:auto;">
        <div style="padding:20px;text-align:center;color:#f9a8c9;font-size:.82rem;">Loading...</div>
      </div>
    </div>

    <!-- View: New conversation compose -->
    <div id="chatViewNew" style="display:none;">
      <div style="padding:10px 16px;border-bottom:1px solid #fce7f3;
                  display:flex;align-items:center;gap:8px;">
        <button type="button" onclick="chatShowList()"
          style="background:none;border:none;color:#e75480;font-size:.8rem;cursor:pointer;padding:0;">← Back</button>
        <span style="font-size:.82rem;font-weight:700;color:#4a0020;">New Message</span>
      </div>
      <div style="padding:14px 16px;display:flex;flex-direction:column;gap:10px;">
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#9d174d;display:block;margin-bottom:4px;">
            Subject (optional)
          </label>
          <input id="newChatSubject" type="text" placeholder="e.g. Order inquiry"
            style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #f9c8d8;
                   border-radius:9px;font-family:inherit;font-size:.82rem;outline:none;">
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#9d174d;display:block;margin-bottom:4px;">
            Message
          </label>
          <textarea id="newChatMessage" rows="4" placeholder="How can we help you?"
            style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #f9c8d8;
                   border-radius:9px;font-family:inherit;font-size:.82rem;outline:none;resize:vertical;"></textarea>
        </div>
        <button type="button" id="newChatSendBtn" onclick="chatSendNew()"
          style="background:linear-gradient(135deg,#f9b8cc,#e75480);color:#fff;
                 border:none;border-radius:9px;padding:10px 14px;
                 font-weight:700;font-size:.84rem;cursor:pointer;">Send Message</button>
      </div>
    </div>

    <!-- View: Chat thread -->
    <div id="chatViewThread" style="display:none;">
      <div style="padding:10px 16px;border-bottom:1px solid #fce7f3;
                  display:flex;align-items:center;gap:8px;">
        <button onclick="chatShowList()"
          style="background:none;border:none;color:#e75480;font-size:.8rem;cursor:pointer;padding:0;">← Back</button>
        <span id="threadTitle" style="font-size:.82rem;font-weight:700;color:#4a0020;
              white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;"></span>
      </div>
      <div id="threadMsgs"
        style="max-height:240px;overflow-y:auto;padding:12px 14px;
               display:flex;flex-direction:column;gap:8px;"></div>
      <div style="padding:10px 12px;border-top:1px solid #fce7f3;display:flex;gap:8px;">
        <input id="threadInput" type="text" placeholder="Type a message..."
          style="flex:1;padding:9px 12px;border:1px solid #f9c8d8;border-radius:9px;
                 font-family:inherit;font-size:.82rem;outline:none;transition:border-color .2s;"
          onfocus="this.style.borderColor='#e75480'" onblur="this.style.borderColor='#f9c8d8'">
        <button onclick="chatSend()"
          style="background:linear-gradient(135deg,#f9b8cc,#e75480);color:#fff;
                 border:none;border-radius:9px;padding:9px 14px;
                 font-weight:700;font-size:.82rem;cursor:pointer;">➤</button>
      </div>
    </div>

  </div><!-- /chatWindow -->
</div><!-- /supportChatWidget -->

<style>
@keyframes chatPulse {
  0%,100% { box-shadow: 0 4px 20px rgba(231,84,128,.4), 0 0 0 0 rgba(231,84,128,.4); }
  50%      { box-shadow: 0 4px 20px rgba(231,84,128,.4), 0 0 0 8px rgba(231,84,128,0); }
}
#chatBubbleBtn:hover { transform: scale(1.08); }
</style>

<script>
// ══════════════════════════════════════════════════════════════════
//  MOBILE HAMBURGER MENU + MOBILE SEARCH
// ══════════════════════════════════════════════════════════════════
window.toggleMobileMenu = function (e) {
  if (e) e.stopPropagation();
  const panel = document.getElementById('mobileNavPanel');
  const searchPanel = document.getElementById('mobileSearchPanel');
  panel.classList.toggle('open');
  searchPanel.classList.remove('open');
};

window.toggleMobileSearch = function (e) {
  if (e) e.stopPropagation();
  const panel = document.getElementById('mobileSearchPanel');
  const navPanel = document.getElementById('mobileNavPanel');
  panel.classList.toggle('open');
  navPanel.classList.remove('open');
  if (panel.classList.contains('open')) {
    setTimeout(() => { const i = panel.querySelector('input'); if (i) i.focus(); }, 60);
  }
};

document.addEventListener('click', function (e) {
  const navPanel     = document.getElementById('mobileNavPanel');
  const searchPanel  = document.getElementById('mobileSearchPanel');
  const hamburger    = document.getElementById('navHamburger');
  const searchToggle = document.getElementById('searchToggleBtn');

  if (navPanel && navPanel.classList.contains('open') &&
      !navPanel.contains(e.target) && hamburger && !hamburger.contains(e.target)) {
    navPanel.classList.remove('open');
  }
  if (searchPanel && searchPanel.classList.contains('open') &&
      !searchPanel.contains(e.target) && searchToggle && !searchToggle.contains(e.target)) {
    searchPanel.classList.remove('open');
  }
});

// ══════════════════════════════════════════════════════════════════
//  PROFILE DROPDOWN
// ══════════════════════════════════════════════════════════════════
(function () {
  const profileDropdown = document.getElementById('profileDropdown');
  const profileBtn      = document.getElementById('profileBtn');

  profileBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    profileDropdown.classList.toggle('open');
    document.getElementById('notifDropdown').style.display = 'none';
  });

  document.addEventListener('click', function (e) {
    if (!profileDropdown.contains(e.target)) {
      profileDropdown.classList.remove('open');
    }
  });
})();

// ══════════════════════════════════════════════════════════════════
//  NOTIFICATION BELL
// ══════════════════════════════════════════════════════════════════
window.toggleNotifDropdown = function (e) {
  if (e) e.stopPropagation();
  const dropdown = document.getElementById('notifDropdown');
  const isOpen   = dropdown.style.display === 'block';
  document.getElementById('profileDropdown').classList.remove('open');
  if (isOpen) { dropdown.style.display = 'none'; }
  else { dropdown.style.display = 'block'; loadNotifications(); }
};

document.addEventListener('click', function (e) {
  const wrapper  = document.getElementById('notifWrapper');
  const dropdown = document.getElementById('notifDropdown');
  if (wrapper && !wrapper.contains(e.target)) { dropdown.style.display = 'none'; }
});

async function loadNotifications() {
  const list = document.getElementById('notifList');
  list.innerHTML = '<div class="notif-empty">Loading...</div>';
  try {
    const res  = await fetch('/Margaux_Collections/api/notifications.php?action=get');
    const data = await res.json();
    if (!data.success || !data.notifications || !data.notifications.length) {
      list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
      return;
    }
    const badge  = document.getElementById('notifBadge');
    const unread = data.notifications.filter(n => !n.is_read).length;
    badge.textContent   = unread;
    badge.style.display = unread > 0 ? 'inline' : 'none';
    list.innerHTML = data.notifications.map(n => `
      <div class="notif-item ${n.is_read ? '' : 'unread'}" onclick="markNotifRead(${n.id})">
        <div class="notif-item-title">${escHtml(n.title)}</div>
        <div class="notif-item-msg">${escHtml(n.message)}</div>
        <div class="notif-item-time">${escHtml(n.created_at)}</div>
      </div>
    `).join('');
  } catch (err) {
    list.innerHTML = '<div class="notif-empty">Could not load notifications.</div>';
  }
}

window.markAllNotifRead = function () {
  fetch('/Margaux_Collections/api/notifications.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'mark_all_read' })
  }).then(() => loadNotifications());
};

window.markNotifRead = function (id) {
  fetch('/Margaux_Collections/api/notifications.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'mark_read', id })
  }).then(() => loadNotifications());
};

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ══════════════════════════════════════════════════════════════════
//  CUSTOMER SUPPORT CHAT WIDGET
// ══════════════════════════════════════════════════════════════════
(function () {
  let chatIsOpen = false, chatActiveCid = null, chatPollTimer = null;

  window.chatToggle = function () {
    chatIsOpen = !chatIsOpen;
    const win = document.getElementById('chatWindow');
    win.style.display = chatIsOpen ? 'block' : 'none';
    document.getElementById('chatBubbleIcon').textContent = chatIsOpen ? '✕' : '💬';
    if (chatIsOpen) chatShowList();
  };

  window.chatShowList = function () {
    clearInterval(chatPollTimer); chatActiveCid = null;
    document.getElementById('chatViewList').style.display   = 'block';
    document.getElementById('chatViewNew').style.display    = 'none';
    document.getElementById('chatViewThread').style.display = 'none';
    chatLoadConvos();
  };

  // ── New: show the "start a new conversation" compose form ─────
  window.chatShowNewForm = function () {
    clearInterval(chatPollTimer); chatActiveCid = null;
    document.getElementById('chatViewList').style.display   = 'none';
    document.getElementById('chatViewThread').style.display = 'none';
    document.getElementById('chatViewNew').style.display    = 'block';
    document.getElementById('newChatSubject').value = '';
    document.getElementById('newChatMessage').value = '';
    document.getElementById('newChatMessage').focus();
  };

  // ── New: submit the first message, which creates the conversation ─
  window.chatSendNew = async function () {
    const subjectInput = document.getElementById('newChatSubject');
    const msgInput      = document.getElementById('newChatMessage');
    const btn           = document.getElementById('newChatSendBtn');
    const msg     = msgInput.value.trim();
    const subject = subjectInput.value.trim() || 'General Inquiry';

    if (!msg) { msgInput.focus(); return; }

    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.textContent = 'Sending…';

    try {
      const data = await chatPost({ action: 'start_conversation', subject: subject, message: msg });
      if (data.success && data.conversation_id) {
        await chatOpenThread(data.conversation_id, subject);
      } else {
        alert('Could not send message: ' + (data.message || 'Unknown error'));
      }
    } catch (e) {
      console.error(e);
      alert('Network error. Please try again.');
    } finally {
      btn.disabled = false;
      btn.textContent = originalLabel;
    }
  };

  async function chatOpenThread(cid, subject) {
    chatActiveCid = cid;
    document.getElementById('chatViewList').style.display   = 'none';
    document.getElementById('chatViewNew').style.display    = 'none';
    document.getElementById('chatViewThread').style.display = 'block';
    document.getElementById('threadTitle').textContent      = subject;
    const inp = document.getElementById('threadInput');
    inp.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); chatSend(); } };
    await chatLoadThread();
    clearInterval(chatPollTimer);
    chatPollTimer = setInterval(chatLoadThread, 5000);
  }
  window.chatOpenThread = chatOpenThread;

  async function chatLoadConvos() {
    try {
      const res  = await fetch('/Margaux_Collections/api/chat.php?action=get_conversations');
      const data = await res.json();
      const list = document.getElementById('chatConvoList');
      const dot  = document.getElementById('chatBubbleDot');
      if (!data.success) { list.innerHTML = '<div style="padding:20px;text-align:center;color:#f9a8c9;font-size:.82rem;">Could not load messages.</div>'; return; }
      const totalUnread = (data.conversations || []).reduce((s, c) => s + (parseInt(c.unread_count) || 0), 0);
      dot.textContent = totalUnread; dot.style.display = totalUnread > 0 ? 'inline' : 'none';
      if (!data.conversations || !data.conversations.length) {
        list.innerHTML = `
          <div class="chat-empty-cta">
            <div style="font-size:2rem;margin-bottom:8px;">💬</div>
            <div style="font-weight:600;color:#4a0020;font-size:.85rem;margin-bottom:4px;">No messages yet</div>
            <div style="font-size:.76rem;color:#f9a8c9;">Send us a message and we'll get back to you.</div>
            <button type="button" class="chat-empty-cta-btn" onclick="chatShowNewForm()">Start a conversation</button>
          </div>`;
        return;
      }
      list.innerHTML = data.conversations.map(c => {
        const preview = truncate(c.last_message || '(no messages yet)', 46);
        const title   = truncate(c.last_message || 'New conversation', 46);
        return `
        <div onclick="chatOpenThread(${c.conversation_id},'${esc(title)}')"
          style="padding:12px 16px;border-bottom:1px solid #fdf2f8;cursor:pointer;
                 background:${parseInt(c.unread_count) > 0 ? '#fff0f5' : '#fff'};transition:background .15s;"
          onmouseover="this.style.background='#fce7f3'"
          onmouseout="this.style.background='${parseInt(c.unread_count) > 0 ? '#fff0f5' : '#fff'}'">
          <div style="font-weight:700;font-size:.83rem;color:#4a0020;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${c.order_id ? 'Order #' + c.order_id + ' — ' : ''}${esc(preview)}</div>
          <div style="display:flex;justify-content:flex-end;align-items:center;">
            ${parseInt(c.unread_count) > 0
              ? `<span style="background:#e75480;color:#fff;font-size:.62rem;font-weight:800;padding:1px 7px;border-radius:999px;">${c.unread_count} new</span>`
              : `<span style="font-size:.7rem;color:#f9a8c9;">${timeAgo(c.last_message_at || c.created_at)}</span>`}
          </div>
        </div>
      `;
      }).join('');
    } catch (e) { console.error(e); }
  }

  async function chatLoadThread() {
    try {
      const res  = await fetch(`/Margaux_Collections/api/chat.php?action=get_messages&conversation_id=${chatActiveCid}`);
      const data = await res.json();
      if (!data.success) return;
      const container = document.getElementById('threadMsgs');
      if (!container) return;
      if (!data.messages.length) { container.innerHTML = '<div style="text-align:center;color:#f9a8c9;font-size:.78rem;padding:16px;">No messages yet.</div>'; return; }
      container.innerHTML = data.messages.map(m => `
        <div style="display:flex;justify-content:${m.sender_type === 'customer' ? 'flex-end' : 'flex-start'};">
          <div style="max-width:78%;">
            <div style="padding:9px 13px;border-radius:14px;font-size:.8rem;line-height:1.5;word-break:break-word;
                        background:${m.sender_type === 'customer' ? 'linear-gradient(135deg,#f9b8cc,#e75480)' : '#fdf2f8'};
                        color:${m.sender_type === 'customer' ? '#fff' : '#4a0020'};
                        border-bottom-${m.sender_type === 'customer' ? 'right' : 'left'}-radius:3px;">
              ${esc(m.message)}
            </div>
            <div style="font-size:.66rem;color:#f9a8c9;margin-top:3px;text-align:${m.sender_type === 'customer' ? 'right' : 'left'};padding:0 3px;">
              ${m.sender_type === 'admin' ? '<strong>Support</strong> · ' : ''}${timeAgo(m.created_at)}
            </div>
          </div>
        </div>
      `).join('');
      container.scrollTop = container.scrollHeight;
    } catch (e) { console.error(e); }
  }

  async function chatPost(params) {
    const body = new URLSearchParams(params);
    const res  = await fetch('/Margaux_Collections/api/chat.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
    });
    return res.json();
  }

  window.chatSend = async function () {
    const input = document.getElementById('threadInput');
    const msg   = input.value.trim();
    if (!msg || !chatActiveCid) return;
    input.value = '';
    try { await chatPost({ action: 'send_message', conversation_id: chatActiveCid, message: msg }); chatLoadThread(); }
    catch (e) { console.error(e); }
  };

  function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/\n/g,'<br>');
  }

  function truncate(str, len) {
    str = String(str || '');
    return str.length > len ? str.slice(0, len) + '…' : str;
  }

  function timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }

  setInterval(async () => {
    if (chatIsOpen) return;
    try {
      const res  = await fetch('/Margaux_Collections/api/chat.php?action=unread_count');
      const data = await res.json();
      if (!data.success) return;
      const dot = document.getElementById('chatBubbleDot');
      dot.textContent = data.unread_count; dot.style.display = data.unread_count > 0 ? 'inline' : 'none';
    } catch (e) {}
  }, 30000);

  document.addEventListener('click', function (e) {
    const widget = document.getElementById('supportChatWidget');
    const navbar = document.querySelector('.navbar');
    if (widget && !widget.contains(e.target) && chatIsOpen) {
      if (navbar && navbar.contains(e.target)) return;
      chatToggle();
    }
  });
})();
</script>
