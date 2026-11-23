<?php
/**
 * 商場設定（site_settings）、公開文件（site_contents）與單據編號
 * 設定未存入資料庫時使用 settings_defaults() 的預設值。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function settings_defaults(): array
{
    return [
        // 第四階段：運費、手續費、期限、寄回地址
        'shipping_mode' => 'by_rule',          // all_free 或 by_rule
        'cod_fee' => '0',
        'return_days' => '7',
        'auto_complete_days' => '3',
        'return_address' => '',
        // 第五階段：Cookie 橫幅與 Consent Mode
        'consent_banner_enabled' => '1',
        'consent_title' => 'Cookie 與隱私設定',
        'consent_text' => '我們使用必要 Cookie 維持登入與購物車運作。經您同意後，才會使用分析與廣告 Cookie 改善網站與行銷成效。您可以隨時從頁面底部的「Cookie 設定」變更選擇。',
        'consent_privacy_url' => 'privacy.php',
        'consent_mode' => 'advanced',         // advanced 或 basic
        'consent_url_passthrough' => '0',
        'consent_ads_data_redaction' => '1',
        'consent_version' => '1',
        // 第六階段：商店與 SEO
        'store_name' => '',
        'legal_name' => '',
        'tax_id' => '',
        'logo_file' => '',
        'default_og_file' => '',
        'cs_email' => '',
        'cs_phone' => '',
        'store_address' => '',
        'same_as' => '',
        'twitter_handle' => '',
        'default_brand' => '',
        'home_title' => '',
        'home_description' => '',
        'handling_min_days' => '0',
        'handling_max_days' => '1',
        'transit_home_min_days' => '1',
        'transit_home_max_days' => '2',
        'transit_cvs_min_days' => '2',
        'transit_cvs_max_days' => '3',
        'return_shipping_payer' => 'customer', // store 或 customer
        'robots_extra' => '',
        // 第六階段：配送與付款
        'ship_home_enabled' => '1',
        'ship_cvs_enabled' => '1',
        'pay_cod_enabled' => '1',
        'pay_cvs_cod_enabled' => '1',
        'pay_card_enabled' => '1',
        'pay_paypal_enabled' => '1',
        'cvs_fee' => '60',
        'cvs_free_threshold' => '',
        'payment_timeout_minutes' => '30',
        'cvs_pickup_days' => '7',
        // 第六階段：維護模式
        'maintenance_enabled' => '0',
        'maintenance_message' => '網站維護中，造成不便敬請見諒，請稍後再回來。',
        'maintenance_until' => '',
        // 第六階段：寄信額度
        'quota_ip_hour' => '5',
        'quota_ip_day' => '20',
        'quota_email_hour' => '3',
        'quota_site_day' => '300',
        // 第六階段：資料清理保留天數
        'retain_cart_days' => '90',
        'retain_security_days' => '180',
        'retain_error_days' => '30',
        'retain_mail_days' => '180',
        'retain_payment_days' => '90',
        'retain_drill_days' => '30',
    ];
}

function settings_table_ready(): bool
{
    global $enable_orders, $enable_ops;
    return $enable_orders || $enable_ops;
}

function settings_all(bool $refresh = false): array
{
    global $pdo;
    static $cache = null;
    if ($cache !== null && !$refresh) { return $cache; }
    $cache = settings_defaults();
    if (settings_table_ready()) {
        try {
            foreach ($pdo->query('SELECT setting_key, setting_value FROM site_settings') as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (PDOException $e) {
            error_log('[shop] 讀取 site_settings 失敗：' . $e->getMessage());
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return array_key_exists($key, $all) ? (string)$all[$key] : $default;
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, (string)$default);
    return preg_match('/^-?[0-9]+$/', $v) ? (int)$v : $default;
}

function setting_on(string $key): bool
{
    return setting($key) === '1';
}

/** 交易內寫入多筆設定並記錄異動（只記錄有變更的鍵） */
function settings_save(PDO $pdo, array $kv, ?int $actorId, string $auditAction = 'update'): int
{
    $before = settings_all(true);
    $changedBefore = [];
    $changedAfter = [];
    $st = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by),
                                                 row_version = row_version + 1');
    foreach ($kv as $k => $v) {
        $v = (string)$v;
        if (($before[$k] ?? null) === $v) { continue; }
        $st->execute([$k, $v, $actorId]);
        $changedBefore[$k] = $before[$k] ?? null;
        $changedAfter[$k] = $v;
    }
    if ($changedAfter) {
        audit_log($pdo, $actorId, 'site_settings', 'settings', $auditAction, $changedBefore, $changedAfter);
    }
    settings_all(true);
    return count($changedAfter);
}

// ---------------------------------------------------------------------
// 公開文件（第五階段）
// ---------------------------------------------------------------------
function content_keys(): array
{
    return [
        'operator_name' => ['公開營運者名稱', 100],
        'contact_info' => ['公開客服／個資權利聯絡方式', 1000],
        'privacy' => ['個資保護聲明', 20000],
        'returns' => ['退貨條款', 20000],
        'shopping_guide' => ['使用者購物須知', 20000],
    ];
}

function site_content(string $key): array
{
    global $pdo, $enable_ops;
    static $cache = null;
    if (!$enable_ops) { return ['content' => '', 'updated_at' => null, 'row_version' => 1]; }
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query('SELECT content_key, content, updated_at, row_version FROM site_contents') as $r) {
                $cache[$r['content_key']] = $r;
            }
        } catch (PDOException $e) {
            error_log('[shop] 讀取 site_contents 失敗：' . $e->getMessage());
        }
    }
    return $cache[$key] ?? ['content' => '', 'updated_at' => null, 'row_version' => 1];
}

function operator_name(): string
{
    global $site_name;
    $n = trim(site_content('operator_name')['content']);
    return $n !== '' ? $n : $site_name;
}

/** 公開資訊是否完整（決策 27：未填時結帳停用） */
function public_info_ready(): bool
{
    global $enable_ops;
    if (!$enable_ops) { return true; }
    return trim(site_content('operator_name')['content']) !== '' && trim(site_content('contact_info')['content']) !== '';
}

/** 代入佔位字 */
function content_fill(string $text): string
{
    return strtr($text, [
        '{營運者名稱}' => operator_name(),
        '{退換貨天數}' => (string)setting_int('return_days', 7),
        '{自動完成天數}' => (string)setting_int('auto_complete_days', 3),
        '{貨到付款手續費}' => money(setting('cod_fee', '0')),
    ]);
}

/** 純文字 → HTML：先編碼，空行分段、換行保留、網址自動連結 */
function plain_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') { return ''; }
    $out = '';
    foreach (preg_split('/\n{2,}/', $text) as $para) {
        $html = h($para);
        $html = preg_replace('~(https?://[^\s<>"\']+)~u', '<a href="$1" rel="noopener nofollow">$1</a>', $html);
        $out .= '<p>' . nl2br($html, false) . '</p>';
    }
    return $out;
}

// ---------------------------------------------------------------------
// 單據編號：類型字母 + 日期 + 6 位流水號，例如 S20261121-000123（須在交易內呼叫）
// ---------------------------------------------------------------------
function next_doc_no(PDO $pdo, string $type): string
{
    $date = date('Y-m-d');
    $pdo->prepare('INSERT INTO doc_sequences (doc_type, doc_date, last_no) VALUES (?, ?, LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)')->execute([$type, $date]);
    $n = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    return $type . date('Ymd') . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}
