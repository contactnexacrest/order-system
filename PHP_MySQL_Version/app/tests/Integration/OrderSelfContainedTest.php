<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ZohoPurchaseRepository;
use App\Services\AssociateCommission;
use App\Services\CategoryDocumentProfile;
use App\Services\DocumentGenerationService;
use App\Services\MerchantTradeService;
use App\Services\OrderSnapshotService;
use App\Services\PaymentTermsApprovalService;
use App\Services\ShipmentGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-262 (6 Nov 2026, double-confirmed): an order is self-contained.
 * Once its PI is approved, editing a master (a clause, the tolerance, the company bank,
 * a preset's approval flag, the pricing exchange rate, a category, a compliance task's
 * name, a product's Zoho item, a vendor's identity, the Merchant Trade settings, an
 * associate's GST) never changes that order's next document, its gates or its money.
 * Before the PI is approved an order follows today's settings, as before.
 * Node twin: orderSelfContained.test.js.
 */
final class OrderSelfContainedTest extends DbTestCase
{
    /** @var array<string, ?string> */
    private array $settingsBefore = [];
    private ?array $clauseBefore = null;
    private ?string $taskNameBefore = null;
    private ?string $fxBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['bank_account_no', 'lut_number', 'quantity_shortfall_tolerance_pct'] as $k) {
            $this->settingsBefore[$k] = CompanySettingsRepository::companyValue($k);
        }
        $this->fxBefore = Database::connection()->query("SELECT setting_value FROM pricing_settings WHERE setting_key = 'pricing_fx_rate'")->fetchColumn() ?: null;
        $this->taskNameBefore = (string) Database::connection()->query("SELECT name FROM compliance_task_types WHERE task_key = 'pre_shipment_inspection'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach ($this->settingsBefore as $k => $v) {
            CompanySettingsRepository::set($k, $v);
        }
        if ($this->fxBefore !== null) {
            $pdo->prepare("UPDATE pricing_settings SET setting_value = :v WHERE setting_key = 'pricing_fx_rate'")->execute(['v' => $this->fxBefore]);
        }
        if ($this->clauseBefore !== null) {
            $pdo->prepare('UPDATE tc_clauses SET clause_text = :t WHERE id = :id')->execute(['t' => $this->clauseBefore['clause_text'], 'id' => $this->clauseBefore['id']]);
        }
        $pdo->prepare("UPDATE compliance_task_types SET name = :n WHERE task_key = 'pre_shipment_inspection'")->execute(['n' => $this->taskNameBefore]);
        $pdo->exec("UPDATE payment_presets SET requires_md_approval = 0 WHERE preset_name = 'Standard — New Buyer'");
        CompanySettingsRepository::setOverlay([]);
        parent::tearDown();
    }

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return (string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }

    private function engineeringCategory(): int
    {
        $id = ProductCategoryRepository::create('TEST-Selfcontained Eng ' . bin2hex(random_bytes(3)), ['Material'], $this->createTestUser('Admin'));
        ProductCategoryRepository::updateSettings($id, [
            'default_unit' => 'PCS', 'allowed_units' => 'PCS', 'default_hs_code' => '848180',
            'packing_type' => 'Wooden Boxes', 'quality_documents' => "Mill Test Certificate 3.1",
            'tc_clause_set' => 'engineering',
        ]);
        return $id;
    }

    /** @return array{0:int,1:int} order id, category id */
    private function order(?int $categoryId = null): array
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $lineId = OrderProductRepository::add($orderId, 1, 'Ball Valve', null, null, '200', false, 'PCS', '42.00', '848180');
        $categoryId ??= $this->engineeringCategory();
        ProductCategoryRepository::setLineCategory($lineId, $categoryId, ['Material' => 'SS316']);
        Database::connection()->prepare('UPDATE orders SET estimated_package_count = 8 WHERE id = :id')->execute(['id' => $orderId]);
        return [$orderId, $categoryId];
    }

    private function approvePi(int $orderId): void
    {
        $pi = DocumentGenerationService::generate($orderId, 'PI', $this->createTestUser('Admin'), null, false, true);
        DocumentGenerationService::finalizeApproval((int) $pi['document_id']);
    }

    /** Edits every master the order's documents read. */
    private function editMasters(int $categoryId): void
    {
        $pdo = Database::connection();
        CompanySettingsRepository::set('bank_account_no', 'NEW-ACCOUNT-999');
        CompanySettingsRepository::set('lut_number', 'NEW-LUT-2027');
        CompanySettingsRepository::set('quantity_shortfall_tolerance_pct', '9');
        $clause = $pdo->query("SELECT id, clause_text FROM tc_clauses WHERE clause_set = 'engineering' AND status = 'active' ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($clause, 'the seed has engineering clauses');
        $this->clauseBefore = $clause;
        $pdo->prepare('UPDATE tc_clauses SET clause_text = :t WHERE id = :id')->execute(['t' => 'CHANGED CLAUSE TEXT AFTER THE PI', 'id' => $clause['id']]);
        ProductCategoryRepository::updateSettings($categoryId, [
            'default_unit' => 'PCS', 'allowed_units' => 'PCS', 'default_hs_code' => '848180',
            'packing_type' => 'Steel Drums', 'quality_documents' => "Hydro Test Report",
            'tc_clause_set' => 'engineering',
        ]);
        $pdo->exec("UPDATE payment_presets SET requires_md_approval = 1 WHERE preset_name = 'Standard — New Buyer'");
        $pdo->exec("UPDATE pricing_settings SET setting_value = '99.5000' WHERE setting_key = 'pricing_fx_rate'");
    }

    public function testApprovingThePiFreezesTheOrderOnce(): void
    {
        [$orderId] = $this->order();
        self::assertFalse(OrderSnapshotService::isFrozen($orderId), 'before the PI is approved the order follows today\'s settings');
        $this->approvePi($orderId);
        $order = OrderRepository::find($orderId);
        self::assertNotNull($order['frozen_at']);
        self::assertSame(0, (int) $order['payment_terms_requires_md_approval']);
        self::assertNotNull($order['quantity_tolerance_pct']);
        $snapshot = OrderSnapshotService::snapshot($orderId);
        self::assertSame(CompanySettingsRepository::companyValue('bank_account_no'), $snapshot['company']['bank_account_no']);
        self::assertArrayHasKey('PI|standard', $snapshot['terms']);
        self::assertFalse(OrderSnapshotService::freeze($orderId), 'a second approval never re-freezes');
    }

    public function testAMasterEditAfterThePiNeverChangesTheOrdersNextDocument(): void
    {
        [$orderId, $categoryId] = $this->order();
        $this->approvePi($orderId);
        [$liveOrder] = $this->order($categoryId); // a second order of the same category, PI not approved: it follows the new settings
        $this->editMasters($categoryId);

        $oc = $this->pdfText((int) DocumentGenerationService::generate($orderId, 'OC', $this->createTestUser('Admin'), null, false, true)['pdf_file_id']);
        self::assertStringNotContainsString('NEW-ACCOUNT-999', $oc, 'company bank frozen');
        self::assertStringNotContainsString('CHANGED CLAUSE TEXT AFTER THE PI', $oc, 'clause text frozen');
        self::assertStringNotContainsString('Hydro Test Report', $oc, 'category quality documents frozen');
        self::assertStringContainsString('Mill Test Certificate 3.1', $oc);

        $profile = CategoryDocumentProfile::forOrder($orderId);
        self::assertSame('Wooden Boxes', $profile['packing_label'], 'packing words frozen');
        self::assertSame(['Mill Test Certificate 3.1'], array_column($profile['quality_documents'], 'name'));

        // The order that has no approved PI yet follows today's settings, exactly as before.
        $liveProfile = CategoryDocumentProfile::forOrder($liveOrder);
        self::assertSame('Steel Drums', $liveProfile['packing_label']);
        $liveOc = $this->pdfText((int) DocumentGenerationService::generate($liveOrder, 'OC', $this->createTestUser('Admin'), null, false, true)['pdf_file_id']);
        self::assertStringContainsString('CHANGED CLAUSE TEXT AFTER THE PI', $liveOc);
        self::assertSame([], CompanySettingsRepository::setOverlay([]), 'no frozen values leak out of a document run');
    }

    public function testGatesAndMoneyStayWithTheOrder(): void
    {
        [$orderId, $categoryId] = $this->order();
        Database::connection()->prepare("UPDATE orders SET currency_code = 'EUR' WHERE id = :id")->execute(['id' => $orderId]);
        $this->approvePi($orderId);
        $fxAtPi = (float) OrderRepository::find($orderId)['pricing_fx_rate_at_pi'];
        $this->editMasters($categoryId);
        $order = OrderRepository::find($orderId);

        self::assertFalse(PaymentTermsApprovalService::state($orderId)['required'], 'the preset flag ticked later does not block the order');
        self::assertSame($fxAtPi, AssociateCommission::usdRate($order), 'USD-per-unit commission uses the rate fixed at PI');
        self::assertNotSame(99.5, AssociateCommission::usdRate($order));
        self::assertSame((float) $this->settingsBefore['quantity_shortfall_tolerance_pct'], OrderSnapshotService::tolerancePct($order), 'the packing gate uses the agreed tolerance');
    }

    public function testTheInspectionGateFollowsTheFixedTaskKeyNotTheName(): void
    {
        [$orderId] = $this->order();
        self::assertTrue(ShipmentGateService::inspectionApplies($orderId));
        Database::connection()->exec("UPDATE compliance_task_types SET name = 'PSI by SGS' WHERE task_key = 'pre_shipment_inspection'");
        self::assertTrue(ShipmentGateService::inspectionApplies($orderId), 'renaming the task never switches the gate off');
    }

    public function testZohoItemAndVendorIdentityStayWithTheOrder(): void
    {
        [$orderId] = $this->order();
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO catalog_products (name, hs_code, specifications, zoho_item_id) VALUES ('TEST-Selfcontained Valve', '848180', '', 'ZITEM-OLD')");
        $productId = (int) $pdo->lastInsertId();
        $pi = DocumentGenerationService::generate($orderId, 'PI', $this->createTestUser('Admin'), null, false, true);
        // The line is linked to the catalogue product before the PI is approved (the freeze copies its Zoho item).
        $pdo->prepare('UPDATE order_products SET catalog_product_id = :p WHERE order_id = :o')->execute(['p' => $productId, 'o' => $orderId]);
        DocumentGenerationService::finalizeApproval((int) $pi['document_id']);
        $pdo->prepare("UPDATE catalog_products SET zoho_item_id = 'ZITEM-NEW' WHERE id = :p")->execute(['p' => $productId]);
        self::assertSame('ZITEM-OLD', (string) $pdo->query("SELECT zoho_item_id FROM order_products WHERE order_id = {$orderId}")->fetchColumn());
        $lines = (new \ReflectionMethod(\App\Services\ZohoInvoiceData::class, 'ciLines'))->invoke(null, $orderId, 'Invoice');
        self::assertSame('ZITEM-OLD', $lines[0]['item_id'] ?? null, 'the CI goes to Books with the item it was frozen with');

        $pdo->exec("INSERT INTO logistics_partners (partner_name, service_type, gstin, pan) VALUES ('TEST-Selfcontained Forwarder', 'freight_forwarder', '33AAAAA0000A1Z5', 'AAAAA0000A')");
        $partnerId = (int) $pdo->lastInsertId();
        $billId = OrderVendorBillRepository::create($orderId, 'logistics', [
            'logistics_partner_id' => $partnerId, 'bill_for' => 'ocean_freight', 'payment_kind' => 'final', 'doc_kind' => 'invoice',
            'bill_no' => 'TEST-SC-1', 'bill_date' => '2026-11-06', 'taxable_inr' => 1000.0, 'gst_inr' => 0.0,
        ], $this->createTestUser('Admin'));
        $pdo->prepare("UPDATE logistics_partners SET partner_name = 'TEST-Renamed Forwarder', gstin = '33BBBBB1111B1Z5', pan = 'BBBBB1111B' WHERE id = :id")->execute(['id' => $partnerId]);
        $bill = ZohoPurchaseRepository::findBill($billId);
        self::assertSame(['TEST-Selfcontained Forwarder', '33AAAAA0000A1Z5', 'AAAAA0000A'], [$bill['vendor_name'], $bill['vendor_gstin'], $bill['vendor_pan']]);
    }

    public function testMerchantDealsAndAssociatePayoutsKeepTheirOwnSettings(): void
    {
        $frozen = ['rbi_settings_json' => json_encode(['mtt_max_months_total' => '9', 'mtt_max_outlay_months' => '4', 'mtt_advance_limit_usd' => '500000', 'mtt_usd_inr_rate' => '84.0000', 'bank_documents' => ['inv' => 'Invoice']])];
        $settings = MerchantTradeService::dealRbiSettings($frozen);
        self::assertSame(['9', '4', '500000', '84.0000'], [$settings['mtt_max_months_total'], $settings['mtt_max_outlay_months'], $settings['mtt_advance_limit_usd'], $settings['mtt_usd_inr_rate']]);
        self::assertSame(['inv' => 'Invoice'], MerchantTradeService::dealBankDocuments($frozen));
        self::assertSame(MerchantTradeService::rbiSettings(), MerchantTradeService::dealRbiSettings(['rbi_settings_json' => null]), 'a deal made before D-262 follows today\'s settings');

        $pdo = Database::connection();
        [$orderId] = $this->order();
        $pdo->exec("INSERT INTO associates (name, status, commission_mode, gstin) VALUES ('TEST-Selfcontained Assoc', 'active', 'ladder', '33CCCCC2222C1Z5')");
        $associateId = (int) $pdo->lastInsertId();
        $payoutId = \App\Repositories\IncentiveRepository::addPayout([
            'order_id' => $orderId, 'payee_kind' => 'associate', 'associate_id' => $associateId, 'role' => 'associate',
            'amount_inr' => 1000.0, 'incentive_month' => date('Y-m'),
        ]);
        $pdo->prepare("UPDATE associates SET gstin = NULL WHERE id = :id")->execute(['id' => $associateId]);
        $row = $pdo->query("SELECT gst_pct, associate_gstin FROM incentive_payouts WHERE id = {$payoutId}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('33CCCCC2222C1Z5', $row['associate_gstin'], 'the GSTIN at lock stays with the payout');
        self::assertSame(AssociateCommission::settings()['gst_pct'], (float) $row['gst_pct']);
    }

    public function testDivisionDocumentsKeepTheCompanyDetailsTheyWereFixedWith(): void
    {
        $read = OrderSnapshotService::companyReader((string) json_encode(['bank_account_no' => 'FROZEN-1']));
        CompanySettingsRepository::set('bank_account_no', 'NEW-ACCOUNT-999');
        self::assertSame('FROZEN-1', $read('bank_account_no'));
        self::assertSame('NEW-ACCOUNT-999', OrderSnapshotService::companyReader(null)('bank_account_no'), 'not yet fixed: today\'s details');
    }

    public function testTheMdRefreshesTheCompanyDetailsWithAReasonAndItIsLogged(): void
    {
        [$orderId] = $this->order();
        self::assertNotNull(OrderSnapshotService::refreshCompany($orderId, $this->createTestUser('Managing Director'), 'Bank account changed'), 'nothing to refresh before the PI');
        $this->approvePi($orderId);
        CompanySettingsRepository::set('bank_account_no', 'NEW-ACCOUNT-999');
        $md = $this->createTestUser('Managing Director');
        self::assertNotNull(OrderSnapshotService::refreshCompany($orderId, $md, 'short'), 'a reason is required');
        self::assertNull(OrderSnapshotService::refreshCompany($orderId, $md, 'Bank account changed to the new SBI account'));
        self::assertSame('NEW-ACCOUNT-999', OrderSnapshotService::snapshot($orderId)['company']['bank_account_no']);
        $log = Database::connection()->query("SELECT reason, new_value FROM audit_log WHERE action_type = 'ORDER_COMPANY_DETAILS_REFRESHED' AND entity_id = {$orderId}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Bank account changed to the new SBI account', $log['reason']);
        self::assertStringContainsString('NEW-ACCOUNT-999', (string) $log['new_value']);
    }
}
