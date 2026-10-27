<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\LogisticsPartnerController;
use App\Controllers\SupplierController;
use App\Controllers\VendorDetailsController;
use App\Helpers\Flash;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\VendorDetailsRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-27): suppliers and logistics partners carry the
 * bank details we pay them into — filled by staff on their behalf, or by
 * them through a one-use Vendor Details Link. Account numbers are stored
 * encrypted; a change by the vendor (or by staff without ticking
 * "verified") must be checked again before paying.
 */
final class VendorBankDetailsTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        Flash::pull();
    }

    private const BANK = [
        'bank_account_name' => 'Heera Granites', 'bank_name' => 'State Bank of India', 'bank_branch' => 'Udaipur Main',
        'bank_ifsc' => 'sbin0001234', 'bank_account_number' => '123456789012', 'bank_account_number_confirm' => '1234 5678 9012',
        'bank_account_type' => 'Current',
    ];

    private function lastFlash(): array
    {
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    private function createSupplier(array $extra): ?int
    {
        $_POST = ['supplier_legal_name' => 'Heera Granites ' . bin2hex(random_bytes(3)), 'types' => ['Quarry Owner'], 'email' => 'accounts@heera.test'] + $extra;
        ob_start();
        (new SupplierController())->create([]);
        ob_end_clean();
        $id = Database::connection()->query("SELECT MAX(id) FROM suppliers WHERE supplier_legal_name = " . Database::connection()->quote($_POST['supplier_legal_name']))->fetchColumn();
        return $id ? (int) $id : null;
    }

    public function testStaffFillTheBankDetailsAndTheAccountNumberIsEncrypted(): void
    {
        self::assertNull($this->createSupplier(['bank_account_number_confirm' => '999'] + self::BANK), 'mismatched account numbers save nothing');
        self::assertStringContainsString('do not match', $this->lastFlash()['message']);

        $id = $this->createSupplier(self::BANK + ['bank_verified' => '1']);
        $row = SupplierRepository::find($id);
        self::assertStringNotContainsString('123456789012', (string) $row['bank_account_number'], 'stored encrypted, never in plain text');
        $bank = VendorDetailsRepository::bankDetails($row);
        self::assertSame('123456789012', $bank['bank_account_number']);
        self::assertSame('SBIN0001234', $bank['bank_ifsc']);
        self::assertNotNull($bank['bank_verified_at']);

        // Editing the address only keeps the bank details — and their verification.
        $_POST = ['supplier_legal_name' => $row['supplier_legal_name'], 'types' => ['Quarry Owner'], 'address' => 'New road'] + array_diff_key(self::BANK, ['bank_account_number' => 1, 'bank_account_number_confirm' => 1]) + ['bank_ifsc' => 'SBIN0001234'];
        $_POST['bank_ifsc'] = 'SBIN0001234';
        (new SupplierController())->update(['id' => (string) $id]);
        self::assertNotNull(VendorDetailsRepository::bankDetails(SupplierRepository::find($id))['bank_verified_at']);

        // A new account number without the tick must be verified again.
        $_POST['bank_account_number'] = $_POST['bank_account_number_confirm'] = '555566667777';
        (new SupplierController())->update(['id' => (string) $id]);
        $bank = VendorDetailsRepository::bankDetails(SupplierRepository::find($id));
        self::assertSame('555566667777', $bank['bank_account_number']);
        self::assertNull($bank['bank_verified_at']);
    }

    public function testTheVendorFillsTheirDetailsOnceThroughTheLink(): void
    {
        $id = $this->createSupplier([]);
        $_POST = [];
        (new SupplierController())->sendDetailsLink(['id' => (string) $id]);
        self::assertStringContainsString('emailed to accounts@heera.test', $this->lastFlash()['message']);
        $token = VendorDetailsRepository::liveDetailsToken(SupplierRepository::find($id));
        self::assertNotNull($token);

        ob_start();
        (new VendorDetailsController())->show(['token' => $token]);
        $page = (string) ob_get_clean();
        self::assertStringContainsString('Your Company &amp; Bank Details', $page);

        $_POST = array_merge(self::BANK, ['bank_ifsc' => 'BAD']);
        (new VendorDetailsController())->submit(['token' => $token]);
        self::assertStringContainsString('valid 11-character IFSC', $this->lastFlash()['message']);
        self::assertFalse(VendorDetailsRepository::bankDetails(SupplierRepository::find($id))['has_bank']);

        $_POST = self::BANK + ['gstin' => '08AAAAA0000A1Z5', 'contact_person' => 'Mahesh'];
        ob_start();
        (new VendorDetailsController())->submit(['token' => $token]);
        self::assertStringContainsString('Thank you', (string) ob_get_clean());
        $row = SupplierRepository::find($id);
        $bank = VendorDetailsRepository::bankDetails($row);
        self::assertSame('123456789012', $bank['bank_account_number']);
        self::assertSame('vendor', $bank['bank_updated_source']);
        self::assertNull($bank['bank_verified_at'], 'what the vendor typed is checked by staff before paying');
        self::assertSame('08AAAAA0000A1Z5', $row['gstin']);
        self::assertSame('Mahesh', $row['contact_person']);

        ob_start();
        (new VendorDetailsController())->show(['token' => $token]);
        self::assertStringContainsString('Link No Longer Valid', (string) ob_get_clean(), 'the link works once');
    }

    public function testTheSameLinkWorksForALogisticsPartnerAndNeverShowsTheFullAccount(): void
    {
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Blue Wave Logistics', 'service_type' => 'freight_forwarder'], $this->userId);
        VendorDetailsRepository::saveBankDetails('logistics_partner', $partnerId, ['bank_account_name' => 'Blue Wave', 'bank_name' => 'HDFC', 'bank_ifsc' => 'HDFC0000123', 'bank_account_number' => '998877665544'], 'staff', $this->userId);
        $_POST = [];
        (new LogisticsPartnerController())->sendDetailsLink(['id' => (string) $partnerId]);
        $token = VendorDetailsRepository::liveDetailsToken(LogisticsPartnerRepository::find($partnerId));

        ob_start();
        (new VendorDetailsController())->show(['token' => $token]);
        $page = (string) ob_get_clean();
        self::assertStringContainsString('Blue Wave Logistics', $page);
        self::assertStringContainsString('ending <strong>5544</strong>', $page);
        self::assertStringNotContainsString('998877665544', $page);

        $_POST = ['bank_account_name' => 'Blue Wave', 'bank_name' => 'HDFC', 'bank_ifsc' => 'HDFC0000123', 'contact_person_name' => 'Ravi'];
        ob_start();
        (new VendorDetailsController())->submit(['token' => $token]);
        ob_end_clean();
        $partner = LogisticsPartnerRepository::find($partnerId);
        self::assertSame('Ravi', $partner['contact_person_name']);
        self::assertSame('998877665544', VendorDetailsRepository::bankDetails($partner)['bank_account_number'], 'an empty account number keeps the one on file');
    }
}
