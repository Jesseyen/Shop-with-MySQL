<?php
/**
 * 會員中心：修改姓名、變更密碼（須驗證舊密碼與辨識碼）；顯示等級、累積消費與距下一級差額；
 * 連到收件資料、我的訂單、客服中心與刪除帳號。會員不得自行修改權限、狀態、等級或累積消費。
 */
require_once __DIR__ . '/app.php';
$me = require_member();

$keep = ['name' => $me['name']];
if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $action = post_str('action');
    try {
        if ($action === 'name') {
            $keep['name'] = post_str('name');
            captcha_check('member_name');
            $name = $keep['name'];
            if (!len_between($name, 1, 50)) { throw new UserError('姓名須為 1～50 個字。'); }
            $v = to_pos_int($_POST['row_version'] ?? '');
            $st = $pdo->prepare('UPDATE members SET name = ?, row_version = row_version + 1
                                  WHERE id = ? AND row_version = ? AND status = 1 AND deleted_at IS NULL');
            $st->execute([$name, $me['id'], $v ?? 0]);
            if ($st->rowCount() !== 1) { throw new UserError('資料已在其他分頁變更，請重新整理後再試。'); }
            flash('success', '姓名已更新。');
            redirect('member.php');
        } elseif ($action === 'password') {
            captcha_check('member_password');
            $old = (string)($_POST['old_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $new2 = (string)($_POST['new_password_confirm'] ?? '');
            if (($e = password_length_error($new)) !== null) { throw new UserError($e); }
            if ($new !== $new2) { throw new UserError('兩次輸入的新密碼不一致。'); }
            db_transaction(function (PDO $pdo) use ($me, $old, $new) {
                $st = $pdo->prepare('SELECT password_hash FROM members WHERE id = ? AND status = 1 AND deleted_at IS NULL FOR UPDATE');
                $st->execute([$me['id']]);
                $hash = $st->fetchColumn();
                if (!$hash || !password_verify($old, $hash)) { throw new UserError('舊密碼不正確。'); }
                $pdo->prepare('UPDATE members SET password_hash = ?, reset_password_token = NULL, reset_password_expires_at = NULL,
                                      row_version = row_version + 1 WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            });
            session_regenerate_id(true);
            flash('success', '密碼已變更。');
            redirect('member.php');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        $_SESSION['_member_keep'] = $keep;
        redirect('member.php');
    }
}
if (isset($_SESSION['_member_keep'])) { $keep = $_SESSION['_member_keep']; unset($_SESSION['_member_keep']); }
$me = current_member(true);

page_header('會員中心');
?>
<h1>會員中心</h1>
<?php if ($enable_launch && $me['deletion_requested_at'] !== null): ?>
  <div class="alert alert-warn">您已申請刪除帳號（<?= h($me['deletion_requested_at']) ?>），管理員處理前可在「<a href="member_delete.php">刪除帳號</a>」頁取消申請。</div>
<?php endif; ?>
<nav class="member-links" aria-label="會員功能">
  <?php if ($enable_orders): ?><a class="btn" href="member_orders.php">我的訂單</a> <a class="btn" href="member_addresses.php">收件資料</a><?php endif; ?>
  <?php if ($enable_ops): ?><a class="btn" href="member_support.php">客服中心</a><?php endif; ?>
  <?php if ($enable_launch): ?><a class="btn" href="member_delete.php">刪除帳號</a><?php endif; ?>
</nav>
<div class="grid-2">
  <section class="card">
    <h2>帳號資料</h2>
    <dl class="meta">
      <dt>Email</dt><dd><?= h($me['email']) ?></dd>
      <dt>會員等級</dt><dd><?= h(member_level_label($me['member_level'])) ?><?= (int)$me['level_locked'] === 1 ? '（已由商店設定）' : '' ?></dd>
      <dt>累積消費</dt><dd><?= money($me['total_spent']) ?></dd>
      <?php if ($enable_orders && (int)$me['level_locked'] !== 1 && ($next = level_next($me['member_level'], $me['total_spent']))): ?>
        <dt>升級提示</dt><dd>再消費 <?= money($next['gap']) ?> 可升級為<?= h(member_level_label($next['level'])) ?></dd>
      <?php endif; ?>
      <dt>註冊時間</dt><dd><?= h($me['created_at']) ?></dd>
    </dl>
    <form method="post" action="member.php" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="name">
      <input type="hidden" name="row_version" value="<?= (int)$me['row_version'] ?>">
      <label>姓名 <input type="text" name="name" value="<?= h($keep['name']) ?>" maxlength="50" required></label>
      <?= captcha_field('member_name') ?>
      <button class="btn btn-primary" type="submit">更新姓名</button>
    </form>
  </section>
  <section class="card">
    <h2>變更密碼</h2>
    <form method="post" action="member.php" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <label>舊密碼 <input type="password" name="old_password" maxlength="72" required autocomplete="current-password"></label>
      <label>新密碼（8～72 字元） <input type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
      <label>確認新密碼 <input type="password" name="new_password_confirm" minlength="8" maxlength="72" required autocomplete="new-password"></label>
      <?= captcha_field('member_password') ?>
      <button class="btn btn-primary" type="submit">變更密碼</button>
    </form>
  </section>
</div>
<?php
page_footer();
