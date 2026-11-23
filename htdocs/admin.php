<?php
/**
 * 管理總覽（第 23 節）：只顯示有檢視權限的模組卡片；數字每次開頁即時查詢；圖表由伺服器產生 SVG，旁附數字表格。
 * 金額以訂單總額計，取消與拒收不計入；日期以台灣時間分日。開啟時順便執行被動檢查（自動完成、清除舊紀錄）。
 */
require_once __DIR__ . '/app.php';
if (!$enable_ops) { redirect('admin_products.php'); }
$GLOBALS['IN_ADMIN'] = true;
$me = current_member();
if (!$me) { render_forbidden('需要管理者登入', false); }
if (!is_staff()) {
    if ($me['is_admin'] === 1 && !admin_ip_allowed()) {
        security_log('ip_denied', '後台 IP 不在白名單：admin.php', true);
        render_forbidden('只開放管理員 IP 白名單內的位址使用；你目前的 IP 是 ' . client_ip(), true);
    }
    render_forbidden('需要管理者權限', true);
}
header('X-Robots-Tag: noindex, nofollow');
admin_passive_checks();

$q1 = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
};

/** 長條圖（SVG） */
function svg_bars(array $data, string $label): string
{
    $max = max(1, max($data ?: [1]));
    $w = 360; $bh = 26; $hgt = count($data) * ($bh + 8) + 8;
    $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="' . h($label) . '">';
    $y = 8;
    foreach ($data as $k => $v) {
        $bw = (int)round(($w - 140) * $v / $max);
        $out .= '<text x="0" y="' . ($y + 18) . '" class="chart-label">' . h($k) . '</text>'
            . '<rect x="70" y="' . $y . '" width="' . max(1, $bw) . '" height="' . $bh . '" rx="3" class="chart-bar"></rect>'
            . '<text x="' . (76 + $bw) . '" y="' . ($y + 18) . '" class="chart-value">' . (int)$v . '</text>';
        $y += $bh + 8;
    }
    return $out . '</svg>';
}

/** 折線圖（SVG）：$series = [名稱 => [日期 => 數值]] */
function svg_lines(array $series, array $days, string $label): string
{
    $w = 720; $hgt = 220; $pl = 40; $pb = 24; $pt = 10;
    $max = 1;
    foreach ($series as $s) { $max = max($max, max($s ?: [0])); }
    $n = max(1, count($days) - 1);
    $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="' . h($label) . '">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $pt + ($hgt - $pt - $pb) * $i / 4;
        $out .= '<line x1="' . $pl . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" class="chart-grid"></line>'
            . '<text x="0" y="' . ($y + 4) . '" class="chart-axis">' . (int)round($max * (4 - $i) / 4) . '</text>';
    }
    $ci = 0;
    foreach ($series as $name => $s) {
        $pts = [];
        foreach ($days as $i => $d) {
            $x = $pl + ($w - $pl - 10) * $i / $n;
            $y = $pt + ($hgt - $pt - $pb) * (1 - ($s[$d] ?? 0) / $max);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $out .= '<polyline points="' . implode(' ', $pts) . '" class="chart-line chart-line-' . $ci++ . '" fill="none"></polyline>';
    }
    foreach ([0, (int)floor(count($days) / 2), count($days) - 1] as $i) {
        $x = $pl + ($w - $pl - 10) * $i / $n;
        $out .= '<text x="' . round($x) . '" y="' . ($hgt - 6) . '" class="chart-axis" text-anchor="middle">' . h(substr($days[$i], 5)) . '</text>';
    }
    return $out . '</svg>';
}

$cards = [];
admin_header('管理總覽');
echo '<h1>管理總覽</h1><p class="muted">' . h($me['name']) . '，您好。以下數字為即時查詢（' . date('Y-m-d H:i') . '）。</p><div class="dashboard">';

if (is_super()) {
    $tips = [];
    if (!public_info_ready()) { $tips[] = '<a href="admin_contents.php">公開營運者名稱或客服聯絡方式尚未填寫（結帳已停用）</a>'; }
    if ($enable_behavior) {
        require_once __DIR__ . '/lib/behavior_archive.php';
        try {
            $until = beh_partitions_until(BEH_MAIN);
            $need = date('Y-m', strtotime('first day of +6 month'));
            if ($until === null || strcmp($until, $need) < 0) { $tips[] = '<a href="admin_behavior_maintenance.php">行為事件分區只準備到 ' . h($until ?? '—') . '，請「檢查並補建月份」</a>'; }
            $cand = beh_archive_candidates();
            if ($cand) { $tips[] = '<a href="admin_behavior_maintenance.php">有 ' . count($cand) . ' 個月份可以封存</a>'; }
        } catch (Throwable $e) {
            $tips[] = '行為分區檢查失敗：' . h($e->getMessage());
        }
    }
    $mf = (int)$q1("SELECT COUNT(*) FROM mail_logs WHERE result = 'failed' AND created_at > NOW() - INTERVAL 7 DAY");
    if ($mf) { $tips[] = '<a href="admin_mail_logs.php?result=failed">近 7 天寄信失敗 ' . $mf . ' 筆</a>'; }
    if ($enable_launch) {
        $er = (int)$q1('SELECT COUNT(*) FROM error_logs WHERE last_seen > NOW() - INTERVAL 1 DAY AND resolved_at IS NULL');
        if ($er) { $tips[] = '<a href="admin_error_logs.php">近 24 小時有 ' . $er . ' 種新錯誤</a>'; }
        if (maintenance_on()) { $tips[] = '<a href="admin_system.php">維護模式開啟中</a>'; }
    }
    echo '<section class="card dash-card"><h2>系統提醒</h2>' . ($tips ? '<ul><li>' . implode('</li><li>', $tips) . '</li></ul>' : '<p class="muted">目前沒有需要處理的提醒。</p>') . '</section>';
}

if (admin_can('member')) {
    $total = (int)$q1('SELECT COUNT(*) FROM members WHERE deleted_at IS NULL');
    $new30 = (int)$q1('SELECT COUNT(*) FROM members WHERE deleted_at IS NULL AND created_at >= CURDATE() - INTERVAL 29 DAY');
    $pending = (int)$q1('SELECT COUNT(*) FROM members WHERE deleted_at IS NULL AND status = 2');
    $dist = $pdo->query('SELECT member_level, COUNT(*) FROM members WHERE deleted_at IS NULL GROUP BY member_level')->fetchAll(PDO::FETCH_KEY_PAIR);
    $bars = [];
    foreach (member_levels() as $k => $l) { $bars[$l] = (int)($dist[$k] ?? 0); }
    $del = $enable_launch ? (int)$q1("SELECT COUNT(*) FROM account_deletion_requests WHERE status = 'pending'") : 0;
    echo '<section class="card dash-card"><h2><a href="admin_members.php">會員</a></h2><p class="big-num">' . $total . '</p>'
        . '<p>近 30 天新增 ' . $new30 . '　待驗證 ' . $pending . ($del ? '　<a href="admin_members.php?status=deletion">刪除申請 ' . $del . '</a>' : '') . '</p>'
        . svg_bars($bars, '各等級會員人數') . '<table class="sr-table"><caption>各等級人數</caption><tbody>';
    foreach ($bars as $k => $v) { echo '<tr><th>' . h($k) . '</th><td>' . $v . '</td></tr>'; }
    echo '</tbody></table></section>';
}

if (admin_can('product')) {
    $active = (int)$q1('SELECT COUNT(*) FROM products WHERE is_active = 1');
    $out = (int)$q1('SELECT COUNT(*) FROM products p WHERE p.is_active = 1 AND NOT EXISTS (SELECT 1 FROM product_variants v JOIN variant_inventory vi ON vi.variant_id = v.id
                     WHERE v.product_id = p.id AND v.is_active = 1 AND vi.quantity > 0)');
    $low = $pdo->query('SELECT p.id, p.name, v.sku, vi.quantity FROM product_variants v JOIN products p ON p.id = v.product_id JOIN variant_inventory vi ON vi.variant_id = v.id
                         WHERE p.is_active = 1 AND v.is_active = 1 AND vi.quantity <= 5 ORDER BY vi.quantity, p.id LIMIT 10')->fetchAll();
    echo '<section class="card dash-card"><h2><a href="admin_products.php">商品</a></h2><p class="big-num">' . $active . '</p><p>上架中　缺貨 ' . $out . '</p>'
        . '<h3>低庫存（≦ 5）</h3>' . ($low ? '<ul>' . implode('', array_map(function ($r) {
            return '<li><a href="admin_inventory.php?q=' . urlencode($r['sku']) . '">' . h($r['name']) . '（' . h($r['sku']) . '）</a>：' . (int)$r['quantity'] . '</li>';
        }, $low)) . '</ul><p><a href="admin_inventory.php?low=1">查看全部</a></p>' : '<p class="muted">無</p>') . '</section>';
}

if ($enable_orders && admin_can('order')) {
    $valid = "status NOT IN ('cancelled','refused')";
    $today = $pdo->query("SELECT COUNT(*), COALESCE(SUM(total),0) FROM orders WHERE {$valid} AND created_at >= CURDATE()")->fetch(PDO::FETCH_NUM);
    $d30 = $pdo->query("SELECT COUNT(*), COALESCE(SUM(total),0) FROM orders WHERE {$valid} AND created_at >= CURDATE() - INTERVAL 29 DAY")->fetch(PDO::FETCH_NUM);
    $by = $pdo->query('SELECT status, COUNT(*) FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    echo '<section class="card dash-card"><h2><a href="admin_orders.php">訂單</a></h2><p>今日 <strong>' . (int)$today[0] . '</strong> 筆、' . money($today[1])
        . '<br>近 30 天 <strong>' . (int)$d30[0] . '</strong> 筆、' . money($d30[1]) . '</p><table class="mini"><tbody>';
    foreach (order_status_labels() as $k => $l) { echo '<tr><th><a href="admin_orders.php?status=' . $k . '">' . h($l) . '</a></th><td>' . (int)($by[$k] ?? 0) . '</td></tr>'; }
    echo '</tbody></table></section>';
    $as = $pdo->query("SELECT CONCAT(type, ':', status) k, COUNT(*) FROM after_sales WHERE status IN ('requested','in_progress') GROUP BY type, status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $req = (int)($as['return:requested'] ?? 0) + (int)($as['exchange:requested'] ?? 0);
    echo '<section class="card dash-card"><h2><a href="admin_after_sales.php">退換貨</a></h2><table class="mini"><tbody>'
        . '<tr><th><a href="admin_after_sales.php?status=requested">申請中</a></th><td>' . $req . '</td></tr>'
        . '<tr><th><a href="admin_after_sales.php?type=return&amp;status=in_progress">退貨中</a></th><td>' . (int)($as['return:in_progress'] ?? 0) . '</td></tr>'
        . '<tr><th><a href="admin_after_sales.php?type=exchange&amp;status=in_progress">換貨中</a></th><td>' . (int)($as['exchange:in_progress'] ?? 0) . '</td></tr></tbody></table></section>';
}

if ($enable_orders && admin_can('shipping')) {
    $s = $pdo->query("SELECT status, COUNT(*) FROM shipments WHERE status IN ('pending','on_hold','arrived') GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $r = (int)$q1("SELECT COUNT(*) FROM return_receipts WHERE status = 'pending'");
    echo '<section class="card dash-card"><h2><a href="admin_shipments.php">出貨與收件</a></h2><table class="mini"><tbody>'
        . '<tr><th><a href="admin_shipments.php?status=pending">待出貨</a></th><td>' . (int)($s['pending'] ?? 0) . '</td></tr>'
        . '<tr><th><a href="admin_shipments.php?status=on_hold">暫緩</a></th><td>' . (int)($s['on_hold'] ?? 0) . '</td></tr>'
        . ($enable_launch ? '<tr><th><a href="admin_shipments.php?status=arrived">已到店待取</a></th><td>' . (int)($s['arrived'] ?? 0) . '</td></tr>' : '')
        . '<tr><th><a href="admin_receipts.php">待收件</a></th><td>' . $r . '</td></tr></tbody></table></section>';
}

if (admin_can('support')) {
    $by = $pdo->query("SELECT status, COUNT(*) FROM support_messages WHERE status IN ('new','in_progress') GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $oldest = $q1("SELECT MIN(created_at) FROM support_messages WHERE status = 'new'");
    $wait = '';
    if ($oldest) {
        $sec = time() - strtotime($oldest);
        $wait = '最舊未處理已等待 ' . ($sec >= 86400 ? floor($sec / 86400) . ' 天 ' : '') . floor(($sec % 86400) / 3600) . ' 小時';
    }
    echo '<section class="card dash-card"><h2><a href="admin_messages.php">訊息</a></h2><table class="mini"><tbody>'
        . '<tr><th><a href="admin_messages.php?status=new">未處理</a></th><td>' . (int)($by['new'] ?? 0) . '</td></tr>'
        . '<tr><th><a href="admin_messages.php?status=in_progress">處理中</a></th><td>' . (int)($by['in_progress'] ?? 0) . '</td></tr></tbody></table>'
        . ($wait ? '<p class="muted">' . h($wait) . '</p>' : '') . '</section>';
}
echo '</div>';

if ($enable_behavior && admin_can('report')) {
    require_once __DIR__ . '/lib/behavior_archive.php';
    $from = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $sources = ['`member_behavior_events`', '`member_behavior_archive`'];
    foreach (beh_archive_months() as $m) {
        if ($m['archive_month'] >= substr($from, 0, 7) && beh_table_exists($m['table_name'])) { $sources[] = '`' . $m['table_name'] . '`'; }
    }
    $union = implode(' UNION ', array_map(function ($t) {
        return "SELECT event_id, event_time, event_type FROM {$t} WHERE event_time >= ? AND event_type IN ('view_product','add_to_cart')";
    }, $sources));
    $st = $pdo->prepare("SELECT DATE(event_time) d, event_type, COUNT(*) n FROM ({$union}) x GROUP BY d, event_type");
    $st->execute(array_fill(0, count($sources), $from));
    $days = [];
    for ($i = 29; $i >= 0; $i--) { $days[] = date('Y-m-d', strtotime("-{$i} days")); }
    $series = ['瀏覽次數' => [], '加入購物車次數' => []];
    foreach ($st->fetchAll() as $r) { $series[$r['event_type'] === 'view_product' ? '瀏覽次數' : '加入購物車次數'][$r['d']] = (int)$r['n']; }
    echo '<section class="card"><h2><a href="admin_behavior_reports.php">瀏覽行為曲線（近 30 天）</a></h2>'
        . '<p class="legend"><span class="key key-0"></span>瀏覽次數　<span class="key key-1"></span>加入購物車次數</p>'
        . svg_lines($series, $days, '近 30 天每日瀏覽與加入購物車次數')
        . '<details><summary>數字表格</summary><div class="table-wrap"><table><thead><tr><th>日期</th><th>瀏覽</th><th>加入購物車</th></tr></thead><tbody>';
    foreach ($days as $d) { echo '<tr><td>' . $d . '</td><td>' . (int)($series['瀏覽次數'][$d] ?? 0) . '</td><td>' . (int)($series['加入購物車次數'][$d] ?? 0) . '</td></tr>'; }
    echo '</tbody></table></div></details></section>';
}
page_footer();
