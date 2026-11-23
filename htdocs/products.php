<?php
/**
 * 商品列表：關鍵字、分類、標籤、分頁。
 * 第六階段：分類 SEO 標題、說明與 OG 圖；canonical 指回分類（第 2 頁起帶 page）；站內搜尋 noindex；
 * ItemList 與 BreadcrumbList；view_item_list。
 */
require_once __DIR__ . '/app.php';

$q = mb_substr(get_str('q'), 0, 100);
$category = to_pos_int($_GET['category'] ?? '');
$tag = to_pos_int($_GET['tag'] ?? '');
$page = current_page();
$perPage = 12;

$cat = $category !== null ? category_find($category) : null;
if ($category !== null && !$cat) { render_not_found('分類'); }
[$rows, $total] = product_search(['q' => $q, 'category' => $category, 'tag' => $tag], $page, $perPage, true);
$cats = categories_all();
$tags = tags_all();
if (function_exists('analytics_touch')) { analytics_touch(); }

$title = $cat ? $cat['name'] : '商品瀏覽';
$canonical = 'products.php' . ($cat ? '?category=' . (int)$cat['id'] . ($page > 1 && $tag === null && $q === '' ? '&page=' . $page : '') : ($page > 1 ? '?page=' . $page : ''));
$opts = ['canonical' => $canonical, 'page_type' => 'product_list', 'noindex' => $q !== ''];
if ($enable_launch) {
    $seoTitle = $cat && trim((string)$cat['seo_title']) !== '' ? $cat['seo_title'] : $title . '｜' . seo_store_name();
    $desc = $cat ? (trim((string)$cat['meta_description']) !== '' ? $cat['meta_description'] : seo_excerpt($cat['description'])) : '';
    $img = $cat && $cat['og_image'] ? site_image_url($cat['og_image']) : '';
    $opts += ['seo_title' => $seoTitle, 'description' => $desc,
              'og' => ['type' => 'website', 'title' => $seoTitle, 'description' => $desc, 'image' => $img]];
    $crumbs = [['首頁', '']];
    if ($cat) { $crumbs[] = [$cat['name'], 'products.php?category=' . (int)$cat['id']]; } else { $crumbs[] = ['商品瀏覽', 'products.php']; }
    $opts['jsonld'] = [seo_item_list($rows, ($page - 1) * $perPage + 1), seo_breadcrumb($crumbs)];
    if ($rows) {
        $items = [];
        foreach ($rows as $i => $p) {
            $items[] = dl_item(['sku' => $p['sku'], 'name' => $p['name'], 'brand' => $p['brand'], 'category_name' => $p['category_name'],
                                'price' => $p['price_min'] ?? $p['price']], 1, ($page - 1) * $perPage + $i + 1);
        }
        $listId = $cat ? 'category_' . (int)$cat['id'] : ($q !== '' ? 'search' : 'all_products');
        $opts['events'] = [dl_event('view_item_list', ['item_list_id' => $listId, 'item_list_name' => $q !== '' ? '搜尋結果' : $title, 'items' => $items])];
    }
}
page_header($title, $opts);
?>
<h1><?= h($title) ?></h1>
<?php if ($cat && trim((string)$cat['description']) !== ''): ?><div class="category-intro"><?= plain_to_html($cat['description']) ?></div><?php endif; ?>
<form class="filters" method="get" action="products.php">
  <label>關鍵字 <input type="search" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="名稱或 SKU"></label>
  <label>分類
    <select name="category"><option value="">全部分類</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $category === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label>標籤
    <select name="tag"><option value="">全部標籤</option>
      <?php foreach ($tags as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tag === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
    </select></label>
  <button class="btn btn-primary" type="submit">搜尋</button>
  <a class="btn" href="products.php">清除條件</a>
</form>
<?php if (!$rows): ?>
  <p class="empty">找不到符合條件的商品，請調整關鍵字或篩選條件。</p>
<?php else: ?>
  <div class="product-grid"><?php foreach ($rows as $p) { echo product_card($p); } ?></div>
  <?= render_pager($total, $page, $perPage, array_filter(['q' => $q, 'category' => $category, 'tag' => $tag])) ?>
<?php endif; ?>
<?php
page_footer();
