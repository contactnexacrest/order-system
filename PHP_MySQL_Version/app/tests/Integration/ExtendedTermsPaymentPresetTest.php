<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "30/70 payment preset": only two seeded presets
 * existed (both 40% advance / 60% balance), and the full Payment
 * Presets CRUD screen (/payment-presets) already lets an admin create
 * a custom one — but nobody had actually added the 30/70 split staff
 * wanted as a standard, reusable option. Adds a third seeded preset,
 * "Extended Terms — 30/70", and proves the SOP's own claim
 * (docs/SOP/15-admin-settings.md: "Creating a new preset ... needs no
 * further code change anywhere") actually holds for it — an order
 * created on this preset snapshots its own advance_pct/balance_pct from
 * it (90-item backlog "standalone order" — OrderRepository::insertRow()),
 * never a hardcoded 40/60 split.
 */
final class ExtendedTermsPaymentPresetTest extends DbTestCase
{
    public function testThePresetIsSeededWithTheRightSplit(): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM payment_presets WHERE preset_name = :name');
        $stmt->execute(['name' => 'Extended Terms — 30/70']);
        $preset = $stmt->fetch();

        self::assertNotFalse($preset, 'the Extended Terms — 30/70 preset must be seeded');
        self::assertSame('30.00', $preset['advance_pct']);
        self::assertSame('70.00', $preset['balance_pct']);
        self::assertSame('B_AGAINST_BL', $preset['balance_trigger_option']);
        self::assertSame(1, (int) $preset['is_active']);
        self::assertSame(0, (int) $preset['is_protected'], 'a freshly-added preset has nothing historical to guard, unlike the two foundational ones');
    }

    /**
     * 90-item backlog "standalone order": an order's advance_pct/balance_pct
     * are now snapshotted from whichever preset it's created with (never
     * a hardcoded 40/60), not re-read from a live join afterward — so
     * this proves the snapshot taken AT CREATION reflects the preset
     * actually picked, rather than switching the preset post-creation
     * (which, correctly, no longer has any effect — see
     * OrderRepository::insertRow()'s docblock).
     */
    public function testAnOrderOnThisPresetComputesItsOwnSplitWithNoHardcodedPercentage(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Extended Terms — 30/70');

        $order = OrderRepository::find($orderId);

        self::assertSame(30.0, (float) $order['advance_pct'], 'must reflect this preset\'s own 30%, never a hardcoded 40%');
        self::assertSame(70.0, (float) $order['balance_pct']);
    }
}
