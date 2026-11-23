<?php
/**
 * 設定新密碼：GET 顯示表單、POST 寫入。
 * 每次都重新檢查帳號狀態、憑證與期限；以同一筆條件更新寫入新密碼並清除憑證，
 * 只有實際更新一筆才顯示成功。輸入錯誤時保留表單（憑證未被消耗，可直接重填）。
 */
require_once __DIR__ . '/app.php';

$token = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? post_str('token') : get_str('token');
$hash = token_hash_from_input($token);

function reset_target(PDO $pdo, ?string $hash): ?array
{
    if ($hash === null) { return null; }
    $st = $pdo->prepare('SELECT id, name FROM members
                          WHERE reset_password_token = ? AND reset_password_expires_at > NOW()
                            AND status = 1 AND deleted_at IS NULL');
    $st->execute([$hash]);
    $m = $st->fetch();
    return $m ?: null;
}

$target = reset_target($pdo, $hash);
$errors = [];
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    try {
        captcha_check('reset');
    } catch (UserError $e) {
        $errors[] = $e->getMessage();
    }
    if ($target && !$errors) {
        $pw = (string)($_POST['password'] ?? '');
        $pw2 = (string)($_POST['password_confirm'] ?? '');
        if (($e = password_length_error($pw)) !== null) { $errors[] = $e; }
        if ($pw !== $pw2) { $errors[] = '兩次輸入的密碼不一致。'; }
        if (!$errors) {
            $up = $pdo->prepare('UPDATE members
                SET password_hash = ?, reset_password_token = NULL, reset_password_expires_at = NULL, row_version = row_version + 1
                WHERE id = ? AND reset_password_token = ? AND reset_password_expires_at > NOW()
                  AND status = 1 AND deleted_at IS NULL');
            $up->execute([password_hash($pw, PASSWORD_DEFAULT), $target['id'], $hash]);
            $success = $up->rowCount() === 1;
            if (!$success) { $target = null; }
        }
    }
}

page_header('設定新密碼');
?>
<section class="card narrow">
  <h1>設定新密碼</h1>
  <?php if ($success): ?>
    <div class="alert alert-success">密碼已更新，請使用新密碼登入。舊密碼與此重設連結已失效。</div>
    <p><a class="btn btn-primary" href="login.php">返回登入</a></p>
  <?php elseif (!$target): ?>
    <div class="alert alert-error">此重設連結無效、已使用或已過期（超過 30 分鐘）。</div>
    <p><a class="btn" href="forgot_password.php">重新申請重設密碼</a></p>
  <?php else: ?>
    <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <p><?= h($target['name']) ?> 您好，請輸入新密碼（8～72 字元）。</p>
    <form method="post" action="reset_password.php" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label>新密碼 <input type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
      <label>確認新密碼 <input type="password" name="password_confirm" minlength="8" maxlength="72" required autocomplete="new-password"></label>
      <?= captcha_field('reset') ?>
      <button class="btn btn-primary" type="submit">更新密碼</button>
    </form>
  <?php endif; ?>
</section>
<?php
page_footer();
