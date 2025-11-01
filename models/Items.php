<?php
require_once __DIR__ . '/../app/config/db.php';

class ItemsModel {

    public static function categories() {
        $db = db();
        try {
            // Try with is_active column first
            $st = $db->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name");
            $result = $st->fetchAll();
            if (!empty($result)) {
                return $result;
            }
        } catch (Exception $e) {
            // If is_active column doesn't exist, try without it
        }
        
        try {
            $st = $db->query("SELECT id, name FROM categories ORDER BY name");
            return $st->fetchAll();
        } catch (Exception $e) {
            // If categories table doesn't exist, return empty array
            return [];
        }
    }

    public static function subcategories($category_id = null) {
        $db = db();
        if ($category_id === null) {
            $st = $db->query("SELECT id, name, category_id FROM subcategories ORDER BY name");
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $db->prepare("SELECT id, name, category_id FROM subcategories WHERE category_id = ? ORDER BY name");
            $st->execute([$category_id]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }


    public static function findById($id) {
        $db = db();
        $st = $db->prepare("SELECT id, name, unit_price, stock FROM items WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public static function get($id) {
        return self::findById($id);
    }

    public static function search(string $q = '', ?int $cat = null, ?int $sub = null, int $limit = 50) {
        $db = db();

        // ✅ تم حذف sku من الجدول
        // نضيف NULL AS sku لتجنب أي خطأ من أكواد قديمة
        $sql = "
            SELECT i.id, i.name, i.unit_price, i.stock, NULL AS sku
            FROM items i
            WHERE 1
        ";

        $params = [];

        if ($q !== '') {
            $sql .= " AND (i.name LIKE :q)";
            $params[':q'] = "%$q%";
        }

        if ($cat !== null) {
            $sql .= " AND i.category_id = :cat";
            $params[':cat'] = $cat;
        }

        if ($sub !== null) {
            $sql .= " AND i.subcategory_id = :sub";
            $params[':sub'] = $sub;
        }

        $sql .= " ORDER BY i.name ASC LIMIT :limit";
        $st = $db->prepare($sql);

        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }

        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    
}
