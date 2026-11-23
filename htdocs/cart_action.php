<?php
/**
 * 購物車寫入：加入（add）、修改數量（update）、移除（remove），以規格為單位
 * - POST + CSRF；伺服器重查會員、明細歸屬、商品、規格、庫存與 row_version
 * - 明細、價格批次與行為事件同一筆交易；購物車不預留、不扣減庫存
 * - 成功後 POST → 303 → GET；GA4 add_to_cart／remove_from_cart 存入一次性佇列
 */
require_once __DIR__ . '/app.php';
if (!$enable_cart) { render_not_found(); }
require_csrf();
$me = require_member();
maintenance_block_write();

$action = post_str('action');
$productIdForReturn = null;
$notice = null;
$messages = [];
$dl = null;

try {
    $pdo->beginTransaction();
    $member = cart_lock_member($pdo, $me['id']);
    $cart = cart_lock_cart($pdo, $member['id']);

    if ($action === 'add') {
        $productIdForReturn = to_pos_int($_POST['product_id'] ?? '');
        $variantId = to_pos_int($_POST['variant_id'] ?? '');
        if ($variantId === null) { throw new UserError('請選擇商品規格。'); }
        $qty = to_pos_int($_POST['quantity'] ?? '', 9999);
        if ($qty === null) { throw new UserError('加入數量須為 1 以上的整數。'); }

        $p = lock_variant($pdo, $variantId);
        $productIdForReturn = $p['id'];
        if ((int)$p['is_active'] !== 1) { throw new UserError('此商品已下架，無法加入購物車。'); }
        if ($p['variant_active'] !== 1) { throw new UserError('此規格已停售，無法加入購物車。'); }
        if ($p['stock'] <= 0) { throw new UserError('此規格目前缺貨，無法加入購物車。'); }

        $st = $pdo->prepare('SELECT id, quantity, row_version FROM cart_items WHERE cart_id = ? AND variant_id = ? FOR UPDATE');
        $st->execute([$cart['id'], $variantId]);
        $item = $st->fetch();
        $before = $item ? (int)$item['quantity'] : 0;
        $canAdd = $p['stock'] - $before;
        if ($qty > $canAdd) {
            throw new UserError($canAdd > 0
                ? "庫存不足：此規格最多還能加入 {$canAdd} 件（目前庫存 {$p['stock']} 件，購物車內已有 {$before} 件）。"
                : "您的購物車已達此規格的庫存上限（目前庫存 {$p['stock']} 件）。");
        }
        $after = $before + $qty;
        if ($item) {
            $itemId = (int)$item['id'];
            if ($enable_behavior) { cart_lot_ensure($pdo, $itemId, $before, $p['unit_price']); }
            $pdo->prepare('UPDATE cart_items SET quantity = ?, row_version = row_version + 1 WHERE id = ?')->execute([$after, $itemId]);
        } else {
            $pdo->prepare('INSERT INTO cart_items (cart_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)')
                ->execute([$cart['id'], $p['id'], $variantId, $after]);
            $itemId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE carts SET updated_at = NOW() WHERE id = ?')->execute([$cart['id']]);
        if ($enable_behavior) {
            cart_lot_add($pdo, $itemId, $p['unit_price'], $qty);
            $amount = cents_to_dec(dec_to_cents($p['unit_price']) * $qty);
            record_cart_event($pdo, 'add_to_cart', $member, $p, $cart, $itemId, $before, $after, $p['unit_price'], $amount);
        }
        $notice = ['product_id' => $p['id'], 'name' => $p['name'] . ($p['variant_text'] !== '' ? '（' . $p['variant_text'] . '）' : ''),
                   'added' => $qty, 'total' => $after];
        $dl = ['add_to_cart', $p, $qty];

    } elseif ($action === 'update' || $action === 'remove') {
        $itemId = to_pos_int($_POST['item_id'] ?? '');
        if ($itemId === null) { throw new UserError('購物車明細編號不正確。'); }
        $st = $pdo->prepare('SELECT variant_id FROM cart_items WHERE id = ? AND cart_id = ?');
        $st->execute([$itemId, $cart['id']]);
        $vid = $st->fetchColumn();
        if ($vid === false) { throw new UserError('找不到此購物車明細，可能已被移除。'); }
        $p = lock_variant($pdo, (int)$vid);
        $st = $pdo->prepare('SELECT id, variant_id, quantity, row_version FROM cart_items WHERE id = ? AND cart_id = ? FOR UPDATE');
        $st->execute([$itemId, $cart['id']]);
        $item = $st->fetch();
        if (!$item || (int)$item['variant_id'] !== $p['variant_id']) { throw new UserError('找不到此購物車明細，可能已被移除。'); }
        assert_row_version($item, $_POST['row_version'] ?? '');
        $before = (int)$item['quantity'];
        if ($enable_behavior) { cart_lot_ensure($pdo, $itemId, $before, $p['unit_price']); }
        $label = $p['name'] . ($p['variant_text'] !== '' ? '（' . $p['variant_text'] . '）' : '');

        if ($action === 'update') {
            $newQty = to_pos_int($_POST['quantity'] ?? '', 9999);
            if ($newQty === null) { throw new UserError('數量須為 1 以上的整數；若要刪除請按「移除」。'); }
            if ((int)$p['is_active'] !== 1 || $p['variant_active'] !== 1) { throw new UserError('此商品已下架或停售，無法修改數量，只能移除。'); }
            if ($p['stock'] <= 0) { throw new UserError('此規格目前缺貨，無法修改數量，只能移除。'); }
            if ($newQty > $p['stock']) { throw new UserError("數量不能超過目前庫存 {$p['stock']} 件。"); }
            if ($newQty === $before) {
                $messages[] = ['info', '數量沒有變更。'];
            } else {
                $pdo->prepare('UPDATE cart_items SET quantity = ?, row_version = row_version + 1 WHERE id = ?')->execute([$newQty, $itemId]);
                $pdo->prepare('UPDATE carts SET updated_at = NOW() WHERE id = ?')->execute([$cart['id']]);
                $delta = $newQty - $before;
                if ($enable_behavior) {
                    if ($delta > 0) {
                        cart_lot_add($pdo, $itemId, $p['unit_price'], $delta);
                        $unit = $p['unit_price'];
                        $amount = cents_to_dec(dec_to_cents($p['unit_price']) * $delta);
                    } else {
                        $unit = null;
                        $amount = cents_to_dec(-dec_to_cents(cart_lot_deduct($pdo, $itemId, -$delta)));
                    }
                    record_cart_event($pdo, 'update_cart', $member, $p, $cart, $itemId, $before, $newQty, $unit, $amount);
                }
                $dl = $delta > 0 ? ['add_to_cart', $p, $delta] : ['remove_from_cart', $p, -$delta];
                $messages[] = ['success', '「' . $label . '」數量已更新為 ' . $newQty . ' 件。'];
            }
        } else {
            $amount = null;
            if ($enable_behavior) { $amount = cents_to_dec(-dec_to_cents(cart_lot_deduct($pdo, $itemId, $before))); }
            $pdo->prepare('DELETE FROM cart_items WHERE id = ?')->execute([$itemId]);
            $pdo->prepare('UPDATE carts SET updated_at = NOW() WHERE id = ?')->execute([$cart['id']]);
            if ($enable_behavior) { record_cart_event($pdo, 'remove_from_cart', $member, $p, $cart, $itemId, $before, 0, null, $amount); }
            $dl = ['remove_from_cart', $p, $before];
            $messages[] = ['success', '已從購物車移除「' . $label . '」。'];
        }
    } else {
        throw new UserError('不支援的購物車操作。');
    }

    $pdo->commit();
    foreach ($messages as $m) { flash($m[0], $m[1]); }
    if ($enable_behavior) { analytics_touch(); }
    if ($notice) { $_SESSION['_cart_notice'] = $notice; }
    if ($dl && $enable_launch) {
        [$ev, $px, $q] = $dl;
        $cat = $px['category_id'] !== null ? (category_find((int)$px['category_id'])['name'] ?? null) : null;
        $it = dl_item(['sku' => $px['sku'], 'name' => $px['name'], 'brand' => $px['brand'], 'category_name' => $cat,
                       'variant_text' => $px['variant_text'], 'price' => $px['unit_price']], $q);
        datalayer_queue(dl_event($ev, ['currency' => 'TWD', 'value' => dl_items_value([$it]), 'items' => [$it]]));
    }
} catch (UserError $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash('error', $e->getMessage());
}

if ($action === 'add' && $productIdForReturn !== null) {
    $url = 'product.php?id=' . $productIdForReturn;
    if ($enable_behavior) { $url .= '&_cart_return=' . cart_return_issue($me['id'], $productIdForReturn); }
    redirect($url);
}
redirect('cart.php');
