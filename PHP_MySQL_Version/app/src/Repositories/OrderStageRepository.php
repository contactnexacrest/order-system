<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderStageRepository
{
    /**
     * Creates all 9 order_stages rows for a new order. Stage 1 starts
     * unlocked/in_progress, the rest locked.
     *
     * External QA report L-14: orders.current_stage_id — a separate
     * column from these order_stages rows, read by the per-order/
     * per-stage audit file_store folder layout — stayed NULL until the
     * first gate actually passed (StageGateService::passAndUnlockNext()
     * is the only other writer), so every brand-new order's own Stage 1
     * documents filed into a generic fallback folder instead of its real
     * Stage 1 folder. This is the one chokepoint all 3 "new order" call
     * sites (OrderController::store(), OrderDuplicationService,
     * SampleDataService) already go through, so setting it here closes
     * the gap for all of them at once.
     */
    public static function initializeForOrder(int $orderId): void
    {
        $pdo = Database::connection();
        $stages = $pdo->query('SELECT id, stage_number FROM stages_master ORDER BY sequence')->fetchAll();

        $stmt = $pdo->prepare(
            'INSERT INTO order_stages (order_id, stage_id, status, unlocked_at)
             VALUES (:order_id, :stage_id, :status, :unlocked_at)'
        );
        $firstStageId = null;
        foreach ($stages as $stage) {
            $isFirst = (int) $stage['stage_number'] === 1;
            if ($isFirst) {
                $firstStageId = (int) $stage['id'];
            }
            $stmt->execute([
                'order_id'    => $orderId,
                'stage_id'    => $stage['id'],
                'status'      => $isFirst ? 'in_progress' : 'locked',
                'unlocked_at' => $isFirst ? date('Y-m-d H:i:s') : null,
            ]);
        }
        if ($firstStageId !== null) {
            OrderRepository::setCurrentStage($orderId, $firstStageId);
        }
    }

    /** @return array<int, array<string,mixed>> stage rows joined with stages_master, in sequence order */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT os.*, sm.stage_number, sm.stage_slug, sm.stage_name, sm.sequence
             FROM order_stages os
             JOIN stages_master sm ON sm.id = os.stage_id
             WHERE os.order_id = :order_id
             ORDER BY sm.sequence'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function findByOrderAndStageNumber(int $orderId, int $stageNumber): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT os.*, sm.stage_number, sm.stage_slug, sm.stage_name
             FROM order_stages os
             JOIN stages_master sm ON sm.id = os.stage_id
             WHERE os.order_id = :order_id AND sm.stage_number = :stage_number'
        );
        $stmt->execute(['order_id' => $orderId, 'stage_number' => $stageNumber]);
        return $stmt->fetch() ?: null;
    }

    public static function passGate(int $orderStageId, ?int $userId): void
    {
        Database::connection()->prepare(
            "UPDATE order_stages SET status = 'gate_passed', gate_passed_at = NOW(), gate_passed_by = :user_id
             WHERE id = :id"
        )->execute(['user_id' => $userId, 'id' => $orderStageId]);
    }

    public static function unlock(int $orderStageId): void
    {
        Database::connection()->prepare(
            "UPDATE order_stages SET status = 'in_progress', unlocked_at = NOW() WHERE id = :id"
        )->execute(['id' => $orderStageId]);
    }

    /** FOB orders skip Stage 6 (Freight Payment) entirely — see StageGateService::maybeAutoSkipFreightStage(). */
    public static function skip(int $orderStageId, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE order_stages SET status = 'skipped', unlocked_at = NOW(), skip_reason = :reason WHERE id = :id"
        )->execute(['reason' => $reason, 'id' => $orderStageId]);
    }
}
