<?php
/**
 * 訂單與售後通知信（交易提交後寄出；失敗只寫寄信紀錄，不影響單據）
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function mail_order_lines(int $orderId): array
{
    $lines = [];
    foreach (order_items($orderId) as $it) {
        $lines[] = '・' . $it['product_name'] . ($it['variant_text'] ? '（' . $it['variant_text'] . '）' : '') . ' × ' . $it['quantity']
            . '　' . money($it['subtotal']);
    }
    return $lines;
}

function mail_order_created(int $orderId): void
{
    $o = order_find($orderId);
    if (!$o) { return; }
    $ship = $o['shipping_method'] === 'cvs'
        ? '取貨門市：' . label_of(cvs_chain_labels(), $o['cvs_chain']) . ' ' . $o['cvs_store_name'] . '（' . $o['cvs_store_address'] . '）'
        : '收件地址：' . address_line($o, 'ship_');
    send_notice_mail($o['member_email'], $o['member_name'], 'order_created', '訂單成立通知 ' . $o['order_no'], [
        $o['member_name'] . ' 您好：', '感謝您的訂購，訂單已成立。',
        "訂單編號：{$o['order_no']}\n" . implode("\n", mail_order_lines($orderId)),
        '商品小計：' . money($o['subtotal']) . "\n運費：" . money($o['shipping_fee']) . "\n手續費：" . money($o['cod_fee']) . "\n總額：" . money($o['total']),
        '配送方式：' . label_of(shipping_method_labels(), $o['shipping_method']) . "\n付款方式：" . label_of(payment_method_labels(), $o['payment_method']) . "\n" . $ship,
        '收到商品後可在「我的訂單」按「我已收到，完成訂單」；完成後 ' . setting_int('return_days', 7) . ' 天內可申請退換貨。',
    ], ['member_id' => (int)$o['member_id'], 'order_id' => $orderId], [abs_url('member_orders.php?id=' . $orderId), '查看訂單']);
}

function mail_order_shipped(int $orderId): void
{
    $o = order_find($orderId);
    if (!$o) { return; }
    $s = null;
    foreach (order_shipments($orderId) as $x) { if ($x['type'] === 'order') { $s = $x; } }
    send_notice_mail($o['member_email'], $o['member_name'], 'order_shipped', '訂單已出貨 ' . $o['order_no'], [
        $o['member_name'] . ' 您好：', '您的訂單 ' . $o['order_no'] . ' 已出貨。',
        '物流商：' . shipment_carrier_label($s['carrier'] ?? null) . "\n追蹤碼：" . ($s['tracking_no'] ?? '') . "\n出貨日：" . substr((string)($s['shipped_at'] ?? ''), 0, 10),
        $o['shipping_method'] === 'cvs' ? '商品到店後會再通知您取貨。' : '收到商品後請在「我的訂單」按「我已收到，完成訂單」；出貨滿 ' . setting_int('auto_complete_days', 3) . ' 天系統會自動完成。',
    ], ['member_id' => (int)$o['member_id'], 'order_id' => $orderId], [abs_url('member_orders.php?id=' . $orderId), '查看訂單']);
}

function mail_order_cancelled(int $orderId, string $reason, bool $refunded): void
{
    $o = order_find($orderId);
    if (!$o) { return; }
    $p = [$o['member_name'] . ' 您好：', '您的訂單 ' . $o['order_no'] . ' 已取消。', '原因：' . $reason];
    if ($refunded) { $p[] = '已以原付款方式（模擬）退款 ' . money($o['total']) . '。'; }
    send_notice_mail($o['member_email'], $o['member_name'], 'order_cancelled', '訂單已取消 ' . $o['order_no'], $p,
        ['member_id' => (int)$o['member_id'], 'order_id' => $orderId]);
}

function mail_after_sale_ctx(int $asId): ?array
{
    global $pdo;
    $st = $pdo->prepare('SELECT a.*, o.order_no, m.name, m.email FROM after_sales a JOIN orders o ON o.id = a.order_id
                           JOIN members m ON m.id = a.member_id WHERE a.id = ?');
    $st->execute([$asId]);
    $a = $st->fetch();
    if (!$a) { return null; }
    $it = $pdo->prepare('SELECT asi.quantity, oi.product_name, oi.variant_text FROM after_sale_items asi JOIN order_items oi ON oi.id = asi.order_item_id
                          WHERE asi.after_sale_id = ?');
    $it->execute([$asId]);
    $a['lines'] = array_map(function ($r) {
        return '・' . $r['product_name'] . ($r['variant_text'] ? '（' . $r['variant_text'] . '）' : '') . ' × ' . $r['quantity'];
    }, $it->fetchAll());
    return $a;
}

function mail_after_sale_approved(int $asId): void
{
    $a = mail_after_sale_ctx($asId);
    if (!$a) { return; }
    $type = after_sale_type_labels()[$a['type']];
    $addr = trim(setting('return_address'));
    $contact = trim(site_content('contact_info')['content']);
    send_notice_mail($a['email'], $a['name'], 'after_sale_approved', $type . '申請已核准 ' . $a['as_no'], [
        $a['name'] . ' 您好：', '您的' . $type . '申請（售後單 ' . $a['as_no'] . '，訂單 ' . $a['order_no'] . '）已核准，請將下列商品寄回：',
        implode("\n", $a['lines']),
        '寄回地址：' . ($addr !== '' ? $addr : '請聯絡客服取得寄回地址'),
        '請在包裹內附上售後單編號 ' . $a['as_no'] . '，寄出後可在「我的訂單」填寫寄回物流單號。',
        $a['type'] === 'exchange' ? '我們收到並驗收商品後，會寄出同規格的新品。' : '我們收到並驗收商品後，客服會與您聯繫退款事宜。',
        $contact !== '' ? "客服聯絡方式：\n" . $contact : '',
    ], ['member_id' => (int)$a['member_id'], 'order_id' => (int)$a['order_id'], 'after_sale_id' => $asId],
        [abs_url('member_orders.php?id=' . (int)$a['order_id']), '查看售後進度']);
}

function mail_after_sale_rejected(int $asId): void
{
    $a = mail_after_sale_ctx($asId);
    if (!$a) { return; }
    $type = after_sale_type_labels()[$a['type']];
    send_notice_mail($a['email'], $a['name'], 'after_sale_rejected', $type . '申請未核准 ' . $a['as_no'], [
        $a['name'] . ' 您好：', '很抱歉，您的' . $type . '申請（售後單 ' . $a['as_no'] . '）未核准。', '原因：' . $a['reject_reason'],
        '如有疑問，請透過客服中心與我們聯繫。',
    ], ['member_id' => (int)$a['member_id'], 'order_id' => (int)$a['order_id'], 'after_sale_id' => $asId]);
}

function mail_return_completed(int $asId): void
{
    $a = mail_after_sale_ctx($asId);
    if (!$a) { return; }
    send_notice_mail($a['email'], $a['name'], 'return_completed', '退貨完成 ' . $a['as_no'], [
        $a['name'] . ' 您好：', '您的退貨（售後單 ' . $a['as_no'] . '）已完成。',
        '退款金額：' . money($a['refund_amount']) . "\n退款方式：" . $a['refund_method'] . "\n退款日期：" . $a['refund_date'],
    ], ['member_id' => (int)$a['member_id'], 'order_id' => (int)$a['order_id'], 'after_sale_id' => $asId]);
}

function mail_exchange_shipped(int $asId): void
{
    global $pdo;
    $a = mail_after_sale_ctx($asId);
    if (!$a) { return; }
    $st = $pdo->prepare("SELECT carrier, tracking_no FROM shipments WHERE after_sale_id = ? AND type = 'exchange'");
    $st->execute([$asId]);
    $s = $st->fetch() ?: [];
    send_notice_mail($a['email'], $a['name'], 'exchange_shipped', '換貨新品已寄出 ' . $a['as_no'], [
        $a['name'] . ' 您好：', '您的換貨新品（售後單 ' . $a['as_no'] . '）已寄出。', implode("\n", $a['lines']),
        '物流商：' . shipment_carrier_label($s['carrier'] ?? null) . "\n追蹤碼：" . ($s['tracking_no'] ?? ''),
    ], ['member_id' => (int)$a['member_id'], 'order_id' => (int)$a['order_id'], 'after_sale_id' => $asId]);
}

function mail_cvs_arrived(int $orderId, string $deadline): void
{
    $o = order_find($orderId);
    if (!$o) { return; }
    $pay = $o['payment_method'] === 'cvs_cod' ? '取貨時請付款 ' . money($o['total']) . '。' : '此訂單已付款，取貨時不需付款。';
    send_notice_mail($o['member_email'], $o['member_name'], 'cvs_arrived', '商品已到店 ' . $o['order_no'], [
        $o['member_name'] . ' 您好：', '您的訂單 ' . $o['order_no'] . ' 已送達 ' . label_of(cvs_chain_labels(), $o['cvs_chain']) . ' ' . $o['cvs_store_name'] . '。',
        '請於 ' . $deadline . ' 前攜帶證件取貨，逾期未取將退回。', $pay,
    ], ['member_id' => (int)$o['member_id'], 'order_id' => $orderId]);
}

function mail_payment_refunded(int $orderId, string $amount): void
{
    $o = order_find($orderId);
    if (!$o) { return; }
    send_notice_mail($o['member_email'], $o['member_name'], 'payment_refunded', '退款通知 ' . $o['order_no'], [
        $o['member_name'] . ' 您好：', '訂單 ' . $o['order_no'] . ' 已以原付款方式（模擬）退款 ' . money($amount) . '。',
    ], ['member_id' => (int)$o['member_id'], 'order_id' => $orderId]);
}
