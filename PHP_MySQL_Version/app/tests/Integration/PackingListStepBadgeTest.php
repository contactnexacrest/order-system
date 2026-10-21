<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderPackingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 19: the Documents panel's own numbered
 * sequence (1a, 2a, 3a, 4a, 5b, 6b, ?, 8b) skipped straight from 6b to
 * 8b — "Generate Packing List" had no step badge at all, unlike every
 * other document-generate button in that panel. Stage 7's own a-d
 * letters are already fully claimed by real Stage 7 actions (Save
 * Packing & Crates/Save Shipping Details/Generate BL Instruction
 * Sheet/Confirm BL Issued), so Packing List gets a new, non-colliding
 * 7e rather than reusing or renumbering any of those.
 */
final class PackingListStepBadgeTest extends DbTestCase
{
    public function testGeneratePackingListButtonHasAStepBadge(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 6; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        // The Documents panel's Packing List button is gated on Stage 7
        // being unlocked AND packing data already saved — same guard the
        // view itself uses ($stage7 && $stage7['status'] !== 'locked' && $packing).
        OrderPackingRepository::upsert($orderId, [
            'actual_quantity_packed' => '50',
            'crate_count' => '10',
            'total_net_weight_kg' => '2600',
            'total_gross_weight_kg' => '2700',
            'total_cbm' => '8.2',
            'packing_date' => null,
            'shortfall_pct' => null,
        ]);

        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = (string) ob_get_clean();

        self::assertMatchesRegularExpression(
            '#<span class="step-badge"[^>]*>7e</span>\s*<button[^>]*>Generate Packing List</button>#',
            $output,
            'Generate Packing List must carry its own step badge, same as every other document-generate button in this panel'
        );
    }
}
