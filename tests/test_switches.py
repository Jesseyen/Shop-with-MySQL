"""開關組合：關閉某些階段時其他功能照常運作（頁面可開、無 PHP 錯誤）"""
import re, shutil, os
from lib import *
CFG = os.path.join(HTDOCS, 'config.php')
orig = open(CFG).read()
combos = {
    '1～5 階段（關閉第六）': dict(enable_launch='false'),
    '1～4 階段（關閉第五、六）': dict(enable_ops='false', enable_launch='false'),
    '1～3＋5 階段（關閉第四）': dict(enable_orders='false'),
    '1＋5 階段（關閉購物車）': dict(enable_cart='false'),
    '只有第一階段': dict(enable_cart='false', enable_ops='false'),
}
front = ['index.php', 'products.php', 'product.php?id=8', 'product.php?id=1', 'login.php', 'register.php', 'contact.php', 'privacy.php']
member = ['member.php', 'cart.php', 'member_orders.php', 'checkout.php', 'member_support.php', 'member_addresses.php']
admin = ['admin.php', 'admin_products.php', 'admin_products.php?id=8', 'admin_members.php', 'admin_inventory.php', 'admin_categories.php', 'admin_orders.php',
         'admin_behavior_reports.php', 'admin_audit_logs.php', 'admin_contents.php', 'admin_store.php']
try:
    for name, flags in combos.items():
        s = orig
        for k, v in flags.items():
            s = re.sub(r'\$' + k + r'\s*=\s*\w+;', f'${k} = {v};', s)
        open(CFG, 'w').write(s)
        db("DELETE FROM error_logs")
        v = Client(); m = Client(); m.login('ming@example.com'); a = Client(); a.login('admin@example.com')
        bad = []
        for p in front:
            r = v.get(p)
            if r.status_code not in (200, 404) or '系統暫時發生錯誤' in r.text or 'Fatal' in r.text: bad.append(('訪客', p, r.status_code))
        for p in member:
            r = m.get(p)
            if r.status_code not in (200, 404, 303) or '系統暫時發生錯誤' in r.text or 'Fatal' in r.text: bad.append(('會員', p, r.status_code))
        for p in admin:
            r = a.get(p)
            if r.status_code not in (200, 404) or '系統暫時發生錯誤' in r.text or 'Fatal' in r.text: bad.append(('管理員', p, r.status_code))
        if 'enable_cart' not in flags and 'enable_orders' not in flags:
            m.post('cart_action.php', {'action': 'add', 'product_id': 7, 'variant_id': 7, 'quantity': 1}, src='product.php?id=7')
            page = m.get('checkout.php').text
            tok = re.search(r'name="checkout_token" value="([a-f0-9]{64})"', page)
            if tok:
                exp = re.search(r'id="expected-total" value="([0-9.]+)"', page).group(1)
                ad = re.search(r'name="address_id" value="(\d+)"', page)
                d = {'checkout_token': tok.group(1), 'expected_total': exp, 'shipping_method': 'home', 'payment_method': 'cod', 'captcha': CAP, 'csrf_token': csrf(page)}
                d.update({'address_id': ad.group(1)} if ad else {'address_id': 'new', 'new[recipient]': '王小明', 'new[phone]': '0912345678', 'new[zip]': '100',
                          'new[city]': '臺北市', 'new[district]': '中正區', 'new[address]': '測試路 1 號'})
                n0 = dbi('SELECT COUNT(*) FROM orders')
                m.s.post(BASE + 'checkout.php', data=d)
                if dbi('SELECT COUNT(*) FROM orders') != n0 + 1: bad.append(('會員', '貨到付款下單', alerts(m.last.text if m.last else '')))
        errs = db("SELECT CONCAT(level,' ',message,' ',file,':',line) FROM error_logs")
        check(name, not bad and errs == '', (bad, errs[:500]))
finally:
    open(CFG, 'w').write(orig)
print('\n%d/%d passed' % (sum(1 for r in results if r[1]), len(results)))
