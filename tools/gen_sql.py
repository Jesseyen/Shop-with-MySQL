# 產生 01_default_data.sql 與 10_sample_data.sql
import subprocess, random, datetime
random.seed(20261121)
OUT='/home/claude/v2/shop/sql/'
def q(s):
    if s is None: return 'NULL'
    return "'" + str(s).replace('\\','\\\\').replace("'","''") + "'"

privacy = """{營運者名稱}（以下稱「本商店」）重視您的隱私。本聲明說明本商店如何蒐集、處理及利用您的個人資料。請依實際營運修改本範本，並請法律人員確認。

一、蒐集目的
會員管理、訂單處理與配送、退換貨與退款、客戶服務、交易安全與防止詐騙，以及經您同意後的網站分析與行銷。

二、蒐集的個人資料類別
姓名、Email、電話、收件地址或取貨門市、訂單與售後紀錄、客服訊息內容、登入與操作紀錄（含 IP 位址）。本商店不蒐集信用卡號或銀行帳號；示範網站的線上付款為模擬流程。

三、利用期間、地區、對象及方式
於蒐集目的存續期間及依法令或帳務需要保存的期間內，在臺灣地區利用；為完成配送與客服，必要時提供給物流業者。

四、Cookie
必要 Cookie 用於維持登入與購物車，無法關閉。分析與廣告 Cookie 只有在您同意後才會啟用，您可以隨時從頁面底部的「Cookie 設定」變更選擇。

五、您的權利
您可以依個人資料保護法請求查詢、閱覽、製給複製本、補充或更正、停止蒐集處理利用或刪除。會員可在會員中心申請刪除帳號；刪除後姓名、地址、電話等個人資料會被清除，訂單編號與金額依帳務需要保留。

六、聯絡方式
如需行使權利或有任何疑問，請透過下方聯絡方式或「聯繫我們」表單與我們聯絡。"""

returns = """依消費者保護法第 19 條，以通訊交易方式購買的商品，享有 7 日猶豫期的解除權。本商店提供自訂單完成起 {退換貨天數} 天的退換貨期間。請依實際營運修改本範本，並請法律人員確認。

一、申請方式
收到商品後，請在「我的訂單」對整筆訂單或個別商品按「退貨」或「換貨」，依步驟選擇品項與數量、原因並送出。訂單在「已出貨」時申請，系統會先完成訂單再建立售後單。

二、期限
自訂單完成時間起算 {退換貨天數} 天，到期日當天 23:59:59 截止。宅配訂單出貨滿 {自動完成天數} 天，系統會自動完成訂單。

三、寄回
客服核准後會寄出寄回說明信（含寄回地址與售後單編號），請將商品連同配件、贈品與包裝寄回，並可在訂單頁填寫寄回單號。

四、換貨
換貨限同一商品、同一規格、同樣數量，不補差價。我們收到並驗收寄回的商品後，才會寄出新品。

五、退款
貨到付款與超商取貨付款訂單，於驗收退貨後由客服與您聯繫退款方式，本商店不會要求您在網站上輸入銀行帳號。線上付款訂單以原付款方式退回。整筆訂單全部退貨時一併退還運費與貨到付款手續費（{貨到付款手續費}）；部分退貨不退運費。

六、無法退換的情形
商品因可歸責於消費者的事由而毀損、滅失或變更者，或依法令公告排除適用解除權的商品，不在此限。"""

guide = """歡迎選購 {營運者名稱} 的商品。請依實際營運修改本範本，並請法律人員確認。

一、訂購流程
加入購物車 → 前往結帳 → 選擇配送與付款方式 → 確認金額並送出。訂單成立後會寄出通知信，您可以在「我的訂單」查看進度。

二、付款方式
宅配可選貨到付款（手續費 {貨到付款手續費}）、信用卡或 PayPal；超商取貨可選取貨付款、信用卡或 PayPal。本示範網站的信用卡與 PayPal 為模擬付款，不會實際扣款，也不需要輸入卡號。線上付款需在期限內完成，逾時訂單自動取消。

三、運費
依商品的運費規則計算，整筆訂單只收一次，取最高的運費；達免運門檻的規則不收費。超商取貨為固定運費。實際金額以結帳頁顯示為準。

四、出貨與收貨
訂單處理完成後出貨，您會收到出貨通知與追蹤碼。收到商品後請按「我已收到，完成訂單」；宅配出貨滿 {自動完成天數} 天系統會自動完成。超商取貨請在到店通知期限內取貨，逾期退回視同拒收。

五、取消訂單
訂單在「處理中」或「待付款」時，可在訂單頁自行取消，庫存會立即回補；已出貨的訂單請改用退貨。

六、退換貨
請參考「退貨條款」，訂單完成後 {退換貨天數} 天內可申請。"""

d = ["-- 預設資料（全新安裝必須匯入）：等級門檻、公開文件範本、模擬門市",
     "SET NAMES utf8mb4;", "SET time_zone = '+08:00';",
     "INSERT IGNORE INTO member_level_thresholds (level, threshold) VALUES ('regular', 0), ('silver', 3000), ('gold', 10000), ('diamond', 30000);",
     "INSERT IGNORE INTO site_contents (content_key, content) VALUES",
     "  ('operator_name', ''), ('contact_info', ''),",
     f"  ('privacy', {q(privacy)}),", f"  ('returns', {q(returns)}),", f"  ('shopping_guide', {q(guide)});"]
stores = []
cities = [('臺北市','信義區'),('臺北市','大安區'),('新北市','板橋區'),('桃園市','中壢區'),('臺中市','西屯區'),('臺南市','東區'),('高雄市','苓雅區'),('新竹市','東區')]
chains = [('711','7-ELEVEN','1'),('family','全家','2'),('hilife','萊爾富','3'),('ok','OK','4')]
for ci,(ch,label,pre) in enumerate(chains):
    for i,(city,dist) in enumerate(cities):
        code = pre + str(100000 + ci*100 + i)[1:]
        name = f"{dist[:-1]}{['信義','忠孝','中正','復興','民生','光華','和平','自強'][i]}門市"
        stores.append(f"('{ch}', '{code}', {q(name)}, {q(city)}, {q(dist + ['信義路五段7號','忠孝東路四段1號','中正路100號','復興路2段3號','民生路88號','光華街12號','和平一路5號','自強路9號'][i])})")
d.append("INSERT IGNORE INTO cvs_stores (chain, store_code, name, city, address) VALUES\n  " + ",\n  ".join(stores) + ";")
open(OUT+'01_default_data.sql','w').write("\n".join(d)+"\n")

def bcrypt(pw):
    return subprocess.run(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],capture_output=True,text=True).stdout
PW = bcrypt('Shop@2026')
s = ["-- 範例資料（選用，測試與展示用）：帳號密碼一律為 Shop@2026，上線前請刪除或改密碼",
     "SET NAMES utf8mb4;", "SET time_zone = '+08:00';"]
members = [
 (1,'系統管理者','admin@example.com',1,1,1,'regular','0'),
 (2,'商品小編','product@example.com',1,1,0,'regular','0'),
 (3,'客服小美','support@example.com',1,1,0,'regular','0'),
 (4,'倉庫阿明','warehouse@example.com',1,1,0,'regular','0'),
 (5,'王小明','ming@example.com',1,0,0,'silver','4200.00'),
 (6,'陳美麗','mei@example.com',1,0,0,'gold','12800.00'),
 (7,'林大華','hua@example.com',1,0,0,'regular','0'),
 (8,'待驗證會員','pending@example.com',2,0,0,'regular','0'),
]
rows=[]
for (i,n,e,st,adm,sup,lv,sp) in members:
    created = (datetime.datetime(2026,9,1)+datetime.timedelta(days=i)).strftime('%Y-%m-%d %H:%M:%S')
    ver = 'NULL' if st==2 else q(created)
    rows.append(f"({i}, {q(n)}, {q(e)}, {q(PW)}, {st}, {adm}, {sup}, '{lv}', {sp}, {ver}, {q(created)})")
s.append("INSERT INTO members (id, name, email, password_hash, status, is_admin, is_super_admin, member_level, total_spent, email_verified_at, created_at) VALUES\n  " + ",\n  ".join(rows) + ";")
s.append("INSERT INTO admin_permissions (member_id, module, level, granted_by) VALUES (2,'product','edit',1),(2,'report','view',1),(3,'support','edit',1),(3,'order','edit',1),(3,'member','view',1),(4,'shipping','edit',1),(4,'order','view',1);")
s.append("INSERT INTO site_contents (content_key, content) VALUES ('operator_name', '台灣好物商場示範店'), ('contact_info', '客服 Email：service@example.com\\n客服電話：02-1234-5678（週一至週五 10:00～18:00）\\n個資權利聯絡：privacy@example.com') ON DUPLICATE KEY UPDATE content = VALUES(content);")
s.append("INSERT INTO site_settings (setting_key, setting_value) VALUES ('return_address', '100 臺北市中正區示範路 1 號 台灣好物商場退貨組'), ('cod_fee', '30'), ('store_name', '台灣好物商場'), ('default_brand', '台灣好物'), ('cs_email', 'service@example.com'), ('cs_phone', '+886-2-1234-5678'), ('home_description', '精選台灣在地好物：茶葉、果乾、手作器皿與服飾，線上下單、宅配或超商取貨。') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);")
s.append("INSERT INTO categories (id, name, description) VALUES (1,'茶葉','台灣高山茶、紅茶與花草茶，產地直送。'),(2,'果乾零食','無添加的在地水果乾與堅果。'),(3,'生活器皿','手作陶瓷與木作餐具。'),(4,'服飾','台灣設計的棉質服飾。');")
s.append("INSERT INTO tags (id, name) VALUES (1,'熱銷'),(2,'新品'),(3,'禮盒'),(4,'手作');")
s.append("INSERT INTO shipping_rules (id, name, fee, free_threshold, is_active) VALUES (1,'一般宅配',80,1000,1),(2,'大型商品',150,NULL,1);")
prods = [
 (1,1,1,'TEA-001','阿里山高山烏龍茶 150g','清香型高山烏龍，回甘明顯。', '680.00',1,1),
 (2,1,1,'TEA-002','日月潭紅玉紅茶 75g','台茶 18 號，天然肉桂與薄荷香。','520.00',1,1),
 (3,1,1,'TEA-003','東方美人茶禮盒','蜜香濃郁，附禮盒包裝。','1280.00',1,1),
 (4,2,1,'SNK-001','愛文芒果乾 200g','屏東愛文芒果，無添加糖。','260.00',1,1),
 (5,2,1,'SNK-002','綜合堅果 300g','低溫烘焙，每日一包。','350.00',1,1),
 (6,3,2,'WAR-001','手作陶瓷大湯碗','手拉坯，每件釉色略有不同。','980.00',1,0),
 (7,3,None,'WAR-002','檜木筷子組','台灣檜木，五雙入。','420.00',1,1),
 (8,4,1,'TSH-001','台灣設計棉 T','100% 棉，台灣製造。','450.00',1,1),
 (9,2,1,'SNK-003','鳳梨酥 12 入（下架）','季節限定已下架。','480.00',0,1),
]
rows=[]
for (i,c,r,sku,n,dsc,pr,act,cvs) in prods:
    rows.append(f"({i}, {c}, {'NULL' if r is None else r}, {q(sku)}, {q(n)}, {q(dsc)}, {pr}, {act}, {cvs})")
s.append("INSERT INTO products (id, category_id, shipping_rule_id, sku, name, description, price, is_active, allow_cvs) VALUES\n  " + ",\n  ".join(rows) + ";")
s.append("INSERT INTO product_tags (product_id, tag_id) VALUES (1,1),(3,3),(3,1),(4,2),(6,4),(7,4),(8,2);")
stock = {1:25,2:3,3:8,4:40,5:0,6:4,7:12,8:0,9:10}
vrows=[]; irows=[]
for (i,*_rest) in prods:
    vrows.append(f"({i}, {i}, 'default', {q(_rest[2])}, NULL, NULL, NULL, 1, {0 if i==8 else 1})")
    irows.append(f"({i}, {stock[i]})")
# 商品 8：顏色 × 尺寸
s.append("INSERT INTO product_variants (id, product_id, variant_key, sku, option_value1_id, option_value2_id, price, is_default, is_active) VALUES\n  " + ",\n  ".join(vrows) + ";")
s.append("INSERT INTO product_options (id, product_id, position, name) VALUES (1,8,1,'顏色'),(2,8,2,'尺寸');")
s.append("INSERT INTO product_option_values (id, option_id, value, sort_order) VALUES (1,1,'白',0),(2,1,'藏青',1),(3,2,'S',0),(4,2,'M',1),(5,2,'L',2);")
vid=10; combos=[]
for a,an in [(1,'W'),(2,'N')]:
    for b,bn in [(3,'S'),(4,'M'),(5,'L')]:
        price = 'NULL' if b!=5 else '490.00'
        combos.append((vid,a,b,f"TSH-001-{an}{bn}",price)); vid+=1
s.append("INSERT INTO product_variants (id, product_id, variant_key, sku, option_value1_id, option_value2_id, price, is_default, is_active) VALUES\n  " +
         ",\n  ".join(f"({v}, 8, '{a}-{b}', {q(sku)}, {a}, {b}, {p}, 0, 1)" for v,a,b,sku,p in combos) + ";")
istock = {10:5,11:8,12:2,13:0,14:6,15:3}
irows += [f"({v}, {istock[v]})" for v,*_ in combos]
s.append("INSERT INTO variant_inventory (variant_id, quantity) VALUES " + ", ".join(irows) + ";")
# 行為事件（近 45 天）
ev=[]; eid=1
now = datetime.datetime(2026,11,21,10,0,0)
for d in range(45,0,-1):
    day = now - datetime.timedelta(days=d)
    for k in range(random.randint(3,12)):
        mid = random.choice([5,6,7]); pid = random.choice([1,2,3,4,5,6,7,8])
        t = day + datetime.timedelta(minutes=random.randint(0,1400))
        typ = random.choices(['view_product','add_to_cart'],[5,1])[0]
        lv = {5:'silver',6:'gold',7:'regular'}[mid]; cat={1:1,2:1,3:1,4:2,5:2,6:3,7:3,8:4}[pid]
        if typ=='view_product':
            ev.append(f"('{t:%Y-%m-%d %H:%M:%S}.000000','view_product','{random.randrange(16**32):032x}',{mid},'{lv}',{pid},{cat},NULL,NULL,NULL,NULL,NULL)")
        else:
            pr = {1:680,2:520,3:1280,4:260,5:350,6:980,7:420,8:450}[pid]
            ev.append(f"('{t:%Y-%m-%d %H:%M:%S}.000000','add_to_cart','{random.randrange(16**32):032x}',{mid},'{lv}',{pid},{cat},0,1,1,{pr},{pr})")
s.append("INSERT INTO member_behavior_events (event_time, event_type, session_key, member_id, member_level_at_event, product_id, category_id_at_event, quantity_before, quantity_after, quantity_delta, unit_price, amount_delta) VALUES\n  " + ",\n  ".join(ev) + ";")
s.append("-- 範例客服訊息")
s.append("INSERT INTO support_messages (message_no, source, member_id, name, email, category, subject, body, ip, created_at) VALUES ('M20261120-000001','contact',NULL,'訪客甲','guest@example.com','product','請問烏龍茶的保存方式','您好，想請問開封後應該如何保存比較好？謝謝。','203.0.113.10','2026-11-20 14:00:00');")
s.append("INSERT INTO support_message_events (message_id, event_type, to_status, is_public) VALUES (1,'create','new',1);")
s.append("INSERT INTO doc_sequences (doc_type, doc_date, last_no) VALUES ('M','2026-11-20',1);")
open(OUT+'10_sample_data.sql','w').write("\n".join(s)+"\n")
print('ok')
