<?php
/**
 * 聲明與購物須知（系統管理者）：營運者名稱、客服聯絡方式與三份公開文件
 * 純文字儲存、不接受 HTML；可用佔位字；修改寫入異動紀錄（含異動前後全文）
 */
require_once __DIR__ . '/app.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

if (is_post()) {
    require_csrf();
    $key = post_str('key');
    $content = str_replace(["\r\n", "\r"], "\n", (string)($_POST['content'] ?? ''));
    try {
        if (!isset(content_keys()[$key])) { throw new UserError('內容項目不正確。'); }
        [$label, $max] = content_keys()[$key];
        if (mb_strlen($content) > $max) { throw new UserError($label . '最多 ' . number_format($max) . ' 字。'); }
        if (preg_match('/<\s*(script|iframe|style|[a-z]+\s+on\w+=)/i', $content)) { throw new UserError('公開文件為純文字，不接受 HTML。'); }
        admin_transaction(function (PDO $pdo, array $a) use ($key, $content) {
            $st = $pdo->prepare('SELECT * FROM site_contents WHERE content_key = ? FOR UPDATE');
            $st->execute([$key]);
            $row = $st->fetch();
            if ($row) {
                assert_row_version($row, $_POST['row_version'] ?? '');
                $pdo->prepare('UPDATE site_contents SET content = ?, updated_by = ?, row_version = row_version + 1 WHERE content_key = ?')->execute([$content, $a['id'], $key]);
            } else {
                $pdo->prepare('INSERT INTO site_contents (content_key, content, updated_by) VALUES (?, ?, ?)')->execute([$key, $content, $a['id']]);
            }
            audit_log($pdo, $a['id'], 'site_content', $key, 'update', $row ? ['content' => $row['content']] : null, ['content' => $content]);
        });
        flash('success', content_keys()[$key][0] . '已儲存，前台立即顯示最新內容。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        $_SESSION['_content_keep'] = [$key => $content];
    }
    redirect('admin_contents.php#' . $key);
}
$keep = $_SESSION['_content_keep'] ?? [];
unset($_SESSION['_content_keep']);
admin_header('聲明與購物須知');
?>
<h1>聲明與購物須知</h1>
<p class="muted">純文字儲存，空行分段、網址自動成為連結，不接受 HTML。可用佔位字：<code>{營運者名稱}</code> <code>{退換貨天數}</code> <code>{自動完成天數}</code> <code>{貨到付款手續費}</code>。
  範本內容請依實際營運修改，並請法律人員確認。</p>
<?php if (!public_info_ready()): ?><div class="alert alert-warn">營運者名稱或客服聯絡方式尚未填寫：結帳頁會停用送出並顯示「商店資訊尚未設定」。</div><?php endif; ?>
<?php foreach (content_keys() as $key => [$label, $max]): $c = site_content($key); $val = $keep[$key] ?? $c['content']; ?>
<form method="post" class="card stack" id="<?= h($key) ?>">
  <?= csrf_field() ?><input type="hidden" name="key" value="<?= h($key) ?>"><input type="hidden" name="row_version" value="<?= (int)$c['row_version'] ?>">
  <h2><?= h($label) ?> <span class="muted small">（最多 <?= number_format($max) ?> 字<?= $c['updated_at'] ? '，最後更新 ' . h($c['updated_at']) : '' ?>）</span></h2>
  <?php if ($max <= 100): ?><input type="text" name="content" value="<?= h($val) ?>" maxlength="<?= $max ?>" data-count>
  <?php else: ?><textarea name="content" rows="<?= $max > 1000 ? 14 : 4 ?>" maxlength="<?= $max ?>" data-count><?= h($val) ?></textarea><?php endif; ?>
  <p class="actions"><button class="btn btn-primary">儲存</button>
    <?php $pages = ['privacy' => 'privacy.php', 'returns' => 'returns.php', 'shopping_guide' => 'shopping-guide.php']; if (isset($pages[$key])): ?><a href="<?= $pages[$key] ?>" target="_blank">前台檢視</a><?php endif; ?></p>
</form>
<?php endforeach; ?>
<?php
page_footer();
