<?php
/**
 * 會員中心「收件資料」：每人最多 10 筆、預設地址一筆、軟刪除；新增與修改需辨識碼。他人的地址回應 404。
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_member();

$editId = to_pos_int($_GET['edit'] ?? '');
$form = ['recipient' => '', 'phone' => '', 'zip' => '', 'city' => '', 'district' => '', 'address' => ''];
if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $action = post_str('action');
    $id = to_pos_int($_POST['id'] ?? '');
    try {
        if ($action === 'create' || $action === 'update') {
            $form = array_merge($form, array_map('strval', post_arr('a')));
            captcha_check('address');
            [$d, $errs] = address_validate($form);
            if ($errs) { throw new UserError(implode(' ', $errs)); }
            db_transaction(function (PDO $pdo) use ($me, $d, $action, $id) {
                $pdo->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE')->execute([$me['id']]);
                if ($action === 'create') {
                    address_insert($pdo, $me['id'], $d, post_str('is_default') === '1');
                    return;
                }
                $st = $pdo->prepare('SELECT * FROM member_addresses WHERE id = ? AND member_id = ? AND deleted_at IS NULL FOR UPDATE');
                $st->execute([$id ?? 0, $me['id']]);
                $row = $st->fetch();
                if (!$row) { throw new UserError('找不到此收件資料。'); }
                assert_row_version($row, $_POST['row_version'] ?? '');
                $pdo->prepare('UPDATE member_addresses SET recipient = ?, phone = ?, zip = ?, city = ?, district = ?, address = ?,
                                      row_version = row_version + 1 WHERE id = ?')
                    ->execute([$d['recipient'], $d['phone'], $d['zip'], $d['city'], $d['district'], $d['address'], $row['id']]);
            });
            flash('success', $action === 'create' ? '收件資料已新增。' : '收件資料已更新。');
            redirect('member_addresses.php');
        }
        db_transaction(function (PDO $pdo) use ($me, $action, $id) {
            $pdo->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE')->execute([$me['id']]);
            $st = $pdo->prepare('SELECT * FROM member_addresses WHERE id = ? AND member_id = ? AND deleted_at IS NULL FOR UPDATE');
            $st->execute([$id ?? 0, $me['id']]);
            $row = $st->fetch();
            if (!$row) { throw new UserError('找不到此收件資料。'); }
            assert_row_version($row, $_POST['row_version'] ?? '');
            if ($action === 'default') {
                $pdo->prepare('UPDATE member_addresses SET is_default = 0, row_version = row_version + 1 WHERE member_id = ? AND is_default = 1')->execute([$me['id']]);
                $pdo->prepare('UPDATE member_addresses SET is_default = 1, row_version = row_version + 1 WHERE id = ?')->execute([$row['id']]);
            } elseif ($action === 'delete') {
                $pdo->prepare('UPDATE member_addresses SET deleted_at = NOW(), is_default = 0, row_version = row_version + 1 WHERE id = ?')->execute([$row['id']]);
                if ((int)$row['is_default'] === 1) {
                    $pdo->prepare('UPDATE member_addresses SET is_default = 1, row_version = row_version + 1
                                    WHERE member_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1')->execute([$me['id']]);
                }
            } else {
                throw new UserError('不支援的操作。');
            }
        });
        flash('success', $action === 'delete' ? '收件資料已刪除；既有訂單的收件資料不受影響。' : '已設為預設收件資料。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        if ($action === 'create' || $action === 'update') {
            $_SESSION['_addr_keep'] = $form;
            redirect('member_addresses.php' . ($action === 'update' && $id ? '?edit=' . $id : ''));
        }
    }
    redirect('member_addresses.php');
}

$list = address_list($me['id']);
$editing = null;
if ($editId !== null) {
    $editing = address_find($me['id'], $editId);
    if (!$editing) { render_not_found('收件資料'); }
    $form = array_intersect_key($editing, $form);
}
if (isset($_SESSION['_addr_keep'])) { $form = $_SESSION['_addr_keep']; unset($_SESSION['_addr_keep']); }
page_header('收件資料');
?>
<h1>收件資料</h1>
<p><a href="member.php">← 會員中心</a>　最多 <?= ADDRESS_LIMIT ?> 筆，結帳與換貨時可從這裡選取；修改或刪除不影響已成立的訂單。</p>
<?php if ($list): ?>
<div class="table-wrap"><table>
  <thead><tr><th>收件人</th><th>電話</th><th>地址</th><th>預設</th><th>操作</th></tr></thead>
  <tbody><?php foreach ($list as $a): ?>
    <tr><td><?= h($a['recipient']) ?></td><td><?= h($a['phone']) ?></td><td><?= h(address_line($a)) ?></td>
      <td><?= (int)$a['is_default'] ? badge('預設') : '' ?></td>
      <td class="actions">
        <a class="btn btn-small" href="member_addresses.php?edit=<?= (int)$a['id'] ?>">修改</a>
        <?php if (!(int)$a['is_default']): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="default"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$a['row_version'] ?>"><button class="btn btn-small">設為預設</button></form>
        <?php endif; ?>
        <form method="post" class="inline" onsubmit="return confirm('確定刪除這筆收件資料？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$a['row_version'] ?>"><button class="btn btn-small btn-danger">刪除</button></form>
      </td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php else: ?><p class="empty">尚未建立收件資料。</p><?php endif; ?>

<?php if ($editing || count($list) < ADDRESS_LIMIT): ?>
<section class="card">
  <h2><?= $editing ? '修改收件資料' : '新增收件資料' ?></h2>
  <form method="post" action="member_addresses.php" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$editing['row_version'] ?>"><?php endif; ?>
    <div class="grid-3">
      <label>收件人（1～30 字） <input type="text" name="a[recipient]" value="<?= h($form['recipient']) ?>" maxlength="30" required></label>
      <label>電話（數字與 -，建議手機） <input type="tel" name="a[phone]" value="<?= h($form['phone']) ?>" maxlength="20" required></label>
      <label>郵遞區號 <input type="text" name="a[zip]" value="<?= h($form['zip']) ?>" maxlength="6" inputmode="numeric" required></label>
      <label>縣市 <input type="text" name="a[city]" value="<?= h($form['city']) ?>" maxlength="10" required></label>
      <label>鄉鎮區 <input type="text" name="a[district]" value="<?= h($form['district']) ?>" maxlength="10" required></label>
      <label class="span-all">詳細地址 <input type="text" name="a[address]" value="<?= h($form['address']) ?>" maxlength="100" required></label>
    </div>
    <?php if (!$editing): ?><label class="check"><input type="checkbox" name="is_default" value="1"> 設為預設收件資料</label><?php endif; ?>
    <?= captcha_field('address') ?>
    <p class="actions"><button class="btn btn-primary" type="submit">儲存</button><?php if ($editing): ?> <a class="btn" href="member_addresses.php">取消修改</a><?php endif; ?></p>
  </form>
</section>
<?php endif; ?>
<?php
page_footer();
