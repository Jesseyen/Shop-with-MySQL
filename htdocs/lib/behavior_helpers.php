<?php
/**
 * 第三階段：行為紀錄共用函式
 * - 分析工作階段（session_key）：獨立隨機產生，前台閒置滿 30 分鐘、重新登入或切換會員時另開
 * - 購物車自動返回的一次性標記 _cart_return（5 分鐘、綁定登入狀態與商品）
 * - 商品瀏覽事件、購物車事件
 * - 原加入價格批次（FIFO）
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const ANALYTICS_IDLE_SECONDS = 1800;
const CART_RETURN_TTL = 300;

// ---------------------------------------------------------------------
// 分析工作階段
// ---------------------------------------------------------------------

/** 前台活動：更新活動時間；必要時另開新的分析工作階段。後台頁面不呼叫。 */
function analytics_touch(): void
{
    $m = current_member();
    if (!$m) { return; }
    $now = time();
    $s = $_SESSION['_an'] ?? null;
    $loginAt = $_SESSION['login_at'] ?? 0;
    if (!is_array($s) || ($s['m'] ?? null) !== $m['id'] || ($s['l'] ?? null) !== $loginAt
        || $now - (int)($s['t'] ?? 0) >= ANALYTICS_IDLE_SECONDS) {
        $s = ['m' => $m['id'], 'l' => $loginAt, 'k' => bin2hex(random_bytes(16)), 't' => $now];
    } else {
        $s['t'] = $now;
    }
    $_SESSION['_an'] = $s;
}

function analytics_session_key(): string
{
    analytics_touch();
    return (string)($_SESSION['_an']['k'] ?? bin2hex(random_bytes(16)));
}

function analytics_on_login(int $memberId): void
{
    unset($_SESSION['_an']);
}

// ---------------------------------------------------------------------
// 購物車自動返回標記
// ---------------------------------------------------------------------
function cart_return_issue(int $memberId, int $productId): string
{
    $now = time();
    $list = $_SESSION['_cart_return'] ?? [];
    foreach ($list as $k => $v) {
        if (($v['exp'] ?? 0) < $now) { unset($list[$k]); }
    }
    if (count($list) > 20) { $list = array_slice($list, -20, null, true); }
    $token = bin2hex(random_bytes(8));
    $list[$token] = ['m' => $memberId, 'p' => $productId, 'l' => $_SESSION['login_at'] ?? 0, 'exp' => $now + CART_RETURN_TTL];
    $_SESSION['_cart_return'] = $list;
    return $token;
}

/** 驗證並消耗標記：符合時回傳 true（此次返回不計瀏覽） */
function cart_return_consume(string $token, int $memberId, int $productId): bool
{
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) { return false; }
    $e = $_SESSION['_cart_return'][$token] ?? null;
    unset($_SESSION['_cart_return'][$token]);
    return is_array($e)
        && $e['m'] === $memberId
        && $e['p'] === $productId
        && $e['l'] === ($_SESSION['login_at'] ?? 0)
        && $e['exp'] >= time();
}

// ---------------------------------------------------------------------
// 事件寫入
// ---------------------------------------------------------------------
function behavior_insert_event(PDO $pdo, array $e): void
{
    $st = $pdo->prepare('INSERT INTO member_behavior_events
        (event_time, event_type, session_key, origin, member_id, member_level_at_event, product_id,
         category_id_at_event, category_origin, quantity_before, quantity_after, quantity_delta,
         unit_price, amount_delta, cart_id, cart_item_id)
        VALUES (NOW(6), ?, ?, \'native\', ?, ?, ?, ?, \'snapshot\', ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $e['event_type'], $e['session_key'], $e['member_id'], $e['member_level'], $e['product_id'],
        $e['category_id'], $e['quantity_before'] ?? null, $e['quantity_after'] ?? null, $e['quantity_delta'] ?? null,
        $e['unit_price'] ?? null, $e['amount_delta'] ?? null, $e['cart_id'] ?? null, $e['cart_item_id'] ?? null,
    ]);
}

/** 商品瀏覽：寫入失敗只記伺服器錯誤，頁面照常顯示（該次瀏覽可能缺記） */
function record_product_view(array $member, array $product): void
{
    global $pdo;
    try {
        behavior_insert_event($pdo, [
            'event_type' => 'view_product',
            'session_key' => analytics_session_key(),
            'member_id' => $member['id'],
            'member_level' => $member['member_level'],
            'product_id' => (int)$product['id'],
            'category_id' => $product['category_id'] !== null ? (int)$product['category_id'] : null,
        ]);
    } catch (Throwable $ex) {
        error_log('[shop] 瀏覽事件寫入失敗：' . $ex->getMessage());
    }
}

/** 購物車事件：必須在購物車交易內呼叫，失敗會讓整筆交易回復 */
function record_cart_event(PDO $pdo, string $type, array $member, array $product, array $cart, int $itemId,
                           int $before, int $after, ?string $unitPrice, string $amountDelta): void
{
    behavior_insert_event($pdo, [
        'event_type' => $type,
        'session_key' => analytics_session_key(),
        'member_id' => $member['id'],
        'member_level' => $member['member_level'],
        'product_id' => (int)$product['id'],
        'category_id' => $product['category_id'] !== null ? (int)$product['category_id'] : null,
        'quantity_before' => $before,
        'quantity_after' => $after,
        'quantity_delta' => $after - $before,
        'unit_price' => $unitPrice,
        'amount_delta' => $amountDelta,
        'cart_id' => $cart['id'],
        'cart_item_id' => $itemId,
    ]);
}

// ---------------------------------------------------------------------
// 原加入價格批次（FIFO）
// ---------------------------------------------------------------------

/**
 * 鎖定明細的批次並檢查一致性。舊明細若完全沒有批次，以「操作當下售價」補建 opening 批次。
 * 已有批次但剩餘量總和與明細數量不符時中止交易。
 */
function cart_lot_ensure(PDO $pdo, int $itemId, int $quantity, string $currentPrice): void
{
    $st = $pdo->prepare('SELECT id, remaining_quantity FROM cart_price_lots WHERE cart_item_id = ? ORDER BY id FOR UPDATE');
    $st->execute([$itemId]);
    $lots = $st->fetchAll();
    $sum = 0;
    foreach ($lots as $l) { $sum += (int)$l['remaining_quantity']; }
    if (!$lots) {
        if ($quantity > 0) { cart_lot_add($pdo, $itemId, $currentPrice, $quantity, 'opening'); }
        return;
    }
    if ($sum !== $quantity) {
        throw new UserError('此購物車明細的價格批次與數量不一致，已中止操作，請聯絡網站管理員。');
    }
}

function cart_lot_add(PDO $pdo, int $itemId, string $unitPrice, int $qty, string $origin = 'native'): void
{
    $pdo->prepare('INSERT INTO cart_price_lots (cart_item_id, unit_price, original_quantity, remaining_quantity, origin, created_at)
                   VALUES (?, ?, ?, ?, ?, NOW(6))')
        ->execute([$itemId, $unitPrice, $qty, $qty, $origin]);
}

/** 依批次 id 由小到大扣回 $qty 件，回傳扣回金額（正值，字串） */
function cart_lot_deduct(PDO $pdo, int $itemId, int $qty): string
{
    $st = $pdo->prepare('SELECT id, unit_price, remaining_quantity FROM cart_price_lots
                          WHERE cart_item_id = ? AND remaining_quantity > 0 ORDER BY id FOR UPDATE');
    $st->execute([$itemId]);
    $up = $pdo->prepare('UPDATE cart_price_lots SET remaining_quantity = remaining_quantity - ? WHERE id = ?');
    $cents = 0;
    $left = $qty;
    foreach ($st->fetchAll() as $l) {
        if ($left <= 0) { break; }
        $take = min($left, (int)$l['remaining_quantity']);
        $up->execute([$take, $l['id']]);
        $cents += dec_to_cents($l['unit_price']) * $take;
        $left -= $take;
    }
    if ($left > 0) {
        throw new UserError('價格批次剩餘數量不足，已中止操作，請聯絡網站管理員。');
    }
    return cents_to_dec($cents);
}
