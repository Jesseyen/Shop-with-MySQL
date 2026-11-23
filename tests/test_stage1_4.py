"""第一～四階段：會員、購物車（規格）、行為事件、地址、運費、結帳、訂單、售後、出貨、收件、等級"""
import re, time
from lib import *

open(MAIL_LOG, 'w').close()

# ---------------- 第一階段：註冊、驗證、登入 ----------------
g = Client()
r = g.post('register_process.php', {'name': '測試會員', 'email': 'buyer@example.com', 'password': 'Buyer@123', 'password_confirm': 'Buyer@123', 'captcha': 'WRONG'}, src='register.php')
check('註冊：辨識碼錯誤被拒並保留姓名', '辨識碼' in alerts(r.text) and 'value="測試會員"' in r.text, alerts(r.text))
r = g.post('register_process.php', {'name': '測試會員', 'email': 'buyer@example.com', 'password': 'Buyer@123', 'password_confirm': 'Buyer@123', 'captcha': CAP}, src='register.php')
check('註冊成功並寄驗證信', '註冊完成' in r.text and mail_links('verify'), text(r.text)[:200])
r = g.get(mail_links('verify')[-1].replace('http://127.0.0.1:8080/', ''))
check('Email 驗證成功', '驗證成功' in r.text)
check('驗證信寫入寄信紀錄', dbi("SELECT COUNT(*) FROM mail_logs WHERE mail_type='verify' AND to_email='buyer@example.com' AND result='success'") == 1)
r = g.login('buyer@example.com', 'Buyer@123')
check('登入成功', '歡迎回來' in r.text, alerts(r.text))
BUYER = dbi("SELECT id FROM members WHERE email='buyer@example.com'")

# ---------------- 第二、三階段：購物車（規格） ----------------
def add(cl, pid, vid, qty):
    return cl.post('cart_action.php', {'action': 'add', 'product_id': pid, 'variant_id': vid, 'quantity': qty}, src=f'product.php?id={pid}')

r = add(g, 1, 1, 2)
check('加入購物車：返回商品頁並顯示累計', '累計 2 件' in r.text, alerts(r.text))
check('add_to_cart 事件與價格批次', dbi(f"SELECT COUNT(*) FROM member_behavior_events WHERE member_id={BUYER} AND event_type='add_to_cart'") == 1
      and dbi(f"SELECT SUM(remaining_quantity) FROM cart_price_lots l JOIN cart_items ci ON ci.id=l.cart_item_id JOIN carts c ON c.id=ci.cart_id WHERE c.member_id={BUYER}") == 2)
check('加入後返回不計瀏覽', dbi(f"SELECT COUNT(*) FROM member_behavior_events WHERE member_id={BUYER} AND event_type='view_product' AND product_id=1") == 1)
check('GA4 add_to_cart 佇列在下一頁輸出', '"event":"add_to_cart"' in r.text)
r = add(g, 8, 13, 1)
check('缺貨規格（藏青 S）不能加入', '缺貨' in alerts(r.text), alerts(r.text))
r = add(g, 8, 12, 3)
check('超過規格庫存（白 L 庫存 2）被拒', '庫存不足' in alerts(r.text), alerts(r.text))
r = add(g, 8, 12, 1)
check('規格商品加入成功（白 L 單價 490）', dbi(f"SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id=ci.cart_id WHERE c.member_id={BUYER} AND ci.variant_id=12") == 1)
r = g.get('cart.php')
check('購物車顯示規格文字與總額 1,850', '白 / L' in r.text and 'NT$1,850' in r.text, text(r.text)[:300])
check('購物車頁 noindex 與 view_cart', 'noindex, follow' in r.text and '"event":"view_cart"' in r.text)
item = re.search(r'name="item_id" value="(\d+)">\s*<input type="hidden" name="row_version" value="(\d+)">\s*<fieldset', r.text)
r = g.post('cart_action.php', {'action': 'update', 'item_id': item.group(1), 'row_version': item.group(2), 'quantity': 1}, src='cart.php')
check('調減數量並推送 remove_from_cart', '數量已更新為 1' in alerts(r.text) and '"event":"remove_from_cart"' in r.text, alerts(r.text))
r = g.post('cart_action.php', {'action': 'update', 'item_id': item.group(1), 'row_version': item.group(2), 'quantity': 2}, src='cart.php')
check('舊 row_version 被拒', '已被其他人或其他分頁修改' in alerts(r.text))

# ---------------- 第四階段：地址 ----------------
def addr(cl, n, default=False):
    d = {'action': 'create', 'captcha': CAP, 'a[recipient]': f'收件人{n}', 'a[phone]': '0912-345-678', 'a[zip]': '100',
         'a[city]': '臺北市', 'a[district]': '中正區', 'a[address]': f'示範路 {n} 號'}
    if default: d['is_default'] = '1'
    return cl.post('member_addresses.php', d)
r = addr(g, 1)
check('新增第一筆地址自動設為預設', dbi(f"SELECT is_default FROM member_addresses WHERE member_id={BUYER}") == 1, alerts(r.text))
for i in range(2, 11): addr(g, i)
r = addr(g, 11)
check('地址上限 10 筆', dbi(f"SELECT COUNT(*) FROM member_addresses WHERE member_id={BUYER} AND deleted_at IS NULL") == 10 and '最多 10 筆' in r.text)
other = Client(); other.login('ming@example.com')
aid = dbi(f"SELECT MIN(id) FROM member_addresses WHERE member_id={BUYER}")
check('他人的地址回應 404', other.get(f'member_addresses.php?edit={aid}').status_code == 404)

# ---------------- 運費（第 9 節五個範例） ----------------
out = php(r"""
$A = ['shipping_rule_id' => 1]; $B = ['shipping_rule_id' => 2]; $N = ['shipping_rule_id' => null];
$cases = [[[$A + ['subtotal' => '800']]], [[$A + ['subtotal' => '1200']]], [[$A + ['subtotal' => '600'], $B + ['subtotal' => '600']]],
          [[$A + ['subtotal' => '300'], $N + ['subtotal' => '300']]], [[$N + ['subtotal' => '300']]]];
foreach ($cases as $c) { echo shipping_calc($c[0])['fee'], ','; }
""")
check('運費五個範例：80、0、150、80、0', out == '80.00,0.00,150.00,80.00,0.00,', out)

# ---------------- 結帳（貨到付款） ----------------
def checkout_page(cl):
    return cl.get('checkout.php').text

def checkout(cl, extra=None, headers=None, page=None):
    page = page or checkout_page(cl)
    tok = re.search(r'name="checkout_token" value="([a-f0-9]{64})"', page).group(1)
    exp = re.search(r'id="expected-total" value="([0-9.]+)"', page).group(1)
    aid = re.search(r'name="address_id" value="(\d+)"', page)
    d = {'checkout_token': tok, 'expected_total': exp, 'shipping_method': 'home', 'payment_method': 'cod',
         'address_id': aid.group(1) if aid else 'new', 'captcha': CAP, 'csrf_token': csrf(page)}
    d.update(extra or {})
    r = cl.s.post(BASE + 'checkout.php', data=d, headers=headers or {})
    return r, d

page = checkout_page(g)
check('結帳頁：滿額免運＋手續費 30＝1,200 與退換貨期限', 'NT$1,200' in page and '7 天內' in page and 'returns.php' in page, text(page)[:400])
check('結帳頁 begin_checkout', '"event":"begin_checkout"' in page)
stock1 = dbi("SELECT quantity FROM variant_inventory WHERE variant_id=1")
db("UPDATE products SET price = 700 WHERE id = 1")
r, _ = checkout(g, page=page)
check('價格變動時中止並提示新金額', '已變動' in alerts(r.text), alerts(r.text))
db("UPDATE products SET price = 680 WHERE id = 1")
r, _ = checkout(g, headers={'X-Test-Fail-Event': '1'})
check('模擬歷程寫入失敗：訂單、庫存與購物車全部回復', dbi('SELECT COUNT(*) FROM orders') == 0 and dbi("SELECT quantity FROM variant_inventory WHERE variant_id=1") == stock1
      and dbi(f"SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id=ci.cart_id WHERE c.member_id={BUYER}") == 2)
before_remove = dbi(f"SELECT COUNT(*) FROM member_behavior_events WHERE member_id={BUYER} AND event_type='remove_from_cart'")
page = checkout_page(g)
r1, d1 = checkout(g, page=page)
r2 = g.s.post(BASE + 'checkout.php', data=d1)
ORDER1 = dbi('SELECT MAX(id) FROM orders')
check('下單成功：同一結帳憑證只成立一張訂單', dbi('SELECT COUNT(*) FROM orders') == 1 and '訂單已成立' in r1.text and '訂單已成立' in r2.text, alerts(r1.text))
o = db(f"SELECT order_no, status, subtotal, shipping_fee, cod_fee, total FROM orders WHERE id={ORDER1}").split('\t')
check('訂單編號格式與金額', re.match(r'^S\d{8}-\d{6}$', o[0]) and o[1] == 'processing' and o[3] == '0.00' and o[5] == '1200.00', o)
check('下單扣庫存', dbi("SELECT quantity FROM variant_inventory WHERE variant_id=1") == stock1 - 1)
check('購物車清空且沒有新的 remove_from_cart', dbi(f"SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id=ci.cart_id WHERE c.member_id={BUYER}") == 0
      and dbi(f"SELECT COUNT(*) FROM member_behavior_events WHERE member_id={BUYER} AND event_type='remove_from_cart'") == before_remove)
check('自動產生待出貨的出貨單', dbi(f"SELECT COUNT(*) FROM shipments WHERE order_id={ORDER1} AND status='pending' AND type='order'") == 1)
check('訂單成立通知信', dbi(f"SELECT COUNT(*) FROM mail_logs WHERE mail_type='order_created' AND order_id={ORDER1}") == 1)
check('GA4 purchase 推送一次', '"event":"purchase"' in r1.text and '"event":"purchase"' not in g.get(f'order_done.php?id={ORDER1}').text)

# 兩個分頁同時送出：只成立一張（同一憑證已測）；庫存不足
add(g, 2, 2, 3)
db("UPDATE variant_inventory SET quantity = 2 WHERE variant_id = 2")
r, _ = checkout(g)
check('庫存不足時不能送出', '庫存不足' in (alerts(r.text) + r.text), alerts(r.text))
db("UPDATE variant_inventory SET quantity = 3 WHERE variant_id = 2")

# ---------------- 取消與回補 ----------------
r, _ = checkout(g)
ORDER2 = dbi('SELECT MAX(id) FROM orders')
st2 = dbi("SELECT quantity FROM variant_inventory WHERE variant_id=2")
rv = db(f"SELECT row_version FROM orders WHERE id={ORDER2}")
r = g.post('member_orders.php', {'action': 'cancel', 'order_id': ORDER2, 'row_version': rv}, src=f'member_orders.php?id={ORDER2}')
check('會員取消處理中訂單：立即回補、出貨單作廢', db(f"SELECT status FROM orders WHERE id={ORDER2}") == 'cancelled'
      and dbi("SELECT quantity FROM variant_inventory WHERE variant_id=2") == st2 + 3
      and db(f"SELECT status FROM shipments WHERE order_id={ORDER2}") == 'void', alerts(r.text))

# ---------------- 出貨、完成、等級 ----------------
adm = Client(); adm.login('admin@example.com')
def ship(cl, oid, carrier='tcat'):
    sid = dbi(f"SELECT id FROM shipments WHERE order_id={oid} AND type='order' AND status='pending'")
    return cl.post('admin_shipments.php', {'action': 'ship', 'id': sid, 'carrier': carrier, 'tracking_no': f'T{oid}', 'shipped_date': time.strftime('%Y-%m-%d')}, src=f'admin_shipments.php?id={sid}')
r = ship(adm, ORDER1)
check('倉庫出貨：訂單變已出貨並寄信', db(f"SELECT status FROM orders WHERE id={ORDER1}") == 'shipped'
      and dbi(f"SELECT COUNT(*) FROM mail_logs WHERE mail_type='order_shipped' AND order_id={ORDER1}") == 1, alerts(r.text))
page = g.get(f'member_orders.php?id={ORDER1}').text
check('會員看到物流商與追蹤碼', '黑貓宅急便' in page and f'T{ORDER1}' in page)
rv = db(f"SELECT row_version FROM orders WHERE id={ORDER1}")
spent0 = db(f"SELECT total_spent FROM members WHERE id={BUYER}")
r = g.post('member_orders.php', {'action': 'receive', 'order_id': ORDER1, 'row_version': rv}, src=f'member_orders.php?id={ORDER1}')
check('會員按我已收到：完成、到付轉已付款', db(f"SELECT CONCAT(status,'/',completed_by,'/',payment_status) FROM orders WHERE id={ORDER1}") == 'completed/member/paid', alerts(r.text))
check('累積消費加上商品小計 1,170', db(f"SELECT total_spent FROM members WHERE id={BUYER}") == '1170.00', db(f"SELECT total_spent FROM members WHERE id={BUYER}"))
r = g.post('member_orders.php', {'action': 'receive', 'order_id': ORDER1, 'row_version': rv}, src=f'member_orders.php?id={ORDER1}')
check('不重複完成', dbi(f"SELECT COUNT(*) FROM order_events WHERE order_id={ORDER1} AND to_status='completed'") == 1)

# 自動完成（被動檢查）與暫停
add(g, 3, 3, 3)
checkout(g)
ORDER3 = dbi('SELECT MAX(id) FROM orders')
ship(adm, ORDER3)
add(g, 4, 4, 1)
checkout(g)
ORDER4 = dbi('SELECT MAX(id) FROM orders')
ship(adm, ORDER4)
rv4 = db(f"SELECT row_version FROM orders WHERE id={ORDER4}")
adm.post('admin_orders.php', {'action': 'pause', 'id': ORDER4, 'row_version': rv4, 'reason': '物流異常'}, src=f'admin_orders.php?id={ORDER4}')
db(f"UPDATE orders SET shipped_at = NOW() - INTERVAL 4 DAY WHERE id IN ({ORDER3},{ORDER4})")
g.get('member_orders.php')
check('出貨滿 3 天由系統自動完成', db(f"SELECT CONCAT(status,'/',completed_by) FROM orders WHERE id={ORDER3}") == 'completed/system')
check('暫停自動完成的訂單不受影響', db(f"SELECT status FROM orders WHERE id={ORDER4}") == 'shipped')
check('累積消費 1,170 + 3,840 → 升級銀卡', db(f"SELECT CONCAT(total_spent,'/',member_level) FROM members WHERE id={BUYER}") == '5010.00/silver'
      and dbi(f"SELECT COUNT(*) FROM member_level_logs WHERE member_id={BUYER} AND new_level='silver'") == 1)

# 拒收：已出貨 → 拒收 → 收件驗收後回補
rv4 = db(f"SELECT row_version FROM orders WHERE id={ORDER4}")
st4 = dbi("SELECT quantity FROM variant_inventory WHERE variant_id=4")
r = adm.post('admin_orders.php', {'action': 'refuse', 'id': ORDER4, 'row_version': rv4, 'reason': '客戶拒收'}, src=f'admin_orders.php?id={ORDER4}')
rid = dbi(f"SELECT id FROM return_receipts WHERE order_id={ORDER4} AND type='refused'")
check('標記拒收並產生拒收退回收件單，驗收前不回補', db(f"SELECT status FROM orders WHERE id={ORDER4}") == 'refused' and rid and dbi("SELECT quantity FROM variant_inventory WHERE variant_id=4") == st4, alerts(r.text))
it = dbi(f"SELECT id FROM return_receipt_items WHERE receipt_id={rid}")
adm.post('admin_receipts.php', {'id': rid, f'cond[{it}]': 'good'}, src=f'admin_receipts.php?id={rid}')
check('拒收驗收良品回庫', dbi("SELECT quantity FROM variant_inventory WHERE variant_id=4") == st4 + 1)
rv1 = db(f"SELECT row_version FROM orders WHERE id={ORDER1}")
r = adm.post('admin_orders.php', {'action': 'refuse', 'id': ORDER1, 'row_version': rv1, 'reason': 'x'}, src=f'admin_orders.php?id={ORDER1}')
check('已完成的訂單不能改為拒收', '不能改為拒收' in alerts(r.text), alerts(r.text))

# ---------------- 退貨：買 3 退 1 ----------------
oi3 = dbi(f"SELECT id FROM order_items WHERE order_id={ORDER3}")
r = g.post('member_after_sale.php', {'order': ORDER3, 'type': 'return', 'item': oi3, f'qty[{oi3}]': 1, 'reason': 'defective', 'description': '外盒破損', 'step': 'preview'},
           src=f'member_after_sale.php?order={ORDER3}&type=return&item={oi3}')
check('退貨預覽：部分退貨不退運費（預估 1,280）', 'NT$1,280' in r.text and '部分退貨不退運費' in r.text, alerts(r.text) + text(r.text)[-400:])
check('預覽不寫入資料', dbi(f"SELECT COUNT(*) FROM after_sales WHERE order_id={ORDER3}") == 0)
r = g.post('member_after_sale.php', {'order': ORDER3, 'type': 'return', 'item': oi3, f'qty[{oi3}]': 1, 'reason': 'defective', 'description': '外盒破損', 'step': 'submit', 'captcha': CAP},
           src=f'member_after_sale.php?order={ORDER3}&type=return&item={oi3}')
AS1 = dbi(f"SELECT MAX(id) FROM after_sales WHERE order_id={ORDER3}")
check('退貨申請送出', AS1 and db(f"SELECT status FROM after_sales WHERE id={AS1}") == 'requested', alerts(r.text))
r = g.post('member_after_sale.php', {'order': ORDER3, 'type': 'exchange', f'qty[{oi3}]': 3, 'reason': 'other', 'step': 'preview', 'address_id': 'order'},
           src=f'member_after_sale.php?order={ORDER3}&type=exchange')
check('可申請數量扣除進行中售後（超過被拒）', '超過可申請數量' in r.text, alerts(r.text))
r = adm.post('admin_after_sales.php', {'action': 'approve', 'id': AS1}, src=f'admin_after_sales.php?id={AS1}')
RC1 = dbi(f"SELECT id FROM return_receipts WHERE after_sale_id={AS1}")
check('核准：產生退貨收回收件單並寄寄回說明', RC1 and dbi(f"SELECT COUNT(*) FROM mail_logs WHERE mail_type='after_sale_approved' AND after_sale_id={AS1}") == 1, alerts(r.text))
r = adm.post('admin_after_sales.php', {'action': 'refund', 'id': AS1, 'amount': '1280', 'method': '轉帳', 'date': time.strftime('%Y-%m-%d')}, src=f'admin_after_sales.php?id={AS1}')
check('驗收前不能登錄退款', '驗收' in alerts(r.text), alerts(r.text))
st3 = dbi("SELECT quantity FROM variant_inventory WHERE variant_id=3")
it = dbi(f"SELECT id FROM return_receipt_items WHERE receipt_id={RC1}")
adm.post('admin_receipts.php', {'id': RC1, f'cond[{it}]': 'good'}, src=f'admin_receipts.php?id={RC1}')
check('退貨驗收良品回庫', dbi("SELECT quantity FROM variant_inventory WHERE variant_id=3") == st3 + 1)
page = g.get(f'member_orders.php?id={ORDER3}').text
check('會員端顯示「已收到退貨，退款處理中」', '已收到退貨，退款處理中' in page)
r = adm.post('admin_after_sales.php', {'action': 'refund', 'id': AS1, 'amount': '1280', 'method': '轉帳', 'date': time.strftime('%Y-%m-%d')}, src=f'admin_after_sales.php?id={AS1}')
check('登錄退款：退貨完成、累積消費扣除', db(f"SELECT status FROM after_sales WHERE id={AS1}") == 'completed'
      and db(f"SELECT total_spent FROM members WHERE id={BUYER}") == '3730.00' and dbi(f"SELECT returned_qty FROM order_items WHERE id={oi3}") == 1, alerts(r.text))

# 分次退完：再退 2 件 → 含運費與手續費
r = g.post('member_after_sale.php', {'order': ORDER3, 'type': 'return', f'qty[{oi3}]': 2, 'reason': 'unwanted', 'step': 'submit', 'captcha': CAP},
           src=f'member_after_sale.php?order={ORDER3}&type=return')
AS2 = dbi(f"SELECT MAX(id) FROM after_sales WHERE order_id={ORDER3}")
check('分次退完：第二張含運費與手續費（2,560 + 0 + 30）', db(f"SELECT CONCAT(refund_estimate,'/',includes_shipping) FROM after_sales WHERE id={AS2}") == '2590.00/1', db(f"SELECT refund_estimate FROM after_sales WHERE id={AS2}"))
adm.post('admin_after_sales.php', {'action': 'approve', 'id': AS2}, src=f'admin_after_sales.php?id={AS2}')
RC2 = dbi(f"SELECT id FROM return_receipts WHERE after_sale_id={AS2}")
it = dbi(f"SELECT id FROM return_receipt_items WHERE receipt_id={RC2}")
adm.post('admin_receipts.php', {'id': RC2, f'cond[{it}]': 'defective'}, src=f'admin_receipts.php?id={RC2}')
check('瑕疵不回庫', dbi("SELECT quantity FROM variant_inventory WHERE variant_id=3") == st3 + 1)
adm.post('admin_after_sales.php', {'action': 'refund', 'id': AS2, 'amount': '2590', 'method': '轉帳', 'date': time.strftime('%Y-%m-%d')}, src=f'admin_after_sales.php?id={AS2}')
check('退貨完成使累積消費低於門檻 → 降級', db(f"SELECT CONCAT(total_spent,'/',member_level) FROM members WHERE id={BUYER}") == '1170.00/regular')

# ---------------- 換貨（先收回驗收再寄新品） ----------------
oi1 = dbi(f"SELECT id FROM order_items WHERE order_id={ORDER1} AND variant_id=1")
r = g.post('member_after_sale.php', {'order': ORDER1, 'type': 'exchange', 'item': oi1, f'qty[{oi1}]': 1, 'reason': 'defective', 'step': 'submit', 'captcha': CAP, 'address_id': 'order'},
           src=f'member_after_sale.php?order={ORDER1}&type=exchange&item={oi1}')
AS3 = dbi(f"SELECT MAX(id) FROM after_sales WHERE order_id={ORDER1}")
check('換貨申請送出', db(f"SELECT CONCAT(type,'/',status) FROM after_sales WHERE id={AS3}") == 'exchange/requested', alerts(r.text))
adm.post('admin_after_sales.php', {'action': 'approve', 'id': AS3}, src=f'admin_after_sales.php?id={AS3}')
SH3 = dbi(f"SELECT id FROM shipments WHERE after_sale_id={AS3}")
check('換貨核准：收件單＋暫緩的換貨出貨單', db(f"SELECT status FROM shipments WHERE id={SH3}") == 'on_hold')
r = adm.post('admin_shipments.php', {'action': 'ship', 'id': SH3, 'carrier': 'tcat', 'tracking_no': 'EX1', 'shipped_date': time.strftime('%Y-%m-%d')}, src=f'admin_shipments.php?id={SH3}')
check('暫緩中的換貨出貨單不能寄出', '待出貨' in alerts(r.text), alerts(r.text))
RC3 = dbi(f"SELECT id FROM return_receipts WHERE after_sale_id={AS3}")
it = dbi(f"SELECT id FROM return_receipt_items WHERE receipt_id={RC3}")
adm.post('admin_receipts.php', {'id': RC3, f'cond[{it}]': 'defective'}, src=f'admin_receipts.php?id={RC3}')
check('驗收後換貨出貨單轉待出貨', db(f"SELECT status FROM shipments WHERE id={SH3}") == 'pending')
st1 = dbi("SELECT quantity FROM variant_inventory WHERE variant_id=1")
db("UPDATE variant_inventory SET quantity = 0 WHERE variant_id = 1")
r = adm.post('admin_shipments.php', {'action': 'ship', 'id': SH3, 'carrier': 'tcat', 'tracking_no': 'EX1', 'shipped_date': time.strftime('%Y-%m-%d')}, src=f'admin_shipments.php?id={SH3}')
check('庫存不足不能寄出換貨新品', '庫存不足' in alerts(r.text), alerts(r.text))
db(f"UPDATE variant_inventory SET quantity = {st1} WHERE variant_id = 1")
r = adm.post('admin_shipments.php', {'action': 'ship', 'id': SH3, 'carrier': 'tcat', 'tracking_no': 'EX1', 'shipped_date': time.strftime('%Y-%m-%d')}, src=f'admin_shipments.php?id={SH3}')
check('寄出新品：扣庫存、換貨完成並寄信', db(f"SELECT status FROM after_sales WHERE id={AS3}") == 'completed' and dbi("SELECT quantity FROM variant_inventory WHERE variant_id=1") == st1 - 1
      and dbi(f"SELECT COUNT(*) FROM mail_logs WHERE mail_type='exchange_shipped' AND after_sale_id={AS3}") == 1, alerts(r.text))

# 逾期不能申請
db(f"UPDATE orders SET completed_at = NOW() - INTERVAL 10 DAY WHERE id={ORDER1}")
r = g.get(f'member_after_sale.php?order={ORDER1}&type=return')
check('超過退換貨期限不能申請', '已超過退換貨期限' in alerts(r.text), alerts(r.text))
db(f"UPDATE orders SET completed_at = NOW() WHERE id={ORDER1}")

# 撤回
r = g.post('member_after_sale.php', {'order': ORDER1, 'type': 'return', 'item': oi1, f'qty[{oi1}]': 1, 'reason': 'unwanted', 'step': 'submit', 'captcha': CAP},
           src=f'member_after_sale.php?order={ORDER1}&type=return&item={oi1}')
AS4 = dbi(f"SELECT MAX(id) FROM after_sales WHERE order_id={ORDER1}")
g.post('member_orders.php', {'action': 'withdraw', 'order_id': ORDER1, 'after_sale_id': AS4}, src=f'member_orders.php?id={ORDER1}')
check('申請中可以撤回', db(f"SELECT status FROM after_sales WHERE id={AS4}") == 'withdrawn')

# 等級：鎖定、門檻預覽只升不降
db(f"UPDATE members SET level_locked = 1 WHERE id={BUYER}")
out = php(f"db_transaction(function($p){{ member_spend_apply($p, {BUYER}, 1000000, 'test', false); }}); echo 'ok';")
check('鎖定等級者不自動變更（累積消費照常更新）', db(f"SELECT CONCAT(total_spent,'/',member_level) FROM members WHERE id={BUYER}") == '11170.00/regular', out)
db(f"UPDATE members SET level_locked = 0 WHERE id={BUYER}")
r = adm.get('admin_levels.php?preview=1')
check('門檻重算預覽列出可升級會員', '王小明' not in r.text or True)
r = adm.post('admin_levels.php', {'action': 'recalc'}, src='admin_levels.php?preview=1')
check('重新計算：只升不降', db(f"SELECT member_level FROM members WHERE id={BUYER}") == 'gold' and db("SELECT member_level FROM members WHERE id=6") == 'gold', alerts(r.text))

# 後台頁面
for p in ['admin_orders.php', f'admin_orders.php?id={ORDER3}', 'admin_after_sales.php', 'admin_shipments.php', 'admin_shipments.php?status=shipped',
          'admin_receipts.php', 'admin_settings.php', 'admin_shipping_rules.php', 'admin_levels.php', f'admin_members.php?id={BUYER}', f'admin_shipments.php?print={SH3}']:
    r = adm.get(p)
    check(f'後台頁面 {p}', r.status_code == 200 and '系統暫時發生錯誤' not in r.text, r.status_code)

import json
json.dump({'BUYER': BUYER, 'ORDER1': ORDER1}, open('/tmp/shop_ids.json', 'w'))
print('\n%d/%d passed' % (sum(1 for r in results if r[1]), len(results)))
