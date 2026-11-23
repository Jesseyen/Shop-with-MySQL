<?php
/**
 * 寄信：PHPMailer 透過外部 SMTP（InfinityFree 的 PHP mail() 受嚴格限制，不使用）。
 * - 第五階段：每封信寫一筆 mail_logs（不存內文與連結），失敗原因只在後台看得到
 * - 第六階段：訪客觸發的信件依 IP、Email 與全站上限限量；超額時不寄出但畫面維持一般訊息
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function mail_type_labels(): array
{
    return [
        'verify' => '註冊驗證', 'resend_verify' => '補寄驗證', 'admin_resend_verify' => '後台補寄驗證', 'reset_password' => '重設密碼',
        'order_created' => '訂單成立', 'order_shipped' => '已出貨', 'order_cancelled' => '訂單取消',
        'after_sale_approved' => '售後核准', 'after_sale_rejected' => '售後拒絕', 'return_completed' => '退貨完成',
        'exchange_shipped' => '換貨新品寄出', 'contact_confirm' => '聯繫確認信', 'support_reply' => '客服回覆',
        'support_member' => '客服中心發問', 'cvs_arrived' => '超商到店通知', 'payment_refunded' => '模擬退款通知',
        'deletion_requested' => '刪除帳號申請', 'deletion_rejected' => '刪除申請退回', 'deletion_completed' => '帳號刪除完成',
        'test' => '測試信',
    ];
}

/** 訪客觸發信件類型（第 34 節） */
function mail_visitor_types(): array
{
    return ['verify', 'resend_verify', 'reset_password', 'contact_confirm', 'support_member'];
}

/** 寄信額度檢查：回傳 null 表示可寄，否則回傳原因 */
function mail_quota_exceeded(string $toEmail): ?string
{
    global $pdo;
    $ip = client_ip();
    $q = function (string $sql, array $args) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return (int)$st->fetchColumn();
    };
    $base = "SELECT COUNT(*) FROM mail_logs WHERE visitor_triggered = 1 AND result = 'success' AND ";
    if ($q($base . 'ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip]) >= setting_int('quota_ip_hour', 5)) { return '同一 IP 每小時額度'; }
    if ($q($base . 'ip = ? AND created_at > NOW() - INTERVAL 1 DAY', [$ip]) >= setting_int('quota_ip_day', 20)) { return '同一 IP 每日額度'; }
    if ($q($base . 'to_email = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$toEmail]) >= setting_int('quota_email_hour', 3)) { return '同一 Email 每小時額度'; }
    if ($q($base . 'created_at >= CURDATE()', []) >= setting_int('quota_site_day', 300)) { return '全站訪客觸發信件每日上限'; }
    return null;
}

/**
 * 寄信並記錄。$meta：type、member_id、order_id、after_sale_id、message_id。
 * 回傳 true 表示已寄出（或因額度未寄出但對訪客維持一般訊息），false 表示寄送失敗。
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text, array $meta = []): bool
{
    global $smtp_host, $smtp_port, $smtp_secure, $smtp_username, $smtp_password, $mail_from, $mail_from_name,
           $enable_ops, $enable_launch;
    $type = $meta['type'] ?? 'other';
    $visitor = in_array($type, mail_visitor_types(), true);
    $started = microtime(true);
    $error = null;
    $result = 'success';

    if ($enable_launch && $visitor) {
        try {
            $why = mail_quota_exceeded($toEmail);
        } catch (Throwable $e) {
            $why = null;
        }
        if ($why !== null) {
            mail_log_write($type, $toEmail, $subject, 'quota_blocked', '超過額度未寄出：' . $why, $meta, $visitor, 0);
            security_log('mail_quota', '寄信超過額度（' . $why . '）：' . (mail_type_labels()[$type] ?? $type), true);
            return true;
        }
    }

    if ($smtp_host === 'log') {
        // 本機測試：寫入暫存檔，不實際寄出
        $line = date('c') . "\tTO: {$toEmail}\tSUBJECT: {$subject}\n{$text}\n-----\n";
        if (file_put_contents(sys_get_temp_dir() . '/shop_mail.log', $line, FILE_APPEND) === false) {
            $result = 'failed';
            $error = '無法寫入本機測試信件檔';
        }
    } else {
        $base = dirname(__DIR__) . '/vendor/phpmailer/src/';
        if (!is_file($base . 'PHPMailer.php')) {
            $result = 'failed';
            $error = 'PHPMailer 檔案不存在：vendor/phpmailer/src/';
        } else {
            require_once $base . 'Exception.php';
            require_once $base . 'PHPMailer.php';
            require_once $base . 'SMTP.php';
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->Port = (int)$smtp_port;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_username;
                $mail->Password = $smtp_password;
                $mail->SMTPSecure = ($smtp_secure === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Timeout = 15;
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';
                $mail->setFrom($mail_from, $mail_from_name);
                $mail->addAddress($toEmail, $toName);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $html;
                $mail->AltBody = $text;
                $mail->send();
            } catch (MailException $e) {
                $result = 'failed';
                $error = $mail->ErrorInfo;
            } catch (Throwable $e) {
                $result = 'failed';
                $error = $e->getMessage();
            }
        }
    }
    if ($result === 'failed') { error_log('[shop] 寄信失敗：' . $error); }
    if ($enable_ops) {
        mail_log_write($type, $toEmail, $subject, $result, $error, $meta, $visitor, (int)round((microtime(true) - $started) * 1000));
    }
    return $result === 'success';
}

function mail_log_write(string $type, string $to, string $subject, string $result, ?string $error, array $meta, bool $visitor, int $ms): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO mail_logs (mail_type, to_email, subject, result, error_message, member_id, order_id,
                                              after_sale_id, message_id, ip, visitor_triggered, duration_ms)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$type, mb_substr($to, 0, 191), mb_substr($subject, 0, 200), $result,
                       $error === null ? null : mb_substr($error, 0, 1000),
                       $meta['member_id'] ?? null, $meta['order_id'] ?? null, $meta['after_sale_id'] ?? null,
                       $meta['message_id'] ?? null, client_ip(), $visitor ? 1 : 0, $ms]);
    } catch (Throwable $e) {
        error_log('[shop] 寄信紀錄寫入失敗：' . $e->getMessage());
    }
}

function mail_layout(string $title, string $bodyHtml): string
{
    global $site_name, $enable_ops;
    $sign = $enable_ops ? '<p style="color:#555;font-size:13px">' . h(operator_name()) . '</p>' : '';
    return '<div style="font-family:Arial,\'Noto Sans TC\',sans-serif;max-width:560px;margin:auto;color:#222">'
        . '<h2 style="color:#0f6e56">' . h($site_name) . '</h2><h3>' . h($title) . '</h3>' . $bodyHtml . $sign
        . '<p style="color:#888;font-size:12px">此信由系統自動寄出，請勿直接回覆。</p></div>';
}

function mail_button(string $link, string $label): string
{
    return '<p><a href="' . h($link) . '" style="background:#0f6e56;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">'
        . h($label) . '</a></p><p style="word-break:break-all;font-size:12px">' . h($link) . '</p>';
}

/** 寄一封以純文字段落組成的通知信 */
function send_notice_mail(string $toEmail, string $toName, string $type, string $title, array $paragraphs, array $meta = [], ?array $button = null): bool
{
    global $site_name;
    $html = '';
    $text = '';
    foreach ($paragraphs as $p) {
        $html .= '<p>' . nl2br(h($p), false) . '</p>';
        $text .= $p . "\n\n";
    }
    if ($button) {
        $html .= mail_button($button[0], $button[1]);
        $text .= $button[1] . '：' . $button[0] . "\n";
    }
    $meta['type'] = $type;
    return send_mail($toEmail, $toName, "【{$site_name}】{$title}", mail_layout($title, $html), $text, $meta);
}

/** Email 驗證信：連結 30 分鐘內有效、只能使用一次 */
function send_verify_mail(string $email, string $name, string $token, ?string $returnTo = null, string $type = 'verify', ?int $memberId = null): bool
{
    $q = ['token' => $token];
    if ($returnTo !== null) { $q['return_to'] = $returnTo; }
    $link = abs_url('verify.php?' . http_build_query($q));
    return send_notice_mail($email, $name, $type, 'Email 驗證',
        [$name . ' 您好：', '請在 30 分鐘內點擊下方連結完成驗證，連結只能使用一次。'],
        ['member_id' => $memberId], [$link, '驗證 Email']);
}

/** 密碼重設信：連結 30 分鐘內有效，重新申請會使舊連結失效 */
function send_reset_mail(string $email, string $name, string $token, ?int $memberId = null): bool
{
    $link = abs_url('reset_password.php?' . http_build_query(['token' => $token]));
    return send_notice_mail($email, $name, 'reset_password', '重設密碼',
        [$name . ' 您好：', '我們收到重設密碼的申請。請在 30 分鐘內點擊下方連結設定新密碼；如果不是您本人申請，請忽略此信。'],
        ['member_id' => $memberId], [$link, '設定新密碼']);
}

/**
 * 為待驗證會員產生新的驗證憑證（取代舊憑證），回傳明文；不符合條件回傳 null。
 */
function issue_verify_token(PDO $pdo, int $memberId): ?string
{
    [$plain, $hash] = new_token();
    $st = $pdo->prepare('UPDATE members
        SET email_verify_token_hash = ?, email_verify_expires_at = NOW() + INTERVAL 30 MINUTE, row_version = row_version + 1
        WHERE id = ? AND status = 2 AND deleted_at IS NULL');
    $st->execute([$hash, $memberId]);
    return $st->rowCount() === 1 ? $plain : null;
}
