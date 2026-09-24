<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderStageRepository
{
    /** Creates all 9 order_stages rows for a new order. Stage 1 starts unlocked/in_progress, the rest locked. */
    public static function initializeForOrder(int $orderId): void
    {
        $pdo = Database::connection();
        $stages = $pdo->query('SELECT id, stage_number FROM stages_master ORDER BY sequence')->fetchAll();

        $stmt = $pdo->prepare(
            'INSERT INTO order_stages (order_id, stage_id, status, unlocked_at)
             VALUES (:order_id, :stage_id, :status, :unlocked_at)'
        );
        foreach ($stages as $stage) {
            $isFirst = (int) $stage['stage_number'] === 1;
            $stmt->execute([
                'order_id'    => $orderId,
                'stage_id'    => $stage['id'],
                'status'      => $isFirst ? 'in_progress' : 'locked',
                'unlocked_at' => $isFirst ? date('Y-m-d H:i:s') : null,
            ]);
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
