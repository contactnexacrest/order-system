<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\ProductCategoryRepository;

/**
 * D-435 (owner 26 Nov 2026): a product added by a brand manager waits in Approvals › New products until
 * someone with "Approve new products" (MD, ED by default) approves it — when the setting
 * brand_products_need_approval is on (default on). Until then it cannot be quoted, ordered or seen by associates.
 * Nobody approves a product they added. Every step is in the Audit Log. Node twin: services/productApproval.js.
 */
final class ProductApproval
{
    public const STATUSES = ['approved' => 'Approved', 'pending' => 'Waiting for approval', 'rejected' => 'Not approved'];

    /** The SQL condition (alias p) for a product that may be used — quoted, ordered, offered to associates. */
    public const APPROVED_SQL = "p.approval_status = 'approved'";

    public static function settingOn(): bool
    {
        return (CompanySettingsRepository::get('brand_products_need_approval') ?? '1') === '1';
    }

    /** Whether a product this user adds must wait for approval: a brand manager, with the setting on. */
    public static function needsApproval(?array $user = null): bool
    {
        return self::settingOn() && AssociateBrands::userBrandId($user) !== null;
    }

    public static function canApprove(int $userId, ?int $roleId): bool
    {
        return PermissionService::can($userId, $roleId, 'approve_new_products');
    }

    /** Called right after a product is created: marks it waiting when the creator is a brand manager. */
    public static function afterCreate(int $productId, int $userId, ?array $user = null): void
    {
        if (!self::needsApproval($user)) {
            return;
        }
        Database::connection()->prepare("UPDATE catalog_products SET approval_status = 'pending', submitted_by = :u, approval_reason = NULL,
                approved_by = NULL, approved_at = NULL WHERE id = :id")->execute(['u' => $userId, 'id' => $productId]);
        AuditLogRepository::log($userId, 'PRODUCT_SUBMITTED_FOR_APPROVAL', 'catalog_products', $productId, 'approval_status', 'approved', 'pending');
    }

    /** "" when the user may decide on the product now, else why not. */
    public static function refusal(array $product, int $userId, ?int $roleId): string
    {
        if (($product['approval_status'] ?? 'approved') !== 'pending') {
            return 'This product is not waiting for approval.';
        }
        if (!self::canApprove($userId, $roleId)) {
            return 'You do not have the "Approve new products" permission.';
        }
        if ((int) ($product['submitted_by'] ?? 0) === $userId || (int) ($product['created_by'] ?? 0) === $userId) {
            return 'Someone else approves a product you added.';
        }
        return '';
    }

    /** Approve (reason optional) or reject (reason required, 10–300 characters). Returns [ok, message]. */
    public static function decide(array $product, bool $approve, string $reason, int $userId, ?int $roleId): array
    {
        $why = self::refusal($product, $userId, $roleId);
        if ($why !== '') {
            return [false, $why];
        }
        $reason = trim($reason);
        if (!$approve && (mb_strlen($reason) < 10 || mb_strlen($reason) > 300)) {
            return [false, 'Give the reason the product is not approved (10 to 300 characters) — the brand manager sees it.'];
        }
        if (mb_strlen($reason) > 300) {
            return [false, 'Keep the note to 300 characters.'];
        }
        $status = $approve ? 'approved' : 'rejected';
        $stmt = Database::connection()->prepare("UPDATE catalog_products SET approval_status = :s, approval_reason = :r, approved_by = :u, approved_at = NOW()
                                                  WHERE id = :id AND approval_status = 'pending'");
        $stmt->execute(['s' => $status, 'r' => $reason !== '' ? $reason : null, 'u' => $userId, 'id' => (int) $product['id']]);
        if ($stmt->rowCount() !== 1) {
            return [false, 'Someone else decided on this product a moment ago.'];
        }
        AuditLogRepository::log($userId, $approve ? 'PRODUCT_APPROVED' : 'PRODUCT_REJECTED', 'catalog_products', (int) $product['id'], 'approval_status', 'pending', $status, $reason !== '' ? $reason : null);
        ApprovalsInbox::resetCache();
        return [true, $approve ? "\"{$product['name']}\" is approved — it can be quoted and associates of its brand see it."
            : "\"{$product['name']}\" is not approved. The brand manager sees your reason on the product."];
    }

    /** A rejected product sent again after changes (by its brand manager). */
    public static function resubmit(array $product, int $userId): bool
    {
        if (($product['approval_status'] ?? '') !== 'rejected') {
            return false;
        }
        Database::connection()->prepare("UPDATE catalog_products SET approval_status = 'pending', submitted_by = :u, approved_by = NULL, approved_at = NULL WHERE id = :id")
            ->execute(['u' => $userId, 'id' => (int) $product['id']]);
        AuditLogRepository::log($userId, 'PRODUCT_SUBMITTED_FOR_APPROVAL', 'catalog_products', (int) $product['id'], 'approval_status', 'rejected', 'pending');
        ApprovalsInbox::resetCache();
        return true;
    }

    /** @return list<array<string,mixed>> products waiting, oldest first, with category, brand and who added them */
    public static function pending(): array
    {
        $rows = Database::connection()->query("SELECT p.id, p.name, p.category_id, p.submitted_by, p.created_by, p.created_at, p.updated_at, u.name AS submitted_by_name
              FROM catalog_products p LEFT JOIN users u ON u.id = p.submitted_by WHERE p.approval_status = 'pending' ORDER BY p.updated_at, p.id")->fetchAll();
        foreach ($rows as &$r) {
            $c = $r['category_id'] !== null ? ProductCategoryRepository::find((int) $r['category_id']) : null;
            $r['category'] = ProductCategoryRepository::displayName($c);
            $r['brand_id'] = $c['brand_id'] ?? null;
        }
        unset($r);
        return $rows;
    }
}
