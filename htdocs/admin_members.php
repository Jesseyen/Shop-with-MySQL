<?php
/**
 * 後台會員管理（會員管理模組）
 * - 分頁查詢：編號、姓名、Email、狀態（含已刪除）、刪除申請
 * - 修改姓名與等級、鎖定等級、啟用、停權、軟刪除、補寄驗證信；該會員的訂單摘要
 * - 第六階段：刪除帳號申請（檢查未結案項目 → 軟刪除並匿名化，或拒絕）
 * - 保護規則見 lib/member_policy.php（後端強制）；管理權限在「管理員與權限」頁由系統管理者授權
 */
require_once __DIR__ . '/app.php';
$actor = require_perm('member', is_post() ? 'edit' : 'view');
$canEdit = admin_can('member', 'edit');

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '');
    $action = post_str('action');
    try {
        if ($id === null) { throw new UserError('會員編號不正確。'); }
        $res = admin_transaction(function (PDO $pdo, array $a) use ($id, $action) {
            $st = $pdo->prepare('SELECT * FROM members WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) { throw new UserError('找不到此會員。'); }
            if (!in_array($action, ['deletion_approve', 'deletion_reject'], true)) { assert_row_version($t, $_POST['row_version'] ?? ''); }
            $acts = member_allowed_actions($a, $t);
            $denied = policy_protected_op_denied($a, $t);
            $mail = null;
            switch ($action) {
                case 'update':
                    if (!$acts['edit']) { throw new UserError('已刪除的會員不能修改。'); }
                    $name = post_str('name');
                    if (!len_between($name, 1, 50)) { throw new UserError('姓名須為 1～50 個字。'); }
                    $level = post_str('member_level');
                    if (!array_key_exists($level, member_levels())) { throw new UserError('會員等級不正確。'); }
                    $locked = post_str('level_locked') === '1' ? 1 : 0;
                    $isAdmin = (int)$t['is_admin'];
                    if ($acts['admin_flag']) { $isAdmin = post_str('is_admin') === '1' ? 1 : 0; }
                    $pdo->prepare('UPDATE members SET name = ?, member_level = ?, level_locked = ?, is_admin = ?, row_version = row_version + 1 WHERE id = ?')
                        ->execute([$name, $level, $GLOBALS['enable_orders'] ? $locked : (int)$t['level_locked'], $isAdmin, $id]);
                    if ($level !== $t['member_level'] && $GLOBALS['enable_orders']) {
                        $pdo->prepare('INSERT INTO member_level_logs (member_id, old_level, new_level, total_spent, reason, actor_id) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$id, $t['member_level'], $level, $t['total_spent'], 'manual', $a['id']]);
                    }
                    $msg = '會員資料已更新。';
                    break;
                case 'activate':
                    if (!$acts['activate']) {
                        throw new UserError((int)$t['status'] === 2 ? '待驗證會員須完成 Email 驗證才能啟用，請改用「補寄驗證信」。' : '此會員目前不能啟用。');
                    }
                    $pdo->prepare('UPDATE members SET status = 1, row_version = row_version + 1 WHERE id = ?')->execute([$id]);
                    $msg = '會員已啟用。';
                    break;
                case 'suspend':
                    if (!$acts['suspend']) { throw new UserError($denied ?? '此會員目前不能停權。'); }
                    $pdo->prepare('UPDATE members SET status = 0, row_version = row_version + 1 WHERE id = ?')->execute([$id]);
                    $msg = '會員已停權。';
                    break;
                case 'delete':
                    if (!$acts['delete']) { throw new UserError($denied ?? '此會員目前不能刪除；管理員須先由系統管理者取消權限。'); }
                    $pdo->prepare('UPDATE members SET deleted_at = NOW(), email_verify_token_hash = NULL, email_verify_expires_at = NULL,
                                          reset_password_token = NULL, reset_password_expires_at = NULL, row_version = row_version + 1 WHERE id = ?')
                        ->execute([$id]);
                    $msg = '會員已刪除（軟刪除）。';
                    break;
                case 'resend':
                    if (!$acts['resend']) { throw new UserError('只有待驗證會員可以補寄驗證信。'); }
                    $token = issue_verify_token($pdo, $id);
                    if ($token === null) { throw new UserError('無法產生驗證憑證。'); }
                    $mail = ['email' => $t['email'], 'name' => $t['name'], 'token' => $token];
                    $msg = '已產生新的驗證憑證並寄出驗證信（30 分鐘內有效）。';
                    break;
                case 'deletion_approve':
                case 'deletion_reject':
                    if (!$GLOBALS['enable_launch']) { throw new UserError('功能未啟用。'); }
                    $st2 = $pdo->prepare("SELECT * FROM account_deletion_requests WHERE member_id = ? AND status = 'pending' FOR UPDATE");
                    $st2->execute([$id]);
                    $req = $st2->fetch();
                    if (!$req) { throw new UserError('此會員沒有待處理的刪除申請。'); }
                    assert_row_version($req, $_POST['row_version'] ?? '');
                    if ($action === 'deletion_reject') {
                        $note = post_str('note');
                        if (!len_between($note, 1, 200)) { throw new UserError('請填寫 1～200 字的拒絕原因。'); }
                        $pdo->prepare("UPDATE account_deletion_requests SET status = 'rejected', handled_by = ?, handled_at = NOW(), note = ?, row_version = row_version + 1 WHERE id = ?")
                            ->execute([$a['id'], $note, $req['id']]);
                        $pdo->prepare('UPDATE members SET deletion_requested_at = NULL, row_version = row_version + 1 WHERE id = ?')->execute([$id]);
                        $email = $t['email'];
                        $name = $t['name'];
                        defer_mail(function () use ($email, $name, $note, $id) {
                            send_notice_mail($email, $name, 'deletion_rejected', '刪除帳號申請未核准',
                                [$name . ' 您好：', '您的刪除帳號申請未核准。', '原因：' . $note, '如有疑問請透過客服中心與我們聯繫。'], ['member_id' => $id]);
                        });
                        $msg = '已拒絕刪除申請並通知會員。';
                    } else {
                        $email = $t['email'];
                        $name = $t['name'];
                        member_anonymize($pdo, $id, $a['id']);
                        $pdo->prepare("UPDATE account_deletion_requests SET status = 'completed', handled_by = ?, handled_at = NOW(), row_version = row_version + 1 WHERE id = ?")
                            ->execute([$a['id'], $req['id']]);
                        // 完成信在匿名化前取得原 Email，交易提交後寄出（寄信紀錄只留遮蔽後的 Email）
                        defer_mail(function () use ($email, $name, $id) {
                            global $pdo;
                            send_notice_mail($email, $name, 'deletion_completed', '帳號已刪除',
                                [$name . ' 您好：', '您的帳號已依申請刪除，個人資料已清除。感謝您曾經的支持。'], ['member_id' => $id]);
                            $pdo->prepare('UPDATE mail_logs SET to_email = ? WHERE member_id = ? AND to_email = ?')->execute([mask_email($email), $id, $email]);
                        });
                        $msg = '已刪除帳號並完成個資匿名化。';
                    }
                    break;
                default:
                    throw new UserError('不支援的操作。');
            }
            $st->execute([$id]);
            $after = $st->fetch();
            if ($action !== 'deletion_approve') { audit_log($pdo, $a['id'], 'member', $id, $action, $t, $after); }
            return ['msg' => $msg, 'mail' => $mail];
        }, 'member');
        flash('success', $res['msg']);
        if ($res['mail'] && !send_verify_mail($res['mail']['email'], $res['mail']['name'], $res['mail']['token'], null, 'admin_resend_verify', $id)) {
            flash('warn', '驗證信寄送失敗，請確認 SMTP 設定後再試（詳細原因見寄信紀錄）。');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_members.php' . ($id ? '?id=' . $id : ''));
}

// ---------------- 單一會員 ----------------
$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) { render_not_found('會員'); }
    $acts = member_allowed_actions($actor, $t);
    $denied = policy_protected_op_denied($actor, $t);
    $status = (int)$t['status'];
    $rv = (int)$t['row_version'];
    $btn = function (string $action, string $label, string $cls = '', string $confirm = '') use ($t, $rv) {
        return '<form method="post" action="admin_members.php" class="inline"' . ($confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '') . '>'
            . csrf_field() . '<input type="hidden" name="id" value="' . (int)$t['id'] . '"><input type="hidden" name="row_version" value="' . $rv . '">'
            . '<input type="hidden" name="action" value="' . h($action) . '"><button class="btn ' . $cls . '" type="submit">' . h($label) . '</button></form> ';
    };
    $orders = [];
    $orderStats = null;
    if ($enable_orders) {
        $s2 = $pdo->prepare('SELECT * FROM orders WHERE member_id = ? ORDER BY id DESC LIMIT 10');
        $s2->execute([$id]);
        $orders = $s2->fetchAll();
        $s2 = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refused') THEN total END), 0) amt FROM orders WHERE member_id = ?");
        $s2->execute([$id]);
        $orderStats = $s2->fetch();
    }
    $req = $enable_launch ? deletion_pending_request($id) : null;
    $blockers = $req ? deletion_blockers($pdo, $id) : [];
    admin_header('會員 #' . $id);
    ?>
    <p><a href="admin_members.php">← 返回會員列表</a></p>
    <h1>會員 #<?= (int)$t['id'] ?>：<?= h($t['name']) ?></h1>
    <?php if ($req): ?>
    <section class="card highlight">
      <h2>刪除帳號申請（<?= h($req['requested_at']) ?>）</h2>
      <p>原因：<?= h($req['reason'] ?? '（未填）') ?></p>
      <?php if ($blockers): ?>
        <div class="alert alert-warn">尚有未結案項目，暫時不能執行刪除：<ul><?php foreach ($blockers as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <form method="post" class="inline" onsubmit="return confirm('執行後會清除姓名、地址、電話等個資且無法復原，確定執行？')"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= (int)$req['row_version'] ?>"><input type="hidden" name="action" value="deletion_approve">
          <button class="btn btn-danger" <?= $blockers ? 'disabled' : '' ?>>執行刪除（軟刪除並匿名化）</button></form>
        <form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= (int)$req['row_version'] ?>">
          <input type="hidden" name="action" value="deletion_reject"><input type="text" name="note" maxlength="200" required placeholder="拒絕原因（會寄給會員）"><button class="btn">拒絕申請</button></form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <div class="grid-2">
      <section class="card">
        <dl class="meta">
          <dt>Email</dt><dd><?= h($t['email']) ?></dd>
          <dt>狀態</dt><dd><?= badge(member_status_label($t)) ?><?= $t['anonymized_at'] ? ' ' . badge('已匿名化') : '' ?></dd>
          <dt>權限</dt><dd><?= (int)$t['is_super_admin'] ? '系統管理者' : ((int)$t['is_admin'] ? '管理員' : '一般會員') ?><?= is_super() && $enable_ops ? '（<a href="admin_admins.php?id=' . $id . '">管理權限</a>）' : '' ?></dd>
          <dt>Email 驗證</dt><dd><?= $t['email_verified_at'] ? h($t['email_verified_at']) : '尚未驗證' ?></dd>
          <dt>等級</dt><dd><?= h(member_level_label($t['member_level'])) ?><?= (int)$t['level_locked'] ? '（已鎖定）' : '' ?></dd>
          <dt>累積消費</dt><dd><?= money($t['total_spent']) ?></dd>
          <dt>註冊時間</dt><dd><?= h($t['created_at']) ?></dd>
          <?php if ($t['deleted_at']): ?><dt>刪除時間</dt><dd><?= h($t['deleted_at']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($t['deleted_at']): ?><div class="alert alert-warn">此會員已刪除，停用一般管理操作。</div>
        <?php elseif ($status === 2): ?><div class="alert alert-info">待驗證會員須完成 Email 驗證才能啟用，可補寄驗證信。</div><?php endif; ?>
        <?php if ($denied && !$t['deleted_at']): ?><p class="muted small">保護規則：<?= h($denied) ?></p><?php endif; ?>
        <?php if ($canEdit): ?><p class="actions">
          <?= $acts['activate'] ? $btn('activate', '啟用') : '' ?>
          <?= $acts['suspend'] ? $btn('suspend', '停權', 'btn-danger', '確定停權此會員？') : '' ?>
          <?= $acts['resend'] ? $btn('resend', '補寄驗證信') : '' ?>
          <?= $acts['delete'] ? $btn('delete', '刪除（軟刪除）', 'btn-danger', '確定刪除此會員？') : '' ?>
        </p><?php endif; ?>
      </section>
      <?php if ($acts['edit'] && $canEdit): ?>
      <section class="card">
        <h2>修改資料</h2>
        <form method="post" action="admin_members.php" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="row_version" value="<?= $rv ?>"><input type="hidden" name="action" value="update">
          <label>姓名 <input type="text" name="name" value="<?= h($t['name']) ?>" maxlength="50" required></label>
          <label>會員等級 <select name="member_level"><?= options_html(member_levels(), $t['member_level']) ?></select></label>
          <?php if ($enable_orders): ?><label class="check"><input type="checkbox" name="level_locked" value="1" <?= (int)$t['level_locked'] ? 'checked' : '' ?>> 鎖定等級（不參與自動計算，累積消費照常更新）</label><?php endif; ?>
          <?php if ($acts['admin_flag']): ?><label class="check"><input type="checkbox" name="is_admin" value="1" <?= (int)$t['is_admin'] ? 'checked' : '' ?>> 管理者</label><?php endif; ?>
          <button class="btn btn-primary" type="submit">儲存</button>
        </form>
      </section>
      <?php endif; ?>
    </div>
    <?php if ($enable_orders): ?>
    <section class="card"><h2>訂單摘要</h2>
      <p>共 <?= (int)$orderStats['n'] ?> 筆，金額合計 <?= money($orderStats['amt']) ?>（不含取消與拒收）</p>
      <?php if ($orders): ?><div class="table-wrap"><table><thead><tr><th>訂單</th><th>時間</th><th>狀態</th><th class="num">總額</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?><tr><td><?= admin_can('order') ? '<a href="admin_orders.php?id=' . (int)$o['id'] . '">' . h($o['order_no']) . '</a>' : h($o['order_no']) ?></td>
          <td><?= h($o['created_at']) ?></td><td><?= h(label_of(order_status_labels(), $o['status'])) ?></td><td class="num"><?= money($o['total']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php
    page_footer();
    exit;
}

// ---------------- 列表 ----------------
$f = ['q' => get_str('q'), 'status' => get_str('status')];
$where = [];
$args = [];
if ($f['q'] !== '') {
    if (ctype_digit($f['q'])) { $where[] = 'm.id = ?'; $args[] = (int)$f['q']; }
    else { $where[] = '(m.name LIKE ? OR m.email LIKE ?)'; $l = '%' . addcslashes($f['q'], '%_\\') . '%'; $args[] = $l; $args[] = $l; }
}
$statusOpts = ['active' => '啟用', 'disabled' => '停權', 'pending' => '待驗證', 'deleted' => '已刪除'];
if ($enable_launch) { $statusOpts['deletion'] = '刪除申請待處理'; }
switch ($f['status']) {
    case 'active': $where[] = 'm.status = 1 AND m.deleted_at IS NULL'; break;
    case 'disabled': $where[] = 'm.status = 0 AND m.deleted_at IS NULL'; break;
    case 'pending': $where[] = 'm.status = 2 AND m.deleted_at IS NULL'; break;
    case 'deleted': $where[] = 'm.deleted_at IS NOT NULL'; break;
    case 'deletion': if ($enable_launch) { $where[] = "EXISTS (SELECT 1 FROM account_deletion_requests r WHERE r.member_id = m.id AND r.status = 'pending')"; } break;
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = current_page();
$per = 30;
$st = $pdo->prepare("SELECT COUNT(*) FROM members m {$w}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT m.* FROM members m {$w} ORDER BY m.id DESC LIMIT {$per} OFFSET {$off}");
$st->execute($args);
$rows = $st->fetchAll();
$pendingDel = $enable_launch ? (int)$pdo->query("SELECT COUNT(*) FROM account_deletion_requests WHERE status = 'pending'")->fetchColumn() : 0;
admin_header('會員管理');
?>
<h1>會員管理</h1>
<?php if ($pendingDel): ?><div class="alert alert-info">有 <?= $pendingDel ?> 筆刪除帳號申請待處理。<a href="admin_members.php?status=deletion">查看</a></div><?php endif; ?>
<form class="filters" method="get">
  <label>編號、姓名或 Email <input type="text" name="q" value="<?= h($f['q']) ?>" maxlength="100"></label>
  <label>狀態 <select name="status"><option value="">全部</option><?= options_html($statusOpts, $f['status']) ?></select></label>
  <button class="btn btn-primary">查詢</button> <a class="btn" href="admin_members.php">清除</a>
</form>
<div class="table-wrap"><table>
  <thead><tr><th>編號</th><th>姓名</th><th>Email</th><th>狀態</th><th>等級</th><th>權限</th><th>註冊時間</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $m): ?>
    <tr><td><?= (int)$m['id'] ?></td><td><?= h($m['name']) ?></td><td><?= h($m['email']) ?></td>
      <td><?= h(member_status_label($m)) ?><?= $enable_launch && $m['deletion_requested_at'] && !$m['deleted_at'] ? ' ' . badge('申請刪除', 'warn') : '' ?></td>
      <td><?= h(member_level_label($m['member_level'])) ?><?= (int)$m['level_locked'] ? '🔒' : '' ?></td>
      <td><?= (int)$m['is_super_admin'] ? '系統管理者' : ((int)$m['is_admin'] ? '管理員' : '') ?></td>
      <td><?= h($m['created_at']) ?></td><td><a class="btn btn-small" href="admin_members.php?id=<?= (int)$m['id'] ?>">管理</a></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, query_keep(['page'])) ?>
<?php
page_footer();
