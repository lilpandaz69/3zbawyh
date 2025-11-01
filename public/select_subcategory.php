<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier']);


if (!isset($_SESSION['pos_flow']) || empty($_SESSION['pos_flow']['category_id'])) {
  header('Location: /3zbawyh/public/select_category.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $sid = isset($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : 0;
  $_SESSION['pos_flow']['subcategory_id'] = $sid ?: null;
  header('Location: /3zbawyh/public/select_items.php'); exit;
}
$category_id = (int)$_SESSION['pos_flow']['category_id'];
$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>POS — اختيار الفرعي</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{--bg:#f6f7fb;--card:#fff;--bd:#e8e8ef;--pri:#2261ee;--pri-ink:#fff}
*{box-sizing:border-box} body{margin:0;background:radial-gradient(1200px 600px at 50% -200px,#eef3ff,#f6f7fb);font-family:system-ui}
.nav{display:flex;justify-content:space-between;align-items:center;padding:14px 18px}
.center{min-height:calc(100vh - 60px);display:grid;place-items:center;padding:16px}
.box{width:min(900px,94vw);background:var(--card);border:1px solid var(--bd);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-top:12px}
.card{border:1px solid var(--bd);border-radius:12px;padding:12px;cursor:pointer;transition:.15s}
.card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.06)}
.badge{font-size:11px;padding:2px 6px;border-radius:999px;background:#eef2f8;color:#345}
</style>
</head>
<body>
<nav class="nav">
  <div><strong>POS — صفحة 2: اختيار الفرعي</strong></div>
  <div style="display:flex;gap:10px">
    <a href="/3zbawyh/public/select_category.php">← التصنيف</a>
    <a href="/3zbawyh/public/cart_checkout.php">الكارت / الدفع</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>
</nav>

<div class="center">
  <div class="box">
    <div style="color:#555;margin-bottom:8px">التصنيف المختار: <span id="catName">#<?=$category_id?></span></div>
    <h3 style="margin:0 0 8px">اختر التصنيف الفرعي</h3>

    <form id="subForm" method="post" style="display:none">
      <input type="hidden" name="subcategory_id" id="subcategory_id">
    </form>

    <div id="subs" class="grid"></div>
  </div>
</div>

<script>
const cid = <?=$category_id?>;
const grid = document.getElementById('subs');
function api(a,p={}){const q=new URLSearchParams(p).toString();return fetch('/3zbawyh/public/pos_api.php?'+(q? q+'&':'')+'action='+a).then(r=>r.json());}
function clickSub(id){document.getElementById('subcategory_id').value=id;document.getElementById('subForm').submit();}
function render(list){
  grid.innerHTML=''; list.forEach(s=>{
    const d=document.createElement('div'); d.className='card';
    d.innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center">
      <strong>${s.name}</strong><span class="badge">#${s.id}</span></div>`;
    d.onclick=()=>clickSub(s.id); grid.appendChild(d);
  });
}
api('search_categories').then(r=>{ if(r.ok){ const c=(r.categories||[]).find(x=>+x.id===cid); if(c) document.getElementById('catName').textContent=c.name; } });
api('search_subcategories',{category_id:cid}).then(r=>{ if(!r.ok){alert(r.error||'خطأ');return;} render(r.subcategories||[]); });
</script>
</body>
</html>
