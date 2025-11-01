<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ok = login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($ok) {
        // توجيه حسب الدور
        if (is_cashier()) {
            header('Location: /3zbawyh/public/select_category.php');
        } else { // admin
            header('Location: /3zbawyh/public/dashboard.php');
        }
        exit;
    } else {
        $error = 'بيانات غير صحيحة';
    }
}
?>


<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>تسجيل الدخول - العزباوية</title>
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
</head>
<body>
<div class="container">
  <h2>تسجيل الدخول</h2>
  <?php if($error): ?>
    <div style="color:#b00;margin-bottom:10px"><?=e($error)?></div>
  <?php endif; ?>
  <form method="post">
    <div class="form-row">
      <input class="input" name="username" placeholder="اسم المستخدم" required>
      <input class="input" name="password" type="password" placeholder="كلمة السر" required>
    </div>
    <button class="btn" type="submit">دخول</button>
  </form>
</div>
</body>
</html>
