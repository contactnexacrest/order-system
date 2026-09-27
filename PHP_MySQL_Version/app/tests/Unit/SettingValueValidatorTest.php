<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\SettingValueValidator;
use PHPUnit\Framework\TestCase;

/**
 * QA-5 SET-02: pins SettingValueValidator's contract for each
 * company_settings.value_type.
 */
final class SettingValueValidatorTest extends TestCase
{
    public function testNumberRejectsNonNumericAndBlank(): void
    {
        foreach (['abc', '', '1.2.3', 'NaN', 'Infinity'] as $v) {
            self::assertNotNull(SettingValueValidator::check('number', $v), "expected \"{$v}\" to be rejected");
        }
    }

    public function testNumberRejectsNegative(): void
    {
        self::assertNotNull(SettingValueValidator::check('number', '-1'));
    }

    public function testNumberAcceptsValidValues(): void
    {
        foreach (['0', '30', '5.00', '270'] as $v) {
            self::assertNull(SettingValueValidator::check('number', $v), "expected \"{$v}\" to be accepted");
        }
    }

    public function testBooleanAcceptsZeroOrOne(): void
    {
        self::assertNull(SettingValueValidator::check('boolean', '0'));
        self::assertNull(SettingValueValidator::check('boolean', '1'));
    }

    public function testBooleanRejectsAnythingElse(): void
    {
        foreach (['true', 'yes', '2', ''] as $v) {
            self::assertNotNull(SettingValueValidator::check('boolean', $v), "expected \"{$v}\" to be rejected");
        }
    }

    public function testDateAcceptsRealCalendarDate(): void
    {
        self::assertNull(SettingValueValidator::check('date', '2027-03-31'));
    }

    public function testDateRejectsInvalidValues(): void
    {
        foreach (['2027-02-30', '31-03-2027', 'not-a-date', ''] as $v) {
            self::assertNotNull(SettingValueValidator::check('date', $v), "expected \"{$v}\" to be rejected");
        }
    }

    public function testJsonAcceptsValidJson(): void
    {
        self::assertNull(SettingValueValidator::check('json', '{"a":1}'));
    }

    public function testJsonRejectsInvalidValues(): void
    {
        foreach (['{not json}', '', '{"a":}'] as $v) {
            self::assertNotNull(SettingValueValidator::check('json', $v), "expected \"{$v}\" to be rejected");
        }
    }

    public function testStringAcceptsAnything(): void
    {
        self::assertNull(SettingValueValidator::check('string', 'anything at all'));
        self::assertNull(SettingValueValidator::check('string', ''));
    }
}
