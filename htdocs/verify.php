<?php
/**
 * Email 驗證：信中連結以 GET 開啟。
 * 憑證 30 分鐘內有效、只能使用一次；停權或軟刪除帳號不能經由驗證重新啟用。
 */
require_once __DIR__ . '/app.php';

$returnTo = remember_return_to($_GET['return_to'] ?? null);
$hash = token_hash_from_input(get_str('token'));
$ok = false;
if ($hash !== null) {
    $st = $pdo->prepare('UPDATE members
        SET status = 1, email_verified_at = NOW(), email_verify_token_hash = NULL, email_verify_expires_at = NULL,
            row_version = row_version + 1
        WHERE email_verify_token_hash = ? AND email_verify_expires_at > NOW() AND status = 2 AND deleted_at IS NULL');
    $st->execute([$hash]);
    $ok = $st->rowCount() === 1;
}

$loginLink = 'login.php' . ($returnTo ? '?return_to=' . urlencode($returnTo) : '');
page_header('Email 驗證');
?>
<section class="card narrow">
  <?php if ($ok): ?>
    <h1>驗證成功</h1>
    <p>您的帳號已啟用，請登入開始使用。<?= $returnTo ? '登入後會返回您原本瀏覽的商品頁。' : '' ?></p>
    <p><a class="btn btn-primary" href="<?= h($loginLink) ?>">前往登入</a></p>
  <?php else: ?>
    <h1>驗證連結無效</h1>
    <p>此連結可能已過期（超過 30 分鐘）、已使用過，或帳號狀態不允許驗證。</p>
    <p><a class="btn" href="resend_verify.php<?= $returnTo ? '?return_to=' . urlencode($returnTo) : '' ?>">補寄驗證信</a>
       <a class="btn" href="<?= h($loginLink) ?>">前往登入</a></p>
  <?php endif; ?>
</section>
<?php
page_footer();
