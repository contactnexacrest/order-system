<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPackingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Task #36 — "Rearrange Standard template into exact 0-17 sequence." The
 * user's own complaint: "no back and forth on screen... after every step
 * we need to come back to document - to generate/view document." Every
 * stage's own "Generate X" document button used to be bunched together
 * in one central "Documents & Approvals" panel at the top of the page,
 * away from that stage's other actions — this moved each one into its
 * own stage section. Also fixes three section-ordering violations found
 * during the audit: Order Communication (step 13) sat before everything
 * else on the page instead of after step 12; Disputes (15/16) rendered
 * before Order Feedback (14); and Travel Expenses (11a) sat next to
 * step 8 instead of with the rest of Order Financials.
 *
 * Position assertions anchor on each real section's own closing `</h2>`
 * tag (e.g. 'Stage 2 — Buyer PO</h2>') rather than the bare heading
 * text — the page's own progress-stepper nav (near the very top of the
 * page) renders every stage's name too, in an `aria-label="Jump to
 * Stage N — ..."` with no `</h2>` anywhere near it, so a bare-text
 * search would false-match that earlier stepper entry instead of the
 * real section further down the page.
 */
final class OrderPageSectionOrderTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function createTestSupplierPo(int $orderId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO suppliers (supplier_legal_name) VALUES (:name)')
            ->execute(['name' => 'Section Order Test Supplier Co']);
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference)
             VALUES (:order_id, :supplier_id, 'Section Order Test Supplier Co', 'SECORDER-SPO-TEST')"
        )->execute(['order_id' => $orderId, 'supplier_id' => $supplierId]);
    }

    private function render(int $orderId, string $role = 'Admin'): string
    {
        $userId = $this->createTestUser($role);
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // Document-generate buttons now live inside their own stage section
    // ---------------------------------------------------------------

    public function testBuyerPoGenerateButtonLivesInsideStage2NotTheTopDocumentsPanel(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 1);

        $output = $this->render($orderId);

        $stage2Pos = strpos($output, "Step 4a &middot; Generate Buyer's PO</h2>");
        $buyerPoGeneratePos = strpos($output, 'Generate Buyer PO</button>');

        self::assertNotFalse($stage2Pos);
        self::assertNotFalse($buyerPoGeneratePos);
        self::assertGreaterThan($stage2Pos, $buyerPoGeneratePos, "Generate Buyer PO must render inside its own Step 4a step-card, after its heading");

        $topOfPage = substr($output, 0, $stage2Pos);
        self::assertStringNotContainsString('Generate Buyer PO</button>', $topOfPage, "Generate Buyer PO must no longer sit inside the top Documents & Approvals panel, before the Step 4a step-card");
    }

    public function testPiGenerateButtonLivesInsideItsOwnStep5cSection(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 2);

        $output = $this->render($orderId);

        $stage3Pos = strpos($output, 'Step 5c &middot; Generate Proforma Invoice</h2>');
        $piGeneratePos = strpos($output, 'Generate Proforma Invoice</button>');

        self::assertNotFalse($stage3Pos);
        self::assertNotFalse($piGeneratePos);
        self::assertGreaterThan($stage3Pos, $piGeneratePos);
    }

    public function testOcGenerateButtonLivesInsideStage4Section(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 3);

        $output = $this->render($orderId);

        $stage4Pos = strpos($output, 'Step 6a &middot; Generate Order Confirmation</h2>');
        $ocGeneratePos = strpos($output, 'Generate Order Confirmation</button>');

        self::assertNotFalse($stage4Pos);
        self::assertNotFalse($ocGeneratePos);
        self::assertGreaterThan($stage4Pos, $ocGeneratePos);
    }

    public function testSuppoGenerateButtonLivesInsideStage5SectionOnceASupplierPoExists(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 4);
        $this->createTestSupplierPo($orderId);

        $output = $this->render($orderId);

        $stage5Pos = strpos($output, 'Step 7b &middot; Generate Supplier PO</h2>');
        $suppoGeneratePos = strpos($output, 'Generate Supplier PO</button>');

        self::assertNotFalse($stage5Pos);
        self::assertNotFalse($suppoGeneratePos);
        self::assertGreaterThan($stage5Pos, $suppoGeneratePos);
    }

    public function testFdnGenerateButtonLivesInsideStage6SectionOnceFreightTermsAreSaved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        $this->unlockThrough($orderId, 5);
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '1200.00', 'insurance_amount' => '150.00']);

        $output = $this->render($orderId);

        $stage6Pos = strpos($output, 'Step 9b &middot; Generate Debit Note</h2>');
        $fdnGeneratePos = strpos($output, 'Generate Debit Note</button>');

        self::assertNotFalse($stage6Pos);
        self::assertNotFalse($fdnGeneratePos);
        self::assertGreaterThan($stage6Pos, $fdnGeneratePos);
    }

    public function testPlGenerateButtonLivesInsideStage7SectionOncePackingIsSaved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 6);
        OrderPackingRepository::upsert($orderId, ['actual_quantity_packed' => '50', 'crate_count' => '10']);

        $output = $this->render($orderId);

        $stage7Pos = strpos($output, 'Step 10b &middot; Generate Packing List</h2>');
        $plGeneratePos = strpos($output, 'Generate Packing List</button>');

        self::assertNotFalse($stage7Pos);
        self::assertNotFalse($plGeneratePos);
        self::assertGreaterThan($stage7Pos, $plGeneratePos);
    }

    public function testCiGenerateButtonLivesInsideStage8Section(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 7);

        $output = $this->render($orderId);

        $stage8Pos = strpos($output, 'Step 11a &middot; Generate Commercial Invoice');
        $ciGeneratePos = strpos($output, 'Generate Commercial Invoice</button>');

        self::assertNotFalse($stage8Pos);
        self::assertNotFalse($ciGeneratePos);
        self::assertGreaterThan($stage8Pos, $ciGeneratePos);
    }

    public function testNoDocumentGenerateButtonExceptQuotationAndAnnexureRemainsInTheTopDocumentsPanel(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 1);

        $output = $this->render($orderId);

        $documentsPanelStart = strpos($output, 'id="step-1-quotation-prepare"');
        $stage2Pos = strpos($output, "Step 4a &middot; Generate Buyer's PO</h2>");
        self::assertNotFalse($documentsPanelStart);
        self::assertNotFalse($stage2Pos);
        $panel = substr($output, $documentsPanelStart, $stage2Pos - $documentsPanelStart);

        self::assertStringContainsString('document_type" value="QT"', $panel, 'the Quotation generate form must still be the one deliberately kept in the top panel');
        foreach (['Generate Buyer PO</button>', 'Generate Proforma Invoice</button>', 'Generate Order Confirmation</button>', 'Generate Supplier PO</button>', 'Generate Debit Note</button>', 'Generate Packing List</button>', 'Generate Commercial Invoice</button>'] as $label) {
            self::assertStringNotContainsString($label, $panel, "{$label} must have moved out of the top Step 1 Prepare Quotation card");
        }
    }

    // ---------------------------------------------------------------
    // Section-order fixes: Communication -> Feedback -> Disputes,
    // Travel Expenses inside Order Financials
    // ---------------------------------------------------------------

    public function testOrderCommunicationRendersBeforeFeedbackWhichRendersBeforeDisputes(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->render($orderId);

        $communicationPos = strpos($output, '<h2>Order Communication</h2>');
        $feedbackPos = strpos($output, '<h2>Order Feedback</h2>');
        $disputesPos = strpos($output, '<h2>Disputes</h2>');

        self::assertNotFalse($communicationPos);
        self::assertNotFalse($feedbackPos);
        self::assertNotFalse($disputesPos);
        self::assertLessThan($feedbackPos, $communicationPos, 'Order Communication (step 15) must render before Order Feedback (step 16)');
        self::assertLessThan($disputesPos, $feedbackPos, 'Order Feedback (step 16) must render before Disputes (step 18)');
    }

    public function testTravelExpensesRendersInsideOrderFinancialsNextToLcAndBankLoan(): void
    {
        // The LC / bank loan block only renders for an LC-paid order.
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight');

        $output = $this->render($orderId);

        $financialsPos = strpos($output, 'id="order-financials"');
        $lcPos = strpos($output, 'Letter of Credit &amp; Bank Loan</h3>');
        $travelPos = strpos($output, 'Travel Expenses — Factory Visits');
        $otherCostsPos = strpos($output, 'Other Order Costs</h3>');
        $productionPos = strpos($output, '<h2>Step 8 &middot; Production &amp; Estimated Shipment</h2>');

        self::assertNotFalse($financialsPos);
        self::assertNotFalse($lcPos);
        self::assertNotFalse($travelPos);
        self::assertNotFalse($otherCostsPos);
        self::assertGreaterThan($financialsPos, $travelPos, 'Travel Expenses must render inside the Order Financials panel');
        self::assertGreaterThan($lcPos, $travelPos, 'Travel Expenses must render after Letter of Credit & Bank Loan');
        self::assertLessThan($otherCostsPos, $travelPos, 'Travel Expenses must render before Other Order Costs');
        self::assertLessThan($travelPos, $productionPos, 'Production & Estimated Shipment must render before Order Financials (and Travel Expenses within it) — Travel Expenses moved out of that section into Financials, not the other way round');
    }

    public function testOrderFinancialsRendersBeforeStage9Closure(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->render($orderId);

        $financialsPos = strpos($output, 'id="order-financials"');
        $stage9Pos = strpos($output, 'id="step-11a-coo-prep"');

        self::assertNotFalse($financialsPos);
        self::assertNotFalse($stage9Pos);
        self::assertLessThan($stage9Pos, $financialsPos, 'Order Financials must render before the Document Despatch & Closure steps — those steps\' own gates refer staff to it as "above"');
        self::assertStringNotContainsString('Order Financials below', $output, 'the stale "below" reference must be corrected now that Financials renders first');
    }

    public function testFreightPaymentIsAGreyedOutNonExpandableCardForFob(): void
    {
        $fob = $this->render($this->createTestOrder($this->createTestClient(), 'FOB'));
        self::assertStringContainsString('<div class="step-group step-group-disabled" id="step-group-stage-6" aria-disabled="true"', $fob);
        self::assertStringContainsString('Not applicable — FOB', $fob);
        self::assertStringNotContainsString('id="step-9a-freight-terms"', $fob, 'nothing to expand for FOB');

        $cif = $this->render($this->createTestOrder($this->createTestClient(), 'CIF'));
        self::assertStringContainsString('<details class="step-group" id="step-group-stage-6"', $cif);
        self::assertStringContainsString('id="step-9a-freight-terms"', $cif);
    }

    public function testLcAndBankLoanDetailsOnlyShowForAnLcPaymentPreset(): void
    {
        $cash = $this->render($this->createTestOrder($this->createTestClient()));
        self::assertStringNotContainsString('Letter of Credit &amp; Bank Loan</h3>', $cash);
        self::assertStringNotContainsString('Record LC / bank loan details for this order', $cash);

        $lc = $this->render($this->createTestOrder($this->createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight'));
        self::assertStringContainsString('Letter of Credit &amp; Bank Loan</h3>', $lc);
        self::assertStringContainsString('Record LC / bank loan details for this order', $lc);
    }
}
