<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderPackingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 19: the Documents panel's own numbered
 * sequence skipped straight past "Generate Packing List" — it had no
 * step badge at all, unlike every other document-generate button in
 * that panel. "Generate Packing List" is now its own single-purpose
 * step-card (Step 10c, right after Step 10b "Save Packing & Crates",
 * both split out of what used to be one shared DB Stage 7 card) — it
 * carries the 10c badge matching that card's own heading.
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
            '#<span class="step-badge"[^>]*>10c</span>\s*<button[^>]*>Generate Packing List</button>#',
            $output,
            'Generate Packing List must carry its own step badge, same as every other document-generate button in this panel'
        );
    }
}
