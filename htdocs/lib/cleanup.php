<?php
/**
 * 資料清理（第六階段，第 35 節）：管理員開啟後台時被動執行，每小時最多一次、每項每次限量
 * 不清理訂單、售後、異動紀錄與行為事件（行為事件由第三階段封存處理）
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const CLEANUP_LIMIT = 500;

function cleanup_tasks(): array
{
    return [
        'carts' => ['長期沒動的購物車', 'retain_cart_days'],
        'sessions' => ['過期 Session', null],
        'security_logs' => ['安全紀錄', 'retain_security_days'],
        'error_logs' => ['錯誤紀錄', 'retain_error_days'],
        'mail_logs' => ['寄信紀錄', 'retain_mail_days'],
        'payments' => ['未成功的付款嘗試', 'retain_payment_days'],
        'drills' => ['備份演練的 dr_ 表', 'retain_drill_days'],
    ];
}

function cleanup_maybe_run(): void
{
    global $pdo;
    $st = $pdo->query("SELECT MAX(started_at) FROM cleanup_runs");
    $last = $st->fetchColumn();
    if ($last && strtotime($last) > time() - 3600) { return; }
    cleanup_run_all();
}

function cleanup_run_all(): array
{
    $out = [];
    foreach (array_keys(cleanup_tasks()) as $t) { $out[$t] = cleanup_run($t); }
    return $out;
}

function cleanup_run(string $task): array
{
    global $pdo;
    $started = date('Y-m-d H:i:s');
    $n = 0;
    $result = 'ok';
    $msg = null;
    try {
        $days = fn_days($task);
        switch ($task) {
            case 'carts':
                $st = $pdo->prepare("SELECT ci.id FROM carts c JOIN cart_items ci ON ci.cart_id = c.id WHERE c.updated_at < NOW() - INTERVAL {$days} DAY LIMIT " . CLEANUP_LIMIT);
                $st->execute();
                $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    db_transaction(function (PDO $pdo) use ($in, $ids) {
                        $pdo->prepare("UPDATE cart_price_lots SET remaining_quantity = 0 WHERE cart_item_id IN ({$in})")->execute($ids);
                        $pdo->prepare("DELETE FROM cart_items WHERE id IN ({$in})")->execute($ids);
                    });
                    $n = count($ids);
                }
                break;
            case 'sessions':
                $path = session_save_path();
                if (!empty($GLOBALS['session_save_path']) && is_dir($path)) {
                    $max = (int)ini_get('session.gc_maxlifetime');
                    foreach ((array)glob(rtrim($path, '/') . '/sess_*') as $f) {
                        if ($n >= CLEANUP_LIMIT * 4) { break; }
                        if (is_file($f) && filemtime($f) < time() - $max && $f !== rtrim($path, '/') . '/sess_' . session_id()) { @unlink($f) && $n++; }
                    }
                } else {
                    $msg = '未設定 $session_save_path（請在 config.php 設定），由主機的 PHP 內建機制清理';
                }
                break;
            case 'security_logs':
            case 'error_logs':
            case 'mail_logs':
                $col = $task === 'error_logs' ? 'last_seen' : 'created_at';
                $n = $pdo->exec("DELETE FROM {$task} WHERE {$col} < NOW() - INTERVAL {$days} DAY LIMIT " . CLEANUP_LIMIT);
                break;
            case 'payments':
                $n = $pdo->exec("DELETE FROM payments WHERE status IN ('created','failed','cancelled') AND created_at < NOW() - INTERVAL {$days} DAY LIMIT " . CLEANUP_LIMIT);
                break;
            case 'drills':
                require_once __DIR__ . '/backup.php';
                $st = $pdo->query("SELECT id FROM backup_drills WHERE status IN ('verified','failed','restoring','uploaded') AND created_at < NOW() - INTERVAL {$days} DAY LIMIT 5");
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $n += drill_drop_tables((int)$id); }
                break;
        }
    } catch (Throwable $e) {
        $result = 'failed';
        $msg = mb_substr($e->getMessage(), 0, 500);
    }
    $pdo->prepare('INSERT INTO cleanup_runs (task, started_at, finished_at, deleted_count, result, message) VALUES (?, ?, NOW(), ?, ?, ?)')
        ->execute([$task, $started, (int)$n, $result, $msg]);
    return ['count' => (int)$n, 'result' => $result, 'message' => $msg];
}

function fn_days(string $task): int
{
    $key = cleanup_tasks()[$task][1] ?? null;
    return $key ? max(1, setting_int($key, 30)) : 0;
}
