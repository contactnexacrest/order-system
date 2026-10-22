<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderRepository;
use App\Repositories\PortRepository;
use App\Services\AmendmentService;
use App\Repositories\AmendmentRepository;
use App\Tests\Support\DbTestCase;

/**
 * Real regression found after the original "standalone order" snapshot
 * fix: OrderController::updateDetails() ("Edit Details", Chapter 17) lets
 * staff change an in-progress order's Incoterm/Currency/Port, but
 * OrderRepository::updateDetails() only ever wrote the *_id FK columns.
 * Before the snapshot fix this was fine — OrderRepository::find() lived
 * off a live JOIN, so a changed FK was reflected immediately. Once
 * find()/every document stopped joining and started reading the order's
 * own incoterm_code/currency_code/port_of_loading_name/_city/
 * port_of_discharge_name/_city instead, this screen silently stopped
 * working for those four fields: the *_id changed, the printed/displayed
 * value didn't. This proves the fix: editing the logistics side now
 * re-resolves just those four snapshot fields, without disturbing an
 * activated Amendment's payment-term override or this order's own
 * PI-intake-corrected client details.
 */
final class OrderEditDetailsSnapshotRefreshTest extends DbTestCase
{
    private function otherCurrencyId(int $excludeId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, code FROM currencies WHERE id != :id LIMIT 1');
        $stmt->execute(['id' => $excludeId]);
        return $stmt->fetch();
    }

    private function otherIncotermId(int $excludeId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, code FROM incoterms WHERE id != :id LIMIT 1');
        $stmt->execute(['id' => $excludeId]);
        return $stmt->fetch();
    }

    public function testChangingCurrencyAndIncotermRefreshesTheirSnapshotColumnsToo(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $original = OrderRepository::find($orderId);
        $newCurrency = $this->otherCurrencyId((int) $original['currency_id']);
        $newIncoterm = $this->otherIncotermId((int) $original['incoterm_id']);

        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'incoterm_id' => $newIncoterm['id'],
            'currency_id' => $newCurrency['id'],
        ];

        ob_start();
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        ob_end_clean();
        Flash::pull(); // drain — a real request reads its own flash once; don't leak into a later test's.

        $after = OrderRepository::find($orderId);
        self::assertSame((int) $newCurrency['id'], (int) $after['currency_id']);
        self::assertSame($newCurrency['code'], $after['currency_code'], 'currency_code must be re-resolved, not left stale, when currency_id changes');
        self::assertSame((int) $newIncoterm['id'], (int) $after['incoterm_id']);
        self::assertSame($newIncoterm['code'], $after['incoterm_code'], 'incoterm_code must be re-resolved, not left stale, when incoterm_id changes');
    }

    public function testChangingThePortOfLoadingRefreshesItsNameAndCity(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer');
        $newPortId = PortRepository::create('New Loading Terminal', 'New Loading City', 'India', 'loading', 95);
        $original = OrderRepository::find($orderId);

        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'incoterm_id' => $original['incoterm_id'],
            'currency_id' => $original['currency_id'],
            'port_of_loading_id' => $newPortId,
        ];

        ob_start();
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        ob_end_clean();
        Flash::pull(); // drain — a real request reads its own flash once; don't leak into a later test's.

        $after = OrderRepository::find($orderId);
        self::assertSame($newPortId, (int) $after['port_of_loading_id']);
        self::assertSame('New Loading Terminal', $after['port_of_loading_name']);
        self::assertSame('New Loading City', $after['port_of_loading_city']);
    }

    public function testEditingLogisticsNeverDisturbsAnActivatedAmendmentsPaymentOverride(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer');

        // Activate an amendment that overrides advance_pct away from the preset's own 40.00 default.
        $amendmentId = AmendmentRepository::create(
            'AMD-EDITGUARD-TEST-' . bin2hex(random_bytes(4)),
            $orderId, 'PHPUnit edit-guard amendment', 'importer', ['note' => 'snapshot'],
            25.0, null, null, null, null, null, null
        );
        AmendmentService::approveByMd($amendmentId, 1);
        $documentId = $this->createTestDocument($orderId, 'QT');
        AmendmentRepository::attachDocument($amendmentId, $documentId);
        $fileId = $this->createTestFile($orderId);
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);
        self::assertSame(25.0, (float) OrderRepository::find($orderId)['advance_pct']);

        // Now edit only the Incoterm via "Edit Details" — must not touch the amendment override.
        $original = OrderRepository::find($orderId);
        $newIncoterm = $this->otherIncotermId((int) $original['incoterm_id']);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['incoterm_id' => $newIncoterm['id'], 'currency_id' => $original['currency_id']];

        ob_start();
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        ob_end_clean();
        Flash::pull(); // drain — a real request reads its own flash once; don't leak into a later test's.

        $after = OrderRepository::find($orderId);
        self::assertSame($newIncoterm['code'], $after['incoterm_code'], 'the Incoterm edit itself must still take effect');
        self::assertSame(25.0, (float) $after['advance_pct'], 'editing logistics details must never silently revert an activated Amendment\'s payment-term override back to the preset default');
    }
}
