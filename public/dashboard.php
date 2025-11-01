<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
if (function_exists('is_cashier') && is_cashier()) { header('Location: /3zbawyh/public/pos.php'); exit; }

date_default_timezone_set('Africa/Cairo');
$u  = current_user();
$db = db();

/* أدوات مساعدة */
function safe_fetch($cb, $fallback=null){ try{ return $cb(); }catch(Throwable $e){ return $fallback; } }
function safe_count(PDO $db, $sql, $params=[],$fallback=0){ return safe_fetch(function()use($db,$sql,$params){ $st=$db->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }, $fallback); }
function safe_sum(PDO $db, $sql, $params=[],$fallback=0.0){ return safe_fetch(function()use($db,$sql,$params){ $st=$db->prepare($sql); $st->execute($params); return (float)($st->fetchColumn() ?: 0); }, $fallback); }
function nf($n){ return number_format((float)$n, (floor($n)===$n?0:2), '.', ','); }
function cols_of(PDO $db, $table){ $st=$db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $st->execute([$table]); return $st->fetchAll(PDO::FETCH_COLUMN); }
function detect_date_col(PDO $db, $table){
  $st=$db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND DATA_TYPE IN ('datetime','timestamp','date','time')");
  $st->execute([$table]); $rows=$st->fetchAll(PDO::FETCH_KEY_PAIR);
  if(!$rows) return null; foreach(['created_at','created_on','invoice_date','date','ts','timestamp','time'] as $p){ if(isset($rows[$p])) return $p; }
  return array_key_first($rows);
}

/* أعمدة اختيارية */
$uCols = safe_fetch(fn()=>cols_of($db,'users'),[]);
$cCols = safe_fetch(fn()=>cols_of($db,'customers'),[]);
$hasInventoryStock = safe_fetch(function() use($db){
  $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_stock'");
  $st->execute(); return (bool)$st->fetchColumn();
}, false);

/* أسماء */
$CASHIER_NAME = in_array('username',$uCols,true) ? 'u.username' : (in_array('name',$uCols,true)?'u.name':"CONCAT('كاشير #', si.cashier_id)");
$CUSTOMER_NAME = in_array('name',$cCols,true) ? 'c.name' : (in_array('customer_name',$cCols,true)?'c.customer_name':"CONCAT('عميل #', si.customer_id)");

/* تاريخ */
$dateCol = safe_fetch(fn()=>detect_date_col($db,'sales_invoices'), null);
$todayStart = (new DateTime('today'))->format('Y-m-d 00:00:00');
$todayEnd   = (new DateTime('today'))->format('Y-m-d 23:59:59');

/* KPIs */
$totalProducts = safe_count($db, "SELECT COUNT(*) FROM items");
$lowStockCount = $hasInventoryStock ? safe_count($db, "SELECT COUNT(*) FROM inventory_stock WHERE qty <= COALESCE(reorder_level,0)") : 0;

$LINE_TOT = "COALESCE(it.line_total, it.qty * it.unit_price)";
if ($dateCol) {
  $todaySales    = safe_sum($db, "SELECT COALESCE(SUM($LINE_TOT),0) FROM sales_invoices si LEFT JOIN sales_items it ON it.invoice_id = si.id WHERE si.`$dateCol` BETWEEN ? AND ?", [$todayStart,$todayEnd], 0.0);
  $todayInvoices = safe_count($db, "SELECT COUNT(DISTINCT si.id) FROM sales_invoices si WHERE si.`$dateCol` BETWEEN ? AND ?", [$todayStart,$todayEnd], 0);
} else {
  $todaySales    = safe_sum($db, "SELECT COALESCE(SUM($LINE_TOT),0) FROM sales_invoices si LEFT JOIN sales_items it ON it.invoice_id = si.id", [], 0.0);
  $todayInvoices = safe_count($db, "SELECT COUNT(*) FROM sales_invoices", [], 0);
}
$totalInvoices = safe_count($db, "SELECT COUNT(*) FROM sales_invoices");

/* آخر الفواتير */
$latestInvoices = safe_fetch(function() use($db,$dateCol,$LINE_TOT,$CUSTOMER_NAME,$CASHIER_NAME){
  $dateSelect = $dateCol ? "si.`$dateCol` AS created_at" : "NULL AS created_at";
  $orderBy    = $dateCol ? "si.`$dateCol` DESC" : "si.id DESC";
  $joinCust   = "LEFT JOIN customers c ON c.id = si.customer_id";
  $joinUser   = "LEFT JOIN users u ON u.id = si.cashier_id";
  $sql = "SELECT si.id, si.invoice_no, $dateSelect,
                 $CUSTOMER_NAME AS customer_name,
                 $CASHIER_NAME AS cashier_name,
                 SUM($LINE_TOT) AS total_amount
          FROM sales_invoices si
          LEFT JOIN sales_items it ON it.invoice_id = si.id
          $joinCust
          $joinUser
          GROUP BY si.id, si.invoice_no, created_at, customer_name, cashier_name
          ORDER BY $orderBy
          LIMIT 8";
  return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}, []);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>اللوحة الرئيسية - العزباوية</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
  <style>
    :root{
      --bg:#f6f7fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#6b7280;
      --primary:#111827;
      --primary-600:#1f2937;
      --accent:#4f46e5;
      --accent-weak:#eef2ff;
      --success:#10b981;
      --warning:#f59e0b;
      --danger:#ef4444;
      --border:#e5e7eb;
      --chip:#1118270d;
      --shadow:0 10px 25px rgba(0,0,0,.06);
    }
    @media (prefers-color-scheme: dark){
      :root{
        --bg:#0b1020; --card:#0f162b; --text:#e5e7eb; --muted:#9ca3af;
        --primary:#e5e7eb; --primary-600:#cbd5e1;
        --accent:#6366f1; --accent-weak:#1f254d; --border:#1f2a44; --chip:#ffffff14;
        --shadow:0 10px 25px rgba(0,0,0,.35);
      }
      a.btn, .btn{ color:#fff; }
    }

    *{box-sizing:border-box}
    body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial;margin:0}
    .container{max-width:1200px;margin:0 auto;padding:16px}

    /* NAV */
    .nav{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-radius:16px;background:linear-gradient(120deg,#0f172a,#1f2937);color:#fff;box-shadow:var(--shadow)}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.3px}
    .brand .logo{width:28px;height:28px;border-radius:8px;background:#fff1;border:1px solid #ffffff22;display:grid;place-items:center}
    .nav ul{display:flex;gap:10px;list-style:none;margin:0;padding:0}
    .nav a{color:#fff;text-decoration:none;padding:8px 12px;border-radius:10px}
    .nav a:hover{background:#ffffff1a}

    /* GRID */
    .grid{display:grid;gap:14px}
    @media(min-width:640px){.cols-2{grid-template-columns:repeat(2,1fr)}}
    @media(min-width:940px){.cols-3{grid-template-columns:repeat(3,1fr)} .cols-4{grid-template-columns:repeat(4,1fr)}}

    /* CARD */
    .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:var(--shadow)}
    .card.hover{transition:transform .2s ease, box-shadow .2s ease}
    .card.hover:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,0,0,.10)}

    /* KPI */
    .kpi{display:flex;flex-direction:column;gap:8px;position:relative;overflow:hidden}
    .kpi .row{display:flex;align-items:center;justify-content:space-between}
    .kpi .icon{width:36px;height:36px;border-radius:12px;background:var(--accent-weak);display:grid;place-items:center;border:1px solid var(--border)}
    .kpi .label{color:var(--muted);font-size:13px}
    .kpi .value{font-size:24px;font-weight:800}
    .kpi .sub{color:var(--muted);font-size:12px}

    /* TABLE */
    .table{width:100%;border-collapse:separate;border-spacing:0 10px}
    .table th{font-size:12px;color:var(--muted);text-align:right;padding:0 12px}
    .table td{padding:12px;background:var(--card);border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
    .table tr{transition:transform .12s ease, background .12s ease}
    .table tr:hover td{background:color-mix(in oklab, var(--card) 92%, var(--accent) 8%)}
    .table tr td:first-child{border-radius:12px 0 0 12px}
    .table tr td:last-child{border-radius:0 12px 12px 0}

    /* BUTTONS + CHIPS */
    .btn{display:inline-flex;align-items:center;gap:8px;background:var(--primary);color:#fff;border:none;padding:10px 12px;border-radius:12px;text-decoration:none;font-weight:600}
    .btn:hover{background:var(--primary-600)}
    .btn.ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
    .chip{font-size:12px;padding:4px 10px;border-radius:999px;background:var(--chip);border:1px solid var(--border);display:inline-flex;gap:6px;align-items:center}

    /* QUICK LINKS */
    .quick-grid{display:grid;gap:12px}
    @media(min-width:560px){ .quick-grid{grid-template-columns:repeat(2,1fr)} }
    @media(min-width:880px){ .quick-grid{grid-template-columns:repeat(4,1fr)} }
    .qcard{display:block;padding:14px;border:1px solid var(--border);border-radius:14px;background:var(--card);text-decoration:none;color:inherit}
    .qcard strong{display:block;margin-bottom:6px}
    .qcard:hover{box-shadow:var(--shadow);transform:translateY(-2px);transition:.2s}

    .footer{margin-top:18px;color:var(--muted);text-align:center}

    .muted{color:var(--muted)}
    .spacer{height:6px}
  </style>
</head>
<body>
<div class="container">

  <nav class="nav">
    <div class="brand">
      <span class="logo">
        <!-- Logo minimalist -->
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="4" stroke="white" stroke-opacity=".7"/><path d="M7 13l3 3 7-7" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
      </span>
      <span>العزباوية</span>
    </div>
    <ul>
      <li><a href="/3zbawyh/public/dashboard.php">اللوحة</a></li>
      <li><a href="/3zbawyh/public/select_category.php">نقطة البيع (POS)</a></li>
      <li><a href="/3zbawyh/public/reports.php">التقارير</a></li>
      <li><a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a></li>
      
    </ul>
  </nav>

  <!-- KPIs -->
  <div class="grid cols-4" style="margin-top:14px">
    <div class="card kpi hover">
      <div class="row">
        <div class="label">مبيعات اليوم</div>
        <div class="icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 12h18M7 12v7m5-7v7m5-7v7M6 8h12l-1-3H7l-1 3Z" stroke="currentColor" stroke-width="1.5"/></svg>
        </div>
      </div>
      <div class="value"><?=nf($todaySales)?> <span class="muted" style="font-size:12px">EGP</span></div>
      <div class="sub">عدد فواتير اليوم: <?=nf($todayInvoices)?></div>
    </div>

    <div class="card kpi hover">
      <div class="row">
        <div class="label">إجمالي الفواتير</div>
        <div class="icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 4h14v16H5zM9 8h6M9 12h6M9 16h6" stroke="currentColor" stroke-width="1.5"/></svg>
        </div>
      </div>
      <div class="value"><?=nf($totalInvoices)?></div>
      <div class="sub">منذ البداية</div>
    </div>

    <div class="card kpi hover">
      <div class="row">
        <div class="label">الأصناف</div>
        <div class="icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7l9-4 9 4-9 4-9-4Zm0 5l9 4 9-4M3 17l9 4 9-4" stroke="currentColor" stroke-width="1.5"/></svg>
        </div>
      </div>
      <div class="value"><?=nf($totalProducts)?></div>
      <div class="sub"><?=nf($lowStockCount)?> منخفض/نافد</div>
    </div>

    <div class="card kpi hover">
      <div class="row">
        <div class="label">مرحباً</div>
        <div class="icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.5"/><path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.5"/></svg>
        </div>
      </div>
      <div class="value"><?=e($u['username'])?></div>
      <div class="sub">دورك: <?=e($u['role'] ?? '')?></div>
    </div>
  </div>

  <div class="grid cols-2" style="margin-top:14px">
    <!-- Latest invoices -->
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <h3 style="margin:0">آخر الفواتير</h3>
        <div style="display:flex;gap:8px">
          <a class="btn ghost" href="/3zbawyh/public/reports.php">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M5 4h14v16H5zM9 8h6M9 12h6M9 16h6" stroke="currentColor" stroke-width="1.5"/></svg>
            عرض التقارير
          </a>
          <a class="btn" href="/3zbawyh/public/select_category.php">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M7 6h10l2 5H5l2-5Zm-2 7h14l-1 4H6l-1-4Z" stroke="currentColor" stroke-width="1.5"/></svg>
            فتح POS
          </a>
        </div>
      </div>

      <?php if (!$latestInvoices || count($latestInvoices)===0): ?>
        <div class="spacer"></div>
        <p class="muted">لا توجد فواتير بعد.</p>
      <?php else: ?>
        <div class="spacer"></div>
        <table class="table" dir="rtl">
          <thead>
            <tr>
              <th>#</th>
              <th>العميل</th>
              <th>الكاشير</th>
              <th>الإجمالي</th>
              <th>التاريخ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($latestInvoices as $inv): ?>
            <tr>
              <td><?=e($inv['invoice_no'] ?? $inv['id'])?></td>
              <td><?=e($inv['customer_name'])?></td>
              <td><?=e($inv['cashier_name'] ?? '—')?></td>
              <td><strong><?=nf($inv['total_amount'])?></strong> <span class="muted">EGP</span></td>
              <td><?=e(isset($inv['created_at']) && $inv['created_at'] ? date('Y-m-d H:i', strtotime($inv['created_at'])) : '—')?></td>
              <td style="text-align:left">
                <a class="btn ghost" href="/3zbawyh/public/invoice_show.php?id=<?=urlencode($inv['id'])?>" title="عرض الفاتورة">
                  عرض
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Quick links -->
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">روابط سريعة</h3>
        <span class="chip">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 6v12M6 12h12" stroke="currentColor" stroke-width="2"/></svg>
          وصول أسرع
        </span>
      </div>

      <div class="spacer"></div>
      <div class="quick-grid">
        <a class="qcard" href="/3zbawyh/public/reports.php">
          <strong>التقارير</strong>
          <div class="muted">ملخصات يومية/شهرية</div>
        </a>
        <a class="qcard" href="/3zbawyh/public/categories.php">
          <strong>التصنيفات</strong>
          <div class="muted">إضافة/تعديل/حذف</div>
        </a>
        <a class="qcard" href="/3zbawyh/public/items_manage.php">
          <strong>الأصناف</strong>
          <div class="muted">إدارة وربط بتصنيف</div>
        </a>
        <a class="qcard" href="/3zbawyh/public/users.php">
          <strong>Users Mangment</strong>
          <div class="muted">To manage users and cashers</div>
        </a>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
      <div class="muted">الوقت الآن: <?=e(now_egypt())?></div>
    </div>
  </div>

  <!-- System status -->
  <div class="card" style="margin-top:14px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <h3 style="margin:0">حالة النظام</h3>
      <?php if ($lowStockCount>0): ?>
        <span class="chip" style="background:color-mix(in oklab, var(--warning) 18%, transparent);">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.3 4.9l-7.2 12.5a2 2 0 0 0 1.7 3h14.4a2 2 0 0 0 1.7-3L13.7 4.9a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="1.6"/></svg>
          مخزون منخفض: <?=nf($lowStockCount)?>
        </span>
      <?php else: ?>
        <span class="chip" style="background:color-mix(in oklab, var(--success) 18%, transparent);">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10" stroke="currentColor" stroke-width="2"/></svg>
          المخزون جيد
        </span>
      <?php endif; ?>
    </div>
    <ul class="muted" style="margin:10px 0 0;padding-inline-start:18px;line-height:1.9">
      <li>جداول حرجة: <code>items</code> / <code>sales_invoices</code> / <code>sales_items</code> ✅</li>
      <li><?= $dateCol ? "التاريخ: نستخدم العمود <code>$dateCol</code>." : "ملاحظة: <code>sales_invoices</code> بدون عمود تاريخ — يُفضّل إضافة <code>created_at</code>." ?></li>
    </ul>
  </div>

  <footer class="footer"><small>© <?=date('Y')?> العزباوية</small></footer>
</div>
</body>
</html>
