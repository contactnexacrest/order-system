<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Repositories\AuditLogRepository;

/** Spec Section 14/17 — Audit Log viewer. Read-only; no delete anywhere. */
final class AuditLogController
{
    private const PAGE_SIZE = 100;

    public function index(array $params): void
    {
        $entityType = trim((string) ($_GET['entity_type'] ?? '')) ?: null;
        $entityId = ($_GET['entity_id'] ?? '') !== '' ? (int) $_GET['entity_id'] : null;
        $userId = ($_GET['user_id'] ?? '') !== '' ? (int) $_GET['user_id'] : null;
        $actionType = trim((string) ($_GET['action_type'] ?? '')) ?: null;
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $rows = AuditLogRepository::search(
            $entityType,
            $entityId,
            $userId,
            $actionType,
            $dateFrom,
            $dateTo,
            self::PAGE_SIZE,
            (self::PAGE_SIZE) * ($page - 1)
        );

        View::render('audit_log/index', [
            'rows'        => $rows,
            'page'        => $page,
            'pageSize'    => self::PAGE_SIZE,
            'actionTypes' => AuditLogRepository::distinctActionTypes(),
            'filters'     => [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'user_id'     => $userId,
                'action_type' => $actionType,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
            ],
        ], 'layout/base');
    }
}
