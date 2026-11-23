<?php
/**
 * 後台：異動紀錄（唯讀）
 */
require_once __DIR__ . '/app.php';
require_perm('super');

$types = ['member' => '會員', 'product' => '商品', 'category' => '分類', 'tag' => '標籤', 'inventory' => '庫存'];
$type = get_str('type');
$entityId = mb_substr(get_str('entity_id'), 0, 40);
$page = current_page();
$perPage = 30;
$where = [];
$args = [];
if (isset($types[$type])) { $where[] = 'l.entity_type = ?'; $args[] = $type; }
if ($entityId !== '') { $where[] = 'l.entity_id = ?'; $args[] = $entityId; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs l {$sqlWhere}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$offset = ($page - 1) * $perPage;
$st = $pdo->prepare("SELECT l.*, m.name AS actor_name FROM audit_logs l LEFT JOIN members m ON m.id = l.actor_id
                      {$sqlWhere} ORDER BY l.id DESC LIMIT {$perPage} OFFSET {$offset}");
$st->execute($args);
$rows = $st->fetchAll();

function pretty_json(?string $s): string
{
    if ($s === null) { return '<span class="muted">—</span>'; }
    $d = json_decode($s, true);
    if (!is_array($d)) { return h($s); }
    return '<pre class="json">' . h(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
}

page_header('異動紀錄', true);
?>
<h1>異動紀錄</h1>
<form class="filters" method="get" action="admin_audit_logs.php">
  <label>類型
    <select name="type">
      <option value="">全部</option>
      <?php foreach ($types as $k => $v): ?><option value="<?= h($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>資料編號 <input type="text" name="entity_id" value="<?= h($entityId) ?>" size="8"></label>
  <button class="btn" type="submit">查詢</button>
</form>
<?php if (!$rows): ?>
  <p class="empty">沒有異動紀錄。</p>
<?php else: ?>
<div class="table-wrap"><table>
  <thead><tr><th>時間</th><th>操作者</th><th>類型</th><th>編號</th><th>動作</th><th>異動前</th><th>異動後</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="small"><?= h($r['created_at']) ?></td>
      <td><?= h($r['actor_name'] ?? ('#' . $r['actor_id'])) ?></td>
      <td><?= h($types[$r['entity_type']] ?? $r['entity_type']) ?></td>
      <td><?= h($r['entity_id']) ?></td>
      <td><?= h($r['action']) ?></td>
      <td><?= pretty_json($r['before_data']) ?></td>
      <td><?= pretty_json($r['after_data']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= render_pager($total, $page, $perPage, array_filter(['type' => $type, 'entity_id' => $entityId], 'strlen')) ?>
<?php endif;
page_footer();
