# Shop with MySQL：台灣好物商場

用 PHP + MariaDB / MySQL 寫成的完整購物商場，針對 [InfinityFree](https://www.infinityfree.com/) 免費主機設計：不使用資料庫 `TRIGGER`、`EVENT`、排程或常駐服務，需要定時的工作都改成「開啟頁面時被動檢查」。

- **目前版本**：6.0.2（2026-11-23），包含第一～六階段全部功能
- **規格文件**：[`docs/需求說明書.md`](docs/需求說明書.md)
- **測試報告**：[`docs/TEST_REPORT.md`](docs/TEST_REPORT.md)（241 項自動測試全部通過）

## 功能

商場分六個階段建置。資料表一次全部建立，每個階段可在 `config.php` 用開關啟用或隱藏。

| 階段 | 功能 | 開關 |
| --- | --- | --- |
| 1 | 會員註冊與 Email 驗證、登入、忘記密碼、會員中心；商品瀏覽；後台會員、商品、分類、標籤、庫存、異動紀錄 | 必備 |
| 2 | 購物車 | `$enable_cart` |
| 3 | 會員行為事件、加入時價格批次、六項排行報表、每月分區與封存 | `$enable_behavior` |
| 4 | 地址簿、運費規則、結帳、訂單、出貨與收件、退換貨、會員等級 | `$enable_orders` |
| 5 | 公開資訊與購物須知、Cookie 同意與 Consent Mode v2、自訂程式碼（GTM / GA4）、寄信紀錄、備份與還原、管理員 IP 白名單、辨識碼、管理員權限模組、管理總覽、聯繫表單與客服中心 | `$enable_ops` |
| 6 | 會員刪除帳號與匿名化、SEO（meta、canonical、robots.txt、sitemap.xml）、結構化資料與 Open Graph、GA4 電子商務 dataLayer、商品圖片、商品規格（SKU）、模擬線上支付與超商取貨、維護模式、錯誤紀錄、寄信額度、資料清理 | `$enable_launch` |

開關依賴：第 3、4 階段需要購物車；第 6 階段需要第 4、5 階段。

**不包含**：真實金流與物流 API、電子發票、優惠券、友善網址、社群登入、同一訂單分批出貨、售後照片上傳。

## 技術與安全設計

- PHP 7.4～8.x、PDO 參數化查詢；MariaDB 10.5 以上（InfinityFree 為 11.4），全部資料表 InnoDB、utf8mb4，時區 UTC+8
- 寄信用 [PHPMailer](https://github.com/PHPMailer/PHPMailer) 6.10.0（已附在 `htdocs/vendor/`）透過外部 SMTP，例如 Gmail 應用程式密碼
- 寫入一律 POST 並檢查 CSRF 權杖；用 `row_version` 防止舊頁覆寫
- 後台寫入在同一筆交易內完成鎖定、異動與異動紀錄；紀錄會排除密碼雜湊與憑證
- 金額用 DECIMAL 與整數「分」計算，不使用浮點數
- Session Cookie 設 HttpOnly、SameSite=Lax，HTTPS 時加 Secure
- 回應標頭：`X-Frame-Options: DENY`、`X-Content-Type-Options: nosniff`、`Referrer-Policy: same-origin`

## 目錄結構

```
htdocs/                 ← 上傳到主機的 htdocs（或其子目錄）
  app.php               共用啟動檔（所有頁面都載入）
  config.sample.php     設定範本 → 複製為 config.php
  lib/                  共用函式庫（.htaccess 禁止直接存取）
  vendor/phpmailer/     PHPMailer 6.10.0
  assets/               CSS、JavaScript、示意圖
  uploads/products/     商品圖片（需可寫入）
  uploads/site/         Logo、OG 圖（需可寫入）
  private_data/         非公開目錄：Session、錯誤備援紀錄、備份上傳檔（需可寫入）
  admin*.php            後台頁面
  *.php                 前台與會員中心頁面
sql/
  00_schema_full.sql    完整資料表結構，50 張資料表（必須）
  01_default_data.sql   預設資料：等級門檻、公開文件範本、模擬門市（必須）
  10_sample_data.sql    範例資料：帳號、商品、規格、行為事件（選用）
tests/                  本機自動測試（不需上傳到主機）
tools/gen_sql.py        重新產生預設與範例資料 SQL（不需上傳到主機）
docs/                   需求說明書、測試報告
```

## 安裝（InfinityFree）

1. **建立資料庫**：控制台 → MySQL Databases，記下 Host、DB Name、User、Password。
2. **匯入 SQL**：在 phpMyAdmin 選好資料庫 → 匯入，依序匯入：
   1. `sql/00_schema_full.sql`
   2. `sql/01_default_data.sql`
   3. `sql/10_sample_data.sql`（選用；想從空的商場開始就不要匯入）
3. **設定檔**：把 `htdocs/config.sample.php` 複製為 `htdocs/config.php`，填入：
   - 資料庫連線，以及 `$site_url`（含子目錄，結尾不加斜線）
   - SMTP 帳密（InfinityFree 不提供 SMTP）
   - `$payment_hmac_key`：改成 32 字以上的隨機字串
   - `$app_debug = false;`
4. **上傳檔案**：用 FTP 上傳 `htdocs/` 內所有檔案，包含 `.htaccess` 與 `vendor/`。`tests/`、`tools/`、`docs/`、`sql/` 不用上傳。
5. **目錄權限**：確認 `uploads/products`、`uploads/site`、`private_data`（含 `sessions`、`backups`）可寫入。
6. **建立系統管理者**
   - 有匯入範例資料：用 `admin@example.com`／`Shop@2026` 登入後，**立即**改密碼，並把其他範例帳號停權或刪除。
   - 沒匯入範例資料：先在前台註冊並完成 Email 驗證，再到 phpMyAdmin 執行：

     ```sql
     UPDATE members SET is_admin = 1, is_super_admin = 1 WHERE email = '你的Email';
     ```
7. **上線前必填**（系統管理者選單）：
   - 「聲明與購物須知」：公開營運者名稱與客服聯絡方式（未填時結帳會停用）；依實際營運修改三份範本，並請法律人員確認。
   - 「商場設定」：手續費、退換貨天數、寄回地址。
   - 「商店與 SEO」：商店名稱、Logo、預設 OG 圖、客服資訊、處理與運送天數。
   - 「付款與配送」：要開放的配送與付款方式、超商運費、模擬門市。
   - 「寄信紀錄」：按「寄測試信」確認 SMTP。
   - 「自訂程式碼」：貼上 GTM 或 GA4 程式碼，載入條件選「Google 標籤」。
8. **管理員分工**：在「管理員與權限」指定商品、會員、訂單、出貨、客服、報表等模組的檢視或編輯權限。

### 範例資料內容（`sql/10_sample_data.sql`）

- 8 個帳號，密碼都是 `Shop@2026`：系統管理者 `admin@example.com`、3 位分工管理員、3 位一般會員、1 位待驗證會員
- 9 個商品、15 個規格（「台灣設計棉 T」為顏色 × 尺寸多規格商品，「鳳梨酥」為下架示範）
- 4 個分類、4 個標籤、2 條運費規則、363 筆會員行為事件、1 則客服訊息

## 緊急開關

設定錯誤導致無法進入後台時，直接在主機修改 `config.php`：

- `$disable_custom_code = true;`：停用全部自訂程式碼。也可以在後台網址加 `?no_custom_code=1`。
- `$disable_admin_ip_allowlist = true;`：暫停 IP 白名單。

## 上線前主機確認事項

這些項目本機測不到，需要在 InfinityFree 實測：

- [ ] SMTP 實際寄達（驗證信、重設信、訂單與客服通知）
- [ ] 分區 DDL 權限：「檢查並補建月份」與封存時的 `DROP PARTITION`
- [ ] PHP GD 擴充可用（辨識碼、圖片）；是否支援 WebP（不支援時自動改存 JPG 或 PNG）
- [ ] `REMOTE_ADDR` 是否為訪客真實 IP（IP 白名單、訊息來源 IP、寄信額度）
- [ ] `upload_max_filesize`、`post_max_size` ≥ 5 MB；執行時間上限（備份還原每批約 8 秒）
- [ ] `RENAME TABLE` 與外鍵重建權限（套用還原）
- [ ] `mod_rewrite` 可用：開啟 `/robots.txt`、`/sitemap.xml`。不可用時，Search Console 改提交 `sitemap.php`。
- [ ] `.htaccess` 生效：直接開啟 `private_data/index.html` 應回應 403
- [ ] 主機的瀏覽器安全檢查是否影響 Search Console、Rich Results Test 與社群分享預覽

## 本機測試

需要 MariaDB、PHP 8.x（含 GD、pdo_mysql、zlib）、Python 3（requests、beautifulsoup4、Pillow）。

```bash
cd htdocs && php -S 127.0.0.1:8080 -t .     # 另開一個終端機
cd tests && ./run_all.sh                     # 重建本機資料庫後依序執行四組測試
```

`run_all.sh` 會刪除並重建本機的 `shop` 資料庫（使用者 `shop`／`shoppass`），請只在測試環境執行。測試用 `config.php` 需設定 `$app_debug = true;`、`$smtp_host = 'log';`（信件寫到 `/tmp/shop_mail.log`）與 `$captcha_test_code = 'TEST9';`。

## 安全提醒

- **不要把 `htdocs/config.php` 提交到這個儲存庫**，它含資料庫與 SMTP 帳密；`.gitignore` 已排除。
- 正式環境 `$app_debug = false`，也不要設定 `$captcha_test_code`。
- 範例帳號使用公開密碼 `Shop@2026`，上線前請刪除或改密碼。
- 備份檔含個資與密碼雜湊，請妥善保管；商品圖片不在備份內，請另以 FTP 備份 `uploads/`。
- 模擬付款不收集任何卡號；改接真實金流時，請實作 `lib/payment.php` 的 `PaymentGateway` 介面。

## 版本紀錄

- **6.0.2**（2026-11-23）：重寫「付款與配送」後台頁（配送方式、付款方式、模擬門市三區塊，門市可直接修改）；修正勾選框與文字被拆成兩行。
- **6.0.1**（2026-11-22）：電話欄位樣式、詳細地址欄位加寬、儲存按鈕樣式；「立即執行清理」加上說明與確認視窗。
- **6.0.0**（2026-11-21）：第一～六階段完整版。

## 授權

商場程式尚未指定授權。`htdocs/vendor/phpmailer/` 為 PHPMailer，依其 [LGPL 2.1 授權](htdocs/vendor/phpmailer/LICENSE) 散布。
