<?php
/**
 * 後台：會員行為報表（六項商品排行＋事件明細）
 * 初次開頁不自動查詢；按「查詢」後才執行（GET 參數 run=1）。查詢錯誤時保留條件。
 */
require_once __DIR__ . '/app.php';
if (!$enable_behavior) { render_error_page(404, '功能未啟用', '行為分析功能尚未啟用。'); }
require_perm('report');
require_once __DIR__ . '/lib/behavior_reports.php';

$run = ($_GET['run'] ?? '') === '1';
[$f, $errors] = beh_report_parse($run ? $_GET : []);
$result = null;
if ($run && !$errors) {
    try {
        $result = beh_report_run($f);
    } catch (UserError $e) {
        $errors[] = $e->getMessage();
    }
}
$cats = categories_all();
$types = beh_event_types();
$levels = member_levels();

function rank_table(string $title, array $rows, array $names, bool $money, string $unit, string $empty): string
{
    $h = '<section class="card"><h2>' . h($title) . '</h2>';
    if (!$rows) { return $h . '<p class="muted small">' . h($empty) . '</p></section>'; }
    $h .= '<table><thead><tr><th>#</th><th>商品</th><th class="num">' . h($unit) . '</th></tr></thead><tbody>';
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        $pid = (int)$r['product_id'];
        $n = $names[$pid] ?? null;
        $label = $n ? h($n['name']) . ' <span class="muted small">' . h($n['sku']) . '</span>' : '#' . $pid . '（商品已不存在）';
        $val = $money ? money($r['v']) : number_format((float)$r['v']);
        $h .= '<tr><td>' . $i . '</td><td><a href="product.php?id=' . $pid . '">' . $label . '</a></td><td class="num">' . $val . '</td></tr>';
    }
    return $h . '</tbody></table></section>';
}

page_header('會員行為報表', true);
?>
<h1>會員行為報表</h1>
<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>

<form method="get" action="admin_behavior_reports.php" class="card stack" id="report-form">
  <input type="hidden" name="run" value="1">
  <div class="filters" style="margin:0">
    <label>起日 <input type="date" name="date_from" value="<?= h($f['date_from']) ?>" required></label>
    <label>訖日 <input type="date" name="date_to" value="<?= h($f['date_to']) ?>" required></label>
    <label>事件當時分類
      <select name="category">
        <option value="">全部分類</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f['category'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>資料來源
      <select name="source">
        <option value="both" <?= $f['source'] === 'both' ? 'selected' : '' ?>>線上＋封存（合併去重）</option>
        <option value="online" <?= $f['source'] === 'online' ? 'selected' : '' ?>>只查線上</option>
        <option value="archive" <?= $f['source'] === 'archive' ? 'selected' : '' ?>>只查封存</option>
      </select>
    </label>
  </div>

  <fieldset>
    <legend>會員範圍</legend>
    <label class="check"><input type="radio" name="member_mode" value="all" <?= $f['member_mode'] === 'all' ? 'checked' : '' ?>> 全部會員</label>
    <label class="check"><input type="radio" name="member_mode" value="specific" <?= $f['member_mode'] === 'specific' ? 'checked' : '' ?>> 指定會員</label>
    <label class="check"><input type="radio" name="member_mode" value="level" <?= $f['member_mode'] === 'level' ? 'checked' : '' ?>> 會員等級</label>
    <fieldset data-mode="specific" style="margin-top:8px">
      <legend>指定會員</legend>
      <label>姓名或編號 <input type="text" name="member_q" value="<?= h($f['member_q']) ?>" maxlength="50" placeholder="可只輸入姓名"></label>
    </fieldset>
    <fieldset data-mode="level" style="margin-top:8px">
      <legend>會員等級</legend>
      <label class="check"><input type="radio" name="level_basis" value="current" <?= $f['level_basis'] === 'current' ? 'checked' : '' ?>> 目前等級</label>
      <label class="check"><input type="radio" name="level_basis" value="historical" <?= $f['level_basis'] === 'historical' ? 'checked' : '' ?>> 事件當時等級</label>
      <div>
        <?php foreach ($levels as $k => $label): ?>
          <label class="check"><input type="checkbox" name="levels[]" value="<?= h($k) ?>" <?= in_array($k, $f['levels'], true) ? 'checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach; ?>
        <label class="check" data-historical-only><input type="checkbox" name="levels[]" value="unknown" <?= in_array('unknown', $f['levels'], true) ? 'checked' : '' ?>> 未知（僅事件當時等級）</label>
      </div>
    </fieldset>
  </fieldset>

  <fieldset>
    <legend>報表顯示</legend>
    <div>行為類型：
      <?php foreach ($types as $k => $label): ?>
        <label class="check"><input type="checkbox" name="types[]" value="<?= h($k) ?>" <?= in_array($k, $f['types'], true) ? 'checked' : '' ?>> <?= h($label) ?></label>
      <?php endforeach; ?>
    </div>
    <div>淨值排行（只影響「淨件數」「淨金額」）：
      <label class="check"><input type="radio" name="net_mode" value="positive" <?= $f['net_mode'] === 'positive' ? 'checked' : '' ?>> 僅正值</label>
      <label class="check"><input type="radio" name="net_mode" value="all" <?= $f['net_mode'] === 'all' ? 'checked' : '' ?>> 全部</label>
      <label class="check"><input type="radio" name="net_mode" value="negative" <?= $f['net_mode'] === 'negative' ? 'checked' : '' ?>> 僅負值</label>
    </div>
  </fieldset>
  <div><button class="btn btn-primary" type="submit">查詢</button> <a class="btn" href="admin_behavior_reports.php">重設條件</a></div>
</form>

<script>
(function () {
  var form = document.getElementById('report-form');
  function sync() {
    var mode = (form.querySelector('input[name=member_mode]:checked') || {}).value || 'all';
    form.querySelectorAll('fieldset[data-mode]').forEach(function (fs) { fs.disabled = fs.getAttribute('data-mode') !== mode; });
    var basis = (form.querySelector('input[name=level_basis]:checked') || {}).value || 'current';
    var unk = form.querySelector('[data-historical-only] input');
    unk.disabled = mode !== 'level' || basis !== 'historical';
    if (unk.disabled) { unk.checked = false; }
  }
  form.addEventListener('change', sync);
  sync();
})();
</script>

<?php if (!$run): ?>
  <p class="empty">設定條件後按「查詢」產生報表。</p>
<?php elseif ($result): ?>
  <?php foreach ($result['notes'] as $n): ?><div class="alert alert-warn"><?= h($n) ?></div><?php endforeach; ?>
  <div class="kpi">
    <?php $sum = []; foreach ($result['summary'] as $s) { $sum[$s['event_type']] = (int)$s['n']; } ?>
    <?php foreach ($types as $k => $label): ?><div><?= h($label) ?><strong><?= number_format($sum[$k] ?? 0) ?></strong></div><?php endforeach; ?>
    <div>來源組成
      <strong class="small"><?php foreach ($result['origins'] as $o) { echo h($o['origin']) . ' ' . number_format((int)$o['n']) . '　'; } ?></strong>
    </div>
  </div>
  <p class="muted small">說明：報表含所選期間、來源內所有事件（包含 origin 為 legacy_view、partition_test 的資料）。前四項只列大於零的商品；後兩項為期間淨變動，與期末購物車餘額不同。</p>
  <?php if ((int)$result['unknown']['events'] > 0): ?>
    <div class="alert alert-info">有 <?= (int)$result['unknown']['events'] ?> 筆購物車事件金額未知（<?= (int)$result['unknown']['products'] ?> 項商品），這些商品不列入「加入購物車淨金額」排行，其他排行不受影響。</div>
  <?php endif; ?>
  <div class="rank-grid">
    <?= rank_table('商品瀏覽次數', $result['rank']['views'], $result['names'], false, '次數', '期間內沒有瀏覽事件。') ?>
    <?= rank_table('不重複瀏覽工作階段', $result['rank']['sessions'], $result['names'], false, '工作階段', '期間內沒有瀏覽事件。') ?>
    <?= rank_table('加入購物車次數', $result['rank']['adds'], $result['names'], false, '次數', '期間內沒有加入購物車事件。') ?>
    <?= rank_table('移出購物車次數', $result['rank']['removes'], $result['names'], false, '次數', '期間內沒有移出購物車事件。') ?>
    <?= rank_table('加入購物車淨件數', $result['rank']['net_qty'], $result['names'], false, '淨件數', '沒有符合淨值條件的商品。') ?>
    <?= rank_table('加入購物車淨金額', $result['rank']['net_amount'], $result['names'], true, '淨金額', '沒有符合淨值條件的商品。') ?>
  </div>

  <h2 style="margin-top:24px">事件明細</h2>
  <?php if (!$result['details']): ?>
    <p class="empty">沒有符合條件的事件。</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>時間</th><th>類型</th><th>會員</th><th>當時等級</th><th>商品</th><th>當時分類</th><th class="num">前→後</th><th class="num">單價</th><th class="num">金額變動</th><th>工作階段</th><th>來源</th></tr></thead>
    <tbody>
    <?php foreach ($result['details'] as $d): ?>
      <tr>
        <td class="small"><?= h(substr($d['event_time'], 0, 19)) ?></td>
        <td><?= h($types[$d['event_type']] ?? $d['event_type']) ?></td>
        <td><?= h($d['member_name'] ?? '#' . $d['member_id']) ?></td>
        <td><?= h(member_level_label($d['member_level_at_event'])) ?></td>
        <td><?= h($d['product_name'] ?? '#' . $d['product_id']) ?></td>
        <td><?= h($d['category_name'] ?? ($d['category_id_at_event'] ? '#' . $d['category_id_at_event'] : '—')) ?></td>
        <td class="num"><?= $d['quantity_before'] !== null ? (int)$d['quantity_before'] . '→' . (int)$d['quantity_after'] : '—' ?></td>
        <td class="num"><?= $d['unit_price'] !== null ? money($d['unit_price']) : '—' ?></td>
        <td class="num"><?= $d['amount_delta'] !== null ? money($d['amount_delta']) : ($d['event_type'] === 'view_product' ? '—' : '未知') ?></td>
        <td class="small"><?= h(substr((string)$d['session_key'], 0, 8)) ?></td>
        <td class="small"><?= h($d['origin']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php
    $params = $_GET; unset($params['page']);
    echo render_pager($result['total'], $f['page'], $result['perPage'], $params);
  ?>
  <?php endif; ?>
<?php endif; ?>
<?php
page_footer();
