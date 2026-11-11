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
use App\Repositories\VendorBankChangeRepository;
use App\Repositories\VendorDetailsRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-27): suppliers and logistics partners carry the
 * bank details we pay them into — filled by staff on their behalf, or by
 * them through a one-use Vendor Details Link. Account numbers are stored
 * encrypted; a change by the vendor (or by staff without ticking
 * "verified") must be checked again before paying.
 *
 * Owner decisions 11 Nov 2026 (D-294): a change no longer replaces the account
 * in use — it waits (vendor_bank_changes) for the vendor's confirmation through
 * a link e-mailed to the registered e-mail, then for staff verification. The
 * full flow is tested in AssociateOwnerDecisionsNov11Test.
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

        // D-294: the details wait for the supplier's confirmation — not in use yet, stored encrypted on the change.
        $id = $this->createSupplier(self::BANK);
        $row = SupplierRepository::find($id);
        self::assertFalse(VendorDetailsRepository::bankDetails($row)['has_bank'], 'not in use until confirmed and verified');
        $change = VendorBankChangeRepository::forVendor('supplier', $id)[0];
        self::assertSame('awaiting_confirmation', $change['status']);
        self::assertStringNotContainsString('123456789012', (string) $change['bank_account_number'], 'stored encrypted, never in plain text');
        self::assertSame('123456789012', VendorBankChangeRepository::number($change));
        self::assertSame('SBIN0001234', $change['bank_ifsc']);
        self::assertSame('accounts@heera.test', $change['sent_to_email'], 'the confirmation link goes to the registered e-mail');

        // Editing the address only records no bank change.
        $_POST = ['supplier_legal_name' => $row['supplier_legal_name'], 'types' => ['Quarry Owner'], 'address' => 'New road'];
        (new SupplierController())->update(['id' => (string) $id]);
        self::assertCount(1, VendorBankChangeRepository::forVendor('supplier', $id));

        // A new account number replaces the change still waiting.
        $_POST = ['supplier_legal_name' => $row['supplier_legal_name'], 'types' => ['Quarry Owner'], 'bank_account_number' => '555566667777', 'bank_account_number_confirm' => '555566667777',
            'bank_account_name' => 'Heera Granites', 'bank_name' => 'State Bank of India', 'bank_ifsc' => 'SBIN0001234'];
        (new SupplierController())->update(['id' => (string) $id]);
        $changes = VendorBankChangeRepository::forVendor('supplier', $id);
        self::assertSame('555566667777', VendorBankChangeRepository::number($changes[0]));
        self::assertSame(['awaiting_confirmation', 'cancelled', 'replaced'], [$changes[0]['status'], $changes[1]['status'], $changes[1]['cancelled_how']]);
        self::assertFalse(VendorDetailsRepository::bankDetails(SupplierRepository::find($id))['has_bank']);
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

        $_POST = self::BANK + ['gstin' => '08AAAAA0000A1Z5', 'contact_person' => 'Accounts Manager'];
        ob_start();
        (new VendorDetailsController())->submit(['token' => $token]);
        self::assertStringContainsString('Thank you', (string) ob_get_clean());
        $row = SupplierRepository::find($id);
        // D-294: what the vendor typed waits for their confirmation (link to the registered e-mail), then staff verify it.
        self::assertFalse(VendorDetailsRepository::bankDetails($row)['has_bank']);
        $change = VendorBankChangeRepository::forVendor('supplier', $id)[0];
        self::assertSame(['awaiting_confirmation', 'vendor', 'accounts@heera.test'], [$change['status'], $change['entered_source'], $change['sent_to_email']]);
        self::assertSame('123456789012', VendorBankChangeRepository::number($change));
        self::assertSame('08AAAAA0000A1Z5', $row['gstin']);
        self::assertSame('Accounts Manager', $row['contact_person']);

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

        $_POST = ['bank_account_name' => 'Blue Wave', 'bank_name' => 'HDFC', 'bank_ifsc' => 'HDFC0000123', 'contact_person_name' => 'Operations Manager'];
        ob_start();
        (new VendorDetailsController())->submit(['token' => $token]);
        ob_end_clean();
        $partner = LogisticsPartnerRepository::find($partnerId);
        self::assertSame('Operations Manager', $partner['contact_person_name']);
        self::assertSame('998877665544', VendorDetailsRepository::bankDetails($partner)['bank_account_number'], 'an empty account number keeps the one on file');
    }
}
