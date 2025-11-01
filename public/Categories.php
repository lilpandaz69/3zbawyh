<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin']);

$db = db();

/* حرس إعادة التعريف (لو الدوال موجودة في helpers خلاص مش هتتعرّف تاني) */
if (!function_exists('table_exists')) {
    function table_exists(PDO $db, $table){
        $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }
}
if (!function_exists('column_exists')) {
    function column_exists(PDO $db, $table, $col){
        $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$table,$col]);
        return (bool)$st->fetchColumn();
    }
}

/* حالة الجداول/الأعمدة */
$hasCategories = table_exists($db,'categories');
$hasDesc   = $hasCategories ? column_exists($db,'categories','description') : false;
$hasActive = $hasCategories ? column_exists($db,'categories','is_active')   : false;

$hasSubcats = table_exists($db,'subcategories');

$msg=null; $err=null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* SQL إنشاء جدول التصنيفات لو مش موجود */
if (!$hasCategories) {
  $createSQL = "CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
}

/* المعالجات */
try {
  /* CRUD التصنيفات */
  if ($hasCategories) {
    if ($action==='create') {
      $fields = ['name'];
      $vals   = [trim($_POST['name'] ?? '')];

      if ($hasDesc)   { $fields[]='description'; $vals[] = ($_POST['description'] ?? null); }
      if ($hasActive) { $fields[]='is_active';   $vals[] = isset($_POST['is_active']) ? 1 : 0; }

      if ($vals[0] === '') throw new Exception('الاسم مطلوب');

      $placeholders = implode(',', array_fill(0, count($vals), '?'));
      $sql = "INSERT INTO categories (".implode(',', $fields).") VALUES ($placeholders)";
      $db->prepare($sql)->execute($vals);
      $msg='تمت الإضافة.';
    }
    elseif ($action==='update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if (!$id || $name==='') throw new Exception('بيانات غير مكتملة');

      $sets = ['name=?']; $vals=[$name];
      if ($hasDesc)   { $sets[]='description=?'; $vals[] = ($_POST['description'] ?? null); }
      if ($hasActive) { $sets[]='is_active=?';   $vals[] = isset($_POST['is_active']) ? 1 : 0; }

      $vals[] = $id;
      $sql = "UPDATE categories SET ".implode(', ', $sets)." WHERE id=?";
      $db->prepare($sql)->execute($vals);
      $msg='تم التحديث.';
    }
    elseif ($action==='delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id) {
        // فضِ ارتباط items لو موجود
        if (table_exists($db,'items')) {
          $db->prepare("UPDATE items SET category_id=NULL WHERE category_id=?")->execute([$id]);
        }
        // هيتم حذف الفرعيات تلقائيًا لو FK ON DELETE CASCADE، وإلا نحذف يدويًا
        if ($hasSubcats) {
          $db->prepare("DELETE FROM subcategories WHERE category_id=?")->execute([$id]);
        }
        $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
        $msg='تم الحذف.';
      }
    }
  }

  /* CRUD التصنيفات الفرعية (مطابق لسكيمتك الحالية) */
  if ($hasSubcats) {
    if ($action === 'sub_create') {
      $cid    = (int)($_POST['category_id'] ?? 0);
      $name   = trim($_POST['name'] ?? '');
      $active = isset($_POST['is_active']) ? 1 : 0;

      if ($cid && $name!=='') {
        $db->prepare("INSERT INTO subcategories (category_id, name, is_active) VALUES (?,?,?)")
           ->execute([$cid, $name, $active]);
        $msg='تمت إضافة التصنيف الفرعي.';
      } else {
        throw new Exception('اسم الفرعي مطلوب');
      }
    }
    elseif ($action === 'sub_update') {
      $id     = (int)($_POST['id'] ?? 0);
      $name   = trim($_POST['name'] ?? '');
      $active = isset($_POST['is_active']) ? 1 : 0;

      if ($id && $name!=='') {
        $db->prepare("UPDATE subcategories SET name=?, is_active=? WHERE id=?")
           ->execute([$name, $active, $id]);
        $msg='تم تحديث التصنيف الفرعي.';
      } else {
        throw new Exception('بيانات الفرعي غير مكتملة');
      }
    }
    elseif ($action === 'sub_delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id) {
        $db->prepare("DELETE FROM subcategories WHERE id=?")->execute([$id]);
        $msg='تم حذف التصنيف الفرعي.';
      }
    }
  }
} catch(Throwable $e){ $err=$e->getMessage(); }

/* قراءة القوائم */
$q = trim($_GET['q'] ?? '');
$list = [];
if ($hasCategories) {
  $st = $db->prepare("SELECT * FROM categories WHERE (?='' OR name LIKE ?) ORDER BY name");
  $like = "%$q%";
  $st->execute([$q,$like]);
  $list = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* لو في تعديل تصنيف، هات فرعياته */
$editing=null; $subs=[]; $subq = trim($_GET['sq'] ?? '');
if ($hasCategories && isset($_GET['edit'])) {
  $st=$db->prepare("SELECT * FROM categories WHERE id=?");
  $st->execute([(int)$_GET['edit']]);
  $editing=$st->fetch(PDO::FETCH_ASSOC);

  if ($editing && $hasSubcats) {
    if ($subq!=='') {
      $like = "%$subq%";
      $st = $db->prepare("SELECT id, name, is_active FROM subcategories WHERE category_id=? AND name LIKE ? ORDER BY name");
      $st->execute([(int)$editing['id'], $like]);
    } else {
      $st = $db->prepare("SELECT id, name, is_active FROM subcategories WHERE category_id=? ORDER BY name");
      $st->execute([(int)$editing['id']]);
    }
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><title>التصنيفات</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
  body{font-family:system-ui}
  .container{max-width:1000px;margin:20px auto}
  .card{background:#fff;border:1px solid #eee;border-radius:12px;padding:12px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .muted{color:#666}
  .card h3{margin-top:0}
  details summary.btn{display:inline-block;cursor:pointer}
  details[open] summary.btn{opacity:.85}
</style>
</head><body>
<div class="container">
  <h2>التصنيفات</h2>

  <?php if(!$hasCategories): ?>
    <div class="card" style="background:#fff7ed">
      <strong>جدول التصنيفات غير موجود.</strong>
      <p>انسخ وشغّل الـSQL ده مرّة واحدة:</p>
      <pre style="white-space:pre-wrap;direction:ltr"><?= $createSQL ?></pre>
    </div>
  <?php endif; ?>

  <?php if($msg): ?><div class="card" style="background:#ecfdf5"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="background:#fef2f2">خطأ: <?= e($err) ?></div><?php endif; ?>

  <form method="get" class="card" style="display:flex;gap:8px;align-items:center">
    <input name="q" value="<?= e($q) ?>" placeholder="بحث بالاسم" class="input" style="flex:1">
    <button class="btn">بحث</button>
  </form>

  <div class="card">
    <h3>إضافة/تعديل تصنيف</h3>
    <form method="post" class="grid2">
      <?php $isEdit = (bool)$editing; ?>
      <input type="hidden" name="action" value="<?= $isEdit? 'update':'create' ?>">
      <?php if($isEdit): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

      <label>الاسم
        <input class="input" name="name" required value="<?= e($editing['name'] ?? '') ?>">
      </label>

      <?php if ($hasDesc): ?>
      <label>وصف
        <input class="input" name="description" value="<?= e($editing['description'] ?? '') ?>">
      </label>
      <?php else: ?>
      <div></div>
      <?php endif; ?>

      <?php if ($hasActive):
        $activeVal = $editing ? (int)($editing['is_active'] ?? 1) : 1; ?>
      <label style="align-self:end;display:flex;gap:6px;align-items:center">
        <input type="checkbox" name="is_active" <?= $activeVal? 'checked':'' ?>> مفعل
      </label>
      <?php endif; ?>

      <div style="align-self:end">
        <button class="btn" type="submit"><?= $isEdit? 'تحديث':'إضافة' ?></button>
        <?php if($isEdit): ?><a class="btn secondary" href="?">إلغاء</a><?php endif; ?>
      </div>
    </form>
  </div>

  <?php if($editing && $hasSubcats): ?>
    <div class="card" style="margin-top:12px">
      <h3>التصنيفات الفرعية لـ: <?= e($editing['name']) ?></h3>

      <!-- إضافة فرعي -->
      <form method="post" class="form-row" style="margin-bottom:10px; align-items:flex-end">
        <input type="hidden" name="action" value="sub_create">
        <input type="hidden" name="category_id" value="<?= (int)$editing['id'] ?>">
        <div style="flex:1">
          <label>الاسم
            <input class="input" name="name" required placeholder="اسم التصنيف الفرعي">
          </label>
        </div>
        <label style="display:flex; gap:6px; align-items:center">
          <input type="checkbox" name="is_active" checked> مفعل
        </label>
        <button class="btn" type="submit">إضافة فرعي</button>
      </form>

      <!-- بحث فرعيات -->
      <form method="get" class="form-row" style="margin-bottom:10px">
        <input type="hidden" name="edit" value="<?= (int)$editing['id'] ?>">
        <input class="input" name="sq" value="<?= e($subq) ?>" placeholder="بحث في الفرعيات">
        <button class="btn">بحث</button>
        <a class="btn secondary" href="?edit=<?= (int)$editing['id'] ?>">مسح البحث</a>
      </form>

      <!-- جدول الفرعيات -->
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>الاسم</th>
            <th>الحالة</th>
            <th>إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($subs as $s): ?>
            <tr>
              <td><?= (int)$s['id'] ?></td>
              <td><?= e($s['name']) ?></td>
              <td><?= ((int)$s['is_active']) ? 'مفعل' : 'متوقف' ?></td>
              <td>
                <!-- تعديل Inline -->
                <details>
                  <summary class="btn">تعديل</summary>
                  <form method="post" class="form-row" style="margin-top:8px; align-items:center">
                    <input type="hidden" name="action" value="sub_update">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <div style="flex:1">
                      <label>الاسم
                        <input class="input" name="name" required value="<?= e($s['name']) ?>">
                      </label>
                    </div>
                    <label style="display:flex; gap:6px; align-items:center">
                      <input type="checkbox" name="is_active" <?= ((int)$s['is_active'])?'checked':''; ?>> مفعل
                    </label>
                    <button class="btn" type="submit">حفظ</button>
                  </form>
                </details>

                <!-- حذف -->
                <form method="post" style="display:inline" onsubmit="return confirm('حذف الفرعي؟');">
                  <input type="hidden" name="action" value="sub_delete">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button class="btn secondary" type="submit">حذف</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($subs)): ?>
            <tr><td colspan="4" style="text-align:center" class="muted">لا توجد تصنيفات فرعية بعد.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <p class="muted">ملاحظة: الأعمدة المستخدمة للفرعيات: name, is_active فقط (حسب جدولك الحالي).</p>
    </div>
  <?php endif; ?>

  <?php if($hasCategories): ?>
  <div class="card" style="margin-top:12px">
    <h3>القائمة (<?= count($list) ?>)</h3>
    <table class="table" style="width:100%">
      <thead>
        <tr>
          <th>#</th><th>الاسم</th>
          <?php if ($hasDesc): ?><th>الوصف</th><?php endif; ?>
          <?php if ($hasActive): ?><th>الحالة</th><?php endif; ?>
          <th>إجراءات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($list as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= e($c['name']) ?></td>
            <?php if ($hasDesc): ?><td><?= e($c['description'] ?? '') ?></td><?php endif; ?>
            <?php if ($hasActive): ?><td><?= ((int)($c['is_active'] ?? 1)) ? 'مفعل' : 'متوقف' ?></td><?php endif; ?>
            <td>
              <a class="btn" href="?edit=<?= (int)$c['id'] ?>">تعديل</a>
              <form method="post" style="display:inline" onsubmit="return confirm('حذف؟');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn secondary" type="submit">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</body></html>
