<?php
/**
 * 備份與還原（第五階段，第 19 節）
 * 備份檔格式：gzip 壓縮的 JSON Lines（.json.gz），每行一個 JSON：
 *   {"type":"header",...}  → {"type":"table","name":...,"columns":[...]} → {"r":[...]}（每列一行）
 *   → {"type":"table_end","name":...,"rows":N,"sha256":...} → {"type":"footer","tables":{...}}
 * 每表 SHA-256 = 依主鍵排序，逐列 json_encode(值陣列) . "\n" 的雜湊；還原後以同樣方式重算核對。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const BACKUP_BATCH_SECONDS = 8.0;

function backup_dir(): string
{
    global $private_dir;
    $d = rtrim($private_dir, '/') . '/backups/';
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    return $d;
}

function backup_is_behavior(string $t): bool
{
    return $t === 'member_behavior_events' || $t === 'member_behavior_archive' || (bool)preg_match('/^member_behavior_archive_\d{6}$/', $t);
}

/** 要備份的資料表：排除 dr_／bak_ 表與備份演練紀錄；行為事件可選 */
function backup_tables(bool $withBehavior): array
{
    global $pdo;
    $st = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (preg_match('/^(dr|bak)_/', $t) || in_array($t, ['backup_drills', 'backup_drill_tables'], true)) { continue; }
        if (!$withBehavior && backup_is_behavior($t)) { continue; }
        $out[] = $t;
    }
    return $out;
}

function table_columns(string $t): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $st->execute([$t]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function table_pk(string $t): array
{
    global $pdo;
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
    $st->execute([$t]);
    $pk = $st->fetchAll(PDO::FETCH_COLUMN);
    return $pk ?: table_columns($t);
}

function qi(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) { throw new RuntimeException('資料表名稱不正確：' . $name); }
    return '`' . $name . '`';
}

/** 依主鍵分段讀取，回呼每列（值陣列） */
function table_each_row(string $t, callable $fn): void
{
    global $pdo;
    $order = implode(', ', array_map('qi', table_pk($t)));
    $cols = implode(', ', array_map('qi', table_columns($t)));
    $off = 0;
    do {
        $rows = $pdo->query("SELECT {$cols} FROM " . qi($t) . " ORDER BY {$order} LIMIT 1000 OFFSET {$off}")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) { $fn($r); }
        $off += 1000;
    } while (count($rows) === 1000);
}

function backup_row_line(array $r): string
{
    return json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** 以串流輸出備份檔 */
function backup_stream(bool $withBehavior): void
{
    global $pdo;
    @set_time_limit(0);
    $tables = backup_tables($withBehavior);
    $name = 'shop-backup-' . date('Ymd-His') . '.json.gz';
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    $gz = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    $out = function (string $line) use ($gz) { echo deflate_add($gz, $line . "\n", ZLIB_NO_FLUSH); };
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    $out(json_encode(['type' => 'header', 'app_version' => APP_VERSION, 'schema_version' => APP_SCHEMA_VERSION, 'created_at' => date('c'),
                      'db_version' => $ver, 'include_behavior' => $withBehavior, 'tables' => $tables], JSON_UNESCAPED_UNICODE));
    $summary = [];
    foreach ($tables as $t) {
        $cols = table_columns($t);
        $out(json_encode(['type' => 'table', 'name' => $t, 'columns' => $cols], JSON_UNESCAPED_UNICODE));
        $h = hash_init('sha256');
        $n = 0;
        table_each_row($t, function (array $r) use ($out, $h, &$n) {
            $line = backup_row_line($r);
            hash_update($h, $line . "\n");
            $out('{"r":' . $line . '}');
            $n++;
        });
        $sha = hash_final($h);
        $summary[$t] = ['columns' => $cols, 'rows' => $n, 'sha256' => $sha];
        $out(json_encode(['type' => 'table_end', 'name' => $t, 'rows' => $n, 'sha256' => $sha]));
        flush();
    }
    $out(json_encode(['type' => 'footer', 'tables' => $summary], JSON_UNESCAPED_UNICODE));
    echo deflate_add($gz, '', ZLIB_FINISH);
}

// ---------------------------------------------------------------------
// 隔離還原演練
// ---------------------------------------------------------------------
function drill_find(int $id): ?array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM backup_drills WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function drill_tables(int $id): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM backup_drill_tables WHERE drill_id = ? ORDER BY id');
    $st->execute([$id]);
    return $st->fetchAll();
}

/** 上傳備份檔：讀取 header，建立演練紀錄 */
function drill_upload(array $file, int $actorId): int
{
    global $pdo;
    if ($file['error'] !== UPLOAD_ERR_OK) { throw new UserError('上傳失敗（錯誤碼 ' . $file['error'] . '）；超過主機上限的備份請改用 phpMyAdmin 匯入。'); }
    $prefix = 'dr_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 3) . '_';
    $path = backup_dir() . $prefix . 'upload.json.gz';
    if (!move_uploaded_file($file['tmp_name'], $path) && !(PHP_SAPI === 'cli' && copy($file['tmp_name'], $path))) {
        throw new UserError('無法儲存上傳檔，請確認非公開目錄可寫入。');
    }
    $gz = @gzopen($path, 'rb');
    $first = $gz ? gzgets($gz, 10 * 1024 * 1024) : false;
    if ($gz) { gzclose($gz); }
    $head = $first ? json_decode($first, true) : null;
    if (!is_array($head) || ($head['type'] ?? '') !== 'header' || !is_array($head['tables'] ?? null)) {
        @unlink($path);
        throw new UserError('檔案不是本系統的備份檔。');
    }
    $match = ($head['schema_version'] ?? '') === APP_SCHEMA_VERSION ? 1 : 0;
    $pdo->prepare('INSERT INTO backup_drills (prefix, status, file_name, meta_json, schema_match, table_count, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$prefix, 'restoring', basename($path), $first, $match, count($head['tables']), $actorId]);
    return (int)$pdo->lastInsertId();
}

/**
 * 分批還原：每次請求處理一段（約 8 秒），回傳 'continue'、'verified' 或 'failed'
 */
function drill_step(int $id): string
{
    global $pdo;
    $d = drill_find($id);
    if (!$d || $d['status'] !== 'restoring') { return $d['status'] ?? 'failed'; }
    $start = microtime(true);
    $path = backup_dir() . $d['file_name'];
    $gz = @gzopen($path, 'rb');
    if (!$gz) { drill_fail($id, '找不到上傳的備份檔。'); return 'failed'; }
    if ((int)$d['file_offset'] > 0) { gzseek($gz, (int)$d['file_offset']); }
    $prefix = $d['prefix'];
    $table = $d['cursor_table'];
    $cols = null;
    $ins = null;
    $buf = [];
    $rows = (int)$d['row_count'];
    $flush = function () use (&$buf, &$ins, &$table, &$cols, $pdo, $prefix) {
        if (!$buf) { return; }
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = 'INSERT INTO ' . qi($prefix . $table) . ' (' . implode(',', array_map('qi', $cols)) . ') VALUES ' . implode(',', array_fill(0, count($buf), $ph));
        $args = [];
        foreach ($buf as $r) { foreach ($r as $v) { $args[] = $v; } }
        $pdo->prepare($sql)->execute($args);
        $buf = [];
    };
    if ($table !== null) { $cols = table_columns($prefix . $table); }
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        while (($line = gzgets($gz, 64 * 1024 * 1024)) !== false) {
            $line = rtrim($line, "\n");
            if ($line === '') { continue; }
            if (strncmp($line, '{"r":', 5) === 0) {
                $r = json_decode(substr($line, 5, -1), true);
                if (!is_array($r) || $table === null || count($r) !== count($cols)) { throw new RuntimeException('資料列格式不正確（表 ' . $table . '）'); }
                $buf[] = $r;
                $rows++;
                if (count($buf) >= 200) { $flush(); }
            } else {
                $j = json_decode($line, true);
                $type = $j['type'] ?? '';
                if ($type === 'header') { continue; }
                if ($type === 'table') {
                    $flush();
                    $t = (string)$j['name'];
                    $live = table_columns($t);
                    if (!$live) { throw new RuntimeException('目前資料庫沒有資料表 ' . $t . '（結構不同）'); }
                    if ($live !== $j['columns']) {
                        $pdo->prepare('UPDATE backup_drills SET schema_match = 0 WHERE id = ?')->execute([$id]);
                    }
                    $pdo->exec('DROP TABLE IF EXISTS ' . qi($prefix . $t));
                    $pdo->exec('CREATE TABLE ' . qi($prefix . $t) . ' LIKE ' . qi($t));
                    $table = $t;
                    $cols = $j['columns'];
                    foreach ($cols as $c) { if (!in_array($c, $live, true)) { throw new RuntimeException('欄位 ' . $t . '.' . $c . ' 不存在（結構不同）'); } }
                    $pdo->prepare('INSERT INTO backup_drill_tables (drill_id, table_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE result = \'pending\'')->execute([$id, $t]);
                } elseif ($type === 'table_end') {
                    $flush();
                    $pdo->prepare('UPDATE backup_drill_tables SET expected_rows = ?, expected_sha = ? WHERE drill_id = ? AND table_name = ?')
                        ->execute([(int)$j['rows'], (string)$j['sha256'], $id, $j['name']]);
                    $table = null;
                } elseif ($type === 'footer') {
                    $flush();
                    gzclose($gz);
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    return drill_verify($id, $rows);
                }
            }
            if (microtime(true) - $start > (float)($GLOBALS["backup_batch_seconds"] ?? BACKUP_BATCH_SECONDS)) {
                $flush();
                $off = gztell($gz);
                gzclose($gz);
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $pdo->prepare('UPDATE backup_drills SET cursor_table = ?, file_offset = ?, row_count = ? WHERE id = ?')->execute([$table, $off, $rows, $id]);
                return 'continue';
            }
        }
        throw new RuntimeException('備份檔不完整（缺少結尾）。');
    } catch (Throwable $e) {
        @gzclose($gz);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        drill_fail($id, $e->getMessage());
        return 'failed';
    }
}

function drill_fail(int $id, string $msg): void
{
    global $pdo;
    $pdo->prepare("UPDATE backup_drills SET status = 'failed', message = ? WHERE id = ?")->execute([mb_substr($msg, 0, 2000), $id]);
}

/** 逐表核對筆數、欄位與 SHA-256 */
function drill_verify(int $id, int $rows): string
{
    global $pdo;
    $d = drill_find($id);
    $ok = true;
    foreach (drill_tables($id) as $t) {
        $h = hash_init('sha256');
        $n = 0;
        table_each_row($d['prefix'] . $t['table_name'], function (array $r) use ($h, &$n) { hash_update($h, backup_row_line($r) . "\n"); $n++; });
        $sha = hash_final($h);
        $res = ($n === (int)$t['expected_rows'] && $t['expected_sha'] !== null && hash_equals($t['expected_sha'], $sha)) ? 'ok' : 'mismatch';
        if ($res !== 'ok') { $ok = false; }
        $pdo->prepare('UPDATE backup_drill_tables SET actual_rows = ?, actual_sha = ?, result = ? WHERE id = ?')->execute([$n, $sha, $res, $t['id']]);
    }
    $pdo->prepare('UPDATE backup_drills SET status = ?, row_count = ?, cursor_table = NULL, message = ? WHERE id = ?')
        ->execute([$ok ? 'verified' : 'failed', $rows, $ok ? '全部資料表驗證通過' : '部分資料表筆數或 SHA-256 不符（備份檔可能遭修改或不完整）', $id]);
    return $ok ? 'verified' : 'failed';
}

function drill_drop_tables(int $id): int
{
    global $pdo;
    $d = drill_find($id);
    if (!$d) { return 0; }
    $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?");
    $st->execute([str_replace('_', '\\_', $d['prefix']) . '%']);
    $n = 0;
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec('DROP TABLE ' . qi($t)); $n++; }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    if ($d['status'] !== 'applied') { $pdo->prepare("UPDATE backup_drills SET status = 'dropped' WHERE id = ?")->execute([$id]); }
    @unlink(backup_dir() . $d['file_name']);
    return $n;
}

// ---------------------------------------------------------------------
// 套用為正式資料（決策 26）：單一 RENAME TABLE 原子交換，交換後補建外鍵並檢查，失敗時換回
// ---------------------------------------------------------------------
function db_foreign_keys(array $tables): array
{
    global $pdo;
    if (!$tables) { return []; }
    $in = implode(',', array_fill(0, count($tables), '?'));
    $st = $pdo->prepare("SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION,
                                r.UPDATE_RULE, r.DELETE_RULE
                           FROM information_schema.KEY_COLUMN_USAGE k
                           JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                          WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME IN ({$in})
                          ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION");
    $st->execute($tables);
    $fks = [];
    foreach ($st->fetchAll() as $r) {
        $key = $r['TABLE_NAME'] . '|' . $r['CONSTRAINT_NAME'];
        $fks[$key]['table'] = $r['TABLE_NAME'];
        $fks[$key]['name'] = $r['CONSTRAINT_NAME'];
        $fks[$key]['ref'] = $r['REFERENCED_TABLE_NAME'];
        $fks[$key]['cols'][] = $r['COLUMN_NAME'];
        $fks[$key]['refcols'][] = $r['REFERENCED_COLUMN_NAME'];
        $fks[$key]['upd'] = $r['UPDATE_RULE'];
        $fks[$key]['del'] = $r['DELETE_RULE'];
    }
    return array_values($fks);
}

function fk_drop_all(string $table): void
{
    global $pdo;
    foreach (db_foreign_keys([$table]) as $fk) { $pdo->exec('ALTER TABLE ' . qi($table) . ' DROP FOREIGN KEY ' . qi($fk['name'])); }
}

function fk_add(array $fk): void
{
    global $pdo;
    $pdo->exec('ALTER TABLE ' . qi($fk['table']) . ' ADD CONSTRAINT ' . qi($fk['name']) . ' FOREIGN KEY (' . implode(',', array_map('qi', $fk['cols'])) . ')'
        . ' REFERENCES ' . qi($fk['ref']) . ' (' . implode(',', array_map('qi', $fk['refcols'])) . ') ON UPDATE ' . $fk['upd'] . ' ON DELETE ' . $fk['del']);
}

function fk_orphans(array $fk): int
{
    global $pdo;
    $on = [];
    $nn = [];
    foreach ($fk['cols'] as $i => $c) {
        $on[] = 'p.' . qi($fk['refcols'][$i]) . ' = c.' . qi($c);
        $nn[] = 'c.' . qi($c) . ' IS NOT NULL';
    }
    return (int)$pdo->query('SELECT COUNT(*) FROM ' . qi($fk['table']) . ' c LEFT JOIN ' . qi($fk['ref']) . ' p ON ' . implode(' AND ', $on)
        . ' WHERE ' . implode(' AND ', $nn) . ' AND p.' . qi($fk['refcols'][0]) . ' IS NULL')->fetchColumn();
}

/** 交換：$pairs = [正式表 => 來源表]，正式表改名為 $bak . 表名 */
function swap_tables(array $tables, string $from, string $bak): void
{
    global $pdo;
    $parts = [];
    foreach ($tables as $t) {
        $parts[] = qi($t) . ' TO ' . qi($bak . $t);
        $parts[] = qi($from . $t) . ' TO ' . qi($t);
    }
    $pdo->exec('RENAME TABLE ' . implode(', ', $parts));
}

function drill_apply(int $id, int $actorId): string
{
    global $pdo;
    $d = drill_find($id);
    if (!$d || $d['status'] !== 'verified') { throw new UserError('只有驗證通過的演練可以套用。'); }
    if ((int)$d['schema_match'] !== 1) { throw new UserError('備份的資料表結構與目前程式版本不同，不能套用。'); }
    $tables = array_column(drill_tables($id), 'table_name');
    foreach (drill_tables($id) as $t) { if ($t['result'] !== 'ok') { throw new UserError('有資料表未通過驗證。'); } }
    $bak = 'bak_' . date('Ymd_His') . '_';
    $wasMaint = setting('maintenance_enabled');
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('maintenance_enabled', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
    $fks = db_foreign_keys($tables);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        swap_tables($tables, $d['prefix'], $bak);
        foreach ($tables as $t) { fk_drop_all($bak . $t); }
        foreach ($fks as $fk) { fk_add($fk); }
        $bad = [];
        foreach ($fks as $fk) { if (($n = fk_orphans($fk)) > 0) { $bad[] = $fk['table'] . '.' . $fk['name'] . '：' . $n . ' 筆'; } }
        if ($bad) {
            foreach ($tables as $t) { fk_drop_all($t); }
            $parts = [];
            foreach ($tables as $t) { $parts[] = qi($t) . ' TO ' . qi($d['prefix'] . $t); $parts[] = qi($bak . $t) . ' TO ' . qi($t); }
            $pdo->exec('RENAME TABLE ' . implode(', ', $parts));
            foreach ($fks as $fk) { fk_add($fk); }
            throw new UserError('外鍵檢查失敗，已換回原資料：' . implode('；', $bad));
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        // 恢復維護模式設定：正式表與保留的 bak_ 表都要恢復，換回時才不會停在維護模式
        foreach (['site_settings', $bak . 'site_settings'] as $t) {
            if (table_columns($t)) {
                $pdo->prepare('INSERT INTO ' . qi($t) . " (setting_key, setting_value) VALUES ('maintenance_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                    ->execute([$wasMaint === '1' ? '1' : '0']);
            }
        }
    }
    $pdo->prepare("UPDATE backup_drills SET status = 'applied', bak_prefix = ?, message = ? WHERE id = ?")
        ->execute([$bak, '已套用為正式資料；原資料保留為 ' . $bak . '*', $id]);
    // 套用後的資料庫裡，操作者帳號可能已不同；異動紀錄寫在新資料中
    try { audit_log($pdo, null, 'backup', $id, 'apply', null, ['bak_prefix' => $bak, 'actor' => $actorId]); } catch (Throwable $e) {}
    return $bak;
}

/** 換回：把 bak_ 表換回正式表（目前的正式表改名為 dr_ 前綴保留） */
function drill_revert(int $id): void
{
    global $pdo;
    $d = drill_find($id);
    if (!$d || $d['status'] !== 'applied' || !$d['bak_prefix']) { throw new UserError('此演練沒有可換回的備份表。'); }
    $tables = array_column(drill_tables($id), 'table_name');
    foreach ($tables as $t) { if (!table_columns($d['bak_prefix'] . $t)) { throw new UserError('找不到保留的 ' . $d['bak_prefix'] . $t . '。'); } }
    $fks = db_foreign_keys($tables);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $t) { fk_drop_all($t); }
        $parts = [];
        foreach ($tables as $t) { $parts[] = qi($t) . ' TO ' . qi($d['prefix'] . $t); $parts[] = qi($d['bak_prefix'] . $t) . ' TO ' . qi($t); }
        $pdo->exec('RENAME TABLE ' . implode(', ', $parts));
        foreach ($fks as $fk) { fk_add($fk); }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    $pdo->prepare("UPDATE backup_drills SET status = 'verified', bak_prefix = NULL, message = '已換回套用前的資料' WHERE id = ?")->execute([$id]);
}
