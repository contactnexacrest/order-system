<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\OrderStageRepository;

/**
 * The Stage 1-9 pipeline lives in stages_master/order_stages (see schema).
 * Real-world gates, per StageGate.docx:
 *   Stage 1 (Quotation)             -> passed automatically the moment a QT is generated
 *   Stage 2 (Buyer PO)              -> buyer's signed PO received (flag + ref number)
 *   Stage 3 (PI / Production)       -> advance payment marked cleared
 *   Stage 4 (Order Confirmation)    -> buyer acknowledges the OC
 *   Stage 5 (Supplier PO)           -> supplier signs, stamps and returns the Supplier PO
 *   Stage 6 (Freight Payment)       -> buyer pays freight & insurance — CFR/CIF only;
 *                                       auto-skipped entirely for FOB orders (see
 *                                       maybeAutoSkipFreightStage(), called right after
 *                                       Stage 5 passes)
 *   Stage 7 (Packing & BL Instr.)   -> BL issued (vessel departs)
 *   Stage 8 (Commercial Invoice)    -> 60% balance payment marked cleared
 *   Stage 9 (Despatch & Closure)    -> COO received + BL originals endorsed + couriered
 *                                       to buyer -> order.status = 'complete' (see
 *                                       OrderController::closeOrder())
 */
final class StageGateService
{
    public static function passAndUnlockNext(int $orderId, int $stageNumber, ?int $userId): void
    {
        $current = OrderStageRepository::findByOrderAndStageNumber($orderId, $stageNumber);
        if (!$current || $current['status'] === 'gate_passed') {
            return; // already passed, or stage doesn't exist — idempotent no-op
        }
        OrderStageRepository::passGate((int) $current['id'], $userId);

        $next = OrderStageRepository::findByOrderAndStageNumber($orderId, $stageNumber + 1);
        if ($next && $next['status'] === 'locked') {
            OrderStageRepository::unlock((int) $next['id']);
            OrderRepository::setCurrentStage($orderId, (int) $next['stage_id']);
        }
    }

    /**
     * Call immediately after Stage 5 (Supplier PO) passes. FOB orders skip
     * Stage 6 (Freight Payment) entirely — per StageGate.docx: "CFR/CIF
     * only — skip entirely if FOB" — since under FOB the buyer arranges
     * and pays freight directly with their own forwarder; NexaCrest never
     * issues a Freight Debit Note and there is nothing to gate on.
     */
    public static function maybeAutoSkipFreightStage(int $orderId, int $userId): void
    {
        $order = OrderRepository::find($orderId);
        if (!$order || strtoupper((string) $order['incoterm_code']) !== 'FOB') {
            return;
        }
        $freightStage = OrderStageRepository::findByOrderAndStageNumber($orderId, 6);
        if (!$freightStage || $freightStage['status'] !== 'in_progress') {
            return; // already skipped/passed, or not yet unlocked — idempotent no-op
        }
        OrderStageRepository::skip((int) $freightStage['id'], 'FOB — freight stage auto-skipped, buyer arranges own freight');

        $next = OrderStageRepository::findByOrderAndStageNumber($orderId, 7);
        if ($next && $next['status'] === 'locked') {
            OrderStageRepository::unlock((int) $next['id']);
            OrderRepository::setCurrentStage($orderId, (int) $next['stage_id']);
        }
    }

    /** Current stage's status/number for an order, for UI gating decisions. */
    public static function currentStage(int $orderId): ?array
    {
        $stages = OrderStageRepository::forOrder($orderId);
        foreach ($stages as $stage) {
            if ($stage['status'] !== 'gate_passed' && $stage['status'] !== 'skipped') {
                return $stage;
            }
        }
        return $stages ? end($stages) : null;
    }
}
