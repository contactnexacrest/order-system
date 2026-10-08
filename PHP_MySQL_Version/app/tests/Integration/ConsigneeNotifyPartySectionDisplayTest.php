<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CompanySettingsRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Covers the "always show CONSIGNEE DETAILS / NOTIFY PARTY" company
 * settings and the dynamic section renumbering that replaced the old
 * hardcoded section-number literals once those two sections became
 * conditionally hidden. The hard rule under test: a section identical to
 * the Buyer is only hidden when BOTH (a) its own "always show" setting is
 * off AND (b) it's genuinely the same as the Buyer — a section that is
 * actually different from the Buyer always prints, regardless of the
 * setting.
 */
final class ConsigneeNotifyPartySectionDisplayTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CompanySettingsRepository::set('always_show_consignee_section', '1', null);
        CompanySettingsRepository::set('always_show_notify_party_section', '1', null);
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('always_show_consignee_section', '1', null);
        CompanySettingsRepository::set('always_show_notify_party_section', '1', null);
        parent::tearDown();
    }

    private function sectionDisplayFlags(array $resolvedConsignee, array $resolvedNotifyParty): array
    {
        $method = new \ReflectionMethod(DocumentDataAssembler::class, 'sectionDisplayFlags');
        $method->setAccessible(true);
        return $method->invoke(null, $resolvedConsignee, $resolvedNotifyParty);
    }

    private function sectionNumbers(string $code, bool $showConsignee, bool $showNotify): array
    {
        $method = new \ReflectionMethod(DocumentGenerationService::class, 'sectionNumbers');
        $method->setAccessible(true);
        return $method->invoke(null, $code, $showConsignee, $showNotify);
    }

    // --- sectionDisplayFlags() ---------------------------------------

    public function testConsigneeHiddenWhenSameAsBuyerAndSettingDisabled(): void
    {
        CompanySettingsRepository::set('always_show_consignee_section', '0', null);
        [$showConsignee, ] = $this->sectionDisplayFlags(
            ['same_as_buyer' => true],
            ['same_as_consignee' => true]
        );
        self::assertFalse($showConsignee);
    }

    public function testConsigneeShownWhenSameAsBuyerButSettingEnabled(): void
    {
        CompanySettingsRepository::set('always_show_consignee_section', '1', null);
        [$showConsignee, ] = $this->sectionDisplayFlags(
            ['same_as_buyer' => true],
            ['same_as_consignee' => true]
        );
        self::assertTrue($showConsignee);
    }

    public function testConsigneeAlwaysShownWhenGenuinelyDifferentFromBuyerEvenIfSettingDisabled(): void
    {
        CompanySettingsRepository::set('always_show_consignee_section', '0', null);
        [$showConsignee, ] = $this->sectionDisplayFlags(
            ['same_as_buyer' => false],
            ['same_as_consignee' => true]
        );
        self::assertTrue($showConsignee);
    }

    public function testNotifyHiddenWhenEffectivelySameAsBuyerAndSettingDisabled(): void
    {
        CompanySettingsRepository::set('always_show_notify_party_section', '0', null);
        [, $showNotify] = $this->sectionDisplayFlags(
            ['same_as_buyer' => true],
            ['same_as_consignee' => true]
        );
        self::assertFalse($showNotify);
    }

    public function testNotifyShownWhenEffectivelySameAsBuyerButSettingEnabled(): void
    {
        CompanySettingsRepository::set('always_show_notify_party_section', '1', null);
        [, $showNotify] = $this->sectionDisplayFlags(
            ['same_as_buyer' => true],
            ['same_as_consignee' => true]
        );
        self::assertTrue($showNotify);
    }

    /**
     * The chain-break case: Notify resolves same_as_consignee=true, but the
     * Consignee itself is NOT the same as the Buyer — so Notify's printed
     * content is actually the (independent) Consignee's content, not the
     * Buyer's. Must always show regardless of the setting.
     */
    public function testNotifyAlwaysShownWhenConsigneeLinkBreaksTheChainToBuyerEvenIfSettingDisabled(): void
    {
        CompanySettingsRepository::set('always_show_notify_party_section', '0', null);
        [, $showNotify] = $this->sectionDisplayFlags(
            ['same_as_buyer' => false],
            ['same_as_consignee' => true]
        );
        self::assertTrue($showNotify);
    }

    public function testNotifyAlwaysShownWhenNotifyItselfIsIndependentOfConsigneeEvenIfSettingDisabled(): void
    {
        CompanySettingsRepository::set('always_show_notify_party_section', '0', null);
        [, $showNotify] = $this->sectionDisplayFlags(
            ['same_as_buyer' => true],
            ['same_as_consignee' => false]
        );
        self::assertTrue($showNotify);
    }

    // --- sectionNumbers() ---------------------------------------------

    public function testQtNumbersUnchangedFromHistoricalFixedValuesWhenBothSectionsShown(): void
    {
        $numbers = $this->sectionNumbers('QT', true, true);
        self::assertSame(2, $numbers['buyer']);
        self::assertSame(3, $numbers['consignee']);
        self::assertArrayNotHasKey('notify_party', $numbers); // QT never has one
        self::assertSame(4, $numbers['product']);
        self::assertSame(5, $numbers['shipping']);
        self::assertSame(6, $numbers['payment']);
        self::assertSame(7, $numbers['documents_provided']);
        self::assertSame(8, $numbers['terms']);
        self::assertSame(9, $numbers['legal_terms']);
    }

    public function testQtNumbersShiftDownByOneWhenConsigneeHidden(): void
    {
        $numbers = $this->sectionNumbers('QT', false, true);
        self::assertSame(2, $numbers['buyer']);
        self::assertArrayNotHasKey('consignee', $numbers);
        self::assertSame(3, $numbers['product']);
        self::assertSame(4, $numbers['shipping']);
        self::assertSame(5, $numbers['payment']);
        self::assertSame(6, $numbers['documents_provided']);
        self::assertSame(7, $numbers['terms']);
        self::assertSame(8, $numbers['legal_terms']);
    }

    public function testPiNumbersUnchangedFromHistoricalFixedValuesWhenBothSectionsShown(): void
    {
        $numbers = $this->sectionNumbers('PI', true, true);
        self::assertSame(2, $numbers['buyer']);
        self::assertSame(3, $numbers['consignee']);
        self::assertSame(4, $numbers['notify_party']);
        self::assertSame(5, $numbers['product']);
        self::assertSame(6, $numbers['shipping']);
        self::assertSame(7, $numbers['payment']);
        self::assertSame(8, $numbers['bank']);
        self::assertSame(9, $numbers['export_doc']);
        self::assertSame(10, $numbers['terms']);
        self::assertSame(11, $numbers['legal_terms']);
    }

    public function testPiNumbersShiftDownByTwoWhenBothSectionsHidden(): void
    {
        $numbers = $this->sectionNumbers('PI', false, false);
        self::assertArrayNotHasKey('consignee', $numbers);
        self::assertArrayNotHasKey('notify_party', $numbers);
        self::assertSame(3, $numbers['product']);
        self::assertSame(4, $numbers['shipping']);
        self::assertSame(5, $numbers['payment']);
        self::assertSame(6, $numbers['bank']);
        self::assertSame(7, $numbers['export_doc']);
        self::assertSame(8, $numbers['terms']);
        self::assertSame(9, $numbers['legal_terms']);
    }

    public function testPiNumbersShiftDownByOneWhenOnlyNotifyHidden(): void
    {
        $numbers = $this->sectionNumbers('PI', true, false);
        self::assertSame(3, $numbers['consignee']);
        self::assertArrayNotHasKey('notify_party', $numbers);
        self::assertSame(4, $numbers['product']);
        self::assertSame(10, $numbers['legal_terms']);
    }

    /**
     * PL/CI never have Terms & Conditions clauses configured — Legal Terms
     * & Definitions follows the last visible named section directly, with
     * no number reserved for the invisible Terms & Conditions heading
     * (never terms+1, unlike QT/PI/OC).
     */
    public function testPlNumbersUnchangedFromHistoricalFixedValuesWhenBothSectionsShown(): void
    {
        $numbers = $this->sectionNumbers('PL', true, true);
        self::assertSame(3, $numbers['consignee']);
        self::assertSame(4, $numbers['notify_party']);
        self::assertSame(5, $numbers['product_summary']);
        self::assertSame(6, $numbers['crate_breakdown']);
        self::assertSame(7, $numbers['declaration']);
        self::assertSame(8, $numbers['terms']);
        self::assertSame(8, $numbers['legal_terms']);
    }

    public function testCiNumbersUnchangedFromHistoricalFixedValuesWhenBothSectionsShown(): void
    {
        $numbers = $this->sectionNumbers('CI', true, true);
        self::assertSame(3, $numbers['consignee']);
        self::assertSame(4, $numbers['notify_party']);
        self::assertSame(5, $numbers['shipping_details']);
        self::assertSame(6, $numbers['product']);
        self::assertSame(7, $numbers['invoice_value']);
        self::assertSame(8, $numbers['bank']);
        self::assertSame(9, $numbers['documents_provided']);
        self::assertSame(10, $numbers['declaration']);
        self::assertSame(11, $numbers['terms']);
        self::assertSame(11, $numbers['legal_terms']);
    }

    public function testOcNumbersUnchangedFromHistoricalFixedValuesWhenConsigneeShown(): void
    {
        // OC never carries a Notify Party section at all.
        $numbers = $this->sectionNumbers('OC', true, true);
        self::assertSame(3, $numbers['consignee']);
        self::assertArrayNotHasKey('notify_party', $numbers);
        self::assertSame(4, $numbers['order_summary']);
        self::assertSame(5, $numbers['payment_status']);
        self::assertSame(6, $numbers['production']);
        self::assertSame(7, $numbers['documents_provided']);
        self::assertSame(8, $numbers['terms']);
        self::assertSame(9, $numbers['legal_terms']);
    }

    public function testUntoggleableTypesKeepTheirFixedNumbersRegardlessOfFlags(): void
    {
        $withFlags = $this->sectionNumbers('BUYERPO', true, true);
        $withoutFlags = $this->sectionNumbers('BUYERPO', false, false);
        self::assertSame($withFlags, $withoutFlags);
        self::assertSame(5, $withFlags['terms']);
        self::assertSame(8, $withFlags['legal_terms']);
    }
}
