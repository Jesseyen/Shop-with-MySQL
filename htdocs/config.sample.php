<?php
/**
 * 設定範本：複製為 config.php 後填入實際值（config.php 不要公開或提交到版本控制）
 * 資料庫連線、寄信帳密與商場基本網址只在這裡設定一次。
 */

// ---- 資料庫（InfinityFree 控制台 → MySQL Databases 可查到）----
$db_host = 'sqlXXX.infinityfree.com';   // MySQL Host Name
$db_port = 3306;
$db_name = 'if0_XXXXXXXX_shop';         // MySQL DB Name
$db_user = 'if0_XXXXXXXX';              // MySQL User Name
$db_pass = 'your-vPanel-password';      // MySQL Password（即 vPanel／帳戶密碼）

// ---- 網站 ----
$site_name = '台灣好物商場';
// 含部署子目錄的完整網址，結尾不要加斜線，例如 https://yourname.infinityfreeapp.com/shop
$site_url  = 'https://yourname.infinityfreeapp.com';

// ---- SMTP（InfinityFree 不提供 SMTP，請使用外部服務，例如 Gmail 應用程式密碼、Brevo 等）----
$smtp_host     = 'smtp.gmail.com';
$smtp_port     = 587;                   // 587 = STARTTLS；465 = SMTPS
$smtp_secure   = 'tls';                 // 'tls'（STARTTLS）或 'ssl'（SMTPS）
$smtp_username = 'your-account@gmail.com';
$smtp_password = 'your-16-char-app-password';
$mail_from      = 'your-account@gmail.com';
$mail_from_name = '台灣好物商場';

// ---- 功能開關（資料表一次全部建立；關閉的階段只是不顯示功能）----
$enable_cart     = true;   // 第二階段：購物車
$enable_behavior = true;   // 第三階段：會員行為分析（需要購物車）
$enable_orders   = true;   // 第四階段：結帳、訂單、退換貨、會員等級（需要購物車）
$enable_ops      = true;   // 第五階段：公開資訊、Cookie 同意、自訂程式碼、寄信紀錄、備份、IP 白名單、辨識碼、權限、訊息中心
$enable_launch   = true;   // 第六階段：刪除帳號、SEO、結構化資料、GA4、圖片、規格、模擬付款與超商、維護、錯誤紀錄、額度、清理（需要第四、五階段）

// ---- 模擬付款簽章金鑰：請改成 32 字以上的隨機字串（例如到 https://www.random.org/strings/ 產生）----
$payment_hmac_key = 'change-me-to-a-long-random-string';

// ---- 非公開目錄（錯誤備援紀錄、備份上傳檔）；若主機允許，建議放在 htdocs 之外，例如 dirname(__DIR__) . '/private'
$private_dir = __DIR__ . '/private_data';
// Session 檔案目錄（建議設定為非公開目錄，資料清理才能刪除過期 Session；留空使用主機預設）
$session_save_path = __DIR__ . '/private_data/sessions';

// ---- 主機在反向代理後方時才設定：信任的代理 IP 與標頭（未設定時只採用 REMOTE_ADDR）----
$trusted_proxies = [];            // 例如 ['10.0.0.1']
$trusted_proxy_header = '';       // 例如 'X-Forwarded-For' 或 'CF-Connecting-IP'

// ---- 緊急開關：設定錯誤導致無法進入後台時，在主機上把對應項目改成 true ----
$disable_custom_code = false;        // 跳過所有自訂程式碼
$disable_admin_ip_allowlist = false; // 暫停管理員 IP 白名單

// ---- 除錯：正式環境務必為 false（true 時會在畫面顯示例外細節）----
$app_debug = false;
