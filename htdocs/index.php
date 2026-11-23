<?php
/**
 * 首頁：搜尋、分類入口、最新上架；第六階段輸出 OnlineStore、WebSite、OG 與 view_item_list。
 */
require_once __DIR__ . '/app.php';

[$latest] = product_search([], 1, 8, true);
$cats = categories_all();
$opts = ['canonical' => '', 'page_type' => 'home'];
if ($enable_launch) {
    $t = trim(setting('home_title'));
    $opts['seo_title'] = $t !== '' ? $t : seo_store_name();
    $opts['description'] = trim(setting('home_description'));
    $opts['og'] = ['type' => 'website', 'title' => $opts['seo_title'], 'description' => $opts['description']];
    $opts['jsonld'] = [seo_organization(), seo_website()];
    if ($latest) {
        $items = [];
        foreach ($latest as $i => $p) {
            $items[] = dl_item(['sku' => $p['sku'], 'name' => $p['name'], 'brand' => $p['brand'], 'category_name' => $p['category_name'],
                                'price' => $p['price_min'] ?? $p['price']], 1, $i + 1);
        }
        $opts['events'] = [dl_event('view_item_list', ['item_list_id' => 'home_latest', 'item_list_name' => '最新上架', 'items' => $items])];
    }
}
page_header('首頁', $opts);
?>
<section class="hero">
  <h1><?= h($enable_launch ? seo_store_name() : $site_name) ?></h1>
  <p>精選台灣好物，線上瀏覽商品、加入購物車並下單。</p>
  <form class="search-inline" method="get" action="products.php">
    <input type="search" name="q" maxlength="100" placeholder="搜尋商品名稱或 SKU" aria-label="搜尋商品">
    <button class="btn btn-primary" type="submit">搜尋</button>
  </form>
</section>
<?php if ($cats): ?>
<section><h2>商品分類</h2><ul class="chips">
  <?php foreach ($cats as $c): ?><li><a href="products.php?category=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></li><?php endforeach; ?>
</ul></section>
<?php endif; ?>
<section>
  <h2>最新上架</h2>
  <?php if (!$latest): ?>
    <p class="empty">目前沒有上架商品。</p>
  <?php else: ?>
    <div class="product-grid"><?php foreach ($latest as $i => $p) { echo product_card($p, $i >= 4); } ?></div>
    <p><a class="btn" href="products.php">瀏覽全部商品 →</a></p>
  <?php endif; ?>
</section>
<?php
page_footer();
