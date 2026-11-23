<?php
/**
 * 登出：採 POST 並驗證 CSRF 權杖（避免被第三方連結強制登出），清除登入狀態與 Session。
 * 用 GET 直接開啟時顯示確認按鈕。
 */
require_once __DIR__ . '/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    page_header('登出');
    echo '<section class="card narrow"><h1>確認登出</h1>';
    if (current_member()) {
        echo '<form method="post" action="logout.php">' . csrf_field() . '<button class="btn btn-primary" type="submit">登出</button></form>';
    } else {
        echo '<p>您目前未登入。</p><p><a class="btn" href="index.php">返回商場</a></p>';
    }
    echo '</section>';
    page_footer();
    exit;
}

require_csrf();
$next = post_str('next') === 'login' ? 'login.php' : 'index.php';
logout_member();
redirect($next);
