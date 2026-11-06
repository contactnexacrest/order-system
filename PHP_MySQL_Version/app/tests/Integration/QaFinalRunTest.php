<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\AssociateCodeService;
use App\Tests\Support\DbTestCase;

/**
 * Final QA run (6 Nov 2026): fixes found by the full e2e run.
 * - A name part ran out of personal codes: one random letter allows only 31 codes, so the 32nd associate
 *   (or code change) with the same readable part could never get a code ("Could not make a unique code").
 */
final class QaFinalRunTest extends DbTestCase
{
    public function testAPersonalCodeIsStillFoundWhenEveryOneLetterCodeForTheNameIsTaken(): void
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode) VALUES (:n, 'active', 'ladder')")->execute(['n' => 'QA Final Assoc ' . bin2hex(random_bytes(3))]);
        $associate = (int) $pdo->lastInsertId();
        $part = 'QF' . strtoupper(bin2hex(random_bytes(3)));
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        foreach (str_split($alphabet) as $r) {
            $body = 'NXA-' . $part . '-' . $r;
            $pdo->prepare('INSERT INTO associate_codes (code, associate_id) VALUES (:c, :a)')->execute(['c' => $body . AssociateCodeService::checkChar($body), 'a' => $associate]);
        }

        $code = AssociateCodeService::generate($part);
        self::assertMatchesRegularExpression('/^NXA-' . $part . '-[' . $alphabet . ']{2,3}[' . $alphabet . ']$/', $code, 'grows to 2 or 3 random letters');
        self::assertTrue(AssociateCodeService::isValid($code), 'the longer code passes the check letter');
        self::assertTrue(AssociateCodeService::isValid('NXA-' . $part . '-2' . AssociateCodeService::checkChar('NXA-' . $part . '-2')), 'old 1-letter codes stay valid');
        self::assertFalse(AssociateCodeService::isValid('NXA-' . $part . '-2345' . AssociateCodeService::checkChar('NXA-' . $part . '-2345')), 'at most 3 random letters');
    }
}
