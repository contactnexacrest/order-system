<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #67: the Freight/Insurance section asked for a low/high
 * range before freight is ever actually booked, and the hint text next to
 * both fields said the literal placeholder "order currency" instead of the
 * order's real currency. Per the user's own wording: a single indicative
 * figure ("current indicative rate only — the real rate is confirmed by
 * Debit Note once cargo is packed and ready"), and the actual currency
 * code shown instead of the placeholder. Consolidated
 * indicative_freight_low/indicative_freight_high into one
 * indicative_freight_amount column (Section BJ, schema.sql); the view and
 * document-template changes are covered by the file-content assertions
 * below, and this test proves the new single column actually round-trips
 * through OrderRepository::create()/updateDetails()/find().
 */
final class FreightInsuranceSingleValueTest extends DbTestCase
{
    public function testIndicativeFreightAmountRoundTripsThroughUpdateDetails(): void
    {
        // CIF: only a CIF order keeps an insurance amount (owner decision D-049).
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        $order = OrderRepository::find($orderId);
        self::assertNotNull($order);
        self::assertArrayHasKey('indicative_freight_amount', $order, 'indicative_freight_low/high must be consolidated into a single indicative_freight_amount column');
        self::assertArrayNotHasKey('indicative_freight_high', $order, 'indicative_freight_high must be dropped, not just unused');

        OrderRepository::updateDetails($orderId, [
            'incoterm_id'                 => $order['incoterm_id'],
            'currency_id'                 => $order['currency_id'],
            'port_of_loading_id'          => $order['port_of_loading_id'] ?? null,
            'port_of_discharge_id'        => $order['port_of_discharge_id'] ?? null,
            'port_of_discharge_text'      => $order['port_of_discharge_text'] ?? null,
            'coo_type'                    => $order['coo_type'] ?? 'To Be Confirmed',
            'container_type'              => $order['container_type'] ?? null,
            'buyers_po_ref'                => $order['buyers_po_ref'] ?? 'NIL',
            'special_requirements'        => $order['special_requirements'] ?? null,
            'est_lead_time_text'          => $order['est_lead_time_text'] ?? null,
            'estimated_total_cbm'         => $order['estimated_total_cbm'] ?? null,
            'estimated_gross_weight_kg'   => $order['estimated_gross_weight_kg'] ?? null,
            'estimated_net_weight_kg'     => $order['estimated_net_weight_kg'] ?? null,
            'estimated_package_count'     => $order['estimated_package_count'] ?? null,
            'estimated_package_type'      => $order['estimated_package_type'] ?? null,
            'indicative_freight_amount'   => '950.00',
            'indicative_insurance_amount' => '120.00',
        ]);

        $updated = OrderRepository::find($orderId);
        self::assertSame('950.00', $updated['indicative_freight_amount']);
        self::assertSame('120.00', $updated['indicative_insurance_amount']);
    }

    public function testCreateOrderFormShowsASingleIndicativeFreightFieldWithLiveCurrencyLabel(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../views/orders/create.php');

        self::assertStringContainsString('name="indicative_freight_amount"', $html);
        self::assertStringNotContainsString('indicative_freight_low', $html);
        self::assertStringNotContainsString('indicative_freight_high', $html);
        self::assertStringContainsString('data-code="<?= htmlspecialchars($cur[\'code\']) ?>"', $html, 'currency options must carry a data-code attribute for the live-label JS to read');
        self::assertStringContainsString('id="freight-currency-unit"', $html);
        self::assertStringContainsString('onchange="updateFreightCurrencyLabels(this)"', $html);
        self::assertStringContainsString('updateFreightCurrencyLabels', $html);
        self::assertStringContainsString('current indicative rate only', $html);
        self::assertStringContainsString('paid in full in advance against the Debit Note, before the vessel is booked', $html);
    }

    public function testEditDetailsFormShowsASingleIndicativeFreightFieldWithTheOrdersOwnCurrency(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../views/orders/edit_details.php');

        self::assertStringContainsString('name="indicative_freight_amount"', $html);
        self::assertStringNotContainsString('indicative_freight_low', $html);
        self::assertStringNotContainsString('indicative_freight_high', $html);
        self::assertStringContainsString("order['currency_code']", $html);
        self::assertStringContainsString('updateFreightCurrencyLabels', $html);
    }

    public function testQtAndPiTemplatesShowASingleIndicativeFreightFigureNotARange(): void
    {
        $qt = (string) file_get_contents(__DIR__ . '/../../templates/QT/quotation.html.twig');
        $pi = (string) file_get_contents(__DIR__ . '/../../templates/PI/proforma_invoice.html.twig');

        foreach ([$qt, $pi] as $html) {
            self::assertStringContainsString('order.indicative_freight_amount', $html);
            self::assertStringNotContainsString('indicative_freight_low', $html);
            self::assertStringNotContainsString('indicative_freight_high', $html);
            self::assertStringContainsString('Current indicative rate only', $html);
            self::assertStringContainsString('paid in full in advance against our Debit Note, before the vessel is booked', $html);
        }
    }

    public function testDocxBuilderShowsASingleIndicativeFreightFigureNotARange(): void
    {
        $php = (string) file_get_contents(__DIR__ . '/../../src/Services/Docx/DocxDocumentBuilder.php');

        self::assertStringContainsString("'indicative_freight_amount'", $php);
        self::assertStringNotContainsString('indicative_freight_low', $php);
        self::assertStringNotContainsString('indicative_freight_high', $php);
        self::assertStringContainsString('Current indicative rate only', $php);
    }
}
