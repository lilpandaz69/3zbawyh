<?php
// app/models/Users.php
require_once __DIR__ . '/../app/config/db.php';


class Users {
    public static function create($username, $password, $role_name='cashier') {
        $pdo = db();
        $role = self::roleId($role_name);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users(username, password_hash, role_id) VALUES (?,?,?)");
        $stmt->execute([$username, $hash, $role]);
        return $pdo->lastInsertId();
    }

    public static function roleId($name) {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name=?");
        $stmt->execute([$name]);
        $r = $stmt->fetch();
        if (!$r) throw new Exception("Role not found: $name");
        return (int)$r['id'];
    }
}
