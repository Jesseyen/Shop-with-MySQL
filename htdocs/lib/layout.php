<?php
/**
 * 版面：<head> 輸出順序（第 16、17、28 節）
 *   1. Consent Mode 預設指令 → 2. 已有選擇時的 update → 3. dataLayer 頁面資訊與電子商務事件
 *   → 4. 自訂程式碼（<head> 後）→ title、meta、SEO、JSON-LD → 5. 自訂程式碼（</head> 前）
 * <body> 後與 </body> 前也各有自訂程式碼位置；Cookie 橫幅只在前台輸出。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

// ---------------------------------------------------------------------
// Cookie 同意（第五階段）：第一方 Cookie shop_consent = 版本.分析.廣告.時間戳
// ---------------------------------------------------------------------
function consent_state(): ?array
{
    global $enable_ops;
    if (!$enable_ops) { return null; }
    $raw = (string)($_COOKIE['shop_consent'] ?? '');
    if (!preg_match('/^([0-9]{1,6})\.([01])\.([01])\.([0-9]{9,11})$/', $raw, $m)) { return null; }
    if ((int)$m[1] !== setting_int('consent_version', 1)) { return null; }
    if ((int)$m[4] < time() - 180 * 86400) { return null; }
    return ['analytics' => $m[2] === '1', 'ads' => $m[3] === '1'];
}

function consent_head_script(bool $isAdmin): string
{
    global $enable_ops;
    if (!$enable_ops) { return ''; }
    $state = consent_state();
    $js = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
        . "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',"
        . "functionality_storage:'granted',security_storage:'granted',wait_for_update:500});\n";
    if (setting_on('consent_url_passthrough')) { $js .= "gtag('set','url_passthrough',true);\n"; }
    if (setting_on('consent_ads_data_redaction')) { $js .= "gtag('set','ads_data_redaction',true);\n"; }
    if ($state) {
        $a = $state['analytics'] ? 'granted' : 'denied';
        $d = $state['ads'] ? 'granted' : 'denied';
        $js .= "gtag('consent','update',{analytics_storage:'{$a}',ad_storage:'{$d}',ad_user_data:'{$d}',ad_personalization:'{$d}'});\n";
    }
    $cfg = [
        'version' => setting_int('consent_version', 1),
        'mode' => setting('consent_mode', 'advanced'),
        'state' => $state,
        'autoShow' => !$isAdmin && setting_on('consent_banner_enabled') && $state === null,
        'path' => (string)(parse_url($GLOBALS['site_url'], PHP_URL_PATH) ?: '/'),
    ];
    $js .= 'window.SHOP_CONSENT=' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n";
    return "<script>\n{$js}</script>\n";
}

// ---------------------------------------------------------------------
// 自訂程式碼（第五階段）
// ---------------------------------------------------------------------
function custom_code_disabled(): bool
{
    global $disable_custom_code, $enable_ops;
    if (!$enable_ops || $disable_custom_code) { return true; }
    return get_str('no_custom_code') === '1' && current_member() && current_member()['is_admin'] === 1;
}

function custom_code_rows(): array
{
    global $pdo;
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        try {
            $rows = $pdo->query('SELECT id, position, scope, load_condition, code FROM custom_code_snippets
                                  WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        } catch (PDOException $e) {
            error_log('[shop] 讀取自訂程式碼失敗：' . $e->getMessage());
        }
    }
    return $rows;
}

/** 需等待同意的程式碼：轉成 type="text/plain" 並加同意分類，取得同意後由 shop.js 轉為可執行 */
function custom_code_gate(string $code, string $category): string
{
    return preg_replace_callback('~<script\b([^>]*)>~i', function ($m) use ($category) {
        $attrs = preg_replace('~\stype\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $m[1]);
        return '<script type="text/plain" data-consent="' . $category . '"' . $attrs . '>';
    }, $code);
}

function custom_code_output(string $position, bool $isAdmin): string
{
    if (custom_code_disabled()) { return ''; }
    $state = consent_state();
    $mode = setting('consent_mode', 'advanced');
    $out = '';
    foreach (custom_code_rows() as $r) {
        if ($r['position'] !== $position) { continue; }
        if ($r['scope'] !== 'both' && $r['scope'] !== ($isAdmin ? 'admin' : 'front')) { continue; }
        $cond = $r['load_condition'];
        $need = null;
        if ($cond === 'analytics') { $need = 'analytics'; }
        elseif ($cond === 'ads') { $need = 'ads'; }
        elseif ($cond === 'google_tag' && $mode === 'basic') { $need = 'any'; }
        $granted = $need === null
            || ($state && ($need === 'any' ? ($state['analytics'] || $state['ads']) : $state[$need]));
        $code = $granted ? $r['code'] : custom_code_gate($r['code'], $need);
        $out .= "<!-- custom-code #{$r['id']} -->\n" . $code . "\n";
    }
    return $out;
}

// ---------------------------------------------------------------------
// 頁面
// ---------------------------------------------------------------------
function noindex_scripts(): array
{
    return ['cart.php', 'checkout.php', 'pay_sim.php', 'login.php', 'register.php', 'register_process.php',
            'forgot_password.php', 'reset_password.php', 'verify.php', 'resend_verify.php', 'logout.php', 'order_done.php'];
}

/**
 * $opts：admin、noindex、canonical、description、og（陣列）、jsonld（陣列的陣列）、page_type、events、seo_title
 * 為相容舊頁面，第二個參數也可直接傳 true 表示後台頁。
 */
function page_header(string $title, $opts = []): void
{
    global $site_name, $enable_launch;
    if (!is_array($opts)) { $opts = ['admin' => (bool)$opts]; }
    $isAdmin = !empty($opts['admin']) || !empty($GLOBALS['IN_ADMIN']);
    $GLOBALS['_page_is_admin'] = $isAdmin;
    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    $member = current_member();
    $self = script_name();
    $noindex = $isAdmin ? 'noindex, nofollow'
        : ((!empty($opts['noindex']) || in_array($self, noindex_scripts(), true) || strpos($self, 'member') === 0) ? 'noindex, follow' : null);
    if ($isAdmin && !headers_sent()) { header('X-Robots-Tag: noindex, nofollow'); }

    $fullTitle = $opts['seo_title'] ?? ($title . '｜' . $site_name);
    echo '<!doctype html><html lang="zh-Hant-TW"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo consent_head_script($isAdmin);
    if ($enable_launch && function_exists('datalayer_head_script')) {
        echo datalayer_head_script($opts['page_type'] ?? ($isAdmin ? 'admin' : 'other'), $opts['events'] ?? []);
    }
    echo custom_code_output('head_start', $isAdmin);
    echo '<title>' . h($fullTitle) . '</title>' . "\n";
    if ($noindex) { echo '<meta name="robots" content="' . $noindex . '">' . "\n"; }
    if (!$isAdmin && $enable_launch && function_exists('seo_head_tags')) {
        echo seo_head_tags($fullTitle, $opts);
    } elseif (!empty($opts['description'])) {
        echo '<meta name="description" content="' . h($opts['description']) . '">' . "\n";
    }
    echo '<link rel="stylesheet" href="assets/shop.css?v=' . APP_VERSION . '">' . "\n";
    echo custom_code_output('head_end', $isAdmin);
    echo '</head><body' . ($isAdmin ? ' class="admin"' : '') . '>' . "\n";
    echo custom_code_output('body_start', $isAdmin);
    echo '<a class="skip-link" href="#main">跳到主要內容</a>';
    if (empty($opts['maintenance'])) { render_navigation($member); } else { render_navigation(null, true); }
    echo '<main class="container" id="main">';
    if (maintenance_on() && is_staff()) {
        echo '<div class="alert alert-warn" role="status">維護模式中：訪客與會員目前看到維護頁，只有管理員可以操作。</div>';
    }
    foreach (flash_take() as $f) {
        echo '<div class="alert alert-' . h($f['type']) . '" role="status">' . h($f['message']) . '</div>';
    }
}

function page_footer(): void
{
    global $enable_ops, $enable_launch;
    $isAdmin = !empty($GLOBALS['_page_is_admin']);
    echo '</main><footer class="site-footer"><div class="container">';
    if ($enable_ops) {
        echo '<nav class="footer-links" aria-label="頁尾連結"><a href="privacy.php">個資保護聲明</a> · <a href="contact.php">聯繫我們</a> · '
            . '<a href="returns.php">退貨條款</a> · <a href="shopping-guide.php">購物須知</a> · '
            . '<button type="button" class="link-btn" data-consent-open>Cookie 設定</button></nav>';
    }
    $tax = $enable_launch ? trim(setting('tax_id')) : '';
    echo '<p>© ' . date('Y') . ' ' . h(operator_name()) . ($tax !== '' ? '　統一編號 ' . h($tax) : '') . '</p>';
    if ($enable_ops) {
        $contact = trim(site_content('contact_info')['content']);
        if ($contact !== '') { echo '<div class="footer-contact small">' . plain_to_html($contact) . '</div>'; }
    } else {
        echo '<p class="small">本站為示範商場。</p>';
    }
    echo '</div></footer>';
    if ($enable_ops && !$isAdmin) { echo consent_banner_html(); }
    echo '<script src="assets/shop.js?v=' . APP_VERSION . '"></script>' . "\n";
    echo custom_code_output('body_end', $isAdmin);
    echo '</body></html>';
}

function consent_banner_html(): string
{
    $privacy = setting('consent_privacy_url', 'privacy.php');
    if (!preg_match('~^(https?://|[a-z0-9_\-./]+$)~i', $privacy)) { $privacy = 'privacy.php'; }
    return '<button type="button" class="consent-fab" data-consent-open aria-haspopup="dialog">Cookie 設定</button>'
        . '<div class="consent-panel" id="consent-panel" role="dialog" aria-modal="false" aria-labelledby="consent-title" hidden>'
        . '<div class="consent-box"><h2 id="consent-title">' . h(setting('consent_title')) . '</h2>'
        . plain_to_html(setting('consent_text'))
        . '<p><a href="' . h($privacy) . '">個資保護聲明</a></p>'
        . '<ul class="consent-options">'
        . '<li><label><input type="checkbox" checked disabled> 必要 Cookie（維持登入與購物車，不能關閉）</label></li>'
        . '<li><label><input type="checkbox" id="consent-analytics"> 允許分析統計</label></li>'
        . '<li><label><input type="checkbox" id="consent-ads"> 允許廣告資料與個人化</label></li></ul>'
        . '<div class="consent-actions"><button type="button" class="btn" data-consent="necessary">僅必要 Cookie</button>'
        . '<button type="button" class="btn" data-consent="save">儲存選擇</button>'
        . '<button type="button" class="btn btn-primary" data-consent="all">全部同意</button>'
        . '<button type="button" class="link-btn" data-consent="collapse">收合</button></div></div></div>';
}

function render_error_page(int $status, string $title, string $message): void
{
    if (!headers_sent()) { http_response_code($status); }
    page_header($title, ['noindex' => true]);
    echo '<section class="card narrow"><h1>' . h($title) . '</h1><p>' . h($message) . '</p>'
        . '<p><a class="btn" href="index.php">返回商場</a></p></section>';
    page_footer();
    exit;
}

/** HTTP 403：說明所需權限，提供登入與返回商場；已登入但權限不足者可安全切換帳號 */
function render_forbidden(string $need, bool $loggedInButInsufficient): void
{
    http_response_code(403);
    $GLOBALS['IN_ADMIN'] = false;
    page_header('無法存取', ['noindex' => true]);
    echo '<section class="card narrow"><h1>無法存取此頁面</h1><p>此頁面' . h($need) . '。</p>';
    if ($loggedInButInsufficient) {
        echo '<p>您目前的帳號權限不足，可以登出後改用其他帳號登入。</p>'
            . '<form method="post" action="logout.php" class="inline">' . csrf_field()
            . '<input type="hidden" name="next" value="login">'
            . '<button class="btn" type="submit">登出並切換帳號</button></form> ';
    } else {
        echo '<p><a class="btn btn-primary" href="login.php">登入</a> <a class="btn" href="register.php">註冊新帳號</a></p>';
    }
    echo '<p><a href="index.php">← 返回商場</a></p></section>';
    page_footer();
    exit;
}

function render_not_found(string $what = '頁面'): void
{
    render_error_page(404, '找不到' . $what, '您要找的' . $what . '不存在或已被移除。');
}

// ---------------------------------------------------------------------
// 小工具
// ---------------------------------------------------------------------
function options_html(array $options, $selected): string
{
    $out = '';
    foreach ($options as $v => $label) {
        $out .= '<option value="' . h($v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
}

function badge(string $text, string $kind = ''): string
{
    return '<span class="badge' . ($kind !== '' ? ' badge-' . h($kind) : '') . '">' . h($text) . '</span>';
}

function admin_header(string $title): void
{
    page_header($title, ['admin' => true]);
}

/** 後台列表篩選表單共用：輸出隱藏欄位以外的 GET 參數 */
function query_keep(array $except = []): array
{
    $q = $_GET;
    foreach ($except as $k) { unset($q[$k]); }
    return array_filter($q, function ($v) { return is_string($v) && $v !== ''; });
}
