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
     * Gives the associate a new personal code. Owner rule (4 Nov 2026, D-254):
     * the old code keeps bringing buyers to the same associate (links already
     * shared keep working) unless staff block it, e.g. after a leak. No code is
     * ever given to anyone else.
     */
    public static function issue(int $associateId, string $readable, bool $blockOld = false, ?string $blockReason = null, ?string $code = null): string
    {
        $code ??= self::generate($readable);
        $pdo = Database::connection();
        // The new code first: if someone took it in the meantime (unique), nothing else has changed.
        $pdo->prepare('INSERT INTO associate_codes (code, associate_id) VALUES (:c, :a)')->execute(['c' => $code, 'a' => $associateId]);
        $pdo->prepare('UPDATE associate_codes SET retired_at = NOW()' . ($blockOld ? ', blocked_at = NOW(), blocked_reason = :r' : '') . ' WHERE associate_id = :a AND retired_at IS NULL AND code <> :c')
            ->execute($blockOld ? ['a' => $associateId, 'c' => $code, 'r' => mb_substr((string) $blockReason, 0, 300)] : ['a' => $associateId, 'c' => $code]);
        $pdo->prepare('UPDATE associates SET personal_code = :c WHERE id = :a')->execute(['c' => $code, 'a' => $associateId]);
        return $code;
    }

    /** D-254: a proposed new code for the associate to look at (nothing saved) — "like a captcha refresh". */
    public static function propose(string $readable): string
    {
        return self::generate($readable);
    }

    /** D-254: how many times the associate changed their own code in the last 365 days. */
    public static function changesInLastYear(int $associateId): int
    {
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM associate_events WHERE associate_id = :a AND event IN ('code_chosen', 'code_changed') AND created_at >= (NOW() - INTERVAL 365 DAY)");
        $stmt->execute(['a' => $associateId]);
        return (int) $stmt->fetchColumn();
    }

    public static function changesPerYear(): int
    {
        $v = (int) (\App\Repositories\IncentiveRepository::settings()['associate_code_changes_per_year'] ?? 3);
        return $v >= 0 ? $v : 3;
    }

    /** Every code the associate has had, newest first. @return list<array<string,mixed>> */
    public static function codes(int $associateId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associate_codes WHERE associate_id = :a ORDER BY issued_at DESC, code');
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
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

    /** The associate who owns the code (current or old, not blocked), if the code is valid. */
    public static function resolve(string $code): ?array
    {
        if (!self::isValid($code)) {
            return null;
        }
        // Owner rule D-254: the current code and every old one (not blocked by staff) bring the buyer to the associate.
        return \App\Repositories\AssociateRepository::findByAnyCode(self::normalise($code));
    }
}
