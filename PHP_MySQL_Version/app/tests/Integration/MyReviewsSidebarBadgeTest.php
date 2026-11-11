<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ReviewController;
use App\Repositories\DocumentReviewRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-26): "My Reviews" shows how many documents are
 * waiting on the signed-in user's review, the same live badge the
 * Quotation/PI Intake Review links carry. Owner decision D-320 (point 26):
 * My Reviews is now "Documents" in the Approvals menu bar, with the same count.
 */
final class MyReviewsSidebarBadgeTest extends DbTestCase
{
    private function myReviewsLink(int $userId): string
    {
        $_SESSION['_auth_user_id'] = $userId;
        $_SERVER['REQUEST_URI'] = '/reviews';
        ob_start();
        try {
            (new ReviewController())->queue([]);
        } finally {
            $output = (string) ob_get_clean();
            unset($_SERVER['REQUEST_URI']);
        }
        $start = strpos($output, '<a href="/reviews"');
        self::assertNotFalse($start);
        return substr($output, $start, (int) strpos($output, '</a>', $start) - $start);
    }

    public function testNoBadgeWhenNothingIsWaitingOnTheUser(): void
    {
        self::assertStringNotContainsString('nav-badge', $this->myReviewsLink($this->createTestUser('Admin')));
    }

    public function testTheBadgeCountsOnlyThisUsersPendingReviews(): void
    {
        $reviewerId = $this->createTestUser('Admin');
        $otherId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        DocumentReviewRepository::assign($this->createTestDocument($orderId, 'QT', 'in_review'), $reviewerId);
        DocumentReviewRepository::assign($this->createTestDocument($orderId, 'PI', 'in_review'), $reviewerId);
        $approvedId = DocumentReviewRepository::assign($this->createTestDocument($orderId, 'OC', 'in_review'), $reviewerId);
        DocumentReviewRepository::approve($approvedId, null);
        DocumentReviewRepository::assign($this->createTestDocument($orderId, 'CI', 'in_review'), $otherId);

        self::assertSame(2, DocumentReviewRepository::pendingCountForReviewer($reviewerId));
        self::assertStringContainsString('<span class="nav-badge">2</span>', $this->myReviewsLink($reviewerId));
    }
}
