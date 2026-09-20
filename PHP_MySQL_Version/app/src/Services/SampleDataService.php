<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\SampleDataRepository;

/**
 * Phase E follow-up — "can we have some sample records to play with, and
 * clear them through the UI whenever, any number of times, without ever
 * touching real data?" (user request, 2026-09-19).
 *
 * Deliberately reuses the exact same repository/service calls a real user's
 * request would make (OrderRepository::create(), StageGateService, the same
 * DocumentGenerationService::generate() every real QT/PI/OC goes through,
 * ...) rather than inventing a parallel fake-data insertion path — the
 * point of a playground is that it behaves exactly like the real system,
 * because it IS the real system, just flagged is_sample_data = 1 and
 * cleanly removable. See SampleDataRepository for the deletion side.
 *
 * Bounded scope (judgment call, flag for your review): two sample clients,
 * one order each — one left brand new at Stage 1 so a new user can practice
 * the create-order-and-generate-QT flow, one pushed through to Stage 5
 * (QT/PI/OC generated, buyer PO + advance payment recorded and cleared) so
 * there's something with real financials to see on the dashboard/reports.
 * Not an attempt to cover all 9 stages or every document type — easy to
 * extend the same way if you want a third order further along later.
 */
final class SampleDataService
{
    public static function isLoaded(): bool
    {
        return SampleDataRepository::isLoaded();
    }

    /** @return array<int, array<string,mixed>> */
    public static function summary(): array
    {
        return SampleDataRepository::summary();
    }

    /**
     * @return array{clients:int,orders:int}
     * @throws \RuntimeException if sample data is already loaded — clear it first.
     */
    public static function load(int $userId): array
    {
        if (SampleDataRepository::isLoaded()) {
            throw new \RuntimeException('Sample data is already loaded. Clear it first if you want a fresh copy.');
        }

        $incoterm = LookupRepository::incoterms()[0] ?? null;
        $currency = LookupRepository::currencies()[0] ?? null;
        $loadingPort = LookupRepository::ports('loading')[0] ?? null;
        $preset = LookupRepository::paymentPresets()[0] ?? null;
        if (!$incoterm || !$currency || !$preset) {
            throw new \RuntimeException('No incoterm/currency/payment preset configured yet — set those up first (Company Settings), then load sample data.');
        }

        $clientAId = self::createSampleClient(
            '[SAMPLE] Aurora Décor Imports',
            '124 Harbor Lane, Sample District, Test Country',
            $userId
        );
        $orderA1Id = self::createSampleOrder($clientAId, $incoterm, $currency, $loadingPort, $preset, $userId, [
            ['Hand-carved decorative planter, Model A', '30 x 30 x 45 cm', 'Polished'],
            ['Hand-carved decorative planter, Model B', '25 x 25 x 40 cm', 'Matte'],
        ]);
        // Left exactly here — Stage 1, no documents yet — as the
        // "start from scratch" sample order.

        $clientBId = self::createSampleClient(
            '[SAMPLE] Meridian Home Collections',
            '77 Riverside Court, Sample District, Test Country',
            $userId
        );
        $orderB1Id = self::createSampleOrder($clientBId, $incoterm, $currency, $loadingPort, $preset, $userId, [
            ['Outdoor stone planter, large', '60 x 60 x 70 cm', 'Natural finish'],
            ['Outdoor stone planter, medium', '40 x 40 x 50 cm', 'Natural finish'],
            ['Garden bench, stone composite', '150 x 45 x 45 cm', 'Sandblasted'],
        ]);
        self::advanceSampleOrderToStage5($orderB1Id, $userId);

        return ['clients' => 2, 'orders' => 2];
    }

    public static function clear(): array
    {
        return SampleDataRepository::clearAll();
    }

    private static function createSampleClient(string $companyName, string $billingAddress, int $userId): int
    {
        $clientUniqueNumber = ReferenceNumberService::generateClientUniqueNumber();
        $clientId = ClientRepository::create([
            'company_legal_name'     => $companyName,
            'billing_address'        => $billingAddress,
            'consignee_name'         => 'SAME',
            'consignee_address'      => 'SAME',
            'contact_person'         => 'Sample Contact',
            'email'                  => null,
            'phone'                  => null,
            'country_of_destination' => 'Test Country',
            'coo_type'               => 'TBC',
            'notify_party'           => null,
        ], $userId, $clientUniqueNumber);
        ClientRepository::markSample($clientId);
        return $clientId;
    }

    /** @param array<int, array{0:string,1:string,2:string}> $productLines [description, dimensions, finish] */
    private static function createSampleOrder(
        int $clientId,
        array $incoterm,
        array $currency,
        ?array $loadingPort,
        array $preset,
        int $userId,
        array $productLines
    ): int {
        $client = ClientRepository::find($clientId);
        $sequenceNo = OrderRepository::nextSequenceForClient($clientId);
        $orderRefFormat = CompanySettingsRepository::get('order_ref_format') ?? 'SC/OC/{YYYY}/{NNN}';
        $orderReference = strtr($orderRefFormat, [
            '{YYYY}' => date('Y'),
            '{NNN}'  => str_pad((string) $sequenceNo, 3, '0', STR_PAD_LEFT),
        ]) . '-' . $clientId;

        $orderId = OrderRepository::create([
            'order_reference'       => $orderReference,
            'client_id'             => $clientId,
            'sequence_no'           => $sequenceNo,
            'buyer_inquiry_ref'     => $client['client_unique_number'],
            'payment_preset_id'     => (int) $preset['id'],
            'incoterm_id'           => (int) $incoterm['id'],
            'port_of_loading_id'    => $loadingPort ? (int) $loadingPort['id'] : null,
            'currency_id'           => (int) $currency['id'],
            'coo_type'              => $client['coo_type'] ?? 'TBC',
            'estimated_total_cbm'         => null,
            'estimated_gross_weight_kg'   => null,
            'estimated_net_weight_kg'     => null,
            'indicative_freight_low'      => null,
            'indicative_freight_high'     => null,
            'indicative_insurance_amount' => null,
            'buyers_po_ref'         => 'NIL',
            'quotation_date'        => date('Y-m-d'),
            'quotation_valid_until' => date('Y-m-d', strtotime('+' . ((int) (CompanySettingsRepository::get('quotation_validity_days') ?? 30)) . ' days')),
        ], $userId);
        OrderRepository::markSample($orderId);

        OrderStageRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $lineNo = 1;
        foreach ($productLines as [$description, $dimensions, $finish]) {
            OrderProductRepository::add(
                $orderId,
                $lineNo++,
                $description,
                $dimensions,
                $finish,
                '10',
                false,
                'pcs',
                '250.00',
                '6802.93'
            );
        }

        return $orderId;
    }

    /**
     * Replays exactly the calls OrderController/DocumentController make for
     * a real order: generate QT (passes Stage 1), record the buyer's PO
     * (passes Stage 2), generate PI, record + clear the advance payment
     * (passes Stage 3), generate OC, confirm buyer acknowledgement (passes
     * Stage 4) — leaving the order sitting at Stage 5 (Supplier PO) with a
     * believable paper trail and payment history to look at.
     */
    private static function advanceSampleOrderToStage5(int $orderId, int $userId): void
    {
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        StageGateService::passAndUnlockNext($orderId, 1, $userId);

        OrderRepository::setBuyersPoRef($orderId, 'SAMPLE-BUYER-PO-0001');
        StageGateService::passAndUnlockNext($orderId, 2, $userId);

        DocumentGenerationService::generate($orderId, 'PI', $userId);

        $fobTotal = OrderProductRepository::totalFobValue($orderId);
        $order = OrderRepository::find($orderId);
        $advanceAmount = round($fobTotal * ((float) $order['advance_pct']) / 100, 2);
        $balanceAmount = round($fobTotal * ((float) $order['balance_pct']) / 100, 2);
        $balanceDueDate = $order['balance_trigger_option'] === 'A_BEFORE_SHIPMENT'
            ? null
            : date('Y-m-d', strtotime('+' . (int) $order['balance_days'] . ' days'));

        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, $advanceAmount, date('Y-m-d'));
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, date('Y-m-d'), $userId);
        OrderPaymentStatusRepository::setBalanceAmount($orderId, $balanceAmount, $balanceDueDate);
        StageGateService::passAndUnlockNext($orderId, 3, $userId);

        DocumentGenerationService::generate($orderId, 'OC', $userId);
        StageGateService::passAndUnlockNext($orderId, 4, $userId);
    }
}
