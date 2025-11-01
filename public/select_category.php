<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier']);


if (isset($_GET['reset'])) {
  $_SESSION['pos_flow'] = ['category_id'=>null,'subcategory_id'=>null];
} elseif (!isset($_SESSION['pos_flow'])) {
  $_SESSION['pos_flow'] = ['category_id'=>null,'subcategory_id'=>null];
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $cid = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
  $_SESSION['pos_flow']['category_id'] = $cid ?: null;
  $_SESSION['pos_flow']['subcategory_id'] = null;
  header('Location: /3zbawyh/public/select_subcategory.php'); exit;
}
$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>POS — اختيار التصنيف</title>
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
.btn{border:0;background:var(--pri);color:var(--pri-ink);padding:10px 14px;border-radius:12px;cursor:pointer}
</style>
</head>
<body>
<nav class="nav">
  <div><strong>POS — صفحة 1: اختيار التصنيف</strong></div>
  <div style="display:flex;gap:10px">
    <a href="/3zbawyh/public/cart_checkout.php">الكارت / الدفع</a>
    <a href="/3zbawyh/public/select_category.php?reset=1">تصفير</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>
</nav>

<div class="center">
  <div class="box">
    <h3 style="margin:0 0 8px">اختر التصنيف</h3>
    <p style="margin:0;color:#666">اضغط على التصنيف للانتقال إلى صفحة الفرعي.</p>

    <form id="catForm" method="post" style="display:none">
      <input type="hidden" name="category_id" id="category_id">
    </form>

    <div id="cats" class="grid"></div>
  </div>
</div>

<script>
const box = document.getElementById('cats');
function api(action, params={}) {
  const q = new URLSearchParams(params).toString();
  return fetch('/3zbawyh/public/pos_api.php?' + (q? q+'&':'') + 'action=' + action).then(r=>r.json());
}
function renderCats(list){
  box.innerHTML='';
  list.forEach(c=>{
    const d = document.createElement('div');
    d.className='card';
    d.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center">
      <strong>${c.name}</strong><span class="badge">#${c.id}</span></div>`;
    d.onclick = ()=>{
      document.getElementById('category_id').value = c.id;
      document.getElementById('catForm').submit();
    };
    box.appendChild(d);
  });
}
api('search_categories').then(r=>{
  if(!r.ok){ alert(r.error||'خطأ'); return; }
  renderCats(r.categories||[]);
});
</script>
</body>
</html>
