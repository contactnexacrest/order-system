<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DisputeController;
use App\Helpers\Flash;
use App\Middleware\PermissionCheck;
use App\Repositories\DisputeReplyRepository;
use App\Repositories\DisputeRepository;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Point 10 — docs/schema.sql Section AO: manage_disputes and
 * respond_to_disputes replace the old single manage_orders gate on every
 * Dispute action, and a dispute now has its own reply thread
 * (dispute_replies), separate from order_comments.
 */
final class DisputeReplyTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        PermissionService::resetCache();
    }

    // ---------------------------------------------------------------
    // Seed grants: Export Executive is the "sales agent" role — gets
    // respond_to_disputes but NOT manage_disputes (privileged-only by
    // default, per the user's explicit ask).
    // ---------------------------------------------------------------

    public function testExportExecutiveCanRespondButCannotManageDisputes(): void
    {
        $userId = $this->createTestUser('Export Executive');
        self::assertTrue(PermissionService::can($userId, $this->roleIdOf($userId), 'respond_to_disputes'));
        self::assertFalse(PermissionService::can($userId, $this->roleIdOf($userId), 'manage_disputes'));
    }

    public function testAdminHasBothDisputePermissionsViaTheAllPermissionsCrossJoin(): void
    {
        $userId = $this->createTestUser('Admin');
        self::assertTrue(PermissionService::can($userId, $this->roleIdOf($userId), 'manage_disputes'));
        self::assertTrue(PermissionService::can($userId, $this->roleIdOf($userId), 'respond_to_disputes'));
    }

    public function testViewerAuditorHasNeitherDisputePermissionByDefault(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        self::assertFalse(PermissionService::can($userId, $this->roleIdOf($userId), 'manage_disputes'));
        self::assertFalse(PermissionService::can($userId, $this->roleIdOf($userId), 'respond_to_disputes'));
    }

    // ---------------------------------------------------------------
    // PermissionCheck::requiresAny — the dispute page must be reachable
    // by someone who can only respond, as well as someone who manages
    // disputes broadly.
    // ---------------------------------------------------------------

    public function testRequiresAnyPassesForAUserHoldingOnlyOneOfTheKeys(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;

        $check = PermissionCheck::requiresAny(['manage_disputes', 'respond_to_disputes']);
        ob_start();
        $result = $check([]);
        ob_end_clean();

        self::assertTrue($result);
    }

    public function testRequiresAnyBlocksAUserHoldingNeitherKey(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        $check = PermissionCheck::requiresAny(['manage_disputes', 'respond_to_disputes']);
        ob_start();
        $result = $check([]);
        ob_end_clean();

        self::assertFalse($result);
    }

    // ---------------------------------------------------------------
    // Reply thread — its own table, not order_comments.
    // ---------------------------------------------------------------

    public function testPostReplyCreatesAReplyVisibleInForDispute(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Test dispute', null, null);
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['body' => 'We are looking into this and will respond by Friday.'];

        ob_start();
        (new DisputeController())->postReply(['disputeId' => $disputeId]);
        ob_end_clean();

        $replies = DisputeReplyRepository::forDispute($disputeId);
        self::assertCount(1, $replies);
        self::assertSame('We are looking into this and will respond by Friday.', $replies[0]['body']);
        self::assertSame($userId, (int) $replies[0]['author_user_id']);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
    }

    public function testPostReplyRejectsAnEmptyBodyAndDoesNotInsertAnything(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Test dispute', null, null);
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['body' => '   '];

        ob_start();
        (new DisputeController())->postReply(['disputeId' => $disputeId]);
        ob_end_clean();

        self::assertCount(0, DisputeReplyRepository::forDispute($disputeId));
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    public function testRepliesAreOrderedOldestFirst(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Test dispute', null, null);
        $userId = $this->createTestUser('Export Executive');

        DisputeReplyRepository::create($disputeId, $userId, 'First reply');
        DisputeReplyRepository::create($disputeId, $userId, 'Second reply');

        $replies = DisputeReplyRepository::forDispute($disputeId);
        self::assertCount(2, $replies);
        self::assertSame('First reply', $replies[0]['body']);
        self::assertSame('Second reply', $replies[1]['body']);
        self::assertSame('PHPUnit Test User', $replies[0]['author_name']);
    }

    private function roleIdOf(int $userId): ?int
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare('SELECT role_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $roleId = $stmt->fetchColumn();
        return $roleId !== null ? (int) $roleId : null;
    }
}
