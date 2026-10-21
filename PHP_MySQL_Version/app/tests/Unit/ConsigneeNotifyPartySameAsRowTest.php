<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * User-reported batch item 17: "Same as Buyer?" / "Same as Consignee?" is
 * an application-level data-entry option (the checkbox on the admin
 * Clients form / client-facing intake forms that decides HOW the
 * Consignee/Notify Party fields resolve) — it must never be printed as a
 * row inside a buyer-facing document itself. The resolved field values
 * (Company Legal Name, Address, etc.) still print as normal; only the
 * "Same as X?" status row is removed from `_consignee_details.html.twig`
 * and `_notify_party.html.twig`.
 */
final class ConsigneeNotifyPartySameAsRowTest extends TestCase
{
    private function twigEnvironment(): TwigEnvironment
    {
        return new TwigEnvironment(new TwigFilesystemLoader(__DIR__ . '/../../templates'), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    public function testConsigneeDetailsPartialNeverPrintsSameAsBuyerRowEvenWhenTrue(): void
    {
        $html = $this->twigEnvironment()->render('_consignee_details.html.twig', [
            'section_number' => 5,
            'consignee' => [
                'same_as_buyer' => true,
                'company_legal_name' => 'Acme Importers Ltd',
                'address_line1' => '1 Buyer Street',
                'address_line2' => null,
                'city' => 'London',
                'postcode' => 'EC1A 1AA',
                'country' => 'United Kingdom',
                'vat_eori_tax_no' => 'GB123456789',
                'contact_person' => 'Jane Buyer',
                'phone' => '+44 20 7946 0000',
                'email' => 'jane@acme.example',
            ],
        ]);

        self::assertStringNotContainsString('Same as Buyer?', $html);
        self::assertStringContainsString('Acme Importers Ltd', $html);
        self::assertStringContainsString('5. CONSIGNEE DETAILS', $html);
    }

    public function testConsigneeDetailsPartialNeverPrintsSameAsBuyerRowWhenFalse(): void
    {
        $html = $this->twigEnvironment()->render('_consignee_details.html.twig', [
            'section_number' => 5,
            'consignee' => [
                'same_as_buyer' => false,
                'company_legal_name' => 'Independent Consignee Co',
                'address_line1' => '2 Dock Road',
                'address_line2' => null,
                'city' => 'Rotterdam',
                'postcode' => '3000AA',
                'country' => 'Netherlands',
                'vat_eori_tax_no' => 'NL123456789B01',
                'contact_person' => 'Pieter Consignee',
                'phone' => '+31 10 000 0000',
                'email' => 'pieter@independent.example',
            ],
        ]);

        self::assertStringNotContainsString('Same as Buyer?', $html);
        self::assertStringContainsString('Independent Consignee Co', $html);
    }

    public function testNotifyPartyPartialNeverPrintsSameAsConsigneeRowEvenWhenTrue(): void
    {
        $html = $this->twigEnvironment()->render('_notify_party.html.twig', [
            'section_number' => 6,
            'notify_party_block' => [
                'same_as_consignee' => true,
                'name' => 'Acme Importers Ltd',
                'address_line1' => '1 Buyer Street',
                'address_line2' => null,
                'city' => 'London',
                'postcode' => 'EC1A 1AA',
                'country' => 'United Kingdom',
                'contact_person' => 'Jane Buyer',
                'phone' => '+44 20 7946 0000',
                'email' => 'jane@acme.example',
            ],
        ]);

        self::assertStringNotContainsString('Same as Consignee?', $html);
        self::assertStringContainsString('Acme Importers Ltd', $html);
        self::assertStringContainsString('6. NOTIFY PARTY', $html);
    }

    public function testNotifyPartyPartialNeverPrintsSameAsConsigneeRowWhenFalse(): void
    {
        $html = $this->twigEnvironment()->render('_notify_party.html.twig', [
            'section_number' => 6,
            'notify_party_block' => [
                'same_as_consignee' => false,
                'name' => 'Freight Forwarders Inc',
                'address_line1' => '9 Harbour Way',
                'address_line2' => null,
                'city' => 'Hamburg',
                'postcode' => '20095',
                'country' => 'Germany',
                'contact_person' => 'Hans Forwarder',
                'phone' => '+49 40 000000',
                'email' => 'hans@forwarder.example',
            ],
        ]);

        self::assertStringNotContainsString('Same as Consignee?', $html);
        self::assertStringContainsString('Freight Forwarders Inc', $html);
    }
}
