<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AnnexureController;
use App\Controllers\EmailDispatchController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #73/#74: once an order's Annexure A document has been
 * approved/sent, nothing told staff that editing the Product Entries or
 * Additional Terms below wouldn't change what the buyer already received,
 * and resending an already-sent Annexure went through the exact same flow
 * as a first-time send. Investigation found no documented "staff-trust"
 * precedent covering this screen (unlike Stage 9's Close Order, which does
 * have one) — this is a real, previously-unhandled gap, not an
 * intentional behavior. Fixed as a warning + confirm(), never a hard
 * block, matching the house style used everywhere else.
 */
final class AnnexureLockWarningTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $_GET = [];
    }

    private function renderAnnexureIndex(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new AnnexureController())->index(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testNoWarningWhenNoAnnexaDocumentExistsYet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->renderAnnexureIndex($orderId);

        self::assertStringNotContainsString('already', $output);
        self::assertStringNotContainsString('onsubmit', $output);
    }

    public function testNoWarningWhenAnnexaDocumentIsStillDraft(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'ANNEXA', 'draft');

        $output = $this->renderAnnexureIndex($orderId);

        self::assertStringNotContainsString('already been approved', $output);
        self::assertStringNotContainsString('already been sent', $output);
    }

    public function testBannerAndConfirmAppearWhenAnnexaIsApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'ANNEXA', 'approved');

        $output = $this->renderAnnexureIndex($orderId);

        self::assertStringContainsString('already been approved', $output);
        self::assertStringContainsString('onsubmit="return confirm(', $output);
        self::assertStringContainsString('This Annexure was already approved', $output);
    }

    public function testBannerAndConfirmAppearWhenAnnexaIsSent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'ANNEXA', 'sent');

        $output = $this->renderAnnexureIndex($orderId);

        self::assertStringContainsString('already been sent', $output);
        self::assertStringContainsString('This Annexure was already sent to the buyer', $output);
    }

    public function testExistingDeleteConfirmStillWorksWhenNotLocked(): void
    {
        // Regression guard: the pre-existing "Remove Entry"/"Remove Image"
        // confirm() text must survive untouched when the Annexure isn't
        // locked — only the wording changes, not whether a confirm exists.
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Repositories\OrderAnnexureRepository::createProduct($orderId, [
            'name' => 'Sample Vase',
            'description' => '',
            'dimensions' => '',
            'finish' => '',
            'components' => '',
            'technical_notes' => '',
        ]);

        $output = $this->renderAnnexureIndex($orderId);

        self::assertStringContainsString("confirm(&quot;Remove this product entry and its images?&quot;)", $output);
    }

    // ---------------------------------------------------------------
    // Email compose — resend warning
    // ---------------------------------------------------------------

    private function renderCompose(int $orderId, int $documentId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new EmailDispatchController())->compose(['id' => (string) $orderId, 'documentId' => (string) $documentId]);
        return ob_get_clean();
    }

    public function testNoResendWarningForFirstTimeApprovedAnnexureSend(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient('buyer@example.test'));
        $documentId = $this->createTestDocument($orderId, 'ANNEXA', 'approved');

        $output = $this->renderCompose($orderId, $documentId);

        self::assertStringNotContainsString('already sent to the buyer once', $output);
    }

    public function testResendWarningAppearsWhenAnnexaWasAlreadySent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient('buyer@example.test'));
        $documentId = $this->createTestDocument($orderId, 'ANNEXA', 'sent');

        $output = $this->renderCompose($orderId, $documentId);

        self::assertStringContainsString('already sent to the buyer once', $output);
        self::assertStringContainsString("onsubmit=\"return confirm('This Annexure was already sent to the buyer once. Resend it anyway?');\"", $output);
    }

    public function testResendWarningDoesNotAppearForOtherDocumentTypesWhenSent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient('buyer@example.test'));
        $documentId = $this->createTestDocument($orderId, 'QT', 'sent');

        $output = $this->renderCompose($orderId, $documentId);

        self::assertStringNotContainsString('already sent to the buyer once', $output);
    }
}
