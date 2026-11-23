<?php
/**
 * 運費規則（商品管理模組）：名稱唯一、運費 ≧ 0、滿額免運門檻（可空）、啟用；有商品使用中的規則不能刪除
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
require_perm('product', is_post() ? 'edit' : 'view');
$canEdit = admin_can('product', 'edit');

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    $id = to_pos_int($_POST['id'] ?? '');
    try {
        $name = post_str('name');
        $fee = parse_price(post_str('fee'));
        $th = post_str('free_threshold');
        $thv = $th === '' ? null : parse_price($th);
        if ($action !== 'delete') {
            if (!len_between($name, 1, 50)) { throw new UserError('規則名稱須為 1～50 字。'); }
            if ($fee === null) { throw new UserError('運費格式不正確。'); }
            if ($th !== '' && $thv === null) { throw new UserError('免運門檻格式不正確（空白代表不因金額免運）。'); }
        }
        admin_transaction(function (PDO $pdo, array $a) use ($action, $id, $name, $fee, $thv) {
            $active = post_str('is_active') === '1' ? 1 : 0;
            if ($action === 'create') {
                $pdo->prepare('INSERT INTO shipping_rules (name, fee, free_threshold, is_active) VALUES (?, ?, ?, ?)')->execute([$name, $fee, $thv, $active]);
                audit_log($pdo, $a['id'], 'shipping_rule', $pdo->lastInsertId(), 'create', null, compact('name', 'fee', 'thv', 'active'));
                return;
            }
            $st = $pdo->prepare('SELECT * FROM shipping_rules WHERE id = ? FOR UPDATE');
            $st->execute([$id ?? 0]);
            $row = $st->fetch();
            if (!$row) { throw new UserError('找不到此規則。'); }
            assert_row_version($row, $_POST['row_version'] ?? '');
            if ($action === 'delete') {
                $c = $pdo->prepare('SELECT COUNT(*) FROM products WHERE shipping_rule_id = ?');
                $c->execute([$id]);
                if ((int)$c->fetchColumn() > 0) { throw new UserError('此規則有商品使用中，不能刪除；可以先停用。'); }
                $pdo->prepare('DELETE FROM shipping_rules WHERE id = ?')->execute([$id]);
                audit_log($pdo, $a['id'], 'shipping_rule', $id, 'delete', $row, null);
                return;
            }
            $pdo->prepare('UPDATE shipping_rules SET name = ?, fee = ?, free_threshold = ?, is_active = ?, row_version = row_version + 1 WHERE id = ?')
                ->execute([$name, $fee, $thv, $active, $id]);
            audit_log($pdo, $a['id'], 'shipping_rule', $id, 'update', $row, ['name' => $name, 'fee' => $fee, 'free_threshold' => $thv, 'is_active' => $active]);
        }, 'product');
        flash('success', '運費規則已儲存。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') { throw $e; }
        flash('error', '規則名稱已存在。');
    }
    redirect('admin_shipping_rules.php');
}
$rules = shipping_rules_all();
admin_header('運費規則');
?>
<h1>運費規則</h1>
<p class="muted">整筆訂單只收一次運費，取適用規則中最高的運費；規則有門檻且商品小計 ≧ 門檻時不收；商品未選規則或規則停用時免運。
  目前全站模式：<strong><?= setting('shipping_mode') === 'all_free' ? '全部免運' : '依商品規則計算' ?></strong><?= is_super() ? '（<a href="admin_settings.php">商場設定</a>）' : '' ?></p>
<div class="table-wrap"><table>
  <thead><tr><th>名稱</th><th>運費</th><th>滿額免運門檻</th><th>啟用</th><th class="num">使用商品</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rules as $r): ?>
    <tr><?php if ($canEdit): ?>
      <td colspan="4"><form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$r['row_version'] ?>">
        <input type="text" name="name" value="<?= h($r['name']) ?>" maxlength="50" required aria-label="名稱">
        <input type="text" name="fee" value="<?= h($r['fee']) ?>" required class="short" aria-label="運費">
        <input type="text" name="free_threshold" value="<?= h($r['free_threshold'] ?? '') ?>" class="short" placeholder="空白＝無" aria-label="免運門檻">
        <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int)$r['is_active'] ? 'checked' : '' ?>> 啟用</label>
        <button class="btn btn-small">儲存</button></form></td>
      <?php else: ?>
      <td><?= h($r['name']) ?></td><td><?= money($r['fee']) ?></td><td><?= $r['free_threshold'] !== null ? money($r['free_threshold']) : '—' ?></td><td><?= (int)$r['is_active'] ? '是' : '否' ?></td>
      <?php endif; ?>
      <td class="num"><?= (int)$r['used'] ?></td>
      <td><?php if ($canEdit && !(int)$r['used']): ?><form method="post" class="inline" onsubmit="return confirm('確定刪除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$r['row_version'] ?>"><button class="btn btn-small btn-danger">刪除</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($canEdit): ?>
<section class="card"><h2>新增規則</h2>
  <form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label>名稱 <input type="text" name="name" maxlength="50" required></label>
    <label>運費 <input type="text" name="fee" required class="short"></label>
    <label>滿額免運門檻 <input type="text" name="free_threshold" class="short" placeholder="空白＝無"></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" checked> 啟用</label>
    <button class="btn btn-primary">新增</button></form>
</section>
<?php endif; ?>
<?php
page_footer();
