<?php
/**
 * 第三階段：每月分區、封存、核對與移除
 *
 * 流程（每月一張獨立 InnoDB 表 member_behavior_archive_YYYYMM）：
 *   copying    每批最多 200 筆，由舊到新依 (event_time, event_id) 游標複製；先線上主表、再舊共用封存表
 *   verifying  核對筆數、內容與月份範圍
 *   drop_ready 移除前再核對一次
 *   dropping   移除來源：分區只含該月資料時 DROP PARTITION，否則分批 DELETE
 *   completed  完成
 *   failed     發生衝突或核對不符：停止並保留來源，禁止覆寫，last_error 記錄原因
 * 每一步都寫回 behavior_archive_months，中斷後可從目前狀態接續（含 DDL 之後中斷）。
 * 維護頁與報表共用資料庫命名鎖 shop_behavior_maint。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const BEH_MAIN = 'member_behavior_events';
const BEH_SHARED = 'member_behavior_archive';
const BEH_LOCK = 'shop_behavior_maint';
const BEH_BATCH = 200;
const BEH_MONTHS_AHEAD = 6;

function beh_cols(): array
{
    return ['event_id', 'event_time', 'event_type', 'session_key', 'origin', 'legacy_view_id', 'member_id',
            'member_level_at_event', 'product_id', 'category_id_at_event', 'category_origin', 'quantity_before',
            'quantity_after', 'quantity_delta', 'unit_price', 'amount_delta', 'cart_id', 'cart_item_id'];
}

function beh_lock(int $timeout = 0): bool
{
    global $pdo;
    $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $st->execute([BEH_LOCK, $timeout]);
    return (string)$st->fetchColumn() === '1';
}

function beh_unlock(): void
{
    global $pdo;
    $pdo->prepare('DO RELEASE_LOCK(?)')->execute([BEH_LOCK]);
}

function beh_table_exists(string $table): bool
{
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function beh_month_table(string $month): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) { throw new RuntimeException('月份格式錯誤'); }
    return 'member_behavior_archive_' . $m[1] . $m[2];
}

function beh_month_range(string $month): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('Asia/Taipei'));
    return [$start->format('Y-m-d 00:00:00'), $start->modify('+1 month')->format('Y-m-d 00:00:00')];
}

function beh_current_month_start(): string
{
    return date('Y-m-01 00:00:00');
}

/** 分區清單：[['name'=>, 'bound'=>'Y-m-d H:i:s' 或 'MAXVALUE', 'rows'=>估計筆數]] */
function beh_partitions(string $table): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT PARTITION_NAME, PARTITION_DESCRIPTION, TABLE_ROWS FROM information_schema.PARTITIONS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
                          ORDER BY PARTITION_ORDINAL_POSITION');
    $st->execute([$table]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $b = trim((string)$r['PARTITION_DESCRIPTION'], "' ");
        $out[] = ['name' => $r['PARTITION_NAME'], 'bound' => $b, 'rows' => (int)$r['TABLE_ROWS']];
    }
    return $out;
}

/** 分區已準備到哪個月（最後一個月份分區涵蓋的月份 YYYY-MM），無分區時回傳 null */
function beh_partitions_until(string $table): ?string
{
    $last = null;
    foreach (beh_partitions($table) as $p) {
        if ($p['bound'] !== 'MAXVALUE') { $last = $p['bound']; }
    }
    if ($last === null) { return null; }
    return (new DateTimeImmutable($last))->modify('-1 month')->format('Y-m');
}

/**
 * 檢查並補建月份分區：以 REORGANIZE PARTITION p_future 補到「本月 + 6 個月」，
 * p_future 內既有資料會被重新分配到新分區，不會遺失。
 * 回傳 [table => [新增的分區名稱...]]
 */
function beh_ensure_partitions(): array
{
    global $pdo;
    $target = (new DateTimeImmutable(beh_current_month_start()))->modify('+' . (BEH_MONTHS_AHEAD + 1) . ' month');
    $result = [];
    foreach ([BEH_MAIN, BEH_SHARED] as $table) {
        if (!beh_table_exists($table)) { continue; }
        $parts = beh_partitions($table);
        if (!$parts) { throw new UserError("資料表 {$table} 沒有分區，請先執行第三階段建表 SQL。"); }
        $hasFuture = false;
        $lastBound = null;
        foreach ($parts as $p) {
            if ($p['bound'] === 'MAXVALUE') { $hasFuture = $p['name']; } else { $lastBound = $p['bound']; }
        }
        if ($hasFuture === false || $lastBound === null) {
            throw new UserError("資料表 {$table} 缺少 p_future 預備分區或月份分區，無法自動補建。");
        }
        $cursor = new DateTimeImmutable($lastBound);
        $defs = [];
        $names = [];
        while ($cursor < $target) {
            $next = $cursor->modify('+1 month');
            $name = 'p' . $cursor->format('Ym');
            $defs[] = "PARTITION {$name} VALUES LESS THAN ('" . $next->format('Y-m-d 00:00:00') . "')";
            $names[] = $name;
            $cursor = $next;
        }
        if ($defs) {
            $defs[] = "PARTITION {$hasFuture} VALUES LESS THAN (MAXVALUE)";
            $pdo->exec("ALTER TABLE `{$table}` REORGANIZE PARTITION `{$hasFuture}` INTO (" . implode(', ', $defs) . ')');
        }
        $result[$table] = $names;
    }
    return $result;
}

function beh_create_month_table(string $table): void
{
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
      event_id              BIGINT UNSIGNED NOT NULL,
      event_time            DATETIME(6) NOT NULL,
      event_type            ENUM('view_product','add_to_cart','update_cart','remove_from_cart') NOT NULL,
      session_key           CHAR(32) NULL,
      origin                ENUM('native','legacy_view','partition_test') NOT NULL DEFAULT 'native',
      legacy_view_id        BIGINT UNSIGNED NULL,
      member_id             INT UNSIGNED NOT NULL,
      member_level_at_event ENUM('regular','silver','gold','diamond') NULL,
      product_id            INT UNSIGNED NOT NULL,
      category_id_at_event  INT UNSIGNED NULL,
      category_origin       ENUM('snapshot','backfill','unknown') NOT NULL DEFAULT 'snapshot',
      quantity_before       INT UNSIGNED NULL,
      quantity_after        INT UNSIGNED NULL,
      quantity_delta        INT NULL,
      unit_price            DECIMAL(10,2) NULL,
      amount_delta          DECIMAL(30,2) NULL,
      cart_id               INT UNSIGNED NULL,
      cart_item_id          INT UNSIGNED NULL,
      PRIMARY KEY (event_id, event_time),
      KEY idx_time (event_time),
      KEY idx_member_time (member_id, event_time),
      KEY idx_product_type_time (product_id, event_type, event_time),
      KEY idx_category_time (category_id_at_event, event_time),
      KEY idx_session (session_key),
      KEY idx_legacy (legacy_view_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** 舊批次表相容性檢查：仍有未結束的舊工作時拒絕封存 */
function beh_legacy_jobs_running(): int
{
    global $pdo;
    if (!beh_table_exists('member_behavior_maintenance_jobs')) { return 0; }
    return (int)$pdo->query("SELECT COUNT(*) FROM member_behavior_maintenance_jobs
                              WHERE status NOT IN ('done','completed','failed','cancelled')")->fetchColumn();
}

function beh_archive_active(): ?array
{
    global $pdo;
    $r = $pdo->query("SELECT * FROM behavior_archive_months WHERE status <> 'completed' ORDER BY archive_month LIMIT 1")->fetch();
    return $r ?: null;
}

function beh_archive_months(): array
{
    global $pdo;
    return $pdo->query('SELECT * FROM behavior_archive_months ORDER BY archive_month')->fetchAll();
}

/** 可封存的月份：已結束（早於本月）、線上或舊共用封存表仍有資料、且尚未完成封存 */
function beh_archive_candidates(): array
{
    global $pdo;
    $cur = beh_current_month_start();
    $sql = "SELECT DATE_FORMAT(event_time, '%Y-%m') AS m, COUNT(*) AS n FROM `" . BEH_MAIN . "` WHERE event_time < ? GROUP BY m";
    $args = [$cur];
    if (beh_table_exists(BEH_SHARED)) {
        $sql .= " UNION ALL SELECT DATE_FORMAT(event_time, '%Y-%m') AS m, COUNT(*) AS n FROM `" . BEH_SHARED . "` WHERE event_time < ? GROUP BY m";
        $args[] = $cur;
    }
    $st = $pdo->prepare("SELECT m, SUM(n) AS n FROM ({$sql}) x GROUP BY m ORDER BY m");
    $st->execute($args);
    $done = [];
    foreach (beh_archive_months() as $r) { if ($r['status'] === 'completed') { $done[$r['archive_month']] = true; } }
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!isset($done[$r['m']])) { $out[] = ['month' => $r['m'], 'rows' => (int)$r['n']]; }
    }
    return $out;
}

function beh_set_status(string $month, array $fields): void
{
    global $pdo;
    $sets = [];
    $args = [];
    foreach ($fields as $k => $v) {
        if ($k === 'completed_at_now') { $sets[] = 'completed_at = NOW()'; continue; }
        $sets[] = "`{$k}` = ?";
        $args[] = $v;
    }
    $args[] = $month;
    $pdo->prepare('UPDATE behavior_archive_months SET ' . implode(', ', $sets) . ' WHERE archive_month = ?')->execute($args);
}

function beh_record_error(string $month, string $message, bool $fail): void
{
    $f = ['last_error' => mb_substr(date('Y-m-d H:i:s') . ' ' . $message, 0, 2000)];
    if ($fail) { $f['status'] = 'failed'; }
    beh_set_status($month, $f);
}

/** 來源（線上 ∪ 舊共用封存）在該月的不重複事件數 */
function beh_source_distinct_count(string $month): int
{
    global $pdo;
    [$s, $e] = beh_month_range($month);
    $sql = 'SELECT event_id, event_time FROM `' . BEH_MAIN . '` WHERE event_time >= ? AND event_time < ?';
    $args = [$s, $e];
    if (beh_table_exists(BEH_SHARED)) {
        $sql .= ' UNION SELECT event_id, event_time FROM `' . BEH_SHARED . '` WHERE event_time >= ? AND event_time < ?';
        array_push($args, $s, $e);
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM ({$sql}) x");
    $st->execute($args);
    return (int)$st->fetchColumn();
}

/** 開始封存最舊的可處理月份 */
function beh_archive_start(int $operatorId): string
{
    global $pdo;
    if (beh_archive_active()) { throw new UserError('已有進行中的封存，請使用「接續封存」。'); }
    if (beh_legacy_jobs_running() > 0) { throw new UserError('舊批次表 member_behavior_maintenance_jobs 仍有未結束的工作，請先處理。'); }
    $cands = beh_archive_candidates();
    if (!$cands) { throw new UserError('無須封存：沒有已結束且仍有線上資料的月份。'); }
    $month = $cands[0]['month'];
    $table = beh_month_table($month);
    $st = $pdo->prepare('SELECT status FROM behavior_archive_months WHERE archive_month = ?');
    $st->execute([$month]);
    if ($st->fetch()) { throw new UserError("{$month} 已有封存紀錄，請先檢查狀態。"); }
    if (beh_table_exists($table)) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($n > 0) {
            throw new UserError("月份表 {$table} 已存在且有 {$n} 筆資料，但沒有封存登記。為避免覆寫已停止，請人工核對。");
        }
    }
    beh_create_month_table($table);
    $expected = beh_source_distinct_count($month);
    $pdo->prepare('INSERT INTO behavior_archive_months (archive_month, table_name, status, expected_count, operator_id)
                   VALUES (?, ?, \'copying\', ?, ?)')->execute([$month, $table, $expected, $operatorId]);
    return $month;
}

/** 複製一批（最多 200 筆）；來源複製完畢時推進游標來源或狀態 */
function beh_copy_batch(array $row): void
{
    global $pdo;
    $month = $row['archive_month'];
    $table = $row['table_name'];
    [$s, $e] = beh_month_range($month);
    $source = (int)$row['cursor_source'];
    $srcTable = $source === 0 ? BEH_MAIN : BEH_SHARED;
    if ($source === 1 && !beh_table_exists(BEH_SHARED)) {
        beh_set_status($month, ['status' => 'verifying']);
        return;
    }
    $cols = beh_cols();
    $colSql = implode(', ', $cols);
    $where = 'event_time >= ? AND event_time < ?';
    $args = [$s, $e];
    if ($row['cursor_time'] !== null) {
        $where .= ' AND (event_time > ? OR (event_time = ? AND event_id > ?))';
        array_push($args, $row['cursor_time'], $row['cursor_time'], $row['cursor_event_id']);
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM behavior_archive_months WHERE archive_month = ? FOR UPDATE');
        $lock->execute([$month]);
        $fresh = $lock->fetch();
        if (!$fresh || $fresh['status'] !== 'copying' || (int)$fresh['cursor_source'] !== $source
            || $fresh['cursor_time'] !== $row['cursor_time'] || $fresh['cursor_event_id'] !== $row['cursor_event_id']) {
            $pdo->rollBack();
            return; // 狀態已被其他請求推進
        }
        $st = $pdo->prepare("SELECT {$colSql} FROM `{$srcTable}` WHERE {$where} ORDER BY event_time, event_id LIMIT " . BEH_BATCH);
        $st->execute($args);
        $rows = $st->fetchAll();
        if (!$rows) {
            if ($source === 0) {
                beh_set_status($month, ['cursor_source' => 1, 'cursor_time' => null, 'cursor_event_id' => null]);
            } else {
                beh_set_status($month, ['status' => 'verifying']);
            }
            $pdo->commit();
            return;
        }
        // 比對月份表中已存在的相同複合主鍵
        $keyPh = implode(',', array_fill(0, count($rows), '(?, ?)'));
        $keyArgs = [];
        foreach ($rows as $r) { $keyArgs[] = $r['event_id']; $keyArgs[] = $r['event_time']; }
        $ex = $pdo->prepare("SELECT {$colSql} FROM `{$table}` WHERE (event_id, event_time) IN ({$keyPh})");
        $ex->execute($keyArgs);
        $existing = [];
        foreach ($ex->fetchAll() as $r) { $existing[$r['event_id'] . '|' . $r['event_time']] = $r; }
        $toInsert = [];
        foreach ($rows as $r) {
            $k = $r['event_id'] . '|' . $r['event_time'];
            if (isset($existing[$k])) {
                if ($existing[$k] !== $r) {
                    $pdo->rollBack();
                    beh_record_error($month, "衝突：事件 {$r['event_id']}（{$r['event_time']}）在月份表與來源 {$srcTable} 的內容不同，已停止並保留來源。", true);
                    throw new UserError("封存 {$month} 發生衝突：事件 {$r['event_id']} 內容不一致，已停止並保留來源資料。");
                }
                continue; // 相同內容（例如中斷後重跑或主表與舊共用表重複）不重複寫入
            }
            $toInsert[] = $r;
        }
        if ($toInsert) {
            $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $sql = "INSERT INTO `{$table}` ({$colSql}) VALUES " . implode(',', array_fill(0, count($toInsert), $ph));
            $vals = [];
            foreach ($toInsert as $r) { foreach ($cols as $c) { $vals[] = $r[$c]; } }
            $pdo->prepare($sql)->execute($vals);
        }
        $last = end($rows);
        $copied = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        beh_set_status($month, ['cursor_time' => $last['event_time'], 'cursor_event_id' => $last['event_id'], 'copied_count' => $copied]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $ex;
    }
}

/** 核對：回傳 null 表示通過，否則回傳不符原因 */
function beh_verify(array $row): ?string
{
    global $pdo;
    $month = $row['archive_month'];
    $table = $row['table_name'];
    if (!beh_table_exists($table)) { return "月份表 {$table} 不存在。"; }
    [$s, $e] = beh_month_range($month);
    $archived = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE event_time < ? OR event_time >= ?");
    $st->execute([$s, $e]);
    if ((int)$st->fetchColumn() > 0) { return '月份表含有不屬於該月份的資料。'; }
    $source = beh_source_distinct_count($month);
    if ($source !== (int)$row['expected_count']) {
        return "來源筆數在處理期間變動（開始時 {$row['expected_count']}、目前 {$source}），請停止人工回補後重新處理。";
    }
    if ($archived !== $source) { return "筆數不符：月份表 {$archived} 筆、來源 {$source} 筆。"; }
    $match = implode(' AND ', array_map(function ($c) { return "a.`{$c}` <=> s.`{$c}`"; }, beh_cols()));
    foreach ([BEH_MAIN, BEH_SHARED] as $src) {
        if (!beh_table_exists($src)) { continue; }
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$src}` s WHERE s.event_time >= ? AND s.event_time < ?
                               AND NOT EXISTS (SELECT 1 FROM `{$table}` a WHERE {$match})");
        $st->execute([$s, $e]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) { return "{$src} 有 {$n} 筆事件在月份表找不到完全相同的內容。"; }
    }
    return null;
}

/** 移除來源：分區只含該月資料時 DROP PARTITION，否則分批 DELETE。可重複執行。 */
function beh_drop_sources(array $row, float $deadline): bool
{
    global $pdo;
    $month = $row['archive_month'];
    [$s, $e] = beh_month_range($month);
    $pname = 'p' . str_replace('-', '', $month);
    foreach ([BEH_MAIN, BEH_SHARED] as $src) {
        if (!beh_table_exists($src)) { continue; }
        $hasPartition = false;
        foreach (beh_partitions($src) as $p) { if ($p['name'] === $pname) { $hasPartition = true; } }
        if ($hasPartition) {
            $inPart = (int)$pdo->query("SELECT COUNT(*) FROM `{$src}` PARTITION (`{$pname}`)")->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM `{$src}` PARTITION (`{$pname}`) WHERE event_time >= ? AND event_time < ?");
            $st->execute([$s, $e]);
            if ($inPart === (int)$st->fetchColumn()) {
                // 該分區只有此月份資料：直接移除分區（DDL，自動提交）
                $pdo->exec("ALTER TABLE `{$src}` DROP PARTITION `{$pname}`");
                continue;
            }
        }
        $del = $pdo->prepare("DELETE FROM `{$src}` WHERE event_time >= ? AND event_time < ? ORDER BY event_time, event_id LIMIT 1000");
        do {
            $del->execute([$s, $e]);
            $n = $del->rowCount();
            if ($n > 0 && microtime(true) > $deadline) { return false; }
        } while ($n > 0);
    }
    return true;
}

/**
 * 執行封存直到完成、暫停、失敗或時間用完。回傳狀態說明字串。
 * 呼叫前須取得命名鎖。
 */
function beh_archive_run(float $seconds = 15.0): string
{
    global $pdo;
    $deadline = microtime(true) + $seconds;
    while (true) {
        $row = beh_archive_active();
        if (!$row) { return 'idle'; }
        $month = $row['archive_month'];
        if ($row['status'] === 'failed') { return 'failed'; }
        if ((int)$row['pause_requested'] === 1) {
            return 'paused';   // 保持暫停，直到按「接續封存」
        }
        if (microtime(true) > $deadline) { return 'continue'; }
        try {
            switch ($row['status']) {
                case 'copying':
                    beh_copy_batch($row);
                    break;
                case 'verifying':
                    $why = beh_verify($row);
                    if ($why !== null) { beh_record_error($month, '核對不符：' . $why, true); return 'failed'; }
                    beh_set_status($month, ['status' => 'drop_ready', 'last_error' => null]);
                    break;
                case 'drop_ready':
                    $why = beh_verify($row);
                    if ($why !== null) { beh_record_error($month, '移除前核對不符：' . $why, true); return 'failed'; }
                    beh_set_status($month, ['status' => 'dropping']);
                    break;
                case 'dropping':
                    if (beh_drop_sources($row, $deadline)) {
                        $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$row['table_name']}`")->fetchColumn();
                        beh_set_status($month, ['status' => 'completed', 'copied_count' => $n, 'completed_at_now' => true,
                                                'cursor_time' => null, 'cursor_event_id' => null, 'last_error' => null]);
                        return 'completed';
                    }
                    break;
                default:
                    return 'unknown';
            }
        } catch (UserError $e) {
            throw $e;
        } catch (Throwable $e) {
            beh_record_error($month, get_class($e) . ': ' . $e->getMessage(), false);
            throw new UserError("封存 {$month} 時發生錯誤，進度已保留，可稍後按「接續封存」。原因已寫入封存紀錄。");
        }
    }
}

function beh_request_pause(): bool
{
    global $pdo;
    $st = $pdo->prepare("UPDATE behavior_archive_months SET pause_requested = 1 WHERE status NOT IN ('completed','failed')");
    $st->execute();
    return $st->rowCount() > 0;
}

/** 接續：清除暫停旗標；失敗狀態回到可重試的步驟（衝突仍會再次停止） */
function beh_archive_resume_prepare(): void
{
    global $pdo;
    $row = beh_archive_active();
    if (!$row) { throw new UserError('沒有進行中的封存。'); }
    $f = ['pause_requested' => 0];
    if ($row['status'] === 'failed') {
        // failed 只會發生在移除來源之前（複製衝突或核對不符），重試時重新計算來源筆數
        // 從頭重新複製（相同內容會略過，不會重複寫入）
        $f['status'] = 'copying';
        $f['cursor_source'] = 0;
        $f['cursor_time'] = null;
        $f['cursor_event_id'] = null;
        $f['expected_count'] = beh_source_distinct_count($row['archive_month']);
    }
    beh_set_status($row['archive_month'], $f);
}
