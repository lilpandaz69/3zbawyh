<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
if (function_exists('is_cashier') && is_cashier()) { header('Location: /3zbawyh/public/pos.php'); exit; }

date_default_timezone_set('Africa/Cairo');
$db = db();

function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, (floor($n)==$n?0:2), '.', ','); }

/* ---------- اكتشاف الأعمدة ديناميكياً ---------- */
function cols_of(PDO $db, string $table): array {
  $st = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$table]);
  return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}
function first_existing_expr(array $have, array $prefs, string $aliasPrefix): ?string {
  $parts = [];
  foreach ($prefs as $p) { if (in_array($p, $have, true)) $parts[] = "$aliasPrefix`$p`"; }
  if (!$parts) return null;
  return 'COALESCE('.implode(',', $parts).')';
}
function detect_date_col(PDO $db, string $table, array $prefs=['created_at','created_on','invoice_date','date','ts','timestamp','time']): ?string {
  $st = $db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE IN ('datetime','timestamp','date','time')");
  $st->execute([$table]);
  $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR); // name => type
  if (!$rows) return null;
  foreach ($prefs as $p) if (isset($rows[$p])) return $p;
  return array_key_first($rows);
}

/* أعمدة العملاء والكاشير المتاحة */
$cCols = cols_of($db, 'customers');            // ممكن تكون فاضية لو مفيش جدول
$uCols = cols_of($db, 'users');

$CUST_NAME_EXPR = first_existing_expr($cCols, ['name','customer_name','full_name','title'], 'c.') ?: "CONCAT('عميل #', si.customer_id)";
$CASHIER_NAME_EXPR = first_existing_expr($uCols, ['username','name','full_name','display_name'], 'u.') ?: "CONCAT('كاشير #', si.cashier_id)";

/* أعمدة الوقت في sales_invoices (ممكن تكون مش موجودة) */
$dateCol = detect_date_col($db, 'sales_invoices'); // قد ترجع null

/* ---------- فلاتر ---------- */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$fromTs = $from.' 00:00:00';
$toTs   = $to  .' 23:59:59';

$cashierId  = (int)($_GET['cashier_id']  ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$catId      = (int)($_GET['category_id'] ?? 0);

/* WHERE */
$where   = [];
$params  = [];
if ($dateCol)           { $where[] = "si.`$dateCol` BETWEEN ? AND ?"; $params[] = $fromTs; $params[] = $toTs; }
if ($cashierId  > 0)    { $where[] = "si.cashier_id = ?";            $params[] = $cashierId; }
if ($customerId > 0)    { $where[] = "si.customer_id = ?";           $params[] = $customerId; }
if ($catId      > 0)    { $where[] = "i.category_id = ?";            $params[] = $catId; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

/* ثوابت حسب جداولك */
$LINE_TOT = "COALESCE(it.line_total, it.qty * it.unit_price)";
$DAY_EXPR = $dateCol ? "DATE(si.`$dateCol`)" : "DATE(NOW())";

/* ---------- تصدير CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="sales_'.$from.'_to_'.$to.'.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['InvoiceID','InvoiceNo','Date','Customer','Cashier','Total']);

  $sqlCsv = "
    SELECT 
      si.id, si.invoice_no, ".($dateCol ? "si.`$dateCol`" : "NULL")." AS created_at,
      $CUST_NAME_EXPR AS customer_name,
      $CASHIER_NAME_EXPR AS cashier_name,
      SUM($LINE_TOT) AS total_amount
    FROM sales_invoices si
    LEFT JOIN sales_items it ON it.invoice_id = si.id
    LEFT JOIN items i        ON i.id = it.item_id
    ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
    ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
    WHERE $whereSql
    GROUP BY si.id, si.invoice_no".($dateCol ? ", si.`$dateCol`" : "").", customer_name, cashier_name
    ORDER BY ".($dateCol ? "si.`$dateCol`" : "si.id")." ASC";
  $st = $db->prepare($sqlCsv); $st->execute($params);
  while($r = $st->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out, [$r['id'],$r['invoice_no'],$r['created_at'],$r['customer_name'],$r['cashier_name'],$r['total_amount']]);
  }
  fclose($out); exit;
}

/* ---------- إجمالي الفترة ---------- */
$sqlSum = "
  SELECT 
    COALESCE(SUM($LINE_TOT),0) AS total_sales,
    COUNT(DISTINCT si.id) AS invoices_count
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  LEFT JOIN items i        ON i.id = it.item_id
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
  WHERE $whereSql";
$st = $db->prepare($sqlSum); $st->execute($params);
list($totalSales, $countInvoices) = $st->fetch(PDO::FETCH_NUM);

/* ---------- تجميع يومي ---------- */
$sqlByDay = "
  SELECT 
    $DAY_EXPR AS d,
    COALESCE(SUM($LINE_TOT),0) AS s,
    COUNT(DISTINCT si.id) AS c
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  LEFT JOIN items i        ON i.id = it.item_id
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
  WHERE $whereSql
  GROUP BY d
  ORDER BY d ASC";
$st = $db->prepare($sqlByDay); $st->execute($params);
$labels=[]; $values=[]; $counts=[];
while($r = $st->fetch(PDO::FETCH_ASSOC)){ $labels[]=$r['d']; $values[]=(float)$r['s']; $counts[]=(int)$r['c']; }

/* ---------- جدول الفواتير ---------- */
$sqlList = "
  SELECT 
    si.id, si.invoice_no, ".($dateCol ? "si.`$dateCol`" : "NULL")." AS created_at,
    $CUST_NAME_EXPR AS customer_name,
    $CASHIER_NAME_EXPR AS cashier_name,
    SUM($LINE_TOT) AS total_amount
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  LEFT JOIN items i        ON i.id = it.item_id
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
  WHERE $whereSql
  GROUP BY si.id, si.invoice_no".($dateCol ? ", si.`$dateCol`" : "").", customer_name, cashier_name
  ORDER BY ".($dateCol ? "si.`$dateCol`" : "si.id")." DESC
  LIMIT 300";
$st = $db->prepare($sqlList); $st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Top الأصناف ---------- */
$sqlTop = "
  SELECT 
    COALESCE(i.name, CONCAT('صنف #', it.item_id)) AS item_name,
    SUM(it.qty) AS qty,
    SUM($LINE_TOT) AS total
  FROM sales_items it
  JOIN sales_invoices si ON si.id = it.invoice_id
  LEFT JOIN items i      ON i.id = it.item_id
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
  WHERE $whereSql
  GROUP BY item_name
  ORDER BY total DESC
  LIMIT 10";
$st = $db->prepare($sqlTop); $st->execute($params);
$topItems = $st->fetchAll(PDO::FETCH_ASSOC);

/* قوائم الفلاتر */
$cashiers  = $db->query("SELECT id, ".(in_array('username',$uCols,true)?'username':(in_array('name',$uCols,true)?'name':'id'))." AS label FROM users ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query("SELECT id, ".(in_array('name',$cCols,true)?'name':(in_array('full_name',$cCols,true)?'full_name':'id'))." AS label FROM customers ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = [];
try { $categories = $db->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>تقارير المبيعات</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{background:#f6f7fb;color:#111;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial}
    .container{max-width:1200px;margin:0 auto;padding:16px}
    .card{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    .grid{display:grid;gap:12px}
    @media(min-width:940px){.grid.cols-2{grid-template-columns:1fr 1fr}}
    .btn{display:inline-block;background:#111;color:#fff;border:none;padding:10px 12px;border-radius:10px;text-decoration:none}
    .table{width:100%;border-collapse:separate;border-spacing:0 8px}
    .table th{font-size:13px;color:#6b7280;text-align:right}
    .table td,.table th{padding:8px 10px;background:#fff}
    .muted{color:#6b7280}
    input,select{padding:8px;border:1px solid #ddd;border-radius:8px}
    canvas{width:100%;max-width:100%;height:260px}
    .note{background:#fff3cd;color:#7a5d00;padding:8px 10px;border-radius:8px;border:1px solid #ffe69c}
  </style>
</head>
<body>
<div class="container">

  <div class="card" style="margin-bottom:12px">
    <div class="row" style="justify-content:space-between">
      <h2 style="margin:0">تقارير المبيعات</h2>
      <a class="btn" href="/3zbawyh/public/dashboard.php">عودة للوحة</a>
    </div>

    <?php if (!$dateCol): ?>
      <p class="note">ملاحظة: جدول <strong>sales_invoices</strong> لا يحتوي على عمود تاريخ — لذلك التقارير تعمل بدون فلتر تاريخ. يُفضّل إضافة عمود مثل <code>created_at DATETIME DEFAULT CURRENT_TIMESTAMP</code>.</p>
    <?php endif; ?>

    <form class="row" method="get" style="margin-top:10px">
      <label>من: <input type="date" name="from" value="<?=e2($from)?>" <?=(!$dateCol?'disabled':'')?>></label>
      <label>إلى: <input type="date" name="to" value="<?=e2($to)?>" <?=(!$dateCol?'disabled':'')?>></label>

      <label>الكاشير:
        <select name="cashier_id">
          <option value="0">الكل</option>
          <?php foreach($cashiers as $c): ?>
            <option value="<?=$c['id']?>" <?=$cashierId==$c['id']?'selected':''?>><?=e2($c['label'])?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>العميل:
        <select name="customer_id">
          <option value="0">الكل</option>
          <?php foreach($customers as $c): ?>
            <option value="<?=$c['id']?>" <?=$customerId==$c['id']?'selected':''?>><?=e2($c['label'])?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <?php if ($categories): ?>
      <label>التصنيف:
        <select name="category_id">
          <option value="0">الكل</option>
          <?php foreach($categories as $c): ?>
            <option value="<?=$c['id']?>" <?=$catId==$c['id']?'selected':''?>><?=e2($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>

      <button class="btn" type="submit">تطبيق</button>
      <a class="btn" href="?from=<?=e2($from)?>&to=<?=e2($to)?>&cashier_id=<?=$cashierId?>&customer_id=<?=$customerId?>&category_id=<?=$catId?>&export=csv">تصدير CSV</a>
    </form>

    <div class="row" style="margin-top:10px">
      <div><strong>إجمالي الفترة:</strong> <?=nf($totalSales)?> EGP</div>
      <div class="muted">عدد الفواتير: <?=nf($countInvoices)?></div>
    </div>
  </div>

  <div class="grid cols-2">
    <div class="card">
      <h3 style="margin:0 0 8px">المبيعات اليومية</h3>
      <canvas id="salesChart"></canvas>
    </div>
    <div class="card">
      <h3 style="margin:0 0 8px">Top 10 أصناف بالمبيعات</h3>
      <?php if (!$topItems): ?>
        <p class="muted">لا توجد بيانات.</p>
      <?php else: ?>
        <table class="table" dir="rtl">
          <thead><tr><th>الصنف</th><th>الكمية</th><th>الإجمالي</th></tr></thead>
          <tbody>
          <?php foreach($topItems as $ti): ?>
            <tr>
              <td><?=e2($ti['item_name'])?></td>
              <td><?=nf($ti['qty'])?></td>
              <td><?=nf($ti['total'])?> EGP</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 8px">الفواتير</h3>
    <?php if (!$rows): ?>
      <p class="muted">لا توجد بيانات.</p>
    <?php else: ?>
      <table class="table" dir="rtl">
        <thead><tr><th>#</th><th>العميل</th><th>الكاشير</th><th>الإجمالي</th><th>التاريخ</th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=e2($r['invoice_no'] ?? $r['id'])?></td>
            <td><?=e2($r['customer_name'])?></td>
            <td><?=e2($r['cashier_name'])?></td>
            <td><?=nf($r['total_amount'])?> EGP</td>
            <td><?=e2($r['created_at'] ?? '—')?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<script>
(function(){
  const labels = <?=json_encode($labels, JSON_UNESCAPED_UNICODE)?>;
  const values = <?=json_encode($values, JSON_UNESCAPED_UNICODE)?>;
  const cvs = document.getElementById('salesChart');
  const ctx = cvs.getContext('2d');

  function draw(){
    const W=cvs.clientWidth, H=cvs.clientHeight;
    if(cvs.width!==W) cvs.width=W; if(cvs.height!==H) cvs.height=H;
    ctx.clearRect(0,0,W,H); ctx.font='12px system-ui'; ctx.fillStyle='#111'; ctx.strokeStyle='#999';
    if(!values.length){ ctx.fillText('لا توجد بيانات ضمن المدى.', 10, 20); return; }
    const pad=28, max=Math.max(...values)||1, min=0, xStep=(W-pad*2)/Math.max(1,values.length-1);
    ctx.beginPath(); ctx.moveTo(pad,pad); ctx.lineTo(pad,H-pad); ctx.lineTo(W-pad,H-pad); ctx.stroke();
    const ticks=4; ctx.textAlign='right';
    for(let i=0;i<=ticks;i++){ const v=min+(max-min)*(i/ticks); const y=H-pad-((v-min)/(max-min))*(H-pad*2);
      ctx.fillText(v.toFixed(0), pad-6, y+4); ctx.strokeStyle='#eee'; ctx.beginPath(); ctx.moveTo(pad,y); ctx.lineTo(W-pad,y); ctx.stroke(); }
    ctx.strokeStyle='#0a0a0a'; ctx.lineWidth=2; ctx.beginPath();
    values.forEach((v,i)=>{ const x=pad+i*xStep; const y=H-pad-((v-min)/(max-min))*(H-pad*2); if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y); });
    ctx.stroke(); ctx.fillStyle='#000';
    values.forEach((v,i)=>{ const x=pad+i*xStep; const y=H-pad-((v-min)/(max-min))*(H-pad*2); ctx.beginPath(); ctx.arc(x,y,3,0,Math.PI*2); ctx.fill(); });
  }
  addEventListener('resize', draw); draw();
})();
</script>
</body>
</html>
