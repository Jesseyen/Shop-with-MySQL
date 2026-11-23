<?php
/**
 * 商店與 SEO 設定（系統管理者，第 26、27 節）：結構化資料、OG、Twitter Card、robots.txt 需要的資訊
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/images.php';
if (!$enable_launch) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

$text = ['store_name' => ['商店名稱', 100], 'legal_name' => ['法定名稱', 100], 'tax_id' => ['統一編號（選填，填寫後也顯示在頁尾）', 8],
         'cs_email' => ['客服 Email', 191], 'cs_phone' => ['客服電話', 30], 'store_address' => ['地址（選填）', 150],
         'twitter_handle' => ['X（Twitter）帳號', 30], 'default_brand' => ['預設品牌名稱', 100],
         'home_title' => ['首頁標題', 100], 'home_description' => ['首頁說明', 200]];
$days = ['handling_min_days', 'handling_max_days', 'transit_home_min_days', 'transit_home_max_days', 'transit_cvs_min_days', 'transit_cvs_max_days'];

if (is_post()) {
    require_csrf();
    $newFiles = [];
    try {
        $kv = [];
        foreach ($text as $k => [$label, $max]) {
            $v = post_str($k);
            if (mb_strlen($v) > $max) { throw new UserError($label . '最多 ' . $max . ' 字。'); }
            $kv[$k] = $v;
        }
        if ($kv['tax_id'] !== '' && !preg_match('/^[0-9]{8}$/', $kv['tax_id'])) { throw new UserError('統一編號須為 8 位數字。'); }
        if ($kv['cs_email'] !== '' && !valid_email($kv['cs_email'])) { throw new UserError('客服 Email 格式不正確。'); }
        $same = trim(str_replace("\r\n", "\n", (string)($_POST['same_as'] ?? '')));
        foreach (array_filter(array_map('trim', explode("\n", $same))) as $u) {
            if (!preg_match('~^https?://[^\s<>"]+$~', $u)) { throw new UserError('社群連結須為 http(s):// 開頭，每行一個。'); }
        }
        $kv['same_as'] = $same;
        $robots = trim(str_replace("\r\n", "\n", (string)($_POST['robots_extra'] ?? '')));
        if (mb_strlen($robots) > 2000) { throw new UserError('robots.txt 自訂規則最多 2,000 字。'); }
        $kv['robots_extra'] = $robots;
        foreach ($days as $k) {
            $v = to_nonneg_int(post_str($k), 60);
            if ($v === null) { throw new UserError('天數須為 0～60 的整數。'); }
            $kv[$k] = (string)$v;
        }
        foreach (['handling', 'transit_home', 'transit_cvs'] as $p) {
            if ((int)$kv[$p . '_min_days'] > (int)$kv[$p . '_max_days']) { throw new UserError('最少天數不能大於最多天數。'); }
        }
        $kv['return_shipping_payer'] = post_str('return_shipping_payer') === 'store' ? 'store' : 'customer';
        foreach (['logo_file' => 'logo', 'default_og_file' => 'og'] as $key => $field) {
            if (!empty($_FILES[$field]['name'])) { $kv[$key] = $newFiles[] = image_store_site($_FILES[$field]); }
            elseif (post_str('remove_' . $field) === '1') { $kv[$key] = ''; }
        }
        $old = [setting('logo_file'), setting('default_og_file')];
        admin_transaction(function (PDO $pdo, array $a) use ($kv) { settings_save($pdo, $kv, $a['id']); });
        foreach (['logo_file' => 0, 'default_og_file' => 1] as $key => $i) {
            if (array_key_exists($key, $kv) && $old[$i] !== '' && $old[$i] !== $kv[$key]) { image_delete_files([$old[$i]], SITE_IMAGE_DIR); }
        }
        flash('success', '商店與 SEO 設定已儲存。');
    } catch (UserError $e) {
        image_delete_files($newFiles, SITE_IMAGE_DIR);
        flash('error', $e->getMessage());
    }
    redirect('admin_store.php');
}
admin_header('商店與 SEO 設定');
?>
<h1>商店與 SEO 設定</h1>
<p class="muted">首頁、商品列表與商品頁的 JSON-LD（OnlineStore、WebSite、ItemList、Product／ProductGroup、Offer、運送與退貨政策）、Open Graph 與 Twitter Card 都使用這裡的資訊。
  上線後請用 Google Rich Results Test 與 Schema Markup Validator 檢查。</p>
<form method="post" enctype="multipart/form-data" class="card stack"><?= csrf_field() ?>
  <h2>商店資訊</h2>
  <div class="grid-2"><?php foreach ($text as $k => [$label, $max]): ?>
    <label><?= h($label) ?> <input type="text" name="<?= h($k) ?>" value="<?= h(setting($k)) ?>" maxlength="<?= $max ?>" <?= in_array($k, ['home_title', 'home_description'], true) ? 'data-count' : '' ?>></label>
  <?php endforeach; ?></div>
  <label>社群連結（sameAs，每行一個網址） <textarea name="same_as" rows="3"><?= h(setting('same_as')) ?></textarea></label>
  <div class="grid-2">
    <div><label>Logo <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
      <?php if (setting('logo_file')): ?><img src="<?= h(SITE_IMAGE_DIR . setting('logo_file')) ?>" alt="目前的 Logo" class="preview"><label class="check"><input type="checkbox" name="remove_logo" value="1"> 移除</label><?php endif; ?></div>
    <div><label>預設 OG 分享圖（建議 1200×630） <input type="file" name="og" accept="image/jpeg,image/png,image/webp"></label>
      <?php if (setting('default_og_file')): ?><img src="<?= h(SITE_IMAGE_DIR . setting('default_og_file')) ?>" alt="目前的預設 OG 圖" class="preview"><label class="check"><input type="checkbox" name="remove_og" value="1"> 移除</label><?php endif; ?></div>
  </div>
  <h2>運送與退貨（結構化資料）</h2>
  <div class="table-wrap"><table><thead><tr><th></th><th>最少天數</th><th>最多天數</th></tr></thead><tbody>
    <?php foreach (['handling' => '處理天數', 'transit_home' => '宅配運送天數', 'transit_cvs' => '超商運送天數'] as $p => $label): ?>
      <tr><td><?= h($label) ?></td><td><input type="number" name="<?= $p ?>_min_days" value="<?= setting_int($p . '_min_days') ?>" min="0" max="60" class="short"></td>
        <td><input type="number" name="<?= $p ?>_max_days" value="<?= setting_int($p . '_max_days') ?>" min="0" max="60" class="short"></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <fieldset><legend>退貨運費負擔</legend>
    <label class="check"><input type="radio" name="return_shipping_payer" value="customer" <?= setting('return_shipping_payer') !== 'store' ? 'checked' : '' ?>> 顧客負擔</label>
    <label class="check"><input type="radio" name="return_shipping_payer" value="store" <?= setting('return_shipping_payer') === 'store' ? 'checked' : '' ?>> 商店負擔（免費退貨）</label></fieldset>
  <h2>robots.txt</h2>
  <p class="muted small">系統自動擋下後台、購物車、結帳與會員頁，並列出 Sitemap 網址。可另加規則（每行一條，只接受 User-agent、Disallow、Allow、Crawl-delay）。
    <a href="robots.txt" target="_blank">檢視 robots.txt</a>　<a href="sitemap.xml" target="_blank">檢視 sitemap.xml</a></p>
  <textarea name="robots_extra" rows="3" class="code"><?= h(setting('robots_extra')) ?></textarea>
  <button class="btn btn-primary">儲存</button>
</form>
<?php
page_footer();
