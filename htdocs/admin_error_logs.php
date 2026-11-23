<?php
/**
 * 錯誤紀錄（系統管理者，第 33 節）：依等級、日期、關鍵字篩選；可標記「已處理」；同一訊息＋檔案＋行號只累加次數
 * 資料庫無法連線時的備援紀錄（非公開目錄的檔案）一併顯示最後 50 行
 */
require_once __DIR__ . '/app.php';
if (!$enable_launch) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '');
    if (post_str('action') === 'resolve_all') {
        $pdo->exec('UPDATE error_logs SET resolved_at = NOW() WHERE resolved_at IS NULL');
    } elseif ($id) {
        $pdo->prepare('UPDATE error_logs SET resolved_at = ' . (post_str('action') === 'reopen' ? 'NULL' : 'NOW()') . ' WHERE id = ?')->execute([$id]);
    }
    flash('success', '已更新。');
    redirect('admin_error_logs.php?' . http_build_query(query_keep()));
}
$f = ['level' => get_str('level'), 'from' => get_str('from'), 'to' => get_str('to'), 'q' => get_str('q'), 'open' => get_str('open')];
$where = [];
$args = [];
if ($f['level'] !== '') { $where[] = 'level = ?'; $args[] = $f['level']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'last_seen >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'last_seen <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
if ($f['q'] !== '') { $where[] = '(message LIKE ? OR file LIKE ? OR url LIKE ?)'; $l = '%' . addcslashes($f['q'], '%_\\') . '%'; array_push($args, $l, $l, $l); }
if ($f['open'] === '1') { $where[] = 'resolved_at IS NULL'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = current_page();
$st = $pdo->prepare("SELECT COUNT(*) FROM error_logs {$w}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * 30;
$st = $pdo->prepare("SELECT * FROM error_logs {$w} ORDER BY last_seen DESC LIMIT 30 OFFSET {$off}");
$st->execute($args);
$rows = $st->fetchAll();
$levels = $pdo->query('SELECT DISTINCT level FROM error_logs ORDER BY level')->fetchAll(PDO::FETCH_COLUMN);
$fallback = is_file(error_private_file()) ? array_slice(file(error_private_file()), -50) : [];
admin_header('錯誤紀錄');
?>
<h1>錯誤紀錄</h1>
<p class="muted">記錄未捕捉例外、PHP 錯誤與警告；網址已去除憑證類參數，不記錄表單內容。保留 <?= setting_int('retain_error_days', 30) ?> 天。</p>
<form class="filters" method="get">
  <label>等級 <select name="level"><option value="">全部</option><?php foreach ($levels as $l): ?><option <?= $f['level'] === $l ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>最後發生 <input type="date" name="from" value="<?= h($f['from']) ?>"> ～ <input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <label>關鍵字 <input type="search" name="q" value="<?= h($f['q']) ?>"></label>
  <label class="check"><input type="checkbox" name="open" value="1" <?= $f['open'] === '1' ? 'checked' : '' ?>> 只看未處理</label>
  <button class="btn btn-primary">篩選</button> <a class="btn" href="admin_error_logs.php">清除</a>
</form>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="resolve_all"><button class="btn btn-small">全部標記已處理</button></form>
<?php foreach ($rows as $r): ?>
<details class="card error-log <?= $r['resolved_at'] ? 'row-off' : '' ?>">
  <summary><?= badge($r['level'], $r['level'] === 'exception' || $r['level'] === 'fatal' ? 'warn' : '') ?> <strong><?= h(mb_substr($r['message'], 0, 160)) ?></strong>
    <span class="muted small"><?= h($r['file'] . ':' . $r['line']) ?>　× <?= (int)$r['occurrences'] ?>　最後 <?= h($r['last_seen']) ?><?= $r['resolved_at'] ? '　已處理' : '' ?></span></summary>
  <dl class="meta"><dt>首次</dt><dd><?= h($r['first_seen']) ?></dd><dt>網址</dt><dd><?= h($r['method'] . ' ' . $r['url']) ?></dd>
    <dt>會員</dt><dd><?= $r['member_id'] ? '#' . (int)$r['member_id'] : '—' ?></dd><dt>IP</dt><dd><?= h($r['ip'] ?? '') ?></dd></dl>
  <pre class="trace"><?= h($r['message'] . "\n\n" . $r['trace']) ?></pre>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="<?= $r['resolved_at'] ? 'reopen' : 'resolve' ?>">
    <button class="btn btn-small"><?= $r['resolved_at'] ? '改為未處理' : '標記已處理' ?></button></form>
</details>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="empty">沒有錯誤紀錄。</p><?php endif; ?>
<?= render_pager($total, $page, 30, query_keep(['page'])) ?>
<?php if ($fallback): ?><section class="card"><h2>備援紀錄（資料庫無法連線時）</h2><pre class="trace"><?= h(implode('', $fallback)) ?></pre></section><?php endif; ?>
<?php
page_footer();
