<?php
/**
 * 會員申請刪除帳號（第 25 節）：輸入密碼與辨識碼並勾選「我了解刪除後無法復原」；原因選填。
 * 申請後帳號仍可使用，管理員處理前可自行取消申請。仍在退換貨期限內的訂單會提醒「刪除後將無法申請退換貨」。
 */
require_once __DIR__ . '/app.php';
if (!$enable_launch) { render_not_found(); }
$me = require_member();
$req = deletion_pending_request($me['id']);

if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $action = post_str('action');
    try {
        captcha_check('member_delete');
        if ($action === 'request') {
            if ($req) { throw new UserError('您已經送出刪除申請。'); }
            if (post_str('agree') !== '1') { throw new UserError('請勾選「我了解刪除後無法復原」。'); }
            $reason = post_str('reason');
            if (mb_strlen($reason) > 500) { throw new UserError('原因最多 500 字。'); }
            db_transaction(function (PDO $pdo) use ($me, $reason) {
                $st = $pdo->prepare('SELECT password_hash FROM members WHERE id = ? FOR UPDATE');
                $st->execute([$me['id']]);
                if (!password_verify((string)($_POST['password'] ?? ''), (string)$st->fetchColumn())) { throw new UserError('密碼不正確。'); }
                $pdo->prepare('INSERT INTO account_deletion_requests (member_id, reason) VALUES (?, ?)')->execute([$me['id'], $reason === '' ? null : $reason]);
                $pdo->prepare('UPDATE members SET deletion_requested_at = NOW(), row_version = row_version + 1 WHERE id = ?')->execute([$me['id']]);
                $email = $me['email'];
                $name = $me['name'];
                $id = $me['id'];
                defer_mail(function () use ($email, $name, $id) {
                    send_notice_mail($email, $name, 'deletion_requested', '已收到刪除帳號申請', [$name . ' 您好：',
                        '我們已收到您的刪除帳號申請。管理員確認訂單、退換貨與客服訊息都已結案後，會刪除您的帳號並清除姓名、地址、電話等個人資料。',
                        '處理前您仍可登入使用，也可以在會員中心取消申請。'], ['member_id' => $id]);
                });
            });
            flash('success', '已送出刪除帳號申請。管理員處理前您可以取消申請。');
        } elseif ($action === 'cancel') {
            db_transaction(function (PDO $pdo) use ($me) {
                $st = $pdo->prepare("UPDATE account_deletion_requests SET status = 'cancelled', handled_at = NOW(), row_version = row_version + 1 WHERE member_id = ? AND status = 'pending'");
                $st->execute([$me['id']]);
                if ($st->rowCount() < 1) { throw new UserError('沒有可取消的申請。'); }
                $pdo->prepare('UPDATE members SET deletion_requested_at = NULL, row_version = row_version + 1 WHERE id = ?')->execute([$me['id']]);
            });
            flash('success', '已取消刪除帳號申請。');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('member_delete.php');
}
$inWindow = [];
if ($enable_orders) {
    $st = $pdo->prepare("SELECT * FROM orders WHERE member_id = ? AND status IN ('shipped','completed')");
    $st->execute([$me['id']]);
    foreach ($st->fetchAll() as $o) {
        $dl = order_return_deadline($o);
        if ($o['status'] === 'shipped' || ($dl && strtotime($dl) >= time())) { $inWindow[] = $o['order_no']; }
    }
}
page_header('刪除帳號');
?>
<section class="card narrow">
  <h1>刪除帳號</h1>
  <p><a href="member.php">← 會員中心</a></p>
  <?php if ($req): ?>
    <div class="alert alert-info">您已於 <?= h($req['requested_at']) ?> 申請刪除帳號，等待管理員處理。處理前您仍可使用帳號。</div>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><?= captcha_field('member_delete') ?><button class="btn">取消申請</button></form>
  <?php else: ?>
    <p>管理員確認您的訂單、退換貨、出貨與客服訊息都已結案後，會刪除帳號並清除姓名、地址、電話等個人資料；訂單編號、金額與商品明細依帳務需要保留（不再連結到可識別的個人）。</p>
    <?php if ($inWindow): ?><div class="alert alert-warn">以下訂單仍在退換貨期限內，刪除後將無法申請退換貨：<?= h(implode('、', $inWindow)) ?></div><?php endif; ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="request">
      <label>原因（選填，最多 500 字） <textarea name="reason" rows="3" maxlength="500"></textarea></label>
      <label>密碼 <input type="password" name="password" maxlength="72" required autocomplete="current-password"></label>
      <label class="check"><input type="checkbox" name="agree" value="1" required> 我了解刪除後無法復原</label>
      <?= captcha_field('member_delete') ?>
      <button class="btn btn-danger">申請刪除帳號</button></form>
  <?php endif; ?>
</section>
<?php
page_footer();
