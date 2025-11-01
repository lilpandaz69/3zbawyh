<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();

$db = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('فاتورة غير موجودة'); }

date_default_timezone_set('Africa/Cairo');

/* ========== دوال مساعدة عامة ========== */
function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, (floor($n)==$n?0:2), '.', ','); }


function columns_of(PDO $db, $table){
  $st=$db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$table]); return $st->fetchAll(PDO::FETCH_COLUMN);
}

function pick_col(array $cols, array $candidates){
  foreach ($candidates as $c){
    if (in_array($c, $cols, true)) return $c;
  }
  return null;
}

/* ========== تحديد أسماء الجداول والأعمدة تلقائيًا ========== */

/* جدول الفواتير */
$invoicesTable = 'sales_invoices';
if (!table_exists($db, $invoicesTable)) {
  // بدائل شائعة
  foreach (['invoices','sale_invoices','sales_invoice'] as $t){
    if (table_exists($db, $t)) { $invoicesTable = $t; break; }
  }
}

/* جدول البنود */
$itemsTable = null;
foreach (['sales_items','invoice_items','sale_items','items_sold'] as $t){
  if (table_exists($db, $t)) { $itemsTable = $t; break; }
}
if (!$itemsTable) { http_response_code(500); exit('لم يتم العثور على جدول البنود (sales_items / invoice_items).'); }

/* جدول العملاء (اختياري) */
$customersTable = table_exists($db, 'customers') ? 'customers' : null;

/* أعمدة جدول الفواتير */
$invCols = columns_of($db, $invoicesTable);
$invIdCol   = pick_col($invCols, ['id','invoice_id']);
$invNoCol   = pick_col($invCols, ['invoice_no','number','invoice_number','code']) ?? $invIdCol;
$invDateCol = pick_col($invCols, ['created_at','invoice_date','date','ts','timestamp','created_on']);

/* أعمدة جدول البنود */
$itemCols = columns_of($db, $itemsTable);
$itemFkCol   = pick_col($itemCols, ['sales_invoice_id','invoice_id','inv_id']);
$itemNameCol = pick_col($itemCols, ['product_name','item_name','name','title','description']) ?? $invIdCol; // fallback سليم
$itemQtyCol  = pick_col($itemCols, ['quantity','qty','qte','amount','count']);
$itemPriceCol= pick_col($itemCols, ['unit_price','price','sell_price','unitprice','rate']);
$itemLineCol = pick_col($itemCols, ['line_total','total','subtotal','lineamount','amount_total']);

/* لو مفاتيح أساسية ناقصة نوقف برسالة واضحة */
if (!$invIdCol) { http_response_code(500); exit('لم يتم العثور على عمود معرف الفاتورة في جدول الفواتير.'); }
if (!$itemFkCol) { http_response_code(500); exit('لم يتم العثور على عمود الربط بالفاتورة داخل جدول البنود (invoice_id).'); }

/* أعمدة جدول العملاء (اختياري) */
$customerNameExpr = "'عميل نقدي'";
if ($customersTable) {
  $custCols = columns_of($db, $customersTable);
  $custNameCol = pick_col($custCols, ['name','customer_name','full_name']);
  if ($custNameCol) {
    $customerNameExpr = "COALESCE(c.`$custNameCol`, 'عميل نقدي')";
  }
}

/* ========== بناء التعبيرات المحسوبة ========== */

/* تعبير إجمالي السطر: لو فيه عمود line_total نستخدمه؛ غير كده نضرب الكمية في السعر.
   لو الكمية أو السعر مش موجودين، نعتبرهم 0/1 بشكل منطقي لتجنب أخطاء. */
$itAlias = 'it';
$qtyExpr   = $itemQtyCol   ? "$itAlias.`$itemQtyCol`"   : "1";
$priceExpr = $itemPriceCol ? "$itAlias.`$itemPriceCol`" : "0";
$lineExpr  = $itemLineCol  ? "COALESCE($itAlias.`$itemLineCol`, ($qtyExpr * $priceExpr))"
                           : "($qtyExpr * $priceExpr)";

/* تحديد حقل التاريخ للعرض */
$dateSelect = $invDateCol ? "si.`$invDateCol` AS created_at" : "NULL AS created_at";

/* ========== جلب الفاتورة ========== */
$sqlInv = "
  SELECT
    si.`$invIdCol`   AS id,
    si.`$invNoCol`   AS invoice_no,
    $customerNameExpr AS customer_name,
    $dateSelect,
    (
      SELECT COALESCE(SUM($lineExpr), 0)
      FROM `$itemsTable` $itAlias
      WHERE $itAlias.`$itemFkCol` = si.`$invIdCol`
    ) AS total_amount
  FROM `$invoicesTable` si
  ".($customersTable ? "LEFT JOIN `$customersTable` c ON c.id = si.customer_id" : "")."
  WHERE si.`$invIdCol` = ?
  LIMIT 1
";
$st = $db->prepare($sqlInv);
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); exit('الفاتورة غير موجودة'); }

/* ========== جلب البنود ========== */
$nameExpr = $itemNameCol ? "$itAlias.`$itemNameCol`" : "CONCAT('بند #', $itAlias.id)";
$sqlItems = "
  SELECT
    $itAlias.id,
    $nameExpr AS product_name,
    ".($itemQtyCol   ? "$itAlias.`$itemQtyCol`"   : "1")."  AS quantity,
    ".($itemPriceCol ? "$itAlias.`$itemPriceCol`" : "0")."  AS unit_price,
    $lineExpr AS line_total
  FROM `$itemsTable` $itAlias
  WHERE $itAlias.`$itemFkCol` = ?
  ORDER BY $itAlias.id ASC
";
$sti = $db->prepare($sqlItems);
$sti->execute([$id]);
$items = $sti->fetchAll(PDO::FETCH_ASSOC);

/* تجهيز التاريخ للعرض */
$created_at_text = ($inv['created_at'] ?? null) ? date('Y-m-d H:i', strtotime($inv['created_at'])) : '—';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>فاتورة #<?=e2($inv['invoice_no'] ?? $inv['id'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{background:#f6f7fb;color:#111;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial}
    .container{max-width:900px;margin:20px auto;padding:16px}
    .card{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px}
    .table{width:100%;border-collapse:separate;border-spacing:0 8px}
    .table th{font-size:13px;color:#6b7280;text-align:right}
    .table td,.table th{padding:8px 10px;background:#fff}
    .row{display:flex;justify-content:space-between;align-items:center}
    .btn{display:inline-block;background:#111;color:#fff;border:none;padding:10px 12px;border-radius:10px;text-decoration:none}
    .muted{color:#6b7280}
  </style>
</head>
<body>
<div class="container">
  <div class="row" style="margin-bottom:12px">
    <a class="btn" href="/3zbawyh/public/dashboard.php">رجوع</a>
    <a class="btn" href="javascript:window.print()">طباعة</a>
  </div>

  <div class="card" style="margin-bottom:12px">
    <h2 style="margin:0">فاتورة #<?=e2($inv['invoice_no'] ?? $inv['id'])?></h2>
    <div class="muted">
      العميل: <?=e2($inv['customer_name'] ?? 'عميل نقدي')?> —
      التاريخ: <?=e2($created_at_text)?>
    </div>
  </div>

  <div class="card">
    <table class="table" dir="rtl">
      <thead><tr><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?=e2($it['product_name'])?></td>
            <td><?=nf($it['quantity'])?></td>
            <td><?=nf($it['unit_price'])?> EGP</td>
            <td><?=nf($it['line_total'])?> EGP</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="text-align:left"><strong>الإجمالي</strong></td>
          <td><strong><?=nf($inv['total_amount'])?> EGP</strong></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
</body>
</html>
