<?php
// 封存流程 CLI 測試：php -d display_errors=1 archive_test.php <step>
chdir(dirname(__DIR__) . '/htdocs');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = 'cli.php';
require dirname(__DIR__) . '/htdocs/app.php';
require_once dirname(__DIR__) . '/htdocs/lib/behavior_archive.php';
$step = $argv[1] ?? '';
$out = [];
switch ($step) {
  case 'start':
    $out['month'] = beh_archive_start(1);
    break;
  case 'batch':
    $row = beh_archive_active();
    beh_copy_batch($row);
    $out = beh_archive_active();
    break;
  case 'run':
    try { $out['state'] = beh_archive_run(15); } catch (UserError $e) { $out['error'] = $e->getMessage(); }
    $out['row'] = $pdo->query("SELECT * FROM behavior_archive_months ORDER BY archive_month DESC LIMIT 1")->fetch();
    break;
  case 'pause':
    $out['pause'] = beh_request_pause();
    break;
  case 'resume':
    beh_archive_resume_prepare();
    $out['ok'] = true;
    break;
  case 'ensure':
    $out = beh_ensure_partitions();
    break;
  case 'until':
    $out['until'] = beh_partitions_until(BEH_MAIN);
    $out['parts'] = array_column(beh_partitions(BEH_MAIN), 'name');
    break;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
