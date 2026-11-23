<?php
/**
 * 申請密碼重設：只為已啟用且未軟刪除的會員產生連結。
 * 資料庫存 SHA-256 雜湊，30 分鐘到期（由資料庫計算），重新申請會取代舊憑證。
 * 不論帳號狀態或寄信結果，都顯示相同訊息。
 */
require_once __DIR__ . '/app.php';

$done = false;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $email = normalize_email(post_str('email'));
    try {
        captcha_check('forgot');
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
    if ($error !== null) {
    } elseif (!valid_email($email)) {
        $error = '請輸入正確的 Email。';
    } else {
        $last = $_SESSION['_forgot_at'] ?? 0;
        if (time() - $last >= 60) {
            $_SESSION['_forgot_at'] = time();
            $st = $pdo->prepare('SELECT id, name, email FROM members WHERE email = ? AND status = 1 AND deleted_at IS NULL');
            $st->execute([$email]);
            $m = $st->fetch();
            if ($m) {
                [$plain, $hash] = new_token();
                $up = $pdo->prepare('UPDATE members SET reset_password_token = ?, reset_password_expires_at = NOW() + INTERVAL 30 MINUTE
                                      WHERE id = ? AND status = 1 AND deleted_at IS NULL');
                $up->execute([$hash, $m['id']]);
                if ($up->rowCount() === 1) {
                    try {
                        send_reset_mail($m['email'], $m['name'], $plain, (int)$m['id']);
                    } catch (Throwable $e) {
                        error_log('[shop] 重設信例外：' . $e->getMessage());
                    }
                }
            }
        }
        $done = true;
    }
}

page_header('忘記密碼');
?>
<section class="card narrow">
  <h1>忘記密碼</h1>
  <?php if ($done): ?>
    <div class="alert alert-success">如果此 Email 屬於可使用的會員帳號，我們已寄出重設密碼連結，30 分鐘內有效；重新申請會使舊連結失效。若 60 秒內重複申請，系統不會重寄。</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="forgot_password.php" class="stack">
    <?= csrf_field() ?>
    <label>註冊 Email <input type="email" name="email" value="<?= h($email ?? '') ?>" maxlength="191" required autocomplete="email"></label>
    <?= captcha_field('forgot') ?>
    <button class="btn btn-primary" type="submit">寄送重設連結</button>
  </form>
  <p><a href="login.php">返回登入</a></p>
</section>
<?php
page_footer();
