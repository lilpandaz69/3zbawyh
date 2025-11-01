<?php
class CategoriesModel {
public static function all(PDO $db, string $q = '', int $limit = 50, int $offset = 0): array {
$sql = "SELECT * FROM categories WHERE (? = '' OR name LIKE ?) ORDER BY name LIMIT ? OFFSET ?";
$st = $db->prepare($sql);
$like = "%$q%";
$st->execute([$q, $like, $limit, $offset]);
return $st->fetchAll(PDO::FETCH_ASSOC);
}


public static function count(PDO $db, string $q = ''): int {
$st = $db->prepare("SELECT COUNT(*) FROM categories WHERE (? = '' OR name LIKE ?)");
$like = "%$q%";
$st->execute([$q, $like]);
return (int)$st->fetchColumn();
}


public static function find(PDO $db, int $id): ?array {
$st = $db->prepare("SELECT * FROM categories WHERE id=?");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
return $row ?: null;
}


public static function create(PDO $db, string $name, ?string $description): int {
$st = $db->prepare("INSERT INTO categories (name, description) VALUES (?,?)");
$st->execute([$name, $description]);
return (int)$db->lastInsertId();
}


public static function update(PDO $db, int $id, string $name, ?string $description): bool {
$st = $db->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
return $st->execute([$name, $description, $id]);
}


public static function delete(PDO $db, int $id): bool {
// عند الحذف نجعل أصنافها NULL بدلًا من الحذف القسري
$db->prepare("UPDATE items SET category_id=NULL WHERE category_id=?")->execute([$id]);
$st = $db->prepare("DELETE FROM categories WHERE id=?");
return $st->execute([$id]);
}
}