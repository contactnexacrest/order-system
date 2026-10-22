<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\Flash;
use PHPUnit\Framework\TestCase;

/**
 * External QA report R-02: "Raw SQL error text shown to users in the red
 * message box (not only in debug mode)" — found on cancel e-mail send,
 * create amendment, and send document. All three (and ~30 other call
 * sites across the controllers) share the same broad
 * `catch (\Throwable $e) { Flash::set('error', $e->getMessage()); }`
 * pattern: correct for a deliberately-thrown `\RuntimeException` business
 * message, but it also shows a genuinely unexpected error's raw internals
 * (a PDOException's SQLSTATE text, a TypeError from array input, ...) the
 * same way. Flash::setErrorFromException()/describeException() is the
 * single helper now used at every one of those sites.
 */
final class FlashExceptionMessageTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        parent::tearDown();
    }

    public function testADeliberateRuntimeExceptionsRealMessageIsShown(): void
    {
        Flash::setErrorFromException(new \RuntimeException('This order is already closed.'));

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertSame('This order is already closed.', $flash[0]['message']);
    }

    public function testAnUnexpectedPdoExceptionsRawMessageIsNeverShown(): void
    {
        // Simulates exactly what the QA report found: an array posted
        // where a scalar was expected caused a raw SQLSTATE/internal
        // error, shown verbatim in the flash before this fix.
        $pdoLikeError = new \TypeError('array_map(): Argument #2 ($array) must be of type array, string given');
        Flash::setErrorFromException($pdoLikeError);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringNotContainsString('array_map', $flash[0]['message']);
        self::assertSame('Something went wrong. Please try again.', $flash[0]['message']);
    }

    public function testACustomFallbackIsUsedForAnUnexpectedErrorWhenProvided(): void
    {
        Flash::setErrorFromException(new \Exception('Connection refused'), 'Could not approve this request.');

        $flash = Flash::pull();
        self::assertSame('Could not approve this request.', $flash[0]['message']);
    }

    public function testAPdoExceptionsRawMessageIsNeverShownEvenThoughPdoExceptionExtendsRuntimeException(): void
    {
        // \PDOException extends \RuntimeException in PHP's own SPL
        // hierarchy — a naive `$e instanceof \RuntimeException` check
        // would still trust it and leak raw SQLSTATE/driver text.
        self::assertInstanceOf(\RuntimeException::class, new \PDOException('test'), 'sanity check on PHP\'s own class hierarchy');

        $pdoException = new \PDOException("SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect date value: '2026-02-30' for column `amendments`.`effective_from`");
        Flash::setErrorFromException($pdoException);

        $flash = Flash::pull();
        self::assertStringNotContainsString('SQLSTATE', $flash[0]['message']);
        self::assertSame('Something went wrong. Please try again.', $flash[0]['message']);
    }

    public function testDescribeExceptionReturnsTheSameDecisionAsAPlainString(): void
    {
        self::assertSame(
            'Deliberate message.',
            Flash::describeException(new \RuntimeException('Deliberate message.'))
        );
        self::assertSame(
            'fallback text',
            Flash::describeException(new \Exception('SQLSTATE[42S22]: Column not found'), 'fallback text')
        );
    }
}
