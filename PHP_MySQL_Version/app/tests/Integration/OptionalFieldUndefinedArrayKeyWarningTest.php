<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ClientIntakeRepository;
use App\Repositories\OrderPackingRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report R-14: "PHP 'Undefined array key' warnings in
 * OrderShippingRepository (etd, eta, container_count),
 * ClientIntakeRepository (billing_*), PiIntakeRepository (billing_*),
 * OrderPackingRepository (crate_count, weights, cbm, packing_date,
 * shortfall_pct) when optional fields are absent." All of these used
 * `$data['x'] ?: null` — unlike `??`, the `?:` (Elvis) operator still
 * evaluates its left side first, so it warns exactly like a bare
 * `$data['x']` would when the key is missing from the submitted form
 * (as opposed to merely being empty/null). Fixed by reading through
 * `?? null` first in every case, which preserves the exact same
 * "falsy/absent -> null" behavior without ever warning.
 */
final class OptionalFieldUndefinedArrayKeyWarningTest extends DbTestCase
{
    /** @return array<int,string> */
    private function captureWarnings(callable $fn): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_DEPRECATED | E_NOTICE);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $warnings;
    }

    public function testOrderShippingRepositoryUpsertWithOnlyRequiredKeys(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $warnings = $this->captureWarnings(function () use ($orderId) {
            // Deliberately omitting etd/eta/container_count — the exact
            // "optional field left blank on the form" case.
            OrderShippingRepository::upsert($orderId, [
                'shipping_line' => 'Test Shipping Line',
            ]);
        });

        self::assertSame([], $warnings);
    }

    public function testOrderPackingRepositoryUpsertWithOnlyRequiredKeys(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $warnings = $this->captureWarnings(function () use ($orderId) {
            OrderPackingRepository::upsert($orderId, []);
        });

        self::assertSame([], $warnings);
    }

    public function testClientIntakeRepositoryCreateWithOnlyRequiredKeys(): void
    {
        $warnings = $this->captureWarnings(function () {
            ClientIntakeRepository::create([
                'company_legal_name'     => 'R-14 Test Co',
                'billing_address'        => '1 Test Street',
                'contact_person'         => 'Jane Test',
                'email'                  => 'r14-test@example.com',
                'country_of_destination' => 'United Kingdom',
            ], '127.0.0.1');
        });

        self::assertSame([], $warnings);
    }

    public function testClientIntakeRepositoryUpdateFromClientWithOnlyRequiredKeys(): void
    {
        $id = ClientIntakeRepository::create([
            'company_legal_name'     => 'R-14 Test Co',
            'billing_address'        => '1 Test Street',
            'contact_person'         => 'Jane Test',
            'email'                  => 'r14-test@example.com',
            'country_of_destination' => 'United Kingdom',
        ], '127.0.0.1');

        $warnings = $this->captureWarnings(function () use ($id) {
            ClientIntakeRepository::updateFromClient($id, [
                'company_legal_name'     => 'R-14 Test Co (updated)',
                'billing_address'        => '2 Test Street',
                'contact_person'         => 'Jane Test',
                'email'                  => 'r14-test@example.com',
                'country_of_destination' => 'United Kingdom',
            ]);
        });

        self::assertSame([], $warnings);
    }

    public function testPiIntakeRepositorySubmitWithOnlyRequiredKeys(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        $warnings = $this->captureWarnings(function () use ($submission) {
            PiIntakeRepository::submit((int) $submission['id'], [
                'company_legal_name'             => 'R-14 Test Co',
                'billing_address'                 => '1 Test Street',
                'vat_eori_tax_no'                 => 'GB123456789',
                'contact_person'                  => 'Jane Test',
                'email'                           => 'r14-test@example.com',
                'phone'                           => '+44 20 1234 5678',
                'port_of_discharge_text'          => 'Felixstowe',
                'country_of_destination'          => 'United Kingdom',
                'incoterm_confirmed'              => 'FOB',
                'payment_terms_confirmation'       => 'Confirmed',
                'quotation_acceptance_reference'   => 'QT-REF-1',
                'coo_type'                        => 'To Be Confirmed',
                // billing_address_line1/2, billing_city, billing_postcode,
                // billing_country, container_type_text, buyer_po_ref,
                // changes_from_quotation, special_document_requirements,
                // and every consignee_*/notify_party_* key deliberately
                // omitted — the exact "optional field left blank" case.
            ], '127.0.0.1');
        });

        self::assertSame([], $warnings);
    }
}
