# Shop with MySQL: Taiwan Goods Store

[繁體中文](README.md) | **English**

A full-featured online store written in PHP + MariaDB / MySQL, designed for the free [InfinityFree](https://www.infinityfree.com/) hosting plan. It does not rely on database `TRIGGER`s, `EVENT`s, cron jobs or background services: anything that must run on a schedule is checked passively when a page is opened.

The user interface, sample data and the detailed specification are in Traditional Chinese.

- **Current version**: 6.0.2 (2026-11-23), includes all features from stages 1–6
- **Specification**: [`docs/需求說明書.md`](docs/需求說明書.md) (Traditional Chinese)
- **Test report**: [`docs/TEST_REPORT.md`](docs/TEST_REPORT.md) (all 241 automated tests pass)

## Features

The store was built in six stages. All tables are created at once, and each stage can be turned on or off with a switch in `config.php`.

| Stage | Features | Switch |
| --- | --- | --- |
| 1 | Member sign-up with email verification, login, password reset, member center; product browsing; admin for members, products, categories, tags, inventory and audit logs | Always on |
| 2 | Shopping cart | `$enable_cart` |
| 3 | Member behavior events, price-at-add batches, six ranking reports, monthly partitioning and archiving | `$enable_behavior` |
| 4 | Address book, shipping rules, checkout, orders, shipping and receipt, returns and exchanges, membership levels | `$enable_orders` |
| 5 | Public business info and shopping guide, cookie consent with Consent Mode v2, custom code (GTM / GA4), mail log, backup and restore, admin IP allowlist, CAPTCHA, admin permission modules, admin dashboard, contact form and support center | `$enable_ops` |
| 6 | Account deletion and anonymization, SEO (meta, canonical, robots.txt, sitemap.xml), structured data and Open Graph, GA4 e-commerce dataLayer, product images, product variants (SKU), simulated online payment and convenience-store pickup, maintenance mode, error log, mail quota, data cleanup | `$enable_launch` |

Dependencies: stages 3 and 4 require the cart; stage 6 requires stages 4 and 5.

**Not included**: real payment or logistics APIs, e-invoices, coupons, friendly URLs, social login, split shipments within one order, photo uploads for after-sales requests.

## Technology and security

- PHP 7.4–8.x with PDO prepared statements; MariaDB 10.5+ (InfinityFree runs 11.4); all tables InnoDB, utf8mb4; time zone UTC+8
- Mail is sent with [PHPMailer](https://github.com/PHPMailer/PHPMailer) 6.10.0 (bundled in `htdocs/vendor/`) through an external SMTP service, such as a Gmail app password
- All writes use POST with a CSRF token; `row_version` prevents stale pages from overwriting newer data
- Admin writes lock, change and log in a single transaction; logs exclude password hashes and credentials
- Money is calculated with DECIMAL and integer cents, never floating point
- Session cookies are HttpOnly and SameSite=Lax, plus Secure over HTTPS
- Response headers: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`

## Directory layout

```
htdocs/                 ← upload to the host's htdocs (or a subfolder)
  app.php               shared bootstrap (loaded by every page)
  config.sample.php     settings template → copy to config.php
  lib/                  shared libraries (.htaccess blocks direct access)
  vendor/phpmailer/     PHPMailer 6.10.0
  assets/               CSS, JavaScript, placeholder image
  uploads/products/     product images (must be writable)
  uploads/site/         logo, OG images (must be writable)
  private_data/         non-public: sessions, fallback error log, uploaded backups (must be writable)
  admin*.php            admin pages
  *.php                 storefront and member center pages
sql/
  00_schema_full.sql    full schema, 50 tables (required)
  01_default_data.sql   default data: level thresholds, policy templates, simulated stores (required)
  10_sample_data.sql    sample data: accounts, products, variants, behavior events (optional)
tests/                  local automated tests (do not upload to the host)
tools/gen_sql.py        regenerates the default and sample data SQL (do not upload to the host)
docs/                   specification, test report
```

## Installation (InfinityFree)

1. **Create a database**: in the control panel, open MySQL Databases and note the Host, DB Name, User and Password.
2. **Import the SQL**: in phpMyAdmin, select the database → Import, in this order:
   1. `sql/00_schema_full.sql`
   2. `sql/01_default_data.sql`
   3. `sql/10_sample_data.sql` (optional; skip it to start with an empty store)
3. **Settings file**: copy `htdocs/config.sample.php`, rename the copy to `htdocs/config.php`, and fill in the database, URL, mail and key as described in [config.php settings](#configphp-settings) below.
4. **Upload the files**: upload everything inside `htdocs/` by FTP, including `.htaccess` and `vendor/`. Do not upload `tests/`, `tools/`, `docs/` or `sql/`.
5. **Folder permissions**: make sure `uploads/products`, `uploads/site` and `private_data` (including `sessions` and `backups`) are writable.
6. **Create the system administrator**
   - With sample data: log in as `admin@example.com` / `Shop@2026`, change the password **immediately**, then suspend or delete the other sample accounts.
   - Without sample data: register on the storefront and verify the email, then run in phpMyAdmin:

     ```sql
     UPDATE members SET is_admin = 1, is_super_admin = 1 WHERE email = 'your@email';
     ```
7. **Required before launch** (system administrator menu):
   - 「聲明與購物須知」(Statements and shopping guide): public operator name and support contact (checkout is disabled until filled in); adapt the three templates to your business and have them reviewed by a lawyer.
   - 「商場設定」(Store settings): fees, return/exchange days, return address.
   - 「商店與 SEO」(Store and SEO): store name, logo, default OG image, support info, handling and transit days.
   - 「付款與配送」(Payment and delivery): enabled delivery and payment methods, convenience-store shipping fee, simulated stores.
   - 「寄信紀錄」(Mail log): click 「寄測試信」(Send test mail) to confirm SMTP works.
   - 「自訂程式碼」(Custom code): paste your GTM or GA4 code and set the load condition to 「Google 標籤」(Google tag).
8. **Admin roles**: in 「管理員與權限」(Admins and permissions), grant view or edit access per module: products, members, orders, shipping, support, reports.

### Sample data (`sql/10_sample_data.sql`)

- 8 accounts, all with password `Shop@2026`: system administrator `admin@example.com`, 3 role-based admins, 3 regular members, 1 unverified member
- 9 products, 15 variants (the cotton T-shirt has color × size variants; the pineapple cake is an unpublished example)
- 4 categories, 4 tags, 2 shipping rules, 363 member behavior events, 1 support message

## config.php settings

`config.php` is the only place that holds credentials. It sits in the same folder as `app.php` (`htdocs/` or your deployment subfolder).

- Edit it by FTP or with the InfinityFree file manager and save it as **UTF-8 without BOM**. A BOM (as added by Windows Notepad) causes "headers already sent" errors.
- Each line has the form `$variable = 'value';`. Change only what is inside the single quotes; keep the quotes and the trailing semicolon.
- Never upload `config.php` to GitHub.

### Required (5 items)

**1. Database connection**: all four values are shown on the **MySQL Databases** page of the InfinityFree control panel.

| Setting | Example | Notes |
| --- | --- | --- |
| `$db_host` | `sql123.infinityfree.com` | MySQL Host Name. Not `localhost`. |
| `$db_port` | `3306` | Leave as is. |
| `$db_name` | `if0_12345678_shop` | MySQL DB Name, including the `if0_number_` prefix. |
| `$db_user` | `if0_12345678` | MySQL User Name. |
| `$db_pass` | (your password) | MySQL Password. On InfinityFree this is the vPanel / hosting account password, not the password you use to log in to the InfinityFree website. |

**2. Store URL**

| Setting | Notes |
| --- | --- |
| `$site_name` | Store name, shown at the top left of every page and on packing slips; also used as the store name when none is set in 「商店與 SEO」(Store and SEO). |
| `$site_url` | Full URL of the store home page, **including the subfolder and without a trailing slash**. Verification and password-reset emails, canonical URLs, the sitemap and the session cookie path all depend on it; a wrong value breaks email links or logs users out right after login. |

Set `$site_url` according to where you uploaded the files:

| Files in | `$site_url` |
| --- | --- |
| `htdocs/` root | `https://yourname.infinityfreeapp.com` |
| `htdocs/shop/` | `https://yourname.infinityfreeapp.com/shop` |
| Custom domain | `https://www.example.com` |

Use `https://` once SSL is enabled on the host. Until then use `http://`, and switch back after SSL is on.

**3. Mail (SMTP)**: InfinityFree does not provide outgoing mail, so an external SMTP service is required. Gmail example:

| Setting | Value |
| --- | --- |
| `$smtp_host` | `smtp.gmail.com` |
| `$smtp_port` / `$smtp_secure` | `587` with `'tls'`, or `465` with `'ssl'` (use them as a pair) |
| `$smtp_username` | Full Gmail address, for example `shop.owner@gmail.com` |
| `$smtp_password` | A Gmail **app password** (16 characters, spaces optional), not your Gmail login password. Turn on 2-Step Verification in your Google Account first; you can then create an app password under Security. |
| `$mail_from` | Sender address. With Gmail, use the same address as `$smtp_username`; otherwise Gmail rewrites or rejects it. |
| `$mail_from_name` | Sender name shown to recipients, for example `Taiwan Goods Store` |

For other providers such as Brevo, set `$smtp_host`, `$smtp_username` and `$smtp_password` to the SMTP host, login and SMTP key from that provider, and use a sender address verified with that provider for `$mail_from`. When you are done, open 「寄信紀錄」(Mail log) in the admin and click 「寄測試信」(Send test mail) to confirm delivery.

**4. Simulated payment signing key `$payment_hmac_key`**: the simulated payment flow signs its requests with this key so payment results cannot be tampered with. Set it to a random string of 32 or more letters and digits, for example by running `php -r "echo bin2hex(random_bytes(32));"` on your own computer, or with [random.org](https://www.random.org/strings/). Do not keep the template value `change-me-to-a-long-random-string`, and do not reuse the key on other sites.

**5. Debug mode `$app_debug`**: must be `false` in production. `true` shows error details (including file paths and SQL) on screen and is for local testing only.

### Optional

| Setting | Default | When to change |
| --- | --- | --- |
| `$enable_cart`, `$enable_behavior`, `$enable_orders`, `$enable_ops`, `$enable_launch` | all `true` | Set to `false` to launch with only some features. See the dependencies under [Features](#features): turning off a prerequisite stage also turns off the stages that depend on it. |
| `$private_dir` | `htdocs/private_data` | Holds the fallback error log and uploaded backups. If your host lets you create a writable folder outside `htdocs`, `dirname(__DIR__) . '/private'` is safer; if unsure, keep the default (external access is blocked by `.htaccess`). |
| `$session_save_path` | `htdocs/private_data/sessions` | Usually leave as is. An empty string uses the host's default location, but then data cleanup cannot delete expired sessions. |
| `$trusted_proxies`, `$trusted_proxy_header` | empty | Set these only when the site sits behind a reverse proxy (such as Cloudflare) and `REMOTE_ADDR` is not the visitor's real IP, for example `$trusted_proxy_header = 'CF-Connecting-IP';` with the proxy IPs listed in `$trusted_proxies`. A wrong setting lets visitors spoof their IP; if unsure, leave them empty. |

### Emergency switches

If a bad setting locks you out of the admin, edit `config.php` directly on the host, and set the switch back to `false` once the problem is fixed:

- `$disable_custom_code = true;`: disables all custom code (for example, a broken GTM / GA4 snippet). You can also add `?no_custom_code=1` to an admin URL.
- `$disable_admin_ip_allowlist = true;`: pauses the admin IP allowlist (for example, when your IP changed and you are locked out).

### Local testing only

These settings are not in the template. Add them only when running `tests/` locally; **never use them in production**:

- `$smtp_host = 'log';`: does not send mail, writes it to `/tmp/shop_mail.log` instead.
- `$captcha_test_code = 'TEST9';`: fixed CAPTCHA code, only active when `$app_debug = true`.

### Filled-in example

```php
<?php
$db_host = 'sql123.infinityfree.com';
$db_port = 3306;
$db_name = 'if0_12345678_shop';
$db_user = 'if0_12345678';
$db_pass = 'your-vPanel-password';

$site_name = '台灣好物商場';
$site_url  = 'https://yourname.infinityfreeapp.com/shop';

$smtp_host      = 'smtp.gmail.com';
$smtp_port      = 587;
$smtp_secure    = 'tls';
$smtp_username  = 'shop.owner@gmail.com';
$smtp_password  = 'abcdefghijklmnop';      // Gmail app password
$mail_from      = 'shop.owner@gmail.com';
$mail_from_name = '台灣好物商場';

$enable_cart     = true;
$enable_behavior = true;
$enable_orders   = true;
$enable_ops      = true;
$enable_launch   = true;

$payment_hmac_key = 'replace-with-your-own-64-character-random-string';

$private_dir       = __DIR__ . '/private_data';
$session_save_path = __DIR__ . '/private_data/sessions';

$trusted_proxies      = [];
$trusted_proxy_header = '';

$disable_custom_code        = false;
$disable_admin_ip_allowlist = false;

$app_debug = false;
```

All accounts, passwords and URLs above are placeholders; replace them with your own.

### Common problems

| Symptom | Likely cause |
| --- | --- |
| The page says 「尚未建立 config.php」(config.php has not been created) | Wrong file name (still `config.sample.php`, or saved as `config.php.txt`), or the file is not in the same folder as `app.php`. |
| Cannot connect to the database | `$db_host` set to `localhost`; `$db_name` missing the `if0_number_` prefix; `$db_pass` set to the InfinityFree website login password. |
| Verification links do not open, or users are logged out right after login | `$site_url` is missing the subfolder, has a trailing slash, or uses `http` / `https` differently from the real address. |
| Mail is not sent | Gmail login password used instead of an app password; `$smtp_port` and `$smtp_secure` do not match; `$mail_from` differs from `$smtp_username`. |
| "headers already sent" appears on pages | `config.php` was saved as UTF-8 with BOM, or there is a blank line before `<?php`. |

## Pre-launch host checks

These cannot be tested locally and must be verified on InfinityFree:

- [ ] SMTP actually delivers (verification, password reset, order and support notifications)
- [ ] Partition DDL permissions: 「檢查並補建月份」(check and add months) and `DROP PARTITION` during archiving
- [ ] PHP GD extension is available (CAPTCHA, images); whether WebP is supported (falls back to JPG or PNG if not)
- [ ] `REMOTE_ADDR` is the visitor's real IP (IP allowlist, message source IP, mail quota)
- [ ] `upload_max_filesize` and `post_max_size` ≥ 5 MB; execution time limit (backup restore runs in batches of about 8 seconds)
- [ ] `RENAME TABLE` and foreign-key rebuild permissions (applying a restore)
- [ ] `mod_rewrite` works: open `/robots.txt` and `/sitemap.xml`. If it does not, submit `sitemap.php` to Search Console instead.
- [ ] `.htaccess` is in effect: opening `private_data/index.html` directly should return 403
- [ ] Whether the host's browser security check interferes with Search Console, the Rich Results Test and social link previews

## Local testing

Requires MariaDB, PHP 8.x (with GD, pdo_mysql, zlib) and Python 3 (requests, beautifulsoup4, Pillow).

```bash
cd htdocs && php -S 127.0.0.1:8080 -t .     # in a separate terminal
cd tests && ./run_all.sh                     # rebuilds the local database and runs the four test suites
```

`run_all.sh` drops and recreates the local `shop` database (user `shop` / `shoppass`); run it only in a test environment. The test `config.php` needs `$app_debug = true;`, `$smtp_host = 'log';` (mail goes to `/tmp/shop_mail.log`) and `$captcha_test_code = 'TEST9';`.

## Security notes

- **Never commit `htdocs/config.php` to this repository**: it contains database and SMTP credentials. `.gitignore` already excludes it.
- In production keep `$app_debug = false` and do not set `$captcha_test_code`.
- The sample accounts use the public password `Shop@2026`; delete them or change their passwords before launch.
- Backup files contain personal data and password hashes; store them safely. Product images are not included in backups; back up `uploads/` separately by FTP.
- The simulated payment never collects card numbers. To connect a real payment provider, implement the `PaymentGateway` interface in `lib/payment.php`.

## Changelog

- **6.0.2** (2026-11-23): rewrote the 「付款與配送」(Payment and delivery) admin page (delivery methods, payment methods and simulated stores in three sections; stores editable in place); fixed checkboxes wrapping onto a separate line from their labels.
- **6.0.1** (2026-11-22): phone field styling, wider street address field, save button styling; added a description and confirmation dialog to 「立即執行清理」(Run cleanup now).
- **6.0.0** (2026-11-21): complete release of stages 1–6.

## License

Copyright (C) 2026 Jesse Yen

This project is licensed under the [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0). You are free to use, modify and distribute it; however, if you distribute a modified version, or use a modified version to provide a service over a network (for example, running a store website), you must make the complete corresponding source code available to its users under the same AGPL-3.0 license.

This project comes with no warranty; see [LICENSE](LICENSE) for details.

Third-party component: `htdocs/vendor/phpmailer/` is [PHPMailer](https://github.com/PHPMailer/PHPMailer), distributed under its [LGPL 2.1 license](htdocs/vendor/phpmailer/LICENSE).
