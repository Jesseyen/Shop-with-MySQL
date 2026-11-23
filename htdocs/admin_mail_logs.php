<?php
/**
 * 寄信紀錄（系統管理者，第 18、34 節）：依類型、結果、日期、Email 篩選，每頁 50 筆，失敗醒目標示；
 * 寄測試信驗證 SMTP；第六階段顯示今日訪客觸發信件額度與超額 IP
 */
require_once __DIR__ . '/app.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

if (is_post()) {
    require_csrf();
    $to = normalize_email(post_str('to'));
    if (!valid_email($to)) {
        flash('error', '請輸入正確的 Email。');
    } else {
        $ok = send_notice_mail($to, $me['name'], 'test', 'SMTP 測試信', ['這是一封測試信，收到代表 SMTP 設定正確。', '寄出時間：' . date('Y-m-d H:i:s')], ['member_id' => $me['id']]);
        flash($ok ? 'success' : 'error', $ok ? '測試信已寄出，請到信箱確認。' : '測試信寄送失敗，原因請見下方紀錄。');
    }
    redirect('admin_mail_logs.php');
}
$f = ['type' => get_str('type'), 'result' => get_str('result'), 'from' => get_str('from'), 'to' => get_str('to'), 'email' => get_str('email')];
$where = [];
$args = [];
if (isset(mail_type_labels()[$f['type']])) { $where[] = 'mail_type = ?'; $args[] = $f['type']; }
if (in_array($f['result'], ['success', 'failed', 'quota_blocked'], true)) { $where[] = 'result = ?'; $args[] = $f['result']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
if ($f['email'] !== '') { $where[] = 'to_email LIKE ?'; $args[] = '%' . addcslashes($f['email'], '%_\\') . '%'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = current_page();
$per = 50;
$st = $pdo->prepare("SELECT COUNT(*) FROM mail_logs {$w}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT * FROM mail_logs {$w} ORDER BY id DESC LIMIT {$per} OFFSET {$off}");
$st->execute($args);
$rows = $st->fetchAll();
$results = ['success' => '成功', 'failed' => '失敗', 'quota_blocked' => '超過額度未寄出'];
admin_header('寄信紀錄');
?>
<h1>寄信紀錄</h1>
<p class="muted">每封系統信都會記錄（不儲存信件內文與連結）；失敗原因只在後台顯示。紀錄保留 180 天。</p>
<?php if ($enable_launch):
    $used = (int)$pdo->query("SELECT COUNT(*) FROM mail_logs WHERE visitor_triggered = 1 AND result = 'success' AND created_at >= CURDATE()")->fetchColumn();
    $over = $pdo->query("SELECT ip, COUNT(*) n, MAX(created_at) last FROM mail_logs WHERE result = 'quota_blocked' AND created_at >= CURDATE() GROUP BY ip ORDER BY n DESC LIMIT 20")->fetchAll(); ?>
<section class="card"><h2>今日寄信額度</h2>
  <p>訪客觸發信件今日已寄 <strong><?= $used ?></strong>／<?= setting_int('quota_site_day', 300) ?> 封；同一 IP 每小時 <?= setting_int('quota_ip_hour', 5) ?> 封、每日 <?= setting_int('quota_ip_day', 20) ?> 封；同一 Email 每小時 <?= setting_int('quota_email_hour', 3) ?> 封（<a href="admin_system.php">調整</a>）。</p>
  <?php if ($over): ?><p>今日超額的 IP：</p><ul><?php foreach ($over as $o): ?><li><?= h($o['ip']) ?>：<?= (int)$o['n'] ?> 次（最後 <?= h($o['last']) ?>）</li><?php endforeach; ?></ul><?php else: ?><p class="muted">今日沒有超額紀錄。</p><?php endif; ?>
</section>
<?php endif; ?>
<form method="post" class="card row-form"><?= csrf_field() ?><label>寄測試信到 <input type="email" name="to" value="<?= h($me['email']) ?>" required></label><button class="btn">寄測試信</button></form>
<form class="filters" method="get">
  <label>類型 <select name="type"><option value="">全部</option><?= options_html(mail_type_labels(), $f['type']) ?></select></label>
  <label>結果 <select name="result"><option value="">全部</option><?= options_html($results, $f['result']) ?></select></label>
  <label>日期 <input type="date" name="from" value="<?= h($f['from']) ?>"> ～ <input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <label>Email <input type="text" name="email" value="<?= h($f['email']) ?>" maxlength="191"></label>
  <button class="btn btn-primary">篩選</button> <a class="btn" href="admin_mail_logs.php">清除</a>
</form>
<div class="table-wrap"><table>
  <thead><tr><th>時間</th><th>類型</th><th>收件 Email</th><th>主旨</th><th>結果</th><th>關聯</th><th>IP</th><th class="num">耗時</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr class="<?= $r['result'] !== 'success' ? 'row-error' : '' ?>"><td><?= h($r['created_at']) ?></td><td><?= h(mail_type_labels()[$r['mail_type']] ?? $r['mail_type']) ?></td>
      <td><?= h($r['to_email']) ?></td><td><?= h($r['subject']) ?></td>
      <td><?= h($results[$r['result']]) ?><?= $r['error_message'] ? '<br><span class="small">' . h($r['error_message']) . '</span>' : '' ?></td>
      <td class="small"><?= $r['member_id'] ? '會員 #' . (int)$r['member_id'] . ' ' : '' ?><?= $r['order_id'] ? '訂單 #' . (int)$r['order_id'] . ' ' : '' ?><?= $r['after_sale_id'] ? '售後 #' . (int)$r['after_sale_id'] . ' ' : '' ?><?= $r['message_id'] ? '訊息 #' . (int)$r['message_id'] : '' ?></td>
      <td class="small"><?= h($r['ip'] ?? '') ?></td><td class="num"><?= (int)$r['duration_ms'] ?> ms</td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, query_keep(['page'])) ?>
<?php
page_footer();
