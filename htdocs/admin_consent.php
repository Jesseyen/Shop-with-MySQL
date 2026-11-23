<?php
/**
 * Cookie 橫幅與重設（系統管理者，第 16 節）
 * 自動顯示、標題與說明、個資聲明連結、Consent Mode 模式（進階／基本）、url_passthrough、ads_data_redaction；
 * 還原預設：恢復自動顯示並讓同意版本加一，使訪客原選擇失效（不清除登入或購物車）
 */
require_once __DIR__ . '/app.php';
if (!$enable_ops) { render_not_found(); }
$me = require_perm('super', is_post() ? 'edit' : 'view');

if (is_post()) {
    require_csrf();
    try {
        if (post_str('action') === 'reset') {
            if (post_str('confirm') !== '1') { throw new UserError('請先勾選「我確認要還原預設」。'); }
            admin_transaction(function (PDO $pdo, array $a) {
                $d = settings_defaults();
                settings_save($pdo, ['consent_banner_enabled' => '1', 'consent_title' => $d['consent_title'], 'consent_text' => $d['consent_text'],
                    'consent_privacy_url' => 'privacy.php', 'consent_mode' => 'advanced', 'consent_url_passthrough' => '0',
                    'consent_ads_data_redaction' => '1', 'consent_version' => (string)(setting_int('consent_version', 1) + 1)], $a['id'], 'reset');
            });
            flash('success', '已還原預設並更新同意版本；訪客下次載入頁面時會重新詢問。');
        } else {
            $title = post_str('consent_title');
            $text = post_str('consent_text');
            $url = post_str('consent_privacy_url');
            $mode = post_str('consent_mode');
            if (!len_between($title, 1, 50)) { throw new UserError('標題須為 1～50 字。'); }
            if (!len_between($text, 1, 1000)) { throw new UserError('說明文字須為 1～1000 字。'); }
            if (!preg_match('~^(https?://[^\s"<>]+|[a-zA-Z0-9_\-./]+)$~', $url)) { throw new UserError('個資保護聲明連結格式不正確。'); }
            if (!in_array($mode, ['advanced', 'basic'], true)) { throw new UserError('模式不正確。'); }
            admin_transaction(function (PDO $pdo, array $a) use ($title, $text, $url, $mode) {
                settings_save($pdo, ['consent_banner_enabled' => post_str('banner') === '1' ? '1' : '0', 'consent_title' => $title,
                    'consent_text' => $text, 'consent_privacy_url' => $url, 'consent_mode' => $mode,
                    'consent_url_passthrough' => post_str('url_passthrough') === '1' ? '1' : '0',
                    'consent_ads_data_redaction' => post_str('ads_data_redaction') === '1' ? '1' : '0'], $a['id']);
            });
            flash('success', 'Cookie 設定已儲存。');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_consent.php');
}
admin_header('Cookie 橫幅與重設');
?>
<h1>Cookie 橫幅與 Consent Mode v2</h1>
<p class="muted">必要 Cookie 一律啟用；分析（analytics_storage）與廣告（ad_storage、ad_user_data、ad_personalization）預設拒絕。
  訪客的選擇存在第一方 Cookie <code>shop_consent</code>，180 天或同意版本更新後重新詢問；不寫入資料庫。目前同意版本：<?= setting_int('consent_version', 1) ?>。</p>
<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="save">
  <label class="check"><input type="checkbox" name="banner" value="1" <?= setting_on('consent_banner_enabled') ? 'checked' : '' ?>> 自動顯示橫幅（關閉後只取消自動彈出，頁面底部按鈕仍可開啟；未選擇時分析與廣告維持拒絕）</label>
  <label>標題 <input type="text" name="consent_title" value="<?= h(setting('consent_title')) ?>" maxlength="50" required></label>
  <label>說明文字（純文字） <textarea name="consent_text" rows="4" maxlength="1000" required><?= h(setting('consent_text')) ?></textarea></label>
  <label>個資保護聲明連結 <input type="text" name="consent_privacy_url" value="<?= h(setting('consent_privacy_url')) ?>" maxlength="255" required></label>
  <fieldset><legend>實作模式</legend>
    <label class="check"><input type="radio" name="consent_mode" value="advanced" <?= setting('consent_mode') !== 'basic' ? 'checked' : '' ?>> 進階模式（預設）：先以拒絕狀態載入 Google 標籤，再依同意調整</label>
    <label class="check"><input type="radio" name="consent_mode" value="basic" <?= setting('consent_mode') === 'basic' ? 'checked' : '' ?>> 基本模式：取得任一同意前不載入「Google 標籤」類自訂程式碼</label>
  </fieldset>
  <label class="check"><input type="checkbox" name="url_passthrough" value="1" <?= setting_on('consent_url_passthrough') ? 'checked' : '' ?>> url_passthrough（未同意時以網址參數傳遞點擊資訊）</label>
  <label class="check"><input type="checkbox" name="ads_data_redaction" value="1" <?= setting_on('consent_ads_data_redaction') ? 'checked' : '' ?>> ads_data_redaction（廣告未同意時遮蔽廣告點擊資訊）</label>
  <button class="btn btn-primary">儲存</button>
</form>
<form method="post" class="card stack" onsubmit="return confirm('確定還原預設？所有訪客會被重新詢問。')"><?= csrf_field() ?><input type="hidden" name="action" value="reset">
  <h2>還原預設</h2>
  <p>恢復自動顯示與四項預設拒絕，同意版本加一使原選擇失效；不清除會員登入或購物車。已開啟的頁面須重新載入才套用。</p>
  <label class="check"><input type="checkbox" name="confirm" value="1" required> 我確認要還原預設</label>
  <button class="btn btn-danger">還原預設</button>
</form>
<?php
page_footer();
