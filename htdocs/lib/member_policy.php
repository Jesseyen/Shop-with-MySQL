<?php
/**
 * 會員狀態、等級與管理者保護規則（後端判斷，畫面提示也由這裡產生）
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const MEMBER_STATUS_DISABLED = 0;
const MEMBER_STATUS_ACTIVE = 1;
const MEMBER_STATUS_PENDING = 2;

function member_status_label(array $m): string
{
    if ($m['deleted_at'] !== null) { return '已刪除'; }
    switch ((int)$m['status']) {
        case 0: return '停權';
        case 1: return '啟用';
        case 2: return '待驗證';
    }
    return '未知';
}

function member_levels(): array
{
    return ['regular' => '一般', 'silver' => '銀卡', 'gold' => '金卡', 'diamond' => '鑽石'];
}

function member_level_label(?string $lv): string
{
    if ($lv === null) { return '未知'; }
    $l = member_levels();
    return $l[$lv] ?? $lv;
}

/**
 * 停權、刪除屬於「受保護操作」，回傳 null 表示允許，否則回傳原因：
 * - 不能對自己執行
 * - 第五階段起：會員管理模組不能停權或刪除任何管理員；系統管理者不能對註冊時間早於或等於自己的系統管理者執行
 * - 未啟用第五階段時：不能對註冊時間早於或等於自己的其他管理者執行
 */
function policy_protected_op_denied(array $actor, array $target): ?string
{
    global $enable_ops;
    if ((int)$actor['id'] === (int)$target['id']) {
        return '不能停權、刪除或變更自己的權限。';
    }
    if ((int)$target['is_admin'] !== 1) { return null; }
    if ($enable_ops) {
        if ((int)($actor['is_super_admin'] ?? 0) !== 1) { return '會員管理模組不能停權或刪除管理員，請由系統管理者處理。'; }
        if ((int)$target['is_super_admin'] === 1 && strcmp((string)$target['created_at'], (string)$actor['created_at']) <= 0) {
            return '不能停權、刪除或變更註冊時間早於或等於您的系統管理者。';
        }
        return null;
    }
    if (strcmp((string)$target['created_at'], (string)$actor['created_at']) <= 0) {
        return '不能停權、刪除或變更註冊時間早於或等於您的管理者。';
    }
    return null;
}

/**
 * 依目標會員狀態列出允許的管理操作（畫面與後端共用）
 * 第五階段起管理權限改在「管理員與權限」頁授權，這裡的 admin_flag 只在未啟用第五階段時使用。
 */
function member_allowed_actions(array $actor, array $target): array
{
    global $enable_ops;
    $acts = ['edit' => false, 'admin_flag' => false, 'activate' => false, 'suspend' => false, 'delete' => false, 'resend' => false];
    if ($target['deleted_at'] !== null) { return $acts; }
    $protected = policy_protected_op_denied($actor, $target) === null;
    $status = (int)$target['status'];
    $acts['edit'] = true;
    $acts['admin_flag'] = !$enable_ops && $protected;
    $acts['delete'] = $protected && (!$enable_ops || (int)$target['is_admin'] !== 1);
    if ($status === MEMBER_STATUS_ACTIVE) {
        $acts['suspend'] = $protected;
    } elseif ($status === MEMBER_STATUS_DISABLED) {
        $acts['activate'] = $target['email_verified_at'] !== null;
    } elseif ($status === MEMBER_STATUS_PENDING) {
        $acts['resend'] = true;
    }
    return $acts;
}
