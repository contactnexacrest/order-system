<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\DocumentDataAssembler;
use PHPUnit\Framework\TestCase;

/**
 * Buyer/Consignee/Notify Party split — pure functions, no DB needed.
 * Covers the "same as" resolution (always fresh off the parent party,
 * never a stale copy) and the admin-editable balance-trigger wording
 * fallback.
 */
final class DocumentDataAssemblerPartySplitTest extends TestCase
{
    private function buyerOrder(array $overrides = []): array
    {
        return array_merge([
            'company_legal_name'    => 'Buyer Co Ltd',
            'billing_address'       => 'Fallback Address, City',
            'billing_address_line1' => '1 Buyer Street',
            'billing_address_line2' => 'Suite 2',
            'billing_city'          => 'Buyer City',
            'billing_postcode'      => 'BC123',
            'country_of_destination' => 'Germany',
            'vat_eori_tax_no'       => 'DE123456789',
            'contact_person'        => 'Buyer Contact',
            'client_phone'          => '+49 111',
            'client_email'          => 'buyer@example.com',
        ], $overrides);
    }

    public function testResolveConsigneeDefaultsToSameAsBuyerWhenFlagUndefined(): void
    {
        $result = DocumentDataAssembler::resolveConsignee($this->buyerOrder());
        self::assertTrue($result['same_as_buyer']);
        self::assertSame('Buyer Co Ltd', $result['company_legal_name']);
        self::assertSame('1 Buyer Street', $result['address_line1']);
        self::assertSame('buyer@example.com', $result['email']);
    }

    public function testResolveConsigneeFallsBackToBillingAddressWhenLine1Blank(): void
    {
        $result = DocumentDataAssembler::resolveConsignee($this->buyerOrder(['billing_address_line1' => null]));
        self::assertSame('Fallback Address, City', $result['address_line1']);
    }

    public function testResolveConsigneeUsesOwnColumnsWhenNotSameAsBuyer(): void
    {
        $order = $this->buyerOrder([
            'consignee_same_as_buyer'   => 0,
            'consignee_name'            => 'Consignee Co',
            'consignee_address_line1'   => '9 Consignee Road',
            'consignee_city'            => 'Consignee City',
            'consignee_country'         => 'France',
            'consignee_email'           => 'consignee@example.com',
        ]);
        $result = DocumentDataAssembler::resolveConsignee($order);

        self::assertFalse($result['same_as_buyer']);
        self::assertSame('Consignee Co', $result['company_legal_name']);
        self::assertSame('9 Consignee Road', $result['address_line1']);
        self::assertSame('France', $result['country']);
        self::assertSame('consignee@example.com', $result['email']);
    }

    private function resolvedConsignee(): array
    {
        return [
            'company_legal_name' => 'Consignee Co',
            'address_line1'      => '9 Consignee Road',
            'address_line2'      => null,
            'city'                => 'Consignee City',
            'postcode'            => 'CC456',
            'country'             => 'France',
            'contact_person'      => 'Consignee Contact',
            'phone'               => '+33 222',
            'email'               => 'consignee@example.com',
        ];
    }

    public function testResolveNotifyPartyDefaultsToSameAsConsigneeAndMirrorsIt(): void
    {
        $result = DocumentDataAssembler::resolveNotifyParty([], $this->resolvedConsignee());
        self::assertTrue($result['same_as_consignee']);
        self::assertSame('Consignee Co', $result['name']);
        self::assertSame('consignee@example.com', $result['email']);
    }

    public function testResolveNotifyPartyUsesOwnColumnsWhenNotSameAsConsignee(): void
    {
        $order = [
            'notify_party_same_as_consignee' => 0,
            'notify_party'                   => 'Freight Forwarder Ltd',
            'notify_party_address_line1'     => '5 Forwarder Lane',
            'notify_party_country'           => 'Netherlands',
            'notify_party_email'             => 'forwarder@example.com',
        ];
        $result = DocumentDataAssembler::resolveNotifyParty($order, $this->resolvedConsignee());

        self::assertFalse($result['same_as_consignee']);
        self::assertSame('Freight Forwarder Ltd', $result['name']);
        self::assertSame('5 Forwarder Lane', $result['address_line1']);
        self::assertSame('Netherlands', $result['country']);
        self::assertSame('forwarder@example.com', $result['email']);
    }

    public function testBalanceTriggerSentenceUsesBuiltInABeforeShipmentSentenceWithCalendarDays(): void
    {
        $text = DocumentDataAssembler::balanceTriggerSentence('A_BEFORE_SHIPMENT', 3, null);
        self::assertSame(
            'Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.',
            $text
        );
    }

    public function testBalanceTriggerSentenceUsesBuiltInBAgainstBlSentenceNamingBlEmailDate(): void
    {
        $text = DocumentDataAssembler::balanceTriggerSentence('B_AGAINST_BL', 7, null);
        self::assertSame(
            'Payable against scanned copy of Bill of Lading, within 7 Calendar Days of the date NexaCrest emails the scanned BL copy.',
            $text
        );
    }

    public function testBalanceTriggerSentenceSubstitutesDaysIntoPresetWordingRegardlessOfOption(): void
    {
        $text = DocumentDataAssembler::balanceTriggerSentence(
            'A_BEFORE_SHIPMENT', 5, 'Custom: within {days} Calendar Days of {days}-day notice.'
        );
        self::assertSame('Custom: within 5 Calendar Days of 5-day notice.', $text);
    }

    public function testBalanceTriggerSentenceIgnoresBlankWordingTemplate(): void
    {
        $text = DocumentDataAssembler::balanceTriggerSentence('A_BEFORE_SHIPMENT', 3, '   ');
        self::assertSame(
            'Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.',
            $text
        );
    }
}
