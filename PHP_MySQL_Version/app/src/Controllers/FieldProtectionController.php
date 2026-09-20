<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\FieldProtectionRepository;
use App\Services\AuthService;

/**
 * Peer-approved lock/unlock governance for the is_protected flag — see
 * Section L, schema.sql, and FieldProtectionRepository's docblock. This
 * controller only ever creates/resolves *requests*; the flag itself is
 * flipped exclusively inside FieldProtectionRepository::approve().
 */
final class FieldProtectionController
{
    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        View::render('field_protection/index', [
            'protectable' => FieldProtectionRepository::listProtectable(),
            'pending' => FieldProtectionRepository::pendingRequests(),
            'resolved' => FieldProtectionRepository::recentResolved(),
            'currentUserId' => (int) $user['id'],
            // A Super Admin can approve their own request (see
            // FieldProtectionRepository::approve()'s bypass) — the UI must
            // offer the same Approve/Reject buttons in that case, not show
            // the "awaiting a different privileged user" placeholder that
            // would otherwise be actively misleading for them.
            'isSuperAdmin' => \App\Services\SuperAdminService::isEffective((int) $user['id']),
        ], 'layout/base');
    }

    public function createRequest(array $params): void
    {
        $user = AuthService::currentUser();
        $tableName = (string) ($_POST['table_name'] ?? '');
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $recordLabel = trim((string) ($_POST['record_label'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (!isset(FieldProtectionRepository::protectableTables()[$tableName]) || !$recordId) {
            Flash::set('error', 'Unrecognized field — nothing was submitted.');
            header('Location: /admin/field-protection');
            return;
        }
        if (!in_array($action, ['lock', 'unlock'], true)) {
            Flash::set('error', 'Invalid action.');
            header('Location: /admin/field-protection');
            return;
        }
        if ($reason === '') {
            Flash::set('error', 'A reason is required to request a protection change — nothing was submitted.');
            header('Location: /admin/field-protection');
            return;
        }

        $existing = FieldProtectionRepository::findPendingForRecord($tableName, $recordId);
        if ($existing) {
            Flash::set('error', "There is already a pending {$existing['requested_action']} request for this field — resolve that one first.");
            header('Location: /admin/field-protection');
            return;
        }

        $requestId = FieldProtectionRepository::createRequest($tableName, $recordId, $recordLabel, $action, $reason, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PROTECTION_FLAG_REQUESTED', $tableName, $recordId, 'is_protected', null, $action, $reason);

        Flash::set('success', "Request submitted (#{$requestId}) — a different privileged user must approve it before anything changes.");
        header('Location: /admin/field-protection');
    }

    public function approve(array $params): void
    {
        $user = AuthService::currentUser();
        $requestId = (int) ($params['requestId'] ?? 0);
        $resolvedReason = trim((string) ($_POST['resolved_reason'] ?? ''));

        try {
            $result = FieldProtectionRepository::approve($requestId, (int) $user['id'], $resolvedReason);
            AuditLogRepository::log(
                (int) $user['id'],
                'PROTECTION_FLAG_APPROVED',
                $result['table_name'],
                (int) $result['record_id'],
                'is_protected',
                $result['requested_action'] === 'lock' ? '0' : '1',
                $result['requested_action'] === 'lock' ? '1' : '0',
                "Approved request #{$requestId} (originally requested by user #{$result['requested_by']}): {$result['reason']}"
            );
            $newState = $result['requested_action'] === 'lock' ? 'protected' : 'unprotected';
            Flash::set('success', "Request #{$requestId} approved — the field is now {$newState}.");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage() ?: 'Could not approve this request.');
        }
        header('Location: /admin/field-protection');
    }

    public function reject(array $params): void
    {
        $user = AuthService::currentUser();
        $requestId = (int) ($params['requestId'] ?? 0);
        $resolvedReason = trim((string) ($_POST['resolved_reason'] ?? ''));

        try {
            $result = FieldProtectionRepository::reject($requestId, (int) $user['id'], $resolvedReason);
            AuditLogRepository::log(
                (int) $user['id'],
                'PROTECTION_FLAG_REJECTED',
                $result['table_name'],
                (int) $result['record_id'],
                'is_protected',
                null,
                null,
                "Rejected request #{$requestId}: " . ($resolvedReason !== '' ? $resolvedReason : '(no reason given)')
            );
            Flash::set('success', "Request #{$requestId} rejected.");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage() ?: 'Could not reject this request.');
        }
        header('Location: /admin/field-protection');
    }
}
