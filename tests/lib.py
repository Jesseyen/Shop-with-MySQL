"""本機 HTTP 測試共用函式"""
import re, subprocess, os, requests
from bs4 import BeautifulSoup

BASE = 'http://127.0.0.1:8080/'
HTDOCS = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'htdocs')
MAIL_LOG = '/tmp/shop_mail.log'
CAP = 'TEST9'
results = []


def db(sql):
    out = subprocess.run(['mariadb', '-ushop', '-pshoppass', 'shop', '-N', '-B', '-e', sql], capture_output=True, text=True)
    if out.returncode != 0:
        raise RuntimeError(out.stderr + '\n' + sql)
    return out.stdout.strip()


def dbi(sql):
    v = db(sql)
    return int(v) if v not in ('', 'NULL') else 0


def check(name, cond, detail=''):
    results.append((name, bool(cond), detail))
    print(('PASS ' if cond else 'FAIL ') + name + (('  -- ' + str(detail)[:300]) if detail and not cond else ''))
    return bool(cond)


def csrf(html):
    m = re.search(r'name="csrf_token" value="([a-f0-9]+)"', html)
    return m.group(1) if m else None


def alerts(html):
    return ' | '.join(a.get_text(' ', strip=True) for a in BeautifulSoup(html, 'html.parser').select('.alert'))


def text(html):
    return BeautifulSoup(html, 'html.parser').get_text(' ', strip=True)


class Client:
    def __init__(self, headers=None):
        self.s = requests.Session()
        if headers:
            self.s.headers.update(headers)
        self.last = None

    def get(self, path, **kw):
        r = self.s.get(BASE + path, allow_redirects=kw.pop('allow_redirects', True), **kw)
        self.last = r
        return r

    def post(self, path, data, src=None, files=None, **kw):
        page = self.get(src or path).text
        data = dict(data)
        data.setdefault('csrf_token', csrf(page))
        r = self.s.post(BASE + path, data=data, files=files, allow_redirects=kw.pop('allow_redirects', True), **kw)
        self.last = r
        return r

    def login(self, email, pw='Shop@2026', return_to=None):
        src = 'login.php' + ('?return_to=' + return_to if return_to else '')
        d = {'email': email, 'password': pw, 'captcha': CAP}
        if return_to:
            d['return_to'] = return_to
        return self.post('login.php', d, src=src)


def mail_links(kind):
    txt = open(MAIL_LOG).read() if os.path.exists(MAIL_LOG) else ''
    return re.findall(r'(http://127\.0\.0\.1:8080/' + kind + r'\.php\?[^\s]+)', txt)


def php(code):
    """在 CLI 載入 app.php 後執行 PHP 程式碼，回傳輸出"""
    full = "chdir('" + HTDOCS + "'); $_SERVER['REMOTE_ADDR']='127.0.0.1'; require 'app.php'; " + code
    out = subprocess.run(['php', '-r', full], capture_output=True, text=True)
    return (out.stdout + out.stderr).strip()
