<?php
require_once __DIR__ . '/app.php';

if (current_member()) { redirect('member.php'); }

$returnTo = remember_return_to($_GET['return_to'] ?? null) ?? validate_return_to($_SESSION['return_to'] ?? null);
$old = $_SESSION['_old_register'] ?? ['name' => '', 'email' => ''];
$errors = $_SESSION['_register_errors'] ?? [];
unset($_SESSION['_old_register'], $_SESSION['_register_errors']);

page_header('註冊會員');
?>
<section class="card narrow">
  <h1>註冊會員</h1>
  <?php if ($errors): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" action="register_process.php" class="stack">
    <?= csrf_field() ?>
    <?php if ($returnTo): ?><input type="hidden" name="return_to" value="<?= h($returnTo) ?>"><?php endif; ?>
    <label>姓名 <input type="text" name="name" value="<?= h($old['name']) ?>" maxlength="50" required autocomplete="name"></label>
    <label>Email <input type="email" name="email" value="<?= h($old['email']) ?>" maxlength="191" required autocomplete="email"></label>
    <label>密碼（8～72 字元） <input type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
    <label>確認密碼 <input type="password" name="password_confirm" minlength="8" maxlength="72" required autocomplete="new-password"></label>
    <?php if ($enable_ops): ?><label class="check"><input type="checkbox" required> 我已閱讀並同意<a href="privacy.php" target="_blank">個資保護聲明</a></label><?php endif; ?>
    <?= captcha_field('register') ?>
    <button class="btn btn-primary" type="submit">註冊</button>
  </form>
  <p class="muted">註冊後會寄出驗證信，請在 30 分鐘內點擊信中連結啟用帳號。</p>
  <p>已有帳號？<a href="login.php<?= $returnTo ? '?return_to=' . urlencode($returnTo) : '' ?>">登入</a>
     沒收到驗證信？<a href="resend_verify.php<?= $returnTo ? '?return_to=' . urlencode($returnTo) : '' ?>">補寄驗證信</a></p>
</section>
<?php
page_footer();
