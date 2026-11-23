<?php
/**
 * 分類說明與 SEO（商品管理模組，第 26 節）：分類說明（顯示在分類頁上方）、SEO 標題、meta description、OG 圖片（上傳）
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/images.php';
if (!$enable_launch) { render_not_found(); }
require_perm('product', is_post() ? 'edit' : 'view');
$id = to_pos_int($_GET['id'] ?? ($_POST['id'] ?? ''));
$c = $id !== null ? category_find($id) : null;
if (!$c) { render_not_found('分類'); }

if (is_post()) {
    require_csrf();
    $new = null;
    try {
        $d = ['description' => post_str('description'), 'seo_title' => post_str('seo_title'), 'meta_description' => post_str('meta_description')];
        if (mb_strlen($d['description']) > 5000) { throw new UserError('分類說明最多 5,000 字。'); }
        if (mb_strlen($d['seo_title']) > 100) { throw new UserError('SEO 標題最多 100 字。'); }
        if (mb_strlen($d['meta_description']) > 200) { throw new UserError('meta description 最多 200 字。'); }
        $og = $c['og_image'];
        if (!empty($_FILES['og']['name'])) { $og = $new = image_store_site($_FILES['og']); }
        elseif (post_str('remove_og') === '1') { $og = null; }
        admin_transaction(function (PDO $pdo, array $a) use ($id, $d, $og, $c) {
            $st = $pdo->prepare('SELECT * FROM categories WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch();
            assert_row_version($row, $_POST['row_version'] ?? '');
            $pdo->prepare('UPDATE categories SET description = ?, seo_title = ?, meta_description = ?, og_image = ?, row_version = row_version + 1 WHERE id = ?')
                ->execute([$d['description'] ?: null, $d['seo_title'] ?: null, $d['meta_description'] ?: null, $og, $id]);
            audit_log($pdo, $a['id'], 'category', $id, 'seo', $row, $d + ['og_image' => $og]);
        }, 'product');
        if ($c['og_image'] && $c['og_image'] !== $og) { image_delete_files([$c['og_image']], SITE_IMAGE_DIR); }
        flash('success', '分類說明與 SEO 已儲存。');
    } catch (UserError $e) {
        if ($new) { image_delete_files([$new], SITE_IMAGE_DIR); }
        flash('error', $e->getMessage());
    }
    redirect('admin_category_edit.php?id=' . $id);
}
$canEdit = admin_can('product', 'edit');
admin_header('分類 ' . $c['name']);
?>
<p><a href="admin_categories.php">← 分類管理</a>　<a href="products.php?category=<?= (int)$c['id'] ?>" target="_blank">前台檢視</a></p>
<h1>分類：<?= h($c['name']) ?></h1>
<form method="post" enctype="multipart/form-data" class="card stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="row_version" value="<?= (int)$c['row_version'] ?>">
  <fieldset class="bare" <?= $canEdit ? '' : 'disabled' ?>>
  <label>分類說明（純文字，顯示在分類頁上方） <textarea name="description" rows="5" maxlength="5000"><?= h($c['description'] ?? '') ?></textarea></label>
  <label>SEO 標題（空白時為「分類名稱｜站名」） <input type="text" name="seo_title" value="<?= h($c['seo_title'] ?? '') ?>" maxlength="100" data-count></label>
  <label>meta description（空白時取分類說明前 120 字） <textarea name="meta_description" rows="2" maxlength="200" data-count><?= h($c['meta_description'] ?? '') ?></textarea></label>
  <label>OG 分享圖（空白時使用站台預設 OG 圖） <input type="file" name="og" accept="image/jpeg,image/png,image/webp"></label>
  <?php if ($c['og_image']): ?><img src="<?= h(SITE_IMAGE_DIR . $c['og_image']) ?>" alt="目前的 OG 圖" class="preview"><label class="check"><input type="checkbox" name="remove_og" value="1"> 移除</label><?php endif; ?>
  <div class="serp-preview"><div class="serp-title"><?= h($c['seo_title'] ?: $c['name'] . '｜' . seo_store_name()) ?></div><div class="serp-url"><?= h(abs_url('products.php?category=' . (int)$c['id'])) ?></div>
    <div class="serp-desc"><?= h($c['meta_description'] ?: seo_excerpt($c['description'])) ?></div></div>
  <?php if ($canEdit): ?><button class="btn btn-primary">儲存</button><?php endif; ?></fieldset>
</form>
<?php
page_footer();
