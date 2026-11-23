<?php
/**
 * 錯誤紀錄（第六階段）
 * - 未捕捉例外、PHP 錯誤與警告寫入 error_logs；同一訊息＋檔案＋行號只累加次數
 * - 使用獨立的資料庫連線寫入，避免被主要交易回復
 * - 資料庫無法連線時改寫入非公開目錄的檔案
 * - 網址去除憑證類參數；不記錄表單內容
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function errors_register(): void
{
    set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0) {
        if (!(error_reporting() & $no)) { return false; }
        $levels = [E_WARNING => 'warning', E_NOTICE => 'notice', E_USER_ERROR => 'error', E_USER_WARNING => 'warning',
                   E_USER_NOTICE => 'notice', E_DEPRECATED => 'deprecated', E_USER_DEPRECATED => 'deprecated'];
        error_record($levels[$no] ?? 'error', $msg, $file, $line, '');
        return false; // 交給 PHP 預設處理（正式環境不顯示）
    });
    set_exception_handler('error_handle_exception');
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_record('fatal', $e['message'], $e['file'], (int)$e['line'], '');
        }
    });
}

function error_handle_exception(Throwable $e): void
{
    global $app_debug, $pdo;
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $x) {}
    }
    if ($e instanceof UserError && function_exists('render_error_page') && !headers_sent()) {
        render_error_page(400, '無法完成操作', $e->getMessage());
    }
    error_log('[shop] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    error_record('exception', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    if (!headers_sent()) { http_response_code(500); }
    $detail = $app_debug ? '<pre>' . h($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>' : '';
    if (function_exists('page_header') && !headers_sent() && isset($pdo)) {
        try {
            page_header('系統錯誤');
            echo '<section class="card"><h1>系統暫時發生錯誤</h1><p>請稍後再試。如果問題持續，請聯絡網站管理員。</p>' . $detail
                . '<p><a class="btn" href="index.php">返回商場</a></p></section>';
            page_footer();
            exit;
        } catch (Throwable $x) {
        }
    }
    echo '<!doctype html><meta charset="utf-8"><h1>系統暫時發生錯誤</h1><p>請稍後再試。</p>' . $detail;
    exit;
}

/** 去除網址中的憑證類參數 */
function error_clean_url(string $uri): string
{
    $parts = explode('?', $uri, 2);
    if (count($parts) < 2) { return mb_substr($uri, 0, 500); }
    parse_str($parts[1], $q);
    foreach ($q as $k => $v) {
        if (preg_match('/token|password|csrf|captcha|sig|secret|key/i', (string)$k)) { $q[$k] = '[已遮蔽]'; }
    }
    return mb_substr($parts[0] . '?' . http_build_query($q), 0, 500);
}

function error_private_file(): string
{
    global $private_dir;
    if (!is_dir($private_dir)) { @mkdir($private_dir, 0700, true); }
    return rtrim($private_dir, '/') . '/error_fallback.log';
}

/** 寫入錯誤紀錄（失敗時改寫檔案，絕不拋出例外） */
function error_record(string $level, string $message, string $file, int $line, string $trace): void
{
    global $db_host, $db_port, $db_name, $db_user, $db_pass, $enable_launch;
    static $busy = false, $epdo = null;
    if ($busy) { return; }
    $busy = true;
    try {
        $root = dirname(__DIR__);
        $file = str_replace($root, '', $file);
        $message = mb_substr($message, 0, 2000);
        $trace = mb_substr(str_replace($root, '', $trace), 0, 8000);
        $url = error_clean_url((string)($_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')));
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI');
        $member = isset($_SESSION['member_id']) && is_int($_SESSION['member_id']) ? $_SESSION['member_id'] : null;
        $ip = function_exists('client_ip') ? client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$enable_launch) { $busy = false; return; }
        try {
            if ($epdo === null) {
                $epdo = new PDO("mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $epdo->exec("SET time_zone = '+08:00'");
            }
            $fp = hash('sha256', $message . '|' . $file . '|' . $line);
            $st = $epdo->prepare('INSERT INTO error_logs (fingerprint, level, message, file, line, url, method, member_id, ip, trace)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen = NOW(), url = VALUES(url),
                                       method = VALUES(method), member_id = VALUES(member_id), ip = VALUES(ip),
                                       trace = VALUES(trace), level = VALUES(level), resolved_at = NULL');
            $st->execute([$fp, $level, $message, $file, $line, $url, $method, $member, $ip, $trace]);
        } catch (Throwable $dbErr) {
            $row = json_encode(['t' => date('Y-m-d H:i:s'), 'level' => $level, 'message' => $message, 'file' => $file,
                'line' => $line, 'url' => $url, 'method' => $method, 'member' => $member, 'ip' => $ip], JSON_UNESCAPED_UNICODE);
            @file_put_contents(error_private_file(), $row . "\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $x) {
    }
    $busy = false;
}
