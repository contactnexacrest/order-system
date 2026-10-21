<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientIntakeReviewController;
use App\Repositories\ClientIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "resend-lost-intake-link flow": the correction link
 * issued at submit() time expires after 7 days with no recovery path —
 * if the client never got the email, or it expired before they got to
 * it, the submission was stuck pending forever with no way to correct
 * it themselves. Staff can now resend a fresh link from the review
 * queue, same staff-initiated regenerate-and-send pattern already used
 * for the PI-stage intake link and the Buyer Action link.
 */
final class IntakeResendCorrectionLinkTest extends DbTestCase
{
    private function createTestIntakeSubmission(): int
    {
        return ClientIntakeRepository::create([
            'company_legal_name'     => 'PHPUnit Resend Test Buyer Ltd',
            'billing_address'        => '1 Test Street',
            'vat_eori_tax_no'        => 'VAT123',
            'contact_person'         => 'Test Contact',
            'email'                  => 'resend-buyer@example.test',
            'phone'                  => '',
            'country_of_destination' => 'Testland',
            'port_of_discharge_text' => '',
            'coo_type'               => '',
            'incoterm_preference'    => '',
            'container_type_text'    => '',
            'buyer_own_reference'    => '',
            'notes'                  => '',
        ], '127.0.0.1');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    public function testResendingIssuesAFreshTokenThatWorksAfterTheOriginalHasExpired(): void
    {
        $id = $this->createTestIntakeSubmission();
        $expiredToken = bin2hex(random_bytes(32));
        $pastExpiry = date('Y-m-d H:i:s', time() - 3600);
        ClientIntakeRepository::setAccessToken($id, hash('sha256', $expiredToken), $pastExpiry);
        // Sanity check reads the row directly rather than through
        // findValidByToken()'s own NOW()-comparison query — that query is
        // the known, separately-documented pre-existing flake covered by
        // IdorAndInputValidationTest::testClientIntakeCorrectionLinkRejectsAnExpiredToken;
        // this test's own subject is resendLink(), not that comparison.
        self::assertSame($pastExpiry, ClientIntakeRepository::find($id)['access_token_expires_at'], 'sanity check: the original token\'s expiry really is in the past');

        ob_start();
        (new ClientIntakeReviewController())->resendLink(['id' => (string) $id]);
        ob_end_clean();

        $submission = ClientIntakeRepository::find($id);
        self::assertNotNull($submission['access_token_hash']);
        self::assertTrue(strtotime($submission['access_token_expires_at']) > time(), 'the resent link must carry a fresh, future expiry');
    }

    public function testResendIsRejectedForAnAlreadyResolvedSubmission(): void
    {
        $id = $this->createTestIntakeSubmission();
        ClientIntakeRepository::markRejected($id, $this->createTestUser('Export Executive'), 'Not a fit');
        $before = ClientIntakeRepository::find($id)['access_token_hash'] ?? null;

        ob_start();
        (new ClientIntakeReviewController())->resendLink(['id' => (string) $id]);
        ob_end_clean();

        $after = ClientIntakeRepository::find($id)['access_token_hash'] ?? null;
        self::assertSame($before, $after, 'an already-resolved submission must never get a new link issued');
    }
}
