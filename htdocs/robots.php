<?php
/** robots.txt（第 26 節）：由 .htaccess 將 /robots.txt 導向此檔動態產生；維護模式時仍可讀取 */
require_once __DIR__ . '/app.php';
header('Content-Type: text/plain; charset=utf-8');
$base = (string)(parse_url($site_url, PHP_URL_PATH) ?: '');
$lines = ['User-agent: *'];
foreach (['admin', 'cart.php', 'cart_action.php', 'checkout.php', 'pay_sim.php', 'order_done.php', 'member', 'login.php', 'logout.php',
          'register.php', 'register_process.php', 'forgot_password.php', 'reset_password.php', 'verify.php', 'resend_verify.php', 'captcha.php',
          'uploads/site/', 'private_data/', 'vendor/', 'lib/'] as $p) {
    $lines[] = 'Disallow: ' . $base . '/' . $p;
}
$lines[] = 'Disallow: ' . $base . '/products.php?q=';
if ($enable_launch && trim(setting('robots_extra')) !== '') {
    foreach (preg_split('/\R/', trim(setting('robots_extra'))) as $l) {
        if (preg_match('/^(User-agent|Disallow|Allow|Crawl-delay):\s*[^\r\n]*$/i', trim($l))) { $lines[] = trim($l); }
    }
}
$lines[] = '';
$lines[] = 'Sitemap: ' . abs_url('sitemap.xml');
echo implode("\n", $lines) . "\n";
