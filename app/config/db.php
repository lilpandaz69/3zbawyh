<?php
// app/config/db.php
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $host = '127.0.0.1';
        $db   = '3zbwyh'; // <= لو اسم DB عندك 3zbawyh عدّله هنا
        $user = 'root';
        $pass = '';
        $charset = 'utf8mb4';
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, $user, $pass, $opts);
        // قيود صارمة لإدخال بيانات صحيحة
        $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES'");
        // إعداد مستوى العزل على مستوى الجلسة (اختياري)
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
    }
    return $pdo;
}
