<?php
/**
 * 後台訂單處理（訂單處理模組）：依狀態、日期、訂單編號、會員、收件人電話篩選，每頁 20 筆
 * 詳情：明細、金額、付款、歷程（含內部備註）與關聯單據；取消、標記完成、標記拒收、暫停／恢復自動完成、內部備註
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_perm('order', is_post() ? 'edit' : 'view');
$canEdit = admin_can('order', 'edit');

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '') ?? 0;
    $action = post_str('action');
    $reason = post_str('reason');
    try {
        admin_transaction(function (PDO $pdo, array $a) use ($id, $action, $reason) {
            $o = order_lock($pdo, $id);
            assert_row_version($o, $_POST['row_version'] ?? '');
            if ($action === 'cancel') {
                if (!len_between($reason, 1, 200)) { throw new UserError('請填寫 1～200 字的取消原因。'); }
                order_cancel($pdo, $o, 'admin', $a['id'], $reason);
            } elseif ($action === 'complete') {
                if ($o['shipping_method'] === 'cvs') { throw new UserError('超商訂單請在出貨單標記「已取貨」完成。'); }
                order_complete($pdo, $o, 'admin', $a['id'], '商店標記訂單完成' . ($reason !== '' ? '：' . $reason : ''));
            } elseif ($action === 'refuse') {
                if (!len_between($reason, 1, 200)) { throw new UserError('請填寫 1～200 字的拒收原因。'); }
                if ($o['shipping_method'] === 'cvs') { throw new UserError('超商訂單請在出貨單標記「未取貨退回」。'); }
                order_refuse($pdo, $o, 'admin', $a['id'], $reason);
            } elseif ($action === 'pause' || $action === 'resume') {
                if ($o['status'] !== 'shipped') { throw new UserError('只有已出貨的訂單可以暫停或恢復自動完成。'); }
                order_update($pdo, $o, ['auto_complete_paused' => $action === 'pause' ? 1 : 0]);
                order_event($pdo, $id, 'auto_complete', null, null, 'admin', $a['id'], null,
                    ($action === 'pause' ? '暫停自動完成' : '恢復自動完成') . ($reason !== '' ? '：' . $reason : ''));
            } elseif ($action === 'note') {
                if (!len_between($reason, 1, 1000)) { throw new UserError('請填寫 1～1000 字的內部備註。'); }
                order_event($pdo, $id, 'note', null, null, 'admin', $a['id'], null, $reason);
            } else {
                throw new UserError('不支援的操作。');
            }
            audit_log($pdo, $a['id'], 'order', $id, $action, ['status' => $o['status']], ['reason' => $reason]);
        }, 'order');
        flash('success', '已完成操作。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_orders.php?id=' . $id);
}

$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $o = order_find($id);
    if (!$o) { render_not_found('訂單'); }
    $items = order_items($id);
    $events = order_events($id);
    $ships = order_shipments($id);
    $receipts = order_receipts($id);
    $afterSales = order_after_sales($id);
    $payments = [];
    if ($enable_launch) {
        $st = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id');
        $st->execute([$id]);
        $payments = $st->fetchAll();
    }
    admin_header('訂單 ' . $o['order_no']);
    $rv = (int)$o['row_version'];
    $form = function (string $action, string $label, string $cls = '', bool $needReason = false, string $ph = '') use ($id, $rv) {
        return '<form method="post" class="row-form">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="row_version" value="' . $rv . '"><input type="hidden" name="action" value="' . h($action) . '">'
            . ($needReason || $ph !== '' ? '<input type="text" name="reason" maxlength="200" placeholder="' . h($ph) . '"' . ($needReason ? ' required' : '') . '>' : '')
            . '<button class="btn btn-small ' . $cls . '">' . h($label) . '</button></form>';
    };
    ?>
    <p><a href="admin_orders.php">← 訂單列表</a></p>
    <h1>訂單 <?= h($o['order_no']) ?>　<?= badge(label_of(order_status_labels(), $o['status'])) ?></h1>
    <div class="grid-2">
      <section class="card"><h2>訂單資訊</h2><dl class="meta">
        <dt>會員</dt><dd><?= admin_can('member') ? '<a href="admin_members.php?id=' . (int)$o['member_id'] . '">' . h($o['member_name']) . '</a>' : h($o['member_name']) ?>（#<?= (int)$o['member_id'] ?>，<?= h(member_level_label($o['member_level'])) ?>）</dd>
        <dt>下單時間</dt><dd><?= h($o['created_at']) ?></dd>
        <dt>配送</dt><dd><?= h(label_of(shipping_method_labels(), $o['shipping_method'])) ?></dd>
        <dt>收件</dt><dd><?= h($o['ship_name']) ?>　<?= h($o['ship_phone']) ?><br>
          <?= $o['shipping_method'] === 'cvs' ? h(label_of(cvs_chain_labels(), $o['cvs_chain']) . ' ' . $o['cvs_store_name'] . '（' . $o['cvs_store_code'] . '）' . $o['cvs_store_address']) : h(address_line($o, 'ship_')) ?></dd>
        <dt>付款</dt><dd><?= h(label_of(payment_method_labels(), $o['payment_method'])) ?>／<?= h(label_of(payment_status_labels(), $o['payment_status'])) ?><?= $o['payment_due_at'] && $o['status'] === 'pending_payment' ? '（期限 ' . h($o['payment_due_at']) . '）' : '' ?></dd>
        <dt>備註</dt><dd><?= h($o['note'] ?? '—') ?></dd>
        <dt>出貨／完成</dt><dd><?= h($o['shipped_at'] ?? '—') ?> ／ <?= h($o['completed_at'] ?? '—') ?><?= $o['completed_by'] ? '（' . h(['member' => '會員', 'admin' => '管理員', 'system' => '系統'][$o['completed_by']]) . '）' : '' ?></dd>
        <dt>自動完成</dt><dd><?= (int)$o['auto_complete_paused'] ? badge('已暫停', 'warn') : '正常' ?></dd>
      </dl></section>
      <section class="card"><h2>金額</h2><dl class="meta totals">
        <dt>商品小計</dt><dd><?= money($o['subtotal']) ?></dd>
        <dt>運費</dt><dd><?= money($o['shipping_fee']) ?><?= $o['shipping_rule_name'] ? '（' . h($o['shipping_rule_name']) . '）' : '' ?></dd>
        <dt>手續費</dt><dd><?= money($o['cod_fee']) ?></dd><dt>總額</dt><dd class="price"><?= money($o['total']) ?></dd>
        <dt>已退款</dt><dd><?= money($o['refunded_total']) ?></dd></dl>
        <?php if ($canEdit): ?><div class="stack">
          <?php if (in_array($o['status'], ['pending_payment', 'processing'], true)) { echo $form('cancel', '取消訂單', 'btn-danger', true, '取消原因（必填）'); } ?>
          <?php if ($o['status'] === 'shipped' && $o['shipping_method'] === 'home') {
              echo $form('complete', '標記完成', '', false, '說明（選填）');
              echo $form('refuse', '標記拒收', 'btn-danger', true, '拒收原因（必填）');
          } ?>
          <?php if ($o['status'] === 'shipped') { echo $form((int)$o['auto_complete_paused'] ? 'resume' : 'pause', (int)$o['auto_complete_paused'] ? '恢復自動完成' : '暫停自動完成', '', false, '原因（選填）'); } ?>
          <?= $form('note', '新增內部備註', '', true, '內部備註（會員看不到）') ?>
        </div><?php endif; ?>
      </section>
    </div>
    <section class="card"><h2>明細</h2><div class="table-wrap"><table>
      <thead><tr><th>商品</th><th>規格</th><th>SKU</th><th class="num">單價</th><th class="num">數量</th><th class="num">已退</th><th class="num">小計</th></tr></thead><tbody>
      <?php foreach ($items as $it): ?><tr><td><?= h($it['product_name']) ?></td><td><?= h($it['variant_text'] ?: '—') ?></td><td><?= h($it['sku']) ?></td>
        <td class="num"><?= money($it['unit_price']) ?></td><td class="num"><?= (int)$it['quantity'] ?></td><td class="num"><?= (int)$it['returned_qty'] ?></td><td class="num"><?= money($it['subtotal']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <section class="card"><h2>關聯單據</h2><ul>
      <?php foreach ($ships as $s): ?><li>出貨單 <?= admin_can('shipping') ? '<a href="admin_shipments.php?id=' . (int)$s['id'] . '">' . h($s['shipment_no']) . '</a>' : h($s['shipment_no']) ?>（<?= h($s['type'] === 'exchange' ? '換貨出貨' : '訂單出貨') ?>，<?= h(label_of(shipment_status_labels(), $s['status'])) ?><?= $s['tracking_no'] ? '，' . h(shipment_carrier_label($s['carrier']) . ' ' . $s['tracking_no']) : '' ?>）</li><?php endforeach; ?>
      <?php foreach ($receipts as $r): ?><li>收件單 <?= admin_can('shipping') ? '<a href="admin_receipts.php?id=' . (int)$r['id'] . '">' . h($r['receipt_no']) . '</a>' : h($r['receipt_no']) ?>（<?= h(label_of(receipt_type_labels(), $r['type'])) ?>，<?= h(label_of(receipt_status_labels(), $r['status'])) ?>）</li><?php endforeach; ?>
      <?php foreach ($afterSales as $a): ?><li>售後單 <a href="admin_after_sales.php?id=<?= (int)$a['id'] ?>"><?= h($a['as_no']) ?></a>（<?= h(after_sale_type_labels()[$a['type']]) ?>，<?= h(label_of(after_sale_status_labels(), $a['status'])) ?>）</li><?php endforeach; ?>
      <?php foreach ($payments as $p): ?><li>付款 #<?= (int)$p['attempt_no'] ?>：<?= h(label_of(payment_method_labels(), $p['method'])) ?> <?= money($p['amount']) ?>，<?= h($p['status']) ?><?= dec_to_cents($p['refunded_amount']) ? '，已退 ' . money($p['refunded_amount']) : '' ?>（<?= h($p['created_at']) ?>）</li><?php endforeach; ?>
      <?php if (!$ships && !$receipts && !$afterSales && !$payments): ?><li class="muted">無</li><?php endif; ?>
    </ul></section>
    <section class="card"><h2>訂單歷程</h2><div class="table-wrap"><table>
      <thead><tr><th>時間</th><th>類型</th><th>狀態</th><th>操作者</th><th>給會員看的說明</th><th>內部備註</th></tr></thead><tbody>
      <?php foreach ($events as $e): ?><tr><td><?= h(substr($e['created_at'], 0, 19)) ?></td><td><?= h($e['event_type']) ?></td>
        <td><?= $e['from_status'] || $e['to_status'] ? h(label_of(order_status_labels(), (string)$e['from_status']) . ' → ' . label_of(order_status_labels(), (string)$e['to_status'])) : '' ?></td>
        <td><?= h(['member' => '會員', 'admin' => '管理員', 'system' => '系統'][$e['actor_type']]) ?><?= $e['actor_name'] ? ' ' . h($e['actor_name']) : '' ?></td>
        <td><?= h($e['public_note'] ?? '') ?></td><td><?= h($e['internal_note'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <?php
    page_footer();
    exit;
}

// ---- 列表 ----
$f = ['status' => get_str('status'), 'from' => get_str('from'), 'to' => get_str('to'), 'no' => get_str('no'),
      'member' => get_str('member'), 'phone' => get_str('phone')];
$where = [];
$args = [];
if (isset(order_status_labels()[$f['status']])) { $where[] = 'o.status = ?'; $args[] = $f['status']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'o.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'o.created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
if ($f['no'] !== '') { $where[] = 'o.order_no LIKE ?'; $args[] = '%' . addcslashes($f['no'], '%_\\') . '%'; }
if ($f['member'] !== '') {
    if (ctype_digit($f['member'])) { $where[] = 'o.member_id = ?'; $args[] = (int)$f['member']; }
    else { $where[] = '(m.name LIKE ? OR m.email LIKE ?)'; $l = '%' . addcslashes($f['member'], '%_\\') . '%'; $args[] = $l; $args[] = $l; }
}
if ($f['phone'] !== '') { $where[] = 'o.ship_phone LIKE ?'; $args[] = '%' . addcslashes($f['phone'], '%_\\') . '%'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = current_page();
$per = 20;
$st = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN members m ON m.id = o.member_id {$w}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT o.*, m.name AS member_name,
                            (SELECT COUNT(*) FROM after_sales a WHERE a.order_id = o.id AND a.status IN ('requested','in_progress')) AS open_as
                       FROM orders o JOIN members m ON m.id = o.member_id {$w} ORDER BY o.id DESC LIMIT {$per} OFFSET {$off}");
$st->execute($args);
$rows = $st->fetchAll();
admin_header('訂單處理');
?>
<h1>訂單處理</h1>
<form class="filters" method="get">
  <label>狀態 <select name="status"><option value="">全部</option><?= options_html(order_status_labels(), $f['status']) ?></select></label>
  <label>下單日期 <input type="date" name="from" value="<?= h($f['from']) ?>"> ～ <input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <label>訂單編號 <input type="text" name="no" value="<?= h($f['no']) ?>" maxlength="20"></label>
  <label>會員（編號、姓名或 Email） <input type="text" name="member" value="<?= h($f['member']) ?>" maxlength="100"></label>
  <label>收件人電話 <input type="text" name="phone" value="<?= h($f['phone']) ?>" maxlength="20"></label>
  <button class="btn btn-primary">篩選</button> <a class="btn" href="admin_orders.php">清除</a>
</form>
<div class="table-wrap"><table>
  <thead><tr><th>訂單編號</th><th>下單時間</th><th>會員</th><th>狀態</th><th>配送／付款</th><th class="num">總額</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $o): ?>
    <tr><td><?= h($o['order_no']) ?></td><td><?= h($o['created_at']) ?></td><td><?= h($o['member_name']) ?>（#<?= (int)$o['member_id'] ?>）</td>
      <td><?= h(label_of(order_status_labels(), $o['status'])) ?><?= (int)$o['open_as'] ? ' ' . badge((int)$o['open_as'] . ' 張售後處理中', 'warn') : '' ?><?= (int)$o['auto_complete_paused'] ? ' ' . badge('暫停自動完成') : '' ?></td>
      <td><?= h(label_of(shipping_method_labels(), $o['shipping_method'])) ?>／<?= h(label_of(payment_method_labels(), $o['payment_method'])) ?>（<?= h(label_of(payment_status_labels(), $o['payment_status'])) ?>）</td>
      <td class="num"><?= money($o['total']) ?></td><td><a class="btn btn-small" href="admin_orders.php?id=<?= (int)$o['id'] ?>">詳情</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">沒有符合條件的訂單。</td></tr><?php endif; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, query_keep(['page'])) ?>
<?php
page_footer();
