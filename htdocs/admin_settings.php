<?php
/**
 * 商場設定（系統管理者）：運費模式、貨到付款手續費、退換貨天數（7～90）、自動完成天數（1～14）、寄回地址
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

if (is_post()) {
    require_csrf();
    try {
        $mode = post_str('shipping_mode');
        $fee = parse_price(post_str('cod_fee'));
        $rd = to_pos_int(post_str('return_days'));
        $ad = to_pos_int(post_str('auto_complete_days'));
        $addr = post_str('return_address');
        if (!in_array($mode, ['all_free', 'by_rule'], true)) { throw new UserError('運費模式不正確。'); }
        if ($fee === null) { throw new UserError('手續費格式不正確。'); }
        if ($rd === null || $rd < 7 || $rd > 90) { throw new UserError('退換貨天數須為 7～90 天（下限對應消保法 7 日解除權）。'); }
        if ($ad === null || $ad < 1 || $ad > 14) { throw new UserError('自動完成天數須為 1～14 天。'); }
        if (mb_strlen($addr) > 300) { throw new UserError('寄回地址最多 300 字。'); }
        admin_transaction(function (PDO $pdo, array $a) use ($mode, $fee, $rd, $ad, $addr) {
            settings_save($pdo, ['shipping_mode' => $mode, 'cod_fee' => $fee, 'return_days' => (string)$rd,
                                 'auto_complete_days' => (string)$ad, 'return_address' => $addr], $a['id']);
        });
        flash('success', '商場設定已儲存。');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_settings.php');
}
admin_header('商場設定');
?>
<h1>商場設定</h1>
<form method="post" class="card stack">
  <?= csrf_field() ?>
  <fieldset><legend>運費模式</legend>
    <label class="check"><input type="radio" name="shipping_mode" value="by_rule" <?= setting('shipping_mode') !== 'all_free' ? 'checked' : '' ?>> 依商品運費規則計算（<a href="admin_shipping_rules.php">管理規則</a>）</label>
    <label class="check"><input type="radio" name="shipping_mode" value="all_free" <?= setting('shipping_mode') === 'all_free' ? 'checked' : '' ?>> 全部免運（宅配）</label>
  </fieldset>
  <label>貨到付款手續費（每筆訂單一次；貨到付款與超商取貨付款適用） <input type="text" name="cod_fee" value="<?= h(setting('cod_fee')) ?>" class="short" required></label>
  <label>退換貨天數（自訂單完成起算，7～90 天） <input type="number" name="return_days" value="<?= setting_int('return_days', 7) ?>" min="7" max="90" required></label>
  <label>出貨後自動完成天數（宅配，1～14 天） <input type="number" name="auto_complete_days" value="<?= setting_int('auto_complete_days', 3) ?>" min="1" max="14" required></label>
  <label>退換貨寄回地址（核准信會附上） <textarea name="return_address" rows="3" maxlength="300"><?= h(setting('return_address')) ?></textarea></label>
  <p class="muted small">退換貨期限下限 7 天對應消保法第 19 條通訊交易解除權；上線前建議請法律人員確認條款文字。</p>
  <button class="btn btn-primary">儲存</button>
</form>
<?php
page_footer();
