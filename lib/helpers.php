<?php
// app/lib/helpers.php
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function now_egypt(){ return (new DateTime('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d H:i'); }
if (!function_exists('table_exists')) {
    function table_exists(PDO $db, string $table): bool {
        $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }
}
if (!function_exists('column_exists')) {
    function column_exists(PDO $db, string $table, string $col): bool {
        $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$table,$col]);
        return (bool)$st->fetchColumn();
    }
}