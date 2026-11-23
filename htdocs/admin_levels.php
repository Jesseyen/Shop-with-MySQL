<?php
/**
 * 會員等級（會員管理模組）：門檻由低到高遞增；存檔不立即套用；
 * 「重新計算全部會員」先預覽受影響人數與名單再執行，只升不降，鎖定等級者不參與
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_perm('member', is_post() ? 'edit' : 'view');
$canEdit = admin_can('member', 'edit');

function level_recalc_candidates(): array
{
    global $pdo;
    $out = [];
    $o = level_order();
    foreach ($pdo->query('SELECT id, name, email, member_level, total_spent FROM members WHERE deleted_at IS NULL AND level_locked = 0 ORDER BY id') as $m) {
        $t = level_for($m['total_spent']);
        if ($o[$t] > $o[$m['member_level']]) { $m['target'] = $t; $out[] = $m; }
    }
    return $out;
}

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'thresholds') {
            $in = post_arr('th');
            $vals = ['regular' => '0'];
            foreach (['silver', 'gold', 'diamond'] as $k) {
                $v = parse_price((string)($in[$k] ?? ''));
                if ($v === null) { throw new UserError(member_level_label($k) . '門檻格式不正確。'); }
                $vals[$k] = $v;
            }
            if (!(dec_to_cents($vals['silver']) > 0 && dec_to_cents($vals['gold']) > dec_to_cents($vals['silver']) && dec_to_cents($vals['diamond']) > dec_to_cents($vals['gold']))) {
                throw new UserError('門檻必須由低到高遞增（銀卡 > 0）。');
            }
            admin_transaction(function (PDO $pdo, array $a) use ($vals) {
                $before = level_thresholds();
                $st = $pdo->prepare('INSERT INTO member_level_thresholds (level, threshold) VALUES (?, ?)
                                     ON DUPLICATE KEY UPDATE threshold = VALUES(threshold), row_version = row_version + 1');
                foreach ($vals as $k => $v) { $st->execute([$k, $v]); }
                audit_log($pdo, $a['id'], 'level_threshold', 'all', 'update', $before, $vals);
            }, 'member');
            flash('success', '門檻已儲存；既有會員等級不會立即變更，請使用「重新計算全部會員」。');
        } elseif ($action === 'recalc') {
            $n = admin_transaction(function (PDO $pdo, array $a) {
                $n = 0;
                foreach (level_recalc_candidates() as $m) {
                    member_spend_apply($pdo, (int)$m['id'], 0, 'recalculate', false, null, null, $a['id']);
                    $n++;
                }
                audit_log($pdo, $a['id'], 'level_threshold', 'all', 'recalculate', null, ['upgraded' => $n]);
                return $n;
            }, 'member');
            flash('success', "已重新計算，{$n} 位會員升級。");
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_levels.php' . ($action === 'preview' ? '?preview=1' : ''));
}
$th = level_thresholds();
$dist = $pdo->query('SELECT member_level, COUNT(*) n FROM members WHERE deleted_at IS NULL GROUP BY member_level')->fetchAll(PDO::FETCH_KEY_PAIR);
$preview = isset($_GET['preview']) ? level_recalc_candidates() : null;
admin_header('會員等級');
?>
<h1>會員等級與門檻</h1>
<p class="muted">累積消費 = 已完成訂單的商品小計（不含運費與手續費），退貨完成時扣除。訂單完成時只升不降；只有退貨完成使累積消費低於門檻時才降級；鎖定等級的會員不參與自動計算。</p>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="thresholds">
  <div class="table-wrap"><table><thead><tr><th>等級</th><th>門檻（元）</th><th class="num">目前人數</th></tr></thead><tbody>
  <?php foreach (member_levels() as $k => $label): ?>
    <tr><td><?= h($label) ?></td><td><?php if ($k === 'regular'): ?>0（固定）<?php elseif ($canEdit): ?><input type="text" name="th[<?= $k ?>]" value="<?= h($th[$k]) ?>" class="short" required><?php else: ?><?= money($th[$k]) ?><?php endif; ?></td>
      <td class="num"><?= (int)($dist[$k] ?? 0) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($canEdit): ?><button class="btn btn-primary">儲存門檻</button><?php endif; ?>
</form>
<section class="card"><h2>重新計算全部會員</h2>
  <p><a class="btn" href="admin_levels.php?preview=1">預覽受影響的會員</a></p>
  <?php if ($preview !== null): ?>
    <p>依目前門檻，共 <strong><?= count($preview) ?></strong> 位會員會升級（只升不降）。</p>
    <?php if ($preview): ?><div class="table-wrap"><table><thead><tr><th>會員</th><th>累積消費</th><th>目前</th><th>升級為</th></tr></thead><tbody>
      <?php foreach (array_slice($preview, 0, 200) as $m): ?><tr><td><?= h($m['name']) ?>（#<?= (int)$m['id'] ?>）</td><td><?= money($m['total_spent']) ?></td><td><?= h(member_level_label($m['member_level'])) ?></td><td><?= h(member_level_label($m['target'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <?php if ($canEdit && $preview): ?><form method="post" onsubmit="return confirm('確定重新計算？')"><?= csrf_field() ?><input type="hidden" name="action" value="recalc"><button class="btn btn-primary">執行重新計算</button></form><?php endif; ?>
  <?php endif; ?>
</section>
<?php
page_footer();
