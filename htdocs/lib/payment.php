<?php
/**
 * 模擬線上付款（第六階段，第 31 節）
 * - 付款程式以介面（PaymentGateway）實作，之後可換成綠界、藍新等真實金流
 * - 模擬頁只提供成功、失敗、取消三個按鈕，不輸入、不儲存任何卡號或帳號
 * - 付款請求以 HMAC-SHA256 簽章（金鑰在 config.php 的 $payment_hmac_key）
 * - 每次付款嘗試寫一筆 payments；重複的回報不重複處理
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

interface PaymentGateway
{
    /** 建立付款後要導向的網址 */
    public function startUrl(array $order, array $payment, string $token): string;
    /** 驗證回報簽章 */
    public function verify(array $data): bool;
}

class SimulatedGateway implements PaymentGateway
{
    public function key(): string
    {
        global $payment_hmac_key, $db_pass, $db_name;
        return $payment_hmac_key !== '' ? $payment_hmac_key : hash('sha256', 'shop-sim|' . $db_name . '|' . $db_pass);
    }

    public function sign(int $paymentId, string $orderNo, string $amount, string $token): string
    {
        return hash_hmac('sha256', $paymentId . '|' . $orderNo . '|' . $amount . '|' . $token, $this->key());
    }

    public function startUrl(array $order, array $payment, string $token): string
    {
        $q = ['pid' => $payment['id'], 'order' => $order['order_no'], 'amount' => $payment['amount'], 't' => $token,
              'sig' => $this->sign((int)$payment['id'], $order['order_no'], $payment['amount'], $token)];
        return 'pay_sim.php?' . http_build_query($q);
    }

    public function verify(array $d): bool
    {
        foreach (['pid', 'order', 'amount', 't', 'sig'] as $k) {
            if (!isset($d[$k]) || !is_string($d[$k])) { return false; }
        }
        if (to_pos_int($d['pid']) === null || !preg_match('/^[a-f0-9]{64}$/', $d['t'])) { return false; }
        return hash_equals($this->sign((int)$d['pid'], $d['order'], $d['amount'], $d['t']), $d['sig']);
    }
}

function payment_gateway(): PaymentGateway
{
    static $g = null;
    return $g ?? ($g = new SimulatedGateway());
}

function is_online_payment(string $method): bool
{
    return in_array($method, ['sim_card', 'sim_paypal'], true);
}

/** 建立一次付款嘗試，回傳導向網址（交易內呼叫，訂單須已鎖定） */
function payment_start(PDO $pdo, array $o): string
{
    if ($o['status'] !== 'pending_payment' || !is_online_payment($o['payment_method'])) { throw new UserError('此訂單不需要線上付款。'); }
    if (strtotime($o['payment_due_at']) < time()) { throw new UserError('已超過付款期限，訂單將自動取消。'); }
    $st = $pdo->prepare('SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM payments WHERE order_id = ?');
    $st->execute([$o['id']]);
    $attempt = (int)$st->fetchColumn();
    [$token, $hash] = new_token();
    $pdo->prepare("UPDATE payments SET status = 'cancelled', completed_at = NOW() WHERE order_id = ? AND status = 'created'")->execute([$o['id']]);
    $pdo->prepare('INSERT INTO payments (order_id, method, attempt_no, amount, token_hash) VALUES (?, ?, ?, ?, ?)')
        ->execute([$o['id'], $o['payment_method'], $attempt, $o['total'], $hash]);
    $pid = (int)$pdo->lastInsertId();
    order_event($pdo, (int)$o['id'], 'payment', null, null, 'member', (int)$o['member_id'], null, '建立第 ' . $attempt . ' 次付款');
    return payment_gateway()->startUrl($o, ['id' => $pid, 'amount' => $o['total']], $token);
}

/**
 * 處理付款回報：$result 為 success／failed／cancelled。回傳 ['order_id' => , 'status' => 'paid'|'failed'|'cancelled'|'duplicate']
 */
function payment_handle(array $data, string $result): array
{
    if (!payment_gateway()->verify($data)) { throw new UserError('付款資料驗證失敗（簽章不符或資料遭竄改）。'); }
    if (!in_array($result, ['success', 'failed', 'cancelled'], true)) { throw new UserError('付款結果不正確。'); }
    $pid = (int)$data['pid'];
    return db_transaction(function (PDO $pdo) use ($pid, $data, $result) {
        $st = $pdo->prepare('SELECT order_id FROM payments WHERE id = ?');
        $st->execute([$pid]);
        $oid = $st->fetchColumn();
        if ($oid === false) { throw new UserError('找不到此付款紀錄。'); }
        $o = order_lock($pdo, (int)$oid);
        $st = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!hash_equals($p['token_hash'], hash('sha256', $data['t'])) || $data['order'] !== $o['order_no']
            || dec_to_cents($data['amount']) !== dec_to_cents($p['amount']) || dec_to_cents($p['amount']) !== dec_to_cents($o['total'])) {
            throw new UserError('付款資料驗證失敗（金額或訂單不符）。');
        }
        if ($p['status'] !== 'created') {
            return ['order_id' => (int)$o['id'], 'status' => 'duplicate', 'payment_status' => $p['status']];
        }
        if ($result !== 'success') {
            $pdo->prepare('UPDATE payments SET status = ?, completed_at = NOW() WHERE id = ?')->execute([$result, $pid]);
            if ($o['status'] === 'pending_payment') {
                order_update($pdo, $o, ['payment_status' => 'failed']);
                order_event($pdo, (int)$o['id'], 'payment', null, null, 'system', null,
                    $result === 'failed' ? '付款失敗，可重新付款' : '已取消付款，可重新付款');
            }
            return ['order_id' => (int)$o['id'], 'status' => $result];
        }
        if ($o['status'] !== 'pending_payment') {
            $pdo->prepare("UPDATE payments SET status = 'cancelled', completed_at = NOW() WHERE id = ?")->execute([$pid]);
            throw new UserError('此訂單已' . label_of(order_status_labels(), $o['status']) . '，付款未完成。');
        }
        $pdo->prepare("UPDATE payments SET status = 'succeeded', completed_at = NOW() WHERE id = ?")->execute([$pid]);
        order_update($pdo, $o, ['status' => 'processing', 'payment_status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
        order_event($pdo, (int)$o['id'], 'status', 'pending_payment', 'processing', 'system', null,
            '付款成功（' . label_of(payment_method_labels(), $o['payment_method']) . '），訂單處理中');
        [$byItem] = order_item_maps($pdo, (int)$o['id']);
        shipment_create($pdo, $o, 'order', 'pending', null, $byItem, $o);
        $orderId = (int)$o['id'];
        defer_mail(function () use ($orderId) { mail_order_created($orderId); });
        return ['order_id' => $orderId, 'status' => 'paid'];
    });
}

/** 以原付款方式模擬退款（交易內呼叫） */
function payment_refund_sim(PDO $pdo, array $o, string $amount): void
{
    $st = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? AND status IN ('succeeded','refunded') ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $st->execute([$o['id']]);
    $p = $st->fetch();
    if (!$p) { throw new UserError('找不到成功的付款紀錄，無法模擬退款。'); }
    $new = dec_to_cents($p['refunded_amount']) + dec_to_cents($amount);
    if ($new > dec_to_cents($p['amount'])) { throw new UserError('退款金額超過付款金額。'); }
    $pdo->prepare('UPDATE payments SET refunded_amount = ?, status = ? WHERE id = ?')
        ->execute([cents_to_dec($new), $new >= dec_to_cents($p['amount']) ? 'refunded' : 'succeeded', $p['id']]);
}
