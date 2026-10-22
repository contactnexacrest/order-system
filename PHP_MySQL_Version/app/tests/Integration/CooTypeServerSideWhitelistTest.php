<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Repositories\LookupRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report R-13: "COO type is not validated on the server: any
 * text (e.g. 'TBC') is stored although the dropdown offers only GSP Form
 * A / Non-Preferential / To Be Confirmed; such clients then lose the
 * value when duplicated." LookupRepository::sanitizeOption() now rejects
 * anything that isn't an active dropdown_options('coo_type') value (or
 * the literal "To Be Confirmed" sentinel), falling back exactly the way
 * an empty submission already did.
 */
final class CooTypeServerSideWhitelistTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testSanitizeOptionAcceptsAnActiveDropdownValue(): void
    {
        self::assertSame('GSP Form A (Preferential)', LookupRepository::sanitizeOption('coo_type', 'GSP Form A (Preferential)'));
        self::assertSame('Non-Preferential', LookupRepository::sanitizeOption('coo_type', 'Non-Preferential'));
    }

    public function testSanitizeOptionAcceptsTheToBeConfirmedSentinelAndBlank(): void
    {
        self::assertSame('To Be Confirmed', LookupRepository::sanitizeOption('coo_type', 'To Be Confirmed'));
        self::assertSame('', LookupRepository::sanitizeOption('coo_type', ''));
    }

    public function testSanitizeOptionRejectsAnArbitraryStringLikeTbc(): void
    {
        self::assertSame('', LookupRepository::sanitizeOption('coo_type', 'TBC'));
        self::assertSame('', LookupRepository::sanitizeOption('coo_type', '<script>alert(1)</script>'));
    }

    public function testClientCreateStoresTbcAsTheToBeConfirmedFallbackNotTheRawText(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'company_legal_name' => 'R-13 Test Buyer Ltd',
            'billing_address'    => '1 Test Street',
            'coo_type'           => 'TBC',
        ];

        ob_start();
        (new ClientController())->store([]);
        ob_end_clean();

        $client = Database::connection()
            ->query("SELECT coo_type FROM clients WHERE company_legal_name = 'R-13 Test Buyer Ltd' ORDER BY id DESC LIMIT 1")
            ->fetch();
        self::assertNotFalse($client);
        self::assertSame('To Be Confirmed', $client['coo_type']);
    }
}
