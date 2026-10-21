<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\ReportRepository;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 item 1 of the approved remediation plan: "customer-wise /
 * product-wise / country-wise reports." Product-wise already existed
 * (products()); this covers aggregateSummary()'s new by_client
 * (customer-wise) breakdown and country_fob_by_currency (giving
 * country-wise an actual value breakdown, not just an order count).
 */
final class AggregateSummaryByClientAndCountryTest extends TestCase
{
    public function testByClientGroupsOrderCountAndFobValuePerCustomer(): void
    {
        $rows = [
            ['currency_code' => 'USD', 'total_fob_value' => '1000', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'UK', 'company_legal_name' => 'Acme Ltd'],
            ['currency_code' => 'USD', 'total_fob_value' => '500', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'UK', 'company_legal_name' => 'Acme Ltd'],
            ['currency_code' => 'EUR', 'total_fob_value' => '300', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'Germany', 'company_legal_name' => 'Beta GmbH'],
        ];

        $summary = ReportRepository::aggregateSummary($rows);

        self::assertSame(2, $summary['by_client']['Acme Ltd']['count']);
        self::assertSame(1500.0, $summary['by_client']['Acme Ltd']['fob_by_currency']['USD']);
        self::assertSame(1, $summary['by_client']['Beta GmbH']['count']);
        self::assertSame(300.0, $summary['by_client']['Beta GmbH']['fob_by_currency']['EUR']);
    }

    public function testByClientSortsMostOrdersFirst(): void
    {
        $rows = [
            ['currency_code' => 'USD', 'total_fob_value' => '100', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'UK', 'company_legal_name' => 'One Order Co'],
            ['currency_code' => 'USD', 'total_fob_value' => '100', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'UK', 'company_legal_name' => 'Two Orders Co'],
            ['currency_code' => 'USD', 'total_fob_value' => '100', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'UK', 'company_legal_name' => 'Two Orders Co'],
        ];

        $summary = ReportRepository::aggregateSummary($rows);
        $names = array_keys($summary['by_client']);

        self::assertSame('Two Orders Co', $names[0], 'the customer with more orders must sort first');
    }

    public function testCountryFobByCurrencyGivesCountryWiseValueNotJustCount(): void
    {
        $rows = [
            ['currency_code' => 'USD', 'total_fob_value' => '1000', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'UK', 'company_legal_name' => 'Acme Ltd'],
            ['currency_code' => 'USD', 'total_fob_value' => '2000', 'current_stage_name' => 'Quotation', 'status' => 'active', 'country_of_destination' => 'UK', 'company_legal_name' => 'Other Co'],
        ];

        $summary = ReportRepository::aggregateSummary($rows);

        self::assertSame(2, $summary['by_country']['UK']);
        self::assertSame(3000.0, $summary['country_fob_by_currency']['UK']['USD'], 'country-wise must give a real value total, not just an order count');
    }
}
