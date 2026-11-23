<?php
/**
 * 商品、規格、圖片、分類、標籤查詢
 * 每個商品至少有一個規格（預設規格），庫存以規格為單位（variant_inventory）。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const PRODUCT_IMAGE_DIR = 'uploads/products/';
const SITE_IMAGE_DIR = 'uploads/site/';

function categories_all(): array
{
    global $pdo;
    return $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
}

function category_find(int $id): ?array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function tags_all(): array
{
    global $pdo;
    return $pdo->query('SELECT id, name, row_version FROM tags ORDER BY name')->fetchAll();
}

function product_tags(int $productId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT t.id, t.name FROM product_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.product_id = ? ORDER BY t.name');
    $st->execute([$productId]);
    return $st->fetchAll();
}

/** 價格區間、庫存、主圖的子查詢欄位（p 為 products 別名） */
function product_summary_columns(): string
{
    return "(SELECT MIN(COALESCE(v.price, p.price)) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS price_min,
            (SELECT MAX(COALESCE(v.price, p.price)) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS price_max,
            (SELECT COALESCE(SUM(vi.quantity), 0) FROM product_variants v JOIN variant_inventory vi ON vi.variant_id = v.id
              WHERE v.product_id = p.id AND v.is_active = 1) AS stock,
            (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count,
            (SELECT i.file_thumb FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS thumb";
}

function product_find(int $id): ?array
{
    global $pdo;
    $st = $pdo->prepare('SELECT p.*, c.name AS category_name, sr.name AS shipping_rule_name, ' . product_summary_columns() . '
                           FROM products p
                           LEFT JOIN categories c ON c.id = p.category_id
                           LEFT JOIN shipping_rules sr ON sr.id = p.shipping_rule_id
                          WHERE p.id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) { return null; }
    $p['tags'] = product_tags($id);
    $p['variants'] = product_variants($id);
    $p['options'] = product_options($id);
    $p['images'] = product_images($id);
    return $p;
}

/** 規格名稱與選項值：[['id','position','name','values'=>[[id,value,sort_order]...]]...] */
function product_options(int $productId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT id, position, name FROM product_options WHERE product_id = ? ORDER BY position');
    $st->execute([$productId]);
    $opts = $st->fetchAll();
    $vs = $pdo->prepare('SELECT id, value, sort_order FROM product_option_values WHERE option_id = ? ORDER BY sort_order, id');
    foreach ($opts as &$o) {
        $vs->execute([$o['id']]);
        $o['values'] = $vs->fetchAll();
    }
    return $opts;
}

/** 規格清單：含有效價格、庫存、規格文字 */
function product_variants(int $productId, bool $activeOnly = false): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT v.*, COALESCE(v.price, p.price) AS effective_price, COALESCE(vi.quantity, 0) AS stock,
                                vi.row_version AS inv_row_version, ov1.value AS value1, ov2.value AS value2
                           FROM product_variants v
                           JOIN products p ON p.id = v.product_id
                           LEFT JOIN variant_inventory vi ON vi.variant_id = v.id
                           LEFT JOIN product_option_values ov1 ON ov1.id = v.option_value1_id
                           LEFT JOIN product_option_values ov2 ON ov2.id = v.option_value2_id
                          WHERE v.product_id = ?' . ($activeOnly ? ' AND v.is_active = 1' : '') . '
                          ORDER BY v.is_default DESC, ov1.sort_order, ov1.id, ov2.sort_order, ov2.id, v.id');
    $st->execute([$productId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['variant_text'] = variant_text($r); }
    return $rows;
}

function variant_text(array $v): string
{
    $parts = array_filter([$v['value1'] ?? null, $v['value2'] ?? null], function ($x) { return $x !== null && $x !== ''; });
    return implode(' / ', $parts);
}

function variant_find(int $variantId): ?array
{
    global $pdo;
    $st = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ?');
    $st->execute([$variantId]);
    $pid = $st->fetchColumn();
    if ($pid === false) { return null; }
    foreach (product_variants((int)$pid) as $v) {
        if ((int)$v['id'] === $variantId) { return $v; }
    }
    return null;
}

function product_images(int $productId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
    $st->execute([$productId]);
    return $st->fetchAll();
}

function product_image_url(?string $file): string
{
    return ($file === null || $file === '') ? 'assets/sample.svg' : PRODUCT_IMAGE_DIR . $file;
}

/** 價格或價格區間，例如 NT$390～450 */
function price_range_text($min, $max): string
{
    if ($min === null) { return '—'; }
    if ($max === null || dec_to_cents($min) === dec_to_cents($max)) { return money($min); }
    return money($min) . '～' . substr(money($max), 3);
}

/**
 * 商品搜尋：名稱、SKU 或規格 SKU；分類、標籤；$activeOnly 為前台只列上架商品
 * 回傳 [rows, total]
 */
function product_search(array $f, int $page, int $perPage, bool $activeOnly): array
{
    global $pdo;
    $where = [];
    $args = [];
    if ($activeOnly) { $where[] = 'p.is_active = 1'; }
    if (($f['q'] ?? '') !== '') {
        $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR EXISTS (SELECT 1 FROM product_variants sv WHERE sv.product_id = p.id AND sv.sku LIKE ?))';
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        array_push($args, $like, $like, $like);
    }
    if (!empty($f['category'])) { $where[] = 'p.category_id = ?'; $args[] = (int)$f['category']; }
    if (!empty($f['tag'])) {
        $where[] = 'EXISTS (SELECT 1 FROM product_tags pt WHERE pt.product_id = p.id AND pt.tag_id = ?)';
        $args[] = (int)$f['tag'];
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $st = $pdo->prepare("SELECT COUNT(*) FROM products p {$sqlWhere}");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $order = !empty($f['order']) && $f['order'] === 'name' ? 'p.name, p.id' : 'p.id DESC';
    $st = $pdo->prepare("SELECT p.id, p.sku, p.name, p.price, p.is_active, p.brand, p.row_version, p.category_id,
                                c.name AS category_name, " . product_summary_columns() . "
                           FROM products p
                           LEFT JOIN categories c ON c.id = p.category_id
                           {$sqlWhere}
                          ORDER BY {$order}
                          LIMIT {$perPage} OFFSET {$offset}");
    $st->execute($args);
    return [$st->fetchAll(), $total];
}

function product_card(array $p, bool $lazy = true): string
{
    $stock = (int)$p['stock'];
    $stockText = $stock > 0 ? '有庫存' : '<span class="badge badge-warn">缺貨</span>';
    $img = product_image_url($p['thumb'] ?? null);
    return '<article class="product-card"><a href="product.php?id=' . (int)$p['id'] . '">'
        . '<img src="' . h($img) . '" alt="' . h($p['name']) . '" width="400" height="300"' . ($lazy ? ' loading="lazy"' : '') . '>'
        . '<h3>' . h($p['name']) . '</h3></a>'
        . '<p class="price">' . h(price_range_text($p['price_min'] ?? $p['price'], $p['price_max'] ?? $p['price'])) . '</p>'
        . '<p class="muted small">' . $stockText . '</p>'
        . '<a class="btn btn-small" href="product.php?id=' . (int)$p['id'] . '">查看詳情</a></article>';
}

/**
 * 建立商品時一併建立預設規格與庫存（須在交易內呼叫）
 */
function product_create_default_variant(PDO $pdo, int $productId, string $sku, int $stock): int
{
    $pdo->prepare('INSERT INTO product_variants (product_id, variant_key, sku, is_default, is_active) VALUES (?, \'default\', ?, 1, 1)')
        ->execute([$productId, $sku]);
    $vid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO variant_inventory (variant_id, quantity) VALUES (?, ?)')->execute([$vid, $stock]);
    return $vid;
}
