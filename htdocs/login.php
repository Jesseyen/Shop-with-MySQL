<?php
require_once __DIR__ . '/app.php';

$returnTo = remember_return_to($_GET['return_to'] ?? ($_POST['return_to'] ?? null))
    ?? validate_return_to($_SESSION['return_to'] ?? null);

if (current_member()) {
    redirect(take_return_to());
}

$error = null;
$emailValue = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $email = normalize_email(post_str('email'));
    $pw = (string)($_POST['password'] ?? '');
    $emailValue = $email;
    try {
        captcha_check('login');
    } catch (UserError $e) {
        $error = $e->getMessage();
    }

    $fails = $_SESSION['_login_fail'] ?? ['n' => 0, 't' => 0];
    if ($error !== null) {
        // 辨識碼錯誤：保留 Email，重新輸入
    } elseif ($fails['n'] >= 5 && time() - $fails['t'] < 60) {
        $error = '嘗試次數過多，請 1 分鐘後再試。';
    } else {
        $st = $pdo->prepare('SELECT id, name, password_hash, status, deleted_at FROM members WHERE email = ?');
        $st->execute([$email]);
        $m = $st->fetch();
        if ($m && password_verify($pw, $m['password_hash'])) {
            if ($m['deleted_at'] !== null || (int)$m['status'] === 0) {
                $error = '此帳號目前無法登入，如有疑問請聯絡網站管理員。';
            } elseif ((int)$m['status'] === 2) {
                $error = '帳號尚未完成 Email 驗證，請先點擊驗證信中的連結，或使用「補寄驗證信」。';
            } else {
                if (password_needs_rehash($m['password_hash'], PASSWORD_DEFAULT)) {
                    $up = $pdo->prepare('UPDATE members SET password_hash = ? WHERE id = ?');
                    $up->execute([password_hash($pw, PASSWORD_DEFAULT), $m['id']]);
                }
                unset($_SESSION['_login_fail']);
                login_member($m);
                flash('success', '歡迎回來，' . $m['name'] . '！');
                redirect(take_return_to());
            }
        } else {
            $fails = ['n' => $fails['n'] + 1, 't' => time()];
            $_SESSION['_login_fail'] = $fails;
            $error = 'Email 或密碼錯誤。';
        }
    }
}

page_header('會員登入');
?>
<section class="card narrow">
  <h1>會員登入</h1>
  <?php if ($returnTo): ?><p class="muted">登入後會返回您原本瀏覽的商品頁。</p><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="login.php" class="stack">
    <?= csrf_field() ?>
    <?php if ($returnTo): ?><input type="hidden" name="return_to" value="<?= h($returnTo) ?>"><?php endif; ?>
    <label>Email <input type="email" name="email" value="<?= h($emailValue) ?>" maxlength="191" required autocomplete="username"></label>
    <label>密碼 <input type="password" name="password" maxlength="72" required autocomplete="current-password"></label>
    <?= captcha_field('login') ?>
    <button class="btn btn-primary" type="submit">登入</button>
  </form>
  <p><a href="forgot_password.php">忘記密碼？</a>
     <a href="register.php<?= $returnTo ? '?return_to=' . urlencode($returnTo) : '' ?>">註冊新帳號</a>
     <a href="resend_verify.php<?= $returnTo ? '?return_to=' . urlencode($returnTo) : '' ?>">補寄驗證信</a></p>
</section>
<?php
page_footer();
