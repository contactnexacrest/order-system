<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\ReasonValidator;
use PHPUnit\Framework\TestCase;

final class ReasonValidatorTest extends TestCase
{
    public function testRejectsEmptyReason(): void
    {
        self::assertNotNull(ReasonValidator::check(''));
        self::assertNotNull(ReasonValidator::check('   '));
    }

    public function testRejectsReasonShorterThanMinLength(): void
    {
        self::assertNotNull(ReasonValidator::check('short'));
        self::assertNotNull(ReasonValidator::check('x'));
    }

    public function testAcceptsReasonAtOrAboveMinLength(): void
    {
        $exactly = str_repeat('a', ReasonValidator::MIN_LENGTH);
        self::assertNull(ReasonValidator::check($exactly));
        self::assertNull(ReasonValidator::check('This is a perfectly good reason.'));
    }

    public function testTrimsWhitespaceBeforeMeasuringLength(): void
    {
        $padded = '   ' . str_repeat('a', ReasonValidator::MIN_LENGTH) . '   ';
        self::assertNull(ReasonValidator::check($padded));
    }
}
