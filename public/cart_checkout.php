
<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier']);
$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>POS — Cart Checkout</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{--bg:#f6f7fb;--card:#fff;--bd:#e8e8ef;--ink:#111;--pri:#2261ee;--pri-ink:#fff;--ok:#137333;--danger:#b3261e}
*{box-sizing:border-box} body{margin:0;background:radial-gradient(1200px 600px at 50% -200px,#eef3ff,#f6f7fb);font-family:system-ui}
.nav{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:#fff;border-bottom:1px solid var(--bd)}
.center{min-height:calc(100vh - 60px);display:grid;place-items:center;padding:16px}
.box{width:min(1100px,96vw);background:var(--card);border:1px solid var(--bd);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:16px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.list{max-height:420px;overflow:auto;border:1px solid var(--bd);border-radius:10px;margin-top:8px}
.line{display:grid;grid-template-columns:2fr 90px 120px 120px 36px;gap:8px;align-items:center;padding:8px 10px;border-bottom:1px solid var(--bd)}
.btn{border:0;background:var(--pri);color:var(--pri-ink);padding:10px 14px;border-radius:12px;cursor:pointer}
.btn.ok{background:var(--ok)} .btn.danger{background:var(--danger)} .btn.secondary{background:#eef3fb;color:#0b4ea9}
.input{border:1px solid var(--bd);border-radius:10px;padding:9px 10px;background:#fff}
.pill{display:inline-block;background:#0b4ea914;border:1px solid #cfe2ff;color:#0b4ea9;padding:4px 10px;border-radius:999px;font-weight:600}
.warn{color:var(--danger);font-weight:700}
.right{text-align:right}
</style>
</head>
<body>
<nav class="nav">
  <div><strong>POS — Cart Checkout</strong></div>
  <div style="display:flex;gap:10px">
    <a class="btn secondary" href="/3zbawyh/public/select_category.php">+ أضف أصناف</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>
</nav>

<div class="center">
  <div class="box">
    <h3 style="margin:0">العربة</h3>
    
    <div id="list" class="list"></div>
<h4>بيانات العميل</h4>
<div class="row" style="margin:6px 0 14px">
  <input id="cust_name" class="input" style="width:260px" placeholder="اسم العميل (اختياري)">
  <input id="cust_phone" class="input" style="width:220px" placeholder="رقم الموبايل (اختياري)">
</div>

    <div class="row" style="margin-top:10px">
      خصم: <input id="discount" class="input" style="width:120px" value="0">
      ضريبة: <input id="tax" class="input" style="width:120px" value="0">
      <span class="pill">الإجمالي: <span id="grand">0.00</span> ج.م</span>
      <!-- زر التفريغ تمت إزالته -->
    </div>

    <hr>

    <h4>طرق الدفع</h4>
    <div id="payWarn" class="warn" style="display:none;margin-bottom:6px"></div>
    <div id="paymentsArea"></div>
    <div class="row" style="margin-top:6px">
      <button class="btn secondary" id="addPay">+ إضافة دفع</button>
      <span class="pill">المدفوع: <span id="paidSum">0.00</span> ج.م</span>
      <!-- سيتم حقن Pill للباقي تلقائياً -->
    </div>

    <div class="row" style="justify-content:flex-end;margin-top:12px">
      <button class="btn ok" id="finish">حفظ + طباعة</button>
    </div>
  </div>
</div>

<script>
const el = s=>document.querySelector(s);
const fmt = n=>{ n=parseFloat(n||0); return isNaN(n)?'0.00':n.toFixed(2); };

function api(action, params={}){
  const q = new URLSearchParams(params).toString();
  return fetch('/3zbawyh/public/pos_api.php?' + (q? q+'&':'') + 'action=' + action).then(r=>r.json());
}
function apiPost(action, body={}){
  return fetch('/3zbawyh/public/pos_api.php?action='+action, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(body)
  }).then(r=>r.json());
}

let CART = [];
function loadCart(){
  api('cart_get').then(r=>{
    if(!r.ok){ alert(r.error||'خطأ'); return; }
    CART = r.cart||[];
    renderCart();
    refreshTotals();
  });
}
function renderCart(){
  const box = el('#list'); box.innerHTML='';
  if (!CART.length){
    box.innerHTML='<div style="padding:16px;color:#666">العربة فارغة — أضف أصناف من صفحة الأصناف.</div>';
    return;
  }
  CART.forEach(l=>{
    const d = document.createElement('div');
    d.className='line';
    const stock = (l.stock==null ? '-' : l.stock);
    d.innerHTML = `
      <div><strong>${l.name}</strong><div style="color:#666;font-size:12px">مخزون: ${stock}</div></div>
      <div><input class=\"input qty\" style=\"width:100%\" value=\"${l.qty}\"></div>
      <div><input class=\"input price\" style=\"width:100%\" value=\"${l.unit_price}\"></div>
      <div class=\"right\">${fmt(l.qty*l.unit_price)}</div>
      <div class=\"right\"><button class=\"btn danger rm\">✖</button></div>
    `;
    d.querySelector('.qty').addEventListener('input', e=>{
      let q = Math.max(0, parseFloat(e.target.value||0));
      api('cart_update', {item_id:l.item_id, qty:q}).then(loadCart);
    });
    d.querySelector('.price').addEventListener('input', e=>{
      const p = Math.max(0, parseFloat(e.target.value||0));
      api('cart_update', {item_id:l.item_id, unit_price:p}).then(loadCart);
    });
    d.querySelector('.rm').onclick = ()=> api('cart_update', {item_id:l.item_id, remove:1}).then(loadCart);
    box.appendChild(d);
  });
}
function refreshTotals(){
  let subtotal=0;
  CART.forEach(l=> subtotal += (+l.qty)*(+l.unit_price) );
  const disc = +el('#discount').value||0;
  const tx = +el('#tax').value||0;
  el('#grand').textContent = fmt(subtotal - disc + tx);
  sumPayments();
}
el('#discount').addEventListener('input', refreshTotals);
el('#tax').addEventListener('input', refreshTotals);

/* ========= Payments: حر بالكامل + مرجع إجباري لـ InstaPay + Change من الكاش فقط ========= */
function paymentRow(method='cash', amount='', ref=''){
  const wrap = document.createElement('div');
  wrap.className='row payrow';
  wrap.innerHTML = `
    <select class=\"input method\">
      <option value=\"cash\">نقدي (Cash)</option>
      <option value=\"visa\">Visa / بطاقة</option>
      <option value=\"instapay\">InstaPay</option>
    </select>
    <input class=\"input amount\" style=\"width:140px\" placeholder=\"المبلغ\">
    <input class=\"input ref\" style=\"width:220px\" placeholder=\"رقم العملية / المرجع (InstaPay)\">
    <button class=\"btn danger remove\">حذف</button>
  `;
  wrap.querySelector('.method').value = method;
  wrap.querySelector('.amount').value = amount;
  wrap.querySelector('.ref').value = ref;

  function tuneRow() {
    const m = wrap.querySelector('.method').value;
    const amountEl = wrap.querySelector('.amount');
    const refEl = wrap.querySelector('.ref');

    amountEl.readOnly = false;
    refEl.disabled = true; refEl.style.opacity = .6;
    if (m !== 'instapay') refEl.value='';

    if (m === 'cash') {
      refEl.disabled = true; refEl.style.opacity = .4;
    } else if (m === 'visa') {
      refEl.disabled = true; refEl.style.opacity = .4;
    } else if (m === 'instapay') {
      refEl.disabled = false; refEl.style.opacity = 1;
      if (!refEl.value) refEl.placeholder='أدخل رقم العملية (إجباري)';
    }
  }

  wrap.querySelector('.method').addEventListener('change', ()=>{ tuneRow(); sumPayments(); });
  wrap.querySelector('.amount').addEventListener('input', sumPayments);
  wrap.querySelector('.ref').addEventListener('input', sumPayments);
  wrap.querySelector('.remove').onclick = ()=>{ wrap.remove(); sumPayments(); };

  tuneRow();
  return wrap;
}

function addPayment(method='cash', amount='', ref=''){
  el('#paymentsArea').appendChild(paymentRow(method, amount, ref));
  sumPayments();
}

function getPaymentsFromUI(){
  const rows = Array.from(document.querySelectorAll('#paymentsArea .payrow'));
  return rows.map(r=>({
    method: r.querySelector('.method').value,
    amount: +r.querySelector('.amount').value || 0,
    ref_no: (r.querySelector('.ref').value||'').trim()
  })).filter(p => p.amount > 0);
}

function sumPayments(){
  const total = parseFloat(document.querySelector('#grand').textContent||'0') || 0;
  const pays = getPaymentsFromUI();

  // مرجع إنستا باي إجباري لو موجود
  const badInsta = pays.find(p=>p.method==='instapay' && !p.ref_no);
  if (badInsta) { showPayError('من فضلك أدخل رقم العملية/المرجع لمدفوعات InstaPay.'); updatePaidSum(pays, total); return; }

  const sum = pays.reduce((a,p)=> a + (+p.amount||0), 0);

  // حساب الباقي (Change) من الكاش فقط
  const cashSum = pays.filter(p=>p.method==='cash').reduce((a,p)=>a+(+p.amount||0),0);
  const nonCash = sum - cashSum;
  const remainingAfterNonCash = total - nonCash;
  const changeDue = Math.max(0, cashSum - Math.max(0, remainingAfterNonCash));

  // الزيادة مسموحة فقط = قيمة الباقي من الكاش
  const overpay = sum - total;
  const overpayAllowed = Math.abs(overpay - changeDue) < 0.01;

  if (Math.abs(sum - total) > 0.009 && !overpayAllowed) {
    showPayError(`المدفوع (${sum.toFixed(2)}) لا يساوي الإجمالي (${total.toFixed(2)}). الزيادة مسموحة فقط لو من \"كاش\" وتتحول لباقي.`);
  } else {
    clearPayError();
  }

  updatePaidSum(pays, total, changeDue);
}

function updatePaidSum(pays, total, changeDue=0){
  const sum = pays.reduce((a,p)=> a + (+p.amount||0), 0);
  el('#paidSum').textContent = (sum||0).toFixed(2);

  let pill = document.querySelector('#changePill');
  if (!pill) {
    pill = document.createElement('span');
    pill.id = 'changePill';
    pill.className = 'pill';
    document.querySelector('#paymentsArea').parentElement.querySelector('.row').appendChild(pill);
  }
  pill.textContent = `الباقي (كاش): ${ (changeDue||0).toFixed(2) } ج.م`;
}

function showPayError(msg){ const w=el('#payWarn'); w.style.display='block'; w.textContent=msg; }
function clearPayError(){ const w=el('#payWarn'); w.style.display='none'; w.textContent=''; }

/* Finish */
el('#finish').onclick = ()=>{
  if (!CART.length) return alert('العربة فارغة');

  const total = parseFloat(el('#grand').textContent||'0') || 0;
  const pays  = getPaymentsFromUI();
  if (!pays.length) return alert('أضف طريقة دفع واحدة على الأقل.');

  // التحقق من إنستا باي (مرجع)
  for (const p of pays) {
    if (p.method==='instapay' && !p.ref_no) {
      return alert('من فضلك أدخل رقم العملية لـ InstaPay.');
    }
  }

  // التحقق من التوازن والسماح بالباقي من الكاش
  const sum = pays.reduce((a,p)=> a + (+p.amount||0), 0);
  const cashSum = pays.filter(p=>p.method==='cash').reduce((a,p)=>a+(+p.amount||0),0);
  const nonCash = sum - cashSum;
  const remainingAfterNonCash = total - nonCash;
  const changeDue = Math.max(0, cashSum - Math.max(0, remainingAfterNonCash));
  const overpay = sum - total;
  const overpayAllowed = Math.abs(overpay - changeDue) < 0.01;
  if (Math.abs(sum - total) > 0.009 && !overpayAllowed) {
    return alert('مجموع المدفوعات لا يساوي الإجمالي (الزيادة مسموحة فقط لو كاش وتتحول لباقي).');
  }

  apiPost('cart_checkout_multi_legacy', {
  discount: +el('#discount').value||0,
  tax: +el('#tax').value||0,
  payments: pays,
  customer_name: (el('#cust_name').value||'').trim(),
  customer_phone: (el('#cust_phone').value||'').trim()
}).then(r=>{
    if(!r.ok){ alert(r.error||'فشل الحفظ'); return; }
    if (r.print_url) window.open(r.print_url,'_blank');
    alert('تم حفظ الفاتورة: ' + (r.invoice?.invoice_no || ''));
    loadCart();

    // إعادة الضبط
    el('#paymentsArea').innerHTML=''; addPayment('cash','', '');
    el('#discount').value='0'; el('#tax').value='0'; refreshTotals();
  });
};

el('#addPay').onclick = ()=> addPayment('cash','', '');
addPayment('cash','', '');
loadCart();
</script>
</body>
</html>