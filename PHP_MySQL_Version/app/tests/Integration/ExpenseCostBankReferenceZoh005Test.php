<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderExpenseController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * QA ZOH-005 — owner decision 13 Nov 2026 (Option A): rule D-212 applies to
 * order expenses and order costs. The forms take a "Bank reference (UTR /
 * transaction ID)", required when the money went out of the company account;
 * the Zoho Books description says what it is, for which order, what it was
 * paid from and the UTR. The Node suite has the same test
 * (expenseCostBankReferenceZoh005.test.js).
 */
final class ExpenseCostBankReferenceZoh005Test extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        $_FILES = [];
        Flash::pull();
    }

    private function lastFlash(): array
    {
        $messages = Flash::pull();
        return end($messages) ?: ['type' => null, 'message' => ''];
    }

    private function row(array $extra): array
    {
        return $extra + ['buyer_name' => 'TEST-QZP Buyer A', 'buyer_inquiry_ref' => 'TQZPA1'];
    }

    public function testZoh005ExpenseDescriptionNamesWhatOrderPaidFromAndUtr(): void
    {
        $e = ['category' => 'courier', 'details' => 'Samples to buyer', 'paid_by' => 'company_account', 'staff_name' => null];
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Courier — Samples to buyer · order expense paid from the company account · UTR HDFCN26304123456',
            ZohoBooksService::expenseDescription($this->row($e + ['bank_reference' => 'HDFCN26304123456'])));
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Courier — Samples to buyer · order expense paid from the company account',
            ZohoBooksService::expenseDescription($this->row($e + ['bank_reference' => null])), 'an old expense without a reference only gains the paid-from');
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Hotel — Hotel Kota, 1 night · order expense paid by company card · transaction ref 4512-778899',
            ZohoBooksService::expenseDescription($this->row(['category' => 'hotel', 'details' => 'Hotel Kota, 1 night', 'paid_by' => 'company_card', 'bank_reference' => '4512-778899'])));
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Hotel — Hotel Kota, 1 night · order expense paid by company card',
            ZohoBooksService::expenseDescription($this->row(['category' => 'hotel', 'details' => 'Hotel Kota, 1 night', 'paid_by' => 'company_card', 'bank_reference' => ''])));
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Travel — train / flight / bus — Train to the factory · order expense paid by staff (Export Executive), to reimburse',
            ZohoBooksService::expenseDescription($this->row(['category' => 'travel_ticket', 'details' => 'Train to the factory', 'paid_by' => 'staff', 'staff_name' => 'Export Executive', 'bank_reference' => null])));
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Travel — train / flight / bus — Train to the factory · order expense paid by staff (Export Executive), to reimburse · transaction ref UPI-6123',
            ZohoBooksService::expenseDescription($this->row(['category' => 'travel_ticket', 'details' => 'Train to the factory', 'paid_by' => 'staff', 'staff_name' => 'Export Executive', 'bank_reference' => 'UPI-6123'])));
    }

    public function testZoh005CostDescriptionNamesWhatOrderPaidFromAndUtr(): void
    {
        self::assertSame('TEST-QZP Buyer A / TQZPA1: ECGC Insurance — ECGC cover · order cost paid from the company account · UTR HDFCN26304123456',
            ZohoBooksService::costEntryDescription($this->row(['category' => 'ecgc_insurance', 'description' => 'ECGC cover', 'paid_from' => 'company_account', 'bank_reference' => 'HDFCN26304123456'])));
        self::assertSame('TEST-QZP Buyer A / TQZPA1: ECGC Insurance — ECGC cover · order cost paid from the company account',
            ZohoBooksService::costEntryDescription($this->row(['category' => 'ecgc_insurance', 'description' => 'ECGC cover', 'paid_from' => 'company_account', 'bank_reference' => null])));
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Documentation — Legalisation fee · order cost paid by company card · transaction ref 4512-990011',
            ZohoBooksService::costEntryDescription($this->row(['category' => 'documentation', 'description' => 'Legalisation fee', 'paid_from' => 'company_card', 'bank_reference' => '4512-990011'])));
        self::assertSame('TEST-QZP Buyer A / TQZPA1: Documentation — Legalisation fee · order cost paid by company card',
            ZohoBooksService::costEntryDescription($this->row(['category' => 'documentation', 'description' => 'Legalisation fee', 'paid_from' => 'company_card', 'bank_reference' => null])));
    }

    public function testZoh005AnExpensePaidFromTheCompanyAccountIsRefusedWithoutItsBankReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $base = ['expense_date' => date('Y-m-d'), 'category' => 'courier', 'details' => 'Samples to buyer', 'amount_inr' => '1800', 'paid_by' => 'company_account', 'no_receipt_reason' => 'Courier gave no bill at the counter'];

        $_POST = $base;
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertSame(['type' => 'error', 'message' => 'Enter the bank reference (UTR / transaction ID): the expense was paid from the company account.'], $this->lastFlash());
        self::assertSame([], OrderExpenseRepository::forOrder($orderId));

        $_POST = $base + ['bank_reference' => str_repeat('9', 151)];
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertStringContainsString('at most 150 characters', $this->lastFlash()['message']);

        $_POST = $base + ['bank_reference' => '  HDFCN26304123456 '];
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertSame('success', $this->lastFlash()['type']);

        $_POST = ['paid_by' => 'company_card'] + $base;
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertSame('success', $this->lastFlash()['type'], 'optional for the company card');

        $rows = OrderExpenseRepository::forOrder($orderId);
        self::assertSame(['HDFCN26304123456', null], array_column($rows, 'bank_reference'));
    }

    public function testZoh005ACostPaidFromTheCompanyAccountIsRefusedWithoutItsBankReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $base = ['category' => 'ecgc_insurance', 'amount_inr' => '4200', 'incurred_at' => date('Y-m-d'), 'description' => 'ECGC cover for this shipment, policy TEST-1', 'paid_from' => 'company_account'];

        $_POST = $base;
        (new OrderFinancialsController())->addCostEntry(['id' => (string) $orderId]);
        self::assertSame(['type' => 'error', 'message' => 'Enter the bank reference (UTR / transaction ID): the cost was paid from the company account.'], $this->lastFlash());
        self::assertSame([], OrderCostEntryRepository::forOrder($orderId));

        $_POST = $base + ['bank_reference' => 'HDFCN26304123456'];
        (new OrderFinancialsController())->addCostEntry(['id' => (string) $orderId]);
        self::assertSame('success', $this->lastFlash()['type']);

        $_POST = ['paid_from' => 'company_card'] + $base;
        (new OrderFinancialsController())->addCostEntry(['id' => (string) $orderId]);
        self::assertSame('success', $this->lastFlash()['type'], 'optional for the company card');

        $refs = array_column(Database::connection()->query('SELECT bank_reference FROM order_cost_entries WHERE order_id = ' . $orderId . ' ORDER BY id')->fetchAll(), 'bank_reference');
        self::assertSame(['HDFCN26304123456', null], $refs);
    }

    public function testZoh005BothFormsAskForTheBankReferenceInTheBrowserWhenTheCompanyAccountPaid(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/views/orders/show.php');
        self::assertStringContainsString('<input type="text" name="bank_reference" maxlength="150" data-required-if="paid_by=company_account"', $view);
        self::assertStringContainsString('<input type="text" name="bank_reference" maxlength="150" data-required-if="paid_from=company_account"', $view);
        self::assertSame(2, substr_count($view, 'Bank reference (UTR / transaction ID) <small class="muted">(required when paid from the company account)</small>'));
    }

    public function testZoh005TheOrderPageListsShowTheBankReference(): void
    {
        self::assertSame('UTR HDFCN26304123456', ZohoBooksService::moneyBankRef('company_account', ' HDFCN26304123456 '));
        self::assertSame('transaction ref 4512', ZohoBooksService::moneyBankRef('company_card', '4512'));
        self::assertNull(ZohoBooksService::moneyBankRef('company_account', null));
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/views/orders/show.php');
        self::assertSame(2, substr_count($view, 'ZohoBooksService::moneyBankRef('));
    }
}
