<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\IncentiveRepository;
use App\Repositories\NotificationRepository;

/**
 * Owner decisions 11 Nov 2026, points 10 and 18 (D-293 / D-294): the rules for
 * checking new bank details, shared by associates and suppliers / logistics
 * partners.
 *
 * - The account holder confirms first (associate: in the portal with a code;
 *   supplier: through a link e-mailed to the registered e-mail) within 7 days,
 *   else the change is cancelled and the old account stays.
 * - Then ONE person (setting: or two people) other than the person who entered
 *   the details verifies them, ticking at least 2 of: matches the bank proof /
 *   phone call on the registered number / test payment with its reference.
 *   A note is optional. Someone allowed by Admin › Self-approval rules › "Bank
 *   details" (seeded MD, ED) may verify their own entry, with a reason.
 * - The account is used after a wait: first account 0 days, change of account
 *   15 days (both 0–90).
 *
 * Node twin: src/services/bankDetailsCheck.js.
 */
final class BankDetailsCheck
{
    /** Days the holder has to confirm (owner: 7). */
    public const CONFIRM_DAYS = 7;
    /** How long a 6-digit confirmation code is valid, and how many wrong tries it allows. */
    public const CODE_MINUTES = 10;
    public const CODE_ATTEMPTS = 5;

    public const CHECKS = [
        'check_bank_proof' => 'Matches the bank proof on file (cancelled cheque / bank statement)',
        'check_phone' => 'Called them on their registered phone number and they confirmed the last 4 digits',
        'check_test_payment' => 'Test payment of ₹1 / USD 1 received in the right name — bank reference',
    ];

    /** Roles alerted when a holder says "This is not me" (the MD and Finance). */
    public const ALERT_ROLES = ['Managing Director', 'Finance Director', 'Finance Manager'];

    /** 'one' (default) or 'two' people check new bank details. */
    public static function people(?array $settings = null): string
    {
        $settings ??= IncentiveRepository::settings();
        return ($settings['bank_details_check_people'] ?? 'one') === 'two' ? 'two' : 'one';
    }

    /** Days before a verified account is used: the first account, or a change of account (each 0–90). */
    public static function waitDays(bool $firstAccount, ?array $settings = null): int
    {
        $settings ??= IncentiveRepository::settings();
        $v = $firstAccount ? ($settings['associate_bank_wait_first_days'] ?? '0') : ($settings['associate_bank_wait_change_days'] ?? '15');
        return max(0, min(90, (int) $v));
    }

    /**
     * The "how did you check it" ticks from a verify form: at least 2, and the test payment needs its reference.
     * @return array{0: ?array{check_bank_proof:int,check_phone:int,check_test_payment:int,test_payment_ref:?string,verify_note:?string}, 1: ?string}
     */
    public static function readChecks(array $post): array
    {
        $ticks = [];
        foreach (array_keys(self::CHECKS) as $k) {
            $ticks[$k] = !empty($post[$k]) ? 1 : 0;
        }
        $ref = trim((string) ($post['test_payment_ref'] ?? ''));
        $note = trim((string) ($post['verify_note'] ?? ''));
        if (array_sum($ticks) < 2) {
            return [null, 'Tick at least two of the ways you checked the bank details.'];
        }
        if ($ticks['check_test_payment'] === 1 && (mb_strlen($ref) < 3 || mb_strlen($ref) > 60)) {
            return [null, 'Enter the bank reference (UTR / SWIFT) of the test payment.'];
        }
        if (mb_strlen($note) > 300) {
            return [null, 'The note can be up to 300 characters.'];
        }
        return [$ticks + ['test_payment_ref' => $ticks['check_test_payment'] === 1 ? $ref : null, 'verify_note' => $note !== '' ? $note : null], null];
    }

    /** "bank proof, phone call, test payment (UTR …)" — what the verifier ticked, for the audit log and the page. */
    public static function checksText(array $row): string
    {
        $parts = [];
        if (!empty($row['check_bank_proof'])) {
            $parts[] = 'bank proof';
        }
        if (!empty($row['check_phone'])) {
            $parts[] = 'phone call on the registered number';
        }
        if (!empty($row['check_test_payment'])) {
            $parts[] = 'test payment' . (!empty($row['test_payment_ref']) ? ' (ref ' . $row['test_payment_ref'] . ')' : '');
        }
        return implode(', ', $parts);
    }

    /**
     * Whether $userId may verify (or first-check) details entered by $enteredBy; for their own entry,
     * only with the self-approval rule "bank_details" and a reason (at least 10 characters).
     * @return ?string the refusal, or null
     */
    public static function selfCheckError(?int $enteredBy, int $userId, string $selfReason): ?string
    {
        if ($enteredBy === null || $enteredBy !== $userId) {
            return null;
        }
        if (!SelfApprovalRules::allowed('bank_details', $userId)) {
            return 'Someone else must verify details you entered.';
        }
        if (mb_strlen(trim($selfReason)) < 10) {
            return 'You entered these details yourself — give the reason you verify them (at least 10 characters).';
        }
        return null;
    }

    /** May this user verify their own entry (the page shows the reason box)? */
    public static function maySelfVerify(int $userId): bool
    {
        return SelfApprovalRules::allowed('bank_details', $userId);
    }

    /** "a•••@email.com" — the registered e-mail as shown on the confirm page. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    /**
     * Tells the MD and Finance (in-app and e-mail) — e.g. "This is not me" on a bank change.
     * ACCESS / SECURITY-CRITICAL e-mail (MUST, never switched off): sent through the existing mail send.
     */
    public static function alertMdAndFinance(string $subject, string $message): void
    {
        $in = implode(',', array_fill(0, count(self::ALERT_ROLES), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT u.id, u.email FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name IN ({$in}) AND u.is_active = 1 ORDER BY u.id"
        );
        $stmt->execute(self::ALERT_ROLES);
        foreach ($stmt->fetchAll() as $u) {
            NotificationRepository::createInAppOnly((int) $u['id'], 'bank_change_refused', null, mb_substr($message, 0, 500));
            if (!empty($u['email'])) {
                EmailService::sendPlainText((string) $u['email'], $subject, $message . "\n\nNexaCrest order system");
            }
        }
    }
}
