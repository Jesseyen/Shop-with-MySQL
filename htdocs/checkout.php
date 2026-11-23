<?php
/**
 * 結帳（第 10、31 節）
 * GET：明細、配送與付款方式、收件資料或門市、備註、辨識碼；顯示退換貨期限與公開文件連結
 * POST：同一筆交易內成立訂單、扣庫存、清空購物車；貨到付款產生待出貨的出貨單，線上付款轉模擬付款頁
 * 防重複送出：一次性結帳憑證（30 分鐘），存在訂單唯一鍵；同一憑證再送出只導向已成立的訂單
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = current_member();
if (!$me) { render_forbidden('需要會員登入才能結帳', false); }

/** 啟用中的配送與付款方式 */
function checkout_methods(array $cart): array
{
    global $enable_launch;
    $ship = [];
    $pay = [];
    if (!$enable_launch) {
        return [['home' => '宅配'], ['home' => ['cod' => '貨到付款']]];
    }
    $cvsOk = true;
    foreach ($cart['items'] as $it) { if ((int)$it['allow_cvs'] !== 1) { $cvsOk = false; } }
    if (setting_on('ship_home_enabled')) { $ship['home'] = '宅配'; }
    if (setting_on('ship_cvs_enabled') && $cvsOk) { $ship['cvs'] = '超商取貨'; }
    $online = [];
    if (setting_on('pay_card_enabled')) { $online['sim_card'] = '信用卡（模擬）'; }
    if (setting_on('pay_paypal_enabled')) { $online['sim_paypal'] = 'PayPal（模擬）'; }
    $pay['home'] = (setting_on('pay_cod_enabled') ? ['cod' => '貨到付款'] : []) + $online;
    $pay['cvs'] = (setting_on('pay_cvs_cod_enabled') ? ['cvs_cod' => '超商取貨付款'] : []) + $online;
    return [$ship, $pay, $cvsOk];
}

/** 報價：$items 需含 price、quantity、shipping_rule_id */
function checkout_quote(array $items, string $ship, string $pay): array
{
    $sub = 0;
    $rows = [];
    foreach ($items as $it) {
        $line = dec_to_cents($it['price']) * (int)$it['quantity'];
        $sub += $line;
        $rows[] = ['subtotal' => cents_to_dec($line), 'shipping_rule_id' => $it['shipping_rule_id']];
    }
    $sh = shipping_calc($rows, $ship);
    $cod = in_array($pay, ['cod', 'cvs_cod'], true) ? dec_to_cents(setting('cod_fee', '0')) : 0;
    $total = $sub + dec_to_cents($sh['fee']) + $cod;
    return ['subtotal' => cents_to_dec($sub), 'shipping_fee' => $sh['fee'], 'rule_name' => $sh['rule_name'],
            'cod_fee' => cents_to_dec($cod), 'total' => cents_to_dec($total)];
}

// =====================================================================
// POST：成立訂單
// =====================================================================
if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $keep = ['ship' => post_str('shipping_method'), 'pay' => post_str('payment_method'), 'address_id' => post_str('address_id'),
             'note' => post_str('note'), 'store_id' => post_str('store_id'), 'pickup_name' => post_str('pickup_name'),
             'pickup_phone' => post_str('pickup_phone'), 'new' => array_map('strval', post_arr('new'))];
    $token = post_str('checkout_token');
    $fail = function (string $msg) use ($keep) {
        flash('error', $msg);
        $_SESSION['_checkout_keep'] = $keep;
        redirect('checkout.php');
    };
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { $fail('結帳頁已失效，請重新確認。'); }
    $tokenHash = hash('sha256', $token);
    $st = $pdo->prepare('SELECT id FROM orders WHERE checkout_token = ? AND member_id = ?');
    $st->execute([$tokenHash, $me['id']]);
    if ($exist = $st->fetchColumn()) { redirect('order_done.php?id=' . (int)$exist); }
    try { captcha_check('checkout'); } catch (UserError $e) { $fail($e->getMessage()); }
    $issued = $_SESSION['_checkout_tokens'][$tokenHash] ?? 0;
    if ($issued < time() - 1800) { $fail('結帳頁已超過 30 分鐘，請重新確認金額後送出。'); }
    if (!public_info_ready()) { $fail('商店資訊尚未設定，暫時無法下單。'); }

    $cart = cart_view($me['id']);
    if (!$cart['items']) { flash('error', '購物車是空的。'); redirect('cart.php'); }
    [$shipM, $payM] = checkout_methods($cart);
    $ship = $keep['ship'];
    $pay = $keep['pay'];
    if (!isset($shipM[$ship])) { $fail('請選擇可用的配送方式。'); }
    if (!isset($payM[$ship][$pay])) { $fail('請選擇可用的付款方式。'); }
    if (mb_strlen($keep['note']) > 200) { $fail('備註最多 200 字。'); }
    $snap = [];
    $store = null;
    $newAddr = null;
    if ($ship === 'home') {
        if ($keep['address_id'] === 'new') {
            [$newAddr, $errs] = address_validate($keep['new']);
            if ($errs) { $fail(implode(' ', $errs)); }
            $snap = address_snapshot($newAddr);
        } else {
            $a = to_pos_int($keep['address_id']) !== null ? address_find($me['id'], (int)$keep['address_id']) : null;
            if (!$a) { $fail('選取的收件資料已不存在，請重新選擇。'); }
            $snap = address_snapshot($a);
        }
    } else {
        $sid = to_pos_int($keep['store_id']);
        $st = $pdo->prepare('SELECT * FROM cvs_stores WHERE id = ? AND is_active = 1');
        $st->execute([$sid ?? 0]);
        $store = $st->fetch();
        if (!$store) { $fail('請選擇取貨門市。'); }
        if (!len_between($keep['pickup_name'], 1, 30)) { $fail('取件人姓名須為 1～30 字（需與證件相同）。'); }
        if (!preg_match('/^09[0-9]{8}$/', $keep['pickup_phone'])) { $fail('取件人手機須為 09 開頭的 10 位數字。'); }
        $snap = ['ship_name' => $keep['pickup_name'], 'ship_phone' => $keep['pickup_phone']];
    }
    $expected = parse_price(post_str('expected_total'));
    if (!empty($app_debug) && !empty($_SERVER['HTTP_X_TEST_FAIL_EVENT'])) { $GLOBALS['_test_fail_order_event'] = true; }

    try {
        $orderId = db_transaction(function (PDO $pdo) use ($me, $ship, $pay, $snap, $store, $newAddr, $keep, $tokenHash, $expected) {
            $pdo->prepare('SELECT id FROM members WHERE id = ? AND status = 1 AND deleted_at IS NULL FOR UPDATE')->execute([$me['id']]);
            $cart = cart_lock_cart($pdo, $me['id']);
            $st = $pdo->prepare('SELECT variant_id FROM cart_items WHERE cart_id = ? ORDER BY variant_id');
            $st->execute([$cart['id']]);
            $vids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!$vids) { throw new UserError('購物車是空的。'); }
            $locked = [];
            foreach ($vids as $vid) { $locked[(int)$vid] = lock_variant($pdo, (int)$vid, true); }
            $st = $pdo->prepare('SELECT id, variant_id, quantity FROM cart_items WHERE cart_id = ? ORDER BY id FOR UPDATE');
            $st->execute([$cart['id']]);
            $items = [];
            foreach ($st->fetchAll() as $ci) {
                $p = $locked[(int)$ci['variant_id']] ?? null;
                if (!$p) { throw new UserError('購物車內容已變更，請重新確認。'); }
                $label = $p['name'] . ($p['variant_text'] !== '' ? '（' . $p['variant_text'] . '）' : '');
                if ((int)$p['is_active'] !== 1 || $p['variant_active'] !== 1) { throw new UserError("「{$label}」已下架或停售，請先從購物車移除。"); }
                if ((int)$ci['quantity'] > $p['stock']) { throw new UserError("「{$label}」庫存不足（目前 {$p['stock']} 件），請調整數量。"); }
                if ($ship === 'cvs' && (int)$p['allow_cvs'] !== 1) { throw new UserError("「{$label}」不提供超商取貨。"); }
                $items[] = ['cart_item_id' => (int)$ci['id'], 'variant_id' => (int)$ci['variant_id'], 'quantity' => (int)$ci['quantity'],
                            'price' => $p['unit_price'], 'shipping_rule_id' => $p['shipping_rule_id'], 'p' => $p];
            }
            $q = checkout_quote($items, $ship, $pay);
            if ($expected === null || dec_to_cents($expected) !== dec_to_cents($q['total'])) {
                throw new UserError('商品價格或運費已變動，新的訂單總額為 ' . money($q['total']) . '，請確認後再送出。');
            }
            if ($newAddr !== null) { address_insert($pdo, $me['id'], $newAddr, false); }
            $online = is_online_payment_method($pay);
            $no = next_doc_no($pdo, 'S');
            $timeout = max(10, min(1440, setting_int('payment_timeout_minutes', 30)));
            $pdo->prepare('INSERT INTO orders (order_no, member_id, status, checkout_token, shipping_method, payment_method, payment_status,
                                  payment_due_at, ship_name, ship_phone, ship_zip, ship_city, ship_district, ship_address,
                                  cvs_chain, cvs_store_code, cvs_store_name, cvs_store_address, note,
                                  subtotal, shipping_fee, shipping_rule_name, cod_fee, total)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$no, $me['id'], $online ? 'pending_payment' : 'processing', $tokenHash, $ship, $pay,
                           $online ? 'pending' : 'cod_unpaid', $online ? date('Y-m-d H:i:s', time() + $timeout * 60) : null,
                           $snap['ship_name'] ?? null, $snap['ship_phone'] ?? null, $snap['ship_zip'] ?? null, $snap['ship_city'] ?? null,
                           $snap['ship_district'] ?? null, $snap['ship_address'] ?? null,
                           $store['chain'] ?? null, $store['store_code'] ?? null, $store['name'] ?? null,
                           $store ? $store['city'] . ' ' . $store['address'] : null,
                           $keep['note'] === '' ? null : $keep['note'],
                           $q['subtotal'], $q['shipping_fee'], $q['rule_name'], $q['cod_fee'], $q['total']]);
            $orderId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, variant_id, product_name, sku, variant_text, category_name,
                                         brand, unit_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $byItem = [];
            foreach ($items as $it) {
                $p = $it['p'];
                $cat = $p['category_id'] !== null ? (category_find((int)$p['category_id'])['name'] ?? null) : null;
                $ins->execute([$orderId, $p['id'], $it['variant_id'], $p['name'], $p['sku'], $p['variant_text'] === '' ? null : $p['variant_text'],
                               $cat, $p['brand'], $it['price'], $it['quantity'], cents_to_dec(dec_to_cents($it['price']) * $it['quantity'])]);
                $byItem[(int)$pdo->lastInsertId()] = $it['quantity'];
                $up = $pdo->prepare('UPDATE variant_inventory SET quantity = quantity - ?, row_version = row_version + 1 WHERE variant_id = ? AND quantity >= ?');
                $up->execute([$it['quantity'], $it['variant_id'], $it['quantity']]);
                if ($up->rowCount() !== 1) { throw new UserError('庫存不足，請重新確認。'); }
            }
            $o = ['id' => $orderId, 'shipping_method' => $ship, 'cvs_chain' => $store['chain'] ?? null, 'cvs_store_code' => $store['store_code'] ?? null,
                  'cvs_store_name' => $store['name'] ?? null, 'cvs_store_address' => $store ? $store['city'] . ' ' . $store['address'] : null];
            order_event($pdo, $orderId, 'created', null, $online ? 'pending_payment' : 'processing', 'member', $me['id'],
                '成立訂單（' . label_of(payment_method_labels(), $pay) . '）');
            if (!$online) {
                shipment_create($pdo, $o, 'order', 'pending', null, $byItem, $snap);
                defer_mail(function () use ($orderId) { mail_order_created($orderId); });
            }
            // 清空購物車（不寫入 remove_from_cart 事件），價格批次剩餘量歸零
            $ids = array_column($items, 'cart_item_id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($GLOBALS['enable_behavior']) {
                $pdo->prepare("UPDATE cart_price_lots SET remaining_quantity = 0 WHERE cart_item_id IN ({$in})")->execute($ids);
            }
            $pdo->prepare("DELETE FROM cart_items WHERE id IN ({$in})")->execute($ids);
            $pdo->prepare('UPDATE carts SET updated_at = NOW() WHERE id = ?')->execute([$cart['id']]);
            return $orderId;
        });
    } catch (UserError $e) {
        $fail($e->getMessage());
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && strpos($e->getMessage(), 'checkout_token') !== false) {
            $st = $pdo->prepare('SELECT id FROM orders WHERE checkout_token = ?');
            $st->execute([$tokenHash]);
            redirect('order_done.php?id=' . (int)$st->fetchColumn());
        }
        throw $e;
    }
    unset($_SESSION['_checkout_tokens'][$tokenHash], $_SESSION['_checkout_keep']);
    if (is_online_payment_method($pay)) {
        $url = db_transaction(function (PDO $pdo) use ($orderId) { return payment_start($pdo, order_lock($pdo, $orderId)); });
        redirect($url);
    }
    redirect('order_done.php?id=' . $orderId);
}

function is_online_payment_method(string $m): bool
{
    return in_array($m, ['sim_card', 'sim_paypal'], true);
}

// =====================================================================
// GET：結帳頁
// =====================================================================
$cart = cart_view($me['id']);
if (!$cart['items']) { flash('info', '購物車是空的。'); redirect('cart.php'); }
[$shipM, $payM, $cvsOk] = checkout_methods($cart) + [2 => true];
$keep = $_SESSION['_checkout_keep'] ?? [];
unset($_SESSION['_checkout_keep']);
$addresses = address_list($me['id']);
$ship = $keep['ship'] ?? array_key_first($shipM);
if (!isset($shipM[$ship])) { $ship = array_key_first($shipM); }
$pay = $keep['pay'] ?? ($ship ? array_key_first($payM[$ship] ?? []) : null);
if ($ship && !isset($payM[$ship][$pay])) { $pay = array_key_first($payM[$ship] ?? []); }

// 各組合的報價（前端切換時顯示）
$quotes = [];
foreach ($shipM as $s => $_) {
    foreach (($payM[$s] ?? []) as $pm => $_l) { $quotes[$s . '|' . $pm] = checkout_quote($cart['items'], $s, $pm); }
}
$q = $ship && $pay ? $quotes[$ship . '|' . $pay] : checkout_quote($cart['items'], 'home', 'cod');
$problems = array_filter(array_map(function ($it) {
    return $it['problem'] === null ? null : $it['name'] . ($it['variant_text'] ? '（' . $it['variant_text'] . '）' : '') . '：' . $it['problem'];
}, $cart['items']));
$infoReady = public_info_ready();
$canSubmit = !$problems && $infoReady && $ship && $pay;

[$token, $tokenHash] = new_token();
$_SESSION['_checkout_tokens'] = array_filter($_SESSION['_checkout_tokens'] ?? [], function ($t) { return $t > time() - 1800; });
$_SESSION['_checkout_tokens'][$tokenHash] = time();

$stores = [];
if ($enable_launch && isset($shipM['cvs'])) {
    $stores = $pdo->query('SELECT * FROM cvs_stores WHERE is_active = 1 ORDER BY chain, city, name')->fetchAll();
}
$opts = ['page_type' => 'checkout'];
if ($enable_launch) {
    $items = [];
    foreach ($cart['items'] as $i => $it) { $items[] = dl_item($it, (int)$it['quantity'], $i + 1); }
    $opts['events'] = [dl_event('begin_checkout', ['currency' => 'TWD', 'value' => dl_items_value($items), 'items' => $items])];
}
page_header('結帳', $opts);
$new = $keep['new'] ?? [];
$selAddr = $keep['address_id'] ?? ($addresses ? (string)$addresses[0]['id'] : 'new');
?>
<h1>結帳</h1>
<?php if (!$infoReady): ?><div class="alert alert-error">商店資訊尚未設定，暫時無法下單。</div><?php endif; ?>
<?php if ($problems): ?>
  <div class="alert alert-error">購物車有無法購買的品項，請回到購物車調整：<ul><?php foreach ($problems as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul>
  <a href="cart.php">回到購物車</a></div>
<?php endif; ?>
<form method="post" action="checkout.php" class="checkout" id="checkout-form">
  <?= csrf_field() ?>
  <input type="hidden" name="checkout_token" value="<?= h($token) ?>">
  <input type="hidden" name="expected_total" id="expected-total" value="<?= h($q['total']) ?>">
  <section class="card">
    <h2>訂購明細</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>商品</th><th>規格</th><th class="num">單價</th><th class="num">數量</th><th class="num">小計</th></tr></thead>
      <tbody><?php foreach ($cart['items'] as $it): ?>
        <tr><td><?= h($it['name']) ?><?= $it['problem'] ? ' ' . badge($it['problem'], 'warn') : '' ?></td><td><?= h($it['variant_text'] ?: '—') ?></td>
          <td class="num"><?= money($it['price']) ?></td><td class="num"><?= (int)$it['quantity'] ?></td><td class="num"><?= money($it['subtotal']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <p class="muted small">金額以送出訂單當下的售價為準；若價格或運費變動，系統會停止送出並請您確認新金額。</p>
  </section>

  <section class="card">
    <h2>配送方式</h2>
    <?php if (!$shipM): ?><p class="alert alert-error">目前沒有可用的配送方式。</p><?php endif; ?>
    <?php foreach ($shipM as $k => $label): ?>
      <label class="check"><input type="radio" name="shipping_method" value="<?= h($k) ?>" <?= $ship === $k ? 'checked' : '' ?> data-ship> <?= h($label) ?></label>
    <?php endforeach; ?>
    <?php if ($enable_launch && !$cvsOk): ?><p class="muted small">購物車內有不提供超商取貨的商品，因此無法選擇超商取貨。</p><?php endif; ?>

    <div data-ship-panel="home" <?= $ship === 'home' ? '' : 'hidden' ?>>
      <h3>收件資料</h3>
      <?php foreach ($addresses as $a): ?>
        <label class="check address-option"><input type="radio" name="address_id" value="<?= (int)$a['id'] ?>" <?= $selAddr === (string)$a['id'] ? 'checked' : '' ?>>
          <?= h($a['recipient']) ?>　<?= h($a['phone']) ?><br><span class="muted"><?= h(address_line($a)) ?></span><?= (int)$a['is_default'] ? ' ' . badge('預設') : '' ?></label>
      <?php endforeach; ?>
      <?php if (count($addresses) < ADDRESS_LIMIT): ?>
      <label class="check"><input type="radio" name="address_id" value="new" <?= $selAddr === 'new' ? 'checked' : '' ?>> 新增收件資料（同時存入收件資料）</label>
      <fieldset class="address-new grid-3">
        <label>收件人 <input type="text" name="new[recipient]" value="<?= h($new['recipient'] ?? '') ?>" maxlength="30"></label>
        <label>電話（建議手機） <input type="tel" name="new[phone]" value="<?= h($new['phone'] ?? '') ?>" maxlength="20"></label>
        <label>郵遞區號 <input type="text" name="new[zip]" value="<?= h($new['zip'] ?? '') ?>" maxlength="6" inputmode="numeric"></label>
        <label>縣市 <input type="text" name="new[city]" value="<?= h($new['city'] ?? '') ?>" maxlength="10"></label>
        <label>鄉鎮區 <input type="text" name="new[district]" value="<?= h($new['district'] ?? '') ?>" maxlength="10"></label>
        <label class="span-all">詳細地址 <input type="text" name="new[address]" value="<?= h($new['address'] ?? '') ?>" maxlength="100"></label>
      </fieldset>
      <?php endif; ?>
    </div>

    <?php if ($stores || isset($shipM['cvs'])): ?>
    <div data-ship-panel="cvs" <?= $ship === 'cvs' ? '' : 'hidden' ?>>
      <h3>取貨門市（模擬）</h3>
      <div class="grid-3">
        <label>超商 <select id="cvs-chain"><option value="">全部</option><?= options_html(cvs_chain_labels(), '') ?></select></label>
        <label>縣市或關鍵字 <input type="search" id="cvs-filter" placeholder="例如：臺北市、信義"></label>
      </div>
      <label>門市 <select name="store_id" id="cvs-store">
        <option value="">請選擇門市</option>
        <?php foreach ($stores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" data-chain="<?= h($s['chain']) ?>" data-text="<?= h($s['city'] . $s['name'] . $s['address']) ?>" <?= ($keep['store_id'] ?? '') === (string)$s['id'] ? 'selected' : '' ?>>
            <?= h(label_of(cvs_chain_labels(), $s['chain']) . '｜' . $s['city'] . '｜' . $s['name'] . '（' . $s['address'] . '）') ?></option>
        <?php endforeach; ?>
      </select></label>
      <div class="grid-3">
        <label>取件人姓名（需與證件相同） <input type="text" name="pickup_name" value="<?= h($keep['pickup_name'] ?? $me['name']) ?>" maxlength="30"></label>
        <label>取件人手機 <input type="tel" name="pickup_phone" value="<?= h($keep['pickup_phone'] ?? '') ?>" maxlength="10" placeholder="09xxxxxxxx"></label>
      </div>
      <p class="muted small">示範網站以內建門市清單代替超商電子地圖。商品到店後請於 <?= setting_int('cvs_pickup_days', 7) ?> 天內取貨。</p>
    </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>付款方式</h2>
    <?php foreach ($payM as $s => $list): ?>
      <div data-ship-panel="<?= h($s) ?>" <?= $ship === $s ? '' : 'hidden' ?>>
        <?php foreach ($list as $k => $label): ?>
          <label class="check"><input type="radio" name="payment_method" value="<?= h($k) ?>" <?= $ship === $s && $pay === $k ? 'checked' : '' ?> <?= $ship === $s ? '' : 'disabled' ?> data-pay> <?= h($label) ?></label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($enable_launch): ?><p class="muted small">信用卡與 PayPal 為模擬付款：不會要求輸入卡號，也不會實際扣款。線上付款需在 <?= setting_int('payment_timeout_minutes', 30) ?> 分鐘內完成，逾時訂單自動取消。</p><?php endif; ?>
  </section>

  <section class="card">
    <h2>金額</h2>
    <dl class="meta totals">
      <dt>商品小計</dt><dd id="q-subtotal"><?= money($q['subtotal']) ?></dd>
      <dt>運費</dt><dd id="q-shipping"><?= money($q['shipping_fee']) ?><?= $q['rule_name'] ? '（' . h($q['rule_name']) . '）' : '' ?></dd>
      <dt>貨到付款手續費</dt><dd id="q-cod"><?= money($q['cod_fee']) ?></dd>
      <dt>訂單總額</dt><dd id="q-total" class="price big"><?= money($q['total']) ?></dd>
    </dl>
    <label>備註（選填，最多 200 字） <textarea name="note" maxlength="200" rows="2"><?= h($keep['note'] ?? '') ?></textarea></label>
    <div class="notice-box">
      <p>收到商品後可在「我的訂單」按「我已收到，完成訂單」，宅配出貨滿 <?= setting_int('auto_complete_days', 3) ?> 天系統自動完成。
         訂單完成後 <strong><?= setting_int('return_days', 7) ?> 天內</strong>（到期日 23:59 前）可在「我的訂單」對整單或個別商品申請退貨或換貨。</p>
      <?php if ($enable_ops): ?><p>送出前請閱讀 <a href="returns.php" target="_blank">退貨條款</a>、<a href="shopping-guide.php" target="_blank">使用者購物須知</a>、<a href="privacy.php" target="_blank">個資保護聲明</a>。</p><?php endif; ?>
    </div>
    <?= captcha_field('checkout') ?>
    <button class="btn btn-primary" type="submit" <?= $canSubmit ? '' : 'disabled' ?>>確認送出訂單</button>
  </section>
</form>
<script type="application/json" id="checkout-data"><?= json_encode(['quotes' => array_map(function ($x) {
    return ['subtotal' => money($x['subtotal']), 'shipping' => money($x['shipping_fee']) . ($x['rule_name'] ? '（' . $x['rule_name'] . '）' : ''),
            'cod' => money($x['cod_fee']), 'total' => money($x['total']), 'total_raw' => $x['total'], 'shipping_raw' => dl_num_safe($x['shipping_fee'])];
}, $quotes), 'shipping_labels' => shipping_method_labels(), 'payment_labels' => payment_method_labels(),
    'items' => $enable_launch ? array_map(function ($it) { return dl_item($it, (int)$it['quantity']); }, $cart['items']) : [],
    'ga' => (bool)$enable_launch], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php
function dl_num_safe($d): float { return dec_to_cents((string)$d) / 100; }
page_footer();
