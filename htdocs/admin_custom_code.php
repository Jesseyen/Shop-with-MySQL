<?php
/**
 * 自訂程式碼（系統管理者，第 17 節）：GTM、GA4、Google Ads 等標籤用這裡加入
 * 位置：<head> 後、</head> 前、<body> 後、</body> 前；套用模組：前台／後台／兩者；載入條件；排序；啟用
 * 需等待同意的程式碼只能包含 <script> 區塊；程式碼原樣輸出，存檔寫入異動紀錄（含異動前後全文）
 * 安全模式：網址加 ?no_custom_code=1（管理者）；或 config.php 的 $disable_custom_code
 */
require_once __DIR__ . '/app.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

$positions = ['head_start' => '<head> 後', 'head_end' => '</head> 前', 'body_start' => '<body> 後', 'body_end' => '</body> 前'];
$scopes = ['front' => '前台與會員頁', 'admin' => '後台頁', 'both' => '兩者'];
$conds = ['always' => '一律載入', 'google_tag' => 'Google 標籤（依 Consent Mode 模式）', 'analytics' => '需分析同意', 'ads' => '需廣告同意'];

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    $id = to_pos_int($_POST['id'] ?? '');
    $d = ['name' => post_str('name'), 'position' => post_str('position'), 'scope' => post_str('scope'),
          'load_condition' => post_str('load_condition'), 'sort_order' => (int)post_str('sort_order'),
          'is_active' => post_str('is_active') === '1' ? 1 : 0, 'code' => str_replace("\r\n", "\n", (string)($_POST['code'] ?? ''))];
    try {
        if ($action !== 'delete') {
            if (!len_between($d['name'], 1, 50)) { throw new UserError('名稱須為 1～50 字。'); }
            if (!isset($positions[$d['position']]) || !isset($scopes[$d['scope']]) || !isset($conds[$d['load_condition']])) { throw new UserError('選項不正確。'); }
            if (strlen($d['code']) > 65536) { throw new UserError('程式碼最多 64 KB。'); }
            if (trim($d['code']) === '') { throw new UserError('請輸入程式碼。'); }
            $gated = $d['load_condition'] !== 'always';
            if ($gated) {
                $rest = preg_replace(['~<script\b[^>]*>.*?</script>~is', '~<!--.*?-->~s'], '', $d['code']);
                if (trim($rest) !== '') { throw new UserError('需等待同意的程式碼只能包含 <script> 區塊（請將 <noscript> 等另建一段「一律載入」）。'); }
            }
        }
        admin_transaction(function (PDO $pdo, array $a) use ($action, $id, $d) {
            if ($action === 'create') {
                $pdo->prepare('INSERT INTO custom_code_snippets (name, position, scope, load_condition, sort_order, is_active, code) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$d['name'], $d['position'], $d['scope'], $d['load_condition'], $d['sort_order'], $d['is_active'], $d['code']]);
                audit_log($pdo, $a['id'], 'custom_code', $pdo->lastInsertId(), 'create', null, $d);
                return;
            }
            $st = $pdo->prepare('SELECT * FROM custom_code_snippets WHERE id = ? FOR UPDATE');
            $st->execute([$id ?? 0]);
            $row = $st->fetch();
            if (!$row) { throw new UserError('找不到此程式碼。'); }
            assert_row_version($row, $_POST['row_version'] ?? '');
            if ($action === 'delete') {
                $pdo->prepare('DELETE FROM custom_code_snippets WHERE id = ?')->execute([$id]);
                audit_log($pdo, $a['id'], 'custom_code', $id, 'delete', $row, null);
                return;
            }
            $pdo->prepare('UPDATE custom_code_snippets SET name = ?, position = ?, scope = ?, load_condition = ?, sort_order = ?, is_active = ?, code = ?,
                                  row_version = row_version + 1 WHERE id = ?')
                ->execute([$d['name'], $d['position'], $d['scope'], $d['load_condition'], $d['sort_order'], $d['is_active'], $d['code'], $id]);
            audit_log($pdo, $a['id'], 'custom_code', $id, 'update', $row, $d);
        });
        flash('success', $action === 'delete' ? '已刪除。' : '已儲存。');
        redirect('admin_custom_code.php');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        $_SESSION['_code_keep'] = $d;
        redirect('admin_custom_code.php?' . ($id ? 'id=' . $id : 'new=1'));
    }
}

$rows = $pdo->query('SELECT * FROM custom_code_snippets ORDER BY position, sort_order, id')->fetchAll();
$edit = null;
if (($eid = to_pos_int($_GET['id'] ?? '')) !== null) {
    foreach ($rows as $r) { if ((int)$r['id'] === $eid) { $edit = $r; } }
    if (!$edit) { render_not_found('程式碼'); }
}
$form = $_SESSION['_code_keep'] ?? ($edit ?? ['name' => '', 'position' => 'head_start', 'scope' => 'front', 'load_condition' => 'google_tag', 'sort_order' => 100, 'is_active' => 1, 'code' => '']);
unset($_SESSION['_code_keep']);
admin_header('自訂程式碼');
?>
<h1>自訂程式碼</h1>
<p class="muted">輸出順序：Consent Mode 預設 → 同意更新 → GA4 dataLayer → 自訂程式碼（&lt;head&gt; 後）。程式碼會原樣輸出，請只貼上可信任來源的程式碼。
  若程式碼讓後台無法操作，可在網址加 <code>?no_custom_code=1</code>，或在 config.php 設定 <code>$disable_custom_code = true;</code>。</p>
<?php if (isset($_GET['new']) || $edit): ?>
<form method="post" class="card stack"><?= csrf_field() ?>
  <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
  <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$edit['row_version'] ?>"><?php endif; ?>
  <div class="grid-3">
    <label>名稱 <input type="text" name="name" value="<?= h($form['name']) ?>" maxlength="50" required></label>
    <label>插入位置 <select name="position"><?= options_html($positions, $form['position']) ?></select></label>
    <label>套用模組 <select name="scope"><?= options_html($scopes, $form['scope']) ?></select></label>
    <label>載入條件 <select name="load_condition"><?= options_html($conds, $form['load_condition']) ?></select></label>
    <label>排序（小的先輸出） <input type="number" name="sort_order" value="<?= (int)$form['sort_order'] ?>"></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int)$form['is_active'] ? 'checked' : '' ?>> 啟用</label>
  </div>
  <label>程式碼（最多 64 KB） <textarea name="code" rows="12" class="code" spellcheck="false"><?= h($form['code']) ?></textarea></label>
  <p class="actions"><button class="btn btn-primary">儲存</button> <a class="btn" href="admin_custom_code.php">返回列表</a></p>
</form>
<?php else: ?>
<p><a class="btn" href="admin_custom_code.php?new=1">＋ 新增程式碼</a></p>
<div class="table-wrap"><table>
  <thead><tr><th>名稱</th><th>出現位置</th><th>載入條件</th><th class="num">排序</th><th>啟用</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr class="<?= (int)$r['is_active'] ? '' : 'row-off' ?>"><td><?= h($r['name']) ?></td><td><?= h($scopes[$r['scope']] . ' 的 ' . $positions[$r['position']]) ?></td>
      <td><?= h($conds[$r['load_condition']]) ?><?= $r['load_condition'] === 'google_tag' ? '<br><span class="muted small">目前：' . (setting('consent_mode') === 'basic' ? '取得同意後載入' : '一律載入') . '</span>' : '' ?></td>
      <td class="num"><?= (int)$r['sort_order'] ?></td><td><?= (int)$r['is_active'] ? '是' : '否' ?></td>
      <td><a class="btn btn-small" href="admin_custom_code.php?id=<?= (int)$r['id'] ?>">編輯</a>
        <form method="post" class="inline" onsubmit="return confirm('確定刪除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$r['row_version'] ?>"><button class="btn btn-small btn-danger">刪除</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">尚未新增程式碼。</td></tr><?php endif; ?></tbody>
</table></div>
<?php endif; ?>
<?php
page_footer();
