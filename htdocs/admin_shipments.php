<?php
/**
 * 後台出貨單（出貨處理模組）：待出貨、暫緩、已出貨清單；填物流商、追蹤碼、出貨日；A4 揀貨／出貨單批次列印
 * 超商出貨單另可模擬「已到店」「已取貨」「未取貨退回」。一張訂單一張訂單出貨單，不分批。
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_perm('shipping', is_post() ? 'edit' : 'view');
$canEdit = admin_can('shipping', 'edit');

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '') ?? 0;
    $action = post_str('action');
    try {
        admin_transaction(function (PDO $pdo, array $a) use ($id, $action) {
            if ($action === 'ship') {
                shipment_ship($pdo, $id, $a['id'], post_str('carrier'), post_str('tracking_no'), post_str('shipped_date'));
            } elseif ($action === 'arrive') {
                shipment_cvs_arrive($pdo, $id, $a['id']);
            } elseif ($action === 'pickup') {
                shipment_cvs_pickup($pdo, $id, 'admin', $a['id']);
            } elseif ($action === 'return') {
                shipment_cvs_return($pdo, $id, $a['id']);
            } else {
                throw new UserError('不支援的操作。');
            }
            audit_log($pdo, $a['id'], 'shipment', $id, $action, null, ['tracking_no' => post_str('tracking_no')]);
        }, 'shipping');
        flash('success', ['ship' => '已出貨。', 'arrive' => '已標記到店並通知會員取貨。', 'pickup' => '已取貨，訂單完成。',
                          'return' => '已標記未取貨退回，並產生拒收退回收件單。'][$action] ?? '完成。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_shipments.php?' . (post_str('back') === 'list' ? 'status=' . urlencode(post_str('status')) : 'id=' . $id));
}

function shipment_items_rows(int $sid): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT si.quantity, oi.product_name, oi.variant_text, oi.sku FROM shipment_items si
                           JOIN order_items oi ON oi.id = si.order_item_id WHERE si.shipment_id = ? ORDER BY si.id');
    $st->execute([$sid]);
    return $st->fetchAll();
}

// ---- 批次列印（A4） ----
if (isset($_GET['print'])) {
    $ids = array_values(array_filter(array_map('to_pos_int', explode(',', get_str('print')))));
    if (!$ids) { render_not_found('出貨單'); }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT s.*, o.order_no, o.payment_method, o.total, o.note FROM shipments s JOIN orders o ON o.id = s.order_id WHERE s.id IN ({$in}) ORDER BY s.id");
    $st->execute($ids);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-Hant-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>揀貨／出貨單</title>'
        . '<style>@page{size:A4;margin:15mm}body{font-family:"Noto Sans TC",sans-serif;font-size:12pt}.sheet{page-break-after:always}'
        . 'table{width:100%;border-collapse:collapse}td,th{border:1px solid #333;padding:4px 6px;text-align:left}h1{font-size:18pt}</style></head><body>';
    foreach ($st->fetchAll() as $s) {
        echo '<div class="sheet"><h1>' . h($GLOBALS['site_name']) . '　' . ($s['type'] === 'exchange' ? '換貨出貨單' : '揀貨／出貨單') . '</h1>'
            . '<p>出貨單 ' . h($s['shipment_no']) . '　訂單 ' . h($s['order_no']) . '　列印時間 ' . date('Y-m-d H:i') . '</p>'
            . '<p>收件人：' . h($s['ship_name']) . '　電話：' . h($s['ship_phone']) . '<br>'
            . ($s['shipping_method'] === 'cvs'
                ? '超商取貨：' . h(label_of(cvs_chain_labels(), $s['cvs_chain']) . ' ' . $s['cvs_store_name'] . '（' . $s['cvs_store_code'] . '）' . $s['cvs_store_address'])
                : '地址：' . h(address_line($s, 'ship_'))) . '</p>'
            . '<p>付款：' . h(label_of(payment_method_labels(), $s['payment_method'])) . ($s['type'] === 'order' && in_array($s['payment_method'], ['cod', 'cvs_cod'], true) ? '　代收金額 ' . money($s['total']) : '')
            . ($s['note'] ? '<br>備註：' . h($s['note']) : '') . '</p>'
            . '<table><thead><tr><th>☐</th><th>商品</th><th>規格</th><th>SKU</th><th>數量</th></tr></thead><tbody>';
        foreach (shipment_items_rows((int)$s['id']) as $it) {
            echo '<tr><td>☐</td><td>' . h($it['product_name']) . '</td><td>' . h($it['variant_text'] ?: '—') . '</td><td>' . h($it['sku']) . '</td><td>' . (int)$it['quantity'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<script>window.print()</script></body></html>';
    exit;
}

$shipForm = function (array $s, bool $inList) {
    $out = '<form method="post" action="admin_shipments.php" class="row-form">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int)$s['id'] . '"><input type="hidden" name="action" value="ship">'
        . ($inList ? '<input type="hidden" name="back" value="list"><input type="hidden" name="status" value="' . h(get_str('status')) . '">' : '');
    if ($s['shipping_method'] === 'cvs') {
        $out .= '<span class="muted small">' . h(label_of(cvs_chain_labels(), $s['cvs_chain'])) . '店到店</span>';
    } else {
        $out .= '<select name="carrier" required aria-label="物流商"><option value="">物流商</option>' . options_html(carrier_labels(), '') . '</select>';
    }
    return $out . '<input type="text" name="tracking_no" maxlength="40" required placeholder="追蹤碼" aria-label="追蹤碼">'
        . '<input type="date" name="shipped_date" value="' . date('Y-m-d') . '" required aria-label="出貨日"><button class="btn btn-small btn-primary">出貨</button></form>';
};
$cvsButtons = function (array $s, bool $inList) {
    $mk = function (string $act, string $label, string $cls = '') use ($s, $inList) {
        return '<form method="post" action="admin_shipments.php" class="inline"' . ($act === 'return' ? ' onsubmit="return confirm(\'確定標記未取貨退回？\')"' : '') . '>' . csrf_field()
            . '<input type="hidden" name="id" value="' . (int)$s['id'] . '"><input type="hidden" name="action" value="' . $act . '">'
            . ($inList ? '<input type="hidden" name="back" value="list"><input type="hidden" name="status" value="' . h(get_str('status')) . '">' : '')
            . '<button class="btn btn-small ' . $cls . '">' . $label . '</button></form> ';
    };
    if ($s['shipping_method'] !== 'cvs') { return ''; }
    if ($s['status'] === 'shipped') { return $mk('arrive', '模擬：已到店'); }
    if ($s['status'] === 'arrived') { return $mk('pickup', '模擬：已取貨') . $mk('return', '模擬：未取貨退回', 'btn-danger'); }
    return '';
};

$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $st = $pdo->prepare('SELECT s.*, o.order_no, o.status AS order_status FROM shipments s JOIN orders o ON o.id = s.order_id WHERE s.id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) { render_not_found('出貨單'); }
    admin_header('出貨單 ' . $s['shipment_no']);
    ?>
    <p><a href="admin_shipments.php">← 出貨單列表</a>　<a href="admin_shipments.php?print=<?= $id ?>" target="_blank">列印 A4</a></p>
    <h1>出貨單 <?= h($s['shipment_no']) ?>　<?= badge(label_of(shipment_status_labels(), $s['status'])) ?></h1>
    <section class="card"><dl class="meta">
      <dt>類型</dt><dd><?= $s['type'] === 'exchange' ? '換貨出貨' : '訂單出貨' ?></dd>
      <dt>訂單</dt><dd><?= admin_can('order') ? '<a href="admin_orders.php?id=' . (int)$s['order_id'] . '">' . h($s['order_no']) . '</a>' : h($s['order_no']) ?>（<?= h(label_of(order_status_labels(), $s['order_status'])) ?>）</dd>
      <dt>收件</dt><dd><?= h($s['ship_name'] . '　' . $s['ship_phone']) ?><br><?= $s['shipping_method'] === 'cvs' ? h(label_of(cvs_chain_labels(), $s['cvs_chain']) . ' ' . $s['cvs_store_name'] . '（' . $s['cvs_store_code'] . '）' . $s['cvs_store_address']) : h(address_line($s, 'ship_')) ?></dd>
      <dt>物流</dt><dd><?= h(shipment_carrier_label($s['carrier'])) ?> <?= h($s['tracking_no'] ?? '') ?>　<?= h($s['shipped_at'] ?? '') ?></dd>
      <?php if ($s['shipping_method'] === 'cvs'): ?><dt>到店／取貨</dt><dd><?= h($s['arrived_at'] ?? '—') ?>（取貨期限 <?= h($s['pickup_deadline'] ?? '—') ?>）／<?= h($s['picked_up_at'] ?? '—') ?></dd><?php endif; ?>
    </dl>
    <ul><?php foreach (shipment_items_rows($id) as $it): ?><li><?= h($it['product_name']) ?><?= $it['variant_text'] ? '（' . h($it['variant_text']) . '）' : '' ?>　<?= h($it['sku']) ?> × <?= (int)$it['quantity'] ?></li><?php endforeach; ?></ul>
    <?php if ($canEdit && $s['status'] === 'pending') { echo $shipForm($s, false); } ?>
    <?php if ($canEdit) { echo $cvsButtons($s, false); } ?>
    <?php if ($s['status'] === 'on_hold'): ?><p class="muted">換貨出貨單暫緩中：倉庫驗收寄回商品後會自動轉為待出貨。</p><?php endif; ?>
    </section>
    <?php
    page_footer();
    exit;
}

$tabs = ['pending' => '待出貨', 'on_hold' => '暫緩', 'shipped' => '已出貨', 'arrived' => '已到店（超商）', 'done' => '已完成／作廢'];
$status = get_str('status');
if (!isset($tabs[$status])) { $status = 'pending'; }
$cond = $status === 'done' ? "s.status IN ('picked_up','returned','void')" : 's.status = ?';
$args = $status === 'done' ? [] : [$status];
$page = current_page();
$per = 50;
$st = $pdo->prepare("SELECT COUNT(*) FROM shipments s WHERE {$cond}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT s.*, o.order_no FROM shipments s JOIN orders o ON o.id = s.order_id WHERE {$cond} ORDER BY s.id " . ($status === 'pending' ? 'ASC' : 'DESC') . " LIMIT {$per} OFFSET {$off}");
$st->execute($args);
$rows = $st->fetchAll();
admin_header('出貨單');
?>
<h1>出貨處理：出貨單</h1>
<nav class="tabs"><?php foreach ($tabs as $k => $label): ?><a href="admin_shipments.php?status=<?= $k ?>" <?= $k === $status ? 'aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?></nav>
<?php if ($rows): ?>
<form method="get" action="admin_shipments.php" target="_blank" id="print-form"><input type="hidden" name="print" id="print-ids" value="<?= h(implode(',', array_column($rows, 'id'))) ?>">
  <button class="btn btn-small">列印本頁 A4 揀貨／出貨單</button></form>
<?php endif; ?>
<div class="table-wrap"><table>
  <thead><tr><th>出貨單</th><th>訂單</th><th>類型</th><th>配送</th><th>收件人</th><th>狀態</th><th>操作</th></tr></thead>
  <tbody><?php foreach ($rows as $s): ?>
    <tr><td><a href="admin_shipments.php?id=<?= (int)$s['id'] ?>"><?= h($s['shipment_no']) ?></a></td><td><?= h($s['order_no']) ?></td>
      <td><?= $s['type'] === 'exchange' ? '換貨' : '訂單' ?></td><td><?= h(label_of(shipping_method_labels(), $s['shipping_method'])) ?></td>
      <td><?= h($s['ship_name']) ?></td><td><?= h(label_of(shipment_status_labels(), $s['status'])) ?><?= $s['tracking_no'] ? '<br><span class="small muted">' . h($s['tracking_no']) . '</span>' : '' ?></td>
      <td><?php if ($canEdit && $s['status'] === 'pending') { echo $shipForm($s, true); } elseif ($canEdit) { echo $cvsButtons($s, true); } ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">沒有出貨單。</td></tr><?php endif; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, ['status' => $status]) ?>
<?php
page_footer();
