<?php
/**
 * 公開文件頁（第五階段，第 15 節）：純文字輸出時編碼、空行分段、網址自動連結、佔位字代入目前設定
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function render_public_page(string $key, string $file): void
{
    global $enable_ops, $enable_launch;
    if (!$enable_ops) { render_not_found(); }
    [$title] = content_keys()[$key];
    $c = site_content($key);
    $opts = ['canonical' => $file, 'page_type' => 'content'];
    if ($enable_launch) {
        $opts['og'] = ['type' => 'website', 'title' => $title . '｜' . seo_store_name(), 'description' => seo_excerpt(content_fill($c['content']))];
        $opts['description'] = seo_excerpt(content_fill($c['content']));
    }
    page_header($title, $opts);
    echo '<article class="card doc"><h1>' . h($title) . '</h1>';
    if ($c['updated_at']) { echo '<p class="muted small">最後更新：' . h(substr($c['updated_at'], 0, 10)) . '</p>'; }
    $body = trim($c['content']);
    echo $body === '' ? '<p class="muted">內容尚未設定。</p>' : plain_to_html(content_fill($body));
    if (in_array($key, ['privacy', 'returns'], true)) {
        $contact = trim(site_content('contact_info')['content']);
        echo '<h2>聯絡方式</h2><p><strong>' . h(operator_name()) . '</strong></p>' . ($contact !== '' ? plain_to_html($contact) : '<p class="muted">尚未設定。</p>');
    }
    echo '</article>';
    page_footer();
}
