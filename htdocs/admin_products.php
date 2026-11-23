<?php
/**
 * 後台商品管理（商品管理模組）
 * - 列表：名稱、SKU 或規格 SKU 搜尋，分類篩選
 * - 新增／修改：名稱、說明、SKU、分類、標籤、價格、上下架、運費規則、可否超商取貨、品牌、SEO 欄位
 * - 第六階段：規格（最多兩種、組合最多 100 個）、每個規格的 SKU／價格／庫存／啟用／圖片／GTIN；商品圖片（最多 10 張）
 * 商品不提供刪除，以下架處理。
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/images.php';
$me = require_perm('product', is_post() ? 'edit' : 'view');
$canEdit = admin_can('product', 'edit');

/** 讀取並驗證商品基本欄位 */
function product_form_input(): array
{
    $d = [
        'name' => post_str('name'), 'sku' => post_str('sku'), 'description' => post_str('description'),
        'category_id' => to_pos_int($_POST['category_id'] ?? ''), 'price' => parse_price(post_str('price')),
        'is_active' => post_str('is_active') === '1' ? 1 : 0,
        'shipping_rule_id' => to_pos_int($_POST['shipping_rule_id'] ?? ''),
        'allow_cvs' => post_str('allow_cvs') === '1' ? 1 : 0, 'brand' => post_str('brand'),
        'seo_title' => post_str('seo_title'), 'meta_description' => post_str('meta_description'),
        'og_image_id' => to_pos_int($_POST['og_image_id'] ?? ''),
    ];
    if (!len_between($d['name'], 1, 150)) { throw new UserError('商品名稱須為 1～150 字。'); }
    if (!preg_match('/^[A-Za-z0-9\-_.]{1,50}$/', $d['sku'])) { throw new UserError('SKU 只能使用英數字、-、_、.，1～50 字。'); }
    if (mb_strlen($d['description']) > 20000) { throw new UserError('說明最多 20,000 字。'); }
    if ($d['price'] === null) { throw new UserError('價格格式不正確（最多 8 位整數與 2 位小數）。'); }
    if (mb_strlen($d['brand']) > 100) { throw new UserError('品牌最多 100 字。'); }
    if (mb_strlen($d['seo_title']) > 100) { throw new UserError('SEO 標題最多 100 字。'); }
    if (mb_strlen($d['meta_description']) > 200) { throw new UserError('meta description 最多 200 字。'); }
    foreach (['brand', 'seo_title', 'meta_description', 'description'] as $k) { if ($d[$k] === '') { $d[$k] = null; } }
    return $d;
}

function product_save_tags(PDO $pdo, int $pid): void
{
    $pdo->prepare('DELETE FROM product_tags WHERE product_id = ?')->execute([$pid]);
    $ins = $pdo->prepare('INSERT IGNORE INTO product_tags (product_id, tag_id) SELECT ?, id FROM tags WHERE id = ?');
    foreach (post_arr('tags') as $t) { if (($t = to_pos_int($t)) !== null) { $ins->execute([$pid, $t]); } }
}

function product_lock(PDO $pdo, int $pid): array
{
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) { throw new UserError('找不到此商品。'); }
    return $p;
}

/** 規格值是否被購物車或訂單使用 */
function variant_in_use(PDO $pdo, int $vid): bool
{
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM cart_items WHERE variant_id = ?) + (SELECT COUNT(*) FROM order_items WHERE variant_id = ?)');
    $st->execute([$vid, $vid]);
    return (int)$st->fetchColumn() > 0;
}

/** 依目前規格值產生缺少的組合；有組合時停用預設規格 */
function variants_regenerate(PDO $pdo, array $p): int
{
    $opts = product_options((int)$p['id']);
    $v1 = $opts[0]['values'] ?? [];
    $v2 = $opts[1]['values'] ?? [];
    if (!$v1) { return 0; }
    $combos = [];
    foreach ($v1 as $a) {
        if ($v2) { foreach ($v2 as $b) { $combos[] = [(int)$a['id'], (int)$b['id']]; } }
        else { $combos[] = [(int)$a['id'], null]; }
    }
    if (count($combos) > 100) { throw new UserError('規格組合最多 100 個（目前 ' . count($combos) . ' 個）。'); }
    $exists = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? AND variant_key = ?');
    $ins = $pdo->prepare('INSERT INTO product_variants (product_id, variant_key, sku, option_value1_id, option_value2_id, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    $skuUsed = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE sku = ?');
    $n = 0;
    foreach ($combos as [$a, $b]) {
        $key = $a . '-' . ($b ?? 0);
        $exists->execute([$p['id'], $key]);
        if ($exists->fetchColumn()) { continue; }
        $i = 1;
        do {
            $sku = mb_substr($p['sku'], 0, 44) . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $skuUsed->execute([$sku]);
            $i++;
        } while ((int)$skuUsed->fetchColumn() > 0);
        $ins->execute([$p['id'], $key, $sku, $a, $b]);
        $pdo->prepare('INSERT INTO variant_inventory (variant_id, quantity) VALUES (?, 0)')->execute([$pdo->lastInsertId()]);
        $n++;
    }
    $pdo->prepare("UPDATE product_variants SET is_active = 0, row_version = row_version + 1 WHERE product_id = ? AND variant_key = 'default'")->execute([$p['id']]);
    return $n;
}

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    $pid = to_pos_int($_POST['id'] ?? '');
    $back = 'admin_products.php' . ($pid ? '?id=' . $pid : '');
    $newFiles = [];
    try {
        $msg = admin_transaction(function (PDO $pdo, array $a) use ($action, $pid, &$back, &$newFiles) {
            global $enable_launch;
            if ($action === 'create') {
                $d = product_form_input();
                $stock = to_nonneg_int(post_str('stock'), 1000000);
                if ($stock === null) { throw new UserError('初始庫存須為 0 以上的整數。'); }
                $pdo->prepare('INSERT INTO products (category_id, shipping_rule_id, sku, name, description, price, is_active, allow_cvs, brand, seo_title, meta_description)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$d['category_id'], $d['shipping_rule_id'], $d['sku'], $d['name'], $d['description'], $d['price'], $d['is_active'],
                               $d['allow_cvs'], $d['brand'], $d['seo_title'], $d['meta_description']]);
                $newId = (int)$pdo->lastInsertId();
                product_create_default_variant($pdo, $newId, $d['sku'], $stock);
                product_save_tags($pdo, $newId);
                audit_log($pdo, $a['id'], 'product', $newId, 'create', null, $d + ['stock' => $stock]);
                $back = 'admin_products.php?id=' . $newId;
                return '商品已新增。';
            }
            if ($pid === null) { throw new UserError('商品編號不正確。'); }
            $p = product_lock($pdo, $pid);
            switch ($action) {
                case 'update':
                    assert_row_version($p, $_POST['row_version'] ?? '');
                    $d = product_form_input();
                    if ($d['og_image_id'] !== null) {
                        $c = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE id = ? AND product_id = ?');
                        $c->execute([$d['og_image_id'], $pid]);
                        if (!(int)$c->fetchColumn()) { $d['og_image_id'] = null; }
                    }
                    $pdo->prepare('UPDATE products SET category_id = ?, shipping_rule_id = ?, sku = ?, name = ?, description = ?, price = ?, is_active = ?,
                                          allow_cvs = ?, brand = ?, seo_title = ?, meta_description = ?, og_image_id = ?, row_version = row_version + 1 WHERE id = ?')
                        ->execute([$d['category_id'], $d['shipping_rule_id'], $d['sku'], $d['name'], $d['description'], $d['price'], $d['is_active'],
                                   $d['allow_cvs'], $d['brand'], $d['seo_title'], $d['meta_description'], $d['og_image_id'], $pid]);
                    $pdo->prepare("UPDATE product_variants SET sku = ? WHERE product_id = ? AND variant_key = 'default'")->execute([$d['sku'], $pid]);
                    product_save_tags($pdo, $pid);
                    audit_log($pdo, $a['id'], 'product', $pid, 'update', $p, $d);
                    return '商品已更新。';

                case 'options':
                    if (!$enable_launch) { throw new UserError('規格功能未啟用。'); }
                    $names = post_arr('opt_name');
                    $vals = post_arr('opt_values');
                    $before = product_options($pid);
                    for ($pos = 1; $pos <= 2; $pos++) {
                        $name = trim((string)($names[$pos] ?? ''));
                        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,，、\n]/u', (string)($vals[$pos] ?? ''))), 'strlen')));
                        $st = $pdo->prepare('SELECT id FROM product_options WHERE product_id = ? AND position = ?');
                        $st->execute([$pid, $pos]);
                        $oid = $st->fetchColumn();
                        if ($name === '' || !$list) {
                            if ($oid) {
                                $c = $pdo->prepare('SELECT v.id FROM product_variants v JOIN product_option_values ov ON ov.id = v.option_value' . $pos . '_id WHERE ov.option_id = ?');
                                $c->execute([$oid]);
                                foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $vid) {
                                    if (variant_in_use($pdo, (int)$vid)) { throw new UserError('此規格已有購物車或訂單使用，不能移除，只能停用個別規格。'); }
                                    $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$vid]);
                                }
                                $pdo->prepare('DELETE FROM product_options WHERE id = ?')->execute([$oid]);
                            }
                            continue;
                        }
                        if ($pos === 2 && !($names[1] ?? '')) { throw new UserError('請先設定第一種規格。'); }
                        if (!len_between($name, 1, 20)) { throw new UserError('規格名稱須為 1～20 字。'); }
                        foreach ($list as $v) { if (!len_between($v, 1, 30)) { throw new UserError('規格值須為 1～30 字。'); } }
                        if ($oid) {
                            $pdo->prepare('UPDATE product_options SET name = ? WHERE id = ?')->execute([$name, $oid]);
                        } else {
                            $pdo->prepare('INSERT INTO product_options (product_id, position, name) VALUES (?, ?, ?)')->execute([$pid, $pos, $name]);
                            $oid = (int)$pdo->lastInsertId();
                        }
                        $st = $pdo->prepare('SELECT id, value FROM product_option_values WHERE option_id = ?');
                        $st->execute([$oid]);
                        $existing = $st->fetchAll(PDO::FETCH_KEY_PAIR);
                        foreach ($existing as $vid => $val) {
                            if (in_array($val, $list, true)) { continue; }
                            $c = $pdo->prepare('SELECT id FROM product_variants WHERE option_value' . $pos . '_id = ?');
                            $c->execute([$vid]);
                            foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $varId) {
                                if (variant_in_use($pdo, (int)$varId)) { throw new UserError('規格值「' . $val . '」已有購物車或訂單使用，不能刪除，請改為停用該規格。'); }
                                $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$varId]);
                            }
                            $pdo->prepare('DELETE FROM product_option_values WHERE id = ?')->execute([$vid]);
                        }
                        $ins = $pdo->prepare('INSERT INTO product_option_values (option_id, value, sort_order) VALUES (?, ?, ?)
                                              ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)');
                        foreach ($list as $i => $v) { $ins->execute([$oid, $v, $i]); }
                    }
                    // 第二種規格新增時，舊的單一規格組合（值-0）若未被使用則移除
                    if (count(product_options($pid)) === 2) {
                        $st = $pdo->prepare("SELECT id FROM product_variants WHERE product_id = ? AND variant_key LIKE '%-0'");
                        $st->execute([$pid]);
                        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) {
                            if (variant_in_use($pdo, (int)$vid)) {
                                $pdo->prepare('UPDATE product_variants SET is_active = 0 WHERE id = ?')->execute([$vid]);
                            } else {
                                $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$vid]);
                            }
                        }
                    }
                    $n = variants_regenerate($pdo, $p);
                    if (!product_options($pid)) {
                        $pdo->prepare("UPDATE product_variants SET is_active = 1 WHERE product_id = ? AND variant_key = 'default'")->execute([$pid]);
                    }
                    audit_log($pdo, $a['id'], 'product_options', $pid, 'update', ['options' => $before], ['options' => product_options($pid), 'created' => $n]);
                    return '規格已儲存' . ($n ? "，新增 {$n} 個組合（庫存 0，請設定 SKU、價格與庫存）" : '') . '。';

                case 'variants':
                    $rows = post_arr('v');
                    $variants = [];
                    foreach (product_variants($pid) as $v) { $variants[(int)$v['id']] = $v; }
                    $upd = $pdo->prepare('UPDATE product_variants SET sku = ?, price = ?, gtin = ?, image_id = ?, is_active = ?, row_version = row_version + 1 WHERE id = ? AND product_id = ?');
                    $changes = [];
                    foreach ($rows as $vid => $r) {
                        $vid = (int)$vid;
                        if (!isset($variants[$vid]) || !is_array($r)) { continue; }
                        $v = $variants[$vid];
                        $sku = trim((string)($r['sku'] ?? ''));
                        if (!preg_match('/^[A-Za-z0-9\-_.]{1,50}$/', $sku)) { throw new UserError('規格 SKU 只能使用英數字、-、_、.，1～50 字。'); }
                        $price = trim((string)($r['price'] ?? ''));
                        $pv = $price === '' ? null : parse_price($price);
                        if ($price !== '' && $pv === null) { throw new UserError('規格「' . $v['variant_text'] . '」價格格式不正確。'); }
                        $gtin = trim((string)($r['gtin'] ?? ''));
                        if ($gtin !== '' && !preg_match('/^[0-9]{8,14}$/', $gtin)) { throw new UserError('GTIN 須為 8～14 位數字。'); }
                        $img = to_pos_int($r['image_id'] ?? '');
                        $active = !empty($r['is_active']) ? 1 : 0;
                        if ($v['variant_key'] === 'default' && product_options($pid)) { $active = 0; }
                        $upd->execute([$v['variant_key'] === 'default' ? $p['sku'] : $sku, $pv, $gtin === '' ? null : $gtin, $img, $active, $vid, $pid]);
                        $stock = to_nonneg_int((string)($r['stock'] ?? ''), 1000000);
                        if ($stock === null) { throw new UserError('庫存須為 0 以上的整數。'); }
                        if ($stock !== (int)$v['stock']) {
                            lock_variant($pdo, $vid, true);
                            $pdo->prepare('INSERT INTO variant_inventory (variant_id, quantity) VALUES (?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), row_version = row_version + 1')
                                ->execute([$vid, $stock]);
                        }
                        $changes[$vid] = ['sku' => $sku, 'price' => $pv, 'stock' => $stock, 'active' => $active];
                    }
                    audit_log($pdo, $a['id'], 'product_variants', $pid, 'update', null, $changes);
                    return '規格已更新。';

                case 'image_upload':
                    $files = uploaded_files('images');
                    if (!$files) { throw new UserError('請選擇圖片。'); }
                    $st = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = ?');
                    $st->execute([$pid]);
                    [$count, $max] = $st->fetch(PDO::FETCH_NUM);
                    if ((int)$count + count($files) > IMAGE_PER_PRODUCT) { throw new UserError('每個商品最多 ' . IMAGE_PER_PRODUCT . ' 張圖片（目前 ' . $count . ' 張）。'); }
                    $ins = $pdo->prepare('INSERT INTO product_images (product_id, file_large, file_thumb, width, height, thumb_width, thumb_height, sort_order)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    foreach ($files as $f) {
                        $r = image_store_product($f);
                        $newFiles[] = $r['file_large'];
                        $newFiles[] = $r['file_thumb'];
                        $ins->execute([$pid, $r['file_large'], $r['file_thumb'], $r['width'], $r['height'], $r['thumb_width'], $r['thumb_height'], ++$max]);
                    }
                    audit_log($pdo, $a['id'], 'product_image', $pid, 'upload', null, ['count' => count($files)]);
                    return '已上傳 ' . count($files) . ' 張圖片。';

                case 'image_update':
                case 'image_up':
                case 'image_down':
                case 'image_main':
                case 'image_delete':
                    $iid = to_pos_int($_POST['image_id'] ?? '') ?? 0;
                    $st = $pdo->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ? FOR UPDATE');
                    $st->execute([$iid, $pid]);
                    $img = $st->fetch();
                    if (!$img) { throw new UserError('找不到此圖片。'); }
                    $list = product_images($pid);
                    $ids = array_map('intval', array_column($list, 'id'));
                    $idx = array_search($iid, $ids, true);
                    if ($action === 'image_update') {
                        $alt = post_str('alt');
                        if (mb_strlen($alt) > 150) { throw new UserError('替代文字最多 150 字。'); }
                        $vid = to_pos_int($_POST['variant_id'] ?? '');
                        $pdo->prepare('UPDATE product_images SET alt = ?, variant_id = ? WHERE id = ?')->execute([$alt === '' ? null : $alt, $vid, $iid]);
                        if ($vid) { $pdo->prepare('UPDATE product_variants SET image_id = ? WHERE id = ? AND product_id = ?')->execute([$iid, $vid, $pid]); }
                    } elseif ($action === 'image_delete') {
                        $pdo->prepare('UPDATE products SET og_image_id = NULL WHERE id = ? AND og_image_id = ?')->execute([$pid, $iid]);
                        $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$iid]);
                        $GLOBALS['_delete_after_commit'] = [$img['file_large'], $img['file_thumb']];
                    } else {
                        if ($action === 'image_main') { unset($ids[$idx]); array_unshift($ids, $iid); }
                        if ($action === 'image_up' && $idx > 0) { [$ids[$idx - 1], $ids[$idx]] = [$ids[$idx], $ids[$idx - 1]]; }
                        if ($action === 'image_down' && $idx < count($ids) - 1) { [$ids[$idx + 1], $ids[$idx]] = [$ids[$idx], $ids[$idx + 1]]; }
                        $up = $pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ?');
                        foreach (array_values($ids) as $i => $x) { $up->execute([$i, $x]); }
                    }
                    audit_log($pdo, $a['id'], 'product_image', $iid, $action, $img, null);
                    return '圖片已更新。';
            }
            throw new UserError('不支援的操作。');
        }, 'product');
        if (!empty($GLOBALS['_delete_after_commit'])) { image_delete_files($GLOBALS['_delete_after_commit']); }
        flash('success', $msg);
    } catch (UserError $e) {
        image_delete_files($newFiles);
        flash('error', $e->getMessage());
        if ($action === 'create') { $_SESSION['_product_keep'] = $_POST; $back = 'admin_products.php?new=1'; }
    } catch (PDOException $e) {
        image_delete_files($newFiles);
        if ($e->getCode() !== '23000') { throw $e; }
        flash('error', 'SKU 已被其他商品或規格使用，請改用其他 SKU。');
        if ($action === 'create') { $_SESSION['_product_keep'] = $_POST; $back = 'admin_products.php?new=1'; }
    }
    redirect($back);
}

$cats = categories_all();
$tags = tags_all();
$rules = $enable_orders ? shipping_rules_all() : [];

/** 商品基本欄位表單 */
function product_fields(array $p, array $cats, array $tags, array $rules, array $tagIds, bool $isNew): void
{
    global $enable_orders, $enable_launch;
    ?>
    <div class="grid-2">
      <label>商品名稱 <input type="text" name="name" value="<?= h($p['name'] ?? '') ?>" maxlength="150" required></label>
      <label>SKU（英數字、-、_、.） <input type="text" name="sku" value="<?= h($p['sku'] ?? '') ?>" maxlength="50" required pattern="[A-Za-z0-9\-_.]{1,50}"></label>
      <label>價格（規格未設價格時使用） <input type="text" name="price" value="<?= h($p['price'] ?? '') ?>" required inputmode="decimal"></label>
      <?php if ($isNew): ?><label>初始庫存 <input type="number" name="stock" value="<?= h($p['stock'] ?? '0') ?>" min="0" required></label><?php endif; ?>
      <label>分類 <select name="category_id"><option value="">未分類</option>
        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)($p['category_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></label>
      <?php if ($enable_orders): ?>
      <label>運費規則 <select name="shipping_rule_id"><option value="">不設定（免運）</option>
        <?php foreach ($rules as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (string)($p['shipping_rule_id'] ?? '') === (string)$r['id'] ? 'selected' : '' ?>><?= h($r['name'] . '（' . money($r['fee']) . ($r['free_threshold'] !== null ? '，滿 ' . money($r['free_threshold']) . ' 免運' : '') . '）' . ((int)$r['is_active'] ? '' : '［停用］')) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <?php if ($enable_launch): ?><label>品牌（空白時使用商店預設品牌） <input type="text" name="brand" value="<?= h($p['brand'] ?? '') ?>" maxlength="100"></label><?php endif; ?>
    </div>
    <label>說明 <textarea name="description" rows="5" maxlength="20000"><?= h($p['description'] ?? '') ?></textarea></label>
    <fieldset><legend>標籤</legend><?php foreach ($tags as $t): ?>
      <label class="check"><input type="checkbox" name="tags[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $tagIds, true) ? 'checked' : '' ?>> <?= h($t['name']) ?></label>
    <?php endforeach; ?><?php if (!$tags): ?><span class="muted">尚無標籤</span><?php endif; ?></fieldset>
    <label class="check"><input type="checkbox" name="is_active" value="1" <?= !isset($p['is_active']) || (int)$p['is_active'] ? 'checked' : '' ?>> 上架</label>
    <?php if ($enable_launch): ?><label class="check"><input type="checkbox" name="allow_cvs" value="1" <?= !isset($p['allow_cvs']) || (int)$p['allow_cvs'] ? 'checked' : '' ?>> 可超商取貨（大型商品請取消）</label><?php else: ?><input type="hidden" name="allow_cvs" value="1"><?php endif; ?>
    <?php if ($enable_launch): ?>
    <fieldset class="seo-fields"><legend>SEO 與社群分享</legend>
      <label>SEO 標題（最多 100 字，空白時為「商品名稱｜站名」） <input type="text" name="seo_title" value="<?= h($p['seo_title'] ?? '') ?>" maxlength="100" data-count></label>
      <label>meta description（最多 200 字，空白時取說明前 120 字） <textarea name="meta_description" rows="2" maxlength="200" data-count><?= h($p['meta_description'] ?? '') ?></textarea></label>
      <?php if (!$isNew): ?>
      <label>OG 分享圖片 <select name="og_image_id"><option value="">主圖（預設）</option>
        <?php foreach ($p['images'] as $i => $im): ?><option value="<?= (int)$im['id'] ?>" <?= (string)$p['og_image_id'] === (string)$im['id'] ? 'selected' : '' ?>>第 <?= $i + 1 ?> 張<?= $im['alt'] ? '：' . h($im['alt']) : '' ?></option><?php endforeach; ?></select></label>
      <div class="serp-preview" aria-label="搜尋結果預覽"><div class="serp-title"><?= h(($p['seo_title'] ?? '') ?: ($p['name'] . '｜' . seo_store_name())) ?></div>
        <div class="serp-url"><?= h(abs_url('product.php?id=' . (int)$p['id'])) ?></div>
        <div class="serp-desc"><?= h(($p['meta_description'] ?? '') ?: seo_excerpt($p['description'])) ?></div></div>
      <?php endif; ?>
    </fieldset>
    <?php endif;
}

// ---------------- 新增 ----------------
if (isset($_GET['new'])) {
    if (!$canEdit) { render_forbidden('需要「商品管理（編輯）」權限', true); }
    $keep = $_SESSION['_product_keep'] ?? [];
    unset($_SESSION['_product_keep']);
    admin_header('新增商品');
    echo '<p><a href="admin_products.php">← 商品列表</a></p><h1>新增商品</h1><form method="post" class="card stack">' . csrf_field() . '<input type="hidden" name="action" value="create">';
    product_fields($keep, $cats, $tags, $rules, array_map('intval', (array)($keep['tags'] ?? [])), true);
    echo '<button class="btn btn-primary">新增商品</button></form>';
    page_footer();
    exit;
}

// ---------------- 編輯 ----------------
$id = to_pos_int($_GET['id'] ?? '');
if ($id !== null) {
    $p = product_find($id);
    if (!$p) { render_not_found('商品'); }
    $tagIds = array_map(function ($t) { return (int)$t['id']; }, $p['tags']);
    admin_header('商品 ' . $p['name']);
    ?>
    <p><a href="admin_products.php">← 商品列表</a>　<a href="product.php?id=<?= $id ?>" target="_blank">前台檢視</a></p>
    <h1><?= h($p['name']) ?> <?= (int)$p['is_active'] ? badge('上架') : badge('下架', 'warn') ?></h1>
    <form method="post" class="card stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="row_version" value="<?= (int)$p['row_version'] ?>">
      <h2>基本資料</h2>
      <fieldset class="bare" <?= $canEdit ? '' : 'disabled' ?>><?php product_fields($p, $cats, $tags, $rules, $tagIds, false); ?></fieldset>
      <?php if ($canEdit): ?><button class="btn btn-primary">儲存基本資料</button><?php endif; ?>
    </form>

    <?php if ($enable_launch): ?>
    <section class="card">
      <h2>規格</h2>
      <p class="muted small">最多兩種規格（例如顏色、尺寸），以逗號分隔選項值；系統產生所有組合（最多 100 個）。已被購物車或訂單使用的規格不能刪除，只能停用。</p>
      <?php if ($canEdit): ?>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="options"><input type="hidden" name="id" value="<?= $id ?>">
        <?php for ($pos = 1; $pos <= 2; $pos++): $o = $p['options'][$pos - 1] ?? null; ?>
          <div class="grid-2"><label>規格 <?= $pos ?> 名稱 <input type="text" name="opt_name[<?= $pos ?>]" value="<?= h($o['name'] ?? '') ?>" maxlength="20" placeholder="<?= $pos === 1 ? '例如：顏色' : '例如：尺寸' ?>"></label>
            <label>選項值（逗號分隔） <input type="text" name="opt_values[<?= $pos ?>]" value="<?= h(implode(', ', array_column($o['values'] ?? [], 'value'))) ?>" placeholder="<?= $pos === 1 ? '紅, 藍' : 'S, M, L' ?>"></label></div>
        <?php endfor; ?>
        <button class="btn">儲存規格並產生組合</button></form>
      <?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="variants"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="table-wrap"><table><thead><tr><th>規格</th><th>SKU</th><th>價格（空白＝<?= money($p['price']) ?>）</th><th>庫存</th><th>圖片</th><th>GTIN</th><th>啟用</th></tr></thead><tbody>
        <?php foreach ($p['variants'] as $v): $isDef = $v['variant_key'] === 'default'; if ($isDef && $p['options'] && !(int)$v['is_active'] && !variant_in_use($pdo, (int)$v['id'])) { continue; } ?>
          <tr class="<?= (int)$v['is_active'] ? '' : 'row-off' ?>"><td><?= $isDef ? '預設規格' : h($v['variant_text']) ?></td>
            <td><input type="text" name="v[<?= (int)$v['id'] ?>][sku]" value="<?= h($v['sku']) ?>" maxlength="50" <?= $isDef ? 'readonly title="預設規格沿用商品 SKU"' : '' ?> aria-label="SKU"></td>
            <td><input type="text" name="v[<?= (int)$v['id'] ?>][price]" value="<?= h($v['price'] ?? '') ?>" class="short" aria-label="價格"></td>
            <td><input type="number" name="v[<?= (int)$v['id'] ?>][stock]" value="<?= (int)$v['stock'] ?>" min="0" class="short" aria-label="庫存"></td>
            <td><select name="v[<?= (int)$v['id'] ?>][image_id]" aria-label="圖片"><option value="">—</option><?php foreach ($p['images'] as $i => $im): ?><option value="<?= (int)$im['id'] ?>" <?= (string)$v['image_id'] === (string)$im['id'] ? 'selected' : '' ?>>第 <?= $i + 1 ?> 張</option><?php endforeach; ?></select></td>
            <td><input type="text" name="v[<?= (int)$v['id'] ?>][gtin]" value="<?= h($v['gtin'] ?? '') ?>" maxlength="14" class="short" aria-label="GTIN"></td>
            <td><input type="checkbox" name="v[<?= (int)$v['id'] ?>][is_active]" value="1" <?= (int)$v['is_active'] ? 'checked' : '' ?> <?= $isDef && $p['options'] ? 'disabled' : '' ?> aria-label="啟用"></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php if ($canEdit): ?><button class="btn btn-primary">儲存規格的 SKU、價格與庫存</button><?php endif; ?>
      </form>
    </section>

    <section class="card">
      <h2>商品圖片（<?= count($p['images']) ?>／<?= IMAGE_PER_PRODUCT ?>）</h2>
      <p class="muted small">JPG、PNG、WebP，單檔最大 5 MB；系統依內容判斷格式、重新編碼並移除 EXIF（含 GPS）。第一張為主圖。</p>
      <?php if ($canEdit && count($p['images']) < IMAGE_PER_PRODUCT): ?>
        <form method="post" enctype="multipart/form-data" class="row-form"><?= csrf_field() ?><input type="hidden" name="action" value="image_upload"><input type="hidden" name="id" value="<?= $id ?>">
          <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required><button class="btn">上傳</button></form>
      <?php endif; ?>
      <ul class="image-admin">
      <?php foreach ($p['images'] as $i => $im): $f = function ($act, $label, $cls = '') use ($id, $im) {
          return '<form method="post" class="inline"' . ($act === 'image_delete' ? ' onsubmit="return confirm(\'確定刪除圖片？\')"' : '') . '>' . csrf_field()
              . '<input type="hidden" name="action" value="' . $act . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="image_id" value="' . (int)$im['id'] . '">'
              . '<button class="btn btn-small ' . $cls . '">' . $label . '</button></form>'; }; ?>
        <li><img src="<?= h(PRODUCT_IMAGE_DIR . $im['file_thumb']) ?>" alt="" width="<?= (int)$im['thumb_width'] ?>" height="<?= (int)$im['thumb_height'] ?>" loading="lazy">
          <div><?= $i === 0 ? badge('主圖') : '' ?> <span class="muted small"><?= (int)$im['width'] ?>×<?= (int)$im['height'] ?></span>
          <?php if ($canEdit): ?>
            <form method="post" class="row-form"><?= csrf_field() ?><input type="hidden" name="action" value="image_update"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>">
              <input type="text" name="alt" value="<?= h($im['alt'] ?? '') ?>" maxlength="150" placeholder="替代文字（空白時用商品名稱）" aria-label="替代文字">
              <select name="variant_id" aria-label="對應規格"><option value="">不指定規格</option><?php foreach ($p['variants'] as $v): if ($v['variant_key'] === 'default') { continue; } ?><option value="<?= (int)$v['id'] ?>" <?= (string)$im['variant_id'] === (string)$v['id'] ? 'selected' : '' ?>><?= h($v['variant_text']) ?></option><?php endforeach; ?></select>
              <button class="btn btn-small">儲存</button></form>
            <?= $i > 0 ? $f('image_main', '設為主圖') . $f('image_up', '↑ 上移') : '' ?><?= $i < count($p['images']) - 1 ? $f('image_down', '↓ 下移') : '' ?><?= $f('image_delete', '刪除', 'btn-danger') ?>
          <?php endif; ?></div></li>
      <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
    <?php
    page_footer();
    exit;
}

// ---------------- 列表 ----------------
$q = mb_substr(get_str('q'), 0, 100);
$category = to_pos_int($_GET['category'] ?? '');
$page = current_page();
[$rows, $total] = product_search(['q' => $q, 'category' => $category], $page, 30, false);
admin_header('商品管理');
?>
<h1>商品管理</h1>
<form class="filters" method="get">
  <label>名稱、SKU 或規格 SKU <input type="search" name="q" value="<?= h($q) ?>" maxlength="100"></label>
  <label>分類 <select name="category"><option value="">全部</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $category === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn btn-primary">搜尋</button> <a class="btn" href="admin_products.php">清除</a>
  <?php if ($canEdit): ?><a class="btn" href="admin_products.php?new=1">＋ 新增商品</a><?php endif; ?>
</form>
<div class="table-wrap"><table>
  <thead><tr><th>編號</th><th></th><th>SKU</th><th>名稱</th><th>分類</th><th>價格</th><th class="num">庫存</th><th>狀態</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $p): ?>
    <tr class="<?= (int)$p['is_active'] ? '' : 'row-off' ?>"><td><?= (int)$p['id'] ?></td><td><img src="<?= h(product_image_url($p['thumb'])) ?>" alt="" width="48" height="36" loading="lazy"></td>
      <td><?= h($p['sku']) ?></td><td><?= h($p['name']) ?><?= (int)$p['variant_count'] > 1 ? ' ' . badge((int)$p['variant_count'] . ' 規格') : '' ?></td>
      <td><?= h($p['category_name'] ?? '未分類') ?></td><td><?= h(price_range_text($p['price_min'] ?? $p['price'], $p['price_max'] ?? $p['price'])) ?></td>
      <td class="num"><?= (int)$p['stock'] ?></td><td><?= (int)$p['is_active'] ? '上架' : '下架' ?></td>
      <td><a class="btn btn-small" href="admin_products.php?id=<?= (int)$p['id'] ?>"><?= $canEdit ? '編輯' : '檢視' ?></a></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= render_pager($total, $page, 30, array_filter(['q' => $q, 'category' => $category])) ?>
<?php
page_footer();
