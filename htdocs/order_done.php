<?php
/**
 * 下單完成頁：顯示訂單編號；第六階段推送 GA4 purchase（以訂單欄位記錄已推送，重新整理不重複）
 */
require_once __DIR__ . '/app.php';
if (!$enable_orders) { render_not_found(); }
$me = require_member();
$id = to_pos_int($_GET['id'] ?? '');
$o = $id !== null ? order_find($id, $me['id']) : null;
if (!$o) { render_not_found('訂單'); }

$opts = ['page_type' => 'purchase', 'noindex' => true];
if ($enable_launch && $o['ga_purchase_pushed_at'] === null && in_array($o['status'], ['processing', 'shipped', 'completed'], true)) {
    $st = $pdo->prepare('UPDATE orders SET ga_purchase_pushed_at = NOW() WHERE id = ? AND ga_purchase_pushed_at IS NULL');
    $st->execute([$o['id']]);
    if ($st->rowCount() === 1) {
        $items = [];
        foreach (order_items((int)$o['id']) as $i => $it) {
            $items[] = dl_item(['sku' => $it['sku'], 'name' => $it['product_name'], 'brand' => $it['brand'], 'category_name' => $it['category_name'],
                                'variant_text' => $it['variant_text'], 'price' => $it['unit_price']], (int)$it['quantity'], $i + 1);
        }
        $opts['events'] = [dl_event('purchase', ['transaction_id' => $o['order_no'], 'value' => dl_num($o['total']),
            'shipping' => dl_num($o['shipping_fee']), 'currency' => 'TWD', 'payment_type' => label_of(payment_method_labels(), $o['payment_method']),
            'shipping_tier' => label_of(shipping_method_labels(), $o['shipping_method']), 'items' => $items])];
    }
}
page_header('訂單已成立', $opts);
?>
<section class="card narrow">
  <?php if ($o['status'] === 'pending_payment'): ?>
    <h1>訂單待付款</h1>
    <p>訂單編號 <strong><?= h($o['order_no']) ?></strong> 尚未完成付款，請於 <?= h($o['payment_due_at']) ?> 前付款，逾時將自動取消。</p>
    <p><a class="btn btn-primary" href="member_orders.php?id=<?= (int)$o['id'] ?>">前往付款</a></p>
  <?php elseif ($o['status'] === 'cancelled'): ?>
    <h1>訂單已取消</h1>
    <p>訂單編號 <strong><?= h($o['order_no']) ?></strong> 已取消。</p>
  <?php else: ?>
    <h1>訂單已成立</h1>
    <p>感謝您的訂購！訂單編號 <strong><?= h($o['order_no']) ?></strong>，總額 <?= money($o['total']) ?>（<?= h(label_of(payment_method_labels(), $o['payment_method'])) ?>）。</p>
    <p>訂單成立通知信已寄到您的 Email。您可以在「我的訂單」查看處理進度。</p>
  <?php endif; ?>
  <p><a class="btn" href="member_orders.php?id=<?= (int)$o['id'] ?>">查看訂單</a> <a class="btn" href="products.php">繼續購物</a></p>
</section>
<?php
page_footer();
