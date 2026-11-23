<?php
/**
 * 後台庫存管理（商品管理模組）：逐規格列出與調整；設定數量或增減，數量須 ≧ 0
 * 鎖定順序：shop_write_lock → 操作者 → 商品 → 規格 → 庫存。下單時扣庫存、出貨前取消與良品回庫時回補。
 */
require_once __DIR__ . '/app.php';
require_perm('product', is_post() ? 'edit' : 'view');
$canEdit = admin_can('product', 'edit');

if (is_post()) {
    require_csrf();
    $vid = to_pos_int($_POST['variant_id'] ?? '');
    $mode = post_str('mode');
    $raw = post_str('value');
    try {
        if ($vid === null) { throw new UserError('規格編號不正確。'); }
        if (!preg_match('/^[+-]?[0-9]{1,7}$/', $raw)) { throw new UserError('數量須為整數（最多 7 位數）。'); }
        $val = (int)$raw;
        $msg = admin_transaction(function (PDO $pdo, array $a) use ($vid, $mode, $val) {
            $p = lock_variant($pdo, $vid, true);
            $st = $pdo->prepare('SELECT variant_id, quantity, row_version FROM variant_inventory WHERE variant_id = ? FOR UPDATE');
            $st->execute([$vid]);
            $inv = $st->fetch();
            if (!$inv) { throw new UserError('此規格缺少庫存資料。'); }
            assert_row_version($inv, $_POST['row_version'] ?? '');
            $old = (int)$inv['quantity'];
            $new = $mode === 'set' ? $val : ($mode === 'adjust' ? $old + $val : null);
            if ($new === null) { throw new UserError('不支援的操作。'); }
            if ($new < 0) { throw new UserError("調整後庫存不能小於 0（目前 {$old}）。"); }
            $pdo->prepare('UPDATE variant_inventory SET quantity = ?, row_version = row_version + 1 WHERE variant_id = ?')->execute([$new, $vid]);
            audit_log($pdo, $a['id'], 'inventory', $vid, $mode, ['sku' => $p['sku'], 'quantity' => $old], ['sku' => $p['sku'], 'quantity' => $new]);
            return "「{$p['name']}」{$p['sku']} 庫存：{$old} → {$new}";
        }, 'product');
        flash('success', $msg);
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_inventory.php?' . http_build_query(query_keep()));
}

$q = mb_substr(get_str('q'), 0, 100);
$low = get_str('low') === '1';
$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR v.sku LIKE ?)';
    $l = '%' . addcslashes($q, '%_\\') . '%';
    array_push($args, $l, $l, $l);
}
if ($low) { $where[] = 'COALESCE(vi.quantity, 0) <= 5 AND v.is_active = 1 AND p.is_active = 1'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = current_page();
$per = 50;
$st = $pdo->prepare("SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN variant_inventory vi ON vi.variant_id = v.id {$w}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT v.id, v.sku, v.is_active, v.variant_key, p.id AS product_id, p.name, p.is_active AS p_active,
                            COALESCE(vi.quantity, 0) AS quantity, vi.row_version, ov1.value AS value1, ov2.value AS value2
                       FROM product_variants v JOIN products p ON p.id = v.product_id
                       LEFT JOIN variant_inventory vi ON vi.variant_id = v.id
                       LEFT JOIN product_option_values ov1 ON ov1.id = v.option_value1_id
                       LEFT JOIN product_option_values ov2 ON ov2.id = v.option_value2_id
                       {$w} ORDER BY p.id DESC, v.id LIMIT {$per} OFFSET {$off}");
$st->execute($args);
$rows = $st->fetchAll();
admin_header('庫存管理');
?>
<h1>庫存管理</h1>
<form class="filters" method="get">
  <label>名稱、SKU 或規格 SKU <input type="search" name="q" value="<?= h($q) ?>" maxlength="100"></label>
  <label class="check"><input type="checkbox" name="low" value="1" <?= $low ? 'checked' : '' ?>> 只看低庫存（≦ 5）</label>
  <button class="btn btn-primary">搜尋</button> <a class="btn" href="admin_inventory.php">清除</a>
</form>
<div class="table-wrap"><table>
  <thead><tr><th>商品</th><th>規格</th><th>SKU</th><th class="num">庫存</th><th>調整</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr class="<?= (int)$r['is_active'] && (int)$r['p_active'] ? '' : 'row-off' ?>">
      <td><a href="admin_products.php?id=<?= (int)$r['product_id'] ?>"><?= h($r['name']) ?></a></td>
      <td><?= $r['variant_key'] === 'default' ? '—' : h(variant_text($r)) ?><?= (int)$r['is_active'] ? '' : ' ' . badge('停用') ?></td>
      <td><?= h($r['sku']) ?></td><td class="num"><?= (int)$r['quantity'] ?></td>
      <td><?php if ($canEdit && $r['row_version'] !== null): ?>
        <form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$r['row_version'] ?>">
          <select name="mode" aria-label="方式"><option value="adjust">增減</option><option value="set">設定為</option></select>
          <input type="text" name="value" class="short" required placeholder="+10／-3／25" aria-label="數量"><button class="btn btn-small">送出</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, query_keep(['page'])) ?>
<?php
page_footer();
