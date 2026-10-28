<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-01: on a locked (fully settled) order, changing the
 * production status is a locked-order edit like any other — it needs
 * "Override locked data" and a reason, and is audit-logged as
 * ORDER_EDITED_WHILE_LOCKED.
 */
final class LockedOrderProductionStatusTest extends DbTestCase
{
    private int $orderId;

    protected function tearDown(): void
    {
        \App\Helpers\Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("UPDATE orders SET is_locked = 1, status = 'complete', production_status_text = 'Shipped' WHERE id = :id")->execute(['id' => $this->orderId]);
        Flash::pull();
    }

    private function update(int $userId, array $post): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
        $_POST = $post;
        (new OrderController())->updateProductionStatus(['id' => (string) $this->orderId]);
    }

    private function lockedEditAuditCount(): int
    {
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ORDER_EDITED_WHILE_LOCKED' AND entity_id = :id AND field_name = 'production_status'");
        $stmt->execute(['id' => $this->orderId]);
        return (int) $stmt->fetchColumn();
    }

    public function testAReasonIsRequiredEvenWithTheOverridePermission(): void
    {
        $this->update($this->createTestUser('Admin'), ['production_status_text' => 'Changed after lock']);

        self::assertSame('Shipped', OrderRepository::find($this->orderId)['production_status_text']);
        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame(0, $this->lockedEditAuditCount());
    }

    public function testWithTheOverridePermissionAndAReasonTheChangeIsSavedAndLogged(): void
    {
        $this->update($this->createTestUser('Admin'), ['production_status_text' => 'Changed after lock', 'reason' => 'Correcting a typo in the final status']);

        self::assertSame('Changed after lock', OrderRepository::find($this->orderId)['production_status_text']);
        self::assertSame(1, $this->lockedEditAuditCount());
    }

    public function testWithoutTheOverridePermissionItIsRefused(): void
    {
        $this->update($this->createTestUser('Export Executive'), ['production_status_text' => 'Changed after lock', 'reason' => 'Correcting a typo in the final status']);

        self::assertSame('Shipped', OrderRepository::find($this->orderId)['production_status_text']);
        self::assertSame(0, $this->lockedEditAuditCount());
    }
}
