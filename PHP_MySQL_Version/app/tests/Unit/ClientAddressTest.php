<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\ClientAddress;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-346 (12 Nov 2026): clients.billing_address is composed
 * from the structured parts as "Line 1, Line 2, City Postcode, Country".
 * Node twin: tests/unit/clientAddress.test.js.
 */
final class ClientAddressTest extends TestCase
{
    public function testAllPartsComposeAsLine1Line2CityPostcodeCountry(): void
    {
        self::assertSame(
            '1 Test Street, Unit 4, Birmingham B1 1AA, United Kingdom',
            ClientAddress::compose('1 Test Street', 'Unit 4', 'Birmingham', 'B1 1AA', 'United Kingdom')
        );
    }

    public function testEmptyPartsAreSkipped(): void
    {
        self::assertSame('1 Test Street, Birmingham, United Kingdom', ClientAddress::compose('1 Test Street', '', 'Birmingham', '', 'United Kingdom'));
        self::assertSame('1 Test Street, B1 1AA, United Kingdom', ClientAddress::compose('1 Test Street', null, '', 'B1 1AA', 'United Kingdom'));
        self::assertSame('', ClientAddress::compose('', null, '  ', '', null));
    }

    public function testPartsAreTrimmed(): void
    {
        self::assertSame('1 Test Street, Birmingham B1 1AA, United Kingdom', ClientAddress::compose(' 1 Test Street ', ' ', ' Birmingham ', ' B1 1AA ', ' United Kingdom '));
    }
}
