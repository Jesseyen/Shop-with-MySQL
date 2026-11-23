<?php
/**
 * 會員自行補寄驗證信。不論帳號是否存在或狀態為何，都顯示相同訊息，避免洩漏帳號資訊。
 * 補寄會產生新憑證並取代舊憑證；信中帶入原商品返回位置。
 */
require_once __DIR__ . '/app.php';

$returnTo = remember_return_to($_GET['return_to'] ?? ($_POST['return_to'] ?? null))
    ?? validate_return_to($_SESSION['return_to'] ?? null);
$done = false;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $email = normalize_email(post_str('email'));
    try {
        captcha_check('resend');
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
    if ($error !== null) {
    } elseif (!valid_email($email)) {
        $error = '請輸入正確的 Email。';
    } else {
        // 簡易節流：同一 Session 60 秒內只處理一次
        $last = $_SESSION['_resend_at'] ?? 0;
        if (time() - $last >= 60) {
            $_SESSION['_resend_at'] = time();
            $st = $pdo->prepare('SELECT id, name, email FROM members WHERE email = ? AND status = 2 AND deleted_at IS NULL');
            $st->execute([$email]);
            $m = $st->fetch();
            if ($m) {
                $token = issue_verify_token($pdo, (int)$m['id']);
                if ($token !== null) {
                    send_verify_mail($m['email'], $m['name'], $token, $returnTo, 'resend_verify', (int)$m['id']);
                }
            }
        }
        $done = true;
    }
}

page_header('補寄驗證信');
?>
<section class="card narrow">
  <h1>補寄驗證信</h1>
  <?php if ($done): ?>
    <div class="alert alert-success">如果這個 Email 有待驗證的帳號，我們已寄出新的驗證信（30 分鐘內有效，舊連結隨即失效）。若 60 秒內重複申請，系統不會重寄。</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="resend_verify.php" class="stack">
    <?= csrf_field() ?>
    <?php if ($returnTo): ?><input type="hidden" name="return_to" value="<?= h($returnTo) ?>"><?php endif; ?>
    <label>註冊 Email <input type="email" name="email" value="<?= h($email ?? '') ?>" maxlength="191" required autocomplete="email"></label>
    <?= captcha_field('resend') ?>
    <button class="btn btn-primary" type="submit">寄出驗證信</button>
  </form>
  <p><a href="login.php">返回登入</a></p>
</section>
<?php
page_footer();
