<?php
/**
 * 模擬付款頁（第六階段）：不輸入、不儲存任何卡號或帳號，只提供「成功」「失敗」「取消」三個按鈕。
 * 付款請求以 HMAC 簽章防竄改；維護模式中仍可使用（付款回報）。
 */
require_once __DIR__ . '/app.php';
if (!$enable_launch) { render_not_found(); }

$fields = ['pid', 'order', 'amount', 't', 'sig'];
$src = is_post() ? $_POST : $_GET;
$data = [];
foreach ($fields as $f) { $data[$f] = is_string($src[$f] ?? null) ? $src[$f] : ''; }

if (is_post()) {
    require_csrf();
    $result = post_str('result');
    try {
        $r = payment_handle($data, $result);
    } catch (UserError $e) {
        render_error_page(400, '付款失敗', $e->getMessage());
    }
    if ($r['status'] === 'paid') {
        flash('success', '付款成功（模擬），訂單處理中。');
        redirect('order_done.php?id=' . $r['order_id']);
    }
    if ($r['status'] === 'duplicate') {
        flash('info', '此付款已處理過，不會重複扣款。');
    } else {
        flash($result === 'failed' ? 'error' : 'info', $result === 'failed' ? '付款失敗（模擬），您可以重新付款。' : '已取消付款，您可以重新付款。');
    }
    redirect('member_orders.php?id=' . $r['order_id']);
}

if (!payment_gateway()->verify($data)) { render_error_page(400, '付款資料不正確', '付款連結無效或已被竄改。'); }
page_header('模擬付款', ['noindex' => true, 'page_type' => 'payment']);
?>
<section class="card narrow">
  <h1>模擬付款頁</h1>
  <div class="alert alert-warn">這是測試用的模擬付款頁，不會實際扣款，也不需要輸入任何卡號或帳號。</div>
  <dl class="meta"><dt>訂單編號</dt><dd><?= h($data['order']) ?></dd><dt>付款金額</dt><dd class="price big"><?= money($data['amount']) ?></dd></dl>
  <form method="post" action="pay_sim.php" class="actions">
    <?= csrf_field() ?>
    <?php foreach ($data as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
    <button class="btn btn-primary" name="result" value="success">模擬付款成功</button>
    <button class="btn btn-danger" name="result" value="failed">模擬付款失敗</button>
    <button class="btn" name="result" value="cancelled">取消</button>
  </form>
</section>
<?php
page_footer();
