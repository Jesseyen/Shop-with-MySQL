<?php
/**
 * 訊息中心（客服及聯繫表單模組，第 24 節）
 * 狀態：未處理 → 處理中（接手，記錄處理人）→ 已處理；已處理可重新開啟為處理中
 * 處理摘要只給內部看；回覆客戶會寄信，會員也能在客服中心看到；每次動作寫入訊息歷程
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/support.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('support', is_post() ? 'edit' : 'view');
$canEdit = admin_can('support', 'edit');

if (is_post()) {
    require_csrf();
    $id = to_pos_int($_POST['id'] ?? '') ?? 0;
    $action = post_str('action');
    $text = trim(str_replace("\r\n", "\n", (string)($_POST['content'] ?? '')));
    try {
        $mail = admin_transaction(function (PDO $pdo, array $a) use ($id, $action, $text) {
            $st = $pdo->prepare('SELECT * FROM support_messages WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $m = $st->fetch();
            if (!$m) { throw new UserError('找不到此訊息。'); }
            assert_row_version($m, $_POST['row_version'] ?? '');
            $ev = $pdo->prepare('INSERT INTO support_message_events (message_id, event_type, content, from_status, to_status, is_public, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $set = function (array $f) use ($pdo, $m) {
                $cols = [];
                $args = [];
                foreach ($f as $k => $v) { $cols[] = "`{$k}` = ?"; $args[] = $v; }
                array_push($args, $m['id'], $m['row_version']);
                $cols[] = 'row_version = row_version + 1';
                $st = $pdo->prepare('UPDATE support_messages SET ' . implode(', ', $cols) . ' WHERE id = ? AND row_version = ?');
                $st->execute($args);
                if ($st->rowCount() !== 1) { throw new UserError('訊息已被其他人更新，請重新整理。'); }
            };
            $mail = null;
            switch ($action) {
                case 'take':
                    if ($m['status'] === 'done') { throw new UserError('已處理的訊息請先重新開啟。'); }
                    $set(['status' => 'in_progress', 'handler_id' => $a['id']]);
                    $ev->execute([$id, 'take', null, $m['status'], 'in_progress', 0, $a['id']]);
                    break;
                case 'done':
                    if ($m['status'] !== 'in_progress') { throw new UserError('只有處理中的訊息可以標記已處理。'); }
                    $set(['status' => 'done', 'done_at' => date('Y-m-d H:i:s')]);
                    $ev->execute([$id, 'status', null, 'in_progress', 'done', 1, $a['id']]);
                    break;
                case 'reopen':
                    if ($m['status'] !== 'done') { throw new UserError('只有已處理的訊息可以重新開啟。'); }
                    $set(['status' => 'in_progress', 'handler_id' => $a['id'], 'done_at' => null]);
                    $ev->execute([$id, 'status', null, 'done', 'in_progress', 1, $a['id']]);
                    break;
                case 'note':
                    if (!len_between($text, 1, 2000)) { throw new UserError('處理摘要須為 1～2,000 字（請勿寫入個資）。'); }
                    $set([]);
                    $ev->execute([$id, 'note', $text, null, null, 0, $a['id']]);
                    break;
                case 'reply':
                    if (!len_between($text, 1, 5000)) { throw new UserError('回覆內容須為 1～5,000 字。'); }
                    if ($m['email'] === null) { throw new UserError('此訊息沒有可回覆的 Email（可能已匿名化）。'); }
                    $f = $m['status'] === 'new' ? ['status' => 'in_progress', 'handler_id' => $a['id']] : [];
                    $set($f);
                    if ($m['status'] === 'new') { $ev->execute([$id, 'take', null, 'new', 'in_progress', 0, $a['id']]); }
                    $ev->execute([$id, 'reply', $text, null, null, 1, $a['id']]);
                    $mail = $m;
                    break;
                default:
                    throw new UserError('不支援的操作。');
            }
            return $mail;
        }, 'support');
        if ($mail) {
            $ok = send_notice_mail($mail['email'], (string)$mail['name'], 'support_reply', '客服回覆 ' . $mail['message_no'], [
                ($mail['name'] ?? '') . ' 您好：', '關於您的訊息「' . $mail['subject'] . '」（編號 ' . $mail['message_no'] . '），客服回覆如下：', $text,
                $mail['member_id'] ? '您也可以在會員中心的「客服中心」查看這則回覆。' : '',
            ], ['member_id' => $mail['member_id'] ? (int)$mail['member_id'] : null, 'message_id' => (int)$mail['id']]);
            flash($ok ? 'success' : 'warn', $ok ? '已回覆並寄信給客戶。' : '回覆已儲存，但寄信失敗（請見寄信紀錄）。');
        } else {
            flash('success', '已更新。');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_messages.php?id=' . $id);
}

$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $st = $pdo->prepare('SELECT s.*, h.name AS handler_name, o.order_no, m.member_level FROM support_messages s LEFT JOIN members h ON h.id = s.handler_id
                           LEFT JOIN orders o ON o.id = s.order_id LEFT JOIN members m ON m.id = s.member_id WHERE s.id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) { render_not_found('訊息'); }
    $st = $pdo->prepare('SELECT e.*, a.name AS actor FROM support_message_events e LEFT JOIN members a ON a.id = e.actor_id WHERE e.message_id = ? ORDER BY e.id');
    $st->execute([$id]);
    $events = $st->fetchAll();
    $orderCount = null;
    if ($m['member_id'] && $enable_orders) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE member_id = ?');
        $st->execute([$m['member_id']]);
        $orderCount = (int)$st->fetchColumn();
    }
    $rv = (int)$m['row_version'];
    $act = function (string $a, string $label, string $cls = '') use ($id, $rv) {
        return '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="row_version" value="' . $rv . '">'
            . '<input type="hidden" name="action" value="' . $a . '"><button class="btn ' . $cls . '">' . $label . '</button></form> ';
    };
    admin_header('訊息 ' . $m['message_no']);
    ?>
    <p><a href="admin_messages.php">← 訊息中心</a></p>
    <h1><?= h($m['subject'] ?? '（已匿名化）') ?></h1>
    <p><?= badge(support_status_labels()[$m['status']]) ?>　<?= h($m['message_no']) ?>　<?= h(support_category_labels()[$m['category']]) ?>　<?= $m['source'] === 'member' ? '會員' : '訪客' ?>　處理人：<?= h($m['handler_name'] ?? '—') ?></p>
    <div class="grid-2">
      <section class="card"><dl class="meta">
        <dt>姓名</dt><dd><?= h($m['name'] ?? '') ?></dd><dt>Email</dt><dd><?= h($m['email'] ?? '') ?></dd><dt>電話</dt><dd><?= h($m['phone'] ?? '—') ?></dd>
        <dt>送出時間</dt><dd><?= h($m['created_at']) ?></dd><dt>來源 IP</dt><dd><?= h($m['ip'] ?? '') ?></dd>
        <?php if ($m['member_id']): ?>
          <dt>會員</dt><dd><?= admin_can('member') ? '<a href="admin_members.php?id=' . (int)$m['member_id'] . '">#' . (int)$m['member_id'] . '</a>' : '#' . (int)$m['member_id'] ?>（<?= h(member_level_label($m['member_level'])) ?><?= $orderCount !== null ? '，訂單 ' . $orderCount . ' 筆' : '' ?>）</dd>
          <?php if ($m['order_no']): ?><dt>相關訂單</dt><dd><?= admin_can('order') ? '<a href="admin_orders.php?id=' . (int)$m['order_id'] . '">' . h($m['order_no']) . '</a>' : h($m['order_no']) ?></dd><?php endif; ?>
        <?php endif; ?>
      </dl><h2>內容</h2><?= plain_to_html((string)$m['body']) ?></section>
      <section class="card">
        <?php if ($canEdit): ?>
          <p class="actions"><?= $m['status'] === 'new' ? $act('take', '接手處理', 'btn-primary') : '' ?><?= $m['status'] === 'in_progress' ? $act('done', '標記已處理') : '' ?><?= $m['status'] === 'done' ? $act('reopen', '重新開啟') : '' ?></p>
          <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= $rv ?>"><input type="hidden" name="action" value="reply">
            <label>回覆客戶（會寄信，會員也能在客服中心看到） <textarea name="content" rows="5" maxlength="5000" required></textarea></label><button class="btn btn-primary">送出回覆</button></form>
          <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= $rv ?>"><input type="hidden" name="action" value="note">
            <label>處理摘要（只給內部看，請勿寫入個資） <textarea name="content" rows="3" maxlength="2000" required></textarea></label><button class="btn">新增摘要</button></form>
        <?php endif; ?>
      </section>
    </div>
    <section class="card"><h2>訊息歷程</h2><ol class="timeline">
      <?php foreach ($events as $e): ?><li><time><?= h(substr($e['created_at'], 0, 19)) ?></time> <?= badge(support_event_labels()[$e['event_type']]) ?>
        <?= $e['from_status'] || $e['to_status'] ? h(($e['from_status'] ? support_status_labels()[$e['from_status']] : '') . ' → ' . ($e['to_status'] ? support_status_labels()[$e['to_status']] : '')) : '' ?>
        <span class="muted small"><?= h($e['actor'] ?? '') ?><?= (int)$e['is_public'] ? '' : '（內部）' ?></span>
        <?php if ($e['content'] !== null && $e['event_type'] !== 'create'): ?><div><?= plain_to_html($e['content']) ?></div><?php endif; ?></li><?php endforeach; ?>
    </ol></section>
    <?php
    page_footer();
    exit;
}

$f = ['status' => get_str('status'), 'source' => get_str('source'), 'category' => get_str('category'), 'handler' => get_str('handler'),
      'from' => get_str('from'), 'to' => get_str('to')];
$where = [];
$args = [];
if (isset(support_status_labels()[$f['status']])) { $where[] = 's.status = ?'; $args[] = $f['status']; }
if (in_array($f['source'], ['contact', 'member'], true)) { $where[] = 's.source = ?'; $args[] = $f['source']; }
if (isset(support_category_labels()[$f['category']])) { $where[] = 's.category = ?'; $args[] = $f['category']; }
if (($hid = to_pos_int($f['handler'])) !== null) { $where[] = 's.handler_id = ?'; $args[] = $hid; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 's.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 's.created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page = current_page();
$per = 30;
$st = $pdo->prepare("SELECT COUNT(*) FROM support_messages s {$w}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$off = ($page - 1) * $per;
$st = $pdo->prepare("SELECT s.*, h.name AS handler_name FROM support_messages s LEFT JOIN members h ON h.id = s.handler_id {$w}
                      ORDER BY (s.status = 'new') DESC, s.id DESC LIMIT {$per} OFFSET {$off}");
$st->execute($args);
$handlers = $pdo->query('SELECT DISTINCT h.id, h.name FROM support_messages s JOIN members h ON h.id = s.handler_id ORDER BY h.name')->fetchAll(PDO::FETCH_KEY_PAIR);
admin_header('訊息中心');
?>
<h1>訊息中心</h1>
<form class="filters" method="get">
  <label>狀態 <select name="status"><option value="">全部</option><?= options_html(support_status_labels(), $f['status']) ?></select></label>
  <label>來源 <select name="source"><option value="">全部</option><?= options_html(['contact' => '訪客', 'member' => '會員'], $f['source']) ?></select></label>
  <label>類別 <select name="category"><option value="">全部</option><?= options_html(support_category_labels(), $f['category']) ?></select></label>
  <label>處理人 <select name="handler"><option value="">全部</option><?= options_html($handlers, $f['handler']) ?></select></label>
  <label>日期 <input type="date" name="from" value="<?= h($f['from']) ?>"> ～ <input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <button class="btn btn-primary">篩選</button> <a class="btn" href="admin_messages.php">清除</a>
</form>
<div class="table-wrap"><table>
  <thead><tr><th>編號</th><th>狀態</th><th>來源</th><th>類別</th><th>主旨</th><th>姓名</th><th>處理人</th><th>時間</th></tr></thead>
  <tbody><?php foreach ($st->fetchAll() as $m): ?>
    <tr class="<?= $m['status'] === 'new' ? 'row-new' : '' ?>"><td><a href="admin_messages.php?id=<?= (int)$m['id'] ?>"><?= h($m['message_no']) ?></a></td>
      <td><?= h(support_status_labels()[$m['status']]) ?></td><td><?= $m['source'] === 'member' ? '會員' : '訪客' ?></td><td><?= h(support_category_labels()[$m['category']]) ?></td>
      <td><?= h($m['subject'] ?? '') ?></td><td><?= h($m['name'] ?? '') ?></td><td><?= h($m['handler_name'] ?? '—') ?></td><td><?= h($m['created_at']) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= render_pager($total, $page, $per, query_keep(['page'])) ?>
<?php
page_footer();
