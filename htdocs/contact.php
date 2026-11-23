<?php
/**
 * 聯繫表單（訪客）：送出前勾選同意個資保護聲明並輸入辨識碼；同一 IP 每小時最多 5 則；
 * 送出後顯示訊息編號並寄確認信。登入中的會員提示可改用客服中心。
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/support.php';
if (!$enable_ops) { render_not_found(); }
$member = current_member();

$form = ['name' => '', 'email' => '', 'phone' => '', 'category' => '', 'subject' => '', 'body' => ''];
$errors = [];
$done = null;
if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $form = array_merge($form, array_map(function ($v) { return is_string($v) ? $v : ''; }, array_intersect_key($_POST, $form)));
    try {
        captcha_check('contact');
        if (post_str('agree') !== '1') { throw new UserError('請勾選同意個資保護聲明。'); }
        if (support_rate_limited()) {
            security_log('contact_limit', '聯繫表單超過每小時 5 則', true);
            throw new UserError('送出次數過多，請稍後再試（同一網路每小時最多 5 則）。');
        }
        [$d, $errors] = support_validate($form, false);
        if (!$errors) {
            [$id, $no] = db_transaction(function (PDO $pdo) use ($d) { return support_create($pdo, 'contact', null, $d); });
            support_confirm_mail('contact_confirm', $id, $no, $d['email'], $d['name'], $d['subject'], null);
            $done = $no;
        }
    } catch (UserError $e) {
        $errors[] = $e->getMessage();
    }
}
page_header('聯繫我們', ['canonical' => 'contact.php', 'page_type' => 'contact']);
?>
<section class="card narrow">
  <h1>聯繫我們</h1>
  <?php if ($done): ?>
    <div class="alert alert-success">已收到您的訊息，編號 <strong><?= h($done) ?></strong>。確認信已寄到您填寫的 Email，客服會盡快回覆。</div>
    <p><a class="btn" href="index.php">返回首頁</a></p>
  <?php else: ?>
    <?php if ($member): ?><div class="alert alert-info">您已登入會員，建議改用 <a href="member_support.php">會員中心的客服中心</a>，可以選擇相關訂單並在線上查看回覆。</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="contact.php" class="stack">
      <?= csrf_field() ?>
      <label>姓名 <input type="text" name="name" value="<?= h($form['name']) ?>" maxlength="50" required></label>
      <label>Email <input type="email" name="email" value="<?= h($form['email']) ?>" maxlength="191" required></label>
      <label>電話（選填） <input type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="20"></label>
      <label>類別 <select name="category" required><option value="">請選擇</option><?= options_html(support_category_labels(), $form['category']) ?></select></label>
      <label>主旨（1～100 字） <input type="text" name="subject" value="<?= h($form['subject']) ?>" maxlength="100" required></label>
      <label>內容（10～2,000 字） <textarea name="body" rows="6" minlength="10" maxlength="2000" required data-count><?= h($form['body']) ?></textarea></label>
      <label class="check"><input type="checkbox" name="agree" value="1" required> 我已閱讀並同意 <a href="privacy.php" target="_blank">個資保護聲明</a></label>
      <?= captcha_field('contact') ?>
      <button class="btn btn-primary">送出</button>
    </form>
  <?php endif; ?>
</section>
<?php
page_footer();
