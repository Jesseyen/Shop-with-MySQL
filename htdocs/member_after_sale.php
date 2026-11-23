<?php
/**
 * 退貨／換貨申請程序（第 12 節）
 * 1. 選品項與數量（整單預先勾好全部可申請數量；個別品項只帶入該品項）
 * 2. 選原因（必選）並填說明（選填，最多 500 字）
 * 3. 換貨選新品寄送地址（預設原訂單地址）；退貨確認預估退款
 * 4. 送出（需辨識碼），顯示售後單編號
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_member();

$src = is_post() ? $_POST : $_GET;
$orderId = to_pos_int($src['order'] ?? '');
$type = (string)($src['type'] ?? '');
if (!isset(after_sale_type_labels()[$type])) { $type = 'return'; }
$o = $orderId !== null ? order_find($orderId, $me['id']) : null;
if (!$o) { render_not_found('訂單'); }
$typeLabel = after_sale_type_labels()[$type];
if (($block = order_after_sale_block($o)) !== null) {
    flash('error', $block);
    redirect('member_orders.php?id=' . $orderId);
}
$items = order_items($orderId);
$avail = order_item_available($pdo, $orderId);
$onlyItem = to_pos_int($src['item'] ?? '');
$addresses = address_list($me['id']);

// 表單值
$qty = [];
foreach ($items as $it) {
    $id = (int)$it['id'];
    $default = ($onlyItem === null || $onlyItem === $id) ? ($avail[$id] ?? 0) : 0;
    $qty[$id] = is_post() ? (int)(post_arr('qty')[$id] ?? 0) : $default;
}
$reason = (string)($src['reason'] ?? '');
$desc = is_post() ? post_str('description') : '';
$addrChoice = (string)($src['address_id'] ?? 'order');

$snapFor = function () use ($addrChoice, $o, $me) {
    if ($addrChoice === 'order') {
        if ($o['shipping_method'] === 'cvs' || $o['ship_address'] === null) { throw new UserError('請從收件資料選擇新品寄送地址。'); }
        return array_intersect_key($o, array_flip(['ship_name', 'ship_phone', 'ship_zip', 'ship_city', 'ship_district', 'ship_address']));
    }
    $a = to_pos_int($addrChoice) !== null ? address_find($me['id'], (int)$addrChoice) : null;
    if (!$a) { throw new UserError('選取的收件資料已不存在，請重新選擇。'); }
    return address_snapshot($a);
};

$preview = null;
$error = null;
if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $step = post_str('step');
    try {
        if ($step === 'submit') { captcha_check('after_sale'); }
        $snap = $type === 'exchange' ? $snapFor() : null;
        $sel = array_filter($qty, function ($q) { return $q > 0; });
        if ($step === 'submit') {
            $r = db_transaction(function (PDO $pdo) use ($me, $orderId, $type, $sel, $reason, $desc, $snap) {
                return after_sale_create($pdo, $me['id'], $orderId, $type, $sel, $reason, $desc, $snap);
            });
            flash('success', $typeLabel . '申請已送出，售後單編號 ' . $r['no'] . '。客服核准後會寄出寄回說明信。');
            redirect('member_orders.php?id=' . $orderId);
        }
        // 預覽：在交易內試算後回復，不寫入任何資料
        $pdo->beginTransaction();
        try {
            $preview = after_sale_create($pdo, $me['id'], $orderId, $type, $sel, $reason, $desc, $snap);
        } finally {
            $pdo->rollBack();
            clear_deferred_mail();
        }
        $preview['snap'] = $snap;
    } catch (UserError $e) {
        $error = $e->getMessage();
        $preview = null;
    }
}

page_header('申請' . $typeLabel);
?>
<p><a href="member_orders.php?id=<?= $orderId ?>">← 訂單 <?= h($o['order_no']) ?></a></p>
<h1>申請<?= h($typeLabel) ?></h1>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<p class="muted">退換貨期限：<?= $o['status'] === 'completed' ? h(substr((string)order_return_deadline($o), 0, 10)) . ' 23:59 前' : '訂單完成後 ' . setting_int('return_days', 7) . ' 天' ?>。
  <?= $type === 'exchange' ? '換貨只能換同一規格、同樣數量的新品；收到並驗收寄回商品後才寄出新品。' : '貨到付款訂單由客服聯繫退款，不需要提供銀行帳號。' ?>
  <?php if ($enable_ops): ?><a href="returns.php" target="_blank">退貨條款</a><?php endif; ?></p>
<?php if ($o['status'] === 'shipped'): ?><div class="alert alert-info">此訂單目前為「已出貨」，送出申請時會先完成訂單。</div><?php endif; ?>

<form method="post" action="member_after_sale.php" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="order" value="<?= $orderId ?>">
  <input type="hidden" name="type" value="<?= h($type) ?>">
  <?php if ($onlyItem !== null): ?><input type="hidden" name="item" value="<?= $onlyItem ?>"><?php endif; ?>
  <section class="card">
    <h2>1. 選擇品項與數量</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>商品</th><th>規格</th><th class="num">單價</th><th class="num">可申請</th><th>本次數量</th></tr></thead>
      <tbody><?php foreach ($items as $it): $id = (int)$it['id']; $max = $avail[$id] ?? 0; ?>
        <tr class="<?= $max ? '' : 'row-off' ?>"><td><?= h($it['product_name']) ?></td><td><?= h($it['variant_text'] ?: '—') ?></td>
          <td class="num"><?= money($it['unit_price']) ?></td><td class="num"><?= $max ?></td>
          <td><input type="number" class="qty" name="qty[<?= $id ?>]" value="<?= min($qty[$id], $max) ?>" min="0" max="<?= $max ?>" <?= $max ? '' : 'disabled' ?> aria-label="<?= h($it['product_name']) ?> 數量"></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
  <section class="card">
    <h2>2. 原因</h2>
    <?php foreach (after_sale_reason_labels() as $k => $label): ?>
      <label class="check"><input type="radio" name="reason" value="<?= h($k) ?>" <?= $reason === $k ? 'checked' : '' ?> required> <?= h($label) ?></label>
    <?php endforeach; ?>
    <label>說明（選填，最多 500 字） <textarea name="description" maxlength="500" rows="3"><?= h($desc) ?></textarea></label>
  </section>
  <?php if ($type === 'exchange'): ?>
  <section class="card">
    <h2>3. 新品寄送地址</h2>
    <?php if ($o['shipping_method'] === 'home'): ?>
      <label class="check"><input type="radio" name="address_id" value="order" <?= $addrChoice === 'order' ? 'checked' : '' ?>> 原訂單地址：<?= h($o['ship_name'] . '　' . address_line($o, 'ship_')) ?></label>
    <?php endif; ?>
    <?php foreach ($addresses as $a): ?>
      <label class="check"><input type="radio" name="address_id" value="<?= (int)$a['id'] ?>" <?= $addrChoice === (string)$a['id'] ? 'checked' : '' ?>> <?= h($a['recipient'] . '　' . address_line($a)) ?></label>
    <?php endforeach; ?>
    <p class="small"><a href="member_addresses.php">管理收件資料</a></p>
  </section>
  <?php endif; ?>

  <?php if ($preview): ?>
  <section class="card highlight">
    <h2><?= $type === 'exchange' ? '4' : '3' ?>. 確認並送出</h2>
    <?php if ($type === 'return'): ?>
      <p>預估退款：<strong><?= money($preview['estimate']) ?></strong><?= $preview['includes_shipping'] ? '（整單退貨，含運費與手續費）' : '（部分退貨不退運費）' ?></p>
    <?php else: ?>
      <p>新品寄送到：<?= h(($preview['snap']['ship_name'] ?? '') . '　' . address_line($preview['snap'], 'ship_')) ?></p>
    <?php endif; ?>
    <input type="hidden" name="step" value="submit">
    <?= captcha_field('after_sale') ?>
    <button class="btn btn-primary" type="submit">送出<?= h($typeLabel) ?>申請</button>
    <button class="btn" type="submit" formnovalidate onclick="this.form.step.value='preview'">重新試算</button>
  </section>
  <?php else: ?>
    <input type="hidden" name="step" value="preview">
    <p><button class="btn btn-primary" type="submit">下一步：確認<?= $type === 'return' ? '預估退款' : '內容' ?></button></p>
  <?php endif; ?>
</form>
<?php
page_footer();
