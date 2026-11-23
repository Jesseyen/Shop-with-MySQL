<?php
/**
 * 商品詳情：規格選擇、圖片、加入購物車；第六階段輸出 SEO、Product／ProductGroup JSON-LD 與 view_item。
 */
require_once __DIR__ . '/app.php';

$id = to_pos_int($_GET['id'] ?? '');
$p = $id !== null ? product_find($id) : null;
if (!$p) { render_not_found('商品'); }
$member = current_member();
$active = (int)$p['is_active'] === 1;
$hasOptions = $enable_launch && count($p['options']) > 0;
$variants = array_values(array_filter($p['variants'], function ($v) use ($hasOptions) {
    return $hasOptions ? ((int)$v['is_active'] === 1 && $v['variant_key'] !== 'default') : (int)$v['is_default'] === 1;
}));
if (!$variants) { $variants = $p['variants']; }

// 目前選擇的規格：網址 variant 參數，否則第一個有庫存的規格
$sel = null;
$want = to_pos_int($_GET['variant'] ?? '');
foreach ($variants as $v) { if ($want !== null && (int)$v['id'] === $want) { $sel = $v; } }
if ($sel === null) { foreach ($variants as $v) { if ($sel === null && (int)$v['stock'] > 0) { $sel = $v; } } }
if ($sel === null) { $sel = $variants[0]; }

// ---- 第三階段：瀏覽計次 ----
$hadMarker = isset($_GET['_cart_return']);
if ($enable_behavior && $member !== null && !is_post()) {
    $isCartReturn = $hadMarker && cart_return_consume(get_str('_cart_return'), $member['id'], (int)$p['id']);
    analytics_touch();
    if ($active && !$isCartReturn) { record_product_view($member, $p); }
}

// ---- 加入購物車狀態（以規格計算）----
$inCart = ($enable_cart && $member !== null) ? cart_quantities($member['id']) : [];
$vdata = [];
foreach ($variants as $v) {
    $in = $inCart[(int)$v['id']] ?? 0;
    $img = null;
    foreach ($p['images'] as $im) { if ((int)$im['id'] === (int)$v['image_id']) { $img = PRODUCT_IMAGE_DIR . $im['file_large']; } }
    $vdata[(int)$v['id']] = ['price' => money($v['effective_price']), 'stock' => (int)$v['stock'], 'in_cart' => $in,
        'available' => max(0, (int)$v['stock'] - $in), 'sku' => $v['sku'], 'image' => $img, 'active' => (int)$v['is_active']];
}
$cur = $vdata[(int)$sel['id']];
$reason = null;
if (!$active) { $reason = '此商品已下架，暫停加入購物車。'; }
elseif ((int)$sel['is_active'] !== 1) { $reason = '此規格已停售。'; }
elseif ($cur['stock'] <= 0) { $reason = '目前缺貨，暫時無法加入購物車。'; }
elseif ($member !== null && $cur['available'] <= 0) { $reason = '您的購物車已達此規格的庫存上限（購物車內 ' . $cur['in_cart'] . ' 件）。'; }

$notice = $_SESSION['_cart_notice'] ?? null;
unset($_SESSION['_cart_notice']);
if ($notice && (int)($notice['product_id'] ?? 0) !== (int)$p['id']) { $notice = null; }

// ---- SEO 與 dataLayer（第六階段）----
$opts = ['canonical' => 'product.php?id=' . (int)$p['id'], 'page_type' => 'product', 'noindex' => !$active];
if ($enable_launch) {
    [$seoTitle, $seoDesc, $ogImg] = seo_product_meta($p);
    $opts['seo_title'] = $seoTitle;
    $opts['description'] = $seoDesc;
    $opts['og'] = ['type' => 'product', 'title' => $seoTitle, 'description' => $seoDesc, 'image' => $ogImg,
                   'extra' => $active ? ['product:price:amount' => (string)dl_num($sel['effective_price']), 'product:price:currency' => 'TWD'] : []];
    $crumbs = [['首頁', '']];
    if ($p['category_id'] !== null) { $crumbs[] = [$p['category_name'], 'products.php?category=' . (int)$p['category_id']]; }
    $crumbs[] = [$p['name'], 'product.php?id=' . (int)$p['id']];
    $opts['jsonld'] = [seo_product($p), seo_breadcrumb($crumbs)];
    if ($active) {
        $it = dl_item(['sku' => $sel['sku'], 'name' => $p['name'], 'brand' => $p['brand'], 'category_name' => $p['category_name'],
                       'variant_text' => $hasOptions ? $sel['variant_text'] : '', 'price' => $sel['effective_price']], 1);
        $opts['events'] = [dl_event('view_item', ['currency' => 'TWD', 'value' => $it['price'], 'items' => [$it]])];
    }
}

page_header($p['name'], $opts);
$mainImg = $cur['image'] ?? ($p['images'] ? PRODUCT_IMAGE_DIR . $p['images'][0]['file_large'] : 'assets/sample.svg');
$mainW = $p['images'] ? (int)$p['images'][0]['width'] : 480;
$mainH = $p['images'] ? (int)$p['images'][0]['height'] : 300;
?>
<nav class="breadcrumb" aria-label="導覽路徑"><a href="index.php">首頁</a> ›
  <?php if ($p['category_id'] !== null): ?><a href="products.php?category=<?= (int)$p['category_id'] ?>"><?= h($p['category_name']) ?></a> ›<?php endif; ?>
  <span><?= h($p['name']) ?></span></nav>

<?php if ($notice): ?>
  <div class="alert alert-success" role="status">
    已將「<?= h($notice['name']) ?>」加入購物車 <?= (int)$notice['added'] ?> 件，購物車內此規格累計 <?= (int)$notice['total'] ?> 件。
    <a href="cart.php">瀏覽購物車 →</a>
  </div>
<?php endif; ?>
<?php if (!$active): ?><div class="alert alert-warn">此商品已下架，以下為商品資訊，暫停販售。</div><?php endif; ?>

<article class="product-detail">
  <div class="gallery">
    <img id="main-image" src="<?= h($mainImg) ?>" alt="<?= h($p['images'][0]['alt'] ?? $p['name']) ?>" width="<?= $mainW ?>" height="<?= $mainH ?>">
    <?php if (count($p['images']) > 1): ?>
      <ul class="thumbs">
      <?php foreach ($p['images'] as $im): ?>
        <li><button type="button" class="thumb-btn" data-large="<?= h(PRODUCT_IMAGE_DIR . $im['file_large']) ?>" aria-label="顯示圖片：<?= h($im['alt'] ?: $p['name']) ?>">
          <img src="<?= h(PRODUCT_IMAGE_DIR . $im['file_thumb']) ?>" alt="" width="<?= (int)$im['thumb_width'] ?>" height="<?= (int)$im['thumb_height'] ?>" loading="lazy"></button></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div>
    <h1><?= h($p['name']) ?></h1>
    <p class="muted">SKU：<span id="v-sku"><?= h($sel['sku']) ?></span></p>
    <p class="price big" id="v-price"><?= money($sel['effective_price']) ?></p>
    <dl class="meta">
      <dt>分類</dt><dd><?= $p['category_name'] !== null ? '<a href="products.php?category=' . (int)$p['category_id'] . '">' . h($p['category_name']) . '</a>' : '未分類' ?></dd>
      <dt>標籤</dt><dd><?php if (!$p['tags']): ?>無<?php endif; ?>
        <?php foreach ($p['tags'] as $t): ?><a class="chip" href="products.php?tag=<?= (int)$t['id'] ?>"><?= h($t['name']) ?></a> <?php endforeach; ?></dd>
      <dt>庫存</dt><dd id="v-stock"><?= $cur['stock'] > 0 ? $cur['stock'] . ' 件' : '<span class="badge badge-warn">缺貨</span>' ?></dd>
      <?php if ($enable_launch && (int)$p['allow_cvs'] !== 1): ?><dt>配送</dt><dd>此商品不提供超商取貨</dd><?php endif; ?>
    </dl>
    <div class="description"><?= nl2br(h((string)$p['description'])) ?></div>

    <?php if ($enable_cart): ?>
      <div class="cart-box">
        <?php if ($member === null): ?>
          <form method="get" action="login.php">
            <input type="hidden" name="return_to" value="product.php?id=<?= (int)$p['id'] ?>">
            <button class="btn btn-primary" type="submit" <?= !$active ? 'disabled' : '' ?>>加入購物車</button>
          </form>
          <p class="muted small"><?= !$active ? h($reason) : '加入購物車前請先登入；新會員註冊並完成 Email 驗證後，登入會回到此頁。' ?></p>
        <?php else: ?>
          <form method="post" action="cart_action.php" class="add-form" id="add-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <?php if ($hasOptions): ?>
              <label>規格（<?= h(implode(' / ', array_column($p['options'], 'name'))) ?>）
                <select name="variant_id" id="variant-select" required>
                  <?php foreach ($variants as $v): $d = $vdata[(int)$v['id']]; ?>
                    <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === (int)$sel['id'] ? 'selected' : '' ?> <?= $d['stock'] <= 0 ? 'disabled' : '' ?>>
                      <?= h($v['variant_text']) ?>　<?= h($d['price']) ?><?= $d['stock'] <= 0 ? '（缺貨）' : '' ?></option>
                  <?php endforeach; ?>
                </select></label>
            <?php else: ?>
              <input type="hidden" name="variant_id" value="<?= (int)$sel['id'] ?>">
            <?php endif; ?>
            <label>數量
              <input type="number" name="quantity" id="v-qty" value="1" min="1" max="<?= max(1, $cur['available']) ?>" step="1" required <?= $reason ? 'disabled' : '' ?>>
            </label>
            <button class="btn btn-primary" type="submit" id="v-add" <?= $reason ? 'disabled' : '' ?>>加入購物車</button>
          </form>
          <p class="muted small" id="v-note"><?= $reason ? h($reason) : '可再加入 ' . $cur['available'] . ' 件（目前庫存 ' . $cur['stock'] . ' 件，購物車內已有 ' . $cur['in_cart'] . ' 件）。' ?></p>
          <script type="application/json" id="variant-data"><?= json_encode(['active' => $active, 'variants' => $vdata], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</article>
<?php if ($hadMarker): ?>
<script>(function () { try { history.replaceState(null, '', 'product.php?id=<?= (int)$p['id'] ?>'); } catch (e) {} })();</script>
<?php endif; ?>
<?php
page_footer();
