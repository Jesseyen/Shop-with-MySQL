<?php
/**
 * 共用啟動檔：所有入口都 require 本檔。
 * 載入 config.php，建立錯誤紀錄、Session、PDO 與共用函式，並執行維護模式與被動檢查。
 * 相容 PHP 7.4 ～ 8.x。
 */
define('APP_BOOT', true);
define('APP_VERSION', '6.0.2');
define('APP_SCHEMA_VERSION', '6.0');

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "尚未建立 config.php：請複製 config.sample.php 為 config.php 並填入設定。";
    exit;
}
require_once __DIR__ . '/config.php';

// ---- 設定預設值與開關依賴 ----
if (!isset($db_port)) { $db_port = 3306; }
if (!isset($smtp_secure)) { $smtp_secure = 'tls'; }
foreach (['enable_cart', 'enable_behavior', 'enable_orders', 'enable_ops', 'enable_launch'] as $__k) {
    if (!isset($$__k)) { $$__k = true; }
}
if (!isset($app_debug)) { $app_debug = false; }
if (!isset($disable_custom_code)) { $disable_custom_code = false; }
if (!isset($disable_admin_ip_allowlist)) { $disable_admin_ip_allowlist = false; }
if (!isset($trusted_proxies)) { $trusted_proxies = []; }
if (!isset($trusted_proxy_header)) { $trusted_proxy_header = ''; }
if (!isset($payment_hmac_key)) { $payment_hmac_key = ''; }
if (!isset($private_dir) || $private_dir === '') { $private_dir = __DIR__ . '/private_data'; }
$site_url = rtrim($site_url, '/');
if (!$enable_cart) { $enable_behavior = false; $enable_orders = false; }
if (!$enable_orders || !$enable_ops) { $enable_launch = false; }

date_default_timezone_set('Asia/Taipei');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', $app_debug ? '1' : '0');
ini_set('log_errors', '1');

/** 可直接顯示給使用者的錯誤訊息 */
class UserError extends RuntimeException {}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') { return true; }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) { return true; }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') { return true; }
    return false;
}

/**
 * 訪客 IP：預設只信任 REMOTE_ADDR；REMOTE_ADDR 屬於 config.php 設定的信任代理時，才採用指定標頭的最後一個非代理位址。
 */
function client_ip(): string
{
    global $trusted_proxies, $trusted_proxy_header;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($trusted_proxy_header !== '' && $trusted_proxies && in_array($ip, $trusted_proxies, true)) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $trusted_proxy_header));
        $raw = (string)($_SERVER[$key] ?? '');
        $parts = array_reverse(array_map('trim', explode(',', $raw)));
        foreach ($parts as $p) {
            if (filter_var($p, FILTER_VALIDATE_IP) && !in_array($p, $trusted_proxies, true)) { return $p; }
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function script_name(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
}

require_once __DIR__ . '/lib/errors.php';
errors_register();

// ---------------------------------------------------------------------
// Session（可在 config.php 以 $session_save_path 指定帳號內非公開目錄）
// ---------------------------------------------------------------------
if (!empty($session_save_path)) {
    if (!is_dir($session_save_path)) { @mkdir($session_save_path, 0700, true); }
    if (is_dir($session_save_path) && is_writable($session_save_path)) { session_save_path($session_save_path); }
}
$cookie_path = parse_url($site_url, PHP_URL_PATH);
$cookie_path = ($cookie_path === null || $cookie_path === '') ? '/' : rtrim($cookie_path, '/') . '/';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '7200');
session_name('SHOPSID');
session_set_cookie_params([
    'lifetime' => 0, 'path' => $cookie_path, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
]);
if (PHP_SAPI !== 'cli') { session_start(); }

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------------------------------------------------------------------
// 資料庫
// ---------------------------------------------------------------------
$pdo = new PDO(
    "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4",
    $db_user, $db_pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]
);
$pdo->exec("SET time_zone = '+08:00'");

// ---------------------------------------------------------------------
// 一般工具
// ---------------------------------------------------------------------
function redirect(string $path): void
{
    header('Location: ' . $path, true, 303);
    exit;
}

function abs_url(string $path): string
{
    global $site_url;
    return $site_url . '/' . ltrim($path, '/');
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function post_str(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function get_str(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function post_arr(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [];
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** 解析正整數；不合法回傳 null */
function to_pos_int($v, int $max = 2147483647): ?int
{
    if (is_int($v)) { $s = (string)$v; } elseif (is_string($v)) { $s = trim($v); } else { return null; }
    if (!preg_match('/^[1-9][0-9]{0,9}$/', $s)) { return null; }
    $n = (int)$s;
    return $n <= $max ? $n : null;
}

/** 解析非負整數；不合法回傳 null */
function to_nonneg_int($v, int $max = 2147483647): ?int
{
    if (is_string($v) && trim($v) === '0') { return 0; }
    if ($v === 0) { return 0; }
    return to_pos_int($v, $max);
}

function require_post(): void
{
    if (!is_post()) {
        render_error_page(405, '不允許的請求方式', '此操作只接受表單送出（POST）。');
    }
}

/** 金額格式化（字串運算，避免浮點誤差），例如 1234.50 → NT$1,234.50 */
function money($dec): string
{
    $s = (string)$dec;
    $neg = false;
    if ($s !== '' && $s[0] === '-') { $neg = true; $s = substr($s, 1); }
    $parts = explode('.', $s, 2);
    $int = ltrim($parts[0], '0');
    if ($int === '') { $int = '0'; }
    $frac = isset($parts[1]) ? str_pad(substr($parts[1], 0, 2), 2, '0') : '00';
    $int = strrev(implode(',', str_split(strrev($int), 3)));
    $out = 'NT$' . $int . ($frac === '00' ? '' : '.' . $frac);
    return ($neg ? '−' : '') . $out;
}

/** 驗證價格字串：最多 8 位整數 + 2 位小數 */
function parse_price(string $s): ?string
{
    $s = trim($s);
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/', $s)) { return null; }
    return $s;
}

/** DECIMAL 字串 → 整數「分」 */
function dec_to_cents($dec): int
{
    $dec = (string)$dec;
    $neg = $dec !== '' && $dec[0] === '-';
    if ($neg) { $dec = substr($dec, 1); }
    $parts = explode('.', $dec, 2);
    $frac = str_pad(substr($parts[1] ?? '0', 0, 2), 2, '0');
    $v = (int)$parts[0] * 100 + (int)$frac;
    return $neg ? -$v : $v;
}

/** 整數「分」 → DECIMAL 字串 */
function cents_to_dec(int $c): string
{
    $neg = $c < 0;
    $c = abs($c);
    return ($neg ? '-' : '') . intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function valid_email(string $email): bool
{
    return strlen($email) <= 191 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** 密碼長度：8～72 位元組（bcrypt 上限 72 位元組） */
function password_length_error(string $pw): ?string
{
    $len = strlen($pw);
    if ($len < 8) { return '密碼至少 8 個字元（位元組）。'; }
    if ($len > 72) { return '密碼最多 72 位元組（中文字一個約 3 位元組）。'; }
    return null;
}

/** 產生隨機憑證：回傳 [明文, SHA-256 雜湊] */
function new_token(): array
{
    $plain = bin2hex(random_bytes(32));
    return [$plain, hash('sha256', $plain)];
}

function token_hash_from_input(string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return null; }
    return hash('sha256', $token);
}

/** 字數檢查（以字元計） */
function len_between(string $s, int $min, int $max): bool
{
    $l = mb_strlen($s);
    return $l >= $min && $l <= $max;
}

/** 返回位置只接受同目錄 product.php?id=正整數 */
function validate_return_to($s): ?string
{
    if (!is_string($s)) { return null; }
    if (preg_match('/^product\.php\?id=([1-9][0-9]{0,9})$/', $s, $m)) {
        return 'product.php?id=' . $m[1];
    }
    return null;
}

function remember_return_to($s): ?string
{
    $r = validate_return_to($s);
    if ($r !== null) { $_SESSION['return_to'] = $r; }
    return $r;
}

function take_return_to(): string
{
    $r = validate_return_to($_SESSION['return_to'] ?? null);
    unset($_SESSION['return_to']);
    return $r ?? 'index.php';
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) { $_SESSION['_csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function require_csrf(): void
{
    require_post();
    $t = $_POST['csrf_token'] ?? '';
    if (!is_string($t) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $t)) {
        render_error_page(400, '表單已失效', '表單驗證碼不正確或已過期，請重新整理頁面後再試一次。');
    }
}

// ---------------------------------------------------------------------
// 會員身分（每次請求都從資料庫核對）
// ---------------------------------------------------------------------
function current_member(bool $refresh = false): ?array
{
    global $pdo;
    static $cache = false;
    if ($cache !== false && !$refresh) { return $cache; }
    $cache = null;
    $id = $_SESSION['member_id'] ?? null;
    if (!is_int($id)) { return null; }
    $st = $pdo->prepare('SELECT id, name, email, status, is_admin, is_super_admin, member_level, level_locked, total_spent,
                                created_at, deleted_at, deletion_requested_at, row_version
                           FROM members WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m || (int)$m['status'] !== 1 || $m['deleted_at'] !== null) {
        clear_login_state();
        return null;
    }
    $m['id'] = (int)$m['id'];
    $m['is_admin'] = (int)$m['is_admin'];
    $m['is_super_admin'] = (int)$m['is_super_admin'];
    $m['status'] = (int)$m['status'];
    $cache = $m;
    return $m;
}

function clear_login_state(): void
{
    unset($_SESSION['member_id'], $_SESSION['login_at'], $_SESSION['_an'], $_SESSION['_cart_return']);
}

function login_member(array $m): void
{
    session_regenerate_id(true);
    $_SESSION['member_id'] = (int)$m['id'];
    $_SESSION['login_at'] = time();
    unset($_SESSION['_an'], $_SESSION['_cart_return']);
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    current_member(true);
    if (function_exists('analytics_on_login')) { analytics_on_login((int)$m['id']); }
}

function logout_member(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function require_member(): array
{
    $m = current_member();
    if (!$m) { render_forbidden('需要會員登入', false); }
    return $m;
}

/** 以 row_version 拒絕舊頁覆寫 */
function assert_row_version(array $row, $posted): void
{
    $v = to_pos_int($posted);
    if ($v === null || (int)$row['row_version'] !== $v) {
        throw new UserError('資料已被其他人或其他分頁修改，請重新整理後再操作。');
    }
}

/** 寫入後台異動紀錄（自動排除敏感欄位），須在交易內呼叫 */
function audit_log(PDO $pdo, ?int $actorId, string $entityType, $entityId, string $action, ?array $before, ?array $after): void
{
    $sensitive = ['password_hash', 'password', 'email_verify_token_hash', 'email_verify_expires_at',
                  'reset_password_token', 'reset_password_expires_at', 'csrf_token', 'checkout_token', 'token_hash'];
    $clean = function ($data) use ($sensitive) {
        if ($data === null) { return null; }
        foreach ($sensitive as $k) { unset($data[$k]); }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $st = $pdo->prepare('INSERT INTO audit_logs (actor_id, entity_type, entity_id, action, before_data, after_data, ip)
                         VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$actorId, $entityType, (string)$entityId, $action, $clean($before), $clean($after), client_ip()]);
}

/** 交易包裝（前台用）：任一步失敗全部回復 */
function db_transaction(callable $fn)
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        if (function_exists('flush_deferred_mail')) { flush_deferred_mail(); }
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (function_exists('clear_deferred_mail')) { clear_deferred_mail(); }
        throw $e;
    }
}

// ---------------------------------------------------------------------
// 分頁
// ---------------------------------------------------------------------
function current_page(): int
{
    $p = to_pos_int($_GET['page'] ?? '1', 100000);
    return $p ?? 1;
}

function render_pager(int $total, int $page, int $perPage, array $params = []): string
{
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) { return '<p class="muted small">共 ' . $total . ' 筆</p>'; }
    $script = script_name();
    $link = function ($p) use ($params, $script) {
        $params['page'] = $p;
        return h($script . '?' . http_build_query($params));
    };
    $out = '<nav class="pager" aria-label="分頁">';
    if ($page > 1) { $out .= '<a href="' . $link($page - 1) . '">‹ 上一頁</a>'; }
    for ($i = max(1, $page - 3), $end = min($pages, $page + 3); $i <= $end; $i++) {
        $out .= $i === $page ? '<span class="current">' . $i . '</span>' : '<a href="' . $link($i) . '">' . $i . '</a>';
    }
    if ($page < $pages) { $out .= '<a href="' . $link($page + 1) . '">下一頁 ›</a>'; }
    return $out . '<span class="muted">共 ' . $total . ' 筆</span></nav>';
}

// ---------------------------------------------------------------------
// 共用函式庫
// ---------------------------------------------------------------------
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/perm.php';
require_once __DIR__ . '/lib/captcha.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/shop_navigation.php';
require_once __DIR__ . '/lib/member_policy.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/catalog_helpers.php';
if ($enable_cart) { require_once __DIR__ . '/lib/cart_helpers.php'; }
if ($enable_behavior) { require_once __DIR__ . '/lib/behavior_helpers.php'; }
if ($enable_orders) {
    require_once __DIR__ . '/lib/order_helpers.php';
    require_once __DIR__ . '/lib/order_mail.php';
}
if ($enable_launch) {
    require_once __DIR__ . '/lib/seo.php';
    require_once __DIR__ . '/lib/datalayer.php';
    require_once __DIR__ . '/lib/payment.php';
    require_once __DIR__ . '/lib/account_deletion.php';
}

maintenance_gate();
