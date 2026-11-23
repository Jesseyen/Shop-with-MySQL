<?php
/** 辨識碼圖片：每次載入都為該表單產生新的辨識碼（舊的立即失效） */
require_once __DIR__ . '/app.php';
$form = get_str('f');
if (!captcha_enabled() || !captcha_valid_form($form) || !captcha_has_gd()) {
    http_response_code(404);
    exit;
}
$r = captcha_issue($form);
captcha_render_png($r['code']);
