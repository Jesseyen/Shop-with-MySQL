<?php
/**
 * 後台權限（第五階段）：系統管理者＋六個模組的檢視／編輯；IP 白名單；安全紀錄；維護模式
 * 未啟用第五階段時，is_admin = 1 的管理者擁有全部權限（與第一～四階段相同）。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function admin_modules(): array
{
    return [
        'product' => '商品管理',
        'member' => '會員管理',
        'order' => '訂單處理',
        'shipping' => '出貨處理',
        'support' => '客服及聯繫表單',
        'report' => '報表分析',
    ];
}

/** 從資料庫讀取某會員的模組權限：['product' => 'edit', ...] */
function admin_perm_map(int $memberId, bool $refresh = false): array
{
    global $pdo, $enable_ops;
    static $cache = [];
    if (!$enable_ops) { return []; }
    if (!$refresh && isset($cache[$memberId])) { return $cache[$memberId]; }
    $st = $pdo->prepare('SELECT module, level FROM admin_permissions WHERE member_id = ?');
    $st->execute([$memberId]);
    $map = [];
    foreach ($st->fetchAll() as $r) { $map[$r['module']] = $r['level']; }
    return $cache[$memberId] = $map;
}

/** 權限判斷（不含 IP 白名單）：$module 為 'super' 時只有系統管理者 */
function member_can(?array $m, string $module, string $level = 'view'): bool
{
    global $enable_ops;
    if (!$m || (int)$m['is_admin'] !== 1) { return false; }
    if (!$enable_ops) { return true; }
    if ((int)($m['is_super_admin'] ?? 0) === 1) { return true; }
    if ($module === 'super') { return false; }
    $map = admin_perm_map((int)$m['id']);
    if (!isset($map[$module])) { return false; }
    return $level === 'view' || $map[$module] === 'edit';
}

/** 目前登入者是否可使用某模組（含 IP 白名單） */
function admin_can(string $module, string $level = 'view'): bool
{
    $m = current_member();
    return member_can($m, $module, $level) && admin_ip_allowed();
}

function is_super(): bool
{
    return admin_can('super', 'edit');
}

/** 是否為可使用後台的人員（任一模組權限、且符合 IP 白名單） */
function is_staff(): bool
{
    $m = current_member();
    if (!$m || $m['is_admin'] !== 1 || !admin_ip_allowed()) { return false; }
    if ($m['is_super_admin'] === 1 || !$GLOBALS['enable_ops']) { return true; }
    return admin_perm_map($m['id']) !== [];
}

$GLOBALS['IN_ADMIN'] = false;

/**
 * 後台頁面入口：登入、IP 白名單、模組權限都通過才回傳會員；否則 403。
 * 同時送出 X-Robots-Tag，並執行後台被動檢查。
 */
function require_perm(string $module, string $level = 'view'): array
{
    $GLOBALS['IN_ADMIN'] = true;
    if (!headers_sent()) { header('X-Robots-Tag: noindex, nofollow'); }
    $m = current_member();
    if (!$m) { render_forbidden('需要管理者登入', false); }
    if ($m['is_admin'] !== 1) { render_forbidden('需要管理者權限', true); }
    if (!admin_ip_allowed()) {
        security_log('ip_denied', '後台 IP 不在白名單：' . script_name(), true);
        render_forbidden('只開放管理員 IP 白名單內的位址使用；你目前的 IP 是 ' . client_ip(), true);
    }
    if (!member_can($m, $module, $level)) {
        $label = $module === 'super' ? '系統管理者' : (admin_modules()[$module] ?? $module) . ($level === 'edit' ? '（編輯）' : '（檢視）');
        render_forbidden('需要「' . $label . '」權限', true);
    }
    admin_passive_checks();
    return $m;
}

/** 寫入前的權限檢查：POST＋CSRF＋編輯權限 */
function require_perm_write(string $module): array
{
    require_csrf();
    return require_perm($module, 'edit');
}

/**
 * 後台交易：先鎖 shop_write_lock(id=1)，再鎖操作者並重新確認權限，最後由 $fn 鎖目標資料。
 */
function admin_transaction(callable $fn, string $module = 'super', string $level = 'edit')
{
    global $pdo;
    $actor = current_member();
    if (!$actor) { throw new UserError('請重新登入。'); }
    $pdo->beginTransaction();
    try {
        $lock = $pdo->query('SELECT id FROM shop_write_lock WHERE id = 1 FOR UPDATE')->fetch();
        if (!$lock) { throw new RuntimeException('shop_write_lock 缺少 id=1 的鎖定列'); }
        $st = $pdo->prepare('SELECT id, name, email, is_admin, is_super_admin, status, deleted_at, created_at
                               FROM members WHERE id = ? FOR UPDATE');
        $st->execute([$actor['id']]);
        $a = $st->fetch();
        if (!$a || (int)$a['status'] !== 1 || $a['deleted_at'] !== null) {
            throw new UserError('您的帳號狀態已變更，請重新登入。');
        }
        $a['id'] = (int)$a['id'];
        $a['is_admin'] = (int)$a['is_admin'];
        $a['is_super_admin'] = (int)$a['is_super_admin'];
        admin_perm_map($a['id'], true);
        if (!member_can($a, $module, $level) || !admin_ip_allowed()) {
            throw new UserError('您的管理權限已變更，無法執行此操作。');
        }
        $result = $fn($pdo, $a);
        $pdo->commit();
        if (function_exists('flush_deferred_mail')) { flush_deferred_mail(); }
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (function_exists('clear_deferred_mail')) { clear_deferred_mail(); }
        throw $e;
    }
}

// ---------------------------------------------------------------------
// IP 白名單（第五階段）
// ---------------------------------------------------------------------
function ip_in_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if (strpos($cidr, '/') === false) {
        $a = @inet_pton($ip);
        $b = @inet_pton($cidr);
        return $a !== false && $b !== false && $a === $b;
    }
    [$net, $bits] = explode('/', $cidr, 2);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) { return false; }
    if (!preg_match('/^[0-9]{1,3}$/', $bits)) { return false; }
    $bits = (int)$bits;
    $maxBits = strlen($ipBin) * 8;
    if ($bits > $maxBits) { return false; }
    $full = intdiv($bits, 8);
    if (substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) { return false; }
    $rem = $bits % 8;
    if ($rem === 0) { return true; }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask);
}

function valid_cidr(string $cidr): bool
{
    $cidr = trim($cidr);
    if (strpos($cidr, '/') === false) { return filter_var($cidr, FILTER_VALIDATE_IP) !== false; }
    [$net, $bits] = explode('/', $cidr, 2);
    if (!filter_var($net, FILTER_VALIDATE_IP) || !preg_match('/^[0-9]{1,3}$/', $bits)) { return false; }
    $max = filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
    return (int)$bits <= $max;
}

function admin_allowlist_active(): array
{
    global $pdo, $enable_ops;
    static $list = null;
    if (!$enable_ops) { return []; }
    if ($list === null) {
        $list = $pdo->query('SELECT cidr FROM admin_ip_allowlist WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    }
    return $list;
}

function ip_matches_list(string $ip, array $list): bool
{
    foreach ($list as $c) {
        if (ip_in_cidr($ip, $c)) { return true; }
    }
    return false;
}

function admin_ip_allowed(): bool
{
    global $disable_admin_ip_allowlist;
    if ($disable_admin_ip_allowlist) { return true; }
    $list = admin_allowlist_active();
    return !$list || ip_matches_list(client_ip(), $list);
}

// ---------------------------------------------------------------------
// 安全紀錄：同一 IP、同一類型每分鐘最多一筆（$throttle）
// ---------------------------------------------------------------------
function security_log(string $type, string $detail, bool $throttle = false): void
{
    global $pdo, $enable_ops;
    if (!$enable_ops) { return; }
    try {
        $ip = client_ip();
        if ($throttle) {
            $st = $pdo->prepare('SELECT 1 FROM security_logs WHERE ip = ? AND event_type = ? AND created_at > NOW() - INTERVAL 1 MINUTE LIMIT 1');
            $st->execute([$ip, $type]);
            if ($st->fetchColumn()) { return; }
        }
        $m = $_SESSION['member_id'] ?? null;
        $pdo->prepare('INSERT INTO security_logs (event_type, ip, member_id, path, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([$type, $ip, is_int($m) ? $m : null, mb_substr(error_clean_url((string)($_SERVER['REQUEST_URI'] ?? '')), 0, 255),
                       mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) {
        error_log('[shop] 安全紀錄寫入失敗：' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// 維護模式（第六階段）
// ---------------------------------------------------------------------
function maintenance_on(): bool
{
    global $enable_launch;
    return $enable_launch && setting_on('maintenance_enabled');
}

/** 前台入口在維護模式下回應 503；登入頁、robots.txt、模擬付款回報與後台不受影響 */
function maintenance_gate(): void
{
    if (PHP_SAPI === 'cli' || !maintenance_on()) { return; }
    $self = script_name();
    $allowed = ['login.php', 'logout.php', 'captcha.php', 'robots.php', 'pay_sim.php'];
    if (in_array($self, $allowed, true) || strpos($self, 'admin') === 0) { return; }
    if (is_staff()) { return; }
    render_maintenance_page();
}

function render_maintenance_page(): void
{
    http_response_code(503);
    $until = setting('maintenance_until');
    $retry = 3600;
    if ($until !== '' && ($ts = strtotime($until)) && $ts > time()) { $retry = max(60, $ts - time()); }
    header('Retry-After: ' . $retry);
    page_header('維護中', ['noindex' => true, 'maintenance' => true]);
    echo '<section class="card narrow"><h1>網站維護中</h1>' . plain_to_html(setting('maintenance_message'));
    if ($until !== '') { echo '<p class="muted">預計結束時間：' . h($until) . '</p>'; }
    echo '</section>';
    page_footer();
    exit;
}

/** 前台寫入在維護模式時一律拒絕（後台人員除外） */
function maintenance_block_write(): void
{
    if (maintenance_on() && !is_staff()) { render_maintenance_page(); }
}

// ---------------------------------------------------------------------
// 後台被動檢查：自動完成訂單、取消逾期未付款、清理舊紀錄（每次請求最多一次）
// ---------------------------------------------------------------------
function admin_passive_checks(): void
{
    static $done = false;
    if ($done || is_post()) { return; }
    $done = true;
    try {
        if (function_exists('orders_passive_maintenance')) { orders_passive_maintenance(); }
        if ($GLOBALS['enable_launch']) {
            require_once __DIR__ . '/cleanup.php';
            cleanup_maybe_run();
        } elseif ($GLOBALS['enable_ops']) {
            // 第五階段：開啟後台頁面時被動刪除 180 天前的寄信紀錄，每次最多 500 筆
            $GLOBALS['pdo']->exec('DELETE FROM mail_logs WHERE created_at < NOW() - INTERVAL 180 DAY LIMIT 500');
        }
    } catch (Throwable $e) {
        error_record('warning', '後台被動檢查失敗：' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    }
}
