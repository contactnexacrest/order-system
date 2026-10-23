<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Services\FileUploadService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BR — export process steps 5-1a/5-2a/5-3a:
 * Letter of Credit upload + LC number, and bank loan amount/interest
 * rate/interest paid recorded as an order expense.
 */
final class OrderLcDetailTest extends DbTestCase
{
    private function render(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    // ---------------------------------------------------------------
    // OrderLcDetailRepository
    // ---------------------------------------------------------------

    public function testUpsertCreatesThenUpdatesTheSameRowRatherThanDuplicating(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        OrderLcDetailRepository::upsert($orderId, [
            'lc_number' => 'LC-001', 'lc_file_id' => null, 'bank_loan_amount' => 50000.0,
            'bank_loan_interest_rate' => 9.5, 'bank_loan_interest_paid' => 1200.0,
            'interest_cost_entry_id' => null, 'recorded_by' => $userId,
        ]);
        $first = OrderLcDetailRepository::find($orderId);
        self::assertSame('LC-001', $first['lc_number']);

        OrderLcDetailRepository::upsert($orderId, [
            'lc_number' => 'LC-001-REVISED', 'lc_file_id' => null, 'bank_loan_amount' => 55000.0,
            'bank_loan_interest_rate' => 10.0, 'bank_loan_interest_paid' => 1400.0,
            'interest_cost_entry_id' => null, 'recorded_by' => $userId,
        ]);
        $second = OrderLcDetailRepository::find($orderId);
        self::assertSame('LC-001-REVISED', $second['lc_number']);
        self::assertSame($first['id'], $second['id'], 'the second save must update the same row, not create a second one');

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM order_lc_details WHERE order_id = {$orderId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    // ---------------------------------------------------------------
    // OrderFinancialsController::saveLcDetails()
    // ---------------------------------------------------------------

    public function testSaveLcDetailsRejectsANonNumericLoanAmount(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['lc_number' => 'LC-X', 'bank_loan_amount' => 'not-a-number'];

        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertNull(OrderLcDetailRepository::find($orderId));
    }

    public function testSaveLcDetailsRejectsANegativeInterestRate(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['bank_loan_interest_rate' => '-5'];

        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    /** External QA report N-11: an interest rate is a percentage — anything above 100% (the report's own example: 250%) is never plausible. */
    public function testSaveLcDetailsRejectsAnInterestRateOver100Percent(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['bank_loan_interest_rate' => '250'];

        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertNull(OrderLcDetailRepository::find($orderId));
    }

    /** External QA report N-11: interest paid larger than the loan amount is unusual, not impossible — warned about, saved either way. */
    public function testSaveLcDetailsWarnsButStillSavesWhenInterestPaidExceedsTheLoanAmount(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['lc_number' => 'LC-OVER', 'bank_loan_amount' => '1000', 'bank_loan_interest_paid' => '1500'];

        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('warning', $flash[0]['type']);
        self::assertStringContainsString('more than the loan amount', $flash[0]['message']);
        $saved = OrderLcDetailRepository::find($orderId);
        self::assertSame('1500.00', $saved['bank_loan_interest_paid']);
    }

    public function testSaveLcDetailsSavesLcNumberAndLoanFieldsWithNoFileUploaded(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'lc_number' => 'LC-2026-047',
            'bank_loan_amount' => '50000',
            'bank_loan_interest_rate' => '9.5',
            'bank_loan_interest_paid' => '',
        ];

        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        $saved = OrderLcDetailRepository::find($orderId);
        self::assertSame('LC-2026-047', $saved['lc_number']);
        self::assertSame('50000.00', $saved['bank_loan_amount']);
        self::assertSame('9.500', $saved['bank_loan_interest_rate']);
        self::assertNull($saved['bank_loan_interest_paid']);
        self::assertNull($saved['interest_cost_entry_id'], 'no interest paid yet — no cost entry should exist');
        self::assertSame([], OrderCostEntryRepository::forOrder($orderId));

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ORDER_LC_DETAILS_SAVED' AND entity_id = {$orderId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    /**
     * Export process step 5-3a: "this loan interest can be considered /
     * record as an expense for that order" — recording an interest-paid
     * amount must create exactly one matching order_cost_entries row
     * (category bank_loan_interest), and re-saving with a different
     * amount must update that SAME row rather than creating a second one.
     */
    public function testSavingInterestPaidCreatesACostEntryAndReSavingUpdatesTheSameOne(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['lc_number' => 'LC-1', 'bank_loan_interest_paid' => '1200'];

        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        $entries = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $entries);
        self::assertSame('bank_loan_interest', $entries[0]['category']);
        self::assertSame('1200.00', $entries[0]['amount_inr']);
        $firstEntryId = (int) $entries[0]['id'];

        $saved = OrderLcDetailRepository::find($orderId);
        self::assertSame($firstEntryId, (int) $saved['interest_cost_entry_id']);

        // Re-save with a higher interest-paid amount.
        $_POST = ['lc_number' => 'LC-1', 'bank_loan_interest_paid' => '1500'];
        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        $entriesAfter = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $entriesAfter, 'must update the existing cost entry, never create a second one');
        self::assertSame($firstEntryId, (int) $entriesAfter[0]['id']);
        self::assertSame('1500.00', $entriesAfter[0]['amount_inr']);
    }

    public function testClearingInterestPaidBackOutRemovesTheCostEntry(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['bank_loan_interest_paid' => '800'];
        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();
        self::assertCount(1, OrderCostEntryRepository::forOrder($orderId));

        $_POST = ['bank_loan_interest_paid' => ''];
        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame([], OrderCostEntryRepository::forOrder($orderId), 'clearing the amount back out must remove the synced cost entry');
        $saved = OrderLcDetailRepository::find($orderId);
        self::assertNull($saved['interest_cost_entry_id']);
    }

    public function testSaveLcDetailsReturns404ForAnUnknownOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderFinancialsController())->saveLcDetails(['id' => '999999999']);
        $output = ob_get_clean();

        self::assertStringContainsString('not found', strtolower($output));
    }

    // ---------------------------------------------------------------
    // View — the order page offers the LC/loan form
    // ---------------------------------------------------------------

    public function testOrderPageOffersTheLcLoanFormWithFileUpload(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->render($orderId);

        self::assertStringContainsString('name="lc_file"', $output);
        self::assertStringContainsString('name="lc_number"', $output);
        self::assertStringContainsString('name="bank_loan_interest_paid"', $output);
        self::assertStringContainsString('/lc-details', $output);
    }

    public function testOrderPageShowsSavedLcDetails(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderLcDetailRepository::upsert($orderId, [
            'lc_number' => 'LC-SHOWN-1', 'lc_file_id' => null, 'bank_loan_amount' => 12345.0,
            'bank_loan_interest_rate' => 8.25, 'bank_loan_interest_paid' => 900.0,
            'interest_cost_entry_id' => null, 'recorded_by' => $userId,
        ]);

        $output = $this->render($orderId);

        self::assertStringContainsString('LC-SHOWN-1', $output);
    }

    /**
     * External QA report L-22 pattern: prove the 'order_lc_copy' upload
     * context actually resolves (seeded in seed.sql) rather than failing
     * "Unknown upload context" before FileUploadService ever reaches
     * move_uploaded_file() — which cannot succeed outside a real HTTP
     * request, so getting that far (and no further) is the proof.
     */
    public function testHandleUploadResolvesTheOrderLcCopyContext(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'lc-copy-test-');
        file_put_contents($tmpPath, '%PDF-1.4 fake pdf content for test');
        $_FILES['lc_file'] = [
            'name' => 'lc-scan.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpPath),
        ];

        try {
            FileUploadService::handleUpload('lc_file', 'order_lc_copy', 'test/lc-copy', null, null, null);
            self::fail('move_uploaded_file() cannot succeed outside a real HTTP upload, so handleUpload() must throw here — the point is which error it throws');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('Unknown upload context', $e->getMessage(), "the 'order_lc_copy' context row must exist");
            self::assertStringContainsString('Failed to move the uploaded file', $e->getMessage());
        } finally {
            unset($_FILES['lc_file']);
            @unlink($tmpPath);
        }
    }
}
