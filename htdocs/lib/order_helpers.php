<?php
/**
 * 第四階段：地址、運費、會員等級、訂單、出貨單、退貨收件單、售後單
 *
 * 固定鎖定順序：會員 → 購物車 → 訂單 → 售後單 → 出貨單／收件單 → 商品 → 規格 → 庫存
 * 狀態轉換一律以「原狀態 + row_version」條件更新；寄信在交易提交後進行（defer_mail）。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

// ---------------------------------------------------------------------
// 標籤
// ---------------------------------------------------------------------
function order_status_labels(): array
{
    return ['pending_payment' => '待付款', 'processing' => '處理中', 'shipped' => '已出貨', 'completed' => '已完成',
            'cancelled' => '已取消', 'refused' => '拒收'];
}
function payment_method_labels(): array
{
    return ['cod' => '貨到付款', 'cvs_cod' => '超商取貨付款', 'sim_card' => '信用卡（模擬）', 'sim_paypal' => 'PayPal（模擬）'];
}
function payment_status_labels(): array
{
    return ['cod_unpaid' => '到付未收', 'pending' => '待付款', 'paid' => '已付款', 'failed' => '付款失敗',
            'partially_refunded' => '部分退款', 'refunded' => '已退款'];
}
function shipping_method_labels(): array
{
    return ['home' => '宅配', 'cvs' => '超商取貨'];
}
function cvs_chain_labels(): array
{
    return ['711' => '7-ELEVEN', 'family' => '全家', 'hilife' => '萊爾富', 'ok' => 'OK 超商'];
}
function carrier_labels(): array
{
    return ['tcat' => '黑貓宅急便', 'hct' => '新竹物流', 'post' => '中華郵政', 'other' => '其他'];
}
function after_sale_type_labels(): array { return ['return' => '退貨', 'exchange' => '換貨']; }
function after_sale_status_labels(): array
{
    return ['requested' => '申請中', 'in_progress' => '處理中', 'completed' => '已完成', 'rejected' => '已拒絕', 'withdrawn' => '已撤回'];
}
function after_sale_reason_labels(): array
{
    return ['defective' => '商品瑕疵', 'not_as_described' => '與描述不符', 'unwanted' => '不想要了', 'other' => '其他'];
}
function shipment_status_labels(): array
{
    return ['on_hold' => '暫緩', 'pending' => '待出貨', 'shipped' => '已出貨', 'arrived' => '已到店', 'picked_up' => '已取貨',
            'returned' => '未取貨退回', 'void' => '作廢'];
}
function receipt_type_labels(): array { return ['return' => '退貨收回', 'exchange' => '換貨收回', 'refused' => '拒收退回']; }
function receipt_status_labels(): array { return ['pending' => '待收件', 'received' => '已收件']; }
function label_of(array $labels, $key): string { return $labels[$key] ?? (string)$key; }

// ---------------------------------------------------------------------
// 交易提交後才寄信
// ---------------------------------------------------------------------
function defer_mail(callable $fn): void
{
    $GLOBALS['_deferred_mail'][] = $fn;
}

function flush_deferred_mail(): void
{
    $list = $GLOBALS['_deferred_mail'] ?? [];
    $GLOBALS['_deferred_mail'] = [];
    foreach ($list as $fn) {
        try { $fn(); } catch (Throwable $e) { error_record('warning', '寄信例外：' . $e->getMessage(), $e->getFile(), $e->getLine(), ''); }
    }
}

function clear_deferred_mail(): void
{
    $GLOBALS['_deferred_mail'] = [];
}

// ---------------------------------------------------------------------
// 地址簿（第 8 節）
// ---------------------------------------------------------------------
const ADDRESS_LIMIT = 10;

function address_list(int $memberId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM member_addresses WHERE member_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id');
    $st->execute([$memberId]);
    return $st->fetchAll();
}

function address_find(int $memberId, int $id): ?array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM member_addresses WHERE id = ? AND member_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $memberId]);
    return $st->fetch() ?: null;
}

/** 驗證地址欄位，回傳 [資料, 錯誤陣列] */
function address_validate(array $in): array
{
    $d = [];
    foreach (['recipient', 'phone', 'zip', 'city', 'district', 'address'] as $k) {
        $d[$k] = trim((string)($in[$k] ?? ''));
    }
    $e = [];
    if (!len_between($d['recipient'], 1, 30)) { $e[] = '收件人須為 1～30 字。'; }
    if (!preg_match('/^[0-9\-]{8,20}$/', $d['phone'])) { $e[] = '電話只能包含數字與「-」，8～20 字。'; }
    if (!preg_match('/^[0-9]{3,6}$/', $d['zip'])) { $e[] = '郵遞區號須為 3～6 位數字。'; }
    if (!len_between($d['city'], 1, 10)) { $e[] = '縣市須為 1～10 字。'; }
    if (!len_between($d['district'], 1, 10)) { $e[] = '鄉鎮區須為 1～10 字。'; }
    if (!len_between($d['address'], 1, 100)) { $e[] = '詳細地址須為 1～100 字。'; }
    return [$d, $e];
}

/** 交易內新增地址（第一筆自動設為預設），回傳編號 */
function address_insert(PDO $pdo, int $memberId, array $d, bool $makeDefault): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM member_addresses WHERE member_id = ? AND deleted_at IS NULL');
    $st->execute([$memberId]);
    $n = (int)$st->fetchColumn();
    if ($n >= ADDRESS_LIMIT) { throw new UserError('收件資料最多 ' . ADDRESS_LIMIT . ' 筆，請先刪除不需要的資料。'); }
    if ($n === 0) { $makeDefault = true; }
    if ($makeDefault) {
        $pdo->prepare('UPDATE member_addresses SET is_default = 0, row_version = row_version + 1 WHERE member_id = ? AND is_default = 1')
            ->execute([$memberId]);
    }
    $pdo->prepare('INSERT INTO member_addresses (member_id, recipient, phone, zip, city, district, address, is_default)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$memberId, $d['recipient'], $d['phone'], $d['zip'], $d['city'], $d['district'], $d['address'], $makeDefault ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

function address_line(array $a, string $prefix = ''): string
{
    return trim(($a[$prefix . 'zip'] ?? '') . ' ' . ($a[$prefix . 'city'] ?? '') . ($a[$prefix . 'district'] ?? '') . ($a[$prefix . 'address'] ?? ''));
}

/** 地址簿資料 → 單據快照欄位 */
function address_snapshot(array $a): array
{
    return ['ship_name' => $a['recipient'], 'ship_phone' => $a['phone'], 'ship_zip' => $a['zip'],
            'ship_city' => $a['city'], 'ship_district' => $a['district'], 'ship_address' => $a['address']];
}

// ---------------------------------------------------------------------
// 運費（第 9 節）
// ---------------------------------------------------------------------
function shipping_rule_find(int $id): ?array
{
    global $pdo;
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $st = $pdo->prepare('SELECT * FROM shipping_rules WHERE id = ?');
        $st->execute([$id]);
        $cache[$id] = $st->fetch() ?: null;
    }
    return $cache[$id];
}

function shipping_rules_all(bool $activeOnly = false): array
{
    global $pdo;
    return $pdo->query('SELECT r.*, (SELECT COUNT(*) FROM products p WHERE p.shipping_rule_id = r.id) AS used
                          FROM shipping_rules r' . ($activeOnly ? ' WHERE r.is_active = 1' : '') . ' ORDER BY r.name')->fetchAll();
}

/**
 * 計算運費：$items 需有 shipping_rule_id 與 subtotal；回傳 ['fee' => DECIMAL, 'rule_name' => ?string]
 * 宅配：整筆只收一次、取最高運費；規則有門檻且商品小計 ≧ 門檻則不收；未設規則或停用規則視同免運。
 * 超商：後台設定的固定運費與免運門檻。
 */
function shipping_calc(array $items, string $method = 'home'): array
{
    $sub = 0;
    foreach ($items as $it) { $sub += dec_to_cents($it['subtotal']); }
    if ($method === 'cvs') {
        $th = setting('cvs_free_threshold');
        if ($th !== '' && $sub >= dec_to_cents($th)) { return ['fee' => '0.00', 'rule_name' => '超商取貨（滿額免運）']; }
        return ['fee' => cents_to_dec(dec_to_cents(setting('cvs_fee', '0'))), 'rule_name' => '超商取貨'];
    }
    if (setting('shipping_mode', 'by_rule') === 'all_free') { return ['fee' => '0.00', 'rule_name' => '全站免運']; }
    $best = 0;
    $name = null;
    $seen = [];
    foreach ($items as $it) {
        $rid = $it['shipping_rule_id'] ?? null;
        if ($rid === null || isset($seen[$rid])) { continue; }
        $seen[$rid] = true;
        $r = shipping_rule_find((int)$rid);
        if (!$r || (int)$r['is_active'] !== 1) { continue; }
        if ($r['free_threshold'] !== null && $sub >= dec_to_cents($r['free_threshold'])) { continue; }
        $fee = dec_to_cents($r['fee']);
        if ($fee > $best) { $best = $fee; $name = $r['name']; }
    }
    return ['fee' => cents_to_dec($best), 'rule_name' => $name];
}

// ---------------------------------------------------------------------
// 會員等級（第 14 節）
// ---------------------------------------------------------------------
function level_order(): array { return ['regular' => 0, 'silver' => 1, 'gold' => 2, 'diamond' => 3]; }

function level_thresholds(): array
{
    global $pdo;
    $t = ['regular' => '0.00', 'silver' => '3000.00', 'gold' => '10000.00', 'diamond' => '30000.00'];
    try {
        foreach ($pdo->query('SELECT level, threshold FROM member_level_thresholds') as $r) { $t[$r['level']] = $r['threshold']; }
    } catch (PDOException $e) {
    }
    $t['regular'] = '0.00';
    return $t;
}

function level_for($total): string
{
    $lv = 'regular';
    foreach (level_thresholds() as $k => $th) {
        if (dec_to_cents($total) >= dec_to_cents($th)) { $lv = $k; }
    }
    return $lv;
}

function level_next(string $level, $total): ?array
{
    $order = level_order();
    foreach (level_thresholds() as $k => $th) {
        if ($order[$k] > $order[$level]) {
            $gap = dec_to_cents($th) - dec_to_cents($total);
            if ($gap > 0) { return ['level' => $k, 'gap' => cents_to_dec($gap)]; }
        }
    }
    return null;
}

/**
 * 交易內（會員列已鎖定）：累積消費加減 $deltaCents 並重算等級；鎖定等級者只更新累積消費。
 * $allowDowngrade 只有退貨完成時為 true。
 */
function member_spend_apply(PDO $pdo, int $memberId, int $deltaCents, string $reason, bool $allowDowngrade,
                            ?int $orderId = null, ?int $afterSaleId = null, ?int $actorId = null): void
{
    $st = $pdo->prepare('SELECT id, member_level, level_locked, total_spent FROM members WHERE id = ? FOR UPDATE');
    $st->execute([$memberId]);
    $m = $st->fetch();
    if (!$m) { throw new RuntimeException('找不到會員'); }
    $newTotal = max(0, dec_to_cents($m['total_spent']) + $deltaCents);
    $newTotalDec = cents_to_dec($newTotal);
    $cur = $m['member_level'];
    $target = level_for($newTotalDec);
    $o = level_order();
    $newLevel = $cur;
    if ((int)$m['level_locked'] !== 1) {
        if ($o[$target] > $o[$cur] || ($allowDowngrade && $o[$target] < $o[$cur])) { $newLevel = $target; }
    }
    $pdo->prepare('UPDATE members SET total_spent = ?, member_level = ?, row_version = row_version + 1 WHERE id = ?')
        ->execute([$newTotalDec, $newLevel, $memberId]);
    if ($newLevel !== $cur) {
        $pdo->prepare('INSERT INTO member_level_logs (member_id, old_level, new_level, total_spent, reason, order_id, after_sale_id, actor_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$memberId, $cur, $newLevel, $newTotalDec, $reason, $orderId, $afterSaleId, $actorId]);
    }
}

// ---------------------------------------------------------------------
// 庫存（須已依序鎖定商品 → 規格 → 庫存）
// ---------------------------------------------------------------------
function stock_deduct(PDO $pdo, int $variantId, int $qty): void
{
    $p = lock_variant($pdo, $variantId, true);
    if ($p['stock'] < $qty) {
        throw new UserError('「' . $p['name'] . ($p['variant_text'] !== '' ? '（' . $p['variant_text'] . '）' : '') . '」庫存不足（目前 ' . $p['stock'] . ' 件）。');
    }
    $st = $pdo->prepare('UPDATE variant_inventory SET quantity = quantity - ?, row_version = row_version + 1 WHERE variant_id = ? AND quantity >= ?');
    $st->execute([$qty, $variantId, $qty]);
    if ($st->rowCount() !== 1) { throw new UserError('庫存不足，請重新確認。'); }
}

function stock_restock(PDO $pdo, int $variantId, int $qty): void
{
    lock_variant($pdo, $variantId, true);
    $pdo->prepare('INSERT INTO variant_inventory (variant_id, quantity) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), row_version = row_version + 1')
        ->execute([$variantId, $qty]);
}

/** 依規格編號排序後逐一處理，避免交叉鎖定 */
function stock_apply_items(PDO $pdo, array $variantQty, bool $deduct): void
{
    ksort($variantQty);
    foreach ($variantQty as $vid => $q) {
        if ($q <= 0) { continue; }
        $deduct ? stock_deduct($pdo, (int)$vid, (int)$q) : stock_restock($pdo, (int)$vid, (int)$q);
    }
}

// ---------------------------------------------------------------------
// 訂單讀取
// ---------------------------------------------------------------------
function order_find(int $id, ?int $memberId = null): ?array
{
    global $pdo;
    $sql = 'SELECT o.*, m.name AS member_name, m.email AS member_email, m.member_level FROM orders o JOIN members m ON m.id = o.member_id WHERE o.id = ?';
    $args = [$id];
    if ($memberId !== null) { $sql .= ' AND o.member_id = ?'; $args[] = $memberId; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetch() ?: null;
}

function order_items(int $orderId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

function order_events(int $orderId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT e.*, m.name AS actor_name FROM order_events e LEFT JOIN members m ON m.id = e.actor_id
                          WHERE e.order_id = ? ORDER BY e.id');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

function order_shipments(int $orderId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM shipments WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

function order_receipts(int $orderId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM return_receipts WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

function order_after_sales(int $orderId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM after_sales WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    $rows = $st->fetchAll();
    $it = $pdo->prepare('SELECT asi.*, oi.product_name, oi.variant_text, oi.sku, oi.unit_price FROM after_sale_items asi
                           JOIN order_items oi ON oi.id = asi.order_item_id WHERE asi.after_sale_id = ? ORDER BY asi.id');
    foreach ($rows as &$r) {
        $it->execute([$r['id']]);
        $r['items'] = $it->fetchAll();
    }
    return $rows;
}

/** 退換貨截止日（完成日 + 天數，當天 23:59:59） */
function order_return_deadline(array $o): ?string
{
    if ($o['completed_at'] === null) { return null; }
    $days = setting_int('return_days', 7);
    return date('Y-m-d 23:59:59', strtotime(substr($o['completed_at'], 0, 10) . " +{$days} days"));
}

/** 可申請退換貨的數量：購買數量 − 已退貨完成 − 進行中售後單 */
function order_item_available(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare("SELECT oi.id, oi.quantity - oi.returned_qty - COALESCE((
                                SELECT SUM(asi.quantity) FROM after_sale_items asi JOIN after_sales a ON a.id = asi.after_sale_id
                                 WHERE asi.order_item_id = oi.id AND a.status IN ('requested','in_progress')), 0) AS avail
                           FROM order_items oi WHERE oi.order_id = ?");
    $st->execute([$orderId]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['id']] = max(0, (int)$r['avail']); }
    return $out;
}

/** 會員能否申請售後，回傳 null 表示可以，否則回傳原因 */
function order_after_sale_block(array $o): ?string
{
    if ($o['status'] === 'shipped') {
        if ($o['shipping_method'] === 'cvs') { return '超商取貨訂單請於取貨後再申請退換貨。'; }
        return null;
    }
    if ($o['status'] !== 'completed') { return '此訂單目前的狀態不能申請退換貨。'; }
    $dl = order_return_deadline($o);
    if ($dl !== null && time() > strtotime($dl)) { return '已超過退換貨期限（' . substr($dl, 0, 10) . '）。'; }
    return null;
}

/** 會員端訂單狀態摘要，例如「已完成（2 項換貨處理中）」 */
function order_member_status(array $o, array $afterSales = []): string
{
    $label = label_of(order_status_labels(), $o['status']);
    $active = ['return' => 0, 'exchange' => 0];
    foreach ($afterSales as $a) {
        if (in_array($a['status'], ['requested', 'in_progress'], true)) { $active[$a['type']] += count($a['items'] ?? [1]); }
    }
    $extra = [];
    if ($active['return']) { $extra[] = $active['return'] . ' 項退貨處理中'; }
    if ($active['exchange']) { $extra[] = $active['exchange'] . ' 項換貨處理中'; }
    return $label . ($extra ? '（' . implode('、', $extra) . '）' : '');
}

/** 會員端售後進度文字（第 12 節） */
function after_sale_member_status(array $a, ?array $receipt): string
{
    switch ($a['status']) {
        case 'requested': return '申請審核中';
        case 'rejected': return '未核准' . ($a['reject_reason'] ? '：' . $a['reject_reason'] : '');
        case 'withdrawn': return '已撤回';
        case 'completed':
            return $a['type'] === 'return'
                ? '退貨完成（退款 ' . money($a['refund_amount']) . '，' . $a['refund_date'] . '）'
                : '新品已寄出';
        case 'in_progress':
            if ($receipt && $receipt['status'] === 'received') {
                return $a['type'] === 'return' ? '已收到退貨，退款處理中' : '已收到商品，新品準備中';
            }
            return '請寄回商品';
    }
    return $a['status'];
}

// ---------------------------------------------------------------------
// 訂單寫入
// ---------------------------------------------------------------------
function order_event(PDO $pdo, int $orderId, string $type, ?string $from, ?string $to, string $actorType, ?int $actorId,
                     ?string $public = null, ?string $internal = null): void
{
    if (!empty($GLOBALS['_test_fail_order_event'])) { throw new RuntimeException('模擬歷程寫入失敗'); }
    $pdo->prepare('INSERT INTO order_events (order_id, event_type, from_status, to_status, actor_type, actor_id, public_note, internal_note)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orderId, $type, $from, $to, $actorType, $actorId,
                   $public === null ? null : mb_substr($public, 0, 500), $internal === null ? null : mb_substr($internal, 0, 1000)]);
}

/** 交易內：先鎖會員、再鎖訂單（FOR UPDATE） */
function order_lock(PDO $pdo, int $orderId, ?int $memberId = null): array
{
    $st = $pdo->prepare('SELECT member_id FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $mid = $st->fetchColumn();
    if ($mid === false || ($memberId !== null && (int)$mid !== $memberId)) { throw new UserError('找不到此訂單。'); }
    $pdo->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE')->execute([$mid]);
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$orderId]);
    return $st->fetch();
}

/** 以原狀態＋row_version 條件更新訂單 */
function order_update(PDO $pdo, array $o, array $fields): void
{
    $set = [];
    $args = [];
    foreach ($fields as $k => $v) { $set[] = "`{$k}` = ?"; $args[] = $v; }
    $args[] = $o['id'];
    $args[] = $o['status'];
    $args[] = $o['row_version'];
    $st = $pdo->prepare('UPDATE orders SET ' . implode(', ', $set) . ', row_version = row_version + 1 WHERE id = ? AND status = ? AND row_version = ?');
    $st->execute($args);
    if ($st->rowCount() !== 1) { throw new UserError('訂單狀態已變更，請重新整理後再操作。'); }
}

function shipment_create(PDO $pdo, array $o, string $type, string $status, ?int $afterSaleId, array $items, array $snap): int
{
    $no = next_doc_no($pdo, 'D');
    $method = $type === 'exchange' ? 'home' : $o['shipping_method'];
    $pdo->prepare('INSERT INTO shipments (shipment_no, order_id, after_sale_id, type, status, shipping_method, ship_name, ship_phone,
                          ship_zip, ship_city, ship_district, ship_address, cvs_chain, cvs_store_code, cvs_store_name, cvs_store_address)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$no, $o['id'], $afterSaleId, $type, $status, $method, $snap['ship_name'] ?? null, $snap['ship_phone'] ?? null,
                   $snap['ship_zip'] ?? null, $snap['ship_city'] ?? null, $snap['ship_district'] ?? null, $snap['ship_address'] ?? null,
                   $method === 'cvs' ? $o['cvs_chain'] : null, $method === 'cvs' ? $o['cvs_store_code'] : null,
                   $method === 'cvs' ? $o['cvs_store_name'] : null, $method === 'cvs' ? $o['cvs_store_address'] : null]);
    $sid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO shipment_items (shipment_id, order_item_id, quantity) VALUES (?, ?, ?)');
    foreach ($items as $oiId => $q) { $ins->execute([$sid, $oiId, $q]); }
    order_event($pdo, (int)$o['id'], 'shipment_created', null, null, 'system', null, null, '建立' . ($type === 'exchange' ? '換貨' : '訂單') . '出貨單 ' . $no . '（' . label_of(shipment_status_labels(), $status) . '）');
    return $sid;
}

function receipt_create(PDO $pdo, array $o, string $type, ?int $afterSaleId, array $items, ?string $note = null): int
{
    $no = next_doc_no($pdo, 'I');
    $pdo->prepare('INSERT INTO return_receipts (receipt_no, order_id, after_sale_id, type, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$no, $o['id'], $afterSaleId, $type, $note]);
    $rid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO return_receipt_items (receipt_id, order_item_id, quantity) VALUES (?, ?, ?)');
    foreach ($items as $oiId => $q) { $ins->execute([$rid, $oiId, $q]); }
    order_event($pdo, (int)$o['id'], 'receipt_created', null, null, 'system', null, null, '建立' . label_of(receipt_type_labels(), $type) . '收件單 ' . $no);
    return $rid;
}

/** 全部明細數量：[order_item_id => qty] 與 [variant_id => qty] */
function order_item_maps(PDO $pdo, int $orderId): array
{
    $byItem = [];
    $byVariant = [];
    foreach (order_items($orderId) as $it) {
        $byItem[(int)$it['id']] = (int)$it['quantity'];
        $byVariant[(int)$it['variant_id']] = ($byVariant[(int)$it['variant_id']] ?? 0) + (int)$it['quantity'];
    }
    return [$byItem, $byVariant];
}

/**
 * 完成訂單（已出貨 → 已完成）：記錄完成時間與完成者、到付轉已付款、累積消費加上商品小計並重算等級
 * 訂單須已由 order_lock 鎖定。
 */
function order_complete(PDO $pdo, array $o, string $by, ?int $actorId, string $note): array
{
    if ($o['status'] !== 'shipped') { throw new UserError('只有已出貨的訂單可以完成。'); }
    $fields = ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'completed_by' => $by, 'completed_actor_id' => $actorId];
    if (in_array($o['payment_method'], ['cod', 'cvs_cod'], true)) { $fields['payment_status'] = 'paid'; $fields['paid_at'] = date('Y-m-d H:i:s'); }
    order_update($pdo, $o, $fields);
    order_event($pdo, (int)$o['id'], 'status', 'shipped', 'completed', $by, $actorId, $note);
    member_spend_apply($pdo, (int)$o['member_id'], dec_to_cents($o['subtotal']), 'order_completed', false, (int)$o['id'], null, $actorId);
    $o = array_merge($o, $fields);
    $o['row_version'] = (int)$o['row_version'] + 1;
    return $o;
}

/**
 * 取消訂單（待付款或處理中）：立即回補庫存、作廢待出貨的出貨單、線上已付款者模擬退款
 */
function order_cancel(PDO $pdo, array $o, string $actorType, ?int $actorId, string $reason): void
{
    if (!in_array($o['status'], ['pending_payment', 'processing'], true)) { throw new UserError('只有待付款或處理中的訂單可以取消。'); }
    $st = $pdo->prepare("SELECT * FROM shipments WHERE order_id = ? AND type = 'order' FOR UPDATE");
    $st->execute([$o['id']]);
    foreach ($st->fetchAll() as $s) {
        if ($s['status'] !== 'pending' && $s['status'] !== 'on_hold') { throw new UserError('出貨單已處理，不能取消訂單。'); }
        $pdo->prepare("UPDATE shipments SET status = 'void', row_version = row_version + 1 WHERE id = ?")->execute([$s['id']]);
    }
    [, $byVariant] = order_item_maps($pdo, (int)$o['id']);
    $fields = ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr($reason, 0, 200)];
    $refunded = false;
    if ($o['payment_status'] === 'paid' && in_array($o['payment_method'], ['sim_card', 'sim_paypal'], true) && function_exists('payment_refund_sim')) {
        payment_refund_sim($pdo, $o, $o['total']);
        $fields['payment_status'] = 'refunded';
        $fields['refunded_total'] = $o['total'];
        $refunded = true;
    } elseif ($o['payment_status'] === 'pending' || $o['payment_status'] === 'failed') {
        $fields['payment_status'] = 'failed';
    }
    order_update($pdo, $o, $fields);
    stock_apply_items($pdo, $byVariant, false);
    order_event($pdo, (int)$o['id'], 'status', $o['status'], 'cancelled', $actorType, $actorId, '訂單已取消：' . $reason);
    if ($refunded) { order_event($pdo, (int)$o['id'], 'refund', null, null, 'system', null, '已以原付款方式模擬退款 ' . money($o['total'])); }
    $oid = (int)$o['id'];
    defer_mail(function () use ($oid, $reason, $refunded) { mail_order_cancelled($oid, $reason, $refunded); });
}

/** 標記拒收（已出貨 → 拒收）：產生拒收退回收件單，驗收後才回補庫存 */
function order_refuse(PDO $pdo, array $o, string $actorType, ?int $actorId, string $reason): void
{
    if ($o['status'] !== 'shipped') { throw new UserError('只有已出貨的訂單可以標記拒收；已完成的訂單不能改為拒收。'); }
    order_update($pdo, $o, ['status' => 'refused', 'refused_reason' => mb_substr($reason, 0, 200)]);
    order_event($pdo, (int)$o['id'], 'status', 'shipped', 'refused', $actorType, $actorId, '訂單拒收：' . $reason);
    [$byItem] = order_item_maps($pdo, (int)$o['id']);
    receipt_create($pdo, $o, 'refused', null, $byItem, $reason);
}

// ---------------------------------------------------------------------
// 被動檢查：自動完成（第 11 節）、逾期未付款取消（第 31 節）
// ---------------------------------------------------------------------
function orders_passive_maintenance(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    orders_auto_complete(50);
    if ($GLOBALS['enable_launch']) { orders_cancel_expired_payments(50); }
}

function orders_auto_complete(int $limit): int
{
    global $pdo;
    $days = max(1, min(14, setting_int('auto_complete_days', 3)));
    $st = $pdo->prepare("SELECT id FROM orders WHERE status = 'shipped' AND auto_complete_paused = 0 AND shipping_method = 'home'
                            AND shipped_at <= NOW() - INTERVAL {$days} DAY ORDER BY shipped_at LIMIT {$limit}");
    $st->execute();
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            db_transaction(function (PDO $pdo) use ($id, $days) {
                $o = order_lock($pdo, (int)$id);
                if ($o['status'] !== 'shipped' || (int)$o['auto_complete_paused'] === 1) { return; }
                if (strtotime($o['shipped_at']) > time() - $days * 86400) { return; }
                order_complete($pdo, $o, 'system', null, '系統自動完成（出貨滿 ' . $days . ' 天）');
            });
            $n++;
        } catch (Throwable $e) {
            error_record('warning', '自動完成訂單失敗：' . $e->getMessage(), $e->getFile(), $e->getLine(), '');
        }
    }
    return $n;
}

function orders_cancel_expired_payments(int $limit): int
{
    global $pdo;
    $ids = $pdo->query("SELECT id FROM orders WHERE status = 'pending_payment' AND payment_due_at < NOW() ORDER BY payment_due_at LIMIT {$limit}")
        ->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $id) {
        try {
            db_transaction(function (PDO $pdo) use ($id) {
                $o = order_lock($pdo, (int)$id);
                if ($o['status'] !== 'pending_payment' || strtotime($o['payment_due_at']) >= time()) { return; }
                order_cancel($pdo, $o, 'system', null, '逾期未付款，系統自動取消');
            });
            $n++;
        } catch (Throwable $e) {
            error_record('warning', '取消逾期未付款訂單失敗：' . $e->getMessage(), $e->getFile(), $e->getLine(), '');
        }
    }
    return $n;
}

// ---------------------------------------------------------------------
// 售後單（第 12、13 節）
// ---------------------------------------------------------------------
/**
 * 會員申請退貨或換貨。$items：[order_item_id => qty]；$snap：換貨寄送地址快照
 * 訂單為已出貨（宅配）時，同一筆交易內先完成訂單（完成者為會員）。
 */
function after_sale_create(PDO $pdo, int $memberId, int $orderId, string $type, array $items, string $reason, string $desc, ?array $snap): array
{
    if (!isset(after_sale_type_labels()[$type])) { throw new UserError('申請類型不正確。'); }
    if (!isset(after_sale_reason_labels()[$reason])) { throw new UserError('請選擇原因。'); }
    if (mb_strlen($desc) > 500) { throw new UserError('說明最多 500 字。'); }
    $o = order_lock($pdo, $orderId, $memberId);
    if (($why = order_after_sale_block($o)) !== null) { throw new UserError($why); }
    if ($o['status'] === 'shipped') {
        $o = order_complete($pdo, $o, 'member', $memberId, '會員申請' . after_sale_type_labels()[$type] . '，訂單先完成');
    }
    $avail = order_item_available($pdo, $orderId);
    $clean = [];
    foreach ($items as $oiId => $q) {
        $oiId = (int)$oiId;
        $q = (int)$q;
        if ($q <= 0) { continue; }
        if (!isset($avail[$oiId])) { throw new UserError('品項不屬於此訂單。'); }
        if ($q > $avail[$oiId]) { throw new UserError('申請數量超過可申請數量。'); }
        $clean[$oiId] = $q;
    }
    if (!$clean) { throw new UserError('請至少選擇一個品項與數量。'); }

    $oItems = [];
    foreach (order_items($orderId) as $it) { $oItems[(int)$it['id']] = $it; }
    $estimate = 0;
    foreach ($clean as $oiId => $q) { $estimate += dec_to_cents($oItems[$oiId]['unit_price']) * $q; }
    $includesShipping = 0;
    if ($type === 'return') {
        // 整單退貨（含分次退完）：本次申請後，每個品項的「已退完成＋進行中退貨＋本次」都等於購買數量時，加退運費與手續費
        $all = true;
        $st = $pdo->prepare("SELECT COALESCE(SUM(asi.quantity),0) FROM after_sale_items asi JOIN after_sales a ON a.id = asi.after_sale_id
                              WHERE asi.order_item_id = ? AND a.type = 'return' AND a.status IN ('requested','in_progress')");
        foreach ($oItems as $id => $it) {
            $st->execute([$id]);
            $returning = (int)$it['returned_qty'] + (int)$st->fetchColumn() + ($clean[$id] ?? 0);
            if ($returning < (int)$it['quantity']) { $all = false; break; }
        }
        // 已有其他售後單退過運費時不重複計入
        $st = $pdo->prepare("SELECT COUNT(*) FROM after_sales WHERE order_id = ? AND includes_shipping = 1 AND status IN ('requested','in_progress','completed')");
        $st->execute([$orderId]);
        if ((int)$st->fetchColumn() > 0) { $all = false; }
        if ($all) {
            $estimate += dec_to_cents($o['shipping_fee']) + dec_to_cents($o['cod_fee']);
            $includesShipping = 1;
        }
    }
    $no = next_doc_no($pdo, 'R');
    $snap = $type === 'exchange' ? $snap : null;
    $pdo->prepare('INSERT INTO after_sales (as_no, order_id, member_id, type, reason, description, ship_name, ship_phone, ship_zip, ship_city,
                          ship_district, ship_address, refund_estimate, includes_shipping)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$no, $orderId, $memberId, $type, $reason, $desc === '' ? null : $desc,
                   $snap['ship_name'] ?? null, $snap['ship_phone'] ?? null, $snap['ship_zip'] ?? null, $snap['ship_city'] ?? null,
                   $snap['ship_district'] ?? null, $snap['ship_address'] ?? null,
                   $type === 'return' ? cents_to_dec($estimate) : null, $includesShipping]);
    $asId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO after_sale_items (after_sale_id, order_item_id, quantity) VALUES (?, ?, ?)');
    foreach ($clean as $oiId => $q) { $ins->execute([$asId, $oiId, $q]); }
    order_event($pdo, $orderId, 'after_sale', null, null, 'member', $memberId,
        '申請' . after_sale_type_labels()[$type] . '（售後單 ' . $no . '）');
    return ['id' => $asId, 'no' => $no, 'estimate' => cents_to_dec($estimate), 'includes_shipping' => $includesShipping];
}

function after_sale_lock(PDO $pdo, int $asId): array
{
    $st = $pdo->prepare('SELECT order_id FROM after_sales WHERE id = ?');
    $st->execute([$asId]);
    $oid = $st->fetchColumn();
    if ($oid === false) { throw new UserError('找不到此售後單。'); }
    $o = order_lock($pdo, (int)$oid);
    $st = $pdo->prepare('SELECT * FROM after_sales WHERE id = ? FOR UPDATE');
    $st->execute([$asId]);
    $a = $st->fetch();
    $it = $pdo->prepare('SELECT * FROM after_sale_items WHERE after_sale_id = ?');
    $it->execute([$asId]);
    $a['items'] = $it->fetchAll();
    return [$o, $a];
}

function after_sale_update(PDO $pdo, array $a, array $fields): void
{
    $set = [];
    $args = [];
    foreach ($fields as $k => $v) { $set[] = "`{$k}` = ?"; $args[] = $v; }
    array_push($args, $a['id'], $a['status'], $a['row_version']);
    $st = $pdo->prepare('UPDATE after_sales SET ' . implode(', ', $set) . ', row_version = row_version + 1 WHERE id = ? AND status = ? AND row_version = ?');
    $st->execute($args);
    if ($st->rowCount() !== 1) { throw new UserError('售後單狀態已變更，請重新整理後再操作。'); }
}

function after_sale_item_map(array $a): array
{
    $m = [];
    foreach ($a['items'] as $i) { $m[(int)$i['order_item_id']] = (int)$i['quantity']; }
    return $m;
}

/** 客服核准：退貨產生「退貨收回」收件單；換貨另產生「暫緩」的換貨出貨單 */
function after_sale_approve(PDO $pdo, int $asId, int $actorId): void
{
    [$o, $a] = after_sale_lock($pdo, $asId);
    if ($a['status'] !== 'requested') { throw new UserError('只有申請中的售後單可以核准。'); }
    after_sale_update($pdo, $a, ['status' => 'in_progress', 'approved_at' => date('Y-m-d H:i:s')]);
    $map = after_sale_item_map($a);
    receipt_create($pdo, $o, $a['type'], $asId, $map);
    if ($a['type'] === 'exchange') {
        shipment_create($pdo, $o, 'exchange', 'on_hold', $asId, $map, $a);
    }
    order_event($pdo, (int)$o['id'], 'after_sale', null, null, 'admin', $actorId, '售後單 ' . $a['as_no'] . ' 已核准，請寄回商品');
    defer_mail(function () use ($asId) { mail_after_sale_approved($asId); });
}

function after_sale_reject(PDO $pdo, int $asId, int $actorId, string $reason): void
{
    [$o, $a] = after_sale_lock($pdo, $asId);
    if ($a['status'] !== 'requested') { throw new UserError('只有申請中的售後單可以拒絕。'); }
    if (!len_between($reason, 1, 200)) { throw new UserError('請填寫 1～200 字的拒絕原因。'); }
    after_sale_update($pdo, $a, ['status' => 'rejected', 'reject_reason' => $reason]);
    order_event($pdo, (int)$o['id'], 'after_sale', null, null, 'admin', $actorId, '售後單 ' . $a['as_no'] . ' 未核准：' . $reason);
    defer_mail(function () use ($asId) { mail_after_sale_rejected($asId); });
}

function after_sale_withdraw(PDO $pdo, int $asId, int $memberId): void
{
    [$o, $a] = after_sale_lock($pdo, $asId);
    if ((int)$a['member_id'] !== $memberId) { throw new UserError('找不到此售後單。'); }
    if ($a['status'] !== 'requested') { throw new UserError('只有申請審核中的售後單可以撤回。'); }
    after_sale_update($pdo, $a, ['status' => 'withdrawn']);
    order_event($pdo, (int)$o['id'], 'after_sale', null, null, 'member', $memberId, '售後單 ' . $a['as_no'] . ' 已撤回');
}

/** 會員核准後選填寄回物流商與單號 */
function after_sale_set_tracking(PDO $pdo, int $asId, int $memberId, string $carrier, string $tracking): void
{
    [$o, $a] = after_sale_lock($pdo, $asId);
    if ((int)$a['member_id'] !== $memberId || $a['status'] !== 'in_progress') { throw new UserError('此售後單目前不能填寫寄回單號。'); }
    if (mb_strlen($carrier) > 20 || mb_strlen($tracking) > 40) { throw new UserError('物流商最多 20 字、單號最多 40 字。'); }
    after_sale_update($pdo, $a, ['return_carrier' => $carrier === '' ? null : $carrier, 'return_tracking' => $tracking === '' ? null : $tracking]);
    order_event($pdo, (int)$o['id'], 'after_sale', null, null, 'member', $memberId, '售後單 ' . $a['as_no'] . ' 已填寫寄回單號');
}

/**
 * 倉庫收件驗收：逐項選良品回庫或瑕疵不回庫
 * - 換貨收回：換貨出貨單由暫緩轉待出貨
 * - 拒收退回：線上已付款者模擬退款
 */
function receipt_receive(PDO $pdo, int $receiptId, int $actorId, array $conditions): void
{
    $st = $pdo->prepare('SELECT order_id, after_sale_id FROM return_receipts WHERE id = ?');
    $st->execute([$receiptId]);
    $ref = $st->fetch();
    if (!$ref) { throw new UserError('找不到此收件單。'); }
    $o = order_lock($pdo, (int)$ref['order_id']);
    $a = null;
    if ($ref['after_sale_id'] !== null) { [$o, $a] = after_sale_lock($pdo, (int)$ref['after_sale_id']); }
    $st = $pdo->prepare('SELECT * FROM return_receipts WHERE id = ? FOR UPDATE');
    $st->execute([$receiptId]);
    $r = $st->fetch();
    if ($r['status'] !== 'pending') { throw new UserError('此收件單已驗收。'); }
    $st = $pdo->prepare('SELECT rri.*, oi.variant_id FROM return_receipt_items rri JOIN order_items oi ON oi.id = rri.order_item_id WHERE rri.receipt_id = ?');
    $st->execute([$receiptId]);
    $items = $st->fetchAll();
    $restock = [];
    $up = $pdo->prepare('UPDATE return_receipt_items SET item_condition = ?, restocked = ? WHERE id = ?');
    foreach ($items as $it) {
        $c = $conditions[$it['id']] ?? '';
        if ($c !== 'good' && $c !== 'defective') { throw new UserError('請逐項選擇「良品回庫」或「瑕疵不回庫」。'); }
        $up->execute([$c, $c === 'good' ? 1 : 0, $it['id']]);
        if ($c === 'good') { $restock[(int)$it['variant_id']] = ($restock[(int)$it['variant_id']] ?? 0) + (int)$it['quantity']; }
    }
    $st = $pdo->prepare("UPDATE return_receipts SET status = 'received', received_at = NOW(), received_by = ?, row_version = row_version + 1
                          WHERE id = ? AND status = 'pending' AND row_version = ?");
    $st->execute([$actorId, $receiptId, $r['row_version']]);
    if ($st->rowCount() !== 1) { throw new UserError('收件單狀態已變更，請重新整理。'); }
    if ($r['type'] === 'exchange' && $a) {
        $pdo->prepare("UPDATE shipments SET status = 'pending', row_version = row_version + 1 WHERE after_sale_id = ? AND status = 'on_hold'")
            ->execute([$a['id']]);
    }
    stock_apply_items($pdo, $restock, false);
    order_event($pdo, (int)$o['id'], 'receipt', null, null, 'admin', $actorId,
        $r['type'] === 'refused' ? '拒收退回商品已驗收' : '已收到寄回商品', '收件單 ' . $r['receipt_no'] . ' 驗收完成');
    if ($r['type'] === 'refused' && $o['payment_status'] === 'paid' && in_array($o['payment_method'], ['sim_card', 'sim_paypal'], true)) {
        payment_refund_sim($pdo, $o, $o['total']);
        order_update($pdo, $o, ['payment_status' => 'refunded', 'refunded_total' => $o['total']]);
        order_event($pdo, (int)$o['id'], 'refund', null, null, 'system', null, '已以原付款方式模擬退款 ' . money($o['total']));
        $oid = (int)$o['id'];
        $amt = $o['total'];
        defer_mail(function () use ($oid, $amt) { mail_payment_refunded($oid, $amt); });
    }
}

/**
 * 登錄退款並完成退貨（驗收後才可操作）：累積消費扣除退回的商品金額，允許降級
 * 線上已付款訂單以原付款方式模擬退款
 */
function after_sale_refund(PDO $pdo, int $asId, int $actorId, string $amount, string $method, string $date, string $note): void
{
    [$o, $a] = after_sale_lock($pdo, $asId);
    if ($a['type'] !== 'return' || $a['status'] !== 'in_progress') { throw new UserError('只有處理中的退貨售後單可以登錄退款。'); }
    $st = $pdo->prepare("SELECT status FROM return_receipts WHERE after_sale_id = ? FOR UPDATE");
    $st->execute([$asId]);
    if ($st->fetchColumn() !== 'received') { throw new UserError('倉庫驗收退貨後才能登錄退款。'); }
    $amt = parse_price($amount);
    if ($amt === null) { throw new UserError('退款金額格式不正確。'); }
    $online = in_array($o['payment_method'], ['sim_card', 'sim_paypal'], true) && in_array($o['payment_status'], ['paid', 'partially_refunded'], true);
    if ($online) { $method = '原付款方式模擬退回'; }
    if (!len_between($method, 1, 30)) { throw new UserError('請填寫退款方式（最多 30 字）。'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { throw new UserError('退款日期格式不正確。'); }
    if (mb_strlen($note) > 200) { throw new UserError('備註最多 200 字。'); }
    $remain = dec_to_cents($o['total']) - dec_to_cents($o['refunded_total']);
    if (dec_to_cents($amt) > $remain) { throw new UserError('退款金額超過訂單可退金額 ' . money(cents_to_dec($remain)) . '。'); }

    after_sale_update($pdo, $a, ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'refund_amount' => $amt,
        'refund_method' => $method, 'refund_date' => $date, 'refund_note' => $note === '' ? null : $note]);
    $itemsCents = 0;
    $oItems = [];
    foreach (order_items((int)$o['id']) as $it) { $oItems[(int)$it['id']] = $it; }
    $up = $pdo->prepare('UPDATE order_items SET returned_qty = returned_qty + ? WHERE id = ? AND returned_qty + ? <= quantity');
    foreach (after_sale_item_map($a) as $oiId => $q) {
        $up->execute([$q, $oiId, $q]);
        if ($up->rowCount() !== 1) { throw new UserError('退貨數量超過購買數量。'); }
        $itemsCents += dec_to_cents($oItems[$oiId]['unit_price']) * $q;
    }
    $newRefunded = dec_to_cents($o['refunded_total']) + dec_to_cents($amt);
    $fields = ['refunded_total' => cents_to_dec($newRefunded)];
    if ($online) {
        payment_refund_sim($pdo, $o, $amt);
        $fields['payment_status'] = $newRefunded >= dec_to_cents($o['total']) ? 'refunded' : 'partially_refunded';
    }
    order_update($pdo, $o, $fields);
    member_spend_apply($pdo, (int)$o['member_id'], -$itemsCents, 'return_completed', true, (int)$o['id'], $asId, $actorId);
    order_event($pdo, (int)$o['id'], 'after_sale', null, null, 'admin', $actorId,
        '退貨完成，退款 ' . money($amt) . '（' . $method . '，' . $date . '）', $note === '' ? null : $note);
    defer_mail(function () use ($asId) { mail_return_completed($asId); });
}

// ---------------------------------------------------------------------
// 出貨單（第 13、31 節）
// ---------------------------------------------------------------------
function shipment_lock(PDO $pdo, int $shipmentId): array
{
    $st = $pdo->prepare('SELECT order_id, after_sale_id FROM shipments WHERE id = ?');
    $st->execute([$shipmentId]);
    $ref = $st->fetch();
    if (!$ref) { throw new UserError('找不到此出貨單。'); }
    $o = order_lock($pdo, (int)$ref['order_id']);
    $a = null;
    if ($ref['after_sale_id'] !== null) { [$o, $a] = after_sale_lock($pdo, (int)$ref['after_sale_id']); }
    $st = $pdo->prepare('SELECT * FROM shipments WHERE id = ? FOR UPDATE');
    $st->execute([$shipmentId]);
    return [$o, $a, $st->fetch()];
}

function shipment_update(PDO $pdo, array $s, array $fields): void
{
    $set = [];
    $args = [];
    foreach ($fields as $k => $v) { $set[] = "`{$k}` = ?"; $args[] = $v; }
    array_push($args, $s['id'], $s['status'], $s['row_version']);
    $st = $pdo->prepare('UPDATE shipments SET ' . implode(', ', $set) . ', row_version = row_version + 1 WHERE id = ? AND status = ? AND row_version = ?');
    $st->execute($args);
    if ($st->rowCount() !== 1) { throw new UserError('出貨單狀態已變更，請重新整理後再操作。'); }
}

/** 出貨：訂單出貨單 → 訂單已出貨；換貨出貨單 → 扣庫存並完成換貨 */
function shipment_ship(PDO $pdo, int $shipmentId, int $actorId, string $carrier, string $tracking, string $date): void
{
    [$o, $a, $s] = shipment_lock($pdo, $shipmentId);
    if ($s['status'] !== 'pending') { throw new UserError('只有待出貨的出貨單可以出貨。'); }
    if ($s['shipping_method'] === 'cvs') {
        $carrier = 'cvs_' . $s['cvs_chain'];
    } elseif (!isset(carrier_labels()[$carrier])) {
        throw new UserError('請選擇物流商。');
    }
    if (!len_between($tracking, 1, 40)) { throw new UserError('請填寫 1～40 字的追蹤碼。'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { throw new UserError('出貨日格式不正確。'); }
    $shippedAt = $date . ' ' . date('H:i:s');
    if ($s['type'] === 'order') {
        if ($o['status'] !== 'processing') { throw new UserError('訂單狀態不是處理中，不能出貨。'); }
        shipment_update($pdo, $s, ['status' => 'shipped', 'carrier' => $carrier, 'tracking_no' => $tracking, 'shipped_at' => $shippedAt]);
        order_update($pdo, $o, ['status' => 'shipped', 'shipped_at' => $shippedAt]);
        order_event($pdo, (int)$o['id'], 'status', 'processing', 'shipped', 'admin', $actorId,
            '已出貨（' . shipment_carrier_label($carrier) . '，追蹤碼 ' . $tracking . '）', '出貨單 ' . $s['shipment_no']);
        $oid = (int)$o['id'];
        defer_mail(function () use ($oid) { mail_order_shipped($oid); });
    } else {
        if (!$a || $a['status'] !== 'in_progress') { throw new UserError('換貨售後單狀態不正確。'); }
        $st = $pdo->prepare('SELECT si.quantity, oi.variant_id FROM shipment_items si JOIN order_items oi ON oi.id = si.order_item_id WHERE si.shipment_id = ?');
        $st->execute([$shipmentId]);
        $byVariant = [];
        foreach ($st->fetchAll() as $r) { $byVariant[(int)$r['variant_id']] = ($byVariant[(int)$r['variant_id']] ?? 0) + (int)$r['quantity']; }
        shipment_update($pdo, $s, ['status' => 'shipped', 'carrier' => $carrier, 'tracking_no' => $tracking, 'shipped_at' => $shippedAt]);
        after_sale_update($pdo, $a, ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);
        stock_apply_items($pdo, $byVariant, true);
        order_event($pdo, (int)$o['id'], 'after_sale', null, null, 'admin', $actorId,
            '換貨新品已寄出（' . shipment_carrier_label($carrier) . '，追蹤碼 ' . $tracking . '）', '售後單 ' . $a['as_no']);
        $asId = (int)$a['id'];
        defer_mail(function () use ($asId) { mail_exchange_shipped($asId); });
    }
}

function shipment_carrier_label(?string $c): string
{
    if ($c === null) { return ''; }
    if (strpos($c, 'cvs_') === 0) { return label_of(cvs_chain_labels(), substr($c, 4)) . '店到店'; }
    return label_of(carrier_labels(), $c);
}

/** 超商：已到店 */
function shipment_cvs_arrive(PDO $pdo, int $shipmentId, int $actorId): void
{
    [$o, , $s] = shipment_lock($pdo, $shipmentId);
    if ($s['shipping_method'] !== 'cvs' || $s['status'] !== 'shipped') { throw new UserError('只有已出貨的超商出貨單可以標記到店。'); }
    $days = max(1, setting_int('cvs_pickup_days', 7));
    $deadline = date('Y-m-d', strtotime("+{$days} days"));
    shipment_update($pdo, $s, ['status' => 'arrived', 'arrived_at' => date('Y-m-d H:i:s'), 'pickup_deadline' => $deadline]);
    order_event($pdo, (int)$o['id'], 'cvs', null, null, 'admin', $actorId, '商品已到店，請於 ' . $deadline . ' 前取貨');
    $oid = (int)$o['id'];
    defer_mail(function () use ($oid, $deadline) { mail_cvs_arrived($oid, $deadline); });
}

/** 超商：已取貨 → 訂單完成（取貨付款同時轉已付款） */
function shipment_cvs_pickup(PDO $pdo, int $shipmentId, string $actorType, ?int $actorId): void
{
    [$o, , $s] = shipment_lock($pdo, $shipmentId);
    if ($s['shipping_method'] !== 'cvs' || $s['status'] !== 'arrived') { throw new UserError('只有已到店的超商出貨單可以標記取貨。'); }
    if ($actorType === 'member' && (int)$o['member_id'] !== $actorId) { throw new UserError('找不到此訂單。'); }
    shipment_update($pdo, $s, ['status' => 'picked_up', 'picked_up_at' => date('Y-m-d H:i:s')]);
    order_complete($pdo, $o, $actorType, $actorId, '已在超商取貨，訂單完成');
}

/** 超商：未取貨退回 → 等同拒收，產生拒收退回收件單 */
function shipment_cvs_return(PDO $pdo, int $shipmentId, int $actorId): void
{
    [$o, , $s] = shipment_lock($pdo, $shipmentId);
    if ($s['shipping_method'] !== 'cvs' || $s['status'] !== 'arrived') { throw new UserError('只有已到店的超商出貨單可以標記未取貨退回。'); }
    shipment_update($pdo, $s, ['status' => 'returned']);
    order_refuse($pdo, $o, 'admin', $actorId, '超商未取貨退回');
}
