<?php
// templates/nav.php
$u = current_user();
?>
<nav class="nav">
  <div class="brand">العزباوية</div>
  <ul>
    <?php if($u): ?>
      <li><a href="/al3zbwyh/public/dashboard.php">اللوحة</a></li>
      <!-- هنضيف POS بعدين -->
      <li><a href="/al3zbwyh/public/logout.php">تسجيل الخروج (<?=e($u['username'])?>)</a></li>
    <?php else: ?>
      <li><a href="/al3zbwyh/public/login.php">تسجيل الدخول</a></li>
    <?php endif; ?>
  </ul>
</nav>
