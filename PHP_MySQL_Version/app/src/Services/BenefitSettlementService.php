<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;

/**
 * Owner decision (2026-10-27, Option A): government export benefits are
 * recorded at Step 13g, just before Mark Fully Settled (13h) — the money
 * usually lands after the buyer has paid in full. 13g is done once every
 * scheme has an eligibility decision and every eligible scheme is either
 * received or skipped with a reason.
 */
final class BenefitSettlementService
{
    /**
     * @return array{done: bool, schemes: array<int, array<string,mixed>>, undecided: array<int,string>, waiting: array<int,string>, unclaimed: array<int,string>, skipped: array<int,string>}
     *   each scheme row carries a `settlement` of not_eligible|undecided|skipped|received|waiting|unclaimed
     */
    public static function state(int $orderId): array
    {
        $claimsByScheme = [];
        foreach (CaExportBenefitRepository::forOrder($orderId) as $claim) {
            $claimsByScheme[$claim['scheme_name']][] = $claim;
        }
        $out = ['undecided' => [], 'waiting' => [], 'unclaimed' => [], 'skipped' => []];
        $schemes = [];
        foreach (OrderExportBenefitSchemeRepository::forOrder($orderId) as $row) {
            $claims = $claimsByScheme[$row['scheme_name']] ?? [];
            if ($row['status'] === 'not_eligible') {
                $settlement = 'not_eligible';
            } elseif ($row['status'] === 'pending') {
                $settlement = 'undecided';
            } elseif (!empty($row['claim_skipped_at'])) {
                $settlement = 'skipped';
            } elseif ($claims === []) {
                $settlement = 'unclaimed';
            } else {
                $settlement = 'received';
                foreach ($claims as $c) {
                    if ($c['received_amount'] === null) {
                        $settlement = 'waiting';
                    }
                }
            }
            $row['settlement'] = $settlement;
            $schemes[] = $row;
            if (isset($out[$settlement])) {
                $out[$settlement][] = $row['scheme_name'];
            }
        }
        return [
            'done' => $out['undecided'] === [] && $out['waiting'] === [] && $out['unclaimed'] === [],
            'schemes' => $schemes,
        ] + $out;
    }
}
