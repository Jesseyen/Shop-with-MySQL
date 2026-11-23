<?php
/**
 * 後台退貨收件單（出貨處理模組）：待收件、已收件清單；顯示會員填寫的寄回單號；
 * 收件時逐項選「良品回庫」或「瑕疵不回庫」。換貨收件後換貨出貨單轉為待出貨。
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_perm('shipping', is_post() ? 'edit' : 'view');
$canEdit = admin_can('shipping', 'edit');

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '') ?? 0;
    try {
        admin_transaction(function (PDO $pdo, array $a) use ($id) {
            $cond = [];
            foreach (post_arr('cond') as $k => $v) { if (to_pos_int((string)$k) !== null && is_string($v)) { $cond[(int)$k] = $v; } }
            receipt_receive($pdo, $id, $a['id'], $cond);
            audit_log($pdo, $a['id'], 'return_receipt', $id, 'receive', null, ['conditions' => $cond]);
        }, 'shipping');
        flash('success', '已完成收件驗收。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_receipts.php?id=' . $id);
}

function receipt_rows(int $rid): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT rri.*, oi.product_name, oi.variant_text, oi.sku FROM return_receipt_items rri
                           JOIN order_items oi ON oi.id = rri.order_item_id WHERE rri.receipt_id = ? ORDER BY rri.id');
    $st->execute([$rid]);
    return $st->fetchAll();
}

$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $st = $pdo->prepare('SELECT r.*, o.order_no, a.as_no, a.return_carrier, a.return_tracking, m.name AS receiver
                           FROM return_receipts r JOIN orders o ON o.id = r.order_id LEFT JOIN after_sales a ON a.id = r.after_sale_id
                           LEFT JOIN members m ON m.id = r.received_by WHERE r.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { render_not_found('收件單'); }
    admin_header('收件單 ' . $r['receipt_no']);
    ?>
    <p><a href="admin_receipts.php">← 收件單列表</a></p>
    <h1>收件單 <?= h($r['receipt_no']) ?>　<?= badge(label_of(receipt_status_labels(), $r['status'])) ?></h1>
    <section class="card"><dl class="meta">
      <dt>類型</dt><dd><?= h(label_of(receipt_type_labels(), $r['type'])) ?></dd>
      <dt>訂單</dt><dd><?= h($r['order_no']) ?><?= $r['as_no'] ? '　售後單 ' . h($r['as_no']) : '' ?></dd>
      <dt>會員寄回</dt><dd><?= h(trim(($r['return_carrier'] ?? '') . ' ' . ($r['return_tracking'] ?? ''))) ?: '—' ?></dd>
      <?php if ($r['note']): ?><dt>說明</dt><dd><?= h($r['note']) ?></dd><?php endif; ?>
      <?php if ($r['status'] === 'received'): ?><dt>收件</dt><dd><?= h($r['received_at']) ?>　<?= h($r['receiver'] ?? '') ?></dd><?php endif; ?>
    </dl>
    <form method="post" action="admin_receipts.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <div class="table-wrap"><table><thead><tr><th>商品</th><th>規格</th><th>SKU</th><th class="num">數量</th><th>驗收</th></tr></thead><tbody>
      <?php foreach (receipt_rows($id) as $it): ?>
        <tr><td><?= h($it['product_name']) ?></td><td><?= h($it['variant_text'] ?: '—') ?></td><td><?= h($it['sku']) ?></td><td class="num"><?= (int)$it['quantity'] ?></td>
          <td><?php if ($r['status'] === 'pending' && $canEdit): ?>
            <label class="check"><input type="radio" name="cond[<?= (int)$it['id'] ?>]" value="good" required> 良品回庫</label>
            <label class="check"><input type="radio" name="cond[<?= (int)$it['id'] ?>]" value="defective"> 瑕疵不回庫</label>
          <?php else: ?><?= $it['item_condition'] === 'good' ? '良品（已回庫）' : ($it['item_condition'] === 'defective' ? '瑕疵（不回庫）' : '—') ?><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
      <?php if ($r['status'] === 'pending' && $canEdit): ?><button class="btn btn-primary">完成收件驗收</button><?php endif; ?>
    </form></section>
    <?php
    page_footer();
    exit;
}

$status = get_str('status') === 'received' ? 'received' : 'pending';
$page = current_page();
$per = 50;
$st = $pdo->prepare('SELECT COUNT(*) FROM return_receipts WHERE status = ?');
$st->execute([$status]);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT r.*, o.order_no, a.as_no, a.return_tracking FROM return_receipts r JOIN orders o ON o.id = r.order_id
                       LEFT JOIN after_sales a ON a.id = r.after_sale_id WHERE r.status = ? ORDER BY r.id DESC LIMIT {$per} OFFSET {$off}");
$st->execute([$status]);
admin_header('退貨收件單');
?>
<h1>出貨處理：退貨收件單</h1>
<nav class="tabs"><a href="admin_receipts.php" <?= $status === 'pending' ? 'aria-current="page"' : '' ?>>待收件</a><a href="admin_receipts.php?status=received" <?= $status === 'received' ? 'aria-current="page"' : '' ?>>已收件</a></nav>
<div class="table-wrap"><table>
  <thead><tr><th>收件單</th><th>類型</th><th>訂單</th><th>售後單</th><th>會員寄回單號</th><th>建立時間</th><th></th></tr></thead>
  <tbody><?php foreach ($st->fetchAll() as $r): ?>
    <tr><td><?= h($r['receipt_no']) ?></td><td><?= h(label_of(receipt_type_labels(), $r['type'])) ?></td><td><?= h($r['order_no']) ?></td><td><?= h($r['as_no'] ?? '—') ?></td>
      <td><?= h($r['return_tracking'] ?? '—') ?></td><td><?= h($r['created_at']) ?></td><td><a class="btn btn-small" href="admin_receipts.php?id=<?= (int)$r['id'] ?>"><?= $status === 'pending' ? '驗收' : '查看' ?></a></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, ['status' => $status]) ?>
<?php
page_footer();
