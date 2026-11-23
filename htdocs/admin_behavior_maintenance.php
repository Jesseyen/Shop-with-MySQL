<?php
/**
 * 後台：行為資料維護（限管理者）
 * 操作只有四項：檢查並補建月份、開始封存、接續封存、本批完成後暫停。
 * 封存每次請求最多執行約 15 秒，未完成時頁面會自動接續（可隨時按「本批完成後暫停」）。
 */
require_once __DIR__ . '/app.php';
if (!$enable_behavior) { render_error_page(404, '功能未啟用', '行為分析功能尚未啟用。'); }
$actor = require_perm('super', is_post() ? 'edit' : 'view');
require_once __DIR__ . '/lib/behavior_archive.php';
@set_time_limit(60);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $op = post_str('op');
    $auto = false;
    try {
        if ($op === 'pause') {
            // 不需取得命名鎖：只設定旗標，執行中的批次完成後就會停下
            $ok = beh_request_pause();
            flash($ok ? 'success' : 'info', $ok ? '已要求暫停，本批完成後停止。' : '目前沒有可暫停的封存。');
        } else {
            if (!beh_lock(0)) {
                throw new UserError('另一個維護作業或報表查詢正在使用資料，請稍後再試。');
            }
            try {
                if ($op === 'ensure') {
                    $res = beh_ensure_partitions();
                    $added = [];
                    foreach ($res as $t => $names) { if ($names) { $added[] = $t . '：' . implode('、', $names); } }
                    flash('success', $added ? '已補建分區　' . implode('；', $added) : '分區已足夠，無須補建。');
                } elseif ($op === 'start' || $op === 'continue') {
                    if ($op === 'start') {
                        $month = beh_archive_start($actor['id']);
                        flash('info', "開始封存 {$month}。");
                    } else {
                        beh_archive_resume_prepare();
                    }
                    $state = beh_archive_run(15.0);
                    $msg = [
                        'continue' => '本次處理時間已到，進度已保存，將自動接續。',
                        'completed' => '封存完成。',
                        'paused' => '已暫停，按「接續封存」可繼續。',
                        'failed' => '封存已停止，請查看錯誤原因。',
                        'idle' => '沒有進行中的封存。',
                    ][$state] ?? $state;
                    flash($state === 'failed' ? 'error' : 'success', $msg);
                    $auto = $state === 'continue';
                } else {
                    throw new UserError('不支援的操作。');
                }
            } finally {
                beh_unlock();
            }
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_behavior_maintenance.php' . ($auto ? '?auto=1' : ''));
}

// ---------------- 狀態 ----------------
$online = $pdo->query('SELECT MIN(event_time) AS mn, MAX(event_time) AS mx, COUNT(*) AS n FROM `' . BEH_MAIN . '`')->fetch();
$sharedCount = beh_table_exists(BEH_SHARED) ? (int)$pdo->query('SELECT COUNT(*) FROM `' . BEH_SHARED . '`')->fetchColumn() : null;
$until = beh_partitions_until(BEH_MAIN);
$needUntil = (new DateTimeImmutable(beh_current_month_start()))->modify('+' . BEH_MONTHS_AHEAD . ' month')->format('Y-m');
$active = beh_archive_active();
$months = beh_archive_months();
$cands = beh_archive_candidates();
$legacyJobs = beh_legacy_jobs_running();
$flags = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'v1.2%' OR setting_key LIKE 'legacy%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$auto = ($_GET['auto'] ?? '') === '1' && $active && $active['status'] !== 'failed' && (int)$active['pause_requested'] === 0;
$statusLabel = ['copying' => '複製中', 'verifying' => '核對中', 'drop_ready' => '待移除來源', 'dropping' => '移除來源中', 'completed' => '完成', 'failed' => '已停止（失敗）'];

if ($active) {
    if ($active['status'] === 'failed') {
        $next = "封存 {$active['archive_month']} 已停止，來源資料已保留。請依錯誤原因處理後按「接續封存」重試。";
    } elseif ((int)$active['pause_requested'] === 1) {
        $next = "封存 {$active['archive_month']} 已暫停，按「接續封存」繼續。";
    } else {
        $next = "封存 {$active['archive_month']} 進行中（{$statusLabel[$active['status']]}），按「接續封存」繼續處理。";
    }
} elseif ($until === null || strcmp($until, $needUntil) < 0) {
    $next = "分區只準備到 " . ($until ?? '（無）') . "，請按「檢查並補建月份」補到 {$needUntil}。";
} elseif ($cands) {
    $next = "可以封存 {$cands[0]['month']}（{$cands[0]['rows']} 筆），按「開始封存」。";
} else {
    $next = '無須封存。';
}

$opBtn = function (string $op, string $label, bool $enabled, string $cls = '') {
    return '<form method="post" action="admin_behavior_maintenance.php" class="inline">' . csrf_field()
        . '<input type="hidden" name="op" value="' . h($op) . '">'
        . '<button class="btn ' . h($cls) . '" type="submit"' . ($enabled ? '' : ' disabled') . '>' . h($label) . '</button></form> ';
};

page_header('行為資料維護', true);
?>
<h1>行為資料維護</h1>

<section class="card">
  <h2>建議下一步</h2>
  <p><strong><?= h($next) ?></strong></p>
  <div class="actions">
    <?= $opBtn('ensure', '檢查並補建月份', !$active) ?>
    <?= $opBtn('start', '開始封存', !$active && (bool)$cands, 'btn-primary') ?>
    <?= $opBtn('continue', '接續封存', (bool)$active, 'btn-primary') ?>
    <?= $opBtn('pause', '本批完成後暫停', $active && $active['status'] !== 'failed' && (int)$active['pause_requested'] === 0, 'btn-warn') ?>
  </div>
  <p class="muted small">封存期間請勿人工回補或修改該月份資料；直接操作資料庫者也須遵守。DROP PARTITION 無法復原，執行前請先備份資料庫。</p>
  <?php if ($auto): ?>
    <form method="post" action="admin_behavior_maintenance.php" id="auto-continue">
      <?= csrf_field() ?><input type="hidden" name="op" value="continue">
      <p class="alert alert-info">將在 2 秒後自動接續封存……（要停止請按「本批完成後暫停」）</p>
    </form>
    <script>setTimeout(function () { document.getElementById('auto-continue').submit(); }, 2000);</script>
  <?php endif; ?>
</section>

<div class="grid-2">
  <section class="card">
    <h2>目前狀態</h2>
    <dl class="meta">
      <dt>線上紀錄</dt>
      <dd><?= (int)$online['n'] ?> 筆<?= $online['n'] > 0 ? '（' . h(substr($online['mn'], 0, 19)) . ' ～ ' . h(substr($online['mx'], 0, 19)) . '）' : '' ?></dd>
      <dt>舊共用封存表</dt><dd><?= $sharedCount === null ? '不存在' : $sharedCount . ' 筆' ?></dd>
      <dt>分區準備到</dt><dd><?= h($until ?? '（無）') ?>（建議至少到 <?= h($needUntil) ?>）</dd>
      <dt>可封存月份</dt><dd><?= $cands ? h(implode('、', array_map(function ($c) { return $c['month'] . '（' . $c['rows'] . ' 筆）'; }, $cands))) : '無' ?></dd>
    </dl>
  </section>
  <section class="card">
    <h2>進行中的封存</h2>
    <?php if (!$active): ?>
      <p class="muted">目前沒有進行中的封存。</p>
    <?php else: ?>
      <dl class="meta">
        <dt>月份</dt><dd><?= h($active['archive_month']) ?>（<?= h($active['table_name']) ?>）</dd>
        <dt>狀態</dt><dd><?= h($statusLabel[$active['status']] ?? $active['status']) ?><?= (int)$active['pause_requested'] === 1 ? '，已暫停' : '' ?></dd>
        <dt>進度</dt><dd><?= (int)$active['copied_count'] ?> / <?= (int)$active['expected_count'] ?> 筆</dd>
        <dt>開始時間</dt><dd><?= h($active['started_at']) ?></dd>
        <?php if ($active['last_error']): ?><dt>上次錯誤</dt><dd class="small"><?= h($active['last_error']) ?></dd><?php endif; ?>
      </dl>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>已封存月份</h2>
  <?php $doneMonths = array_filter($months, function ($m) { return $m['status'] === 'completed'; }); ?>
  <?php if (!$doneMonths): ?>
    <p class="muted">尚未封存任何月份。</p>
  <?php else: ?>
    <table>
      <thead><tr><th>月份</th><th>資料表</th><th class="num">登記筆數</th><th class="num">實際筆數</th><th>完成時間</th><th>操作者</th></tr></thead>
      <tbody>
      <?php foreach ($doneMonths as $m):
          $actual = beh_table_exists($m['table_name']) ? (int)$pdo->query('SELECT COUNT(*) FROM `' . $m['table_name'] . '`')->fetchColumn() : null; ?>
        <tr>
          <td><?= h($m['archive_month']) ?></td>
          <td><?= h($m['table_name']) ?></td>
          <td class="num"><?= (int)$m['copied_count'] ?></td>
          <td class="num"><?= $actual === null ? '<span class="badge badge-warn">資料表遺失</span>' : $actual ?></td>
          <td><?= h($m['completed_at']) ?></td>
          <td><?= $m['operator_id'] ? '#' . (int)$m['operator_id'] : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<details class="card">
  <summary>技術資料</summary>
  <h3>分區（<?= h(BEH_MAIN) ?>）</h3>
  <table><thead><tr><th>分區</th><th>上限（不含）</th><th class="num">估計筆數</th></tr></thead><tbody>
  <?php foreach (beh_partitions(BEH_MAIN) as $p): ?>
    <tr><td><?= h($p['name']) ?></td><td><?= h($p['bound']) ?></td><td class="num"><?= (int)$p['rows'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <h3>封存登記（全部）</h3>
  <table><thead><tr><th>月份</th><th>狀態</th><th>游標</th><th class="num">預期</th><th class="num">已複製</th><th>最後錯誤</th></tr></thead><tbody>
  <?php foreach ($months as $m): ?>
    <tr><td><?= h($m['archive_month']) ?></td><td><?= h($m['status']) ?></td>
        <td class="small"><?= (int)$m['cursor_source'] === 0 ? '主表' : '舊共用表' ?> <?= h((string)$m['cursor_time']) ?> #<?= h((string)$m['cursor_event_id']) ?></td>
        <td class="num"><?= (int)$m['expected_count'] ?></td><td class="num"><?= (int)$m['copied_count'] ?></td>
        <td class="small"><?= h((string)$m['last_error']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <h3>旗標與相容性</h3>
  <ul>
    <?php foreach ($flags as $k => $v): ?><li><?= h($k) ?> = <?= h($v) ?></li><?php endforeach; ?>
    <?php if (!$flags): ?><li>尚未執行一次性轉換（v1.2_ready 未設定）。從第二階段升級時，請在 phpMyAdmin 執行 sql/21_one_time_conversion_carts.sql。</li><?php endif; ?>
    <li>舊批次表未結束工作：<?= (int)$legacyJobs ?> 筆</li>
  </ul>
</details>
<?php
page_footer();
