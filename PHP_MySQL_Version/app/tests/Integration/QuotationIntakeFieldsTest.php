<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientIntakeController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report U-05/U-06, both on the public /quotation-details form:
 *
 * U-05: on phones the field placeholders for VAT/EORI and Certificate of
 * Origin Type were long enough to get visually truncated ("UK: EORI No. |
 * Others: Tax Reg. N…", "GSP Form A (preferential) / Non-pr…"). The long
 * guidance now lives in a <small> hint under the field, with a short
 * placeholder in the input itself.
 *
 * U-06: Incoterm and Country were free text, producing inconsistent data
 * ("fob", "F.O.B", "UK", "United Kingdom" for the same thing). Country
 * (billing_country, country_of_destination, consignee_country) is now a
 * dropdown (with "Not sure" and an "Other — specify" escape hatch), the
 * same pattern already used for Incoterm.
 */
final class QuotationIntakeFieldsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testVatAndCooTypeHintsMovedOutOfThePlaceholderIntoHelpText(): void
    {
        ob_start();
        (new ClientIntakeController())->show([]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('placeholder="UK: EORI No.', $html, 'the long VAT/EORI guidance must no longer be crammed into the placeholder');
        self::assertStringContainsString('UK: EORI No. | Others: Tax Reg. No.', $html, 'the guidance must still be shown, just as help text now');
        self::assertStringNotContainsString('placeholder="GSP Form A (preferential)', $html, 'the long Certificate of Origin guidance must no longer be crammed into the placeholder');
        self::assertStringContainsString('GSP Form A (preferential) / Non-preferential', $html, 'the guidance must still be shown, just as help text now');
    }

    public function testBillingAndDestinationCountryAreDropdownsWithNotSureAndOtherOptions(): void
    {
        ob_start();
        (new ClientIntakeController())->show([]);
        $html = (string) ob_get_clean();

        foreach (['billing_country', 'country_of_destination', 'consignee_country'] as $field) {
            self::assertStringContainsString("id=\"{$field}_select\"", $html, "{$field} must be a dropdown, not free text");
            self::assertStringContainsString("name=\"{$field}\"", $html, "{$field} must still submit under its original field name");
        }
        self::assertStringContainsString('>Not sure<', $html);
        self::assertStringContainsString('Other (not listed) — specify', $html);
        self::assertStringContainsString('>United Kingdom<', $html, 'the seeded country list must actually be rendered as options');
    }

    public function testSubmittingAChosenCountryStillSavesCorrectly(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_POST = [
            'company_legal_name'     => 'Country Dropdown Test Co',
            'billing_address_line1'  => '1 Test Street',
            'billing_city'           => 'Testville',
            'billing_country'        => 'United Kingdom',
            'vat_eori_tax_no'        => 'GB123456789',
            'contact_person'         => 'Jane Test',
            'email'                  => 'jane-' . bin2hex(random_bytes(4)) . '@example.test',
            'country_of_destination' => 'Not sure',
            'incoterm_preference'    => 'FOB',
        ];

        ob_start();
        (new ClientIntakeController())->submit([]);
        ob_end_clean();

        $row = Database::connection()->query(
            'SELECT billing_country, country_of_destination FROM client_intake_submissions ORDER BY id DESC LIMIT 1'
        )->fetch();

        self::assertSame('United Kingdom', $row['billing_country']);
        self::assertSame('Not sure', $row['country_of_destination']);
    }
}
