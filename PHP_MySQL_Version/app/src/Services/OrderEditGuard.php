<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;

/**
 * Gate for editing order details or product rows once an order has
 * reached Order Confirmation (Stage 4) — before that, editing works
 * exactly as it always has (manage_orders alone). From Stage 4 onward,
 * the caller also needs edit_order_post_confirmation (or is Super Admin,
 * unconditional as everywhere else) and must supply a reason; every such
 * edit is logged. Mirrors the CaFyLockGuard pattern used for the CA
 * module's financial-year-lock override.
 */
final class OrderEditGuard
{
    public static function isPostConfirmation(?int $currentStageNumber): bool
    {
        return $currentStageNumber !== null && $currentStageNumber >= 4;
    }

    public static function allow(
        ?int $currentStageNumber,
        int $userId,
        ?int $roleId,
        string $reason,
        string $entityType,
        int $entityId,
        string $field
    ): bool {
        if (!self::isPostConfirmation($currentStageNumber)) {
            return true;
        }
        if (!PermissionService::can($userId, $roleId, 'edit_order_post_confirmation')) {
            Flash::set('error', 'This order has already reached Order Confirmation — editing it now needs the "Edit an order after confirmation" permission.');
            return false;
        }
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            return false;
        }

        AuditLogRepository::log($userId, 'ORDER_EDITED_POST_CONFIRMATION', $entityType, $entityId, $field, null, null, $reason);
        Flash::set('warning', "Editing this order after confirmation — logged for audit ({$field}).");
        return true;
    }

    public static function canOverride(int $userId, ?int $roleId): bool
    {
        return PermissionService::can($userId, $roleId, 'edit_order_post_confirmation');
    }
}
