<?php
/**
 * 維護與清理（系統管理者，第 32、34、35 節）：維護模式、寄信額度、資料清理保留天數與立即清理
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/cleanup.php';
if (!$enable_launch) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'maintenance') {
            $msg = post_str('maintenance_message');
            $until = post_str('maintenance_until');
            if (!len_between($msg, 1, 1000)) { throw new UserError('維護訊息須為 1～1000 字。'); }
            if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $until)) { throw new UserError('預計結束時間格式不正確。'); }
            admin_transaction(function (PDO $pdo, array $a) use ($msg, $until) {
                settings_save($pdo, ['maintenance_enabled' => post_str('enabled') === '1' ? '1' : '0', 'maintenance_message' => $msg,
                                     'maintenance_until' => str_replace('T', ' ', $until)], $a['id']);
            });
            flash('success', post_str('enabled') === '1' ? '維護模式已開啟：訪客與會員會看到維護頁（HTTP 503）。' : '維護模式已關閉。');
        } elseif ($action === 'quota') {
            $kv = [];
            foreach (['quota_ip_hour' => 1000, 'quota_ip_day' => 10000, 'quota_email_hour' => 1000, 'quota_site_day' => 100000] as $k => $max) {
                $v = to_pos_int(post_str($k), $max);
                if ($v === null) { throw new UserError('額度須為 1 以上的整數。'); }
                $kv[$k] = (string)$v;
            }
            admin_transaction(function (PDO $pdo, array $a) use ($kv) { settings_save($pdo, $kv, $a['id']); });
            flash('success', '寄信額度已儲存。');
        } elseif ($action === 'retention') {
            $kv = [];
            foreach (cleanup_tasks() as [$label, $key]) {
                if ($key === null) { continue; }
                $v = to_pos_int(post_str($key), 3650);
                if ($v === null) { throw new UserError($label . '保留天數須為 1～3650。'); }
                $kv[$key] = (string)$v;
            }
            admin_transaction(function (PDO $pdo, array $a) use ($kv) { settings_save($pdo, $kv, $a['id']); });
            flash('success', '保留天數已儲存。');
        } elseif ($action === 'cleanup') {
            $r = cleanup_run_all();
            flash('success', '已清理：' . implode('、', array_map(function ($k, $v) { return cleanup_tasks()[$k][0] . ' ' . $v['count']; }, array_keys($r), $r)));
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_system.php');
}
$runs = [];
foreach (array_keys(cleanup_tasks()) as $t) {
    $st = $pdo->prepare('SELECT * FROM cleanup_runs WHERE task = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$t]);
    $runs[$t] = $st->fetch() ?: null;
}
admin_header('維護與清理');
?>
<h1>維護與清理</h1>
<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="maintenance">
  <h2>維護模式 <?= maintenance_on() ? badge('開啟中', 'warn') : badge('關閉') ?></h2>
  <p class="muted">開啟時前台所有頁面回應 HTTP 503 與 Retry-After，所有前台寫入一律拒絕；登入頁、robots.txt 與模擬付款回報仍可使用；有後台權限且符合 IP 白名單的管理員可正常瀏覽前台與後台。</p>
  <label class="check"><input type="checkbox" name="enabled" value="1" <?= maintenance_on() ? 'checked' : '' ?>> 開啟維護模式</label>
  <label>維護訊息（純文字） <textarea name="maintenance_message" rows="3" maxlength="1000" required><?= h(setting('maintenance_message')) ?></textarea></label>
  <label>預計結束時間（選填） <input type="datetime-local" name="maintenance_until" value="<?= h(str_replace(' ', 'T', substr(setting('maintenance_until'), 0, 16))) ?>"></label>
  <button class="btn btn-primary">儲存</button></form>

<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="quota">
  <h2>寄信額度（訪客觸發的信件）</h2>
  <p class="muted">適用：註冊驗證、補寄驗證、忘記密碼、聯繫確認信、客服中心發問。訂單、出貨、售後等交易通知與後台寄出的信不受限制。超額時不寄出，畫面仍顯示一般訊息。</p>
  <div class="grid-2">
    <label>同一 IP 每小時 <input type="number" name="quota_ip_hour" value="<?= setting_int('quota_ip_hour', 5) ?>" min="1" required></label>
    <label>同一 IP 每日 <input type="number" name="quota_ip_day" value="<?= setting_int('quota_ip_day', 20) ?>" min="1" required></label>
    <label>同一收件 Email 每小時 <input type="number" name="quota_email_hour" value="<?= setting_int('quota_email_hour', 3) ?>" min="1" required></label>
    <label>全站訪客觸發每日上限 <input type="number" name="quota_site_day" value="<?= setting_int('quota_site_day', 300) ?>" min="1" required></label>
  </div>
  <button class="btn btn-primary">儲存</button> <a href="admin_mail_logs.php">查看今日用量</a></form>

<section class="card">
  <h2>資料清理</h2>
  <p class="muted">開啟後台頁面時被動執行，每小時最多一次、每項每次最多 <?= CLEANUP_LIMIT ?> 筆；不清理訂單、售後、異動紀錄與行為事件。</p>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="retention">
    <div class="table-wrap"><table><thead><tr><th>項目</th><th>保留天數</th><th>上次執行</th><th class="num">清除筆數</th><th>結果</th></tr></thead><tbody>
    <?php foreach (cleanup_tasks() as $k => [$label, $key]): $r = $runs[$k]; ?>
      <tr><td><?= h($label) ?></td><td><?php if ($key): ?><input type="number" name="<?= h($key) ?>" value="<?= setting_int($key, 30) ?>" min="1" max="3650" class="short"> 天<?php else: ?>Session 有效時間<?php endif; ?></td>
        <td><?= h($r['started_at'] ?? '—') ?></td><td class="num"><?= $r ? (int)$r['deleted_count'] : '' ?></td><td><?= $r ? h($r['result'] === 'ok' ? '完成' : '失敗') . ($r['message'] ? '：' . h($r['message']) : '') : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <button class="btn btn-primary">儲存保留天數</button></form>
  <hr>
  <h3>立即執行清理</h3>
  <p class="muted help">系統平常會在管理員開啟後台頁面時自動清理（每小時最多一次）。按下面的按鈕可以不用等，馬上依上表的保留天數清除過期資料，結果會更新在上表的「上次執行」「清除筆數」。</p>
  <form method="post" onsubmit="return confirm('確定立即依保留天數清除過期資料？清除後無法復原。')"><?= csrf_field() ?><input type="hidden" name="action" value="cleanup"><button class="btn">立即執行清理</button></form>
  <p class="muted small">Session 檔案位置：<?= !empty($session_save_path) ? '已設定專用目錄' : '使用主機預設。若要讓系統清理過期 Session，請在 config.php 加上：<code>$session_save_path = __DIR__ . &quot;/private_data/sessions&quot;;</code>' ?></p>
</section>
<?php
page_footer();
