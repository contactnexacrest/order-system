<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\EmailLogRepository;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 16: the Email Approvals sidebar link
 * (layout/base.php) gave no indication anything was waiting, unlike
 * Quotation Intake Review / PI Intake Review / Notifications, which all
 * carry a live-COUNT badge. awaitingDispatchCount() backs that badge,
 * counting the exact same rows the Email Approvals page itself lists
 * (pending_approval + approved-but-not-yet-dispatched) so the badge
 * number always matches what staff see when they click through.
 */
final class EmailApprovalsPendingCountTest extends DbTestCase
{
    public function testAwaitingDispatchCountReflectsPendingAndApprovedButNotYetDispatchedRows(): void
    {
        $before = EmailLogRepository::awaitingDispatchCount();
        $userId = $this->createTestUser('Admin');

        $pendingId = EmailLogRepository::create(null, null, null, 'buyer@example.test', 'Subject', 'Body', null, $userId);
        self::assertSame($before + 1, EmailLogRepository::awaitingDispatchCount(), 'a freshly queued send must appear in the badge count immediately');

        $toApproveId = EmailLogRepository::create(null, null, null, 'buyer2@example.test', 'Subject', 'Body', null, $userId);
        self::assertSame($before + 2, EmailLogRepository::awaitingDispatchCount());

        EmailLogRepository::approve($toApproveId, $userId);
        self::assertSame($before + 2, EmailLogRepository::awaitingDispatchCount(), 'approved-but-not-yet-dispatched must still count — it is still in the deferred window a Cancel can stop');

        EmailLogRepository::reject($pendingId, $userId, 'Wrong recipient');
        self::assertSame($before + 1, EmailLogRepository::awaitingDispatchCount(), 'rejecting a send must decrement the count immediately');

        EmailLogRepository::cancel($toApproveId, $userId, 'No longer needed');
        self::assertSame($before, EmailLogRepository::awaitingDispatchCount(), 'cancelling the last approved-but-undispatched send must bring the count back to its baseline');
    }
}
