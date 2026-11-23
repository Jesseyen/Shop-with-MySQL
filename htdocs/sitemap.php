<?php
/** sitemap.xml（第 26 節）：首頁、有上架商品的分類頁、上架商品（含 lastmod 與圖片）、公開文件頁；不列 noindex 頁 */
require_once __DIR__ . '/app.php';
header('Content-Type: application/xml; charset=utf-8');
$x = function (string $s) { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
$url = function (string $loc, ?string $mod = null, array $images = []) use ($x) {
    echo '<url><loc>' . $x($loc) . '</loc>' . ($mod ? '<lastmod>' . $x(date('c', strtotime($mod))) . '</lastmod>' : '');
    foreach ($images as $i) { echo '<image:image><image:loc>' . $x($i) . '</image:loc></image:image>'; }
    echo "</url>\n";
};
$url(abs_url(''));
foreach ($pdo->query('SELECT c.id, GREATEST(c.updated_at, MAX(p.updated_at)) m FROM categories c JOIN products p ON p.category_id = c.id AND p.is_active = 1 GROUP BY c.id, c.updated_at ORDER BY c.id') as $c) {
    $url(abs_url('products.php?category=' . (int)$c['id']), $c['m']);
}
$imgs = [];
foreach ($pdo->query('SELECT i.product_id, i.file_large FROM product_images i JOIN products p ON p.id = i.product_id AND p.is_active = 1 ORDER BY i.product_id, i.sort_order, i.id') as $i) {
    $imgs[(int)$i['product_id']][] = abs_url(PRODUCT_IMAGE_DIR . $i['file_large']);
}
foreach ($pdo->query('SELECT id, updated_at FROM products WHERE is_active = 1 ORDER BY id') as $p) {
    $url(abs_url('product.php?id=' . (int)$p['id']), $p['updated_at'], $imgs[(int)$p['id']] ?? []);
}
if ($enable_ops) {
    foreach (['privacy' => 'privacy.php', 'returns' => 'returns.php', 'shopping_guide' => 'shopping-guide.php'] as $k => $f) {
        $url(abs_url($f), site_content($k)['updated_at']);
    }
    $url(abs_url('contact.php'));
}
echo '</urlset>';
