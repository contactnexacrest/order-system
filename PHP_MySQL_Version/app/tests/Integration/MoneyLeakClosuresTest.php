<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Services\AuditMoneyMask;
use App\Services\DocumentGenerationService;
use App\Services\MoneyVisibility;
use App\Tests\Support\DbTestCase;

/**
 * Owner points 6 / 15 (2 Nov 2026): "only permitted people see amounts — not
 * a single leak". Closes the gaps the review found:
 *  - a generated copy that is no longer a document's current PDF (the draft
 *    after approval, the final after a supersede, an older CAFIN) kept no
 *    document type, so no money class, so anyone on the order could download it;
 *  - the audit log, an order's audit page and the per-order report's Full
 *    Audit Trail printed amounts to anyone allowed to read them;
 *  - money reports opened for any view_reports holder.
 * The whole-app proof is e2e/tests/12-money-matrix.spec.js. Node twin:
 * moneyLeakClosures.test.js.
 */
final class MoneyLeakClosuresTest extends DbTestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
    }

    private function generatedFile(int $orderId): int
    {
        $id = $this->createTestFile($orderId);
        \App\Config\Database::connection()->prepare("UPDATE file_store SET file_origin = 'GENERATED_AUTO' WHERE id = :id")->execute(['id' => $id]);
        return $id;
    }

    public function testEveryGeneratedCopyKeepsItsDocumentsMoneyClass(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $draft = $this->generatedFile($orderId);
        $final = $this->generatedFile($orderId);
        $superseded = $this->generatedFile($orderId);
        $docId = DocumentRepository::create($orderId, (int) DocumentGenerationService::documentTypeIdFor('SUPPO'), 'SC/SPO/26/LEAK1', 0, $draft, null, null);

        DocumentRepository::markApproved($docId, $final);
        // The draft is no longer the document's PDF — it must still be a Supplier PO file.
        self::assertSame(MoneyVisibility::PURCHASE, FileStoreRepository::moneyClassFor($draft));
        self::assertSame(MoneyVisibility::PURCHASE, FileStoreRepository::moneyClassFor($final));

        DocumentRepository::markSuperseded($docId, $superseded);
        self::assertSame(MoneyVisibility::PURCHASE, FileStoreRepository::moneyClassFor($final), 'the sent final, after a supersede');
        self::assertSame(MoneyVisibility::PURCHASE, FileStoreRepository::moneyClassFor($superseded));

        // An older CAFIN copy stays CAFIN: refused without view_cafin, out of the dossier.
        $oldCafin = $this->generatedFile($orderId);
        $newCafin = $this->generatedFile($orderId);
        $cafinType = (int) DocumentGenerationService::documentTypeIdFor('CAFIN');
        DocumentRepository::create($orderId, $cafinType, 'SC/CAFIN/26/LEAK1', 0, $oldCafin, null, null);
        DocumentRepository::create($orderId, $cafinType, 'SC/CAFIN/26/LEAK1', 1, $newCafin, null, null);
        self::assertSame('CAFIN', FileStoreRepository::documentTypeCodeFor($oldCafin));
        $dossierIds = array_map(static fn (array $f): int => (int) $f['id'], FileStoreRepository::forOrderExcludingInternalCaDocs($orderId));
        self::assertNotContains($oldCafin, $dossierIds);
        self::assertNotContains($newCafin, $dossierIds);
        self::assertContains($draft, $dossierIds, 'other files still go in the dossier (gated per person)');
    }

    public function testAuditRowsAreClassifiedByTheMoneyTheyCarry(): void
    {
        $cases = [
            ['ADVANCE_PAYMENT_RECORDED', 'order_payment_status', 'advance_amount', 'buyer_payments'],
            ['CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', 'advance_exchange_rate', 'buyer_payments'],
            ['SUPPLIER_BILL_ADDED', 'order_vendor_bills', 'bill_no', 'purchase'],
            ['LOGISTICS_BILL_ADDED', 'order_vendor_bills', 'bill_no', 'logistics'],
            ['ORDER_EXPENSE_ADDED', 'order_expenses', 'amount_inr', 'financials'],
            ['ORDER_INCENTIVE_SET', 'orders', null, 'incentives'],
            ['INCENTIVE_PAID', 'incentive_payouts', null, 'payouts'],
            ['FIELD_EDIT', 'orders', 'unit_price', 'selling'],
            ['FIELD_EDIT', 'orders', 'port_of_discharge_text', null],
            ['LOGIN_SUCCESS', 'users', null, null],
        ];
        foreach ($cases as [$action, $entity, $field, $class]) {
            self::assertSame($class, AuditMoneyMask::classFor(['action_type' => $action, 'entity_type' => $entity, 'entity_id' => 0, 'field_name' => $field]), $action);
        }
    }

    public function testAuditValuesAreHiddenFromPeopleWhoMayNotSeeThatMoney(): void
    {
        $row = ['action_type' => 'ADVANCE_PAYMENT_RECORDED', 'entity_type' => 'order_payment_status', 'entity_id' => 1, 'field_name' => 'advance_amount',
            'old_value' => null, 'new_value' => '823.61', 'reason' => 'Received 823.61 less bank charges'];
        $logistics = ['action_type' => 'LOGISTICS_BILL_ADDED', 'entity_type' => 'orders', 'entity_id' => 1, 'field_name' => 'taxable_inr', 'old_value' => null, 'new_value' => '649.17', 'reason' => null];

        $this->signIn($this->createTestUser('Logistics Executive'));
        $masked = AuditMoneyMask::mask($row);
        self::assertSame(AuditMoneyMask::HIDDEN, $masked['new_value']);
        self::assertStringNotContainsString('823', (string) $masked['reason']);
        self::assertSame('649.17', AuditMoneyMask::mask($logistics)['new_value'], 'their own kind of money stays visible');

        $this->signIn($this->createTestUser('System Administrator'));
        self::assertSame(AuditMoneyMask::HIDDEN, AuditMoneyMask::mask($logistics)['new_value'], 'System Administrator: no business money');

        $this->signIn($this->createTestUser('Accounts Executive'));
        self::assertSame('823.61', AuditMoneyMask::mask($row)['new_value']);
    }

    public function testMoneyReportsRefusePeopleWhoMayNotSeeThatMoney(): void
    {
        $this->signIn($this->createTestUser('Logistics Executive'));
        foreach (['aggregate', 'payments', 'trends', 'ageing', 'products', 'performance', 'amendments', 'todaysPayments'] as $action) {
            http_response_code(200);
            ob_start();
            (new \App\Controllers\ReportController())->{$action}([]);
            $body = (string) ob_get_clean();
            self::assertSame(403, http_response_code(), $action);
            self::assertStringContainsString('You do not have permission to see the amounts in this report.', $body, $action);
        }
    }
}
