<?php
/**
 * 管理員與權限（系統管理者，第 22 節）
 * 從已啟用、已驗證的會員中指定管理員，逐模組授權「檢視」或「編輯」，或設為系統管理者；取消全部權限即回到一般會員。
 * 保護：只有系統管理者能授權、不能變更自己的權限、不能變更註冊時間早於或等於自己的系統管理者、至少保留一位啟用中的系統管理者。
 */
require_once __DIR__ . '/app.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');
$modules = admin_modules();

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '') ?? 0;
    try {
        admin_transaction(function (PDO $pdo, array $a) use ($id, $modules) {
            if ($id === $a['id']) { throw new UserError('不能變更自己的權限。'); }
            $st = $pdo->prepare('SELECT * FROM members WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t || $t['deleted_at'] !== null) { throw new UserError('找不到此會員。'); }
            assert_row_version($t, $_POST['row_version'] ?? '');
            if ((int)$t['is_super_admin'] === 1 && strcmp((string)$t['created_at'], (string)$a['created_at']) <= 0) {
                throw new UserError('不能變更註冊時間早於或等於您的系統管理者。');
            }
            $super = post_str('super') === '1' ? 1 : 0;
            $perms = [];
            foreach ($modules as $k => $_) {
                $lv = (string)(post_arr('perm')[$k] ?? '');
                if ($lv === 'view' || $lv === 'edit') { $perms[$k] = $lv; }
            }
            if (isset($perms['report']) && $perms['report'] === 'edit') { $perms['report'] = 'view'; }
            $staff = ($super || $perms) ? 1 : 0;
            if ($staff && ((int)$t['status'] !== 1 || $t['email_verified_at'] === null)) { throw new UserError('只能指定已啟用、已完成 Email 驗證的會員為管理員。'); }
            $before = ['is_admin' => (int)$t['is_admin'], 'is_super_admin' => (int)$t['is_super_admin'], 'perms' => admin_perm_map($id, true)];
            $pdo->prepare('UPDATE members SET is_admin = ?, is_super_admin = ?, row_version = row_version + 1 WHERE id = ?')->execute([$staff, $super, $id]);
            $pdo->prepare('DELETE FROM admin_permissions WHERE member_id = ?')->execute([$id]);
            if (!$super) {
                $ins = $pdo->prepare('INSERT INTO admin_permissions (member_id, module, level, granted_by) VALUES (?, ?, ?, ?)');
                foreach ($perms as $mod => $lv) { $ins->execute([$id, $mod, $lv, $a['id']]); }
            }
            $n = (int)$pdo->query('SELECT COUNT(*) FROM members WHERE is_super_admin = 1 AND status = 1 AND deleted_at IS NULL')->fetchColumn();
            if ($n < 1) { throw new UserError('至少需保留一位啟用中的系統管理者。'); }
            audit_log($pdo, $a['id'], 'admin_permission', $id, 'update', $before,
                ['is_admin' => $staff, 'is_super_admin' => $super, 'perms' => $super ? 'all' : $perms]);
        });
        flash('success', '權限已更新，立即生效。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_admins.php?id=' . $id);
}

$id = to_pos_int($_GET['id'] ?? '');
$q = get_str('q');
$cands = [];
if ($q !== '') {
    $st = $pdo->prepare('SELECT id, name, email FROM members WHERE status = 1 AND deleted_at IS NULL AND email_verified_at IS NOT NULL
                          AND (email LIKE ? OR name LIKE ? OR id = ?) ORDER BY id LIMIT 20');
    $l = '%' . addcslashes($q, '%_\\') . '%';
    $st->execute([$l, $l, ctype_digit($q) ? (int)$q : 0]);
    $cands = $st->fetchAll();
}
$staff = $pdo->query('SELECT id, name, email, is_super_admin, status, created_at FROM members WHERE is_admin = 1 AND deleted_at IS NULL ORDER BY is_super_admin DESC, id')->fetchAll();
admin_header('管理員與權限');
?>
<h1>管理員與權限</h1>
<p class="muted">系統管理者擁有全部權限（含所有設定類頁面）；其他管理員依模組授權「檢視」或「編輯」，編輯包含檢視；報表分析為唯讀模組。管理員只看到有權限的選單、頁面、按鈕與總覽卡片。</p>
<?php if ($id !== null):
    $st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) { render_not_found('會員'); }
    $map = admin_perm_map($id, true);
    $locked = $id === $me['id'] || ((int)$t['is_super_admin'] === 1 && strcmp((string)$t['created_at'], (string)$me['created_at']) <= 0);
?>
<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= (int)$t['row_version'] ?>">
  <h2><?= h($t['name']) ?>（#<?= $id ?>，<?= h($t['email']) ?>）</h2>
  <?php if ($locked): ?><div class="alert alert-warn"><?= $id === $me['id'] ? '不能變更自己的權限。' : '不能變更註冊時間早於或等於您的系統管理者。' ?></div><?php endif; ?>
  <fieldset class="bare" <?= $locked ? 'disabled' : '' ?>>
  <label class="check"><input type="checkbox" name="super" value="1" <?= (int)$t['is_super_admin'] ? 'checked' : '' ?>> 系統管理者（全部權限）</label>
  <div class="table-wrap"><table><thead><tr><th>模組</th><th>無</th><th>檢視</th><th>編輯</th></tr></thead><tbody>
  <?php foreach ($modules as $k => $label): $cur = $map[$k] ?? ''; ?>
    <tr><td><?= h($label) ?></td>
      <td><input type="radio" name="perm[<?= $k ?>]" value="" <?= $cur === '' ? 'checked' : '' ?> aria-label="<?= h($label) ?> 無"></td>
      <td><input type="radio" name="perm[<?= $k ?>]" value="view" <?= $cur === 'view' ? 'checked' : '' ?> aria-label="<?= h($label) ?> 檢視"></td>
      <td><?php if ($k !== 'report'): ?><input type="radio" name="perm[<?= $k ?>]" value="edit" <?= $cur === 'edit' ? 'checked' : '' ?> aria-label="<?= h($label) ?> 編輯"><?php else: ?>—（唯讀）<?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted small">取消全部權限（且不勾選系統管理者）即回到一般會員。</p>
  <button class="btn btn-primary">儲存權限</button></fieldset>
  <p><a href="admin_admins.php">← 管理員列表</a></p>
</form>
<?php endif; ?>
<section class="card"><h2>目前的管理員</h2><div class="table-wrap"><table><thead><tr><th>會員</th><th>Email</th><th>權限</th><th></th></tr></thead><tbody>
<?php foreach ($staff as $s): $map = admin_perm_map((int)$s['id'], true); ?>
  <tr><td><?= h($s['name']) ?>（#<?= (int)$s['id'] ?>）</td><td><?= h($s['email']) ?></td>
    <td><?= (int)$s['is_super_admin'] ? badge('系統管理者') : h(implode('、', array_map(function ($k, $v) use ($modules) { return $modules[$k] . ($v === 'edit' ? '（編輯）' : '（檢視）'); }, array_keys($map), $map)) ?: '—') ?></td>
    <td><a class="btn btn-small" href="admin_admins.php?id=<?= (int)$s['id'] ?>">設定</a></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<section class="card"><h2>新增管理員</h2>
  <form method="get" class="row-form"><label>搜尋已啟用、已驗證的會員（Email、姓名或編號） <input type="search" name="q" value="<?= h($q) ?>" maxlength="100"></label><button class="btn">搜尋</button></form>
  <?php if ($q !== ''): ?><ul><?php foreach ($cands as $c): ?><li><?= h($c['name']) ?>（#<?= (int)$c['id'] ?>，<?= h($c['email']) ?>） <a class="btn btn-small" href="admin_admins.php?id=<?= (int)$c['id'] ?>">設定權限</a></li><?php endforeach; ?>
    <?php if (!$cands): ?><li class="muted">找不到符合的會員。</li><?php endif; ?></ul><?php endif; ?>
</section>
<?php
page_footer();
