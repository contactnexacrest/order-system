<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csv;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderRepository;

/** Spec Section 14/17 — Audit Log viewer. Read-only; no delete anywhere. */
final class AuditLogController
{
    private const PAGE_SIZE = 100;
    private const CSV_HEADERS = ['When', 'User', 'Action', 'Entity', 'Field', 'Old', 'New', 'Reason'];

    /**
     * Pure row-mapping, extracted so it can be unit-tested directly —
     * Csv::stream() calls exit() on its success path, same reasoning as
     * ReportController's own *CsvRows() helpers.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function orderAuditLogCsvRows(array $rows): array
    {
        return array_map(static fn(array $r): array => [
            'When'   => $r['created_at'],
            'User'   => $r['user_name'] ?? 'system',
            'Action' => $r['action_type'],
            'Entity' => ($r['entity_type'] ?? '') . ($r['entity_id'] ? ' #' . $r['entity_id'] : ''),
            'Field'  => $r['field_name'] ?? '',
            'Old'    => $r['old_value'] ?? '',
            'New'    => $r['new_value'] ?? '',
            'Reason' => $r['reason'] ?? '',
        ], $rows);
    }

    /**
     * Everything logged against this order — its own row, plus every
     * document/dispute/amendment/email_log row that belongs to it. See
     * AuditLogRepository::forOrder()'s docblock for why this needed its
     * own query rather than reusing search()'s single entity_type filter.
     */
    public function forOrder(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $rows = AuditLogRepository::forOrder($orderId);

        // External QA report M-03: "One-click export of an order's audit
        // log (privileged only)." Reuses the page's own existing
        // view_audit_log permission gate (this whole action is already
        // restricted to it) rather than adding a separate export route —
        // same ?format=csv-on-the-same-GET convention ReportController
        // already uses elsewhere in this codebase.
        if (($_GET['format'] ?? '') === 'csv') {
            Csv::stream(
                'audit_log_order_' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '.csv',
                self::CSV_HEADERS,
                self::orderAuditLogCsvRows($rows)
            );
        }

        View::render('audit_log/order', [
            'order' => $order,
            'rows'  => $rows,
        ], 'layout/base');
    }

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
