<?php
/**
 * 購物車頁：名稱、規格、SKU、目前單價、庫存、數量、小計、總金額；第四階段加「前往結帳」。
 * 下架、停售或缺貨的明細保留並標示原因，停用數量更新但可移除。訪客直接開啟回應 403。
 */
require_once __DIR__ . '/app.php';
if (!$enable_cart) { render_not_found(); }
$me = current_member();
if (!$me) { render_forbidden('需要會員登入才能查看購物車', false); }
if ($enable_behavior) { analytics_touch(); }

$cart = cart_view($me['id']);
$problems = array_filter(array_column($cart['items'], 'problem'));
$opts = ['page_type' => 'cart'];
if ($enable_launch && $cart['items']) {
    $items = [];
    foreach ($cart['items'] as $i => $it) { $items[] = dl_item($it, (int)$it['quantity'], $i + 1); }
    $opts['events'] = [dl_event('view_cart', ['currency' => 'TWD', 'value' => dl_items_value($items), 'items' => $items])];
}
page_header('購物車', $opts);
?>
<h1>購物車</h1>
<?php if (!$cart['items']): ?>
  <div class="empty"><p>購物車目前是空的。</p><p><a class="btn btn-primary" href="products.php">去逛逛商品</a></p></div>
<?php else: ?>
<div class="table-wrap"><table>
  <thead><tr><th>商品</th><th>SKU</th><th class="num">單價</th><th class="num">庫存</th><th>數量</th><th class="num">小計</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($cart['items'] as $it):
      $canUpdate = (int)$it['is_active'] === 1 && (int)$it['variant_active'] === 1 && (int)$it['stock'] > 0;
      $qty = (int)$it['quantity'];
  ?>
    <tr class="<?= $it['problem'] === null ? '' : 'row-off' ?>">
      <td class="cart-product">
        <img src="<?= h(product_image_url($it['thumb'])) ?>" alt="" width="56" height="42" loading="lazy">
        <span><a href="product.php?id=<?= (int)$it['product_id'] ?>"><?= h($it['name']) ?></a>
        <?php if ($it['variant_text'] !== ''): ?><br><span class="muted small"><?= h($it['variant_text']) ?></span><?php endif; ?>
        <?php if ($it['problem'] !== null): ?> <span class="badge badge-warn"><?= h($it['problem']) ?></span><?php endif; ?></span>
      </td>
      <td><?= h($it['sku']) ?></td>
      <td class="num"><?= money($it['price']) ?></td>
      <td class="num"><?= (int)$it['stock'] ?></td>
      <td>
        <form method="post" action="cart_action.php" class="row-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
          <input type="hidden" name="row_version" value="<?= (int)$it['row_version'] ?>">
          <fieldset class="bare" <?= $canUpdate ? '' : 'disabled' ?>>
            <input type="number" name="quantity" value="<?= $qty ?>" min="1" max="<?= max(1, (int)$it['stock']) ?>" step="1" required class="qty" aria-label="數量">
            <button class="btn btn-small" type="submit">更新</button>
          </fieldset>
        </form>
      </td>
      <td class="num"><?= money($it['subtotal']) ?></td>
      <td>
        <form method="post" action="cart_action.php" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
          <input type="hidden" name="row_version" value="<?= (int)$it['row_version'] ?>">
          <button class="btn btn-small btn-danger" type="submit">移除</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="4">共 <?= (int)$cart['count'] ?> 件</td><td>商品小計</td><td class="num"><?= money($cart['total']) ?></td><td></td></tr></tfoot>
</table></div>
<p class="muted small">金額依目前售價計算；購物車不保留庫存，送出訂單時才會扣除。</p>
<p class="actions">
  <a class="btn" href="products.php">← 繼續購物</a>
  <?php if ($enable_orders): ?>
    <?php if ($problems): ?>
      <button class="btn btn-primary" type="button" disabled>前往結帳</button>
      <span class="muted small">請先移除或調整標示問題的品項。</span>
    <?php else: ?>
      <a class="btn btn-primary" href="checkout.php">前往結帳 →</a>
    <?php endif; ?>
  <?php endif; ?>
</p>
<?php endif; ?>
<?php
page_footer();
