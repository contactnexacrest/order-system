<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AmendmentController;
use App\Services\AuthService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (AMD-06 — external QA report cross-verification): mdApprove(),
 * reject(), and generateDocument() all built their redirect from
 * `$amendment['order_id'] ?? ''` even when the amendment lookup itself
 * came back null (an unknown or already-deleted id) — producing a
 * malformed `/orders//amendments` redirect instead of a clean 404, and
 * masking what should have been an obvious "this id doesn't exist" signal
 * behind a broken URL.
 */
final class AmendmentNotFoundTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testMdApproveOnAnUnknownAmendmentIdReturns404NotAMalformedRedirect(): void
    {
        $userId = $this->createTestUser('Managing Director');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new AmendmentController())->mdApprove(['amendmentId' => 999999]);
        $output = ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('not found', strtolower($output));
    }

    public function testRejectOnAnUnknownAmendmentIdReturns404NotAMalformedRedirect(): void
    {
        $userId = $this->createTestUser('Managing Director');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new AmendmentController())->reject(['amendmentId' => 999999]);
        $output = ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('not found', strtolower($output));
    }

    public function testGenerateDocumentOnAnUnknownAmendmentIdReturns404NotAMalformedRedirect(): void
    {
        $userId = $this->createTestUser('Managing Director');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new AmendmentController())->generateDocument(['amendmentId' => 999999]);
        $output = ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('not found', strtolower($output));
    }
}
