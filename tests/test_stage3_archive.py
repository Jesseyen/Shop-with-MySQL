#!/usr/bin/env python3
"""分區、封存、合併查詢測試"""
import json, subprocess, sys, re, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib import Client, db, check, results, BASE
from bs4 import BeautifulSoup

# 準備資料：9 月與 10 月各補 260 筆事件（範例資料只有近 45 天）
for month, days in (('2026-09', 30), ('2026-10', 31)):
    db(f"""INSERT INTO member_behavior_events (event_time, event_type, session_key, member_id, member_level_at_event, product_id, category_id_at_event,
                 quantity_before, quantity_after, quantity_delta, unit_price, amount_delta)
          WITH RECURSIVE seq AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM seq WHERE n < 260)
          SELECT TIMESTAMP('{month}-01') + INTERVAL (n * 9973) MOD ({days} * 86400) SECOND,
                 IF(n % 4 = 0, 'add_to_cart', 'view_product'), LPAD(HEX(n), 32, '0'), 5 + n % 3, 'regular', 1 + n % 7, 1 + n % 3,
                 IF(n % 4 = 0, 0, NULL), IF(n % 4 = 0, 1, NULL), IF(n % 4 = 0, 1, NULL), IF(n % 4 = 0, 100, NULL), IF(n % 4 = 0, 100, NULL)
            FROM seq""")

def cli(step):
    out = subprocess.run(['php', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'archive_test.php'), step], capture_output=True, text=True)
    lines = [l for l in out.stdout.strip().splitlines() if l.startswith('{') or l.startswith('[')]
    return json.loads(lines[-1]) if lines else {'raw': out.stdout + out.stderr}

def kpi(html):
    soup = BeautifulSoup(html, 'html.parser')
    return ' '.join(d.get_text(' ', strip=True) for d in soup.select('.kpi div'))

def ranks(html):
    soup = BeautifulSoup(html, 'html.parser')
    return [[tr.get_text('|', strip=True) for tr in sec.select('tbody tr')] for sec in soup.select('.rank-grid section')]

adm = Client(); adm.login('admin@example.com')
SEP = 'admin_behavior_reports.php?run=1&date_from=2026-09-01&date_to=2026-09-30&net_mode=all&types[]=view_product&types[]=add_to_cart&types[]=update_cart&types[]=remove_from_cart&source='
OCT = 'admin_behavior_reports.php?run=1&date_from=2026-10-01&date_to=2026-10-31&net_mode=all&types[]=view_product&types[]=add_to_cart&types[]=update_cart&types[]=remove_from_cart&source='
ALL = 'admin_behavior_reports.php?run=1&date_from=2026-09-01&date_to=2026-11-20&net_mode=all&types[]=view_product&types[]=add_to_cart&types[]=update_cart&types[]=remove_from_cart&source='

sep_before = adm.get(SEP + 'both').text
all_before = adm.get(ALL + 'both').text
sep_rows = int(db("SELECT COUNT(*) FROM member_behavior_events WHERE event_time >= '2026-09-01' AND event_time < '2026-10-01'"))
oct_rows = int(db("SELECT COUNT(*) FROM member_behavior_events WHERE event_time >= '2026-10-01' AND event_time < '2026-11-01'"))

# 補建分區：先合併 p202704、p202705 到 p_future，並放一筆 2027-04 測試資料在 p_future
db("ALTER TABLE member_behavior_events REORGANIZE PARTITION p202704, p202705, p_future INTO (PARTITION p_future VALUES LESS THAN (MAXVALUE))")
db("INSERT INTO member_behavior_events (event_time, event_type, origin, member_id, product_id) VALUES ('2027-04-15 10:00:00', 'view_product', 'partition_test', 5, 1)")
check('補建前分區只到 2027-03', cli('until')['until'] == '2027-03')
r = adm.post('admin_behavior_maintenance.php', {'op': 'ensure'}, src='admin_behavior_maintenance.php')
check('檢查並補建月份', '已補建分區' in r.text and 'p202704' in r.text and cli('until')['until'] == '2027-05', cli('until'))
check('補建保留預備分區資料', db("SELECT COUNT(*) FROM member_behavior_events PARTITION (p202704) WHERE origin='partition_test'") == '1')
db("DELETE FROM member_behavior_events WHERE origin='partition_test'")
r = adm.post('admin_behavior_maintenance.php', {'op': 'ensure'}, src='admin_behavior_maintenance.php')
check('分區足夠時不補建', '無須補建' in r.text)

# 舊共用封存表放 2 筆 9 月資料：1 筆與主表重複（相同內容）、1 筆只存在舊表
db("""INSERT INTO member_behavior_archive SELECT * FROM member_behavior_events WHERE event_time >= '2026-09-01' AND event_time < '2026-10-01' ORDER BY event_id LIMIT 1""")
db("""INSERT INTO member_behavior_archive (event_id, event_time, event_type, session_key, origin, member_id, member_level_at_event, product_id, category_id_at_event)
      VALUES (900001, '2026-09-15 12:00:00.000000', 'view_product', 'legacysharedsession0000000000001', 'native', 5, 'regular', 2, 1)""")
sep_total = sep_rows + 1

# 開始封存（9 月）
r = adm.post('admin_behavior_maintenance.php', {'op': 'start'}, src='admin_behavior_maintenance.php')
row = db("SELECT status, expected_count, copied_count FROM behavior_archive_months WHERE archive_month='2026-09'")
check('9 月封存完成且筆數一致（含舊共用表整併、去重）', row == f'completed\t{sep_total}\t{sep_total}', row)
check('月份表實際筆數', db("SELECT COUNT(*) FROM member_behavior_archive_202609") == str(sep_total))
check('主表 9 月資料已移除', db("SELECT COUNT(*) FROM member_behavior_events WHERE event_time < '2026-10-01'") == '0')
check('p202609 分區已 DROP', 'p202609' not in cli('until')['parts'])
check('舊共用表 9 月資料已整併移除', db("SELECT COUNT(*) FROM member_behavior_archive WHERE event_time < '2026-10-01'") == '0')
check('月份表保留索引', int(db("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='shop' AND TABLE_NAME='member_behavior_archive_202609'")) == 7)

# 封存前後統計一致（加上舊共用表那 1 筆瀏覽）
sep_after = adm.get(SEP + 'both').text
k_before, k_after = kpi(sep_before), kpi(sep_after)
check('封存後合併查詢可查到 9 月資料', ranks(sep_after)[0] != [] and '瀏覽商品' in k_after, k_after)
nb = int(re.search(r'瀏覽商品 ([\d,]+)', k_before).group(1).replace(',', ''))
na = int(re.search(r'瀏覽商品 ([\d,]+)', k_after).group(1).replace(',', ''))
check('封存前後統計一致（+1 筆舊共用表事件）', na == nb + 1, (nb, na))
check('購物車排行封存前後一致', ranks(sep_before)[2:] == ranks(sep_after)[2:])
r = adm.get(SEP + 'online')
check('只查線上但涵蓋已封存月份時提示', '已封存的 2026-09' in r.text)
r = adm.get(SEP + 'archive'); check('只查封存可查到', kpi(r.text) == k_after)

# 10 月：暫停、衝突、接續
out = cli('start'); check('開始 10 月封存', out.get('month') == '2026-10', out)
out = cli('batch'); check('複製一批 200 筆', int(out['copied_count']) == 200, out)
oct_mid = adm.get(OCT + 'both').text
check('複製期間合併查詢不重算', re.search(r'瀏覽商品 ([\d,]+)', kpi(oct_mid)).group(1) ==
      str(int(db("SELECT COUNT(*) FROM member_behavior_events WHERE event_type='view_product' AND event_time >= '2026-10-01' AND event_time < '2026-11-01'"))))
cli('pause')
out = cli('run'); check('本批完成後暫停', out.get('state') == 'paused', out)
r = adm.get('admin_behavior_maintenance.php'); check('維護頁顯示暫停', '已暫停' in r.text)
# 製造衝突：改動已複製的一筆
eid = db("SELECT event_id FROM member_behavior_archive_202610 ORDER BY event_time LIMIT 1")
db(f"UPDATE member_behavior_archive_202610 SET session_key='tampered00000000000000000000000' WHERE event_id={eid}")
db("UPDATE behavior_archive_months SET cursor_time=NULL, cursor_event_id=NULL WHERE archive_month='2026-10'")
cli('resume')
out = cli('run')
check('衝突即停止並保留來源', out['row']['status'] == 'failed' and '衝突' in (out['row']['last_error'] or '')
      and db("SELECT COUNT(*) FROM member_behavior_events WHERE event_time >= '2026-10-01' AND event_time < '2026-11-01'") == str(oct_rows), out)
r = adm.get('admin_behavior_maintenance.php'); check('重新開頁仍可看到上次失敗原因', '衝突' in r.text)
# 修正後接續
db(f"DELETE FROM member_behavior_archive_202610 WHERE event_id={eid}")
r = adm.post('admin_behavior_maintenance.php', {'op': 'continue'}, src='admin_behavior_maintenance.php')
row = db("SELECT status, expected_count, copied_count FROM behavior_archive_months WHERE archive_month='2026-10'")
check('修正後接續完成 10 月封存', row == f'completed\t{oct_rows}\t{oct_rows}', row)

# 中斷恢復：模擬「已 DROP 分區、尚未標記完成」→ 狀態 dropping 可接續
check('11 月為本月不可封存', '無須封存' in adm.get('admin_behavior_maintenance.php').text)

# 全期間（線上 + 兩個月份表）與封存前一致
all_after = adm.get(ALL + 'both').text
check('全期間六項排行封存前後一致（扣除舊共用表 1 筆瀏覽）', ranks(all_before)[2:] == ranks(all_after)[2:])

# 已登記月份表遺失須報錯
db("RENAME TABLE member_behavior_archive_202609 TO mba_hidden")
r = adm.get(SEP + 'both'); check('已登記月份表遺失時報錯', '不存在' in r.text and 'rank-grid' not in r.text)
r = adm.get('admin_behavior_maintenance.php'); check('維護頁標示資料表遺失', '資料表遺失' in r.text)
db("RENAME TABLE mba_hidden TO member_behavior_archive_202609")

# 命名鎖：維護進行中報表等待並拒絕
import threading, time
holder = subprocess.Popen(['mariadb', '-ushop', '-pshoppass', 'shop', '-e', "SELECT GET_LOCK('shop_behavior_maint', 10); SELECT SLEEP(8);"])
time.sleep(1)
r = adm.get(SEP + 'both'); check('維護鎖被占用時報表提示稍後', '維護作業正在進行' in r.text)
r = adm.post('admin_behavior_maintenance.php', {'op': 'ensure'}, src='admin_behavior_maintenance.php')
check('維護鎖被占用時維護操作拒絕', '正在使用資料' in r.text)
holder.wait()

# 管理總覽的瀏覽曲線涵蓋已封存月份並去重
page = adm.get('admin.php').text
rows = re.findall(r'<tr><td>(\d{4}-\d{2}-\d{2})</td><td>(\d+)</td><td>(\d+)</td></tr>', page)
exp = int(db("""SELECT COUNT(*) FROM (SELECT event_id, event_time FROM member_behavior_events WHERE event_type='view_product' AND event_time >= CURDATE() - INTERVAL 29 DAY
  UNION SELECT event_id, event_time FROM member_behavior_archive WHERE event_type='view_product' AND event_time >= CURDATE() - INTERVAL 29 DAY
  UNION SELECT event_id, event_time FROM member_behavior_archive_202610 WHERE event_type='view_product' AND event_time >= CURDATE() - INTERVAL 29 DAY) x"""))
check('總覽瀏覽曲線涵蓋封存月份並去重', sum(int(r[1]) for r in rows) == exp and len(rows) == 30)

passed = sum(1 for r_ in results if r_[1]); print(f'{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
