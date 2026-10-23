<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderTravelExpenseRepository;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BW — export process step 11a: "Record 5/7/10%
 * of the total order value as order expenses - it includes all the
 * travelling expenses for that order". Staff records the factory
 * distance (reference only) and chooses the percentage; the system
 * computes and syncs the amount into order_cost_entries.
 */
final class OrderTravelExpenseTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
    }

    private function addProduct(int $orderId, float $fobValue): void
    {
        OrderProductRepository::add($orderId, 1, 'Test Product', null, null, '1', false, 'pcs', (string) $fobValue);
    }

    // ---------------------------------------------------------------
    // OrderTravelExpenseRepository
    // ---------------------------------------------------------------

    public function testFindReturnsNullBeforeAnythingRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertNull(OrderTravelExpenseRepository::find($orderId));
    }

    public function testUpsertCreatesThenUpdatesTheSameRow(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        OrderTravelExpenseRepository::upsert($orderId, ['factory_distance_km' => 175.0, 'expense_pct' => 5.0, 'expense_amount' => 500.0, 'cost_entry_id' => null, 'recorded_by' => $userId]);
        $first = OrderTravelExpenseRepository::find($orderId);
        self::assertSame('5.00', $first['expense_pct']);

        OrderTravelExpenseRepository::upsert($orderId, ['factory_distance_km' => 500.0, 'expense_pct' => 15.0, 'expense_amount' => 1500.0, 'cost_entry_id' => null, 'recorded_by' => $userId]);
        $second = OrderTravelExpenseRepository::find($orderId);
        self::assertSame('15.00', $second['expense_pct']);
        self::assertSame($first['id'], $second['id']);
    }

    // ---------------------------------------------------------------
    // OrderFinancialsController::saveTravelExpenses()
    // ---------------------------------------------------------------

    public function testSavingAPresetPercentageComputesTheAmountFromFobValue(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->addProduct($orderId, 10000.0);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['factory_distance_km' => '175', 'expense_pct' => '5'];

        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        $row = OrderTravelExpenseRepository::find($orderId);
        self::assertSame('175.00', $row['factory_distance_km']);
        self::assertSame('5.00', $row['expense_pct']);
        self::assertSame('500.00', $row['expense_amount'], '5% of 10000 FOB value');
        self::assertSame('success', Flash::pull()[0]['type']);
    }

    public function testSavingACustomOtherPercentageUsesTheFreeTextValue(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->addProduct($orderId, 10000.0);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['expense_pct' => 'other', 'expense_pct_other' => '12.5'];

        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        $row = OrderTravelExpenseRepository::find($orderId);
        self::assertSame('12.50', $row['expense_pct']);
        self::assertSame('1250.00', $row['expense_amount']);
    }

    public function testCreatesACostEntryForTheComputedAmount(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->addProduct($orderId, 20000.0);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['expense_pct' => '10'];

        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        $entries = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $entries);
        self::assertSame('travel_expenses', $entries[0]['category']);
        self::assertSame('2000.00', $entries[0]['amount_inr']);
    }

    public function testResavingUpdatesTheSameCostEntryRatherThanCreatingASecondOne(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->addProduct($orderId, 10000.0);
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['expense_pct' => '5'];
        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        $_POST = ['expense_pct' => '15'];
        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        $entries = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $entries);
        self::assertSame('1500.00', $entries[0]['amount_inr']);
    }

    public function testRejectsAZeroPercentage(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['expense_pct' => '0'];

        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderTravelExpenseRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testRejectsAPercentageOverOneHundred(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['expense_pct' => '150'];

        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderTravelExpenseRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testRejectsANonNumericDistance(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['expense_pct' => '5', 'factory_distance_km' => 'far away'];

        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderTravelExpenseRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testReturns404ForAnUnknownOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['expense_pct' => '5'];

        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => '999999999']);
        $output = (string) ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('not found', strtolower($output));
    }

    // ---------------------------------------------------------------
    // View rendering — orders/show.php
    // ---------------------------------------------------------------

    public function testStaffOrderPageOffersPresetAndCustomPercentageOptions(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="expense_pct"', $output);
        self::assertStringContainsString('name="factory_distance_km"', $output);
        self::assertStringContainsString('Other — specify', $output);
        self::assertStringContainsString('action="/orders/' . $orderId . '/travel-expenses"', $output);
    }

    public function testStaffOrderPageShowsTheSavedAmount(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->addProduct($orderId, 10000.0);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['factory_distance_km' => '175', 'expense_pct' => '5'];
        ob_start();
        (new OrderFinancialsController())->saveTravelExpenses(['id' => (string) $orderId]);
        ob_end_clean();

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('175.00 km', $output);
        self::assertStringContainsString('500.00', $output);
    }

    public function testFieldIsNotExposedWithoutManageOrderFinancials(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('name="expense_pct"', $output, 'travel expenses are financial data — gated the same as the LC/inspection-fee sections');
    }
}
