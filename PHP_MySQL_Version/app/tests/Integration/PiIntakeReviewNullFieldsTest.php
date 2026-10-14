<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\PiIntakeReviewController;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog, P1 (#40): pi_intake_review/index.php passed nullable
 * submission fields (consignee_name and siblings) straight into
 * htmlspecialchars() without a (string) cast, which throws a PHP 8.1+
 * deprecation warning the moment any pending row has one of those columns
 * left null — the common case for a submission still missing optional
 * details. This renders a real pending row with every nullable field left
 * null and asserts no warning/deprecation notice fires.
 */
final class PiIntakeReviewNullFieldsTest extends DbTestCase
{
    public function testRendersPendingQueueWithoutWarningsWhenOptionalFieldsAreNull(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'             => $submission['company_legal_name'],
            'billing_address'                => $submission['billing_address'],
            'vat_eori_tax_no'                => $submission['vat_eori_tax_no'],
            'contact_person'                 => $submission['contact_person'],
            'email'                          => $submission['email'],
            'phone'                          => $submission['phone'],
            // The exact fields pi_intake_review/index.php renders —
            // deliberately left null, the common "client hasn't filled
            // this in yet" case.
            'port_of_discharge_text'         => null,
            'country_of_destination'         => $submission['country_of_destination'],
            'incoterm_confirmed'             => null,
            'container_type_text'            => null,
            'payment_terms_confirmation'     => null,
            'quotation_acceptance_reference' => null,
            'coo_type'                       => null,
            'buyer_po_ref'                   => null,
            'changes_from_quotation'         => null,
            'special_document_requirements'  => null,
        ], '127.0.0.1');
        // consignee_name/notify_party are set by the PI-details form's own
        // Consignee section, not submit() above — left at their
        // just-created-link default of NULL, exactly as a submission
        // where the client used "Same as Buyer" would have them.

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_DEPRECATED | E_NOTICE);

        ob_start();
        (new PiIntakeReviewController())->index([]);
        $output = ob_get_clean();

        restore_error_handler();

        self::assertSame([], $warnings, 'rendering a pending row with null optional fields must not raise any PHP warning/deprecation/notice');
        // Not asserting an exact "Pending (N)" count: other test classes in
        // this same PHPUnit process (the DB is rebuilt once per process,
        // not per test) may leave their own pending_review rows behind, so
        // the real count here is "at least this one", not necessarily 1.
        self::assertStringContainsString($submission['company_legal_name'], $output);
        self::assertStringNotContainsString('No pending submissions.', $output);
    }
}
