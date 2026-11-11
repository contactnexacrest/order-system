<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\OrderRepository;
use App\Services\AssociatePayoutService;
use App\Services\BuyerActionService;
use App\Services\ClientPortalService;
use App\Services\EmailDispatchService;
use App\Services\PaymentActionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-330 (11 Nov 2026, "23 OK", confirmed twice): plain words on every
 * screen and e-mail a buyer, an associate or a supplier sees. Exact wording, a
 * representative set per audience, and the guarded e-mail-template SQL (it updates a
 * template only while its body is still the old seeded text). Node twin:
 * tests/integration/plainLanguageD330.test.js.
 */
final class PlainLanguageD330Test extends DbTestCase
{
    private const SQL = __DIR__ . '/../fixtures/d330_plain_language_email_templates.sql';

    // ---- Buyer --------------------------------------------------------------------------

    public function testTheBuyerSeesTheStageInBuyerWords(): void
    {
        $stage = static fn (string $status, int $n): string => AssociatePortalRepository::stageLabel(['status' => $status, 'current_stage_number' => $n]);
        self::assertSame(
            ['Quotation', 'Order confirmed', 'Order confirmed', 'In production', 'In production', 'Shipping', 'Shipping', 'Shipping', 'Shipped', 'Shipped', 'Cancelled', 'Did not go ahead'],
            [$stage('active', 1), $stage('active', 2), $stage('active', 3), $stage('active', 4), $stage('active', 5), $stage('active', 6),
             $stage('active', 7), $stage('active', 8), $stage('active', 9), $stage('complete', 3), $stage('cancelled', 4), $stage('lost', 2)]
        );
    }

    public function testABuyerNeverReadsTheWordLost(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("UPDATE orders SET status = 'lost' WHERE id = :id")->execute(['id' => $orderId]);
        $order = OrderRepository::find($orderId);
        self::assertSame('This order did not go ahead, so there is no payment to tell us about.', ClientPortalService::paymentReportRefusal($order));
        self::assertSame('This order did not go ahead, so you can no longer upload a purchase order. If you have a question, please contact your NexaCrest contact person.',
            BuyerActionService::uploadBuyerPo($order)['message']);

        Database::connection()->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id")->execute(['id' => $orderId]);
        self::assertSame('This order was cancelled, so there is no payment to tell us about.', ClientPortalService::paymentReportRefusal(OrderRepository::find($orderId)));
    }

    public function testThePaymentLinkWords(): void
    {
        self::assertSame(['doc' => 'FDN', 'label' => 'Freight and insurance', 'document' => 'freight bill (debit note)'], PaymentActionService::LEGS['freight']);
        self::assertStringStartsWith('PAYMENT DETAILS: once you have paid, please send us a screenshot of the payment and its details (bank reference number, amount and date) on this secure link. You do not need to sign in:',
            EmailDispatchService::PAYMENT_LINK_PARAGRAPH);
    }

    // ---- Associate ----------------------------------------------------------------------

    public function testTheAssociateCommissionWords(): void
    {
        self::assertSame('We will pay you ₹104,312.50 (at ₹83.45 per USD, set on 12 Nov 2026; this will not change)',
            AssociatePayoutService::fixedRupeeLine(104312.50, 83.45, 'USD', '2026-11-12 10:00:00'));
        self::assertSame('How we set your commission — it does not change later', AssociatePayoutService::howFixedBox(['based_in' => 'india'])['title']);
    }

    public function testTheAssociateClientWords(): void
    {
        self::assertSame('Changed by NexaCrest', AssociatePortalRepository::cameVia(['associate_source' => 'md_change']));
        self::assertSame('Linked by NexaCrest', AssociatePortalRepository::cameVia(['associate_source' => 'staff']));
    }

    // ---- The guarded e-mail-template SQL -------------------------------------------------

    public function testTheTemplateSqlUpdatesOnlyAnUneditedTemplateAndIsSafeToRunTwice(): void
    {
        $sql = (string) file_get_contents(self::SQL);
        self::assertMatchesRegularExpression("/\\n-- payment_followup\\n/", $sql);
        $row = Database::connection()->query("SELECT subject, body FROM email_templates WHERE template_key = 'payment_followup'")->fetch();
        try {
            // Put the template back to the text seeded before D-330 (taken from the SQL's own guard).
            self::assertSame(1, preg_match("/WHERE template_key = 'payment_followup'\\n   AND body = ('(?:[^']|'')*');/", $sql, $m));
            Database::connection()->exec("UPDATE email_templates SET subject = 'Payment Follow-Up — Order {order_reference}', body = {$m[1]} WHERE template_key = 'payment_followup'");

            $this->runSql($sql);
            $new = Database::connection()->query("SELECT body FROM email_templates WHERE template_key = 'payment_followup'")->fetchColumn();
            self::assertStringContainsString('A payment on your order {order_reference} (your enquiry reference: {buyer_inquiry_ref}) is still open. Please make the payment as soon as you can and send us the bank receipt, so we can continue with your order.', (string) $new);

            $this->runSql($sql); // a second run changes nothing
            self::assertSame($new, Database::connection()->query("SELECT body FROM email_templates WHERE template_key = 'payment_followup'")->fetchColumn());

            // An admin's own wording is never overwritten.
            Database::connection()->exec("UPDATE email_templates SET body = 'Our own follow-up text.' WHERE template_key = 'payment_followup'");
            $this->runSql($sql);
            self::assertSame('Our own follow-up text.', Database::connection()->query("SELECT body FROM email_templates WHERE template_key = 'payment_followup'")->fetchColumn());
        } finally {
            Database::connection()->prepare("UPDATE email_templates SET subject = :s, body = :b WHERE template_key = 'payment_followup'")
                ->execute(['s' => $row['subject'], 'b' => $row['body']]);
        }
    }

    private function runSql(string $sql): void
    {
        foreach (preg_split("/;\\n/", $sql) as $statement) {
            $lines = array_filter(explode("\n", $statement), static fn (string $l): bool => !str_starts_with(ltrim($l), '--'));
            $statement = trim(implode("\n", $lines));
            if ($statement !== '') {
                Database::connection()->exec($statement);
            }
        }
    }
}
