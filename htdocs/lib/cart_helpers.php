<?php
/**
 * 第二階段：購物車共用函式（以規格為單位）
 * 交易內固定鎖定順序：會員 → 購物車 → 商品 → 規格 → 庫存 → 購物車明細 →（第三階段）價格批次
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

/** 某會員購物車內某規格的件數 */
function cart_quantity_of(int $memberId, int $variantId): int
{
    global $pdo;
    $st = $pdo->prepare('SELECT ci.quantity FROM carts c JOIN cart_items ci ON ci.cart_id = c.id
                          WHERE c.member_id = ? AND ci.variant_id = ?');
    $st->execute([$memberId, $variantId]);
    $q = $st->fetchColumn();
    return $q === false ? 0 : (int)$q;
}

/** 購物車內各規格件數：[variant_id => qty] */
function cart_quantities(int $memberId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT ci.variant_id, ci.quantity FROM carts c JOIN cart_items ci ON ci.cart_id = c.id WHERE c.member_id = ?');
    $st->execute([$memberId]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['variant_id']] = (int)$r['quantity']; }
    return $out;
}

/** 購物車頁資料：明細、小計與總額以整數「分」計算 */
function cart_view(int $memberId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT ci.id AS item_id, ci.quantity, ci.row_version, ci.product_id, ci.variant_id,
                                p.name, p.is_active, p.allow_cvs, p.shipping_rule_id, p.category_id, p.brand,
                                c2.name AS category_name,
                                v.sku, v.is_active AS variant_active, COALESCE(v.price, p.price) AS price,
                                COALESCE(vi.quantity, 0) AS stock, ov1.value AS value1, ov2.value AS value2,
                                (SELECT i.file_thumb FROM product_images i WHERE i.product_id = p.id
                                  ORDER BY (i.variant_id <=> v.id) DESC, i.sort_order, i.id LIMIT 1) AS thumb
                           FROM carts c
                           JOIN cart_items ci ON ci.cart_id = c.id
                           JOIN products p ON p.id = ci.product_id
                           JOIN product_variants v ON v.id = ci.variant_id
                           LEFT JOIN categories c2 ON c2.id = p.category_id
                           LEFT JOIN variant_inventory vi ON vi.variant_id = v.id
                           LEFT JOIN product_option_values ov1 ON ov1.id = v.option_value1_id
                           LEFT JOIN product_option_values ov2 ON ov2.id = v.option_value2_id
                          WHERE c.member_id = ?
                          ORDER BY ci.created_at, ci.id');
    $st->execute([$memberId]);
    $items = $st->fetchAll();
    $cents = 0;
    $count = 0;
    foreach ($items as &$it) {
        $it['variant_text'] = variant_text($it);
        $it['subtotal'] = cents_to_dec(dec_to_cents($it['price']) * (int)$it['quantity']);
        $it['problem'] = cart_item_problem($it);
        $cents += dec_to_cents($it['subtotal']);
        $count += (int)$it['quantity'];
    }
    unset($it);
    return ['items' => $items, 'total' => cents_to_dec($cents), 'count' => $count];
}

/** 明細目前無法購買的原因；可購買時回傳 null */
function cart_item_problem(array $it): ?string
{
    if ((int)$it['is_active'] !== 1) { return '已下架'; }
    if ((int)$it['variant_active'] !== 1) { return '此規格已停售'; }
    if ((int)$it['stock'] <= 0) { return '缺貨'; }
    if ((int)$it['quantity'] > (int)$it['stock']) { return '超過庫存（目前 ' . (int)$it['stock'] . ' 件）'; }
    return null;
}

/** 交易內：重新核對會員狀態並以共用鎖鎖定會員列 */
function cart_lock_member(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT id, name, email, status, deleted_at, member_level FROM members WHERE id = ? LOCK IN SHARE MODE');
    $st->execute([$memberId]);
    $m = $st->fetch();
    if (!$m || (int)$m['status'] !== 1 || $m['deleted_at'] !== null) {
        throw new UserError('您的帳號目前無法使用購物車，請重新登入。');
    }
    $m['id'] = (int)$m['id'];
    return $m;
}

/** 交易內：取得（必要時建立）會員的購物車並鎖定 */
function cart_lock_cart(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT id, member_id FROM carts WHERE member_id = ? FOR UPDATE');
    $st->execute([$memberId]);
    $c = $st->fetch();
    if (!$c) {
        $pdo->prepare('INSERT IGNORE INTO carts (member_id) VALUES (?)')->execute([$memberId]);
        $st->execute([$memberId]);
        $c = $st->fetch();
    }
    if (!$c) { throw new RuntimeException('無法建立購物車'); }
    $c['id'] = (int)$c['id'];
    return $c;
}

/**
 * 交易內：依序鎖定商品 → 規格 → 庫存（共用鎖；$forUpdate 時改為排他鎖，用於扣庫存）
 */
function lock_variant(PDO $pdo, int $variantId, bool $forUpdate = false): array
{
    $mode = $forUpdate ? 'FOR UPDATE' : 'LOCK IN SHARE MODE';
    $st = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ?');
    $st->execute([$variantId]);
    $pid = $st->fetchColumn();
    if ($pid === false) { throw new UserError('找不到此商品規格。'); }
    $st = $pdo->prepare("SELECT id, name, price, is_active, category_id, allow_cvs, shipping_rule_id, brand FROM products WHERE id = ? {$mode}");
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) { throw new UserError('找不到此商品。'); }
    $st = $pdo->prepare("SELECT id, product_id, sku, price, is_active, option_value1_id, option_value2_id FROM product_variants WHERE id = ? {$mode}");
    $st->execute([$variantId]);
    $v = $st->fetch();
    if (!$v || (int)$v['product_id'] !== (int)$p['id']) { throw new UserError('找不到此商品規格。'); }
    $st = $pdo->prepare("SELECT quantity FROM variant_inventory WHERE variant_id = ? {$mode}");
    $st->execute([$variantId]);
    $q = $st->fetchColumn();
    $p['id'] = (int)$p['id'];
    $p['variant_id'] = (int)$v['id'];
    $p['sku'] = $v['sku'];
    $p['variant_active'] = (int)$v['is_active'];
    $p['unit_price'] = $v['price'] !== null ? $v['price'] : $p['price'];
    $p['stock'] = $q === false ? 0 : (int)$q;
    $p['variant_text'] = variant_text_by_ids($pdo, $v['option_value1_id'], $v['option_value2_id']);
    return $p;
}

function variant_text_by_ids(PDO $pdo, $v1, $v2): string
{
    $vals = [];
    foreach ([$v1, $v2] as $id) {
        if ($id === null) { continue; }
        $st = $pdo->prepare('SELECT value FROM product_option_values WHERE id = ?');
        $st->execute([$id]);
        $x = $st->fetchColumn();
        if ($x !== false) { $vals[] = $x; }
    }
    return implode(' / ', $vals);
}
