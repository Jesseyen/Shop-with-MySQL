<?php
/**
 * 備份與還原（系統管理者，第 19 節）
 * 下載完整備份（再次輸入管理員密碼並勾選確認）→ 隔離還原演練（dr_ 表，分批處理、逐表驗證）→ 驗證通過後套用為正式資料
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/backup.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

function backup_check_password(int $memberId, string $pw): void
{
    global $pdo;
    $st = $pdo->prepare('SELECT password_hash FROM members WHERE id = ?');
    $st->execute([$memberId]);
    if (!password_verify($pw, (string)$st->fetchColumn())) { throw new UserError('管理員密碼不正確。'); }
}

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    $id = to_pos_int($_POST['id'] ?? '') ?? 0;
    try {
        switch ($action) {
            case 'download':
                backup_check_password($me['id'], (string)($_POST['password'] ?? ''));
                if (post_str('confirm') !== '1') { throw new UserError('請勾選確認：備份檔含個資與密碼雜湊，會妥善保管。'); }
                $withB = post_str('behavior') === '1';
                admin_transaction(function (PDO $pdo, array $a) use ($withB) {
                    audit_log($pdo, $a['id'], 'backup', 'download', 'download', null, ['include_behavior' => $withB]);
                });
                backup_stream($withB);
                exit;
            case 'upload':
                $files = $_FILES['backup'] ?? null;
                if (!$files) { throw new UserError('請選擇備份檔。'); }
                $newId = drill_upload($files, $me['id']);
                admin_transaction(function (PDO $pdo, array $a) use ($newId) { audit_log($pdo, $a['id'], 'backup', $newId, 'drill_upload', null, null); });
                redirect('admin_backup.php?drill=' . $newId . '&run=1');
            case 'step':
                $r = drill_step($id);
                if ($r === 'continue') { redirect('admin_backup.php?drill=' . $id . '&run=1'); }
                flash($r === 'verified' ? 'success' : 'error', $r === 'verified' ? '還原演練完成，全部資料表驗證通過。' : '還原演練失敗，請查看原因。');
                redirect('admin_backup.php?drill=' . $id);
            case 'drop':
                $n = drill_drop_tables($id);
                flash('success', "已刪除 {$n} 個隔離表。");
                redirect('admin_backup.php');
            case 'apply':
                backup_check_password($me['id'], (string)($_POST['password'] ?? ''));
                if (post_str('phrase') !== '套用備份') { throw new UserError('請輸入確認字句「套用備份」。'); }
                $bak = drill_apply($id, $me['id']);
                flash('success', '已套用為正式資料，原資料保留為 ' . $bak . '* 表，可換回。若你的帳號在備份中不存在，需重新登入。');
                redirect('admin_backup.php?drill=' . $id);
            case 'revert':
                backup_check_password($me['id'], (string)($_POST['password'] ?? ''));
                drill_revert($id);
                flash('success', '已換回套用前的資料。');
                redirect('admin_backup.php?drill=' . $id);
        }
        throw new UserError('不支援的操作。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        redirect('admin_backup.php' . ($id ? '?drill=' . $id : ''));
    }
}

$drillId = to_pos_int($_GET['drill'] ?? '');
admin_header('備份與還原');
echo '<h1>備份與還原</h1>';
if ($drillId !== null) {
    $d = drill_find($drillId);
    if (!$d) { render_not_found('演練'); }
    $statusLabels = ['uploaded' => '已上傳', 'restoring' => '還原中', 'verified' => '驗證通過', 'failed' => '失敗', 'applied' => '已套用', 'dropped' => '已刪除隔離表'];
    echo '<p><a href="admin_backup.php">← 備份與還原</a></p><section class="card"><h2>演練 #' . (int)$d['id'] . '　' . badge($statusLabels[$d['status']]) . '</h2>'
        . '<dl class="meta"><dt>隔離前綴</dt><dd><code>' . h($d['prefix']) . '</code></dd><dt>資料表</dt><dd>' . (int)$d['table_count'] . '</dd>'
        . '<dt>資料列</dt><dd>' . (int)$d['row_count'] . '</dd><dt>結構版本</dt><dd>' . ((int)$d['schema_match'] ? '與目前程式相同' : '<strong>與目前程式不同</strong>') . '</dd>'
        . '<dt>時間</dt><dd>' . h($d['created_at']) . '</dd><dt>結果</dt><dd>' . h($d['message'] ?? '') . '</dd></dl>';
    if ($d['status'] === 'restoring') {
        echo '<form method="post" id="drill-step">' . csrf_field() . '<input type="hidden" name="action" value="step"><input type="hidden" name="id" value="' . (int)$d['id'] . '">'
            . '<p>處理中：' . h($d['cursor_table'] ?? '') . '（已處理 ' . (int)$d['row_count'] . ' 列）。每次處理一段，未完成時會自動接續。</p><button class="btn btn-primary">繼續處理</button></form>';
        if (isset($_GET['run'])) { echo '<script>setTimeout(function(){document.getElementById("drill-step").submit();},300);</script>'; }
    }
    $tables = drill_tables((int)$d['id']);
    if ($tables) {
        echo '<div class="table-wrap"><table><thead><tr><th>資料表</th><th class="num">預期筆數</th><th class="num">實際筆數</th><th>SHA-256</th><th>結果</th></tr></thead><tbody>';
        foreach ($tables as $t) {
            echo '<tr class="' . ($t['result'] === 'mismatch' ? 'row-error' : '') . '"><td>' . h($t['table_name']) . '</td><td class="num">' . (int)$t['expected_rows'] . '</td><td class="num">' . (int)$t['actual_rows']
                . '</td><td class="small"><code>' . h(substr((string)$t['actual_sha'], 0, 16)) . '…</code></td><td>' . h(['pending' => '待驗證', 'ok' => '一致', 'mismatch' => '不一致'][$t['result']]) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if ($d['status'] === 'verified' && (int)$d['schema_match'] === 1) {
        echo '<form method="post" class="stack" onsubmit="return confirm(\'套用後正式資料會被備份內容取代（原資料保留可換回），確定？\')">' . csrf_field()
            . '<input type="hidden" name="action" value="apply"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><h3>套用為正式資料</h3>'
            . '<p class="muted">套用期間網站自動進入維護模式；以單一 RENAME TABLE 原子交換，原正式表改名為 bak_ 保留；交換後補建外鍵並檢查，失敗時自動換回。</p>'
            . '<label>確認字句（請輸入「套用備份」） <input type="text" name="phrase" required></label><label>管理員密碼 <input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button class="btn btn-danger">套用為正式資料</button></form>';
    }
    if ($d['status'] === 'applied') {
        echo '<form method="post" class="row-form">' . csrf_field() . '<input type="hidden" name="action" value="revert"><input type="hidden" name="id" value="' . (int)$d['id'] . '">'
            . '<label>管理員密碼 <input type="password" name="password" required></label><button class="btn btn-danger">換回套用前的資料</button></form>';
    }
    if (in_array($d['status'], ['verified', 'failed', 'restoring'], true)) {
        echo '<form method="post" onsubmit="return confirm(\'刪除此演練的隔離表？\')">' . csrf_field() . '<input type="hidden" name="action" value="drop"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><button class="btn">刪除隔離表</button></form>';
    }
    echo '</section>';
    page_footer();
    exit;
}
$drills = $pdo->query('SELECT d.*, m.name FROM backup_drills d LEFT JOIN members m ON m.id = d.created_by ORDER BY d.id DESC LIMIT 30')->fetchAll();
?>
<section class="card">
  <h2>下載完整備份</h2>
  <p class="muted">gzip 壓縮的 JSON（.json.gz，JSON Lines 格式），包含所有商場資料表與每表筆數、SHA-256；不含 config.php、SMTP 帳密與商品圖片檔（圖片請以 FTP 另行備份）。</p>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="download">
    <label class="check"><input type="checkbox" name="behavior" value="1"> 包含會員行為事件與封存月份表（檔案可能很大）</label>
    <label class="check"><input type="checkbox" name="confirm" value="1" required> 我了解備份檔含個資與密碼雜湊，會妥善保管</label>
    <label>再次輸入管理員密碼 <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary">下載備份</button></form>
</section>
<section class="card">
  <h2>隔離還原演練</h2>
  <p class="muted">上傳備份後在 <code>dr_</code> 開頭的隔離表還原並逐表驗證，不修改正式資料。主機上傳上限：<?= h(ini_get('upload_max_filesize')) ?>；超過上限的備份請改用 phpMyAdmin 匯入。</p>
  <form method="post" enctype="multipart/form-data" class="row-form"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <input type="file" name="backup" accept=".gz" required><button class="btn">上傳並開始演練</button></form>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>隔離前綴</th><th>狀態</th><th class="num">資料表</th><th class="num">資料列</th><th>建立</th><th></th></tr></thead><tbody>
  <?php foreach ($drills as $d): ?><tr><td><?= (int)$d['id'] ?></td><td><code><?= h($d['prefix']) ?></code></td><td><?= h($d['status']) ?></td><td class="num"><?= (int)$d['table_count'] ?></td>
    <td class="num"><?= (int)$d['row_count'] ?></td><td class="small"><?= h($d['name'] ?? '') ?><br><?= h($d['created_at']) ?></td><td><a class="btn btn-small" href="admin_backup.php?drill=<?= (int)$d['id'] ?>">查看</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php
page_footer();
