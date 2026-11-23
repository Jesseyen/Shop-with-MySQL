<?php
/**
 * 聯繫表單、客服中心與訊息中心（第五階段，第 24 節）
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function support_category_labels(): array
{
    return ['product' => '商品詢問', 'order' => '訂單問題', 'return' => '退換貨', 'account' => '帳號問題', 'other' => '其他'];
}
function support_status_labels(): array { return ['new' => '未處理', 'in_progress' => '處理中', 'done' => '已處理']; }
function support_event_labels(): array
{
    return ['create' => '建立訊息', 'take' => '接手處理', 'status' => '狀態變更', 'note' => '處理摘要', 'reply' => '回覆客戶'];
}

/** 驗證發問欄位，回傳 [資料, 錯誤] */
function support_validate(array $in, bool $member): array
{
    $d = ['name' => trim((string)($in['name'] ?? '')), 'email' => normalize_email((string)($in['email'] ?? '')),
          'phone' => trim((string)($in['phone'] ?? '')), 'category' => (string)($in['category'] ?? ''),
          'subject' => trim((string)($in['subject'] ?? '')), 'body' => trim(str_replace("\r\n", "\n", (string)($in['body'] ?? ''))),
          'order_id' => to_pos_int($in['order_id'] ?? '')];
    $e = [];
    if (!$member) {
        if (!len_between($d['name'], 1, 50)) { $e[] = '姓名須為 1～50 字。'; }
        if (!valid_email($d['email'])) { $e[] = 'Email 格式不正確。'; }
    }
    if ($d['phone'] !== '' && !preg_match('/^[0-9\-+ ]{6,20}$/', $d['phone'])) { $e[] = '電話格式不正確。'; }
    if (!isset(support_category_labels()[$d['category']])) { $e[] = '請選擇類別。'; }
    if (!len_between($d['subject'], 1, 100)) { $e[] = '主旨須為 1～100 字。'; }
    if (!len_between($d['body'], 10, 2000)) { $e[] = '內容須為 10～2,000 字。'; }
    return [$d, $e];
}

/** 建立訊息（交易內），回傳 [id, message_no] */
function support_create(PDO $pdo, string $source, ?array $member, array $d): array
{
    $no = next_doc_no($pdo, 'M');
    $pdo->prepare('INSERT INTO support_messages (message_no, source, member_id, name, email, phone, category, subject, body, order_id, ip)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$no, $source, $member['id'] ?? null, $member ? $member['name'] : $d['name'], $member ? $member['email'] : $d['email'],
                   $d['phone'] === '' ? null : $d['phone'], $d['category'], $d['subject'], $d['body'], $d['order_id'], client_ip()]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO support_message_events (message_id, event_type, to_status, is_public, actor_id) VALUES (?, 'create', 'new', 1, ?)")
        ->execute([$id, $member['id'] ?? null]);
    return [$id, $no];
}

/** 同一 IP 每小時最多 5 則 */
function support_rate_limited(): bool
{
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM support_messages WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= 5;
}

function support_confirm_mail(string $type, int $id, string $no, string $email, string $name, string $subject, ?int $memberId): void
{
    send_notice_mail($email, $name, $type, '我們已收到您的訊息 ' . $no, [
        $name . ' 您好：', '我們已收到您的訊息（編號 ' . $no . '），客服會盡快回覆您。', '主旨：' . $subject,
        $memberId ? '您也可以在會員中心的「客服中心」查看處理進度與回覆。' : '如需補充說明，請再次使用聯繫表單並註明訊息編號。',
    ], ['member_id' => $memberId, 'message_id' => $id]);
}
