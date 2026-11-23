<?php
/**
 * 辨識碼（第五階段，決策 31、32）
 * - 每張表單各自產生、Session 只存雜湊、10 分鐘有效、只能用一次、不分大小寫
 * - 排除 0／O、1／I／L 等易混淆字元
 * - 同一 Session 連續錯 10 次需等 5 分鐘
 * - 主機沒有 GD 時改用算術題
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const CAPTCHA_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CAPTCHA_TTL = 600;

function captcha_enabled(): bool
{
    return (bool)$GLOBALS['enable_ops'];
}

function captcha_has_gd(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

function captcha_valid_form(string $form): bool
{
    return (bool)preg_match('/^[a-z_]{2,30}$/', $form);
}

/** 產生新的辨識碼並存入 Session（只存雜湊）；回傳明文（圖片用）或算術題文字 */
function captcha_issue(string $form): array
{
    if (captcha_has_gd()) {
        $code = '';
        for ($i = 0; $i < 5; $i++) { $code .= CAPTCHA_CHARS[random_int(0, strlen(CAPTCHA_CHARS) - 1)]; }
        $answer = $code;
        $question = null;
    } else {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $answer = (string)($a + $b);
        $question = "{$a} 加 {$b} 等於多少？";
        $code = $question;
    }
    $_SESSION['_cap'][$form] = ['h' => hash('sha256', strtoupper($answer)), 'exp' => time() + CAPTCHA_TTL, 'q' => $question];
    return ['code' => $code, 'question' => $question];
}

/** 表單內的辨識碼欄位 */
function captcha_field(string $form): string
{
    if (!captcha_enabled()) { return ''; }
    $id = 'cap_' . $form;
    if (!captcha_has_gd()) {
        $q = captcha_issue($form)['question'];
        return '<div class="captcha"><label for="' . h($id) . '">辨識碼：' . h($q) . '</label>'
            . '<input id="' . h($id) . '" type="text" name="captcha" inputmode="numeric" maxlength="3" required autocomplete="off"></div>';
    }
    $src = 'captcha.php?f=' . rawurlencode($form) . '&r=' . bin2hex(random_bytes(4));
    return '<div class="captcha"><label for="' . h($id) . '">辨識碼（不分大小寫）</label>'
        . '<div class="captcha-row"><img src="' . h($src) . '" width="150" height="50" alt="辨識碼圖片：請輸入圖片中的 5 個英數字；看不清楚可按「換一張」" data-captcha-img>'
        . '<button type="button" class="btn btn-small" data-captcha-refresh>換一張</button></div>'
        . '<input id="' . h($id) . '" type="text" name="captcha" maxlength="5" required autocomplete="off" autocapitalize="characters" spellcheck="false">'
        . '</div>';
}

/** 後端先驗：CSRF 之後立即呼叫；失敗拋出 UserError（呼叫端保留已填內容） */
function captcha_check(string $form): void
{
    global $app_debug, $captcha_test_code;
    if (!captcha_enabled()) { return; }
    $fail = $_SESSION['_cap_fail'] ?? ['n' => 0, 't' => 0];
    if ($fail['n'] >= 10 && time() - $fail['t'] < 300) {
        security_log('captcha_lock', '辨識碼連續錯誤 10 次', true);
        throw new UserError('辨識碼錯誤次數過多，請 5 分鐘後再試。');
    }
    $input = strtoupper(trim((string)($_POST['captcha'] ?? '')));
    $saved = $_SESSION['_cap'][$form] ?? null;
    unset($_SESSION['_cap'][$form]); // 只能用一次
    $ok = false;
    if ($app_debug && !empty($captcha_test_code) && hash_equals(strtoupper((string)$captcha_test_code), $input)) {
        $ok = true; // 本機自動測試用，正式環境 $app_debug 必為 false
    } elseif (is_array($saved) && $saved['exp'] >= time() && $input !== '' && hash_equals($saved['h'], hash('sha256', $input))) {
        $ok = true;
    }
    if (!$ok) {
        $_SESSION['_cap_fail'] = ['n' => ($fail['n'] >= 10 ? 0 : $fail['n']) + 1, 't' => time()];
        throw new UserError($input === '' ? '請輸入辨識碼。' : '辨識碼不正確或已過期，請重新輸入新的辨識碼。');
    }
    unset($_SESSION['_cap_fail']);
}

/** 輸出 PNG 圖片 */
function captcha_render_png(string $code): void
{
    $w = 150; $hgt = 50;
    $im = imagecreatetruecolor($w, $hgt);
    $bg = imagecolorallocate($im, 246, 248, 247);
    imagefilledrectangle($im, 0, 0, $w, $hgt, $bg);
    for ($i = 0; $i < 6; $i++) {
        $c = imagecolorallocate($im, random_int(150, 210), random_int(150, 210), random_int(150, 210));
        imageline($im, random_int(0, $w), random_int(0, $hgt), random_int(0, $w), random_int(0, $hgt), $c);
    }
    for ($i = 0; $i < 300; $i++) {
        $c = imagecolorallocate($im, random_int(120, 220), random_int(120, 220), random_int(120, 220));
        imagesetpixel($im, random_int(0, $w - 1), random_int(0, $hgt - 1), $c);
    }
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        $c = imagecolorallocate($im, random_int(10, 80), random_int(40, 110), random_int(30, 90));
        $x = 12 + $i * 26 + random_int(-3, 3);
        $y = random_int(10, 22);
        // 內建字型放大：先畫在小圖再放大，避免依賴 TTF 字型檔
        $tmp = imagecreatetruecolor(10, 16);
        $tbg = imagecolorallocate($tmp, 246, 248, 247);
        imagefilledrectangle($tmp, 0, 0, 10, 16, $tbg);
        $tc = imagecolorallocate($tmp, ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
        imagestring($tmp, 5, 1, 0, $code[$i], $tc);
        imagecolortransparent($tmp, $tbg);
        imagecopyresized($im, $tmp, $x, $y, 0, 0, 20, 28, 10, 16);
        imagedestroy($tmp);
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, max-age=0');
    imagepng($im);
    imagedestroy($im);
}
