<?php
/**
 * 後台售後單（訂單處理模組）：依類型、狀態、日期篩選；核准、拒絕（填原因）、登錄退款（倉庫驗收後才可操作）
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_perm('order', is_post() ? 'edit' : 'view');
$canEdit = admin_can('order', 'edit');

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '') ?? 0;
    $action = post_str('action');
    try {
        admin_transaction(function (PDO $pdo, array $a) use ($id, $action) {
            if ($action === 'approve') {
                after_sale_approve($pdo, $id, $a['id']);
            } elseif ($action === 'reject') {
                after_sale_reject($pdo, $id, $a['id'], post_str('reason'));
            } elseif ($action === 'refund') {
                after_sale_refund($pdo, $id, $a['id'], post_str('amount'), post_str('method'), post_str('date'), post_str('note'));
            } else {
                throw new UserError('不支援的操作。');
            }
            audit_log($pdo, $a['id'], 'after_sale', $id, $action, null, ['amount' => post_str('amount'), 'reason' => post_str('reason')]);
        }, 'order');
        flash('success', ['approve' => '已核准，系統已產生收件單並寄出寄回說明。', 'reject' => '已拒絕並通知會員。', 'refund' => '已登錄退款，退貨完成。'][$action] ?? '完成。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_after_sales.php?id=' . $id);
}

$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $st = $pdo->prepare('SELECT a.*, o.order_no, o.payment_method, o.payment_status, o.total, o.refunded_total, m.name AS member_name
                           FROM after_sales a JOIN orders o ON o.id = a.order_id JOIN members m ON m.id = a.member_id WHERE a.id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) { render_not_found('售後單'); }
    $all = order_after_sales((int)$a['order_id']);
    foreach ($all as $x) { if ((int)$x['id'] === $id) { $a['items'] = $x['items']; } }
    $st = $pdo->prepare('SELECT * FROM return_receipts WHERE after_sale_id = ?');
    $st->execute([$id]);
    $rcpt = $st->fetch() ?: null;
    $st = $pdo->prepare('SELECT * FROM shipments WHERE after_sale_id = ?');
    $st->execute([$id]);
    $ship = $st->fetch() ?: null;
    $online = in_array($a['payment_method'], ['sim_card', 'sim_paypal'], true);
    admin_header('售後單 ' . $a['as_no']);
    ?>
    <p><a href="admin_after_sales.php">← 售後單列表</a></p>
    <h1><?= h(after_sale_type_labels()[$a['type']]) ?>　<?= h($a['as_no']) ?>　<?= badge(label_of(after_sale_status_labels(), $a['status'])) ?></h1>
    <div class="grid-2">
      <section class="card"><dl class="meta">
        <dt>訂單</dt><dd><a href="admin_orders.php?id=<?= (int)$a['order_id'] ?>"><?= h($a['order_no']) ?></a></dd>
        <dt>會員</dt><dd><?= h($a['member_name']) ?>（#<?= (int)$a['member_id'] ?>）</dd>
        <dt>申請時間</dt><dd><?= h($a['created_at']) ?></dd>
        <dt>原因</dt><dd><?= h(after_sale_reason_labels()[$a['reason']]) ?><?= $a['description'] ? '<br>' . nl2br(h($a['description'])) : '' ?></dd>
        <?php if ($a['type'] === 'exchange'): ?><dt>新品寄送</dt><dd><?= h($a['ship_name'] . '　' . $a['ship_phone']) ?><br><?= h(address_line($a, 'ship_')) ?></dd><?php endif; ?>
        <dt>會員寄回</dt><dd><?= h(($a['return_carrier'] ?? '') . ' ' . ($a['return_tracking'] ?? '')) ?: '—' ?></dd>
        <?php if ($a['type'] === 'return'): ?><dt>預估退款</dt><dd><?= money($a['refund_estimate'] ?? '0') ?><?= (int)$a['includes_shipping'] ? '（含運費與手續費）' : '' ?></dd><?php endif; ?>
        <?php if ($a['reject_reason']): ?><dt>拒絕原因</dt><dd><?= h($a['reject_reason']) ?></dd><?php endif; ?>
        <?php if ($a['refund_amount'] !== null): ?><dt>退款</dt><dd><?= money($a['refund_amount']) ?>　<?= h($a['refund_method']) ?>　<?= h($a['refund_date']) ?><?= $a['refund_note'] ? '<br>' . h($a['refund_note']) : '' ?></dd><?php endif; ?>
        <dt>收件單</dt><dd><?= $rcpt ? h($rcpt['receipt_no'] . '（' . label_of(receipt_status_labels(), $rcpt['status']) . '）') : '—' ?></dd>
        <?php if ($ship): ?><dt>換貨出貨單</dt><dd><?= h($ship['shipment_no'] . '（' . label_of(shipment_status_labels(), $ship['status']) . '）') ?></dd><?php endif; ?>
      </dl></section>
      <section class="card"><h2>品項</h2><ul>
        <?php foreach ($a['items'] as $i): ?><li><?= h($i['product_name']) ?><?= $i['variant_text'] ? '（' . h($i['variant_text']) . '）' : '' ?>　<?= h($i['sku']) ?> × <?= (int)$i['quantity'] ?>　<?= money(cents_to_dec(dec_to_cents($i['unit_price']) * (int)$i['quantity'])) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($canEdit && $a['status'] === 'requested'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-primary">核准</button></form>
        <form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reject">
          <input type="text" name="reason" maxlength="200" required placeholder="拒絕原因（會寄給會員）"><button class="btn btn-danger">拒絕</button></form>
      <?php elseif ($canEdit && $a['type'] === 'return' && $a['status'] === 'in_progress'): ?>
        <?php if (!$rcpt || $rcpt['status'] !== 'received'): ?>
          <p class="muted">倉庫驗收退貨後才能登錄退款。</p>
        <?php else: ?>
          <h3>登錄退款</h3>
          <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="refund">
            <label>退款金額 <input type="text" name="amount" value="<?= h($a['refund_estimate']) ?>" required inputmode="decimal"></label>
            <label>退款方式 <input type="text" name="method" maxlength="30" value="<?= $online ? '原付款方式模擬退回' : '' ?>" <?= $online ? 'readonly' : 'required' ?> placeholder="例如：轉帳、現金"></label>
            <label>退款日期 <input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>備註（選填） <input type="text" name="note" maxlength="200"></label>
            <p class="muted small">剩餘可退金額 <?= money(cents_to_dec(dec_to_cents($a['total']) - dec_to_cents($a['refunded_total']))) ?>；部分退貨不退運費。</p>
            <button class="btn btn-primary">登錄退款並完成退貨</button></form>
        <?php endif; ?>
      <?php endif; ?>
      </section>
    </div>
    <?php
    page_footer();
    exit;
}

$f = ['type' => get_str('type'), 'status' => get_str('status'), 'from' => get_str('from'), 'to' => get_str('to')];
$where = [];
$args = [];
if (isset(after_sale_type_labels()[$f['type']])) { $where[] = 'a.type = ?'; $args[] = $f['type']; }
if (isset(after_sale_status_labels()[$f['status']])) { $where[] = 'a.status = ?'; $args[] = $f['status']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'a.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'a.created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = current_page();
$per = 20;
$st = $pdo->prepare("SELECT COUNT(*) FROM after_sales a {$w}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT a.*, o.order_no, m.name AS member_name,
                            (SELECT r.status FROM return_receipts r WHERE r.after_sale_id = a.id LIMIT 1) AS receipt_status
                       FROM after_sales a JOIN orders o ON o.id = a.order_id JOIN members m ON m.id = a.member_id
                       {$w} ORDER BY FIELD(a.status, 'requested', 'in_progress') DESC, a.id DESC LIMIT {$per} OFFSET {$off}");
$st->execute($args);
admin_header('售後單');
?>
<h1>售後單（退貨與換貨）</h1>
<form class="filters" method="get">
  <label>類型 <select name="type"><option value="">全部</option><?= options_html(after_sale_type_labels(), $f['type']) ?></select></label>
  <label>狀態 <select name="status"><option value="">全部</option><?= options_html(after_sale_status_labels(), $f['status']) ?></select></label>
  <label>申請日期 <input type="date" name="from" value="<?= h($f['from']) ?>"> ～ <input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <button class="btn btn-primary">篩選</button> <a class="btn" href="admin_after_sales.php">清除</a>
</form>
<div class="table-wrap"><table>
  <thead><tr><th>售後單</th><th>類型</th><th>訂單</th><th>會員</th><th>狀態</th><th>收件</th><th>申請時間</th><th></th></tr></thead>
  <tbody><?php foreach ($st->fetchAll() as $a): ?>
    <tr><td><?= h($a['as_no']) ?></td><td><?= h(after_sale_type_labels()[$a['type']]) ?></td><td><?= h($a['order_no']) ?></td><td><?= h($a['member_name']) ?></td>
      <td><?= h(label_of(after_sale_status_labels(), $a['status'])) ?></td><td><?= $a['receipt_status'] ? h(label_of(receipt_status_labels(), $a['receipt_status'])) : '—' ?></td>
      <td><?= h($a['created_at']) ?></td><td><a class="btn btn-small" href="admin_after_sales.php?id=<?= (int)$a['id'] ?>">詳情</a></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, query_keep(['page'])) ?>
<?php
page_footer();
