<?php
// invoice_print.php — Thermal Receipt (80mm/58mm)
// يعتمد على جداول: sales_invoices, sales_items, items, users, customers

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();

$db = db();
$invoice_id = (int)($_GET['id'] ?? 0);

// ========== جلب بيانات الفاتورة ==========
$inv = $db->prepare("
  SELECT 
    i.id,
    i.invoice_no,
    i.invoice_date,
    i.subtotal,
    i.discount,
    i.tax,
    i.total,
    i.payment_method,
    i.paid_amount,
    i.change_due,
    i.created_at,
    u.username  AS cashier,
    c.phone     AS customer_phone ,
    c.name      AS customer
  FROM sales_invoices i
  LEFT JOIN users     u ON u.id = i.cashier_id
  LEFT JOIN customers c ON c.id = i.customer_id
  WHERE i.id = ?
  LIMIT 1
");
$inv->execute([$invoice_id]);
$invoice = $inv->fetch(PDO::FETCH_ASSOC);

// تطبيع الحقول للأسماء اللي القالب بيستخدمها
if ($invoice) {
  $invoice['invoice_number'] = $invoice['invoice_no'] ?? $invoice_id;
  $invoice['created_at']     = $invoice['invoice_date'] ?? $invoice['created_at'] ?? date('Y-m-d H:i:s');

  // تفكيك طريقة الدفع إلى حقول منفصلة
  $pm = strtolower(trim((string)($invoice['payment_method'] ?? '')));
  $invoice['pay_cash']      = null;
  $invoice['pay_card']      = null;
  $invoice['pay_instapay']  = null;

  if ($pm === 'cash') {
    $invoice['pay_cash'] = (float)$invoice['paid_amount'];
  } elseif (in_array($pm, ['card','visa','mastercard','pos'], true)) {
    $invoice['pay_card'] = (float)$invoice['paid_amount'];
  } elseif (in_array($pm, ['instapay','instant','transfer','bank'], true)) {
    $invoice['pay_instapay'] = (float)$invoice['paid_amount'];
  }

  $invoice['change_amount'] = isset($invoice['change_due']) ? (float)$invoice['change_due'] : null;
} else {
  // لو الفاتورة مش موجودة
  http_response_code(404);
  die('لم يتم العثور على الفاتورة المطلوبة.');
}

// ========== جلب تفاصيل الأصناف ==========
$it = $db->prepare("
  SELECT 
    itms.name AS name,
    si.qty    AS quantity,
    si.unit_price,
    (si.qty * si.unit_price) AS line_total
  FROM sales_items si
  JOIN items itms ON itms.id = si.item_id
  WHERE si.invoice_id = ?
  ORDER BY si.id
");
$it->execute([$invoice_id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

// ========== دوال مساعدة ==========
function nf($n){ return number_format((float)$n, 2); }

?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>إيصال #<?php echo e($invoice['invoice_number']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
/* ====== إعدادات الإيصال (80mm) ====== */
:root{
  --receipt-width: 80mm;   /* لو عايز 58mm اضغط الزرار تحت */
  --font-size: 12px;       /* قلل/كبّر حسب رغبتك */
  --line-height: 1.35;
}

*{ box-sizing:border-box; }
html, body{ margin:0; padding:0; }
body{
  width: var(--receipt-width);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace;
  font-size: var(--font-size);
  line-height: var(--line-height);
  color:#000;
  background:#fff;
}

@page{
  size: var(--receipt-width) auto; /* عرض ثابت وطول تلقائي */
  margin: 0; /* بدون هوامش */
}

.wrapper{ padding:8px 8px 12px; }
.center{ text-align:center; }
.right{ text-align:right; }
.left{ text-align:left; }
.bold{ font-weight:700; }
.small{ font-size: 11px; }
.hr{ border-top:1px dashed #000; margin:6px 0; }

/* رأس الإيصال */
.header .title{ font-size:14px; font-weight:700; }
.header .meta{ margin-top:4px; }

/* جدول الأصناف */
.items{ width:100%; border-collapse:collapse; margin-top:6px; table-layout:fixed; }
.items th, .items td{ padding:2px 0; word-wrap:break-word; }
.items th{ text-align:right; border-bottom:1px dashed #000; }
.items td.qty,
.items td.price,
.items td.total{ white-space:nowrap; }
.items td.name{ width:100%; }

/* الإجماليات والمدفوعات */
.totals{ width:100%; margin-top:6px; }
.totals .row{ display:flex; justify-content:space-between; margin:2px 0; }

/* أسفل الإيصال */
.footer{ margin-top:8px; }
.cut-line{ text-align:center; margin-top:8px; }
.cut-line::before{ content:"-------------------------------"; }

/* عناصر لا تُطبع */
.no-print{ display:inline-flex; gap:6px; margin:8px; }
@media print{
  .no-print{ display:none !important; }
}
</style>
</head>
<body onload="autoPrint()">
<div class="wrapper">

  <!-- الهيدر -->
  <div class="header center">
    <div class="title">العزباوية</div>
    <div class="small">شارع ...، القاهرة — 01000000000</div>
    <div class="hr"></div>

    <div class="meta right">
      <div>رقم: <span class="bold"><?php echo e($invoice['invoice_number']); ?></span></div>
      <div>التاريخ: <?php echo e(date('Y-m-d H:i', strtotime($invoice['created_at']))); ?></div>
      <div>الكاشير: <?php echo e($invoice['cashier'] ?? ''); ?></div>
      <?php if(!empty($invoice['customer']) || !empty($invoice['customer_phone'])): ?>
  <div>
    العميل:
    <?php
      $name  = trim((string)($invoice['customer'] ?? ''));
      $phone = trim((string)($invoice['customer_phone'] ?? ''));
      echo e($name . ($phone !== '' ? ' — ' . $phone : ''));
    ?>
  </div>
<?php endif; ?>

    </div>

    <div class="hr"></div>
  </div>

  <!-- الأصناف -->
  <table class="items">
    <thead>
      <tr>
        <th>الصنف</th>
        <th class="qty">الكمية</th>
        <th class="price">السعر</th>
        <th class="total">الإجمالي</th>
      </tr>
    </thead>
    <tbody>
      <?php if($items): ?>
        <?php foreach($items as $row): ?>
          <tr>
            <td class="name"><?php echo e($row['name']); ?></td>
            <td class="qty"><?php echo e((int)$row['quantity']); ?></td>
            <td class="price"><?php echo nf($row['unit_price']); ?></td>
            <td class="total"><?php echo nf($row['line_total']); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="4" class="center small">لا توجد تفاصيل أصناف لهذه الفاتورة</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="hr"></div>

  <!-- الإجماليات -->
  <div class="totals">
    <div class="row"><span>الإجمالي قبل الخصم</span><span><?php echo nf($invoice['subtotal'] ?? 0); ?></span></div>
    <?php if(!empty($invoice['discount']) && (float)$invoice['discount']>0): ?>
      <div class="row"><span>خصم</span><span>-<?php echo nf($invoice['discount']); ?></span></div>
    <?php endif; ?>
    <?php if(!empty($invoice['tax']) && (float)$invoice['tax']>0): ?>
      <div class="row"><span>ضريبة</span><span><?php echo nf($invoice['tax']); ?></span></div>
    <?php endif; ?>
    <div class="row bold"><span>الإجمالي المستحق</span><span><?php echo nf($invoice['total'] ?? 0); ?></span></div>
  </div>

  <div class="hr"></div>

  <!-- المدفوعات -->
  <div class="totals">
    <?php if(isset($invoice['pay_cash'])): ?>
      <div class="row"><span>نقدًا</span><span><?php echo nf($invoice['pay_cash']); ?></span></div>
    <?php endif; ?>
    <?php if(isset($invoice['pay_card'])): ?>
      <div class="row"><span>بطاقة</span><span><?php echo nf($invoice['pay_card']); ?></span></div>
    <?php endif; ?>
    <?php if(isset($invoice['pay_instapay'])): ?>
      <div class="row"><span>InstaPay</span><span><?php echo nf($invoice['pay_instapay']); ?></span></div>
    <?php endif; ?>
    <?php if(isset($invoice['change_amount'])): ?>
      <div class="row"><span>الباقي</span><span><?php echo nf($invoice['change_amount']); ?></span></div>
    <?php endif; ?>
  </div>

  <div class="footer center">
    <div class="hr"></div>
    <div>شكراً لتسوقكم من العزباوية</div>
    <div class="small">استبدال خلال 14 يوم مع الفاتورة</div>
    <div class="cut-line"></div>
  </div>

</div>

<!-- أدوات سريعة من المتصفح -->
<div class="no-print center">
  <button onclick="window.print()">طباعة</button>
  <button onclick="setWidth('58mm')">58mm</button>
  <button onclick="setWidth('80mm')">80mm</button>
  <label class="small" style="display:inline-flex;align-items:center;gap:4px">
    <input type="checkbox" id="closeAfterPrint" checked> إغلاق بعد الطباعة
  </label>
</div>

<script>
function setWidth(w){
  document.documentElement.style.setProperty('--receipt-width', w);
  setTimeout(()=>{}, 30);
}
function autoPrint(){
  // اطبع تلقائيًا عند الفتح
  window.print();
  // أغلق التبويب بعد الطباعة لو الخيار مفعّل
  const closeIt = document.getElementById('closeAfterPrint');
  if (closeIt && closeIt.checked) {
    // بعض المتصفحات تحتاج مهلة صغيرة
    setTimeout(()=>{ window.close(); }, 400);
  }
}
</script>

</body>
</html>
