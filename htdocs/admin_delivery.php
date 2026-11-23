<?php
/**
 * 付款與配送（系統管理者，第 31 節）
 * 1. 配送方式：宅配、超商取貨的啟用、運費與取貨期限
 * 2. 付款方式：貨到付款、超商取貨付款、信用卡（模擬）、PayPal（模擬）的啟用與線上付款期限
 * 3. 模擬門市：篩選、新增、修改、停用
 */
require_once __DIR__ . '/app.php';
if (!$enable_launch) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    $back = 'admin_delivery.php';
    try {
        if ($action === 'shipping') {
            $kv = ['ship_home_enabled' => post_str('ship_home_enabled') === '1' ? '1' : '0',
                   'ship_cvs_enabled' => post_str('ship_cvs_enabled') === '1' ? '1' : '0'];
            if ($kv['ship_home_enabled'] === '0' && $kv['ship_cvs_enabled'] === '0') { throw new UserError('至少需要啟用一種配送方式。'); }
            $fee = parse_price(post_str('cvs_fee'));
            if ($fee === null) { throw new UserError('超商運費格式不正確。'); }
            $th = post_str('cvs_free_threshold');
            if ($th !== '' && parse_price($th) === null) { throw new UserError('超商免運門檻格式不正確（空白代表沒有門檻）。'); }
            $pick = to_pos_int(post_str('cvs_pickup_days'), 30);
            if ($pick === null) { throw new UserError('取貨期限須為 1～30 天。'); }
            $kv += ['cvs_fee' => $fee, 'cvs_free_threshold' => $th, 'cvs_pickup_days' => (string)$pick];
            admin_transaction(function (PDO $pdo, array $a) use ($kv) { settings_save($pdo, $kv, $a['id']); });
            flash('success', '配送方式已儲存。');
            $back .= '#shipping';
        } elseif ($action === 'payment') {
            $kv = [];
            foreach (['pay_cod_enabled', 'pay_cvs_cod_enabled', 'pay_card_enabled', 'pay_paypal_enabled'] as $k) {
                $kv[$k] = post_str($k) === '1' ? '1' : '0';
            }
            $timeout = to_pos_int(post_str('payment_timeout_minutes'), 1440);
            if ($timeout === null || $timeout < 10) { throw new UserError('線上付款期限須為 10～1,440 分鐘。'); }
            $kv['payment_timeout_minutes'] = (string)$timeout;
            // 每種啟用的配送方式至少要有一種付款方式
            $home = setting_on('ship_home_enabled') && !($kv['pay_cod_enabled'] === '1' || $kv['pay_card_enabled'] === '1' || $kv['pay_paypal_enabled'] === '1');
            $cvs = setting_on('ship_cvs_enabled') && !($kv['pay_cvs_cod_enabled'] === '1' || $kv['pay_card_enabled'] === '1' || $kv['pay_paypal_enabled'] === '1');
            if ($home || $cvs) { throw new UserError(($home ? '宅配' : '超商取貨') . '沒有任何可用的付款方式，請至少啟用一種。'); }
            admin_transaction(function (PDO $pdo, array $a) use ($kv) { settings_save($pdo, $kv, $a['id']); });
            flash('success', '付款方式已儲存。');
            $back .= '#payment';
        } else {
            $id = to_pos_int($_POST['id'] ?? '');
            $d = ['chain' => post_str('chain'), 'store_code' => strtoupper(post_str('store_code')), 'name' => post_str('name'),
                  'city' => post_str('city'), 'address' => post_str('address')];
            if ($action !== 'store_toggle') {
                if (!isset(cvs_chain_labels()[$d['chain']])) { throw new UserError('請選擇超商。'); }
                if (!preg_match('/^[A-Za-z0-9]{3,10}$/', $d['store_code'])) { throw new UserError('門市代號須為 3～10 位英數字。'); }
                if (!len_between($d['name'], 1, 50)) { throw new UserError('門市名稱須為 1～50 字。'); }
                if (!len_between($d['city'], 1, 10)) { throw new UserError('縣市須為 1～10 字。'); }
                if (!len_between($d['address'], 1, 150)) { throw new UserError('地址須為 1～150 字。'); }
            }
            admin_transaction(function (PDO $pdo, array $a) use ($action, $id, $d) {
                if ($action === 'store_create') {
                    $pdo->prepare('INSERT INTO cvs_stores (chain, store_code, name, city, address) VALUES (?, ?, ?, ?, ?)')->execute(array_values($d));
                    audit_log($pdo, $a['id'], 'cvs_store', $pdo->lastInsertId(), 'create', null, $d);
                    return;
                }
                $st = $pdo->prepare('SELECT * FROM cvs_stores WHERE id = ? FOR UPDATE');
                $st->execute([$id ?? 0]);
                $row = $st->fetch();
                if (!$row) { throw new UserError('找不到此門市。'); }
                assert_row_version($row, $_POST['row_version'] ?? '');
                if ($action === 'store_toggle') {
                    $pdo->prepare('UPDATE cvs_stores SET is_active = 1 - is_active, row_version = row_version + 1 WHERE id = ?')->execute([$id]);
                } elseif ($action === 'store_update') {
                    $pdo->prepare('UPDATE cvs_stores SET chain = ?, store_code = ?, name = ?, city = ?, address = ?, row_version = row_version + 1 WHERE id = ?')
                        ->execute(array_merge(array_values($d), [$id]));
                } else {
                    throw new UserError('不支援的操作。');
                }
                audit_log($pdo, $a['id'], 'cvs_store', $id, $action, $row, $d);
            });
            flash('success', ['store_create' => '已新增門市。', 'store_update' => '門市已更新。', 'store_toggle' => '門市狀態已變更。'][$action] ?? '已儲存。');
            $back .= '?' . http_build_query(array_filter(['chain' => post_str('f_chain'), 'q' => post_str('f_q')])) . '#stores';
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') { throw $e; }
        flash('error', '同一家超商的門市代號已存在。');
    }
    redirect($back);
}

// ---- 門市清單（篩選） ----
$fChain = get_str('chain');
$fQ = mb_substr(get_str('q'), 0, 50);
$where = [];
$args = [];
if (isset(cvs_chain_labels()[$fChain])) { $where[] = 'chain = ?'; $args[] = $fChain; }
if ($fQ !== '') {
    $where[] = '(name LIKE ? OR city LIKE ? OR address LIKE ? OR store_code LIKE ?)';
    $l = '%' . addcslashes($fQ, '%_\\') . '%';
    array_push($args, $l, $l, $l, $l);
}
$st = $pdo->prepare('SELECT * FROM cvs_stores' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY chain, store_code');
$st->execute($args);
$stores = $st->fetchAll();
$counts = $pdo->query('SELECT chain, SUM(is_active) active, COUNT(*) total FROM cvs_stores GROUP BY chain')->fetchAll(PDO::FETCH_UNIQUE);
$editId = to_pos_int($_GET['edit'] ?? '');
$keepF = '<input type="hidden" name="f_chain" value="' . h($fChain) . '"><input type="hidden" name="f_q" value="' . h($fQ) . '">';

$sw = function (string $key, string $label) {
    return '<label class="switch"><input type="checkbox" name="' . h($key) . '" value="1"' . (setting_on($key) ? ' checked' : '') . '> ' . h($label) . '</label>';
};
$codFee = setting('cod_fee', '0');
admin_header('付款與配送');
?>
<h1>付款與配送</h1>
<p class="muted">線上付款與超商取貨都是模擬流程，不連接真實金流與物流，也不收集卡號。日後要接綠界、藍新等真實金流，請實作 <code>lib/payment.php</code> 的 PaymentGateway 介面。</p>

<form method="post" class="card" id="shipping"><?= csrf_field() ?><input type="hidden" name="action" value="shipping">
  <h2>1. 配送方式</h2>
  <div class="table-wrap"><table class="settings">
    <thead><tr><th>配送方式</th><th>運費</th><th>其他設定</th></tr></thead>
    <tbody>
      <tr><td><?= $sw('ship_home_enabled', '宅配') ?></td>
        <td>依商品的運費規則計算（<a href="admin_shipping_rules.php">管理運費規則</a>）</td>
        <td class="muted small">出貨滿 <?= setting_int('auto_complete_days', 3) ?> 天自動完成（<a href="admin_settings.php">商場設定</a>）</td></tr>
      <tr><td><?= $sw('ship_cvs_enabled', '超商取貨') ?></td>
        <td><div class="inline-fields">
          <label>固定運費 <input type="text" name="cvs_fee" value="<?= h(setting('cvs_fee')) ?>" class="short" inputmode="decimal" required></label>
          <label>滿額免運 <input type="text" name="cvs_free_threshold" value="<?= h(setting('cvs_free_threshold')) ?>" class="short" inputmode="decimal" placeholder="空白＝無"></label>
        </div></td>
        <td><label class="inline-fields">到店後 <input type="number" name="cvs_pickup_days" value="<?= setting_int('cvs_pickup_days', 7) ?>" min="1" max="30" class="short" required> 天內取貨</label></td></tr>
    </tbody>
  </table></div>
  <p class="muted small">商品可在商品編輯頁標記「不可超商取貨」；購物車有這類商品時，結帳頁不顯示超商取貨。</p>
  <button class="btn btn-primary">儲存配送方式</button>
</form>

<form method="post" class="card" id="payment"><?= csrf_field() ?><input type="hidden" name="action" value="payment">
  <h2>2. 付款方式</h2>
  <div class="table-wrap"><table class="settings">
    <thead><tr><th>付款方式</th><th>適用配送</th><th>說明</th></tr></thead>
    <tbody>
      <tr><td><?= $sw('pay_cod_enabled', '貨到付款') ?></td><td>宅配</td><td>手續費 <?= money($codFee) ?>（<a href="admin_settings.php">商場設定</a>）</td></tr>
      <tr><td><?= $sw('pay_cvs_cod_enabled', '超商取貨付款') ?></td><td>超商取貨</td><td>手續費 <?= money($codFee) ?>，取貨時付款</td></tr>
      <tr><td><?= $sw('pay_card_enabled', '信用卡（模擬）') ?></td><td>宅配、超商取貨</td><td rowspan="2">導向模擬付款頁，只有「成功／失敗／取消」三個按鈕；不輸入卡號、不實際扣款</td></tr>
      <tr><td><?= $sw('pay_paypal_enabled', 'PayPal（模擬）') ?></td><td>宅配、超商取貨</td></tr>
    </tbody>
  </table></div>
  <p><label class="inline-fields">線上付款期限：下單後 <input type="number" name="payment_timeout_minutes" value="<?= setting_int('payment_timeout_minutes', 30) ?>" min="10" max="1440" class="short" required> 分鐘內未付款，訂單自動取消並回補庫存（10～1,440 分鐘）</label></p>
  <button class="btn btn-primary">儲存付款方式</button>
</form>

<section class="card" id="stores">
  <h2>3. 模擬門市</h2>
  <p class="muted small">結帳選超商取貨時，會員從這份清單選門市（代替超商電子地圖）。停用的門市不會出現在結帳頁，已成立的訂單不受影響。</p>
  <p><?php foreach (cvs_chain_labels() as $k => $label): $c = $counts[$k] ?? ['active' => 0, 'total' => 0]; ?>
    <?= badge($label . '：啟用 ' . (int)$c['active'] . '／' . (int)$c['total']) ?>
  <?php endforeach; ?></p>

  <h3>新增門市</h3>
  <form method="post" class="store-form"><?= csrf_field() ?><input type="hidden" name="action" value="store_create"><?= $keepF ?>
    <label>超商 <select name="chain" required><option value="">請選擇</option><?= options_html(cvs_chain_labels(), '') ?></select></label>
    <label>門市代號 <input type="text" name="store_code" maxlength="10" required></label>
    <label>門市名稱 <input type="text" name="name" maxlength="50" required></label>
    <label>縣市 <input type="text" name="city" maxlength="10" required></label>
    <label>地址 <input type="text" name="address" maxlength="150" required></label>
    <button class="btn btn-primary">新增</button>
  </form>

  <h3>門市清單</h3>
  <form method="get" class="filters">
    <label>超商 <select name="chain"><option value="">全部</option><?= options_html(cvs_chain_labels(), $fChain) ?></select></label>
    <label>關鍵字 <input type="search" name="q" value="<?= h($fQ) ?>" placeholder="名稱、縣市、地址或代號"></label>
    <button class="btn">篩選</button> <a class="btn" href="admin_delivery.php#stores">清除</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>超商</th><th>代號</th><th>門市名稱</th><th>縣市</th><th>地址</th><th>狀態</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($stores as $s): ?>
      <?php if ($editId === (int)$s['id']): ?>
        <tr><td colspan="7">
          <form method="post" class="store-form"><?= csrf_field() ?><input type="hidden" name="action" value="store_update"><?= $keepF ?>
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$s['row_version'] ?>">
            <label>超商 <select name="chain" required><?= options_html(cvs_chain_labels(), $s['chain']) ?></select></label>
            <label>門市代號 <input type="text" name="store_code" value="<?= h($s['store_code']) ?>" maxlength="10" required></label>
            <label>門市名稱 <input type="text" name="name" value="<?= h($s['name']) ?>" maxlength="50" required></label>
            <label>縣市 <input type="text" name="city" value="<?= h($s['city']) ?>" maxlength="10" required></label>
            <label>地址 <input type="text" name="address" value="<?= h($s['address']) ?>" maxlength="150" required></label>
            <span><button class="btn btn-primary">儲存</button> <a class="btn" href="admin_delivery.php?<?= h(http_build_query(array_filter(['chain' => $fChain, 'q' => $fQ]))) ?>#stores">取消</a></span>
          </form></td></tr>
      <?php else: ?>
        <tr class="<?= (int)$s['is_active'] ? '' : 'row-off' ?>">
          <td><?= h(cvs_chain_labels()[$s['chain']]) ?></td><td><?= h($s['store_code']) ?></td><td><?= h($s['name']) ?></td>
          <td><?= h($s['city']) ?></td><td><?= h($s['address']) ?></td><td><?= (int)$s['is_active'] ? '啟用' : badge('停用', 'warn') ?></td>
          <td class="actions">
            <a class="btn btn-small" href="admin_delivery.php?<?= h(http_build_query(array_filter(['chain' => $fChain, 'q' => $fQ, 'edit' => (int)$s['id']]))) ?>#stores">修改</a>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="store_toggle"><?= $keepF ?>
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$s['row_version'] ?>">
              <button class="btn btn-small"><?= (int)$s['is_active'] ? '停用' : '啟用' ?></button></form>
          </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$stores): ?><tr><td colspan="7" class="empty">沒有符合條件的門市。</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php
page_footer();
