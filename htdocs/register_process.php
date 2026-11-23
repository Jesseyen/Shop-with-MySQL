<?php
require_once __DIR__ . '/app.php';
require_csrf();

if (current_member()) { redirect('member.php'); }

$name = post_str('name');
$email = normalize_email(post_str('email'));
$pw = (string)($_POST['password'] ?? '');
$pw2 = (string)($_POST['password_confirm'] ?? '');
$returnTo = remember_return_to($_POST['return_to'] ?? null);

$errors = [];
try {
    captcha_check('register');
} catch (UserError $e) {
    $_SESSION['_old_register'] = ['name' => $name, 'email' => $email];
    $_SESSION['_register_errors'] = [$e->getMessage()];
    redirect('register.php' . ($returnTo ? '?return_to=' . urlencode($returnTo) : ''));
}
maintenance_block_write();
$nameLen = mb_strlen($name);
if ($nameLen < 1 || $nameLen > 50) { $errors[] = '姓名須為 1～50 個字。'; }
if (!valid_email($email)) { $errors[] = 'Email 格式不正確。'; }
if (($e = password_length_error($pw)) !== null) { $errors[] = $e; }
if ($pw !== $pw2) { $errors[] = '兩次輸入的密碼不一致。'; }

if (!$errors) {
    [$plain, $hash] = new_token();
    try {
        $st = $pdo->prepare('INSERT INTO members (name, email, password_hash, status, email_verify_token_hash, email_verify_expires_at)
                             VALUES (?, ?, ?, 2, ?, NOW() + INTERVAL 30 MINUTE)');
        $st->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), $hash]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            $errors[] = '此 Email 已經註冊過。若尚未驗證，請使用「補寄驗證信」；若忘記密碼請使用「忘記密碼」。';
        } else {
            throw $ex;
        }
    }
}

if ($errors) {
    $_SESSION['_old_register'] = ['name' => $name, 'email' => $email];
    $_SESSION['_register_errors'] = $errors;
    redirect('register.php' . ($returnTo ? '?return_to=' . urlencode($returnTo) : ''));
}

$sent = send_verify_mail($email, $name, $plain, $returnTo, 'verify', (int)$pdo->lastInsertId());

page_header('註冊完成');
?>
<section class="card narrow">
  <h1>註冊完成，請驗證 Email</h1>
  <?php if ($sent): ?>
    <p>驗證信已寄到 <strong><?= h($email) ?></strong>，請在 30 分鐘內點擊信中連結啟用帳號。</p>
  <?php else: ?>
    <div class="alert alert-warn">帳號已建立，但驗證信暫時無法寄出，請稍後使用「補寄驗證信」。</div>
  <?php endif; ?>
  <p>沒收到信？請檢查垃圾郵件匣，或 <a href="resend_verify.php<?= $returnTo ? '?return_to=' . urlencode($returnTo) : '' ?>">補寄驗證信</a>。</p>
</section>
<?php
page_footer();
