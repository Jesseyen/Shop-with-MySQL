<?php
/**
 * 會員申請刪除帳號與資料匿名化（第六階段，第 25 節）
 * 軟刪除前自動檢查訂單相關處理是否都已結案；執行時在同一筆交易內清除姓名、地址、電話等個資，只留關聯所需資訊。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function deletion_status_labels(): array
{
    return ['pending' => '待處理', 'cancelled' => '已取消', 'rejected' => '已拒絕', 'completed' => '已完成'];
}

function deletion_pending_request(int $memberId): ?array
{
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM account_deletion_requests WHERE member_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $st->execute([$memberId]);
    return $st->fetch() ?: null;
}

/** 列出阻擋刪除的未結案項目 */
function deletion_blockers(PDO $pdo, int $memberId): array
{
    $q = function (string $sql) use ($pdo, $memberId) {
        $st = $pdo->prepare($sql);
        $st->execute([$memberId]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    };
    $out = [];
    foreach ($q("SELECT CONCAT(order_no, '（', status, '）') FROM orders WHERE member_id = ? AND status IN ('pending_payment','processing','shipped')") as $x) {
        $out[] = '訂單未結案：' . strtr($x, ['pending_payment' => '待付款', 'processing' => '處理中', 'shipped' => '已出貨']);
    }
    foreach ($q("SELECT CONCAT(as_no, '（', status, '）') FROM after_sales WHERE member_id = ? AND status IN ('requested','in_progress')") as $x) {
        $out[] = '售後單未結案：' . strtr($x, ['requested' => '申請中', 'in_progress' => '處理中，含已收件待登錄退款']);
    }
    foreach ($q("SELECT s.shipment_no FROM shipments s JOIN orders o ON o.id = s.order_id WHERE o.member_id = ? AND s.status IN ('pending','on_hold')") as $x) {
        $out[] = '出貨單未結案：' . $x;
    }
    foreach ($q("SELECT r.receipt_no FROM return_receipts r JOIN orders o ON o.id = r.order_id WHERE o.member_id = ? AND r.status = 'pending'") as $x) {
        $out[] = '收件單待收件：' . $x;
    }
    foreach ($q("SELECT message_no FROM support_messages WHERE member_id = ? AND status IN ('new','in_progress')") as $x) {
        $out[] = '客服訊息未處理完成：' . $x;
    }
    $st = $pdo->prepare('SELECT is_admin FROM members WHERE id = ?');
    $st->execute([$memberId]);
    if ((int)$st->fetchColumn() === 1) { $out[] = '此帳號為管理員，請先由系統管理者取消全部權限'; }
    return $out;
}

function mask_email(string $email): string
{
    return mb_substr($email, 0, 1) . '***@***';
}

/** 把 JSON 內的個資欄位改為「[已刪除]」 */
function scrub_json(?string $json): ?string
{
    if ($json === null) { return null; }
    $d = json_decode($json, true);
    if (!is_array($d)) { return $json; }
    $keys = ['name', 'email', 'phone', 'recipient', 'zip', 'district', 'address', 'ship_name', 'ship_phone', 'ship_zip',
             'ship_district', 'ship_address', 'note', 'reason', 'body', 'subject', 'to_email'];
    $walk = function (&$a) use (&$walk, $keys) {
        foreach ($a as $k => &$v) {
            if (is_array($v)) { $walk($v); }
            elseif (in_array((string)$k, $keys, true) && $v !== null && $v !== '') { $v = '[已刪除]'; }
        }
    };
    $walk($d);
    return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** 交易內：匿名化會員（會員列須已鎖定） */
function member_anonymize(PDO $pdo, int $memberId, int $actorId): void
{
    $blockers = deletion_blockers($pdo, $memberId);
    if ($blockers) { throw new UserError('尚有未結案項目，不能刪除：' . implode('；', $blockers)); }
    $junk = '!' . bin2hex(random_bytes(30));
    $pdo->prepare("UPDATE members SET name = '已刪除會員', email = ?, password_hash = ?, status = 0, is_admin = 0, is_super_admin = 0,
                          email_verify_token_hash = NULL, email_verify_expires_at = NULL, reset_password_token = NULL, reset_password_expires_at = NULL,
                          deleted_at = COALESCE(deleted_at, NOW()), anonymized_at = NOW(), row_version = row_version + 1 WHERE id = ?")
        ->execute(['deleted-' . $memberId . '@deleted.invalid', $junk, $memberId]);
    $pdo->prepare('DELETE FROM admin_permissions WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('DELETE FROM member_addresses WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('UPDATE orders SET ship_name = NULL, ship_phone = NULL, ship_zip = NULL, ship_district = NULL, ship_address = NULL, note = NULL
                    WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('UPDATE shipments s JOIN orders o ON o.id = s.order_id
                      SET s.ship_name = NULL, s.ship_phone = NULL, s.ship_zip = NULL, s.ship_district = NULL, s.ship_address = NULL
                    WHERE o.member_id = ?')->execute([$memberId]);
    $pdo->prepare('UPDATE after_sales SET ship_name = NULL, ship_phone = NULL, ship_zip = NULL, ship_district = NULL, ship_address = NULL,
                          description = NULL, return_carrier = NULL, return_tracking = NULL WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('UPDATE support_messages SET name = NULL, email = NULL, phone = NULL, subject = NULL, body = NULL, ip = NULL WHERE member_id = ?')
        ->execute([$memberId]);
    $pdo->prepare("UPDATE support_message_events e JOIN support_messages m ON m.id = e.message_id SET e.content = NULL
                    WHERE m.member_id = ? AND e.event_type IN ('reply','create')")->execute([$memberId]);
    $st = $pdo->prepare('SELECT id, to_email FROM mail_logs WHERE member_id = ?');
    $st->execute([$memberId]);
    $up = $pdo->prepare('UPDATE mail_logs SET to_email = ?, ip = NULL WHERE id = ?');
    foreach ($st->fetchAll() as $r) { $up->execute([mask_email($r['to_email']), $r['id']]); }
    $st = $pdo->prepare("SELECT id, before_data, after_data FROM audit_logs WHERE (entity_type = 'member' AND entity_id = ?) OR actor_id = ?");
    $st->execute([(string)$memberId, $memberId]);
    $up = $pdo->prepare('UPDATE audit_logs SET before_data = ?, after_data = ?, ip = NULL WHERE id = ?');
    foreach ($st->fetchAll() as $r) { $up->execute([scrub_json($r['before_data']), scrub_json($r['after_data']), $r['id']]); }
    $pdo->prepare('UPDATE security_logs SET ip = NULL WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('UPDATE error_logs SET ip = NULL WHERE member_id = ?')->execute([$memberId]);
    $st = $pdo->prepare('SELECT ci.id FROM carts c JOIN cart_items ci ON ci.cart_id = c.id WHERE c.member_id = ?');
    $st->execute([$memberId]);
    $items = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($items) {
        $in = implode(',', array_fill(0, count($items), '?'));
        $pdo->prepare("UPDATE cart_price_lots SET remaining_quantity = 0 WHERE cart_item_id IN ({$in})")->execute($items);
        $pdo->prepare("DELETE FROM cart_items WHERE id IN ({$in})")->execute($items);
    }
    $pdo->prepare("UPDATE account_deletion_requests SET reason = NULL WHERE member_id = ?")->execute([$memberId]);
    audit_log($pdo, $actorId, 'member', $memberId, 'anonymize', null, ['anonymized' => true]);
}
