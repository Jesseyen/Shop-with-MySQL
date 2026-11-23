<?php
/**
 * 第三階段：行為報表查詢（六項商品排行與事件明細）
 * 資料來源依「線上主表 → 舊共用封存表 → 月份表」的順序，按 (event_id, event_time) 排除重複。
 * 由 admin_behavior_reports.php 呼叫。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }
require_once __DIR__ . '/behavior_archive.php';

function beh_event_types(): array
{
    return ['view_product' => '瀏覽商品', 'add_to_cart' => '加入購物車', 'update_cart' => '修改數量', 'remove_from_cart' => '移出購物車'];
}

/** 從 GET 解析條件；回傳 [filters, errors]。與目前模式無關的欄位一律忽略。 */
function beh_report_parse(array $in): array
{
    $errors = [];
    $f = [];
    $today = date('Y-m-d');
    $f['date_from'] = is_string($in['date_from'] ?? null) ? $in['date_from'] : date('Y-m-d', strtotime('-29 days'));
    $f['date_to'] = is_string($in['date_to'] ?? null) ? $in['date_to'] : $today;
    $df = DateTimeImmutable::createFromFormat('!Y-m-d', $f['date_from']);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $f['date_to']);
    if (!$df || $df->format('Y-m-d') !== $f['date_from']) { $errors[] = '起日格式不正確。'; }
    if (!$dt || $dt->format('Y-m-d') !== $f['date_to']) { $errors[] = '訖日格式不正確。'; }
    if ($df && $dt && $df > $dt) { $errors[] = '起日不能晚於訖日。'; }
    if ($df && $dt && $df->diff($dt)->days > 1100) { $errors[] = '查詢期間最長約 3 年。'; }

    $f['category'] = ($in['category'] ?? '') === '' ? null : to_pos_int($in['category']);
    if (($in['category'] ?? '') !== '' && $f['category'] === null) { $errors[] = '分類不正確。'; }

    $f['source'] = in_array($in['source'] ?? '', ['online', 'archive', 'both'], true) ? $in['source'] : 'both';

    $f['member_mode'] = in_array($in['member_mode'] ?? '', ['all', 'specific', 'level'], true) ? $in['member_mode'] : 'all';
    $f['member_q'] = '';
    $f['level_basis'] = 'current';
    $f['levels'] = [];
    if ($f['member_mode'] === 'specific') {
        $f['member_q'] = is_string($in['member_q'] ?? null) ? mb_substr(trim($in['member_q']), 0, 50) : '';
        if ($f['member_q'] === '') { $errors[] = '請輸入會員姓名或編號。'; }
    } elseif ($f['member_mode'] === 'level') {
        $f['level_basis'] = ($in['level_basis'] ?? '') === 'historical' ? 'historical' : 'current';
        $allowed = array_keys(member_levels());
        if ($f['level_basis'] === 'historical') { $allowed[] = 'unknown'; }
        foreach ((array)($in['levels'] ?? []) as $l) {
            if (is_string($l) && in_array($l, $allowed, true)) { $f['levels'][$l] = $l; }
        }
        $f['levels'] = array_values($f['levels']);
        if (!$f['levels']) { $errors[] = '「會員等級」模式至少要勾選一個等級。'; }
    }

    $types = [];
    $rawTypes = $in['types'] ?? (isset($in['run']) ? [] : array_keys(beh_event_types()));
    foreach ((array)$rawTypes as $t) {
        if (is_string($t) && isset(beh_event_types()[$t])) { $types[$t] = $t; }
    }
    $f['types'] = array_values($types);
    if (!$f['types']) { $errors[] = '至少要選擇一種行為類型。'; }

    $f['net_mode'] = in_array($in['net_mode'] ?? '', ['positive', 'all', 'negative'], true) ? $in['net_mode'] : 'positive';
    $f['page'] = to_pos_int($in['page'] ?? '1', 100000) ?? 1;
    return [$f, $errors];
}

/** 組合來源 UNION（已去重）與篩選；回傳 [sql, params, notes] */
function beh_report_source(array $f): array
{
    global $pdo;
    $from = $f['date_from'] . ' 00:00:00';
    $to = (new DateTimeImmutable($f['date_to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    $cols = implode(', ', array_map(function ($c) { return "s.`{$c}`"; }, beh_cols()));
    $notes = [];

    // 篩選條件（套用到每個來源，便於使用索引）
    $cond = ['s.event_time >= ?', 's.event_time < ?'];
    $condArgs = [$from, $to];
    $cond[] = 's.event_type IN (' . implode(',', array_fill(0, count($f['types']), '?')) . ')';
    array_push($condArgs, ...$f['types']);
    if ($f['category'] !== null) { $cond[] = 's.category_id_at_event = ?'; $condArgs[] = $f['category']; }
    if ($f['member_mode'] === 'specific') {
        $like = '%' . addcslashes($f['member_q'], '%_\\') . '%';
        if (ctype_digit($f['member_q'])) {
            $cond[] = 's.member_id IN (SELECT mm.id FROM members mm WHERE mm.id = ? OR mm.name LIKE ?)';
            array_push($condArgs, (int)$f['member_q'], $like);
        } else {
            $cond[] = 's.member_id IN (SELECT mm.id FROM members mm WHERE mm.name LIKE ?)';
            $condArgs[] = $like;
        }
    } elseif ($f['member_mode'] === 'level') {
        $lv = array_values(array_filter($f['levels'], function ($l) { return $l !== 'unknown'; }));
        if ($f['level_basis'] === 'current') {
            $cond[] = 's.member_id IN (SELECT mm.id FROM members mm WHERE mm.member_level IN (' . implode(',', array_fill(0, count($lv), '?')) . '))';
            array_push($condArgs, ...$lv);
        } else {
            $or = [];
            if ($lv) {
                $or[] = 's.member_level_at_event IN (' . implode(',', array_fill(0, count($lv), '?')) . ')';
                array_push($condArgs, ...$lv);
            }
            if (in_array('unknown', $f['levels'], true)) { $or[] = 's.member_level_at_event IS NULL'; }
            $cond[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    $condSql = implode(' AND ', $cond);

    $parts = [];
    $args = [];
    $useOnline = $f['source'] !== 'archive';
    $useArchive = $f['source'] !== 'online';
    $sharedExists = beh_table_exists(BEH_SHARED);

    if ($useOnline) {
        $parts[] = "SELECT {$cols} FROM `" . BEH_MAIN . "` s WHERE {$condSql}";
        array_push($args, ...$condArgs);
    }
    // 只查線上、但期間涵蓋已封存月份時提示
    $registered = beh_archive_months();
    foreach ($registered as $r) {
        [$ms, $me] = beh_month_range($r['archive_month']);
        $overlap = $ms < $to && $me > $from;
        if (!$overlap) { continue; }
        if (!$useArchive && in_array($r['status'], ['dropping', 'completed'], true)) {
            $notes[] = "查詢期間涵蓋已封存的 {$r['archive_month']}，目前只查線上資料，該月份資料不會列入。請將「資料來源」改為「封存」或「兩者合併」。";
        }
    }
    if ($useArchive) {
        if ($sharedExists) {
            $sql = "SELECT {$cols} FROM `" . BEH_SHARED . "` s WHERE {$condSql}";
            array_push($args, ...$condArgs);
            if ($useOnline) {
                $sql .= ' AND NOT EXISTS (SELECT 1 FROM `' . BEH_MAIN . '` x WHERE x.event_id = s.event_id AND x.event_time = s.event_time)';
            }
            $parts[] = $sql;
        }
        foreach ($registered as $r) {
            [$ms, $me] = beh_month_range($r['archive_month']);
            if (!($ms < $to && $me > $from)) { continue; }   // 只讀取指定期間的月份
            if (!beh_table_exists($r['table_name'])) {
                throw new UserError("已登記的封存月份表 {$r['table_name']}（{$r['archive_month']}）不存在，請檢查資料庫後再查詢。");
            }
            $sql = "SELECT {$cols} FROM `{$r['table_name']}` s WHERE {$condSql}";
            array_push($args, ...$condArgs);
            if ($useOnline) {
                $sql .= ' AND NOT EXISTS (SELECT 1 FROM `' . BEH_MAIN . '` x WHERE x.event_id = s.event_id AND x.event_time = s.event_time)';
            }
            if ($sharedExists) {
                $sql .= ' AND NOT EXISTS (SELECT 1 FROM `' . BEH_SHARED . '` y WHERE y.event_id = s.event_id AND y.event_time = s.event_time)';
            }
            $parts[] = $sql;
        }
    }
    if (!$parts) {
        // 沒有任何來源：回傳空集合
        $parts[] = "SELECT {$cols} FROM `" . BEH_MAIN . "` s WHERE 1 = 0";
    }
    return ['(' . implode(') UNION ALL (', $parts) . ')', $args, $notes];
}

/** 執行報表：回傳六項排行、明細與統計 */
function beh_report_run(array $f): array
{
    global $pdo;
    if (!beh_lock(5)) {
        throw new UserError('行為資料維護作業正在進行，請稍後再查詢。');
    }
    try {
        [$src, $args, $notes] = beh_report_source($f);
        $U = "({$src}) ev";
        $q = function (string $sql, array $extra = []) use ($pdo, $args) {
            $st = $pdo->prepare($sql);
            $st->execute(array_merge($args, $extra));
            return $st->fetchAll();
        };

        $summary = $q("SELECT ev.event_type, COUNT(*) AS n FROM {$U} GROUP BY ev.event_type");
        $origins = $q("SELECT ev.origin, COUNT(*) AS n FROM {$U} GROUP BY ev.origin");

        $top = ' ORDER BY v DESC, product_id ASC LIMIT 10';
        $rank = [];
        $rank['views'] = $q("SELECT ev.product_id, COUNT(*) AS v FROM {$U} WHERE ev.event_type = 'view_product'
                             GROUP BY ev.product_id HAVING v > 0" . $top);
        $rank['sessions'] = $q("SELECT ev.product_id, COUNT(DISTINCT ev.session_key) AS v FROM {$U} WHERE ev.event_type = 'view_product'
                                GROUP BY ev.product_id HAVING v > 0" . $top);
        $rank['adds'] = $q("SELECT ev.product_id, COUNT(*) AS v FROM {$U} WHERE ev.event_type = 'add_to_cart'
                            GROUP BY ev.product_id HAVING v > 0" . $top);
        $rank['removes'] = $q("SELECT ev.product_id, COUNT(*) AS v FROM {$U} WHERE ev.event_type = 'remove_from_cart'
                               GROUP BY ev.product_id HAVING v > 0" . $top);

        $netHaving = ['positive' => ' HAVING v > 0', 'negative' => ' HAVING v < 0', 'all' => ''][$f['net_mode']];
        $cartTypes = "ev.event_type IN ('add_to_cart','update_cart','remove_from_cart')";
        $rank['net_qty'] = $q("SELECT ev.product_id,
                    SUM(COALESCE(ev.quantity_delta, CAST(ev.quantity_after AS SIGNED) - CAST(ev.quantity_before AS SIGNED))) AS v
                FROM {$U} WHERE {$cartTypes} GROUP BY ev.product_id{$netHaving}" . $top);
        $amountHaving = $netHaving === '' ? ' HAVING SUM(ev.amount_delta IS NULL) = 0'
                                          : $netHaving . ' AND SUM(ev.amount_delta IS NULL) = 0';
        $rank['net_amount'] = $q("SELECT ev.product_id, SUM(ev.amount_delta) AS v FROM {$U} WHERE {$cartTypes}
                GROUP BY ev.product_id{$amountHaving}" . $top);
        $unknown = $q("SELECT COUNT(*) AS events, COUNT(DISTINCT ev.product_id) AS products FROM {$U}
                       WHERE {$cartTypes} AND ev.amount_delta IS NULL")[0];

        // 商品名稱
        $ids = [];
        foreach ($rank as $list) { foreach ($list as $r) { $ids[(int)$r['product_id']] = true; } }
        $names = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, name, sku FROM products WHERE id IN ({$ph})");
            $st->execute(array_keys($ids));
            foreach ($st->fetchAll() as $p) { $names[(int)$p['id']] = $p; }
        }

        // 明細
        $perPage = 50;
        $total = (int)$q("SELECT COUNT(*) AS n FROM {$U}")[0]['n'];
        $offset = ($f['page'] - 1) * $perPage;
        $details = $q("SELECT ev.*, m.name AS member_name, p.name AS product_name, c.name AS category_name
                         FROM {$U}
                         LEFT JOIN members m ON m.id = ev.member_id
                         LEFT JOIN products p ON p.id = ev.product_id
                         LEFT JOIN categories c ON c.id = ev.category_id_at_event
                        ORDER BY ev.event_time DESC, ev.event_id DESC
                        LIMIT {$perPage} OFFSET {$offset}");

        return compact('summary', 'origins', 'rank', 'unknown', 'names', 'details', 'total', 'perPage', 'notes');
    } finally {
        beh_unlock();
    }
}
