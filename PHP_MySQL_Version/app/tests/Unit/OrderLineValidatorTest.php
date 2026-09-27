<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\OrderLineValidator;
use PHPUnit\Framework\TestCase;

/**
 * QA-5 ORD-05/ORD-06: pins OrderLineValidator's contract for quantity and
 * unit price.
 */
final class OrderLineValidatorTest extends TestCase
{
    public function testQuantityRejectsNonNumeric(): void
    {
        self::assertNotNull(OrderLineValidator::checkQuantity('abc', false));
    }

    public function testQuantityRejectsNegativeAndZero(): void
    {
        self::assertNotNull(OrderLineValidator::checkQuantity('-5', false));
        self::assertNotNull(OrderLineValidator::checkQuantity('0', false));
    }

    public function testQuantityAcceptsAPositiveNumber(): void
    {
        self::assertNull(OrderLineValidator::checkQuantity('120', false));
        self::assertNull(OrderLineValidator::checkQuantity('12.5', false));
    }

    public function testQuantityAllowsBlankOrTbcRegardlessOfContent(): void
    {
        self::assertNull(OrderLineValidator::checkQuantity('', false));
        self::assertNull(OrderLineValidator::checkQuantity('garbage', true));
        self::assertNull(OrderLineValidator::checkQuantity('-5', true));
    }

    public function testUnitPriceRejectsNonNumeric(): void
    {
        self::assertNotNull(OrderLineValidator::checkUnitPrice('abc'));
    }

    public function testUnitPriceRejectsNegative(): void
    {
        self::assertNotNull(OrderLineValidator::checkUnitPrice('-1'));
    }

    public function testUnitPriceAcceptsZeroOrPositive(): void
    {
        self::assertNull(OrderLineValidator::checkUnitPrice('0'));
        self::assertNull(OrderLineValidator::checkUnitPrice('45.75'));
    }

    public function testUnitPriceAllowsBlank(): void
    {
        self::assertNull(OrderLineValidator::checkUnitPrice(''));
    }
}
