<?php
/**
 * 會員中心「客服中心」：欄位與聯繫表單相同，姓名與 Email 帶入會員資料，可選擇相關訂單；
 * 可查看自己送出過的訊息、處理狀態與客服回覆（不含內部處理摘要）
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/support.php';
if (!$enable_ops) { render_not_found(); }
$me = require_member();

$form = ['phone' => '', 'category' => '', 'subject' => '', 'body' => '', 'order_id' => ''];
$errors = [];
if (is_post()) {
    require_csrf();
    maintenance_block_write();
    $form = array_merge($form, array_map(function ($v) { return is_string($v) ? $v : ''; }, array_intersect_key($_POST, $form)));
    try {
        captcha_check('support');
        [$d, $errors] = support_validate($form, true);
        if ($d['order_id'] !== null && (!$enable_orders || !order_find($d['order_id'], $me['id']))) { $errors[] = '相關訂單不正確。'; }
        if (!$errors) {
            [$id, $no] = db_transaction(function (PDO $pdo) use ($d, $me) { return support_create($pdo, 'member', $me, $d); });
            support_confirm_mail('support_member', $id, $no, $me['email'], $me['name'], $d['subject'], $me['id']);
            flash('success', '已送出，訊息編號 ' . $no . '。');
            redirect('member_support.php?id=' . $id);
        }
    } catch (UserError $e) {
        $errors[] = $e->getMessage();
    }
}

$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $st = $pdo->prepare('SELECT s.*, o.order_no FROM support_messages s LEFT JOIN orders o ON o.id = s.order_id WHERE s.id = ? AND s.member_id = ?');
    $st->execute([$id, $me['id']]);
    $m = $st->fetch();
    if (!$m) { render_not_found('訊息'); }
    $st = $pdo->prepare("SELECT * FROM support_message_events WHERE message_id = ? AND is_public = 1 AND event_type IN ('reply','create','status') ORDER BY id");
    $st->execute([$id]);
    $events = $st->fetchAll();
    page_header('客服訊息 ' . $m['message_no']);
    ?>
    <p><a href="member_support.php">← 客服中心</a></p>
    <h1><?= h($m['subject']) ?></h1>
    <p><?= badge(support_status_labels()[$m['status']]) ?>　<?= h(support_category_labels()[$m['category']]) ?>　編號 <?= h($m['message_no']) ?>　<?= h($m['created_at']) ?><?= $m['order_no'] ? '　訂單 ' . h($m['order_no']) : '' ?></p>
    <section class="card"><h2>您的訊息</h2><?= plain_to_html((string)$m['body']) ?></section>
    <section class="card"><h2>客服回覆</h2>
      <?php $replies = array_filter($events, function ($e) { return $e['event_type'] === 'reply'; }); ?>
      <?php if (!$replies): ?><p class="muted">尚未回覆，客服會盡快處理。</p><?php endif; ?>
      <?php foreach ($replies as $e): ?><div class="reply"><p class="muted small"><?= h(substr($e['created_at'], 0, 19)) ?></p><?= plain_to_html((string)$e['content']) ?></div><?php endforeach; ?>
    </section>
    <?php
    page_footer();
    exit;
}
$st = $pdo->prepare('SELECT * FROM support_messages WHERE member_id = ? ORDER BY id DESC LIMIT 50');
$st->execute([$me['id']]);
$list = $st->fetchAll();
$orders = [];
if ($enable_orders) {
    $st = $pdo->prepare('SELECT id, order_no, created_at FROM orders WHERE member_id = ? ORDER BY id DESC LIMIT 30');
    $st->execute([$me['id']]);
    $orders = $st->fetchAll();
}
page_header('客服中心');
?>
<h1>客服中心</h1>
<p><a href="member.php">← 會員中心</a></p>
<?php if ($list): ?>
<div class="table-wrap"><table><thead><tr><th>編號</th><th>主旨</th><th>類別</th><th>狀態</th><th>時間</th></tr></thead><tbody>
  <?php foreach ($list as $m): ?><tr><td><a href="member_support.php?id=<?= (int)$m['id'] ?>"><?= h($m['message_no']) ?></a></td><td><?= h($m['subject']) ?></td>
    <td><?= h(support_category_labels()[$m['category']]) ?></td><td><?= h(support_status_labels()[$m['status']]) ?></td><td><?= h($m['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<section class="card">
  <h2>發問</h2>
  <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" action="member_support.php" class="stack">
    <?= csrf_field() ?>
    <p class="muted">姓名：<?= h($me['name']) ?>　Email：<?= h($me['email']) ?></p>
    <label>電話（選填） <input type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="20"></label>
    <label>類別 <select name="category" required><option value="">請選擇</option><?= options_html(support_category_labels(), $form['category']) ?></select></label>
    <?php if ($orders): ?><label>相關訂單（選填） <select name="order_id"><option value="">不指定</option>
      <?php foreach ($orders as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $form['order_id'] === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['order_no'] . '（' . substr($o['created_at'], 0, 10) . '）') ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>主旨（1～100 字） <input type="text" name="subject" value="<?= h($form['subject']) ?>" maxlength="100" required></label>
    <label>內容（10～2,000 字） <textarea name="body" rows="6" minlength="10" maxlength="2000" required data-count><?= h($form['body']) ?></textarea></label>
    <?= captcha_field('support') ?>
    <button class="btn btn-primary">送出</button>
  </form>
</section>
<?php
page_footer();
