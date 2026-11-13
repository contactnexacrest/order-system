<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\Database;
use App\Repositories\ProductRepository;
use App\Services\PricingService;
use App\Services\ProductSpecs;
use App\Services\SupplierOffers;

/**
 * Shared pricing fixtures (QA 13 Nov 2026, stale-test fixes):
 *  - the D-245 worked examples (cost 1,000.00 / minimum 1,250.00 / staff floor
 *    1,287.50 / quote 1,326.13 from a ₹80,000 supplier price) were worked out with
 *    margin 20 % and other costs 2.5 %; D-347 made the installed values 15 % / 5.5 %.
 *    A test that relies on those figures pins EVERY pricing setting it depends on,
 *    so its result never depends on what an earlier test left behind;
 *  - D-361: only a CONFIGURED product with an active supplier offer is quotable
 *    (offered on orders), so a fixture product a test picks is configured first.
 * Node twin: tests/support/fixtures.js (pinWorkedExamplePricing, configureProductForQuoting).
 */
trait PricingFixtures
{
    /** Every pricing setting at the code fallbacks, with the D-245 worked-example margin 20 % and other costs 2.5 %. */
    protected static function pinWorkedExamplePricing(array $overrides = []): void
    {
        $values = array_merge(PricingService::DEFAULTS, ['margin_pct' => '20.00', 'other_cost_pct' => '2.50'], $overrides);
        $pdo = Database::connection();
        foreach ($values as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        // The worked examples price without the team-incentive allowance; the seed has the program on
        // since D-347, so pin it off here too (otherwise the result depends on which test ran before).
        $pdo->exec("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
    }

    /** Every pricing setting back at the code fallbacks (the installed values, D-347). */
    protected static function resetPricingSettings(): void
    {
        $pdo = Database::connection();
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $pdo->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'"); // seed value (D-347)
    }

    /**
     * D-361: fixes the product's base price at the supplier price "used for pricing"
     * (so every figure stays the same) and keeps it Active — the product is then
     * configured with an active supplier offer, i.e. quotable. With $activate false the
     * Active flag is left as it is.
     */
    protected static function configureProductForQuoting(int $productId, int $userId, bool $activate = true): void
    {
        $product = ProductRepository::find($productId);
        $base = SupplierOffers::configuredBase($product);
        if ($base['inr'] === null) {
            throw new \RuntimeException("Product {$productId} has no usable supplier price to configure from.");
        }
        SupplierOffers::setConfiguredBase($productId, (float) $base['inr'], $base['mm'], (string) $base['unit'], $userId);
        if ($activate) {
            ProductRepository::setActive($productId, true, $userId);
        }
    }

    /**
     * D-348: a goods line picked from Products must have its "Thickness" / "Size / format"
     * chosen. The spec_json an order-line form posts with every offered specification on
     * its base option (or its first option), so the price is the product's base price.
     */
    protected static function baseSpecJson(int $productId): string
    {
        $selection = [];
        foreach (ProductSpecs::offered(ProductRepository::find($productId)) as $s) {
            $pick = $s['options'][0]['id'];
            foreach ($s['options'] as $o) {
                if ($o['is_base']) {
                    $pick = $o['id'];
                    break;
                }
            }
            $selection[(string) $s['id']] = (int) $pick;
        }
        return (string) json_encode($selection);
    }
}
