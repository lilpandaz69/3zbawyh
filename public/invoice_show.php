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
  foreach ($candidates as $c){ if (in_array($c, $cols, true)) return $c; }
  return null;
}

/* ========== تحديد أسماء الجداول والأعمدة تلقائيًا ========== */
$invoicesTable = 'sales_invoices';
if (function_exists('table_exists')) {
  if (!table_exists($db, $invoicesTable)) {
    foreach (['invoices','sale_invoices','sales_invoice'] as $t){
      if (table_exists($db, $t)) { $invoicesTable = $t; break; }
    }
  }
}

$itemsTable = null;
foreach (['sales_items','invoice_items','sale_items','items_sold'] as $t){
  if (function_exists('table_exists') ? table_exists($db,$t) : true) {
    try{
      $db->query("SELECT 1 FROM `$t` LIMIT 1");
      $itemsTable = $t; break;
    }catch(Throwable $e){}
  }
}
if (!$itemsTable) { http_response_code(500); exit('لم يتم العثور على جدول البنود (sales_items / invoice_items).'); }

$customersTable = null;
try{
  $db->query("SELECT 1 FROM customers LIMIT 1");
  $customersTable = 'customers';
}catch(Throwable $e){}

/* أعمدة جدول الفواتير */
$invCols = columns_of($db, $invoicesTable);
$invIdCol      = pick_col($invCols, ['id','invoice_id']);
$invNoCol      = pick_col($invCols, ['invoice_no','number','invoice_number','code']) ?? $invIdCol;
$invDateCol    = pick_col($invCols, ['created_at','invoice_date','date','ts','timestamp','created_on']);
$invSubtotal   = pick_col($invCols, ['subtotal']);
$invDiscount   = pick_col($invCols, ['discount']);
$invTax        = pick_col($invCols, ['tax']);
$invTotalCol   = pick_col($invCols, ['total','grand_total']);
$invPayMethod  = pick_col($invCols, ['payment_method']);
$invPaidAmount = pick_col($invCols, ['paid_amount','amount_paid']);
$invChangeDue  = pick_col($invCols, ['change_due','change']);
$invPayRef     = pick_col($invCols, ['payment_ref','ref_no','reference']);
$invPayNote    = pick_col($invCols, ['payment_note','note','notes']);

if (!$invIdCol) { http_response_code(500); exit('لم يتم العثور على عمود معرف الفاتورة في جدول الفواتير.'); }

/* أعمدة جدول البنود */
$itemCols       = columns_of($db, $itemsTable);
$itemFkCol      = pick_col($itemCols, ['sales_invoice_id','invoice_id','inv_id']);
$itemNameCol    = pick_col($itemCols, ['product_name','item_name','name','title','description']);
$itemQtyCol     = pick_col($itemCols, ['quantity','qty','qte','amount','count']);
$itemPriceCol   = pick_col($itemCols, ['unit_price','price','sell_price','unitprice','rate']);
$itemLineCol    = pick_col($itemCols, ['line_total','total','subtotal','lineamount','amount_total']);
$itemItemFkCol  = pick_col($itemCols, ['item_id','product_id','items_id']); // الربط مع جدول items

if (!$itemFkCol) { http_response_code(500); exit('لم يتم العثور على عمود الربط بالفاتورة داخل جدول البنود (invoice_id).'); }

/* العملاء (اختياري) */
$customerNameExpr = "'عميل نقدي'";
if ($customersTable) {
  $custCols = columns_of($db, $customersTable);
  $custNameCol = pick_col($custCols, ['name','customer_name','full_name']);
  if ($custNameCol) $customerNameExpr = "COALESCE(c.`$custNameCol`, 'عميل نقدي')";
}

/* ========== تعبيرات البنود ========== */
$itAlias = 'it';
$qtyExpr   = $itemQtyCol   ? "$itAlias.`$itemQtyCol`"   : "1";
$priceExpr = $itemPriceCol ? "$itAlias.`$itemPriceCol`" : "0";
$lineExpr  = $itemLineCol  ? "COALESCE($itAlias.`$itemLineCol`, ($qtyExpr * $priceExpr))"
                           : "($qtyExpr * $priceExpr)";
$dateSelect = $invDateCol ? "si.`$invDateCol` AS created_at" : "NULL AS created_at";

/* ========== جلب الفاتورة ========== */
$selectExtra = [];
if ($invSubtotal)   $selectExtra[] = "si.`$invSubtotal`   AS subtotal";
if ($invDiscount)   $selectExtra[] = "si.`$invDiscount`   AS discount";
if ($invTax)        $selectExtra[] = "si.`$invTax`        AS tax";
if ($invTotalCol)   $selectExtra[] = "si.`$invTotalCol`   AS total_db";
if ($invPayMethod)  $selectExtra[] = "LOWER(TRIM(si.`$invPayMethod`)) AS payment_method";
if ($invPaidAmount) $selectExtra[] = "si.`$invPaidAmount` AS paid_amount";
if ($invChangeDue)  $selectExtra[] = "si.`$invChangeDue`  AS change_due";
if ($invPayRef)     $selectExtra[] = "si.`$invPayRef`     AS payment_ref";
if ($invPayNote)    $selectExtra[] = "si.`$invPayNote`    AS payment_note";

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
    ".($selectExtra ? ",".implode(",", $selectExtra) : "")."
  FROM `$invoicesTable` si
  ".($customersTable ? "LEFT JOIN `$customersTable` c ON c.id = si.customer_id" : "")."
  WHERE si.`$invIdCol` = ?
  LIMIT 1
";
$st = $db->prepare($sqlInv);
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); exit('الفاتورة غير موجودة'); }

/* ========== تجهيز اسم المنتج من جدول items ========== */
$itemsMasterTable = null;
try{
  $db->query("SELECT 1 FROM items LIMIT 1");
  $itemsMasterTable = 'items';
}catch(Throwable $e){
  $itemsMasterTable = null;
}

/*
  لو جدول items موجود ومعانا عمود الربط (item_id / product_id / items_id)
  هنجيب الاسم من items.name، ولو مش موجود نرجع للاسم الموجود في جدول البنود أو "بند #"
*/
$itemsJoin = "";
if ($itemsMasterTable && $itemItemFkCol) {
  $baseNameExpr = $itemNameCol
    ? "$itAlias.`$itemNameCol`"
    : "CONCAT('بند #', $itAlias.id)";
  $nameExpr = "COALESCE(i.name, $baseNameExpr)";
  $itemsJoin = "LEFT JOIN `$itemsMasterTable` i ON i.id = $itAlias.`$itemItemFkCol`";
} else {
  $nameExpr = $itemNameCol ? "$itAlias.`$itemNameCol`" : "CONCAT('بند #', $itAlias.id)";
  $itemsJoin = "";
}

/* ========== جلب البنود ========== */
$sqlItems = "
  SELECT
    $itAlias.id,
    $nameExpr AS product_name,
    ".($itemQtyCol   ? "$itAlias.`$itemQtyCol`"   : "1")."  AS quantity,
    ".($itemPriceCol ? "$itAlias.`$itemPriceCol`" : "0")."  AS unit_price,
    $lineExpr AS line_total
  FROM `$itemsTable` $itAlias
  $itemsJoin
  WHERE $itAlias.`$itemFkCol` = ?
  ORDER BY $itAlias.id ASC
";
$sti = $db->prepare($sqlItems);
$sti->execute([$id]);
$items = $sti->fetchAll(PDO::FETCH_ASSOC);

/* ========== فك الـpayment_note (لو موجود) ========== */
function parse_distribution_note(?string $note): array {
  $res = ['cash'=>0,'visa'=>0,'instapay'=>0,'vodafone_cash'=>0,'agel'=>0,'other'=>0];
  if (!$note) return $res;

  $note = trim($note);

  // 1) MULTI;method,amount,ref,note;...
  if (stripos($note, 'MULTI;') === 0) {
    $parts = explode(';', $note);
    array_shift($parts); // remove MULTI
    foreach ($parts as $seg) {
      if ($seg === '') continue;
      $bits = explode(',', $seg);
      $method = isset($bits[0]) ? strtolower(trim(urldecode($bits[0]))) : '';
      $amount = isset($bits[1]) ? (float)urldecode($bits[1]) : 0;
      if ($method === 'agyl') $method = 'agel';
      if (!isset($res[$method])) $method = 'other';
      $res[$method] += $amount;
    }
    return $res;
  }

  // 2) Dist: cash=.., visa=.., instapay=.., vodafone_cash=.., agyl=..
  if (stripos($note, 'dist:') === 0) {
    $str = trim(substr($note, 5));
    foreach (explode(',', $str) as $pair) {
      $pair = trim($pair);
      if ($pair === '') continue;
      $kv = explode('=', $pair);
      if (count($kv) !== 2) continue;
      $k = strtolower(trim($kv[0]));
      $v = (float)trim($kv[1]);
      if ($k === 'agyl') $k = 'agel';
      if (!isset($res[$k])) $k = 'other';
      $res[$k] += $v;
    }
    return $res;
  }

  // أي نص تاني: هنظهره كما هو، بس مش هنقدر نوزع منه
  return $res;
}

/* تجهيز التاريخ للعرض */
$created_at_text = ($inv['created_at'] ?? null) ? date('Y-m-d H:i', strtotime($inv['created_at'])) : '—';

/* نحضر بيانات الدفع للعرض */
$pm   = strtolower(trim((string)($inv['payment_method'] ?? '')));
$ref  = trim((string)($inv['payment_ref'] ?? ''));
$note = (string)($inv['payment_note'] ?? '');

$dist = parse_distribution_note($note);

$byMethod = ['cash'=>0,'visa'=>0,'instapay'=>0,'vodafone_cash'=>0,'agel'=>0,'other'=>0];

if ($pm === 'mixed') {
  // لو mixed نستخدم التوزيع من الـnote (لو فاضي هتفضل صفار)
  $byMethod = $dist;
} else {
  // طريقة واحدة: نسجل إجمالي الفاتورة للطريقة دي
  $single = $pm;
  if ($single === 'agyl') $single = 'agel';
  if (!isset($byMethod[$single])) $single = 'other';
  $byMethod[$single] = (float)$inv['total_amount'];
}

/* لو total_db موجود نفضله على المحسوب من البنود */
$totalDisplay = isset($inv['total_db']) ? (float)$inv['total_db'] : (float)$inv['total_amount'];

$subtotal = isset($inv['subtotal']) ? (float)$inv['subtotal'] : null;
$discount = isset($inv['discount']) ? (float)$inv['discount'] : null;
$tax      = isset($inv['tax'])      ? (float)$inv['tax']      : null;
$paidAmt  = isset($inv['paid_amount']) ? (float)$inv['paid_amount'] : null;
$change   = isset($inv['change_due'])  ? (float)$inv['change_due']  : null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>فاتورة #<?=e2($inv['invoice_no'] ?? $inv['id'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- نفس ستايل السيستم العام -->
  <link rel="stylesheet" href="/3zbawyh/public/style.css">

  <style>
    body{
      background:#f3f4f6;
      color:#111827;
      font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic","Tahoma",sans-serif;
    }
    .invoice-wrapper{
      max-width:960px;
      margin:24px auto;
    }
    .top-bar{
      display:flex;
      justify-content:space-between;
      align-items:center;
      margin-bottom:16px;
      gap:8px;
      flex-wrap:wrap;
    }
    .top-bar-left{
      font-weight:bold;
      font-size:18px;
      color:#111827;
    }
    .btn-group{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .btn-small{
      padding:8px 12px;
      border-radius:8px;
      border:0;
      cursor:pointer;
      font-size:14px;
      text-decoration:none;
      display:inline-block;
    }
    .btn-main{
      background:#111827;
      color:#fff;
    }
    .btn-light{
      background:#e5e7eb;
      color:#111827;
    }

    .invoice-card{
      background:#fff;
      border-radius:14px;
      padding:18px 20px;
      box-shadow:0 2px 8px rgba(15,23,42,.06);
      margin-bottom:14px;
    }

    .invoice-header-grid{
      display:flex;
      flex-wrap:wrap;
      justify-content:space-between;
      gap:16px;
    }
    .box{
      flex:1;
      min-width:260px;
    }
    .label{
      font-size:12px;
      color:#6b7280;
      margin-bottom:2px;
    }
    .value{
      font-size:15px;
      font-weight:600;
      color:#111827;
    }
    .invoice-title{
      font-size:22px;
      margin-bottom:6px;
      font-weight:700;
    }
    .invoice-subline{
      font-size:13px;
      color:#6b7280;
    }

    .section-title{
      font-size:15px;
      font-weight:700;
      margin-bottom:10px;
      color:#111827;
    }

    table.items-table{
      width:100%;
      border-collapse:collapse;
      font-size:14px;
    }
    .items-table thead{
      background:#f9fafb;
      border-bottom:1px solid #e5e7eb;
    }
    .items-table th{
      padding:10px 8px;
      color:#6b7280;
      font-weight:600;
      text-align:right;
      white-space:nowrap;
    }
    .items-table td{
      padding:8px 8px;
      border-bottom:1px solid #f3f4f6;
      vertical-align:middle;
    }
    .items-table tbody tr:nth-child(even){
      background:#f9fafb;
    }
    .items-table .num{
      text-align:left;
      white-space:nowrap;
    }
    .items-table tfoot td{
      background:#f9fafb;
      font-weight:600;
    }

    .summary-lines{
      display:flex;
      flex-direction:column;
      gap:4px;
      font-size:14px;
    }
    .summary-line{
      display:flex;
      justify-content:space-between;
      align-items:center;
    }
    .summary-line span:first-child{
      color:#4b5563;
    }

    .two-column{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      margin-top:10px;
    }

    .badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:3px 10px;
      border-radius:999px;
      background:#f3f4ff;
      color:#1d4ed8;
      font-size:12px;
      font-weight:500;
    }

    .note-box{
      margin-top:10px;
      padding:10px 12px;
      border-radius:10px;
      background:#f9fafb;
      font-size:13px;
      color:#374151;
      max-height:120px;
      overflow:auto;
    }

    .text-muted{
      color:#9ca3af;
      font-size:12px;
      margin-top:6px;
    }

    @media print{
      .top-bar{display:none}
      body{background:#fff;}
      .invoice-wrapper{margin:0;padding:0;max-width:100%;}
      .invoice-card{
        box-shadow:none;
        border:1px solid #e5e7eb;
        border-radius:0;
      }
    }
  </style>
</head>
<body>
<div class="invoice-wrapper">

  <!-- شريط علوي -->
  <div class="top-bar">
    <div class="top-bar-left">
      نظام المبيعات - عرض فاتورة
    </div>
    <div class="btn-group">
      <a href="/3zbawyh/public/dashboard.php" class="btn-small btn-light">الرجوع للوحة التحكم</a>
<a href="/3zbawyh/public/invoice_print.php?id=<?= (int)$inv['id'] ?>"
   class="btn-small btn-main"
   target="_blank">
  طباعة الفاتورة الحرارية
</a>

    </div>
  </div>

  <!-- الهيدر الرئيسي -->
  <div class="invoice-card">
    <div class="invoice-header-grid">
      <div class="box">
        <div class="invoice-title">
          فاتورة #<?=e2($inv['invoice_no'] ?? $inv['id'])?>
        </div>
        <div class="invoice-subline">
          عميل: <strong><?=e2($inv['customer_name'] ?? 'عميل نقدي')?></strong>
        </div>
      </div>

      <div class="box">
        <div class="label">تاريخ الفاتورة</div>
        <div class="value"><?=e2($created_at_text)?></div>

        <div class="label" style="margin-top:8px;">المبلغ الإجمالي</div>
        <div class="value"><?=nf($totalDisplay)?> جنيه</div>
      </div>
    </div>
  </div>

  <!-- تفاصيل الأصناف -->
  <div class="invoice-card">
    <div class="section-title">تفاصيل الأصناف</div>
    <table class="items-table">
      <thead>
      <tr>
        <th>الصنف</th>
        <th style="width:80px;">الكمية</th>
        <th style="width:120px;">سعر الوحدة</th>
        <th style="width:130px;">الإجمالي</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?=e2($it['product_name'])?></td>
          <td class="num"><?=nf($it['quantity'])?></td>
          <td class="num"><?=nf($it['unit_price'])?> جنيه</td>
          <td class="num"><?=nf($it['line_total'])?> جنيه</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
      <?php if ($subtotal !== null): ?>
        <tr>
          <td colspan="3">المجموع قبل الخصم والضريبة</td>
          <td class="num"><?=nf($subtotal)?> جنيه</td>
        </tr>
      <?php endif; ?>
      <?php if ($discount !== null): ?>
        <tr>
          <td colspan="3">الخصم</td>
          <td class="num"><?=nf($discount)?> جنيه</td>
        </tr>
      <?php endif; ?>
      <?php if ($tax !== null): ?>
        <tr>
          <td colspan="3">الضريبة</td>
          <td class="num"><?=nf($tax)?> جنيه</td>
        </tr>
      <?php endif; ?>
      <tr>
        <td colspan="3">الإجمالي النهائي</td>
        <td class="num"><?=nf($totalDisplay)?> جنيه</td>
      </tr>
      </tfoot>
    </table>
  </div>

  <!-- بيانات الدفع + التوزيع -->
  <div class="two-column">
    <!-- بيانات الدفع -->
    <div class="invoice-card" style="flex:1;min-width:280px;">
      <div class="section-title">بيانات الدفع</div>

      <div class="summary-lines">
        <div class="summary-line">
          <span>طريقة الدفع:</span>
          <span class="badge"><?= e2($pm !== '' ? $pm : 'غير محدد') ?></span>
        </div>

        <?php if ($ref): ?>
        <div class="summary-line">
          <span>مرجع الدفع:</span>
          <span><?= e2($ref) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($paidAmt !== null): ?>
        <div class="summary-line">
          <span>المبلغ المدفوع:</span>
          <span><?= nf($paidAmt) ?> جنيه</span>
        </div>
        <?php endif; ?>

        <?php if ($change !== null): ?>
        <div class="summary-line">
          <span>الباقي للعميل:</span>
          <span><?= nf($change) ?> جنيه</span>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($note !== ''): ?>
      <div class="label" style="margin-top:12px;">ملاحظات الدفع</div>
      <div class="note-box">
        <?= nl2br(e2($note)) ?>
      </div>
      <?php endif; ?>

      <div class="text-muted">
        هذه البيانات خاصة بطريقة سداد الفاتورة، لمراجعة الكاشير أو الإدارة.
      </div>
    </div>

    <!-- توزيع المبلغ -->
    <div class="invoice-card" style="flex:1;min-width:280px;">
      <div class="section-title">توزيع المبلغ على وسائل الدفع</div>

      <table class="items-table">
        <tbody>
        <tr>
          <td>نقدي (Cash)</td>
          <td class="num"><?=nf($byMethod['cash'])?> جنيه</td>
        </tr>
        <tr>
          <td>Visa</td>
          <td class="num"><?=nf($byMethod['visa'])?> جنيه</td>
        </tr>
        <tr>
          <td>InstaPay</td>
          <td class="num"><?=nf($byMethod['instapay'])?> جنيه</td>
        </tr>
        <tr>
          <td>Vodafone Cash</td>
          <td class="num"><?=nf($byMethod['vodafone_cash'])?> جنيه</td>
        </tr>
        <tr>
          <td>آجل </td>
          <td class="num"><?=nf($byMethod['agel'])?> جنيه</td>
        </tr>
        <?php if ($byMethod['other'] > 0.0001): ?>
        <tr>
          <td>طرق أخرى</td>
          <td class="num"><?=nf($byMethod['other'])?> جنيه</td>
        </tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
        <tr>
          <td>إجمالي الموزع</td>
          <td class="num">
            <?php $distSum = array_sum($byMethod); ?>
            <?=nf($distSum)?> جنيه
          </td>
        </tr>
        </tfoot>
      </table>

      <?php
      $distSum = array_sum($byMethod);
      if (abs($distSum - $totalDisplay) > 0.01):
      ?>
      <div class="text-muted">
        * ملاحظة: إجمالي التوزيع (<?=nf($distSum)?>) لا يساوي إجمالي الفاتورة (<?=nf($totalDisplay)?>).  
        قد تكون بيانات التوزيع ناقصة أو تم التعديل يدويًا.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="text-align:center;margin-top:18px;font-size:12px;color:#9ca3af;">
    شكرًا لتعاملكم معنا 🌟
  </div>

</div>
</body>
</html>
