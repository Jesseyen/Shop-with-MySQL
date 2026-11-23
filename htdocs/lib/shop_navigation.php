<?php
/**
 * 選單：管理選單只輸出有權限的項目（第五階段起依模組權限；IP 不符時不輸出）。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

/** 後台選單定義：[模組, 檔名, 名稱]；模組 'super' 為系統管理者、'*' 為任何後台人員 */
function admin_menu_items(): array
{
    global $enable_behavior, $enable_orders, $enable_ops, $enable_launch;
    $items = [];
    if ($enable_ops) { $items[] = ['*', 'admin.php', '管理總覽']; }
    $items[] = ['product', 'admin_products.php', '商品'];
    $items[] = ['product', 'admin_categories.php', '分類'];
    $items[] = ['product', 'admin_tags.php', '標籤'];
    $items[] = ['product', 'admin_inventory.php', '庫存'];
    if ($enable_orders) { $items[] = ['product', 'admin_shipping_rules.php', '運費規則']; }
    $items[] = ['member', 'admin_members.php', '會員'];
    if ($enable_orders) {
        $items[] = ['member', 'admin_levels.php', '會員等級'];
        $items[] = ['order', 'admin_orders.php', '訂單'];
        $items[] = ['order', 'admin_after_sales.php', '售後單'];
        $items[] = ['shipping', 'admin_shipments.php', '出貨單'];
        $items[] = ['shipping', 'admin_receipts.php', '退貨收件單'];
    }
    if ($enable_ops) { $items[] = ['support', 'admin_messages.php', '訊息中心']; }
    if ($enable_behavior) { $items[] = ['report', 'admin_behavior_reports.php', '行為報表']; }
    if ($enable_ops) {
        $items[] = ['super', 'admin_admins.php', '管理員與權限'];
        $items[] = ['super', 'admin_contents.php', '聲明與購物須知'];
        $items[] = ['super', 'admin_consent.php', 'Cookie 橫幅與重設'];
        $items[] = ['super', 'admin_custom_code.php', '自訂程式碼'];
        $items[] = ['super', 'admin_mail_logs.php', '寄信紀錄'];
        $items[] = ['super', 'admin_backup.php', '備份與還原'];
        $items[] = ['super', 'admin_ip_allowlist.php', 'IP 白名單'];
    }
    if ($enable_orders) { $items[] = ['super', 'admin_settings.php', '商場設定']; }
    if ($enable_launch) {
        $items[] = ['super', 'admin_store.php', '商店與 SEO'];
        $items[] = ['super', 'admin_delivery.php', '付款與配送'];
        $items[] = ['super', 'admin_system.php', '維護與清理'];
        $items[] = ['super', 'admin_error_logs.php', '錯誤紀錄'];
    }
    $items[] = ['super', 'admin_audit_logs.php', '異動紀錄'];
    if ($enable_behavior) { $items[] = ['super', 'admin_behavior_maintenance.php', '行為資料維護']; }
    return $items;
}

function render_navigation(?array $member, bool $minimal = false): void
{
    global $site_name, $enable_cart, $enable_orders, $enable_ops;
    $self = script_name();
    $item = function (string $href, string $label) use ($self) {
        $cur = $self === $href ? ' aria-current="page"' : '';
        return '<li><a href="' . h($href) . '"' . $cur . '>' . h($label) . '</a></li>';
    };

    echo '<header class="site-header"><div class="container nav-wrap">';
    echo '<a class="brand" href="index.php">' . h($site_name) . '</a>';
    if ($minimal) { echo '</div></header>'; return; }
    echo '<nav aria-label="主選單"><ul class="nav">';
    echo $item('index.php', '首頁');
    echo $item('products.php', '商品瀏覽');
    if ($member === null) {
        echo $item('login.php', '登入');
        echo $item('register.php', '註冊');
    } else {
        if ($enable_cart) { echo $item('cart.php', '購物車'); }
        if ($enable_orders) { echo $item('member_orders.php', '我的訂單'); }
        echo $item('member.php', '會員中心');
        if ($enable_ops) { echo $item('member_support.php', '客服中心'); }
        echo '<li><form method="post" action="logout.php" class="inline">' . csrf_field()
            . '<button type="submit" class="link-btn">登出</button></form></li>';
    }
    echo '</ul></nav>';
    $staff = $member !== null && is_staff();
    if ($member !== null) {
        echo '<span class="whoami">' . h($member['name']) . ($staff ? '（管理者）' : '') . '</span>';
    }
    echo '</div>';

    if ($staff) {
        $links = '';
        foreach (admin_menu_items() as [$mod, $file, $label]) {
            $ok = $mod === '*' ? true : admin_can($mod, 'view');
            if ($ok) { $links .= $item($file, $label); }
        }
        if ($links !== '') {
            echo '<div class="admin-bar"><div class="container"><ul class="nav nav-admin" aria-label="管理選單">'
                . '<li class="label">後台</li>' . $links . '</ul></div></div>';
        }
    }
    echo '</header>';
}
