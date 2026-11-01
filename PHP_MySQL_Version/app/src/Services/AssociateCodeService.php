<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * Owner decision 1 Nov 2026 (AS-5, part 4.4-A2): an associate's permanent
 * personal code, e.g. NXA-RAJESH-7K. Every code belongs to one associate for
 * ever (unique, never reused). It ends in a check letter, so a mistyped code
 * is refused instead of matching someone else. Upper / lower case and the
 * look-alikes 0/O and 1/I are read as the same.
 */
final class AssociateCodeService
{
    /** No 0, 1, I, L or O — nothing that can be misread. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Upper case, no spaces, 0→O, 1→I. */
    public static function normalise(string $code): string
    {
        return strtr(strtoupper(preg_replace('/\s+/', '', $code) ?? ''), ['0' => 'O', '1' => 'I']);
    }

    /** The readable middle part, from a name or the associate's own choice: letters and digits, 3–12. */
    public static function readablePart(string $text): string
    {
        $part = self::normalise(preg_replace('/[^A-Za-z0-9]/', '', $text) ?? '');
        return substr($part, 0, 12);
    }

    public static function checkChar(string $body): string
    {
        $sum = 0;
        foreach (str_split(str_replace('-', '', $body)) as $i => $ch) {
            $sum += (ord($ch) * ($i + 3)) % 97;
        }
        return self::ALPHABET[$sum % strlen(self::ALPHABET)];
    }

    /** A new code NXA-{PART}-{R}{CHECK}, unique across all associates, past and present. */
    public static function generate(string $readable): string
    {
        $part = self::readablePart($readable);
        if (strlen($part) < 3) {
            $part = str_pad($part, 3, 'X');
        }
        for ($i = 0; $i < 50; $i++) {
            $body = 'NXA-' . $part . '-' . self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            $code = $body . self::checkChar($body);
            $stmt = Database::connection()->prepare('SELECT 1 FROM associates WHERE personal_code = :c UNION SELECT 1 FROM associate_codes WHERE code = :c2');
            $stmt->execute(['c' => $code, 'c2' => $code]);
            if ($stmt->fetchColumn() === false) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not make a unique code — please try again.');
    }

    /**
     * Gives the associate a new personal code: the old one is retired (it no
     * longer brings new clients; clients it brought stay theirs) and no code
     * is ever given to anyone else.
     */
    public static function issue(int $associateId, string $readable): string
    {
        $code = self::generate($readable);
        $pdo = Database::connection();
        $pdo->prepare('UPDATE associate_codes SET retired_at = NOW() WHERE associate_id = :a AND retired_at IS NULL')->execute(['a' => $associateId]);
        $pdo->prepare('INSERT INTO associate_codes (code, associate_id) VALUES (:c, :a)')->execute(['c' => $code, 'a' => $associateId]);
        $pdo->prepare('UPDATE associates SET personal_code = :c WHERE id = :a')->execute(['c' => $code, 'a' => $associateId]);
        return $code;
    }

    /** Whether the code is well-formed and its check letter matches (typo protection). */
    public static function isValid(string $code): bool
    {
        $c = self::normalise($code);
        if (preg_match('/^(NXA-[A-Z0-9]{3,12}-[' . self::ALPHABET . '])([' . self::ALPHABET . '])$/', $c, $m) !== 1) {
            return false;
        }
        return self::checkChar($m[1]) === $m[2];
    }

    /** The associate who owns the code, if the code is valid and the associate may bring new clients. */
    public static function resolve(string $code): ?array
    {
        if (!self::isValid($code)) {
            return null;
        }
        // Only the associate's current code brings new clients; a retired code resolves to nobody.
        return \App\Repositories\AssociateRepository::findByCode(self::normalise($code));
    }
}
