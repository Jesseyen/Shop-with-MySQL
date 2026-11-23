<?php
/**
 * GA4 電子商務 dataLayer（第六階段，第 28 節）
 * - 在 Consent Mode 指令之後、自訂程式碼（含 GTM）之前輸出
 * - 每個電子商務事件前先推送 {ecommerce: null}
 * - POST 後導向的事件先存入 Session 一次性佇列，下一頁輸出後清除
 * - 只推送頁面類型與是否登入，不推送任何個人資料
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function dl_num($dec): float
{
    return dec_to_cents((string)$dec) / 100;
}

/** 組成 GA4 items 的一筆 */
function dl_item(array $x, int $qty, int $index = 0): array
{
    global $site_name;
    $brand = trim((string)($x['brand'] ?? ''));
    if ($brand === '') { $brand = trim(setting('default_brand')) ?: $site_name; }
    $item = [
        'item_id' => (string)$x['sku'],
        'item_name' => (string)($x['name'] ?? $x['product_name'] ?? ''),
        'item_brand' => $brand,
        'price' => dl_num($x['price'] ?? $x['unit_price'] ?? $x['effective_price'] ?? '0'),
        'quantity' => $qty,
    ];
    $cat = $x['category_name'] ?? null;
    if ($cat !== null && $cat !== '') { $item['item_category'] = (string)$cat; }
    $vt = (string)($x['variant_text'] ?? '');
    if ($vt !== '') { $item['item_variant'] = $vt; }
    if ($index > 0) { $item['index'] = $index; }
    return $item;
}

function dl_event(string $event, array $ecommerce): array
{
    return ['event' => $event, 'ecommerce' => $ecommerce];
}

function dl_items_value(array $items): float
{
    $c = 0;
    foreach ($items as $i) { $c += (int)round($i['price'] * 100) * (int)$i['quantity']; }
    return $c / 100;
}

/** POST 後導向的事件存入一次性佇列 */
function datalayer_queue(array $event): void
{
    $q = $_SESSION['_dl_queue'] ?? [];
    $q[] = $event;
    $_SESSION['_dl_queue'] = array_slice($q, -10);
}

function datalayer_head_script(string $pageType, array $events): string
{
    $queued = $_SESSION['_dl_queue'] ?? [];
    unset($_SESSION['_dl_queue']);
    $events = array_merge($queued, $events);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRESERVE_ZERO_FRACTION;
    $js = "window.dataLayer=window.dataLayer||[];\n"
        . 'dataLayer.push(' . json_encode(['page_type' => $pageType, 'logged_in' => current_member() !== null], $flags) . ");\n";
    foreach ($events as $e) {
        if (isset($e['ecommerce'])) { $js .= "dataLayer.push({ecommerce:null});\n"; }
        $js .= 'dataLayer.push(' . json_encode($e, $flags) . ");\n";
    }
    return "<script>\n{$js}</script>\n";
}
