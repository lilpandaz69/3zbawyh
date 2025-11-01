<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier']);


if (!isset($_SESSION['pos_flow']) || empty($_SESSION['pos_flow']['category_id'])) {
  header('Location: /3zbawyh/public/select_category.php'); exit;
}
if (empty($_SESSION['pos_flow']['subcategory_id'])) {
  header('Location: /3zbawyh/public/select_subcategory.php'); exit;
}
$category_id    = (int)$_SESSION['pos_flow']['category_id'];
$subcategory_id = (int)$_SESSION['pos_flow']['subcategory_id'];
$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>POS — الأصناف</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{
  --bg1:#f6f7fb; --bg2:#eef3ff; --card:#fff; --ink:#111; --muted:#667;
  --pri:#2261ee; --pri-ink:#fff; --bd:#e8e8ef; --badge:#eef2f8; --accent:#0b4ea9;
  --ok:#137333;
}
*{box-sizing:border-box}
body{
  margin:0;
  background:radial-gradient(1200px 600px at 50% -200px,var(--bg2),var(--bg1));
  color:var(--ink);
  font-family:system-ui,-apple-system,Segoe UI,Roboto;
}
a{color:var(--pri)}
.nav{
  position:sticky; top:0; z-index:10;
  display:flex;justify-content:space-between;align-items:center;
  padding:14px 18px;background:linear-gradient(#ffffffdd,#ffffffcc);
  backdrop-filter: blur(6px); border-bottom:1px solid var(--bd);
}
.center{min-height:calc(100vh - 64px);display:grid;place-items:start center;padding:16px}
.box{
  width:min(1100px,96vw); background:var(--card); border:1px solid var(--bd);
  border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.06); padding:16px
}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.input{
  border:1px solid var(--bd);border-radius:12px;padding:10px 12px;background:#fff;min-width:220px
}
.btn{
  border:0;background:var(--pri);color:var(--pri-ink);
  padding:10px 14px;border-radius:12px;cursor:pointer;transition:.15s;box-shadow:0 2px 8px rgba(34,97,238,.18)
}
.btn:hover{transform:translateY(-1px)}
.btn.secondary{background:#eef3fb;color:var(--accent)}
.badge{font-size:11px;padding:2px 8px;border-radius:999px;background:var(--badge);color:#345}
.pill{display:inline-block;background:#0b4ea914;border:1px solid #cfe2ff;color:#0b4ea9;padding:4px 10px;border-radius:999px;font-weight:600}

.list{
  margin-top:10px; display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px
}
.card{
  border:1px solid var(--bd); border-radius:14px; padding:12px; background:#fff;
  transition:.15s; box-shadow:0 2px 10px rgba(0,0,0,.04)
}
.card:hover{transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.08)}
.card .name{font-weight:700; margin-bottom:6px}
.card .meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.card .actions{display:flex;justify-content:space-between;align-items:center;gap:10px}
.add-btn{
  display:inline-flex;align-items:center;gap:6px
}
.add-btn .plus{
  width:22px;height:22px;display:grid;place-items:center;border-radius:8px;background:#edf2ff;color:#1b4ed8;
  font-weight:900;transition:transform .15s
}
.add-btn:active .plus{transform:scale(0.92)}
.stock-low{color:#b3261e;font-weight:700}

/* Floating cart button */
.fab{
  position:fixed; inset-inline-end:20px; inset-block-end:20px; z-index:12;
  display:flex;align-items:center;gap:10px;
  background:var(--pri); color:#fff; border-radius:999px; padding:10px 14px;
  box-shadow:0 10px 24px rgba(34,97,238,.25); text-decoration:none
}
.fab b{background:#ffffff22;border:1px solid #ffffff55;padding:2px 10px;border-radius:999px}

/* Toast */
#toasts{
  position:fixed; inset-block-start:14px; inset-inline-end:14px; z-index:9999;
  display:flex; flex-direction:column; gap:8px; pointer-events:none;
}
.toast{
  pointer-events:auto;
  min-width:240px; max-width:360px;
  background:#111; color:#fff; border-radius:12px; padding:10px 12px;
  box-shadow:0 10px 28px rgba(0,0,0,.25); display:flex; gap:10px; align-items:flex-start;
  animation:slideIn .18s ease-out
}
.toast.ok{background:#174e2f}
.toast .t-title{font-weight:700; margin-bottom:2px}
.toast .t-meta{font-size:12px; opacity:.85}
.toast .t-close{margin-inline-start:auto; cursor:pointer; opacity:.8}
@keyframes slideIn{from{transform:translateY(-6px); opacity:0} to{transform:translateY(0); opacity:1}}
</style>
</head>
<body>
<nav class="nav">
  <div><strong>POS — صفحة الأصناف</strong></div>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="pill">التصنيف: <span id="catName">#<?=$category_id?></span></span>
    <span class="pill">الفرعي: <span id="subName">#<?=$subcategory_id?></span></span>
    <a class="btn secondary" href="/3zbawyh/public/select_subcategory.php">← الفرعي</a>
    <a class="btn" href="/3zbawyh/public/cart_checkout.php" id="cartBtnTop">الكارت (0)</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>
</nav>

<div id="toasts"></div>

<div class="center">
  <div class="box">
    <div class="row" style="justify-content:space-between">
      <div style="display:flex;gap:8px;align-items:center">
        <input id="q" class="input" placeholder="ابحث باسم الصنف (Enter)">
        <button class="btn" id="btnSearch">بحث</button>
      </div>
      <div class="pill" id="countHint">— النتائج: 0 عنصر</div>
    </div>

    <div id="itemsGrid" class="list"></div>
  </div>
</div>

<!-- زر كارت عائم -->
<a class="fab" href="/3zbawyh/public/cart_checkout.php" id="cartFab">
  <span>اذهب للكارت</span>
  <b id="cartCount">0</b>
</a>

<script>
const cid = <?=$category_id?>, sid = <?=$subcategory_id?>;
const el = s=>document.querySelector(s);
const fmt = n=>{ n=parseFloat(n||0); return isNaN(n)?'0.00':n.toFixed(2); };

/* API helpers */
function api(a,p={}){const q=new URLSearchParams(p).toString();return fetch('/3zbawyh/public/pos_api.php?'+(q? q+'&':'')+'action='+a).then(r=>r.json());}
function postForm(a, body={}) {
  return fetch('/3zbawyh/public/pos_api.php?action='+a, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams(body)
  }).then(r=>r.json());
}

/* Toast */
function toast({title='تم', msg='', ok=false, timeout=2200}={}){
  const wrap = el('#toasts');
  const t = document.createElement('div');
  t.className = 'toast' + (ok?' ok':'');
  t.innerHTML = `
    <div>
      <div class="t-title">${title}</div>
      <div class="t-meta">${msg}</div>
    </div>
    <div class="t-close">✖</div>
  `;
  t.querySelector('.t-close').onclick = ()=> t.remove();
  wrap.appendChild(t);
  setTimeout(()=>{ t.style.opacity='.0'; t.style.transform='translateY(-6px)'; setTimeout(()=>t.remove(), 180); }, timeout);
}

/* Breadcrumb names */
api('search_categories').then(r=>{ if(r.ok){ const c=(r.categories||[]).find(x=>+x.id===cid); if(c) el('#catName').textContent=c.name; }});
api('search_subcategories',{category_id:cid}).then(r=>{ if(r.ok){ const s=(r.subcategories||[]).find(x=>+x.id===sid); if(s) el('#subName').textContent=s.name; }});

/* Load + render items */
function renderItems(items){
  const grid = el('#itemsGrid'); grid.innerHTML='';
  el('#countHint').textContent = '— النتائج: ' + (items?.length || 0) + ' عنصر';
  if (!items || !items.length){
    grid.innerHTML = `<div style="grid-column:1/-1;padding:16px;color:#666">لا توجد نتائج ضمن هذا الفرعي.</div>`;
    return;
  }
  items.forEach(it=>{
    const stock = (it.stock==null || it.stock==='') ? '-' : it.stock;
    const low = (stock!== '-' && parseFloat(stock)<=0);
    const d = document.createElement('div');
    d.className='card';
    d.innerHTML = `
      <div class="name">${it.name}</div>
      <div class="meta">
        <span class="badge">سعر: ${fmt(it.unit_price)} ج.م</span>
        <span class="badge ${low?'stock-low':''}">مخزون: ${stock}</span>
      </div>
      <div class="actions">
        <small class="muted">#${it.id}</small>
        <button class="btn add-btn" data-id="${it.id}">
          <span class="plus">＋</span> أضِف
        </button>
      </div>
    `;
    grid.appendChild(d);
  });

  grid.querySelectorAll('.add-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = +btn.dataset.id;
      postForm('cart_add', {item_id:id, qty:1}).then(res=>{
        if(!res.ok){ alert(res.error||'خطأ'); return; }
        refreshCartCount();
        // Toast نجاح
        toast({ok:true, title:'تمت الإضافة', msg:`تم إضافة الصنف (#${id}) إلى العربة.`});
        // نبضة بصرية صغيرة على الزر
        btn.style.transform='scale(0.96)';
        setTimeout(()=>{ btn.style.transform=''; },120);
      });
    });
  });
}
function searchItems(){
  const q = el('#q').value.trim();
  api('search_items',{q, category_id: cid, subcategory_id: sid}).then(r=>{
    if(!r.ok){ alert(r.error||'خطأ'); return; }
    renderItems(r.items||[]);
  });
}

/* Cart count */
function refreshCartCount(){
  api('cart_get').then(r=>{
    if(!r.ok) return;
    const c = (r.cart||[]).length;
    const top = el('#cartBtnTop');
    const fab = el('#cartCount');
    if (top) top.textContent = `الكارت (${c})`;
    if (fab) fab.textContent = c;
  });
}

/* Events */
el('#btnSearch').onclick = searchItems;
el('#q').addEventListener('keydown', e=>{ if(e.key==='Enter') searchItems(); });

/* Init */
searchItems();
refreshCartCount();
</script>
</body>
</html>
