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

        $sequenceNo = OrderRepository::nextSequenceForClient((int) $source['client_id']);
        $orderRefFormat = CompanySettingsRepository::get('order_ref_format') ?? 'SC/OC/{YYYY}/{NNN}';
        $testModeEnabled = TestModeService::isEnabled();
        $orderReference = TestModeService::applyReferencePrefix(
            strtr($orderRefFormat, [
                '{YYYY}' => date('Y'),
                '{NNN}'  => str_pad((string) $sequenceNo, 3, '0', STR_PAD_LEFT),
            ]) . '-' . $source['client_id'],
            $testModeEnabled
        );

        $newOrderId = OrderRepository::create([
            'order_reference'       => $orderReference,
            'client_id'             => $source['client_id'],
            'sequence_no'           => $sequenceNo,
            'buyer_inquiry_ref'     => $client['client_unique_number'] ?? $source['buyer_inquiry_ref'],
            'payment_preset_id'     => $source['payment_preset_id'],
            'incoterm_id'           => $source['incoterm_id'],
            'port_of_loading_id'    => $source['port_of_loading_id'],
            'port_of_discharge_id'  => $source['port_of_discharge_id'],
            'port_of_discharge_text' => $source['port_of_discharge_id'] ? null : $source['port_of_discharge_text'],
            'currency_id'           => $source['currency_id'],
            'coo_type'              => $source['coo_type'] ?? 'TBC',
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
        ], $createdBy);
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

        return $newOrderId;
    }
}
