<?php
/**
 * SEO、Open Graph、Twitter Card 與 JSON-LD 結構化資料（第六階段，第 26、27 節）
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function seo_excerpt(?string $s, int $len = 120): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
}

function site_image_url(string $file): string
{
    return $file === '' ? '' : abs_url(SITE_IMAGE_DIR . $file);
}

function seo_default_og_image(): string
{
    $f = setting('default_og_file');
    return $f !== '' ? site_image_url($f) : '';
}

function seo_store_name(): string
{
    global $site_name;
    $n = trim(setting('store_name'));
    return $n !== '' ? $n : $site_name;
}

function jsonld_script(array $data): string
{
    $data = ['@context' => 'https://schema.org'] + $data;
    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRESERVE_ZERO_FRACTION)
        . "</script>\n";
}

/** <head> 內的 description、canonical、OG、Twitter 與 JSON-LD */
function seo_head_tags(string $fullTitle, array $opts): string
{
    $out = '';
    $desc = trim((string)($opts['description'] ?? ''));
    if ($desc === '' && script_name() === 'index.php') { $desc = trim(setting('home_description')); }
    if ($desc !== '') { $out .= '<meta name="description" content="' . h($desc) . '">' . "\n"; }
    if (!empty($opts['canonical'])) {
        $out .= '<link rel="canonical" href="' . h(abs_url($opts['canonical'])) . '">' . "\n";
    }
    if (isset($opts['og']) && is_array($opts['og'])) {
        $og = $opts['og'];
        $img = $og['image'] ?? '';
        if ($img === '') { $img = seo_default_og_image(); }
        $tags = [
            'og:type' => $og['type'] ?? 'website',
            'og:title' => $og['title'] ?? $fullTitle,
            'og:description' => $og['description'] ?? $desc,
            'og:url' => !empty($opts['canonical']) ? abs_url($opts['canonical']) : '',
            'og:site_name' => seo_store_name(),
            'og:locale' => 'zh_TW',
            'og:image' => $img,
        ];
        foreach (($og['extra'] ?? []) as $k => $v) { $tags[$k] = $v; }
        foreach ($tags as $k => $v) {
            if ((string)$v !== '') { $out .= '<meta property="' . h($k) . '" content="' . h($v) . '">' . "\n"; }
        }
        $tw = ['twitter:card' => 'summary_large_image', 'twitter:title' => $tags['og:title'],
               'twitter:description' => $tags['og:description'], 'twitter:image' => $img];
        $handle = trim(setting('twitter_handle'));
        if ($handle !== '') { $tw['twitter:site'] = '@' . ltrim($handle, '@'); }
        foreach ($tw as $k => $v) {
            if ((string)$v !== '') { $out .= '<meta name="' . h($k) . '" content="' . h($v) . '">' . "\n"; }
        }
    }
    foreach (($opts['jsonld'] ?? []) as $ld) { $out .= jsonld_script($ld); }
    return $out;
}

// ---------------------------------------------------------------------
// JSON-LD 組件
// ---------------------------------------------------------------------
function seo_return_policy(): array
{
    $store = setting('return_shipping_payer', 'customer') === 'store';
    return [
        '@type' => 'MerchantReturnPolicy',
        'applicableCountry' => 'TW',
        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
        'merchantReturnDays' => setting_int('return_days', 7),
        'returnMethod' => 'https://schema.org/ReturnByMail',
        'returnFees' => $store ? 'https://schema.org/FreeReturn' : 'https://schema.org/ReturnShippingFees',
    ];
}

function seo_delivery_time(string $kind): array
{
    $q = function (string $a, string $b) {
        return ['@type' => 'QuantitativeValue', 'minValue' => setting_int($a), 'maxValue' => setting_int($b), 'unitCode' => 'DAY'];
    };
    return [
        '@type' => 'ShippingDeliveryTime',
        'handlingTime' => $q('handling_min_days', 'handling_max_days'),
        'transitTime' => $kind === 'cvs' ? $q('transit_cvs_min_days', 'transit_cvs_max_days') : $q('transit_home_min_days', 'transit_home_max_days'),
    ];
}

/**
 * 單件商品的運送資訊：依運費規則（或超商固定運費）計算；單件價格已達免運門檻時運費為 0
 */
function seo_shipping_details(array $p, string $unitPrice): array
{
    $out = [];
    $priceCents = dec_to_cents($unitPrice);
    if (setting_on('ship_home_enabled')) {
        $fee = '0';
        if (setting('shipping_mode', 'by_rule') === 'by_rule' && !empty($p['shipping_rule_id'])) {
            $rule = shipping_rule_find((int)$p['shipping_rule_id']);
            if ($rule && (int)$rule['is_active'] === 1) {
                $free = $rule['free_threshold'] !== null && $priceCents >= dec_to_cents($rule['free_threshold']);
                $fee = $free ? '0' : $rule['fee'];
            }
        }
        $out[] = seo_shipping_entry($fee, 'home');
    }
    if (setting_on('ship_cvs_enabled') && (int)($p['allow_cvs'] ?? 1) === 1) {
        $th = setting('cvs_free_threshold');
        $fee = ($th !== '' && $priceCents >= dec_to_cents($th)) ? '0' : setting('cvs_fee', '0');
        $out[] = seo_shipping_entry($fee, 'cvs');
    }
    return $out;
}

function seo_shipping_entry(string $fee, string $kind): array
{
    return [
        '@type' => 'OfferShippingDetails',
        'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => dl_num($fee), 'currency' => 'TWD'],
        'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'TW'],
        'deliveryTime' => seo_delivery_time($kind),
    ];
}

function seo_breadcrumb(array $crumbs): array
{
    $list = [];
    $i = 1;
    foreach ($crumbs as [$name, $path]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i++, 'name' => $name, 'item' => abs_url($path)];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

function seo_item_list(array $products, int $startPos = 1): array
{
    $list = [];
    $i = $startPos;
    foreach ($products as $p) {
        $list[] = ['@type' => 'ListItem', 'position' => $i++, 'url' => abs_url('product.php?id=' . (int)$p['id']), 'name' => $p['name']];
    }
    return ['@type' => 'ItemList', 'itemListElement' => $list];
}

function seo_organization(): array
{
    $org = [
        '@type' => 'OnlineStore',
        '@id' => abs_url('#store'),
        'name' => seo_store_name(),
        'url' => abs_url(''),
        'hasMerchantReturnPolicy' => seo_return_policy(),
    ];
    $legal = trim(setting('legal_name'));
    if ($legal !== '') { $org['legalName'] = $legal; }
    if (setting('logo_file') !== '') { $org['logo'] = site_image_url(setting('logo_file')); }
    $tax = trim(setting('tax_id'));
    if ($tax !== '') { $org['taxID'] = $tax; }
    $cp = ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'areaServed' => 'TW', 'availableLanguage' => 'zh-TW'];
    if (trim(setting('cs_email')) !== '') { $cp['email'] = trim(setting('cs_email')); }
    if (trim(setting('cs_phone')) !== '') { $cp['telephone'] = trim(setting('cs_phone')); }
    if (count($cp) > 4) { $org['contactPoint'] = $cp; }
    $addr = trim(setting('store_address'));
    if ($addr !== '') { $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $addr, 'addressCountry' => 'TW']; }
    $same = array_values(array_filter(array_map('trim', preg_split('/\R/', setting('same_as'))), function ($u) {
        return preg_match('~^https?://~i', $u);
    }));
    if ($same) { $org['sameAs'] = $same; }
    return $org;
}

function seo_website(): array
{
    return [
        '@type' => 'WebSite', 'name' => seo_store_name(), 'url' => abs_url(''),
        'potentialAction' => ['@type' => 'SearchAction', 'target' => abs_url('products.php?q={search_term_string}'),
                              'query-input' => 'required name=search_term_string'],
    ];
}

/** 商品頁：單一規格輸出 Product；多規格輸出 ProductGroup + hasVariant；下架商品不輸出 Offer */
function seo_product(array $p): array
{
    global $site_name;
    $brand = trim((string)$p['brand']) ?: (trim(setting('default_brand')) ?: $site_name);
    $images = array_map(function ($i) { return abs_url(PRODUCT_IMAGE_DIR . $i['file_large']); }, $p['images']);
    $active = (int)$p['is_active'] === 1;
    $variants = array_values(array_filter($p['variants'], function ($v) { return (int)$v['is_active'] === 1; }));
    $url = abs_url('product.php?id=' . (int)$p['id']);
    $offer = function (array $v) use ($p, $url) {
        return [
            '@type' => 'Offer',
            'url' => $url . (count($p['variants']) > 1 ? '&variant=' . (int)$v['id'] : ''),
            'priceCurrency' => 'TWD',
            'price' => dl_num($v['effective_price']),
            'availability' => (int)$v['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'shippingDetails' => seo_shipping_details($p, $v['effective_price']),
            'hasMerchantReturnPolicy' => seo_return_policy(),
        ];
    };
    $base = [
        'name' => $p['name'],
        'description' => seo_excerpt($p['description'], 5000),
        'brand' => ['@type' => 'Brand', 'name' => $brand],
    ];
    if ($images) { $base['image'] = $images; }
    $multi = count($p['options']) > 0 && count($variants) > 0;
    if (!$multi) {
        $v = $variants[0] ?? ($p['variants'][0] ?? null);
        $prod = ['@type' => 'Product'] + $base + ['sku' => $v['sku'] ?? $p['sku']];
        if ($v && !empty($v['gtin'])) { $prod['gtin'] = $v['gtin']; }
        if ($active && $v) { $prod['offers'] = $offer($v); }
        return $prod;
    }
    $group = ['@type' => 'ProductGroup'] + $base + ['productGroupID' => $p['sku'], 'url' => $url];
    $varies = [];
    foreach ($p['options'] as $o) {
        $varies[] = preg_match('/色|color/iu', $o['name']) ? 'https://schema.org/color'
            : (preg_match('/尺|碼|size/iu', $o['name']) ? 'https://schema.org/size' : 'https://schema.org/' . rawurlencode($o['name']));
    }
    $group['variesBy'] = $varies;
    $group['hasVariant'] = [];
    foreach ($variants as $v) {
        $item = ['@type' => 'Product', 'name' => $p['name'] . ' ' . $v['variant_text'], 'sku' => $v['sku']];
        if (!empty($v['gtin'])) { $item['gtin'] = $v['gtin']; }
        foreach ($p['options'] as $i => $o) {
            $val = $i === 0 ? $v['value1'] : $v['value2'];
            if ($val === null) { continue; }
            if (preg_match('/色|color/iu', $o['name'])) { $item['color'] = $val; }
            elseif (preg_match('/尺|碼|size/iu', $o['name'])) { $item['size'] = $val; }
            else { $item['additionalProperty'][] = ['@type' => 'PropertyValue', 'name' => $o['name'], 'value' => $val]; }
        }
        if (!empty($v['image_id'])) {
            foreach ($p['images'] as $img) {
                if ((int)$img['id'] === (int)$v['image_id']) { $item['image'] = abs_url(PRODUCT_IMAGE_DIR . $img['file_large']); }
            }
        }
        if ($active) { $item['offers'] = $offer($v); }
        $group['hasVariant'][] = $item;
    }
    return $group;
}

/** 商品頁 SEO 標題、說明與 OG 圖 */
function seo_product_meta(array $p): array
{
    $title = trim((string)$p['seo_title']) !== '' ? $p['seo_title'] : $p['name'] . '｜' . seo_store_name();
    $desc = trim((string)$p['meta_description']) !== '' ? $p['meta_description'] : seo_excerpt($p['description']);
    $img = '';
    foreach ($p['images'] as $i) {
        if ($p['og_image_id'] !== null && (int)$i['id'] === (int)$p['og_image_id']) { $img = abs_url(PRODUCT_IMAGE_DIR . $i['file_large']); }
    }
    if ($img === '' && $p['images']) { $img = abs_url(PRODUCT_IMAGE_DIR . $p['images'][0]['file_large']); }
    return [$title, $desc, $img];
}
