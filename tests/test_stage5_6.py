"""第五、六階段：公開資訊、Cookie 同意、自訂程式碼、寄信紀錄、備份還原、IP 白名單、辨識碼、權限、總覽、訊息中心；
刪除帳號、SEO、結構化資料、GA4、圖片、規格、模擬付款、超商、維護、錯誤紀錄、寄信額度、資料清理"""
import re, time, io, json, gzip, os
from lib import *

adm = Client(); adm.login('admin@example.com')
ids = json.load(open('/tmp/shop_ids.json'))
BUYER = ids['BUYER']
g = Client(); g.login('buyer@example.com', 'Buyer@123')

# ---------------- 公開資訊 ----------------
rv = db("SELECT row_version FROM site_contents WHERE content_key='shopping_guide'")
r = adm.post('admin_contents.php', {'key': 'shopping_guide', 'row_version': rv,
             'content': '本店退換貨 {退換貨天數} 天，<b>粗體</b>\n\n第二段 https://example.com/x'}, src='admin_contents.php')
page = Client().get('shopping-guide.php').text
check('公開文件：佔位字代入、HTML 被編碼、網址自動連結、空行分段', '本店退換貨 7 天' in page and '&lt;b&gt;粗體&lt;/b&gt;' in page
      and '<a href="https://example.com/x"' in page and page.count('<p>') >= 2, alerts(r.text))
check('頁尾五個連結與營運者名稱', all(x in page for x in ['privacy.php', 'contact.php', 'returns.php', 'shopping-guide.php', 'data-consent-open', '台灣好物商場示範店']))
r = adm.post('admin_contents.php', {'key': 'privacy', 'row_version': db("SELECT row_version FROM site_contents WHERE content_key='privacy'"), 'content': '<script>alert(1)</script>'}, src='admin_contents.php')
check('公開文件拒絕 HTML 程式碼', '不接受 HTML' in alerts(r.text))
db("UPDATE site_contents SET content='' WHERE content_key='contact_info'")
g.post('cart_action.php', {'action': 'add', 'product_id': 7, 'variant_id': 7, 'quantity': 1}, src='product.php?id=7')
page = g.get('checkout.php').text
check('公開資訊未填：結帳停用並顯示提示', '商店資訊尚未設定' in page and re.search(r'<button class="btn btn-primary" type="submit" disabled>確認送出訂單', page))
db("UPDATE site_contents SET content='客服 Email：service@example.com' WHERE content_key='contact_info'")
check('稽核紀錄含異動前後全文', dbi("SELECT COUNT(*) FROM audit_logs WHERE entity_type='site_content' AND entity_id='shopping_guide' AND after_data LIKE '%第二段%'") == 1)

# ---------------- Cookie 同意與自訂程式碼 ----------------
def code(name, pos, scope, cond, body, order=100):
    return adm.post('admin_custom_code.php', {'action': 'create', 'name': name, 'position': pos, 'scope': scope, 'load_condition': cond,
                    'sort_order': order, 'is_active': '1', 'code': body}, src='admin_custom_code.php?new=1')
code('GTM', 'head_start', 'front', 'google_tag', '<script>window.GTM_LOADED=1</script>', 10)
code('分析', 'head_end', 'front', 'analytics', '<script>window.GA_X=1</script>')
code('廣告', 'body_end', 'both', 'ads', '<script src="https://example.com/ads.js"></script>')
code('後台工具', 'body_start', 'admin', 'always', '<!-- admin-only -->')
r = code('錯誤', 'head_start', 'front', 'analytics', '<noscript>x</noscript>')
check('等待同意的程式碼只能包含 <script>', '只能包含' in alerts(r.text))
v = Client()
page = v.get('index.php').text
pos = [page.find(x) for x in ["gtag('consent','default'", 'dataLayer.push({"page_type"', 'GTM_LOADED', '<title>', 'GA_X', '</head>']]
check('載入順序：consent default → dataLayer → <head> 後自訂碼 → title → </head> 前', pos == sorted(pos) and -1 not in pos, pos)
check('進階模式：Google 標籤一律載入；分析與廣告等待同意', '<script>window.GTM_LOADED=1</script>' in page and 'type="text/plain" data-consent="analytics"' in page
      and 'type="text/plain" data-consent="ads" src="https://example.com/ads.js"' in page)
check('前台不輸出後台專用程式碼', 'admin-only' not in page)
check('未選擇時自動顯示橫幅', '"autoShow":true' in page and 'id="consent-panel"' in page)
v.s.cookies.set('shop_consent', '1.1.0.%d' % int(time.time()), domain='127.0.0.1')
page = v.get('index.php').text
check('已同意分析：送出 consent update 並直接執行分析碼', "gtag('consent','update',{analytics_storage:'granted',ad_storage:'denied'" in page and '<script>window.GA_X=1</script>' in page and '"autoShow":false' in page)
v.s.cookies.set('shop_consent', '1.1.0.%d' % (int(time.time()) - 181 * 86400), domain='127.0.0.1')
check('180 天後重新詢問', '"autoShow":true' in v.get('index.php').text)
apage = adm.get('admin_products.php').text
check('後台：輸出 consent 指令、套用後台程式碼、不顯示橫幅', "gtag('consent','default'" in apage and 'admin-only' in apage and 'id="consent-panel"' not in apage and 'ads.js' in apage)
check('安全模式 ?no_custom_code=1', 'admin-only' not in adm.get('admin_products.php?no_custom_code=1').text)
adm.post('admin_consent.php', {'action': 'save', 'banner': '1', 'consent_title': 'Cookie 設定', 'consent_text': '說明', 'consent_privacy_url': 'privacy.php', 'consent_mode': 'basic', 'ads_data_redaction': '1'}, src='admin_consent.php')
v2 = Client()
check('基本模式：Google 標籤在同意前不執行', 'type="text/plain" data-consent="any">window.GTM_LOADED' in v2.get('index.php').text)
v.s.cookies.set('shop_consent', '1.1.0.%d' % int(time.time()), domain='127.0.0.1')
r = adm.post('admin_consent.php', {'action': 'reset', 'confirm': '1'}, src='admin_consent.php')
check('還原預設：同意版本加一使原選擇失效', db("SELECT setting_value FROM site_settings WHERE setting_key='consent_version'") == '2' and '"autoShow":true' in v.get('index.php').text)

# ---------------- 辨識碼 ----------------
c = Client()
c.get('login.php')
c.get('captcha.php?f=login')
r = c.post('login.php', {'email': 'ming@example.com', 'password': 'Shop@2026', 'captcha': 'XXXXX'})
check('辨識碼錯誤：拒絕並保留 Email', '辨識碼不正確' in alerts(r.text) and 'value="ming@example.com"' in r.text)
for i in range(10):
    c.post('login.php', {'email': 'ming@example.com', 'password': 'Shop@2026', 'captcha': 'XXXXX'})
r = c.post('login.php', {'email': 'ming@example.com', 'password': 'Shop@2026', 'captcha': CAP})
check('連續錯 10 次需等 5 分鐘', '5 分鐘後' in alerts(r.text), alerts(r.text))
out = php("$_SESSION=[]; $r = captcha_issue('t'); $_POST['captcha']=strtolower($r['code']); captcha_check('t'); echo 'ok1 '; "
          "$_POST['captcha']=$r['code']; try { captcha_check('t'); echo 'reused-ok'; } catch (UserError $e) { echo 'reuse-fail '; } "
          "$r = captcha_issue('t'); $_SESSION['_cap']['t']['exp'] = time() - 1; $_POST['captcha'] = $r['code']; try { captcha_check('t'); } catch (UserError $e) { echo 'expired'; }")
check('辨識碼不分大小寫、只能用一次、10 分鐘過期', out == 'ok1 reuse-fail expired', out)

# ---------------- 權限模組 ----------------
p = Client(); p.login('product@example.com')
check('商品管理員看得到商品、看不到訂單', p.get('admin_products.php').status_code == 200 and p.get('admin_orders.php').status_code == 403)
nav = p.get('admin.php').text
check('選單只列有權限的項目', 'admin_products.php' in nav and 'admin_orders.php' not in nav and 'admin_admins.php' not in nav)
w = Client(); w.login('warehouse@example.com')
r = w.post('admin_orders.php', {'action': 'note', 'id': 1, 'row_version': 1, 'reason': 'x'}, src='admin_orders.php?id=1')
check('只有檢視權限：頁面不輸出寫入表單、送出寫入回應 403', 'name="action" value="note"' not in w.get('admin_orders.php?id=1').text and r.status_code == 403)
s = Client(); s.login('support@example.com')
check('會員管理（檢視）不能停權管理員', s.get('admin_members.php?id=2').status_code == 200 and 'value="suspend"' not in s.get('admin_members.php?id=2').text)
mrv = db("SELECT row_version FROM members WHERE id=2")
adm.post('admin_admins.php', {'id': 2, 'row_version': mrv, 'perm[product]': 'view'}, src='admin_admins.php?id=2')
check('授權變更立即生效（改為檢視）', p.get('admin_products.php?new=1').status_code == 403)
r = adm.post('admin_admins.php', {'id': 1, 'row_version': db("SELECT row_version FROM members WHERE id=1")}, src='admin_admins.php?id=1')
check('不能變更自己的權限', '不能變更自己的權限' in alerts(r.text))
check('授權變更寫入異動紀錄', dbi("SELECT COUNT(*) FROM audit_logs WHERE entity_type='admin_permission' AND entity_id='2'") == 1)

# ---------------- IP 白名單 ----------------
out = php("echo (int)ip_in_cidr('203.0.113.9','203.0.113.0/24'),(int)ip_in_cidr('203.0.114.9','203.0.113.0/24'),(int)ip_in_cidr('2001:db8::1','2001:db8::/32'),"
          "(int)ip_in_cidr('2001:db9::1','2001:db8::/32'),(int)ip_in_cidr('203.0.113.5','203.0.113.5'),(int)valid_cidr('10.0.0.0/33');")
check('IPv4／IPv6／CIDR 判斷', out == '101010', out)
r = adm.post('admin_ip_allowlist.php', {'action': 'create', 'cidr': '203.0.113.0/24', 'note': '辦公室', 'is_active': '1'}, src='admin_ip_allowlist.php')
check('目前 IP 不在清單內時拒絕存檔', '拒絕存檔' in alerts(r.text) and dbi('SELECT COUNT(*) FROM admin_ip_allowlist') == 0)
db("INSERT INTO admin_ip_allowlist (cidr, is_active) VALUES ('203.0.113.0/24', 1)")
r = adm.get('admin_products.php')
check('IP 不符：後台 403、不輸出後台選單、寫入安全紀錄', r.status_code == 403 and 'nav-admin' not in adm.get('index.php').text
      and dbi("SELECT COUNT(*) FROM security_logs WHERE event_type='ip_denied'") >= 1)
db("INSERT INTO admin_ip_allowlist (cidr, is_active) VALUES ('127.0.0.1', 1)")
check('符合白名單後恢復', adm.get('admin_products.php').status_code == 200)
db("DELETE FROM admin_ip_allowlist")

# ---------------- 聯繫表單與訊息中心 ----------------
v = Client()
d = {'name': '訪客乙', 'email': 'guest2@example.com', 'category': 'order', 'subject': '詢問出貨時間', 'body': '請問訂單多久會出貨呢？謝謝您。', 'agree': '1', 'captcha': CAP}
r = v.post('contact.php', d)
MSG = dbi("SELECT MAX(id) FROM support_messages")
check('聯繫表單：訊息編號、記錄 IP、寄確認信', re.search(r'M\d{8}-\d{6}', r.text) and db(f"SELECT ip FROM support_messages WHERE id={MSG}") == '127.0.0.1'
      and dbi(f"SELECT COUNT(*) FROM mail_logs WHERE mail_type='contact_confirm' AND message_id={MSG}") == 1, alerts(r.text))
for i in range(4):
    v.post('contact.php', d)
r = v.post('contact.php', d)
check('同一 IP 每小時最多 5 則', '每小時最多 5 則' in r.text, alerts(r.text))
rv = db(f"SELECT row_version FROM support_messages WHERE id={MSG}")
s.post('admin_messages.php', {'action': 'take', 'id': MSG, 'row_version': rv}, src=f'admin_messages.php?id={MSG}')
check('接手處理：記錄處理人', db(f"SELECT CONCAT(status,'/',handler_id) FROM support_messages WHERE id={MSG}") == 'in_progress/3')
r = g.post('member_support.php', {'category': 'product', 'subject': '會員發問', 'body': '想問烏龍茶有沒有大包裝？', 'order_id': str(ids['ORDER1']), 'captcha': CAP}, src='member_support.php')
MMSG = dbi("SELECT MAX(id) FROM support_messages WHERE source='member'")
rv = db(f"SELECT row_version FROM support_messages WHERE id={MMSG}")
s.post('admin_messages.php', {'action': 'note', 'id': MMSG, 'row_version': rv, 'content': '內部：請倉庫確認'}, src=f'admin_messages.php?id={MMSG}')
rv = db(f"SELECT row_version FROM support_messages WHERE id={MMSG}")
s.post('admin_messages.php', {'action': 'reply', 'id': MMSG, 'row_version': rv, 'content': '您好，目前有 300g 大包裝。'}, src=f'admin_messages.php?id={MMSG}')
page = g.get(f'member_support.php?id={MMSG}').text
check('回覆客戶：寄信且會員看得到；內部摘要不出現在會員端', '300g 大包裝' in page and '請倉庫確認' not in page
      and dbi(f"SELECT COUNT(*) FROM mail_logs WHERE mail_type='support_reply' AND message_id={MMSG}") == 1)
rv = db(f"SELECT row_version FROM support_messages WHERE id={MMSG}")
s.post('admin_messages.php', {'action': 'done', 'id': MMSG, 'row_version': rv}, src=f'admin_messages.php?id={MMSG}')
rv = db(f"SELECT row_version FROM support_messages WHERE id={MMSG}")
s.post('admin_messages.php', {'action': 'reopen', 'id': MMSG, 'row_version': rv}, src=f'admin_messages.php?id={MMSG}')
check('已處理可重新開啟，歷程完整', db(f"SELECT status FROM support_messages WHERE id={MMSG}") == 'in_progress'
      and dbi(f"SELECT COUNT(*) FROM support_message_events WHERE message_id={MMSG}") >= 5)

# ---------------- 管理總覽 ----------------
page = adm.get('admin.php').text
check('總覽：卡片與 SVG 圖表', all(x in page for x in ['會員', '商品', '訂單', '退換貨', '出貨與收件', '訊息', '瀏覽行為曲線', '<svg']))
check('總覽：會員數與資料庫一致', f'<p class="big-num">{dbi("SELECT COUNT(*) FROM members WHERE deleted_at IS NULL")}</p>' in page)
check('只顯示有權限的卡片（倉庫）', '出貨與收件' in w.get('admin.php').text and '瀏覽行為曲線' not in w.get('admin.php').text)

# ---------------- 寄信紀錄 ----------------
r = adm.post('admin_mail_logs.php', {'to': 'admin@example.com'}, src='admin_mail_logs.php')
check('寄測試信並記錄', dbi("SELECT COUNT(*) FROM mail_logs WHERE mail_type='test'") == 1 and '測試信已寄出' in alerts(r.text))
check('寄信紀錄不含驗證連結', dbi("SELECT COUNT(*) FROM mail_logs WHERE subject LIKE '%token%' OR error_message LIKE '%token=%'") == 0)

# ---------------- 備份與還原 ----------------
r = adm.post('admin_backup.php', {'action': 'download', 'password': 'wrong', 'confirm': '1'}, src='admin_backup.php')
check('下載備份需要正確密碼', '密碼不正確' in alerts(r.text))
r = adm.post('admin_backup.php', {'action': 'download', 'password': 'Shop@2026', 'confirm': '1'}, src='admin_backup.php')
raw = gzip.decompress(r.content).decode()
lines = raw.strip().split('\n')
head = json.loads(lines[0]); foot = json.loads(lines[-1])
check('備份檔：header、每表筆數與 SHA-256；預設不含行為事件', head['type'] == 'header' and foot['type'] == 'footer'
      and foot['tables']['members']['rows'] == dbi('SELECT COUNT(*) FROM members') and 'member_behavior_events' not in foot['tables'])
open('/tmp/backup_ok.json.gz', 'wb').write(r.content)
bad = raw.replace('台灣好物商場示範店', '被竄改的名稱', 1)
open('/tmp/backup_bad.json.gz', 'wb').write(gzip.compress(bad.encode()))
r = adm.post('admin_backup.php', {'action': 'upload'}, src='admin_backup.php', files={'backup': ('b.json.gz', open('/tmp/backup_bad.json.gz', 'rb'))})
for i in range(200):
    if 'id="drill-step"' not in r.text: break
    did = re.search(r'name="id" value="(\d+)"', r.text.split('id="drill-step"')[1]).group(1)
    r = adm.s.post(BASE + 'admin_backup.php', data={'action': 'step', 'id': did, 'csrf_token': csrf(r.text)})
BAD = dbi('SELECT MAX(id) FROM backup_drills')
check('竄改的備份檔驗證失敗', db(f"SELECT status FROM backup_drills WHERE id={BAD}") == 'failed'
      and dbi(f"SELECT COUNT(*) FROM backup_drill_tables WHERE drill_id={BAD} AND result='mismatch'") >= 1)
r = adm.post('admin_backup.php', {'action': 'upload'}, src='admin_backup.php', files={'backup': ('b.json.gz', open('/tmp/backup_ok.json.gz', 'rb'))})
steps = 0
for i in range(500):
    if 'id="drill-step"' not in r.text: break
    did = re.search(r'name="id" value="(\d+)"', r.text.split('id="drill-step"')[1]).group(1)
    r = adm.s.post(BASE + 'admin_backup.php', data={'action': 'step', 'id': did, 'csrf_token': csrf(r.text)})
    steps += 1
OK = dbi('SELECT MAX(id) FROM backup_drills')
check('還原演練：分批接續並全部驗證通過', db(f"SELECT status FROM backup_drills WHERE id={OK}") == 'verified' and steps > 1
      and dbi(f"SELECT COUNT(*) FROM backup_drill_tables WHERE drill_id={OK} AND result<>'ok'") == 0, (db(f"SELECT message FROM backup_drills WHERE id={OK}"), steps))
prefix = db(f"SELECT prefix FROM backup_drills WHERE id={OK}")
check('演練不修改正式資料（dr_ 表）', dbi(f"SELECT COUNT(*) FROM {prefix}members") == dbi('SELECT COUNT(*) FROM members'))
db("UPDATE site_contents SET content='套用前修改' WHERE content_key='operator_name'")
r = adm.post('admin_backup.php', {'action': 'apply', 'id': OK, 'phrase': '套用備份', 'password': 'Shop@2026'}, src=f'admin_backup.php?drill={OK}')
check('套用為正式資料：資料一致、外鍵已補建', db("SELECT content FROM site_contents WHERE content_key='operator_name'") == '台灣好物商場示範店'
      and dbi("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='shop' AND TABLE_NAME='orders'") == 1
      and db("SELECT setting_value FROM site_settings WHERE setting_key='maintenance_enabled'") in ('0', ''), alerts(r.text))
r = adm.post('admin_backup.php', {'action': 'revert', 'id': OK, 'password': 'Shop@2026'}, src=f'admin_backup.php?drill={OK}')
check('可以換回套用前的資料', db("SELECT content FROM site_contents WHERE content_key='operator_name'") == '套用前修改', alerts(r.text))
db("UPDATE site_contents SET content='台灣好物商場示範店' WHERE content_key='operator_name'")
adm.post('admin_backup.php', {'action': 'drop', 'id': BAD}, src=f'admin_backup.php?drill={BAD}')
check('刪除演練隔離表', db(f"SELECT status FROM backup_drills WHERE id={BAD}") == 'dropped')

# =====================================================================
# 第六階段
# =====================================================================
v = Client()
robots = v.get('robots.txt').text if v.get('robots.txt').status_code == 200 else v.get('robots.php').text
check('robots.txt：擋下後台與結帳、列出 Sitemap', 'Disallow: /admin' in robots and 'Disallow: /checkout.php' in robots and 'Sitemap: http://127.0.0.1:8080/sitemap.xml' in robots)
sm = v.get('sitemap.php').text
check('sitemap.xml：只列上架商品與有商品的分類', 'product.php?id=1<' in sm and 'product.php?id=9<' not in sm and 'products.php?category=1' in sm and 'cart.php' not in sm)
pg = v.get('product.php?id=9').text
check('下架商品 noindex 且不輸出 Offer', 'noindex, follow' in pg and '"offers"' not in pg)
pg = v.get('products.php?category=1&tag=1&page=1').text
check('分類＋標籤頁 canonical 指回分類', '<link rel="canonical" href="http://127.0.0.1:8080/products.php?category=1">' in pg)
pg = v.get('products.php?q=茶').text
check('站內搜尋結果 noindex', 'noindex, follow' in pg)
pg = v.get('product.php?id=1&utm_source=x&_cart_return=abc').text
check('商品頁 canonical 去除追蹤參數', '<link rel="canonical" href="http://127.0.0.1:8080/product.php?id=1">' in pg)
lds = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', pg)]
prod = [x for x in lds if x['@type'] == 'Product'][0]
check('Product JSON-LD：Offer、運送、退貨政策', prod['offers']['priceCurrency'] == 'TWD' and prod['offers']['shippingDetails'] and prod['offers']['hasMerchantReturnPolicy']['merchantReturnDays'] == 7)
home = v.get('index.php').text
lds = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', home)]
check('首頁 OnlineStore 與 WebSite、OG 與 Twitter Card', {'OnlineStore', 'WebSite'} <= {x['@type'] for x in lds} and 'og:site_name' in home and 'twitter:card' in home)
check('後台頁面 X-Robots-Tag', adm.get('admin_orders.php').headers.get('X-Robots-Tag') == 'noindex, nofollow')

# 商品 SEO 欄位
pr = db("SELECT row_version FROM products WHERE id=2")
adm.post('admin_products.php', {'action': 'update', 'id': 2, 'row_version': pr, 'name': '日月潭紅玉紅茶 75g', 'sku': 'TEA-002', 'price': '520', 'category_id': '1',
         'shipping_rule_id': '1', 'is_active': '1', 'allow_cvs': '1', 'seo_title': '紅玉紅茶推薦｜台灣好物', 'meta_description': '自訂描述文字'}, src='admin_products.php?id=2')
pg = v.get('product.php?id=2').text
check('商品自訂 SEO 標題與 description 生效', '<title>紅玉紅茶推薦｜台灣好物</title>' in pg and 'content="自訂描述文字"' in pg)

# 圖片上傳
from PIL import Image
buf = io.BytesIO()
img = Image.new('RGB', (1600, 1000), (200, 80, 40))
exif = Image.Exif(); exif[0x010F] = 'TestCamera'
img.save(buf, 'JPEG', exif=exif.tobytes())
r = adm.post('admin_products.php', {'action': 'image_upload', 'id': 2}, src='admin_products.php?id=2', files={'images[]': ('photo.jpg', buf.getvalue(), 'image/jpeg')})
f = db("SELECT CONCAT(file_large,'|',file_thumb,'|',width,'|',thumb_width) FROM product_images WHERE product_id=2").split('|')
large = os.path.join(HTDOCS, 'uploads/products', f[0])
check('圖片上傳：大圖 1200、縮圖 400、隨機檔名', os.path.exists(large) and f[2] == '1200' and f[3] == '400' and re.match(r'^[a-f0-9]{24}\.(webp|jpg|png)$', f[0]), (alerts(r.text), f))
im2 = Image.open(large)
check('重新編碼並移除 EXIF', not im2.getexif().get(0x010F))
r = adm.post('admin_products.php', {'action': 'image_upload', 'id': 2}, src='admin_products.php?id=2', files={'images[]': ('evil.jpg', b'<?php echo 1; ?>', 'image/jpeg')})
check('偽裝副檔名的 PHP 檔被拒', '只接受' in alerts(r.text), alerts(r.text))
r = adm.post('admin_products.php', {'action': 'image_upload', 'id': 2}, src='admin_products.php?id=2', files={'images[]': ('big.jpg', b'\xff\xd8' + b'0' * (5 * 1024 * 1024 + 10), 'image/jpeg')})
check('超過 5 MB 被拒', '5 MB' in alerts(r.text) or '超過主機允許' in alerts(r.text), alerts(r.text))
pg = v.get('product.php?id=2').text
check('商品頁使用上傳的圖片並輸出 og:image', 'uploads/products/' + f[0] in pg and 'property="og:image"' in pg)

# 規格：新增規格產生組合
pid8 = 8
r = adm.post('admin_products.php', {'action': 'options', 'id': pid8, 'opt_name[1]': '顏色', 'opt_values[1]': '白, 藏青, 黑', 'opt_name[2]': '尺寸', 'opt_values[2]': 'S, M, L'}, src='admin_products.php?id=8')
check('新增規格值產生組合（3 × 3 = 9）', dbi("SELECT COUNT(*) FROM product_variants WHERE product_id=8 AND variant_key<>'default'") == 9, alerts(r.text))
pg = v.get('product.php?id=8').text
lds = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', pg)]
check('多規格輸出 ProductGroup + hasVariant', any(x['@type'] == 'ProductGroup' and len(x['hasVariant']) == 9 for x in lds))
check('列表頁價格區間 NT$450～490', 'NT$450～490' in v.get('products.php?category=4').text)

# ---------------- 模擬線上付款 ----------------
def checkout(cl, ship='home', pay='cod', extra=None):
    page = cl.get('checkout.php').text
    tok = re.search(r'name="checkout_token" value="([a-f0-9]{64})"', page).group(1)
    q = json.loads(re.search(r'<script type="application/json" id="checkout-data">(.*?)</script>', page).group(1))['quotes'][ship + '|' + pay]
    aid = re.search(r'name="address_id" value="(\d+)"', page)
    d = {'checkout_token': tok, 'expected_total': q['total_raw'], 'shipping_method': ship, 'payment_method': pay,
         'address_id': aid.group(1) if aid else 'new', 'captcha': CAP, 'csrf_token': csrf(page)}
    d.update(extra or {})
    return cl.s.post(BASE + 'checkout.php', data=d)

st7 = dbi("SELECT quantity FROM variant_inventory WHERE variant_id=7")
r = checkout(g, 'home', 'sim_card')
OP = dbi('SELECT MAX(id) FROM orders')
check('線上付款：訂單待付款、先扣庫存、不產生出貨單、導向模擬付款頁', db(f"SELECT CONCAT(status,'/',payment_status) FROM orders WHERE id={OP}") == 'pending_payment/pending'
      and dbi("SELECT quantity FROM variant_inventory WHERE variant_id=7") == st7 - 1 and dbi(f"SELECT COUNT(*) FROM shipments WHERE order_id={OP}") == 0
      and '模擬付款頁' in r.text and '不需要輸入任何卡號' in r.text, alerts(r.text))
form = {m.group(1): m.group(2) for m in re.finditer(r'<input type="hidden" name="(pid|order|amount|t|sig)" value="([^"]*)"', r.text)}
bad = dict(form, amount='1.00')
r2 = g.s.post(BASE + 'pay_sim.php', data=dict(bad, result='success', csrf_token=csrf(r.text)))
check('竄改金額：簽章驗證失敗', '驗證失敗' in r2.text and db(f"SELECT status FROM orders WHERE id={OP}") == 'pending_payment')
r2 = g.s.post(BASE + 'pay_sim.php', data=dict(form, result='failed', csrf_token=csrf(r.text)))
check('模擬付款失敗：保持待付款、可重新付款', db(f"SELECT CONCAT(status,'/',payment_status) FROM orders WHERE id={OP}") == 'pending_payment/failed' and '前往付款' in r2.text)
r3 = g.post('member_orders.php', {'action': 'pay', 'order_id': OP}, src=f'member_orders.php?id={OP}')
form = {m.group(1): m.group(2) for m in re.finditer(r'<input type="hidden" name="(pid|order|amount|t|sig)" value="([^"]*)"', r3.text)}
r4 = g.s.post(BASE + 'pay_sim.php', data=dict(form, result='success', csrf_token=csrf(r3.text)))
check('模擬付款成功：處理中、已付款、產生出貨單、推送 purchase', db(f"SELECT CONCAT(status,'/',payment_status) FROM orders WHERE id={OP}") == 'processing/paid'
      and dbi(f"SELECT COUNT(*) FROM shipments WHERE order_id={OP} AND status='pending'") == 1 and '"event":"purchase"' in r4.text)
r5 = g.s.post(BASE + 'pay_sim.php', data=dict(form, result='success', csrf_token=csrf(r3.text)))
check('重複回報不重複處理', '已處理過' in alerts(r5.text) and dbi(f"SELECT COUNT(*) FROM shipments WHERE order_id={OP}") == 1)
rv = db(f"SELECT row_version FROM orders WHERE id={OP}")
adm.post('admin_orders.php', {'action': 'cancel', 'id': OP, 'row_version': rv, 'reason': '客戶要求取消'}, src=f'admin_orders.php?id={OP}')
check('已付款訂單取消：模擬退款', db(f"SELECT CONCAT(status,'/',payment_status) FROM orders WHERE id={OP}") == 'cancelled/refunded'
      and db(f"SELECT status FROM payments WHERE order_id={OP} AND status IN ('refunded') LIMIT 1") == 'refunded')

# 逾時自動取消
g.post('cart_action.php', {'action': 'add', 'product_id': 7, 'variant_id': 7, 'quantity': 1}, src='product.php?id=7')
checkout(g, 'home', 'sim_paypal')
OT = dbi('SELECT MAX(id) FROM orders')
st7 = dbi("SELECT quantity FROM variant_inventory WHERE variant_id=7")
db(f"UPDATE orders SET payment_due_at = NOW() - INTERVAL 1 MINUTE WHERE id={OT}")
g.get('member_orders.php')
check('待付款超過期限自動取消並回補庫存', db(f"SELECT status FROM orders WHERE id={OT}") == 'cancelled' and dbi("SELECT quantity FROM variant_inventory WHERE variant_id=7") == st7 + 1)

# ---------------- 超商取貨 ----------------
g.post('cart_action.php', {'action': 'add', 'product_id': 6, 'variant_id': 6, 'quantity': 1}, src='product.php?id=6')
page = g.get('checkout.php').text
check('不可超商取貨的商品：停用超商取貨', 'value="cvs"' not in page and '無法選擇超商取貨' in page)
cid = re.search(r'name="item_id" value="(\d+)">\s*<input type="hidden" name="row_version" value="(\d+)">\s*<button class="btn btn-small btn-danger"', g.get('cart.php').text)
g.post('cart_action.php', {'action': 'remove', 'item_id': cid.group(1), 'row_version': cid.group(2)}, src='cart.php')
g.post('cart_action.php', {'action': 'add', 'product_id': 4, 'variant_id': 4, 'quantity': 2}, src='product.php?id=4')
store = dbi("SELECT id FROM cvs_stores WHERE chain='711' LIMIT 1")
r = checkout(g, 'cvs', 'cvs_cod', {'store_id': store, 'pickup_name': '測試會員', 'pickup_phone': '0912345678'})
OC = dbi('SELECT MAX(id) FROM orders')
check('超商取貨付款下單：門市快照、運費 60、手續費 30', db(f"SELECT CONCAT(shipping_method,'/',payment_method,'/',shipping_fee,'/',cod_fee,'/',cvs_chain) FROM orders WHERE id={OC}") == 'cvs/cvs_cod/60.00/30.00/711', alerts(r.text))
SC = dbi(f"SELECT id FROM shipments WHERE order_id={OC}")
adm.post('admin_shipments.php', {'action': 'ship', 'id': SC, 'tracking_no': 'C1', 'shipped_date': time.strftime('%Y-%m-%d')}, src=f'admin_shipments.php?id={SC}')
adm.post('admin_shipments.php', {'action': 'arrive', 'id': SC}, src=f'admin_shipments.php?id={SC}')
page = g.get(f'member_orders.php?id={OC}').text
check('已到店：會員看到取貨期限、寄到店通知', '已到店，請於' in page and dbi(f"SELECT COUNT(*) FROM mail_logs WHERE mail_type='cvs_arrived' AND order_id={OC}") == 1)
g.post('member_orders.php', {'action': 'pickup', 'order_id': OC, 'shipment_id': SC}, src=f'member_orders.php?id={OC}')
check('會員按我已取貨：訂單完成、取貨付款轉已付款', db(f"SELECT CONCAT(status,'/',payment_status) FROM orders WHERE id={OC}") == 'completed/paid')
# 未取貨退回（線上已付款）
g.post('cart_action.php', {'action': 'add', 'product_id': 4, 'variant_id': 4, 'quantity': 1}, src='product.php?id=4')
r = checkout(g, 'cvs', 'sim_card', {'store_id': store, 'pickup_name': '測試會員', 'pickup_phone': '0912345678'})
OR = dbi('SELECT MAX(id) FROM orders')
form = {m.group(1): m.group(2) for m in re.finditer(r'<input type="hidden" name="(pid|order|amount|t|sig)" value="([^"]*)"', r.text)}
g.s.post(BASE + 'pay_sim.php', data=dict(form, result='success', csrf_token=csrf(r.text)))
SR = dbi(f"SELECT id FROM shipments WHERE order_id={OR}")
for a in ['ship', 'arrive', 'return']:
    adm.post('admin_shipments.php', {'action': a, 'id': SR, 'tracking_no': 'C2', 'shipped_date': time.strftime('%Y-%m-%d')}, src=f'admin_shipments.php?id={SR}')
RR = dbi(f"SELECT id FROM return_receipts WHERE order_id={OR} AND type='refused'")
check('未取貨退回：訂單拒收並產生拒收退回收件單', db(f"SELECT status FROM orders WHERE id={OR}") == 'refused' and RR)
it = dbi(f"SELECT id FROM return_receipt_items WHERE receipt_id={RR}")
adm.post('admin_receipts.php', {'id': RR, f'cond[{it}]': 'good'}, src=f'admin_receipts.php?id={RR}')
check('驗收後線上付款模擬退款', db(f"SELECT payment_status FROM orders WHERE id={OR}") == 'refunded')

# ---------------- 維護模式 ----------------
adm.post('admin_system.php', {'action': 'maintenance', 'enabled': '1', 'maintenance_message': '系統升級中', 'maintenance_until': ''}, src='admin_system.php')
r = Client().get('index.php')
check('維護模式：訪客 503 與 Retry-After', r.status_code == 503 and 'Retry-After' in r.headers and '系統升級中' in r.text)
r = g.post('cart_action.php', {'action': 'add', 'product_id': 1, 'variant_id': 1, 'quantity': 1}, src='login.php')
check('維護模式：會員寫入被拒', r.status_code == 503)
check('維護模式：登入頁與 robots.txt 仍可使用', Client().get('login.php').status_code == 200 and Client().get('robots.php').status_code == 200)
r = adm.get('index.php')
check('維護模式：管理員可正常瀏覽並看到提示', r.status_code == 200 and '維護模式中' in r.text)
adm.post('admin_system.php', {'action': 'maintenance', 'maintenance_message': '系統升級中', 'maintenance_until': ''}, src='admin_system.php')
check('關閉維護模式', Client().get('index.php').status_code == 200)

# ---------------- 錯誤紀錄 ----------------
php("trigger_error('測試警告 password=secret123', E_USER_WARNING); trigger_error('測試警告 password=secret123', E_USER_WARNING);")
Client().get('index.php?password=hunter2&x=1&__err=1')
check('錯誤紀錄：同一錯誤只累加次數', dbi("SELECT occurrences FROM error_logs WHERE message LIKE '測試警告%'") == 2)
r = adm.get('admin_error_logs.php')
check('錯誤紀錄頁可檢視', '測試警告' in r.text and r.status_code == 200)
out = php("echo error_clean_url('/x.php?token=abc&password=p&csrf_token=c&ok=1');")
check('網址去除憑證類參數', 'abc' not in out and '=p&' not in out and 'ok=1' in out, out)

# ---------------- 寄信額度 ----------------
db("INSERT INTO site_settings (setting_key, setting_value) VALUES ('quota_ip_hour','2') ON DUPLICATE KEY UPDATE setting_value='2'")
db("DELETE FROM mail_logs WHERE visitor_triggered=1")
for i in range(3):
    c = Client()
    r = c.post('forgot_password.php', {'email': 'ming@example.com', 'captcha': CAP})
check('同一 IP 超過每小時額度後不再寄出、畫面訊息不變、紀錄標記超額', dbi("SELECT COUNT(*) FROM mail_logs WHERE mail_type='reset_password' AND result='success'") == 2
      and dbi("SELECT COUNT(*) FROM mail_logs WHERE result='quota_blocked'") == 1 and '如果此 Email 屬於可使用的會員帳號' in r.text
      and dbi("SELECT COUNT(*) FROM security_logs WHERE event_type='mail_quota'") == 1)
g.post('cart_action.php', {'action': 'add', 'product_id': 5, 'variant_id': 5, 'quantity': 1}, src='product.php?id=5')
db("UPDATE variant_inventory SET quantity=5 WHERE variant_id=5")
g.post('cart_action.php', {'action': 'add', 'product_id': 5, 'variant_id': 5, 'quantity': 1}, src='product.php?id=5')
checkout(g)
check('交易通知不受額度影響', dbi("SELECT COUNT(*) FROM mail_logs WHERE mail_type='order_created' AND result='success' AND created_at > NOW() - INTERVAL 1 MINUTE") >= 1)
db("UPDATE site_settings SET setting_value='5' WHERE setting_key='quota_ip_hour'")

# ---------------- 刪除帳號與匿名化 ----------------
dc = Client()
dc.post('register_process.php', {'name': '要刪除的人', 'email': 'del@example.com', 'password': 'Delete@123', 'password_confirm': 'Delete@123', 'captcha': CAP}, src='register.php')
dc.get(mail_links('verify')[-1].replace('http://127.0.0.1:8080/', ''))
dc.login('del@example.com', 'Delete@123')
DEL = dbi("SELECT id FROM members WHERE email='del@example.com'")
dc.post('member_addresses.php', {'action': 'create', 'captcha': CAP, 'a[recipient]': '王刪除', 'a[phone]': '0911222333', 'a[zip]': '106', 'a[city]': '臺北市', 'a[district]': '大安區', 'a[address]': '刪除路 1 號'})
dc.post('cart_action.php', {'action': 'add', 'product_id': 7, 'variant_id': 7, 'quantity': 1}, src='product.php?id=7')
checkout(dc)
ODEL = dbi(f'SELECT MAX(id) FROM orders WHERE member_id={DEL}')
r = dc.post('member_delete.php', {'action': 'request', 'password': 'Delete@123', 'agree': '1', 'reason': '不再使用', 'captcha': CAP})
check('會員申請刪除帳號（申請後仍可使用）', dbi(f"SELECT COUNT(*) FROM account_deletion_requests WHERE member_id={DEL} AND status='pending'") == 1 and dc.get('member.php').status_code == 200)
page = adm.get(f'admin_members.php?id={DEL}').text
check('有未結案訂單時無法核准', '訂單未結案' in page and re.search(r'<button class="btn btn-danger" disabled>執行刪除', page))
SD = dbi(f"SELECT id FROM shipments WHERE order_id={ODEL}")
adm.post('admin_shipments.php', {'action': 'ship', 'id': SD, 'carrier': 'post', 'tracking_no': 'D1', 'shipped_date': time.strftime('%Y-%m-%d')}, src=f'admin_shipments.php?id={SD}')
rv = db(f"SELECT row_version FROM orders WHERE id={ODEL}")
adm.post('admin_orders.php', {'action': 'complete', 'id': ODEL, 'row_version': rv}, src=f'admin_orders.php?id={ODEL}')
rq = db(f"SELECT row_version FROM account_deletion_requests WHERE member_id={DEL} AND status='pending'")
r = adm.post('admin_members.php', {'action': 'deletion_approve', 'id': DEL, 'row_version': rq}, src=f'admin_members.php?id={DEL}')
m = db(f"SELECT CONCAT(name,'|',email,'|',status,'|',IF(anonymized_at IS NULL,0,1)) FROM members WHERE id={DEL}")
check('核准後匿名化：姓名、Email、狀態', m == f'已刪除會員|deleted-{DEL}@deleted.invalid|0|1', (m, alerts(r.text)))
check('地址刪除、訂單收件快照只留縣市', dbi(f"SELECT COUNT(*) FROM member_addresses WHERE member_id={DEL}") == 0
      and db(f"SELECT CONCAT(IFNULL(ship_name,'-'),'|',IFNULL(ship_phone,'-'),'|',IFNULL(ship_address,'-'),'|',ship_city) FROM orders WHERE id={ODEL}") == '-|-|-|臺北市')
check('訂單金額與商品快照仍完整', db(f"SELECT total FROM orders WHERE id={ODEL}") != '' and dbi(f"SELECT COUNT(*) FROM order_items WHERE order_id={ODEL}") == 1)
check('寄信紀錄 Email 遮蔽、完成信已寄', dbi(f"SELECT COUNT(*) FROM mail_logs WHERE member_id={DEL} AND to_email LIKE '%del@example.com%'") == 0
      and dbi(f"SELECT COUNT(*) FROM mail_logs WHERE member_id={DEL} AND mail_type='deletion_completed'") == 1)
check('匿名化後無法登入', '錯誤' in alerts(Client().login('del@example.com', 'Delete@123').text))

# ---------------- 資料清理 ----------------
g.post('cart_action.php', {'action': 'add', 'product_id': 1, 'variant_id': 1, 'quantity': 1}, src='product.php?id=1')
db(f"UPDATE carts SET updated_at = NOW() - INTERVAL 100 DAY WHERE member_id={BUYER}")
db("INSERT INTO security_logs (created_at, event_type, ip) VALUES (NOW() - INTERVAL 200 DAY, 'test', '1.2.3.4')")
orders_before = dbi('SELECT COUNT(*) FROM orders'); audits_before = dbi('SELECT COUNT(*) FROM audit_logs')
r = adm.post('admin_system.php', {'action': 'cleanup'}, src='admin_system.php')
check('資料清理：過期購物車與舊安全紀錄清除', dbi(f"SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id=ci.cart_id WHERE c.member_id={BUYER}") == 0
      and dbi("SELECT COUNT(*) FROM security_logs WHERE event_type='test'") == 0, alerts(r.text))
check('訂單與異動紀錄不受影響', dbi('SELECT COUNT(*) FROM orders') == orders_before and dbi('SELECT COUNT(*) FROM audit_logs') >= audits_before)

# ---------------- 其餘後台頁面 ----------------
for p_ in ['admin.php', 'admin_store.php', 'admin_delivery.php', 'admin_system.php', 'admin_error_logs.php', 'admin_category_edit.php?id=1', 'admin_admins.php',
           'admin_contents.php', 'admin_consent.php', 'admin_custom_code.php', 'admin_mail_logs.php', 'admin_backup.php', 'admin_ip_allowlist.php',
           'admin_messages.php', 'admin_audit_logs.php', 'admin_behavior_reports.php', 'admin_behavior_maintenance.php', 'admin_inventory.php',
           'admin_products.php?id=8', 'admin_products.php?new=1', 'admin_categories.php', 'admin_tags.php', 'admin_members.php?status=deletion']:
    r = adm.get(p_)
    check(f'後台頁面 {p_}', r.status_code == 200 and '系統暫時發生錯誤' not in r.text, r.status_code)
for p_ in ['member.php', 'member_orders.php', 'member_addresses.php', 'member_support.php', 'member_delete.php', 'cart.php']:
    r = g.get(p_)
    check(f'會員頁面 {p_}', r.status_code == 200 and '系統暫時發生錯誤' not in r.text, r.status_code)
errs = db("SELECT CONCAT(level,' ',message,' ',file,':',line) FROM error_logs WHERE message NOT LIKE '測試警告%' AND message NOT LIKE '%模擬歷程寫入失敗%'")
check('整個測試過程沒有其他 PHP 錯誤或警告', errs == '', errs)
print('\n%d/%d passed' % (sum(1 for r in results if r[1]), len(results)))
