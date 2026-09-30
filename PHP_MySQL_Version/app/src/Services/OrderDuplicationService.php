<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\OrderSupplierPoRepository;

/**
 * Creates a brand-new order from an existing one — a "repeat order",
 * requested by staff directly (any order, any status, including a closed
 * one) or approved from a client reorder request (ReorderController).
 * Deliberately copies only what a fresh order would otherwise need typed
 * in by hand again: client, commercial terms, and product lines. Never
 * copies anything instance-specific to the source order — its dates,
 * documents, payment status, FIRC/INR-actual data, disputes, comments, or
 * stage progress — all of that starts fresh, exactly like any order
 * created through the normal /orders/create form.
 *
 * One exception, added 2026-09-30: if the source order has a Supplier PO,
 * its supplier and pricing terms are carried forward onto the new order
 * as a fresh order_supplier_po row with status='draft' (see
 * carrySupplierPoForward() below) — a repeat client order almost always
 * means a repeat order to the same supplier too, and staff shouldn't have
 * to re-type terms that haven't changed. It's a draft, not an issued PO:
 * nothing is sent to the supplier, and staff review/adjust it through the
 * normal Stage 5 flow once this new order reaches that stage.
 */
final class OrderDuplicationService
{
    /**
     * @param array<int, array{description:string, dimensions:?string, finish:?string, quantity:?string, quantity_is_tbc:bool, unit:?string, unit_price:?string, hs_code:string}>|null $productLines
     *   When null, copies the source order's own current active product lines.
     */
    public static function duplicate(int $sourceOrderId, int $createdBy, ?array $productLines = null): int
    {
        $source = OrderRepository::find($sourceOrderId);
        if ($source === null) {
            throw new \RuntimeException("Cannot duplicate order {$sourceOrderId} — not found.");
        }
        $client = ClientRepository::find((int) $source['client_id']);

        $orderRefFormat = CompanySettingsRepository::get('order_ref_format') ?? 'SC/OC/{YYYY}/{NNN}';
        $testModeEnabled = TestModeService::isEnabled();

        // QA-5 CONC-03: see OrderRepository::createWithNextSequence()'s docblock.
        $result = OrderRepository::createWithNextSequence((int) $source['client_id'], function (int $sequenceNo) use (
            $orderRefFormat, $testModeEnabled, $source, $client
        ): array {
            return [
                'order_reference'       => TestModeService::applyReferencePrefix(
                    strtr($orderRefFormat, [
                        '{YYYY}' => date('Y'),
                        '{NNN}'  => str_pad((string) $sequenceNo, 3, '0', STR_PAD_LEFT),
                    ]) . '-' . $source['client_id'],
                    $testModeEnabled
                ),
                'buyer_inquiry_ref'     => $client['client_unique_number'] ?? $source['buyer_inquiry_ref'],
                'payment_preset_id'     => $source['payment_preset_id'],
                'incoterm_id'           => $source['incoterm_id'],
                'port_of_loading_id'    => $source['port_of_loading_id'],
                'port_of_discharge_id'  => $source['port_of_discharge_id'],
                'port_of_discharge_text' => $source['port_of_discharge_id'] ? null : $source['port_of_discharge_text'],
                'currency_id'           => $source['currency_id'],
                'coo_type'              => $source['coo_type'] ?? 'To Be Confirmed',
                'include_annexure_a'    => (bool) $source['include_annexure_a'],
                'special_requirements'  => $source['special_requirements'],
                'container_type'        => $source['container_type'],
                'estimated_total_cbm'   => $source['estimated_total_cbm'],
                'estimated_gross_weight_kg' => $source['estimated_gross_weight_kg'],
                'estimated_net_weight_kg'   => $source['estimated_net_weight_kg'],
                'estimated_package_count'   => $source['estimated_package_count'],
                'estimated_package_type'    => $source['estimated_package_type'],
                'est_lead_time_text'    => $source['est_lead_time_text'],
                'indicative_freight_low'  => $source['indicative_freight_low'],
                'indicative_freight_high' => $source['indicative_freight_high'],
                'indicative_insurance_amount' => $source['indicative_insurance_amount'],
                'buyers_po_ref'         => 'NIL', // the buyer's own ref is specific to each order — staff records the new one
                'quotation_date'        => date('Y-m-d'),
                'quotation_valid_until' => date('Y-m-d', strtotime('+' . ((int) (CompanySettingsRepository::get('quotation_validity_days') ?? 30)) . ' days')),
                'duplicated_from_order_id' => (int) $source['id'],
            ];
        }, $createdBy);
        $newOrderId = $result['orderId'];
        if ($testModeEnabled) {
            OrderRepository::markTest($newOrderId);
        }

        OrderStageRepository::initializeForOrder($newOrderId);
        OrderPaymentStatusRepository::initializeForOrder($newOrderId);

        if ($productLines === null) {
            foreach (OrderProductRepository::forOrder($sourceOrderId) as $line) {
                OrderProductRepository::duplicate((int) $line['id'], $newOrderId);
            }
        } else {
            $lineNo = 1;
            foreach ($productLines as $line) {
                OrderProductRepository::add(
                    $newOrderId,
                    $lineNo++,
                    $line['description'],
                    $line['dimensions'],
                    $line['finish'],
                    $line['quantity'],
                    $line['quantity_is_tbc'],
                    $line['unit'],
                    $line['unit_price'],
                    $line['hs_code']
                );
            }
        }

        AuditLogRepository::log($createdBy, 'ORDER_DUPLICATED', 'orders', $newOrderId, 'source_order_id', null, (string) $sourceOrderId);

        self::carrySupplierPoForward($sourceOrderId, $newOrderId, $createdBy);

        return $newOrderId;
    }

    /**
     * See class docblock. Silently does nothing if the source order never
     * had a Supplier PO — that's the common case for an order duplicated
     * before it ever reached Stage 5, and is not an error.
     */
    private static function carrySupplierPoForward(int $sourceOrderId, int $newOrderId, int $createdBy): void
    {
        $sourceSupplierPo = OrderSupplierPoRepository::findLatestForOrder($sourceOrderId);
        if ($sourceSupplierPo === null) {
            return;
        }

        $docTypeId = \App\Services\DocumentGenerationService::documentTypeIdFor('SUPPO');
        $newReference = $docTypeId ? \App\Services\ReferenceNumberService::generateDocumentRef($docTypeId) : null;
        if ($newReference === null) {
            return;
        }

        $newSupplierPoId = OrderSupplierPoRepository::create(
            $newOrderId,
            (int) $sourceSupplierPo['supplier_id'],
            $newReference,
            [
                'material_stone_type'   => $sourceSupplierPo['material_stone_type'],
                'grade'                 => $sourceSupplierPo['grade'],
                'surface_finish'        => $sourceSupplierPo['surface_finish'],
                'dimensions'            => $sourceSupplierPo['dimensions'],
                'dimensional_tolerance' => $sourceSupplierPo['dimensional_tolerance'],
                'quantity'              => $sourceSupplierPo['quantity'],
                'unit'                  => $sourceSupplierPo['unit'],
                'colour_reference'      => $sourceSupplierPo['colour_reference'],
                'special_requirements'  => $sourceSupplierPo['special_requirements'],
                'unit_price_inr'        => $sourceSupplierPo['unit_price_inr'],
                'basic_value_inr'       => $sourceSupplierPo['basic_value_inr'],
                'gst_rate_pct'          => $sourceSupplierPo['gst_rate_pct'],
                'gst_amount_inr'        => $sourceSupplierPo['gst_amount_inr'],
                'total_payable_inr'     => $sourceSupplierPo['total_payable_inr'],
                'advance_pct'           => $sourceSupplierPo['advance_pct'],
                'advance_amount_inr'    => $sourceSupplierPo['advance_amount_inr'],
                'balance_amount_inr'    => $sourceSupplierPo['balance_amount_inr'],
                'delivery_location'     => $sourceSupplierPo['delivery_location'],
                // Deliberately NOT carried over: required_delivery_date and
                // delivery_confirmation_due_date are specific to the source
                // shipment's own timeline — staff set fresh dates for this order.
                'required_delivery_date' => null,
                'packing_requirement'   => $sourceSupplierPo['packing_requirement'],
            ],
            'draft'
        );

        AuditLogRepository::log(
            $createdBy,
            'SUPPLIER_PO_CARRIED_FORWARD',
            'order_supplier_po',
            $newSupplierPoId,
            'source_supplier_po_id',
            null,
            (string) $sourceSupplierPo['id']
        );
    }
}
