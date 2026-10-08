<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * Sidebar nav badges for Quotation Intake Review / PI Intake Review
 * (layout/base.php) — previously the two links gave no indication a
 * submission was waiting, so staff had to click in just to find out.
 * pendingCount()/pendingReviewCount() back those badges with a live
 * COUNT, so the number appears the moment a submission is filed and
 * drops the moment it's accepted or rejected — no caching, nothing to
 * manually decrement.
 */
final class IntakeReviewPendingCountTest extends DbTestCase
{
    private function createClientIntakeSubmission(): int
    {
        return ClientIntakeRepository::create([
            'company_legal_name'     => 'Badge Test Buyer Ltd',
            'billing_address'        => '1 Badge Street',
            'billing_address_line1'  => null,
            'billing_address_line2'  => null,
            'billing_city'           => null,
            'billing_postcode'       => null,
            'billing_country'        => null,
            'vat_eori_tax_no'        => null,
            'contact_person'         => 'Jane Buyer',
            'email'                  => 'jane@badge-test.example',
            'phone'                  => null,
            'country_of_destination' => 'Netherlands',
            'port_of_discharge_text' => null,
            'coo_type'               => null,
            'incoterm_preference'    => null,
            'container_type_text'    => null,
            'buyer_own_reference'    => null,
            'notes'                  => null,
        ], '127.0.0.1');
    }

    public function testClientIntakePendingCountReflectsOnlyPendingRows(): void
    {
        $before = ClientIntakeRepository::pendingCount();

        $pendingId = $this->createClientIntakeSubmission();
        self::assertSame($before + 1, ClientIntakeRepository::pendingCount(), 'a freshly submitted request must appear in the count immediately');

        $toRejectId = $this->createClientIntakeSubmission();
        self::assertSame($before + 2, ClientIntakeRepository::pendingCount());

        ClientIntakeRepository::markRejected($toRejectId, $this->createTestUser('Admin'), 'Not a fit');
        self::assertSame($before + 1, ClientIntakeRepository::pendingCount(), 'rejecting a request must decrement the count immediately');

        $clientId = $this->createTestClient();
        ClientIntakeRepository::markConverted($pendingId, $clientId, $this->createTestUser('Admin'));
        self::assertSame($before, ClientIntakeRepository::pendingCount(), 'accepting the last pending request must bring the count back to its baseline');
    }

    public function testPiIntakeReviewPendingCountReflectsOnlyPendingReviewRows(): void
    {
        $before = PiIntakeRepository::pendingReviewCount();

        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        // A fresh link alone (status default) isn't a standing request yet
        // — only submit() (the client actually filling the form) flips it
        // to pending_review, which is what the badge counts.
        self::assertSame($before, PiIntakeRepository::pendingReviewCount());

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'            => $submission['company_legal_name'],
            'billing_address'               => $submission['billing_address'],
            'vat_eori_tax_no'               => $submission['vat_eori_tax_no'],
            'contact_person'                => $submission['contact_person'],
            'email'                         => $submission['email'],
            'phone'                         => $submission['phone'],
            'port_of_discharge_text'        => $submission['port_of_discharge_text'],
            'country_of_destination'        => $submission['country_of_destination'],
            'incoterm_confirmed'            => $submission['incoterm_confirmed'],
            'container_type_text'           => $submission['container_type_text'],
            'payment_terms_confirmation'    => 'CONFIRMED',
            'quotation_acceptance_reference' => 'We accept Quotation X',
            'coo_type'                      => $submission['coo_type'],
            'buyer_po_ref'                  => null,
            'changes_from_quotation'        => null,
            'special_document_requirements' => null,
        ], '127.0.0.1');

        self::assertSame($before + 1, PiIntakeRepository::pendingReviewCount(), 'a client-submitted PI-stage form must appear in the count immediately');

        PiIntakeRepository::markRejected((int) $submission['id'], $this->createTestUser('Admin'), 'Resubmit with correct VAT');
        self::assertSame($before, PiIntakeRepository::pendingReviewCount(), 'rejecting the submission must decrement the count immediately');
    }
}
