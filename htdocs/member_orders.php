<?php
/**
 * 我的訂單（第 11、12、31 節）
 * 清單：訂單編號、日期、狀態摘要、總額。詳情：明細、金額、配送、出貨資訊、售後進度與時間軸（不含內部備註）。
 * 會員操作：取消（處理中／待付款）、付款、我已收到（完成）、我已取貨、申請退貨或換貨、撤回申請、填寫寄回單號。
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_member();
orders_passive_maintenance();

if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $action = post_str('action');
    $oid = to_pos_int($_POST['order_id'] ?? '') ?? 0;
    try {
        if ($action === 'pay') {
            $url = db_transaction(function (PDO $pdo) use ($oid, $me) { return payment_start($pdo, order_lock($pdo, $oid, $me['id'])); });
            redirect($url);
        }
        db_transaction(function (PDO $pdo) use ($action, $oid, $me) {
            if ($action === 'cancel') {
                $o = order_lock($pdo, $oid, $me['id']);
                assert_row_version($o, $_POST['row_version'] ?? '');
                order_cancel($pdo, $o, 'member', $me['id'], '會員取消訂單');
            } elseif ($action === 'receive') {
                $o = order_lock($pdo, $oid, $me['id']);
                assert_row_version($o, $_POST['row_version'] ?? '');
                if ($o['shipping_method'] !== 'home') { throw new UserError('超商取貨訂單請在取貨後按「我已取貨」。'); }
                order_complete($pdo, $o, 'member', $me['id'], '會員確認收到商品，完成訂單');
            } elseif ($action === 'pickup') {
                $sid = to_pos_int($_POST['shipment_id'] ?? '') ?? 0;
                shipment_cvs_pickup($pdo, $sid, 'member', $me['id']);
            } elseif ($action === 'withdraw') {
                after_sale_withdraw($pdo, to_pos_int($_POST['after_sale_id'] ?? '') ?? 0, $me['id']);
            } elseif ($action === 'tracking') {
                after_sale_set_tracking($pdo, to_pos_int($_POST['after_sale_id'] ?? '') ?? 0, $me['id'],
                    post_str('return_carrier'), post_str('return_tracking'));
            } else {
                throw new UserError('不支援的操作。');
            }
        });
        $msg = ['cancel' => '訂單已取消，庫存已回補。', 'receive' => '已完成訂單，謝謝您！', 'pickup' => '已確認取貨，訂單完成。',
                'withdraw' => '已撤回售後申請。', 'tracking' => '寄回單號已儲存。'];
        flash('success', $msg[$action] ?? '完成。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('member_orders.php?id=' . $oid);
}

$id = to_pos_int($_GET['id'] ?? '');
if ($id === null) {
    $page = current_page();
    $per = 20;
    $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE member_id = ?');
    $st->execute([$me['id']]);
    $total = (int)$st->fetchColumn();
    $off = ($page - 1) * $per;
    $st = $pdo->prepare("SELECT * FROM orders WHERE member_id = ? ORDER BY id DESC LIMIT {$per} OFFSET {$off}");
    $st->execute([$me['id']]);
    $rows = $st->fetchAll();
    page_header('我的訂單');
    echo '<h1>我的訂單</h1>';
    if (!$rows) {
        echo '<p class="empty">尚無訂單。<a href="products.php">去逛逛</a></p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>訂單編號</th><th>下單時間</th><th>狀態</th><th>付款</th><th class="num">總額</th><th></th></tr></thead><tbody>';
        foreach ($rows as $o) {
            echo '<tr><td>' . h($o['order_no']) . '</td><td>' . h($o['created_at']) . '</td><td>' . h(order_member_status($o, order_after_sales((int)$o['id'])))
                . '</td><td>' . h(label_of(payment_method_labels(), $o['payment_method'])) . '／' . h(label_of(payment_status_labels(), $o['payment_status']))
                . '</td><td class="num">' . money($o['total']) . '</td><td><a class="btn btn-small" href="member_orders.php?id=' . (int)$o['id'] . '">查看</a></td></tr>';
        }
        echo '</tbody></table></div>' . render_pager($total, $page, $per);
    }
    page_footer();
    exit;
}

$o = order_find($id, $me['id']);
if (!$o) { render_not_found('訂單'); }
$items = order_items($id);
$ships = order_shipments($id);
$receipts = order_receipts($id);
$afterSales = order_after_sales($id);
$events = order_events($id);
$avail = order_item_available($pdo, $id);
$block = order_after_sale_block($o);
$deadline = order_return_deadline($o);
$orderShip = null;
foreach ($ships as $s) { if ($s['type'] === 'order') { $orderShip = $s; } }
$receiptFor = function (int $asId) use ($receipts) {
    foreach ($receipts as $r) { if ((int)$r['after_sale_id'] === $asId) { return $r; } }
    return null;
};
page_header('訂單 ' . $o['order_no']);
?>
<p><a href="member_orders.php">← 我的訂單</a></p>
<h1>訂單 <?= h($o['order_no']) ?></h1>
<p class="status-line"><strong><?= h(order_member_status($o, $afterSales)) ?></strong>
  <span class="muted">下單時間 <?= h($o['created_at']) ?></span></p>

<div class="actions">
<?php if ($o['status'] === 'pending_payment'): ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="order_id" value="<?= $id ?>">
    <button class="btn btn-primary">前往付款（期限 <?= h($o['payment_due_at']) ?>）</button></form>
<?php endif; ?>
<?php if (in_array($o['status'], ['pending_payment', 'processing'], true)): ?>
  <form method="post" class="inline" onsubmit="return confirm('確定取消此訂單？')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
    <input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= (int)$o['row_version'] ?>">
    <button class="btn btn-danger">取消訂單</button></form>
<?php endif; ?>
<?php if ($o['status'] === 'shipped' && $o['shipping_method'] === 'home'): ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="receive">
    <input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= (int)$o['row_version'] ?>">
    <button class="btn btn-primary">我已收到，完成訂單</button></form>
<?php endif; ?>
<?php if ($o['status'] === 'shipped' && $orderShip && $orderShip['status'] === 'arrived'): ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="pickup">
    <input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="shipment_id" value="<?= (int)$orderShip['id'] ?>">
    <button class="btn btn-primary">我已取貨</button></form>
<?php endif; ?>
<?php if ($block === null && array_sum($avail) > 0): ?>
  <a class="btn" href="member_after_sale.php?order=<?= $id ?>&type=return">整單退貨</a>
  <a class="btn" href="member_after_sale.php?order=<?= $id ?>&type=exchange">整單換貨</a>
<?php endif; ?>
</div>
<?php if ($deadline && $o['status'] === 'completed'): ?>
  <p class="muted">退換貨期限：<?= h(substr($deadline, 0, 10)) ?> 23:59 前
    <?php $left = (int)floor((strtotime($deadline) - time()) / 86400); if ($left >= 0): ?>（剩 <?= $left + 1 ?> 天）<?php endif; ?>
    <?php if ($enable_ops): ?>　<a href="returns.php">退貨條款</a><?php endif; ?></p>
<?php endif; ?>

<section class="card">
  <h2>商品明細</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>商品</th><th>規格</th><th>SKU</th><th class="num">單價</th><th class="num">數量</th><th class="num">小計</th><th>售後</th></tr></thead>
    <tbody><?php foreach ($items as $it): ?>
      <tr><td><?= h($it['product_name']) ?></td><td><?= h($it['variant_text'] ?: '—') ?></td><td><?= h($it['sku']) ?></td>
        <td class="num"><?= money($it['unit_price']) ?></td><td class="num"><?= (int)$it['quantity'] ?><?= (int)$it['returned_qty'] ? '<br><span class="muted small">已退 ' . (int)$it['returned_qty'] . '</span>' : '' ?></td>
        <td class="num"><?= money($it['subtotal']) ?></td>
        <td><?php if ($block === null && ($avail[(int)$it['id']] ?? 0) > 0): ?>
          <a class="btn btn-small" href="member_after_sale.php?order=<?= $id ?>&type=return&item=<?= (int)$it['id'] ?>">退貨</a>
          <a class="btn btn-small" href="member_after_sale.php?order=<?= $id ?>&type=exchange&item=<?= (int)$it['id'] ?>">換貨</a>
        <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <dl class="meta totals">
    <dt>商品小計</dt><dd><?= money($o['subtotal']) ?></dd>
    <dt>運費</dt><dd><?= money($o['shipping_fee']) ?><?= $o['shipping_rule_name'] ? '（' . h($o['shipping_rule_name']) . '）' : '' ?></dd>
    <dt>貨到付款手續費</dt><dd><?= money($o['cod_fee']) ?></dd>
    <dt>訂單總額</dt><dd class="price"><?= money($o['total']) ?></dd>
    <?php if (dec_to_cents($o['refunded_total']) > 0): ?><dt>已退款</dt><dd><?= money($o['refunded_total']) ?></dd><?php endif; ?>
    <dt>付款</dt><dd><?= h(label_of(payment_method_labels(), $o['payment_method'])) ?>／<?= h(label_of(payment_status_labels(), $o['payment_status'])) ?></dd>
  </dl>
</section>

<section class="card">
  <h2>配送</h2>
  <?php if ($o['shipping_method'] === 'cvs'): ?>
    <p>超商取貨：<?= h(label_of(cvs_chain_labels(), $o['cvs_chain'])) ?> <?= h($o['cvs_store_name']) ?>（<?= h($o['cvs_store_address']) ?>）<br>取件人：<?= h($o['ship_name']) ?>　<?= h($o['ship_phone']) ?></p>
  <?php else: ?>
    <p>宅配：<?= h($o['ship_name']) ?>　<?= h($o['ship_phone']) ?><br><?= h(address_line($o, 'ship_')) ?></p>
  <?php endif; ?>
  <?php if ($o['note']): ?><p class="muted">備註：<?= h($o['note']) ?></p><?php endif; ?>
  <?php foreach ($ships as $s): if ($s['status'] === 'void') { continue; } ?>
    <p><?= $s['type'] === 'exchange' ? '換貨新品' : '出貨' ?>：<?= h(label_of(shipment_status_labels(), $s['status'])) ?>
      <?php if ($s['shipped_at']): ?>　<?= h(shipment_carrier_label($s['carrier'])) ?>　追蹤碼 <?= h($s['tracking_no']) ?>　出貨日 <?= h(substr($s['shipped_at'], 0, 10)) ?><?php endif; ?>
      <?php if ($s['status'] === 'arrived'): ?><br><strong>已到店，請於 <?= h($s['pickup_deadline']) ?> 前取貨</strong><?php endif; ?></p>
  <?php endforeach; ?>
</section>

<?php if ($afterSales): ?>
<section class="card">
  <h2>退換貨</h2>
  <?php foreach ($afterSales as $a): $r = $receiptFor((int)$a['id']); ?>
    <div class="after-sale">
      <h3><?= h(after_sale_type_labels()[$a['type']]) ?>　<?= h($a['as_no']) ?>　<?= badge(after_sale_member_status($a, $r)) ?></h3>
      <ul><?php foreach ($a['items'] as $i): ?><li><?= h($i['product_name']) ?><?= $i['variant_text'] ? '（' . h($i['variant_text']) . '）' : '' ?> × <?= (int)$i['quantity'] ?></li><?php endforeach; ?></ul>
      <p class="muted small">原因：<?= h(after_sale_reason_labels()[$a['reason']]) ?><?= $a['description'] ? '；' . h($a['description']) : '' ?>
        <?php if ($a['type'] === 'return' && $a['refund_estimate'] !== null): ?>　預估退款 <?= money($a['refund_estimate']) ?><?= (int)$a['includes_shipping'] ? '（含運費與手續費）' : '' ?><?php endif; ?></p>
      <?php if ($a['status'] === 'requested'): ?>
        <form method="post" class="inline" onsubmit="return confirm('確定撤回此申請？')"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
          <input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="after_sale_id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-small">撤回申請</button></form>
      <?php elseif ($a['status'] === 'in_progress' && (!$r || $r['status'] === 'pending')): ?>
        <form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="action" value="tracking">
          <input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="after_sale_id" value="<?= (int)$a['id'] ?>">
          <label>寄回物流商（選填） <input type="text" name="return_carrier" value="<?= h($a['return_carrier']) ?>" maxlength="20"></label>
          <label>寄回單號（選填） <input type="text" name="return_tracking" value="<?= h($a['return_tracking']) ?>" maxlength="40"></label>
          <button class="btn btn-small">儲存</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>訂單歷程</h2>
  <ol class="timeline">
  <?php foreach ($events as $e): if ($e['public_note'] === null && $e['from_status'] === null) { continue; } ?>
    <li><time><?= h(substr($e['created_at'], 0, 19)) ?></time>
      <?php if ($e['from_status'] || $e['to_status']): ?><span class="muted"><?= h(label_of(order_status_labels(), (string)$e['from_status'])) ?> → <?= h(label_of(order_status_labels(), (string)$e['to_status'])) ?></span><?php endif; ?>
      <?= h($e['public_note'] ?? '') ?>
      <span class="muted small">（<?= ['member' => '會員', 'admin' => '商店', 'system' => '系統'][$e['actor_type']] ?>）</span></li>
  <?php endforeach; ?>
  </ol>
</section>
<?php
page_footer();
