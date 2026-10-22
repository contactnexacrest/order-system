<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AmendmentController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report R-02: "Raw SQL error text shown to users in the red
 * message box (not only in debug mode — the exception message is passed
 * to the flash). — Where: cancel e-mail send, create amendment, send
 * document." Reproduced here for the "create amendment" flow: an
 * impossible date (amendments.effective_from is DATE NULL) makes the
 * INSERT throw a PDOException ("SQLSTATE[22007]: Invalid datetime
 * format... Incorrect date value") — AmendmentController::create()'s own
 * `catch (\Throwable $e)` sees this BEFORE it could ever reach Router's
 * global SQLSTATE handler, so that global fix (R-01/R-03/R-04) does not
 * cover this case; only Flash::setErrorFromException() does.
 */
final class AmendmentCreateHidesRawDbErrorTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $_SERVER['HTTP_REFERER'] = '';
    }

    public function testAnImpossibleEffectiveFromDateShowsAFriendlyMessageNotRawSqlstateText(): void
    {
        $userId = $this->createTestUser('Managing Director');
        $_SESSION['_auth_user_id'] = $userId;

        $orderId = $this->createTestOrder($this->createTestClient());

        $_POST = [
            'reason'         => 'PHPUnit R-02 regression test',
            'requested_by'   => 'importer',
            'effective_from' => '2026-02-30', // 30-Feb does not exist
        ];

        ob_start();
        (new AmendmentController())->create(['id' => (string) $orderId]);
        ob_get_clean();

        $flash = \App\Helpers\Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
        self::assertStringNotContainsString('SQLSTATE', $flash[0]['message']);
        self::assertStringNotContainsString('Incorrect date value', $flash[0]['message']);
        self::assertSame('Something went wrong. Please try again.', $flash[0]['message']);
    }
}
