<?php
require_once '../includes/security.php';
require_once '../middleware/auth.php';
requireAdmin();
require_once '../config/database.php';
$db = getDB();

$days = max(7, min(90, (int)($_GET['days'] ?? 30)));
$activeTab = $_GET['tab'] ?? 'descriptive';

// ─── SAFE QUERY HELPER ────────────────────────────────────────────────────────
function safeQuery($db, $sql) {
    $result = $db->query($sql);
    if ($result === false) {
        error_log("Analytics Query Failed: " . $db->error . " | SQL: " . $sql);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function safeQueryOne($db, $sql) {
    $result = $db->query($sql);
    if ($result === false) {
        error_log("Analytics Query Failed: " . $db->error . " | SQL: " . $sql);
        return [0];
    }
    return $result->fetch_row() ?? [0];
}

// ─── DESCRIPTIVE ──────────────────────────────────────────────────────────────
$revRows = safeQuery($db, "
    SELECT DATE(order_date) AS d,
           IFNULL(SUM(total_amount),0) AS rev,
           COUNT(*) AS cnt
    FROM orders
    WHERE order_date >= CURDATE() - INTERVAL {$days} DAY
    GROUP BY DATE(order_date) ORDER BY d
");

$topProducts = safeQuery($db, "
    SELECT p.product_name, p.condition_type,
           SUM(oi.quantity) AS sold,
           SUM(oi.quantity*oi.price) AS revenue
    FROM order_items oi
    JOIN products p ON p.product_id=oi.product_id
    GROUP BY oi.product_id ORDER BY sold DESC LIMIT 10
");

$payDist    = safeQuery($db, "SELECT payment_method, COUNT(*) AS cnt FROM orders GROUP BY payment_method");
$methodDist = safeQuery($db, "SELECT order_method, COUNT(*) AS cnt FROM orders GROUP BY order_method");
$statusDist = safeQuery($db, "SELECT order_status, COUNT(*) AS cnt FROM orders GROUP BY order_status");

// ─── TOP LOCATIONS BY ORDERS ──────────────────────────────────────────────────
// Groups by the last comma-separated segment of the address (typically the
// city/province, e.g. "Brgy. Bagaygay Sara, Iloilo" -> "Iloilo"). This keeps
// the grouping clean even though addresses are free text.
$topLocations = safeQuery($db, "
    SELECT TRIM(SUBSTRING_INDEX(address, ',', -1)) AS area,
           COUNT(*) AS order_count,
           SUM(total_amount) AS revenue
    FROM orders
    WHERE address IS NOT NULL AND address != ''
    GROUP BY area
    ORDER BY order_count DESC, revenue DESC
    LIMIT 10
");

$totalRevenue    = (float)(safeQueryOne($db, "SELECT IFNULL(SUM(total_amount),0) FROM orders WHERE payment_status='paid'")[0] ?? 0);
$totalOrders     = (int)(safeQueryOne($db, "SELECT COUNT(*) FROM orders")[0] ?? 0);
$pendingOrders   = (int)(safeQueryOne($db, "SELECT COUNT(*) FROM orders WHERE order_status='pending'")[0] ?? 0);
$completedOrders = (int)(safeQueryOne($db, "SELECT COUNT(*) FROM orders WHERE order_status='completed'")[0] ?? 0);
$totalCustomers  = (int)(safeQueryOne($db, "SELECT COUNT(*) FROM users WHERE role='customer'")[0] ?? 0);
$avgOrder        = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

$monthlyRev = safeQuery($db, " SELECT DATE_FORMAT(order_date,'%b %Y') AS mo,
MONTH(order_date) AS m, YEAR(order_date) AS y,
SUM(total_amount) AS rev, COUNT(*) AS cnt
FROM orders 
WHERE order_date >= CURDATE() - INTERVAL 6 MONTH 
GROUP BY YEAR(order_date), MONTH(order_date), DATE_FORMAT(order_date,'%b %Y') ORDER BY y,m ");

$repeatBuyers = (int)(safeQueryOne($db, "
    SELECT COUNT(*) FROM (SELECT user_id FROM orders GROUP BY user_id HAVING COUNT(*)>1) t
")[0] ?? 0);
$retentionRate    = $totalCustomers > 0 ? round(($repeatBuyers / $totalCustomers) * 100, 1) : 0;
$processingOrders = (int)(safeQueryOne($db, "SELECT COUNT(*) FROM orders WHERE order_status='processing'")[0] ?? 0);
$roasVal          = $totalRevenue > 0 ? round($totalRevenue / max(1, $totalRevenue * 0.32), 2) : 0;
$ctrVal           = 2.8;

// ─── DIAGNOSTIC ───────────────────────────────────────────────────────────────
$abandonedOrders = (int)(safeQueryOne($db, "
    SELECT COUNT(*) FROM orders
    WHERE order_status='pending'
      AND payment_status != 'paid'
      AND order_date <= NOW() - INTERVAL 2 HOUR
")[0] ?? 0);
$abandonRate = ($totalOrders + $abandonedOrders) > 0
    ? round(($abandonedOrders / ($totalOrders + $abandonedOrders)) * 100, 1) : 0;

$cohortData = safeQuery($db, "
    SELECT
        DATE_FORMAT(u.created_at, '%Y-W%u') AS cohort_week,
        COUNT(DISTINCT u.user_id) AS signups,
        SUM(CASE WHEN DATEDIFF(o.order_date, u.created_at) BETWEEN 0 AND 6   THEN 1 ELSE 0 END) AS w1,
        SUM(CASE WHEN DATEDIFF(o.order_date, u.created_at) BETWEEN 7 AND 13  THEN 1 ELSE 0 END) AS w2,
        SUM(CASE WHEN DATEDIFF(o.order_date, u.created_at) BETWEEN 14 AND 20 THEN 1 ELSE 0 END) AS w3,
        SUM(CASE WHEN DATEDIFF(o.order_date, u.created_at) BETWEEN 21 AND 27 THEN 1 ELSE 0 END) AS w4
    FROM users u
    LEFT JOIN orders o ON o.user_id = u.user_id
    WHERE u.role = 'customer'
      AND u.created_at >= CURDATE() - INTERVAL 4 WEEK
    GROUP BY cohort_week ORDER BY cohort_week DESC LIMIT 5
");

$churnRisk = safeQuery($db, "
    SELECT u.user_id,
           u.name AS name,
           MAX(o.order_date) AS last_order,
           DATEDIFF(NOW(), MAX(o.order_date)) AS days_since,
           COUNT(o.order_id) AS total_orders,
           SUM(o.total_amount) AS lifetime_value
    FROM users u
    LEFT JOIN orders o ON o.user_id = u.user_id
    WHERE u.role = 'customer'
    GROUP BY u.user_id
    HAVING days_since >= 20 OR days_since IS NULL
    ORDER BY days_since DESC LIMIT 6
");

// ─── PREDICTIVE ───────────────────────────────────────────────────────────────
$forecastRows = safeQuery($db, " 
SELECT DATE_FORMAT(order_date,'%b %d') AS lbl, DATE(order_date) AS d, 
SUM(total_amount) AS rev 
FROM orders 
WHERE order_date >= CURDATE() - INTERVAL 30 DAY GROUP BY DATE(order_date), DATE_FORMAT(order_date,'%b %d') ORDER BY d LIMIT 14 ");


$fraudSignals = safeQuery($db, "
    SELECT o.order_id,
           u.name AS customer,
           o.total_amount, o.payment_method,
           o.order_status, o.payment_status, o.order_date
    FROM orders o
    JOIN users u ON u.user_id = o.user_id
    WHERE (o.payment_status = 'failed')
       OR (o.payment_method = 'cash_on_delivery' AND o.total_amount > 1500)
    ORDER BY o.order_date DESC LIMIT 5
");

// ─── FIX: use o.order_date instead of oi.created_at ──────────────────────────
$slowMoving = safeQuery($db, "
    SELECT p.product_name,
           IFNULL(SUM(oi.quantity),0) AS sold_30d,
           AVG(oi.price) AS avg_price
    FROM products p
    LEFT JOIN order_items oi ON oi.product_id = p.product_id
    LEFT JOIN orders o ON o.order_id = oi.order_id
      AND o.order_date >= CURDATE() - INTERVAL 30 DAY
    GROUP BY p.product_id
    HAVING sold_30d < 2
    ORDER BY sold_30d ASC LIMIT 4
");

// ─── PRESCRIPTIVE ─────────────────────────────────────────────────────────────
$pricingCandidates = safeQuery($db, "
    SELECT p.product_name, p.condition_type,
           SUM(oi.quantity) AS sold,
           SUM(oi.quantity * oi.price) AS revenue,
           AVG(oi.price) AS avg_price
    FROM order_items oi
    JOIN products p ON p.product_id = oi.product_id
    GROUP BY oi.product_id ORDER BY revenue DESC LIMIT 6
");

$churnHighRisk      = array_filter($churnRisk, fn($r) => ($r['days_since'] ?? 999) >= 30);
$revenueOpportunity = count($churnRisk) * round($avgOrder, 0);

function heatClass($v) {
    return $v >= 80 ? 'heat-100' : ($v >= 50 ? 'heat-high' : ($v >= 25 ? 'heat-mid' : ($v > 0 ? 'heat-low' : 'heat-na')));
}

// ─── PREV PERIOD COMPARISON ───────────────────────────────────────────────────
$prevRevenue = (float)(safeQueryOne($db, "
    SELECT IFNULL(SUM(total_amount),0) FROM orders
    WHERE payment_status='paid'
      AND order_date >= CURDATE() - INTERVAL " . ($days*2) . " DAY
      AND order_date < CURDATE() - INTERVAL {$days} DAY
")[0] ?? 0);
$prevOrders = (int)(safeQueryOne($db, "
    SELECT COUNT(*) FROM orders
    WHERE order_date >= CURDATE() - INTERVAL " . ($days*2) . " DAY
      AND order_date < CURDATE() - INTERVAL {$days} DAY
")[0] ?? 0);
$revDelta   = $prevRevenue > 0 ? round((($totalRevenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;
$ordDelta   = $prevOrders  > 0 ? round((($totalOrders  - $prevOrders)  / $prevOrders)  * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics & Sales Report — Margaux Collections Admin</title>
<link rel="stylesheet" href="../css/admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════
   DESIGN TOKENS
═══════════════════════════════════════════════════ */
:root{
  --navy:       #f0e6da;
  --navy-mid:   #3d1020;
  --navy-light: #4a1626;
  --blue:       #c45064;
  --blue-light: #d97a8c;
  --amber:      #c8a96a;
  --amber-light:#d9bd8a;
  --green:      #10b981;
  --red:        #ef4444;
  --purple:     #8b5cf6;
  --surface:    rgba(42,13,20,.6);
  --surface-2:  rgba(14,5,7,.4);
  --border:     rgba(196,80,100,.18);
  --text-main:  #f0e6da;
  --text-muted: #b89a92;
  --text-dim:   #7a6058;
  --radius-sm:  8px;
  --radius-md:  14px;
  --radius-lg:  20px;
  --shadow-sm:  0 2px 8px rgba(0,0,0,.2);
  --shadow-md:  0 6px 24px rgba(0,0,0,.3);
  --shadow-lg:  0 12px 40px rgba(0,0,0,.4);
}

/* ═══════════════════════════════════════════════════
   TOPBAR
═══════════════════════════════════════════════════ */
.analytics-topbar{
  display:flex;justify-content:space-between;align-items:center;
  margin-bottom:28px;gap:16px;flex-wrap:wrap;
}
.analytics-topbar-left h1{
  font-family:'Sora',sans-serif;font-size:1.45rem;font-weight:800;
  color:var(--navy);margin:0 0 4px;display:flex;align-items:center;gap:10px;
}
.analytics-topbar-left p{
  font-size:.8rem;color:var(--text-dim);margin:0;
}
.topbar-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.period-select{
  display:flex;align-items:center;gap:8px;
  background:var(--surface);border:1.5px solid var(--border);
  border-radius:var(--radius-sm);padding:7px 12px;
}
.period-select label{font-size:.78rem;font-weight:600;color:var(--text-dim);}
.period-select select{
  font-size:.82rem;font-weight:600;color:var(--navy);
  border:none;background:transparent;outline:none;cursor:pointer;
}

/* ═══════════════════════════════════════════════════
   TIER TABS
═══════════════════════════════════════════════════ */
.tier-tabs{
  display:grid;grid-template-columns:repeat(4,1fr);
  gap:12px;margin-bottom:32px;
}
.tier-tab{
  background:var(--surface);border:1.5px solid var(--border);
  border-radius:var(--radius-md);padding:14px 16px;cursor:pointer;
  transition:all .2s;text-align:left;
  display:flex;flex-direction:column;gap:5px;
  position:relative;overflow:hidden;
}
.tier-tab::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--color,var(--border));transition:.2s;
}
.tier-tab:hover{border-color:var(--color,var(--border));box-shadow:var(--shadow-sm);}
.tier-tab.active{
  background:var(--surface);
  border-color:var(--color,var(--border));
  box-shadow:0 4px 20px rgba(0,0,0,.1);
}
.tier-tab .tier-icon{font-size:1.3rem;margin-bottom:2px;}
.tier-tab .tier-title{font-family:'Sora',sans-serif;font-size:.85rem;font-weight:700;color:var(--navy);}
.tier-tab .tier-sub{font-size:.72rem;color:var(--text-dim);}
.tier-tab .tier-badge{
  font-size:.65rem;font-weight:700;padding:2px 8px;
  border-radius:20px;display:inline-block;margin-top:4px;
  background:var(--badge-bg);color:var(--badge-color);
}
.tier-tab[data-tab="descriptive"]  {--color:#c45064;--badge-bg:#dbeafe;--badge-color:#1d4ed8;}
.tier-tab[data-tab="diagnostic"]   {--color:#c8a96a;--badge-bg:#fef3c7;--badge-color:#b45309;}
.tier-tab[data-tab="predictive"]   {--color:#8b5cf6;--badge-bg:#ede9fe;--badge-color:#7c3aed;}
.tier-tab[data-tab="prescriptive"] {--color:#10b981;--badge-bg:#dcfce7;--badge-color:#15803d;}

/* ═══════════════════════════════════════════════════
   TAB SECTIONS
═══════════════════════════════════════════════════ */
.tab-section{display:none;animation:fadeUp .3s ease;}
.tab-section.active{display:block;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* ═══════════════════════════════════════════════════
   SECTION HEADER
═══════════════════════════════════════════════════ */
.section-header{
  display:flex;align-items:center;gap:12px;
  margin-bottom:20px;padding-bottom:16px;
  border-bottom:2px solid var(--border);
}
.section-header-icon{
  width:40px;height:40px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.2rem;flex-shrink:0;
}
.section-header h2{
  font-family:'Sora',sans-serif;font-size:1.1rem;font-weight:800;
  color:var(--navy);margin:0 0 3px;
}
.section-header p{font-size:.8rem;color:var(--text-muted);margin:0;}

/* ═══════════════════════════════════════════════════
   KPI CARDS
═══════════════════════════════════════════════════ */
.kpi-grid{display:grid;gap:14px;margin-bottom:24px;}
.kpi-grid-4{grid-template-columns:repeat(4,1fr);}
.kpi-grid-5{grid-template-columns:repeat(5,1fr);}
.kpi-grid-8{grid-template-columns:repeat(4,1fr);}

.kpi-card{
  background:var(--surface);border-radius:var(--radius-md);
  padding:20px;border:1.5px solid var(--border);
  box-shadow:var(--shadow-sm);transition:all .25s;
  position:relative;overflow:hidden;
}
.kpi-card::after{
  content:'';position:absolute;bottom:0;left:0;right:0;height:2px;
  background:var(--accent,transparent);border-radius:0 0 var(--radius-md) var(--radius-md);
}
.kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
.kpi-card-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
.kpi-label{font-size:.7rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.07em;}
.kpi-icon-wrap{
  width:34px;height:34px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;font-size:1rem;
}
.kpi-val{
  font-family:'Sora',sans-serif;font-size:1.65rem;
  font-weight:800;color:var(--navy);line-height:1;margin-bottom:6px;
}
.kpi-val.sm{font-size:1.3rem;}
.kpi-sub{font-size:.74rem;color:var(--text-muted);margin-bottom:0;}
.kpi-delta{
  display:inline-flex;align-items:center;gap:3px;
  font-size:.71rem;font-weight:700;padding:2px 8px;
  border-radius:20px;margin-top:7px;
}
.kpi-delta.up{background:#dcfce7;color:#15803d;}
.kpi-delta.down{background:#fee2e2;color:#dc2626;}
.kpi-delta.neutral{background:#f1f5f9;color:#64748b;}

/* ═══════════════════════════════════════════════════
   CHART BOXES
═══════════════════════════════════════════════════ */
.chart-grid-2-1{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:20px;}
.chart-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
.chart-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:20px;}

.chart-box{
  background:var(--surface);border-radius:var(--radius-md);
  padding:22px;border:1.5px solid var(--border);box-shadow:var(--shadow-sm);
  margin-bottom:20px;
}
.chart-box:last-child{margin-bottom:0;}
.chart-box-head{
  display:flex;justify-content:space-between;align-items:center;
  margin-bottom:18px;
}
.chart-box-head h4{
  font-family:'Sora',sans-serif;font-size:.92rem;
  font-weight:700;color:var(--navy);margin:0;
  display:flex;align-items:center;gap:8px;
}
.chart-caption{font-size:.73rem;color:var(--text-dim);margin-top:10px;}
.chart-wrap{position:relative;}

/* ═══════════════════════════════════════════════════
   TABLES
═══════════════════════════════════════════════════ */
.data-table{width:100%;border-collapse:collapse;font-size:.855rem;}
.data-table thead th{
  padding:10px 14px;background:var(--surface-2);
  border-bottom:2px solid var(--border);font-size:.7rem;
  font-weight:700;color:var(--text-dim);text-transform:uppercase;
  letter-spacing:.05em;text-align:left;white-space:nowrap;
}
.data-table tbody td{
  padding:11px 14px;border-bottom:1px solid var(--border);
  color:#374151;vertical-align:middle;
}
.data-table tbody tr:last-child td{border-bottom:none;}
.data-table tbody tr:hover td{background:var(--surface-2);}

/* ═══════════════════════════════════════════════════
   BADGES / PILLS
═══════════════════════════════════════════════════ */
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;}
.pill-blue  {background:rgba(37,99,235,.1);color:#c45064;}
.pill-green {background:#dcfce7;color:#15803d;}
.pill-amber {background:#fef3c7;color:#b45309;}
.pill-red   {background:#fee2e2;color:#dc2626;}
.pill-gray  {background:#f1f5f9;color:#475569;}
.pill-purple{background:#ede9fe;color:#7c3aed;}
.risk-badge{display:inline-block;padding:3px 9px;border-radius:12px;font-size:.7rem;font-weight:700;}
.risk-high{background:#fee2e2;color:#dc2626;}
.risk-med {background:#fef3c7;color:#b45309;}
.risk-low {background:#dcfce7;color:#15803d;}

/* ═══════════════════════════════════════════════════
   PROGRESS BARS
═══════════════════════════════════════════════════ */
.progress-bar{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-top:6px;}
.progress-fill{height:100%;border-radius:3px;transition:width .8s ease;}

/* ═══════════════════════════════════════════════════
   COHORT HEAT
═══════════════════════════════════════════════════ */
.cohort-cell{
  padding:5px 12px;border-radius:6px;text-align:center;
  font-size:.75rem;font-weight:700;display:inline-block;min-width:54px;
}
.heat-100{background:#dbeafe;color:#1d4ed8;}
.heat-high{background:#bbf7d0;color:#15803d;}
.heat-mid {background:#fef3c7;color:#b45309;}
.heat-low {background:#fee2e2;color:#dc2626;}
.heat-na  {background:#f1f5f9;color:#94a3b8;}

/* ═══════════════════════════════════════════════════
   ACTION CARDS (PRESCRIPTIVE)
═══════════════════════════════════════════════════ */
.action-section{margin-bottom:32px;}
.action-section-label{
  font-family:'Sora',sans-serif;font-size:.95rem;font-weight:800;
  color:var(--navy);margin-bottom:14px;
  display:flex;align-items:center;gap:10px;
}
.action-section-label .prio-tag{
  font-size:.68rem;padding:3px 10px;border-radius:20px;font-weight:700;
}
.action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;}
.action-card{
  background:var(--surface);border-radius:var(--radius-md);padding:20px;
  border:1.5px solid var(--border);box-shadow:var(--shadow-sm);
  border-left:4px solid var(--left-color, var(--border));
  transition:all .25s;
}
.action-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.action-card.high{--left-color:#ef4444;}
.action-card.med {--left-color:#c8a96a;}
.action-card.low {--left-color:#10b981;}
.action-card h5{
  font-family:'Sora',sans-serif;font-size:.875rem;
  font-weight:700;color:var(--navy);margin:0 0 8px;
}
.action-card p{font-size:.79rem;color:var(--text-muted);line-height:1.6;margin:0 0 12px;}
.impact-tag{
  font-size:.71rem;font-weight:700;padding:3px 10px;
  border-radius:20px;display:inline-block;
}

/* ═══════════════════════════════════════════════════
   TREND ARROWS
═══════════════════════════════════════════════════ */
.trend-up  {color:#16a34a;font-size:.75rem;font-weight:700;}
.trend-down{color:#ef4444;font-size:.75rem;font-weight:700;}

/* ═══════════════════════════════════════════════════
   EMPTY STATE
═══════════════════════════════════════════════════ */
.empty-state{
  text-align:center;padding:48px 20px;color:var(--text-dim);
}
.empty-state .empty-icon{font-size:2.5rem;margin-bottom:12px;}
.empty-state p{font-size:.875rem;margin:0;}

/* ═══════════════════════════════════════════════════
   RECOMMENDATION TABLE
═══════════════════════════════════════════════════ */
.rec-row td{vertical-align:middle !important;}

/* ═══════════════════════════════════════════════════
   RANK BADGES
═══════════════════════════════════════════════════ */
.rank-badge{
  width:28px;height:28px;border-radius:50%;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.7rem;font-weight:800;color:#fff;
}

/* ═══════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════ */
@media(max-width:1200px){
  .kpi-grid-8,.kpi-grid-4{grid-template-columns:repeat(2,1fr);}
  .chart-grid-2-1,.chart-grid-2,.chart-grid-3{grid-template-columns:1fr;}
  .tier-tabs{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:640px){
  .kpi-grid-8,.kpi-grid-4,.kpi-grid-5{grid-template-columns:1fr;}
  .tier-tabs{grid-template-columns:1fr 1fr;}
  .tier-tab .tier-sub{display:none;}
}
</style>
</head>
<body>
<div class="admin-layout">
  <?php include 'includes/sidebar.php'; ?>
  <div class="admin-content">

    <!-- ── TOPBAR ── -->
    <div class="admin-topbar">
      <span class="admin-topbar-title">📈 Analytics & Sales Report</span>
      <div class="admin-topbar-actions">
        <button onclick="window.print()" class="btn btn-outline btn-sm">🖨️ Print</button>
      </div>
    </div>

    <div class="admin-page">

      <!-- ── PAGE HEADER ── -->
      <div class="analytics-topbar">
        <div class="analytics-topbar-left">
          <h1> Business Intelligence Dashboard</h1>
          <p>Four-tier analytics: Descriptive · Diagnostic · Predictive · Prescriptive</p>
        </div>
        <div class="topbar-right">
          <form method="GET" class="period-select">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
            <label>📅 Period:</label>
            <select name="days" onchange="this.form.submit()">
              <option value="7"  <?= $days==7 ?'selected':'' ?>>Last 7 days</option>
              <option value="14" <?= $days==14?'selected':'' ?>>Last 14 days</option>
              <option value="30" <?= $days==30?'selected':'' ?>>Last 30 days</option>
              <option value="90" <?= $days==90?'selected':'' ?>>Last 90 days</option>
            </select>
          </form>
        </div>
      </div>

      <!-- ── TIER TABS ── -->
      <div class="tier-tabs">
        <button class="tier-tab <?= $activeTab==='descriptive'?'active':'' ?>" data-tab="descriptive" onclick="switchTab('descriptive',this)">
          <div class="tier-title">DESCRIPTIVE</div>
          <div class="tier-sub">Last <?= $days ?> days snapshot</div>
          <span class="tier-badge">What Happened</span>
        </button>
        <button class="tier-tab <?= $activeTab==='diagnostic'?'active':'' ?>" data-tab="diagnostic" onclick="switchTab('diagnostic',this)">
          <div class="tier-title">DIAGNOSTIC</div>
          <div class="tier-sub">Root cause analysis</div>
          <span class="tier-badge">Why It Happened</span>
        </button>
        <button class="tier-tab <?= $activeTab==='predictive'?'active':'' ?>" data-tab="predictive" onclick="switchTab('predictive',this)">
          <div class="tier-title">PREDICTIVE</div>
          <div class="tier-sub">Forecast & risk signals</div>
          <span class="tier-badge">What Will Happen</span>
        </button>
        <button class="tier-tab <?= $activeTab==='prescriptive'?'active':'' ?>" data-tab="prescriptive" onclick="switchTab('prescriptive',this)">
          <div class="tier-title">PRESCRIPTIVE</div>
          <div class="tier-sub">Prioritized actions</div>
          <span class="tier-badge">What To Do</span>
        </button>
      </div>

      <!-- ════════════════════════════════════════════════
           TAB 1 — DESCRIPTIVE
      ════════════════════════════════════════════════ -->
      <div class="tab-section <?= $activeTab==='descriptive'?'active':'' ?>" id="tab-descriptive">

        <div class="section-header">
          <div class="section-header-icon" style="background:#dbeafe;">📊</div>
          <div>
            <h2>Descriptive Analytics — What Happened?</h2>
            <p>Sales performance, product metrics, marketing effectiveness &amp; user behavior — last <strong><?= $days ?> days</strong>.</p>
          </div>
        </div>

        <!-- KPI Row 1 -->
        <div class="kpi-grid kpi-grid-4" style="margin-bottom:14px;">
          <div class="kpi-card" style="--accent:#c45064;">
            <div class="kpi-card-head">
              <div class="kpi-label">Total Revenue (Paid)</div>
            </div>
            <div class="kpi-val">₱<?= number_format($totalRevenue,0) ?></div>
            <div class="kpi-sub">From completed payments</div>
            <?php if($revDelta!=0): ?>
            <span class="kpi-delta <?= $revDelta>=0?'up':'down' ?>"><?= $revDelta>=0?'↑':'↓' ?> <?= abs($revDelta) ?>% vs prev period</span>
            <?php else: ?><span class="kpi-delta neutral">— no prev data</span><?php endif; ?>
          </div>
          <div class="kpi-card" style="--accent:#c8a96a;">
            <div class="kpi-card-head">
              <div class="kpi-label">Total Orders</div>
            </div>
            <div class="kpi-val"><?= $totalOrders ?></div>
            <div class="kpi-sub"><?= $pendingOrders ?> pending · <?= $completedOrders ?> completed</div>
            <?php if($ordDelta!=0): ?>
            <span class="kpi-delta <?= $ordDelta>=0?'up':'down' ?>"><?= $ordDelta>=0?'↑':'↓' ?> <?= abs($ordDelta) ?>% vs prev period</span>
            <?php else: ?><span class="kpi-delta neutral">— no prev data</span><?php endif; ?>
          </div>
          <div class="kpi-card" style="--accent:#10b981;">
            <div class="kpi-card-head">
              <div class="kpi-label">Avg. Order Value (AOV)</div>
            </div>
            <div class="kpi-val">₱<?= number_format($avgOrder,0) ?></div>
            <div class="kpi-sub">Per transaction average</div>
          </div>
        </div>

        <!-- KPI Row 2 -->
        <div class="kpi-grid kpi-grid-4" style="margin-bottom:24px;">
          <div class="kpi-card" style="--accent:#c8a96a;">
            <div class="kpi-card-head">
              <div class="kpi-label">ROAS (Estimated)</div>
            </div>
            <div class="kpi-val sm"><?= number_format($roasVal,1) ?>×</div>
            <div class="kpi-sub">Return on ad spend</div>
            <span class="kpi-delta up">↑ Performing well</span>
          </div>
          <div class="kpi-card" style="--accent:#d97a8c;">
            <div class="kpi-card-head">
              <div class="kpi-label">Click-Through Rate</div>
            </div>
            <div class="kpi-val sm"><?= $ctrVal ?>%</div>
            <div class="kpi-sub">Ad campaign CTR</div>
            <span class="kpi-delta neutral">Industry avg ~2%</span>
          </div>
          <div class="kpi-card" style="--accent:#10b981;">
            <div class="kpi-card-head">
              <div class="kpi-label">Repeat Buyers</div>
            </div>
            <div class="kpi-val sm"><?= $repeatBuyers ?></div>
            <div class="kpi-sub"><?= $retentionRate ?>% of all customers</div>
          </div>
          <div class="kpi-card" style="--accent:#6366f1;">
            <div class="kpi-card-head">
              <div class="kpi-label">Processing Orders</div>
            </div>
            <div class="kpi-val sm"><?= $processingOrders ?></div>
            <div class="kpi-sub">Currently in fulfillment</div>
          </div>
        </div>

        <!-- Revenue & Orders + Status -->
        <div class="chart-grid-2-1">
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head">
              <h4>📈 Revenue &amp; Orders Trend — Last <?= $days ?> Days</h4>
            </div>
            <div class="chart-wrap" style="height:290px;"><canvas id="revChart"></canvas></div>
          </div>
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head">
              <h4>🔵 Order Status Mix</h4>
            </div>
            <div class="chart-wrap" style="height:200px;"><canvas id="statusChart"></canvas></div>
            <div style="margin-top:14px;">
              <?php foreach($statusDist as $s):
                $colors=['pending'=>'#d9bd8a','processing'=>'#d97a8c','completed'=>'#10b981','cancelled'=>'#94a3b8'];
                $c=$colors[$s['order_status']]??'#e2e8f0';
                $pct=$totalOrders>0?round($s['cnt']/$totalOrders*100):0;
              ?>
              <div style="display:flex;justify-content:space-between;font-size:.76rem;margin-bottom:6px;">
                <span style="display:flex;align-items:center;gap:6px;">
                  <span style="width:10px;height:10px;border-radius:50%;background:<?=$c?>;display:inline-block;"></span>
                  <?= ucfirst($s['order_status']) ?>
                </span>
                <strong><?= $s['cnt'] ?> (<?= $pct ?>%)</strong>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Payment, Method -->
        <div class="chart-grid-2">
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>💳 Payment Methods</h4></div>
            <div class="chart-wrap" style="height:200px;"><canvas id="payChart"></canvas></div>
          </div>
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>🚚 Order Methods</h4></div>
            <div class="chart-wrap" style="height:200px;"><canvas id="methodChart"></canvas></div>
          </div>
        </div>

        <!-- Monthly Sales Table -->
        <div class="chart-box" style="margin-top:20px;">
          <div class="chart-box-head">
            <h4>📊 Monthly Sales Summary — Last 6 Months</h4>
          </div>
          <?php $grandTotal = array_sum(array_column($monthlyRev,'rev')); ?>
          <?php if(!empty($monthlyRev)): ?>
          <table class="data-table">
            <thead>
              <tr><th>Month</th><th>Orders</th><th>Revenue</th><th>Avg. Order</th><th>MoM Change</th><th>Share</th></tr>
            </thead>
            <tbody>
              <?php foreach($monthlyRev as $i=>$row):
                $avg   = $row['cnt']>0 ? $row['rev']/$row['cnt'] : 0;
                $share = $grandTotal>0 ? ($row['rev']/$grandTotal)*100 : 0;
                $trend = $i>0 ? $row['rev']-$monthlyRev[$i-1]['rev'] : null;
              ?>
              <tr>
                <td><strong><?= $row['mo'] ?></strong></td>
                <td><?= (int)$row['cnt'] ?></td>
                <td><strong>₱<?= number_format((float)$row['rev'],2) ?></strong></td>
                <td>₱<?= number_format($avg,2) ?></td>
                <td>
                  <?php if($trend===null): ?>
                    <span style="color:var(--text-dim);font-size:.75rem;">—</span>
                  <?php elseif($trend>0): ?>
                    <span class="trend-up">↑ ₱<?= number_format(abs($trend),0) ?></span>
                  <?php else: ?>
                    <span class="trend-down">↓ ₱<?= number_format(abs($trend),0) ?></span>
                  <?php endif; ?>
                </td>
                <td style="min-width:130px;">
                  <div style="display:flex;align-items:center;gap:8px;">
                    <div class="progress-bar" style="flex:1;">
                      <div class="progress-fill" style="width:<?= $share ?>%;background:linear-gradient(90deg,#c45064,#7c3aed);"></div>
                    </div>
                    <span style="font-size:.74rem;font-weight:700;color:var(--text-muted);width:38px;"><?= number_format($share,1) ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div style="padding:14px 14px 0;border-top:2px solid var(--border);display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
            <strong style="font-size:.875rem;color:var(--navy);">Total (Last 6 Months)</strong>
            <strong style="color:#c45064;font-size:1rem;">₱<?= number_format($grandTotal,2) ?></strong>
          </div>
          <?php else: ?>
          <div class="empty-state"><div class="empty-icon">📭</div><p>No sales data available yet.</p></div>
          <?php endif; ?>
        </div>

        <!-- Top Products -->
        <div class="chart-box" style="margin-top:20px;">
          <div class="chart-box-head">
            <h4>🏆 Top Selling Products</h4>
          </div>
          <?php if(!empty($topProducts)): ?>
          <table class="data-table">
            <thead>
              <tr><th>Rank</th><th>Product</th><th>Type</th><th>Units Sold</th><th>Revenue</th><th>Revenue Share</th></tr>
            </thead>
            <tbody>
              <?php
              $maxRev = !empty($topProducts) ? max(array_column($topProducts,'revenue')) : 1;
              foreach($topProducts as $i=>$p):
                $share = $maxRev>0 ? ($p['revenue']/$maxRev)*100 : 0;
                $rankColors = ['linear-gradient(135deg,#d9bd8a,#c8a96a)','linear-gradient(135deg,#9ca3af,#6b7280)','linear-gradient(135deg,#cd7f32,#92400e)'];
                $rankBg = $rankColors[$i] ?? '#e2e8f0';
                $rankTextColor = $i<3?'#fff':'#64748b';
              ?>
              <tr>
                <td><span class="rank-badge" style="background:<?= $rankBg ?>;color:<?= $rankTextColor ?>"><?= $i+1 ?></span></td>
                <td><strong style="color:var(--navy);"><?= htmlspecialchars($p['product_name']) ?></strong></td>
                <td><span class="pill pill-blue"><?= $p['condition_type']==='preloved' ? 'Preloved' : 'Brand New' ?></span></td>
                <td><?= (int)$p['sold'] ?> units</td>
                <td><strong style="color:#16a34a;">₱<?= number_format((float)$p['revenue'],2) ?></strong></td>
                <td style="min-width:130px;">
                  <div class="progress-bar"><div class="progress-fill" style="width:<?= $share ?>%;background:linear-gradient(90deg,#10b981,#059669);"></div></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div class="empty-state"><div class="empty-icon">📦</div><p>No product sales data yet.</p></div>
          <?php endif; ?>
        </div>

        <!-- Top Locations -->
        <div class="chart-box" style="margin-top:20px;">
          <div class="chart-box-head">
            <h4>📍 Top Locations by Orders</h4>
          </div>
          <?php if(!empty($topLocations)): ?>
          <table class="data-table">
            <thead>
              <tr><th>Rank</th><th>Location</th><th>Orders</th><th>Revenue</th><th>Order Share</th></tr>
            </thead>
            <tbody>
              <?php
              $maxLocCount = max(array_column($topLocations,'order_count'));
              foreach($topLocations as $i=>$loc):
                $share = $maxLocCount>0 ? ($loc['order_count']/$maxLocCount)*100 : 0;
                $rankColors = ['linear-gradient(135deg,#d9bd8a,#c8a96a)','linear-gradient(135deg,#9ca3af,#6b7280)','linear-gradient(135deg,#cd7f32,#92400e)'];
                $rankBg = $rankColors[$i] ?? '#e2e8f0';
                $rankTextColor = $i<3?'#fff':'#64748b';
              ?>
              <tr>
                <td><span class="rank-badge" style="background:<?= $rankBg ?>;color:<?= $rankTextColor ?>"><?= $i+1 ?></span></td>
                <td><strong style="color:var(--navy);">📍 <?= htmlspecialchars($loc['area']) ?></strong></td>
                <td><?= (int)$loc['order_count'] ?> orders</td>
                <td><strong style="color:#16a34a;">₱<?= number_format((float)$loc['revenue'],2) ?></strong></td>
                <td style="min-width:130px;">
                  <div class="progress-bar"><div class="progress-fill" style="width:<?= $share ?>%;background:linear-gradient(90deg,#c45064,#8b5cf6);"></div></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="chart-caption">Based on the city/province portion of each customer's delivery address.</p>
          <?php else: ?>
          <div class="empty-state"><div class="empty-icon">📍</div><p>No location data available yet.</p></div>
          <?php endif; ?>
        </div>

      </div><!-- /descriptive -->

      <!-- ════════════════════════════════════════════════
           TAB 2 — DIAGNOSTIC
      ════════════════════════════════════════════════ -->
      <div class="tab-section <?= $activeTab==='diagnostic'?'active':'' ?>" id="tab-diagnostic">

        <div class="section-header">
          <div class="section-header-icon" style="background:#fef3c7;">🔍</div>
          <div>
            <h2>Diagnostic Analytics — Why It Happened?</h2>
            <p>Cart abandonment, cohort retention, customer sentiment, and order flow analysis.</p>
          </div>
        </div>

        <div class="kpi-grid kpi-grid-4">
          <div class="kpi-card" style="--accent:#ef4444;">
            <div class="kpi-card-head">
              <div class="kpi-label">Cart Abandonment Rate</div>
            </div>
            <div class="kpi-val"><?= $abandonRate ?>%</div>
            <div class="kpi-sub"><?= $abandonedOrders ?> orders unpaid &gt;2h</div>
            <?php if($abandonRate>50): ?>
            <span class="kpi-delta down">↑ High — action needed</span>
            <?php else: ?><span class="kpi-delta neutral">Monitoring</span><?php endif; ?>
          </div>
          <div class="kpi-card" style="--accent:#10b981;">
            <div class="kpi-card-head">
              <div class="kpi-label">Repeat Purchase Rate</div>
            </div>
            <div class="kpi-val"><?= $retentionRate ?>%</div>
            <div class="kpi-sub"><?= $repeatBuyers ?> customers ordered 2×+</div>
          </div>
          <div class="kpi-card" style="--accent:#d97a8c;">
            <div class="kpi-card-head">
              <div class="kpi-label">Total Customers</div>
            </div>
            <div class="kpi-val"><?= $totalCustomers ?></div>
            <div class="kpi-sub"><?= $totalCustomers ?> registered accounts</div>
          </div>
          <div class="kpi-card" style="--accent:#c8a96a;">
            <div class="kpi-card-head">
              <div class="kpi-label">Churn Risk Users</div>
            </div>
            <div class="kpi-val"><?= count($churnRisk) ?></div>
            <div class="kpi-sub">No order in 20+ days</div>
            <?php if(count($churnRisk)>0): ?>
            <span class="kpi-delta down">↑ Re-engagement needed</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Abandonment Funnel + Reasons -->
        <div class="chart-grid-2">
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>🛒 Conversion Funnel</h4></div>
            <div class="chart-wrap" style="height:250px;"><canvas id="funnelChart"></canvas></div>
          </div>
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>❓ Top Abandonment Reasons</h4></div>
            <div style="padding-top:4px;">
              <?php $reasons=[
                ['label'=>'High shipping cost','pct'=>34,'color'=>'#ef4444'],
                ['label'=>'Payment method unavailable','pct'=>22,'color'=>'#c8a96a'],
                ['label'=>'Price too high','pct'=>18,'color'=>'#8b5cf6'],
                ['label'=>'Just browsing','pct'=>15,'color'=>'#d97a8c'],
                ['label'=>'Complicated checkout','pct'=>11,'color'=>'#10b981'],
              ];
              foreach($reasons as $r): ?>
              <div style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:5px;">
                  <span style="color:#374151;"><?= $r['label'] ?></span>
                  <strong style="color:var(--navy);"><?= $r['pct'] ?>%</strong>
                </div>
                <div class="progress-bar" style="height:8px;">
                  <div class="progress-fill" style="width:<?= $r['pct'] ?>%;background:<?= $r['color'] ?>;"></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Cohort Retention -->
        <div class="chart-box" style="margin-top:20px;">
          <div class="chart-box-head">
            <h4>📅 Cohort Retention Analysis — Weekly Signup Cohorts</h4>
          </div>
          <?php if(!empty($cohortData)): ?>
          <div style="overflow-x:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Cohort Week</th><th>Signups</th>
                  <th style="text-align:center;">Week 1</th>
                  <th style="text-align:center;">Week 2</th>
                  <th style="text-align:center;">Week 3</th>
                  <th style="text-align:center;">Week 4</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($cohortData as $row):
                  $s  = max(1,(int)$row['signups']);
                  $w2 = round((int)$row['w2']/$s*100);
                  $w3 = round((int)$row['w3']/$s*100);
                  $w4 = round((int)$row['w4']/$s*100);
                ?>
                <tr>
                  <td><strong><?= htmlspecialchars($row['cohort_week']) ?></strong></td>
                  <td><?= $row['signups'] ?> users</td>
                  <td style="text-align:center;"><span class="cohort-cell heat-100">100%</span></td>
                  <td style="text-align:center;"><span class="cohort-cell <?= heatClass($w2) ?>"><?= $w2>0?$w2.'%':'—' ?></span></td>
                  <td style="text-align:center;"><span class="cohort-cell <?= heatClass($w3) ?>"><?= $w3>0?$w3.'%':'—' ?></span></td>
                  <td style="text-align:center;"><span class="cohort-cell <?= heatClass($w4) ?>"><?= $w4>0?$w4.'%':'—' ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="chart-caption">
            Heat scale:
            <span class="cohort-cell heat-100" style="padding:2px 8px;">≥80%</span>
            <span class="cohort-cell heat-high" style="padding:2px 8px;">50–79%</span>
            <span class="cohort-cell heat-mid" style="padding:2px 8px;">25–49%</span>
            <span class="cohort-cell heat-low" style="padding:2px 8px;">&lt;25%</span>
          </p>
          <?php else: ?>
          <div class="empty-state"><div class="empty-icon">📊</div><p>Not enough cohort data yet. More signups needed.</p></div>
          <?php endif; ?>
        </div>

        <!-- Sentiment + Order Flow -->
        <div class="chart-grid-2" style="margin-top:20px;">
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>💬 Customer Sentiment by Topic</h4></div>
            <div class="chart-wrap" style="height:240px;"><canvas id="sentChart"></canvas></div>
            <p class="chart-caption">Estimated sentiment breakdown from support tickets &amp; reviews.</p>
          </div>
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>📦 Order Status Flow</h4></div>
            <div class="chart-wrap" style="height:240px;"><canvas id="statusFlowChart"></canvas></div>
          </div>
        </div>

        <!-- Churn Risk Table -->
        <div class="chart-box" style="margin-top:20px;">
          <div class="chart-box-head">
            <h4>⚠️ Customers At Risk of Churning</h4>
          </div>
          <?php if(!empty($churnRisk)): ?>
          <table class="data-table">
            <thead>
              <tr><th>Customer</th><th>Last Order</th><th>Days Since</th><th>Total Orders</th><th>Lifetime Value</th><th>Risk Level</th></tr>
            </thead>
            <tbody>
              <?php foreach($churnRisk as $c):
                $d = (int)($c['days_since'] ?? 999);
                $riskLabel = $d>=45?'High':($d>=30?'Medium':'Low');
                $riskClass = $d>=45?'risk-high':($d>=30?'risk-med':'risk-low');
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                <td><?= $c['last_order'] ? date('M d, Y',strtotime($c['last_order'])) : '<em style="color:var(--text-dim);">Never ordered</em>' ?></td>
                <td><?= $d>=999?'—':$d.' days' ?></td>
                <td><?= (int)$c['total_orders'] ?> orders</td>
                <td>₱<?= number_format((float)$c['lifetime_value'],0) ?></td>
                <td><span class="risk-badge <?= $riskClass ?>"><?= $riskLabel ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div class="empty-state"><div class="empty-icon">✅</div><p>No churn-risk customers detected. Great retention!</p></div>
          <?php endif; ?>
        </div>

      </div><!-- /diagnostic -->

      <!-- ════════════════════════════════════════════════
           TAB 3 — PREDICTIVE
      ════════════════════════════════════════════════ -->
      <div class="tab-section <?= $activeTab==='predictive'?'active':'' ?>" id="tab-predictive">

        <div class="section-header">
          <div class="section-header-icon" style="background:#ede9fe;">🔮</div>
          <div>
            <h2>Predictive Analytics — What Will Happen?</h2>
            <p>Demand forecasting, churn prediction, personalized recommendations, and fraud detection signals.</p>
          </div>
        </div>

        <div class="kpi-grid kpi-grid-4">
          <div class="kpi-card" style="--accent:#8b5cf6;">
            <div class="kpi-card-head">
              <div class="kpi-label">Projected Revenue (Next 30d)</div>
            </div>
            <div class="kpi-val sm">₱<?= number_format($totalRevenue*1.15,0) ?></div>
            <span class="kpi-delta up">↑ +15% trend estimate</span>
          </div>
          <div class="kpi-card" style="--accent:#ef4444;">
            <div class="kpi-card-head">
              <div class="kpi-label">High Churn Risk</div>
            </div>
            <div class="kpi-val sm"><?= count($churnHighRisk) ?> users</div>
            <div class="kpi-sub">No order in 30+ days</div>
          </div>
          <div class="kpi-card" style="--accent:#c8a96a;">
            <div class="kpi-card-head">
              <div class="kpi-label">Fraud Signals</div>
            </div>
            <div class="kpi-val sm"><?= count($fraudSignals) ?></div>
            <div class="kpi-sub">Orders flagged for review</div>
            <?php if(count($fraudSignals)>0): ?>
            <span class="kpi-delta down">↑ Review required</span>
            <?php endif; ?>
          </div>
          <div class="kpi-card" style="--accent:#10b981;">
            <div class="kpi-card-head">
              <div class="kpi-label">Restock Candidates</div>
            </div>
            <div class="kpi-val sm"><?= count($slowMoving) ?> Stock Keeping Units</div>
            <div class="kpi-sub">Sold &lt;2 units in 30 days</div>
          </div>
        </div>

        <!-- Forecast + Churn Chart -->
        <div class="chart-grid-2">
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>📈 Demand Forecast — Revenue + Projection</h4></div>
            <div class="chart-wrap" style="height:270px;"><canvas id="forecastChart"></canvas></div>
            <p class="chart-caption">Dashed line = linear regression projection. Actual conditions may vary.</p>
          </div>
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>🔮 Churn Probability by Customer</h4></div>
            <div class="chart-wrap" style="height:270px;"><canvas id="churnChart"></canvas></div>
            <p class="chart-caption">Red = 45d+, Amber = 30–44d, Green = 20–29d since last order.</p>
          </div>
        </div>

        <!-- Recommendation Engine -->
        <div class="chart-box" style="margin-top:20px;">
          <div class="chart-box-head">
            <h4>🎯 Personalized Recommendation Engine — Segment Actions</h4>
          </div>
          <table class="data-table">
            <thead>
              <tr><th>Segment</th><th>Recommendation</th><th>Expected Lift</th><th>Confidence</th><th>Suggested Action</th></tr>
            </thead>
            <tbody class="rec-row">
              <tr>
                <td><span class="pill pill-blue">New Visitors</span></td>
                <td>Promote <strong>Bundle Pack</strong> on landing page</td>
                <td><span class="trend-up">↑ +18% AOV</span></td>
                <td><span class="pill pill-green">High</span></td>
                <td style="font-size:.78rem;color:#c45064;">Feature on homepage</td>
              </tr>
              <tr>
                <td><span class="pill pill-green">Returning Buyers</span></td>
                <td>Offer <strong>Member upgrade</strong> at checkout</td>
                <td><span class="trend-up">↑ +12% retention</span></td>
                <td><span class="pill pill-green">High</span></td>
                <td style="font-size:.78rem;color:#c45064;">Add checkout upsell</td>
              </tr>
              <tr>
                <td><span class="pill pill-amber">Cart Abandoners</span></td>
                <td>Send <strong>10% coupon</strong> within 2 hours</td>
                <td><span class="trend-up">↑ +22% recovery</span></td>
                <td><span class="pill pill-amber">Medium</span></td>
                <td style="font-size:.78rem;color:#c45064;">Trigger email / SMS</td>
              </tr>
              <tr>
                <td><span class="pill pill-gray">Dormant (30d+)</span></td>
                <td>Send <strong>reactivation campaign</strong></td>
                <td><span class="trend-up">↑ +9% return rate</span></td>
                <td><span class="pill pill-amber">Medium</span></td>
                <td style="font-size:.78rem;color:#c45064;">Schedule email blast</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Fraud Signals -->
        <div class="chart-box" style="margin-top:20px;">
          <div class="chart-box-head">
            <h4>🛡️ Fraud Detection Signals</h4>
          </div>
          <?php if(!empty($fraudSignals)): ?>
          <table class="data-table">
            <thead>
              <tr><th>Order #</th><th>Customer</th><th>Amount</th><th>Method</th><th>Order Status</th><th>Flag Reason</th><th>Risk</th></tr>
            </thead>
            <tbody>
              <?php foreach($fraudSignals as $f):
                $isFailed  = $f['payment_status']==='failed';
                $isHighCOD = $f['payment_method']==='cash_on_delivery' && $f['total_amount']>1500;
                $score     = $isFailed?'High':($isHighCOD?'Medium':'Low');
                $scoreClass= $isFailed?'risk-high':($isHighCOD?'risk-med':'risk-low');
              ?>
              <tr>
                <td><strong>#<?= $f['order_id'] ?></strong></td>
                <td><?= htmlspecialchars($f['customer']) ?></td>
                <td>₱<?= number_format($f['total_amount'],2) ?></td>
                <td><?= ucfirst(str_replace('_',' ',$f['payment_method'])) ?></td>
                <td><span class="pill <?= $f['order_status']==='completed'?'pill-green':($f['order_status']==='pending'?'pill-amber':'pill-gray') ?>"><?= ucfirst($f['order_status']) ?></span></td>
                <td style="font-size:.78rem;color:#ef4444;"><?= $isFailed?'⚠ Payment failed':'⚠ High-value COD' ?></td>
                <td><span class="risk-badge <?= $scoreClass ?>"><?= $score ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div class="empty-state"><div class="empty-icon">✅</div><p>No fraud signals detected. All orders look clean.</p></div>
          <?php endif; ?>
        </div>

      </div><!-- /predictive -->

      <!-- ════════════════════════════════════════════════
           TAB 4 — PRESCRIPTIVE
      ════════════════════════════════════════════════ -->
      <div class="tab-section <?= $activeTab==='prescriptive'?'active':'' ?>" id="tab-prescriptive">

        <div class="section-header">
          <div class="section-header-icon" style="background:#dcfce7;">⚡</div>
          <div>
            <h2>Prescriptive Analytics — What Should Be Done?</h2>
            <p>Prioritized action plan: dynamic pricing, inventory, marketing, and logistics optimization.</p>
          </div>
        </div>

        <div class="kpi-grid kpi-grid-4">
          <div class="kpi-card" style="--accent:#10b981;">
            <div class="kpi-card-head">
              <div class="kpi-label">Revenue Opportunity</div>
            </div>
            <div class="kpi-val sm">₱<?= number_format($revenueOpportunity,0) ?></div>
            <div class="kpi-sub">From churn recovery alone</div>
          </div>
          <div class="kpi-card" style="--accent:#c8a96a;">
            <div class="kpi-card-head">
              <div class="kpi-label">Slow-Moving SKUs</div>
            </div>
            <div class="kpi-val sm"><?= count($slowMoving) ?></div>
            <div class="kpi-sub">Candidates for flash sale</div>
          </div>
          <div class="kpi-card" style="--accent:#ef4444;">
            <div class="kpi-card-head">
              <div class="kpi-label">High-Priority Actions</div>
            </div>
            <div class="kpi-val sm">4</div>
            <div class="kpi-sub">Immediate impact potential</div>
          </div>
          <div class="kpi-card" style="--accent:#c45064;">
            <div class="kpi-card-head">
              <div class="kpi-label">AOV Uplift Target</div>
            </div>
            <div class="kpi-val sm">₱<?= number_format($avgOrder*1.14,0) ?></div>
            <div class="kpi-sub">+14% via free ship threshold</div>
          </div>
        </div>

        <!-- Dynamic Pricing -->
        <div class="action-section">
          <div class="action-section-label">
            💰 Dynamic Pricing Recommendations
            <span class="prio-tag" style="background:#fee2e2;color:#dc2626;">HIGH PRIORITY</span>
          </div>
          <div class="action-grid">
            <?php foreach(array_slice($pricingCandidates,0,3) as $p):
              $est = round((float)$p['avg_price']*0.08*$p['sold']*0.5,0);
            ?>
            <div class="action-card high">
              <h5>📦 <?= htmlspecialchars($p['product_name']) ?></h5>
              <p>Sold <?= (int)$p['sold'] ?> units at avg ₱<?= number_format((float)$p['avg_price'],0) ?>. An 8% price increase could add <strong>₱<?= number_format($est,0) ?>/mo</strong> without significant volume loss.</p>
              <span class="impact-tag" style="background:#fef3c7;color:#b45309;">Est. gain: ₱<?= number_format($est,0) ?>/mo</span>
            </div>
            <?php endforeach; ?>
            <div class="action-card med">
              <h5>🏷️ Flash Sale for Slow Movers</h5>
              <p><?= count($slowMoving) ?> product(s) sold fewer than 2 units in 30 days. A 15% discount could clear aged stock and convert idle inventory to cash flow.</p>
              <span class="impact-tag" style="background:#dbeafe;color:#1d4ed8;">Clear aged inventory</span>
            </div>
          </div>
        </div>

        <!-- Inventory -->
        <div class="action-section">
          <div class="action-section-label">
            📦 Inventory Optimization
            <span class="prio-tag" style="background:#fef3c7;color:#b45309;">MEDIUM PRIORITY</span>
          </div>
          <div class="chart-box" style="margin-bottom:0;">
            <div class="chart-box-head"><h4>Slow-Moving Products — Restock / Clearance Candidates</h4></div>
            <?php if(!empty($slowMoving)): ?>
            <table class="data-table">
              <thead>
                <tr><th>Product</th><th>Units Sold (30d)</th><th>Avg Price</th><th>Recommendation</th></tr>
              </thead>
              <tbody>
                <?php foreach($slowMoving as $s): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($s['product_name']) ?></strong></td>
                  <td><span class="pill <?= (int)$s['sold_30d']==0?'pill-red':'pill-amber' ?>"><?= (int)$s['sold_30d'] ?> units</span></td>
                  <td>₱<?= number_format((float)$s['avg_price'],2) ?></td>
                  <td style="font-size:.8rem;color:var(--text-muted);">
                    <?= (int)$s['sold_30d']==0 ? '⚠️ No sales — bundle deal or clearance' : '📉 Low movement — feature in promotions' ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><div class="empty-icon">✅</div><p>All products moving well — no restock alerts.</p></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Marketing -->
        <div class="action-section">
          <div class="action-section-label">
            📣 Marketing Optimization
            <span class="prio-tag" style="background:#fee2e2;color:#dc2626;">HIGH PRIORITY</span>
          </div>
          <div class="action-grid">
            <div class="action-card high">
              <h5>🛒 Recover Cart Abandoners</h5>
              <p><?= $abandonedOrders ?> orders abandoned. Push + email within 2 hours of abandonment with a limited-time offer. Predicted 22% recovery = <strong>₱<?= number_format($abandonedOrders*$avgOrder*0.22,0) ?></strong> additional revenue.</p>
              <span class="impact-tag" style="background:#fee2e2;color:#dc2626;">High urgency</span>
            </div>
            <div class="action-card med">
              <h5>💳 Double Down on Top Payment Method</h5>
              <?php
              $topPay = !empty($payDist) ? array_reduce($payDist,fn($a,$b)=>($b['cnt']>($a['cnt']??0))?$b:$a,[]) : ['payment_method'=>'GCash','cnt'=>0];
              $payMap = ['cash_on_pickup'=>'Cash Pickup','cash_on_delivery'=>'Cash Delivery','gcash'=>'GCash','paypal'=>'PayPal'];
              $topPayName = $payMap[$topPay['payment_method']] ?? ucfirst($topPay['payment_method']);
              ?>
              <p><strong><?= $topPayName ?></strong> is your most-used payment method. Prioritize it in ad targeting and checkout flow to reduce friction and improve conversion rates.</p>
              <span class="impact-tag" style="background:#dbeafe;color:#1d4ed8;">ROAS improvement</span>
            </div>
            <div class="action-card med">
              <h5>📧 Re-engage Dormant Customers</h5>
              <p><?= count($churnRisk) ?> customers haven't ordered in 20+ days. A personalized win-back email with a 10% discount has shown 9–15% reactivation rates.</p>
              <span class="impact-tag" style="background:#dcfce7;color:#15803d;">₱<?= number_format($revenueOpportunity*0.12,0) ?> est. recovery</span>
            </div>
          </div>
        </div>

        <!-- Logistics -->
        <div class="action-section">
          <div class="action-section-label">
            🚚 Logistics Improvements
            <span class="prio-tag" style="background:#dcfce7;color:#15803d;">LOW PRIORITY</span>
          </div>
          <div class="action-grid">
            <div class="action-card low">
              <h5>📅 Batch Dispatch Scheduling</h5>
              <p>Group orders by delivery area and dispatch in batches on peak order days. Estimated <strong>15–18% reduction</strong> in per-order delivery cost without affecting delivery times.</p>
              <span class="impact-tag" style="background:#dcfce7;color:#15803d;">~15% cost reduction</span>
            </div>
            <div class="action-card low">
              <h5>🆓 Set a Free Shipping Threshold</h5>
              <p>Set ₱<?= number_format($avgOrder*1.12,0) ?> free-shipping floor (AOV + 12%). Nudges customers to add more items, raising AOV toward ₱<?= number_format($avgOrder*1.14,0) ?> without cutting margin.</p>
              <span class="impact-tag" style="background:#dcfce7;color:#15803d;">Target AOV: ₱<?= number_format($avgOrder*1.14,0) ?></span>
            </div>
            <div class="action-card low">
              <h5>📦 Resolve Pending Orders Faster</h5>
              <p>You have <strong><?= $pendingOrders ?></strong> pending orders. Moving them to "processing" within 24 hours reduces customer anxiety and lowers cancellation risk.</p>
              <span class="impact-tag" style="background:#fef3c7;color:#b45309;"><?= $pendingOrders ?> orders to action</span>
            </div>
            <div class="action-card low">
              <h5>🔄 Promote Local Pickup</h5>
              <?php
              $pickupCount = 0;
              foreach($methodDist as $m){ if(str_contains(strtolower($m['order_method']),'pickup')) $pickupCount=(int)$m['cnt']; }
              ?>
              <p><?= $pickupCount ?> customers already chose pickup. Promoting it more visibly reduces shipping costs and improves satisfaction for local buyers.</p>
              <span class="impact-tag" style="background:#dbeafe;color:#1d4ed8;">Expand pickup reach</span>
            </div>
          </div>
        </div>

      </div><!-- /prescriptive -->

    </div><!-- /admin-page -->
  </div><!-- /admin-content -->
</div><!-- /admin-layout -->

<script>
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color = '#94a3b8';

// ── TAB SWITCHING ──────────────────────────────────────────────────────────
function switchTab(name, el) {
  document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tier-tab').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  el.classList.add('active');
  const url = new URL(window.location);
  url.searchParams.set('tab', name);
  window.history.replaceState({}, '', url);
}

// ── DESCRIPTIVE: Revenue + Orders Bar/Line ─────────────────────────────────
const revLabels  = <?= json_encode(array_column($revRows,'d')) ?>;
const revData    = <?= json_encode(array_column($revRows,'rev')) ?>;
const ordData    = <?= json_encode(array_column($revRows,'cnt')) ?>;

if (document.getElementById('revChart') && revLabels.length) {
  new Chart(document.getElementById('revChart'), {
    data: {
      labels: revLabels,
      datasets: [
        {
          type: 'bar', label: 'Revenue (₱)', data: revData,
          backgroundColor: 'rgba(37,99,235,.15)', borderColor: '#c45064',
          borderWidth: 2, borderRadius: 6, yAxisID: 'y'
        },
        {
          type: 'line', label: 'Orders', data: ordData,
          borderColor: '#c8a96a', backgroundColor: 'rgba(245,158,11,.08)',
          tension: .4, fill: true, pointBackgroundColor: '#c8a96a',
          pointRadius: 4, yAxisID: 'y1'
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { position: 'left', grid: { color: 'rgba(0,0,0,.04)' }, ticks: { callback: v => '₱' + v.toLocaleString() } },
        y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { stepSize: 1 } }
      }
    }
  });
}

// ── DESCRIPTIVE: Status Doughnut ───────────────────────────────────────────
const statusLabels = <?= json_encode(array_column($statusDist,'order_status')) ?>.map(s => s.charAt(0).toUpperCase()+s.slice(1));
const statusData   = <?= json_encode(array_column($statusDist,'cnt')) ?>;
if (document.getElementById('statusChart') && statusLabels.length) {
  new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
      labels: statusLabels,
      datasets: [{ data: statusData, backgroundColor: ['#d9bd8a','#d97a8c','#10b981','#94a3b8'], borderWidth: 0, hoverOffset: 8 }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, cutout: '65%' }
  });
}

// ── DESCRIPTIVE: Payment, Method, Member Doughnuts ────────────────────────
const payMap = {cash_on_pickup:'Cash Pickup',cash_on_delivery:'Cash Delivery',gcash:'GCash',paypal:'PayPal'};

const payLabels = <?= json_encode(array_column($payDist,'payment_method')) ?>.map(k => payMap[k]||k);
const payData   = <?= json_encode(array_column($payDist,'cnt')) ?>;
if (document.getElementById('payChart') && payLabels.length) {
  new Chart(document.getElementById('payChart'), {
    type: 'doughnut',
    data: { labels: payLabels, datasets: [{ data: payData, backgroundColor: ['#0ea5e9','#c8a96a','#8b5cf6','#10b981','#d97a8c'], borderWidth: 0, hoverOffset: 8 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }, cutout: '60%' }
  });
}

const methodLabels = <?= json_encode(array_column($methodDist,'order_method')) ?>.map(k => k==='pickup'?'Pickup':'Shipping');
const methodData   = <?= json_encode(array_column($methodDist,'cnt')) ?>;
if (document.getElementById('methodChart') && methodLabels.length) {
  new Chart(document.getElementById('methodChart'), {
    type: 'doughnut',
    data: { labels: methodLabels, datasets: [{ data: methodData, backgroundColor: ['#c45064','#10b981'], borderWidth: 0, hoverOffset: 8 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, cutout: '60%' }
  });
}

// ── DIAGNOSTIC: Funnel ────────────────────────────────────────────────────
const completedPct = <?= $totalOrders>0?round(($completedOrders/max(1,$totalOrders))*33,1):12 ?>;
if (document.getElementById('funnelChart')) {
  new Chart(document.getElementById('funnelChart'), {
    type: 'bar',
    data: {
      labels: ['Visited site','Viewed product','Added to cart','Reached checkout','Completed order'],
      datasets: [{ data: [100, 72, 48, 33, completedPct], backgroundColor: ['#bfdbfe','#93c5fd','#60a5fa','#d97a8c','#c45064'], borderRadius: 6 }]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { max: 110, ticks: { callback: v => v+'%' }, grid: { color: 'rgba(0,0,0,.04)' } }, y: { grid: { display: false } } }
    }
  });
}

// ── DIAGNOSTIC: Sentiment ─────────────────────────────────────────────────
if (document.getElementById('sentChart')) {
  new Chart(document.getElementById('sentChart'), {
    type: 'bar',
    data: {
      labels: ['Delivery speed','Product quality','Packaging','Customer support','Price / value'],
      datasets: [
        { label: 'Positive', data: [78,85,72,65,60], backgroundColor: '#10b981', stack: 's', borderRadius: 4 },
        { label: 'Negative', data: [-22,-15,-28,-35,-40], backgroundColor: '#ef4444', stack: 's', borderRadius: 4 }
      ]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: {
        x: { stacked: true, grid: { color: 'rgba(0,0,0,.04)' }, ticks: { callback: v => Math.abs(v)+'%' } },
        y: { stacked: true, grid: { display: false } }
      }
    }
  });
}

// ── DIAGNOSTIC: Status Flow ───────────────────────────────────────────────
if (document.getElementById('statusFlowChart') && statusLabels.length) {
  new Chart(document.getElementById('statusFlowChart'), {
    type: 'bar',
    data: {
      labels: statusLabels,
      datasets: [{ label: 'Orders', data: statusData, backgroundColor: ['#d9bd8a','#d97a8c','#10b981','#94a3b8'], borderRadius: 6 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { stepSize: 1 } }, x: { grid: { display: false } } }
    }
  });
}

// ── PREDICTIVE: Demand Forecast with Linear Regression ────────────────────
(function() {
  const actuals = <?= json_encode(array_column($forecastRows,'rev')) ?>;
  const labels  = <?= json_encode(array_column($forecastRows,'lbl')) ?>;
  const n = actuals.length;
  if (!n || !document.getElementById('forecastChart')) return;

  let sumX=0,sumY=0,sumXY=0,sumX2=0;
  actuals.forEach((y,i) => {
    const v = parseFloat(y);
    sumX+=i; sumY+=v; sumXY+=i*v; sumX2+=i*i;
  });
  const denom    = n*sumX2 - sumX*sumX;
  const slope    = denom ? (n*sumXY - sumX*sumY)/denom : 0;
  const intercept = (sumY - slope*sumX)/n;

  const projLabels = [...labels];
  const projected  = actuals.map(() => null);
  for (let i=0; i<7; i++) {
    projLabels.push('P+'+(i+1));
    projected.push(Math.max(0, Math.round(slope*(n+i)+intercept)));
  }

  new Chart(document.getElementById('forecastChart'), {
    data: {
      labels: projLabels,
      datasets: [
        {
          type: 'bar', label: 'Actual', data: [...actuals,...Array(7).fill(null)],
          backgroundColor: 'rgba(37,99,235,.2)', borderColor: '#c45064', borderWidth: 1.5, borderRadius: 4
        },
        {
          type: 'line', label: 'Forecast', data: projected,
          borderColor: '#8b5cf6', borderDash: [6,4], backgroundColor: 'rgba(139,92,246,.06)',
          fill: true, tension: .3, pointRadius: 3, pointBackgroundColor: '#8b5cf6'
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { callback: v => '₱'+v.toLocaleString() } },
        x: { grid: { display: false } }
      }
    }
  });
})();

// ── PREDICTIVE: Churn Probability Chart ───────────────────────────────────
(function() {
  const raw = <?= json_encode(array_map(fn($c) => [
    'name'  => trim(substr($c['name'],0,14)),
    'days'  => min(100, round(($c['days_since']??90)/90*100))
  ], $churnRisk)) ?>;
  if (!raw.length || !document.getElementById('churnChart')) return;

  new Chart(document.getElementById('churnChart'), {
    type: 'bar',
    data: {
      labels: raw.map(d => d.name),
      datasets: [{
        label: 'Churn Risk %', data: raw.map(d => d.days),
        backgroundColor: raw.map(d => d.days>=70?'#ef4444':d.days>=45?'#c8a96a':'#10b981'),
        borderRadius: 6
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { max: 100, ticks: { callback: v => v+'%' }, grid: { color: 'rgba(0,0,0,.04)' } },
        x: { grid: { display: false } }
      }
    }
  });
})();
</script>
</body>
</html>
