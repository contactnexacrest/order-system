<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;

/**
 * Gap list answers (owner, 1 Nov 2026), G-1 and G-3: what must be done
 * before the Bill of Lading is recorded (Step 10f).
 *  - G-1: on a "before shipment" order (balance trigger A_BEFORE_SHIPMENT,
 *    not a Letter of Credit) the buyer's balance is recorded and cleared
 *    before shipment — Step 11c/11d open from the freight stage on, without
 *    the Commercial Invoice (the buyer pays against the PI).
 *  - G-3: when the order has a pre-shipment inspection (its Compliance
 *    Checklist line is not "not applicable"), the inspection must be
 *    recorded as Passed.
 * A manager (override_shipment_gates) can release the step with a written
 * reason; every release is kept and shown on the order.
 */
final class ShipmentGateService
{
    public const INSPECTION_TASK = 'Pre-Shipment Inspection';
    /** D-262: the fixed code of the inspection task (renaming the task never switches the gate off). */
    public const INSPECTION_TASK_KEY = 'pre_shipment_inspection';

    /** @param array<string,mixed> $task a checklist row (OrderComplianceTaskRepository::forOrder) */
    public static function isInspectionTask(array $task): bool
    {
        return ($task['task_key'] ?? null) === self::INSPECTION_TASK_KEY
            || (($task['task_key'] ?? null) === null && ($task['task_type_name'] ?? null) === self::INSPECTION_TASK);
    }

    public const MESSAGES = [
        'balance' => 'This order\'s balance is due before shipment: record and clear the balance (Steps 11c and 11d) before recording the Bill of Lading.',
        'inspection' => 'The pre-shipment inspection has not passed: record a Passed inspection (Step 8a) before recording the Bill of Lading, or mark the inspection not applicable on the compliance checklist.',
    ];

    /** A "before shipment" order: its balance is paid before the goods leave (not LC, which the bank pays against the documents). */
    public static function isPreShipmentBalance(array $order): bool
    {
        return ($order['balance_trigger_option'] ?? '') === 'A_BEFORE_SHIPMENT' && strtoupper((string) ($order['payment_method'] ?? '')) !== 'LC';
    }

    /** G-1: the balance can be recorded and cleared now, before Stage 8 — from the freight stage on. */
    public static function balanceOpenEarly(int $orderId): bool
    {
        $order = OrderRepository::find($orderId);
        if ($order === null || !self::isPreShipmentBalance($order)) {
            return false;
        }
        return StageGateService::isReachedOrPassed($orderId, 6) || StageGateService::isReachedOrPassed($orderId, 7);
    }

    /** Whether the order has a pre-shipment inspection (its checklist line is not marked not applicable). */
    public static function inspectionApplies(int $orderId): bool
    {
        foreach (OrderComplianceTaskRepository::forOrder($orderId) as $task) {
            if (self::isInspectionTask($task) && $task['status'] !== 'skipped') {
                return true;
            }
        }
        return false;
    }

    public static function inspection(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.*, u.name AS recorded_by_name FROM order_inspections i LEFT JOIN users u ON u.id = i.recorded_by WHERE i.order_id = :o'
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * What still stops the Bill of Lading.
     * @return array<string, string> gate key ('balance' | 'inspection') => message
     */
    public static function blockers(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return [];
        }
        $out = [];
        if (self::isPreShipmentBalance($order)) {
            $payment = OrderPaymentStatusRepository::find($orderId);
            if ($payment !== null && (float) ($payment['balance_amount'] ?? 0) > 0 && $payment['balance_cleared_at'] === null) {
                $out['balance'] = self::MESSAGES['balance'];
            }
        }
        if (self::inspectionApplies($orderId) && (self::inspection($orderId)['result'] ?? null) !== 'passed') {
            $out['inspection'] = self::MESSAGES['inspection'];
        }
        return $out;
    }

    /** G-3: stores the inspection result; Passed resolves the checklist line, Failed reopens it and tells the managers. */
    public static function saveInspection(array $order, string $result, string $date, string $agency, int $fileId, ?string $note, int $userId): void
    {
        $orderId = (int) $order['id'];
        $before = self::inspection($orderId);
        Database::connection()->prepare(
            'INSERT INTO order_inspections (order_id, result, inspected_on, agency_name, report_file_id, note, recorded_by)
             VALUES (:o, :r, :d, :a, :f, :n, :u)
             ON DUPLICATE KEY UPDATE result = VALUES(result), inspected_on = VALUES(inspected_on), agency_name = VALUES(agency_name),
                report_file_id = VALUES(report_file_id), note = VALUES(note), recorded_by = VALUES(recorded_by)'
        )->execute(['o' => $orderId, 'r' => $result, 'd' => $date, 'a' => $agency, 'f' => $fileId, 'n' => $note, 'u' => $userId]);
        \App\Repositories\AuditLogRepository::log($userId, 'INSPECTION_RESULT_RECORDED', 'orders', $orderId, 'inspection_result', $before['result'] ?? null, $result, $note);
        foreach (OrderComplianceTaskRepository::forOrder($orderId) as $task) {
            if (self::isInspectionTask($task)) {
                OrderComplianceTaskRepository::setStatus($orderId, (int) $task['task_type_id'], $result === 'passed' ? 'approved' : 'pending_approval', null, $userId);
            }
        }
        if ($result === 'failed') {
            $message = "Pre-shipment inspection FAILED on order {$order['order_reference']} ({$agency}, " . \App\Helpers\Dates::short($date) . "): {$note} The Bill of Lading waits for a Passed inspection.";
            foreach (\App\Repositories\PermissionRepository::usersWithPermission('override_shipment_gates') as $uid) {
                \App\Repositories\NotificationRepository::create($uid, null, 'inspection_failed', $orderId, $message);
            }
        }
    }

    public static function recordOverride(int $orderId, array $gates, string $reason, int $userId): void
    {
        Database::connection()->prepare('INSERT INTO order_shipment_gate_overrides (order_id, gates, reason, user_id) VALUES (:o, :g, :r, :u)')
            ->execute(['o' => $orderId, 'g' => implode(',', $gates), 'r' => $reason, 'u' => $userId]);
    }

    /** @return list<array<string,mixed>> the releases of the Bill of Lading step, oldest first */
    public static function overrides(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.*, u.name AS user_name FROM order_shipment_gate_overrides g JOIN users u ON u.id = g.user_id WHERE g.order_id = :o ORDER BY g.id'
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    /** "the pre-shipment balance and the inspection" */
    public static function gatesLabel(string $gates): string
    {
        $labels = ['balance' => 'the pre-shipment balance', 'inspection' => 'the inspection'];
        return implode(' and ', array_map(static fn (string $g): string => $labels[$g] ?? $g, explode(',', $gates)));
    }
}
