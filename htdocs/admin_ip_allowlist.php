<?php
/**
 * 管理員 IP 白名單與安全紀錄（系統管理者，第 20 節）
 * 有任一啟用項目時，只有符合的 IP 能使用管理功能；存檔後若目前 IP 不在清單內則拒絕存檔（防止鎖死）
 */
require_once __DIR__ . '/app.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');
$myIp = client_ip();

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    $id = to_pos_int($_POST['id'] ?? '');
    try {
        $cidr = post_str('cidr');
        $note = post_str('note');
        if ($action === 'create' || $action === 'update') {
            if (!valid_cidr($cidr)) { throw new UserError('請輸入正確的 IP 或 CIDR，例如 203.0.113.5、203.0.113.0/24、2001:db8::/32。'); }
            if (mb_strlen($note) > 100) { throw new UserError('說明最多 100 字。'); }
        }
        admin_transaction(function (PDO $pdo, array $a) use ($action, $id, $cidr, $note, $myIp) {
            $active = post_str('is_active') === '1' ? 1 : 0;
            if ($action === 'create') {
                $pdo->prepare('INSERT INTO admin_ip_allowlist (cidr, note, is_active, created_by) VALUES (?, ?, ?, ?)')->execute([$cidr, $note ?: null, $active, $a['id']]);
                audit_log($pdo, $a['id'], 'ip_allowlist', $pdo->lastInsertId(), 'create', null, compact('cidr', 'note', 'active'));
            } else {
                $st = $pdo->prepare('SELECT * FROM admin_ip_allowlist WHERE id = ? FOR UPDATE');
                $st->execute([$id ?? 0]);
                $row = $st->fetch();
                if (!$row) { throw new UserError('找不到此項目。'); }
                assert_row_version($row, $_POST['row_version'] ?? '');
                if ($action === 'delete') {
                    $pdo->prepare('DELETE FROM admin_ip_allowlist WHERE id = ?')->execute([$id]);
                    audit_log($pdo, $a['id'], 'ip_allowlist', $id, 'delete', $row, null);
                } else {
                    $pdo->prepare('UPDATE admin_ip_allowlist SET cidr = ?, note = ?, is_active = ?, row_version = row_version + 1 WHERE id = ?')->execute([$cidr, $note ?: null, $active, $id]);
                    audit_log($pdo, $a['id'], 'ip_allowlist', $id, 'update', $row, compact('cidr', 'note', 'active'));
                }
            }
            $list = $pdo->query('SELECT cidr FROM admin_ip_allowlist WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
            if ($list && !ip_matches_list($myIp, $list)) {
                throw new UserError('存檔後你目前的 IP（' . $myIp . '）不在白名單內，會讓你無法使用後台，因此拒絕存檔。請先加入你目前的 IP。');
            }
        });
        flash('success', '白名單已儲存。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_ip_allowlist.php');
}
$rows = $pdo->query('SELECT l.*, m.name AS creator FROM admin_ip_allowlist l LEFT JOIN members m ON m.id = l.created_by ORDER BY l.id')->fetchAll();
$page = current_page();
$total = (int)$pdo->query('SELECT COUNT(*) FROM security_logs')->fetchColumn();
$off = ($page - 1) * 50;
$logs = $pdo->query("SELECT s.*, m.name FROM security_logs s LEFT JOIN members m ON m.id = s.member_id ORDER BY s.id DESC LIMIT 50 OFFSET {$off}")->fetchAll();
$types = ['ip_denied' => 'IP 不在白名單', 'captcha_lock' => '辨識碼錯誤過多', 'mail_quota' => '寄信超過額度', 'login_lock' => '登入失敗過多', 'contact_limit' => '發問頻率限制'];
admin_header('IP 白名單');
?>
<h1>管理員 IP 白名單</h1>
<div class="alert alert-info">你目前的 IP：<strong><?= h($myIp) ?></strong>。清單為空時不限制；有任一啟用項目時，只有符合的 IP 能使用後台（管理者帳號仍可在前台購物）。
  緊急時可在 config.php 設定 <code>$disable_admin_ip_allowlist = true;</code> 暫停。<?= $disable_admin_ip_allowlist ? '<br><strong>目前已由 config.php 暫停白名單。</strong>' : '' ?></div>
<div class="table-wrap"><table><thead><tr><th>IP／CIDR</th><th>說明</th><th>啟用</th><th>建立</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td colspan="3"><form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$r['row_version'] ?>">
    <input type="text" name="cidr" value="<?= h($r['cidr']) ?>" maxlength="50" required aria-label="IP"><input type="text" name="note" value="<?= h($r['note'] ?? '') ?>" maxlength="100" aria-label="說明">
    <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int)$r['is_active'] ? 'checked' : '' ?>> 啟用</label><button class="btn btn-small">儲存</button>
    <?= ip_in_cidr($myIp, $r['cidr']) ? badge('符合目前 IP') : '' ?></form></td>
    <td class="small"><?= h($r['creator'] ?? '') ?><br><?= h($r['created_at']) ?></td>
    <td><form method="post" class="inline" onsubmit="return confirm('確定刪除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$r['row_version'] ?>"><button class="btn btn-small btn-danger">刪除</button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<form method="post" class="card row-form"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label>IP 或 CIDR <input type="text" name="cidr" value="<?= $rows ? '' : h($myIp) ?>" maxlength="50" required></label>
  <label>說明 <input type="text" name="note" maxlength="100"></label>
  <label class="check"><input type="checkbox" name="is_active" value="1" checked> 啟用</label><button class="btn btn-primary">新增</button></form>
<h2>安全紀錄</h2>
<div class="table-wrap"><table><thead><tr><th>時間</th><th>類型</th><th>IP</th><th>會員</th><th>頁面</th><th>說明</th></tr></thead><tbody>
<?php foreach ($logs as $l): ?><tr><td><?= h($l['created_at']) ?></td><td><?= h($types[$l['event_type']] ?? $l['event_type']) ?></td><td><?= h($l['ip'] ?? '') ?></td>
  <td><?= $l['member_id'] ? h(($l['name'] ?? '') . ' #' . $l['member_id']) : '' ?></td><td class="small"><?= h($l['path'] ?? '') ?></td><td class="small"><?= h($l['detail'] ?? '') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?= render_pager($total, $page, 50) ?>
<?php
page_footer();
