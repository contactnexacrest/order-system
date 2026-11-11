<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Helpers\Dates;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;

/**
 * Owner decisions 11 Nov 2026, point 18 (D-293): the associate confirms new bank
 * details before NexaCrest verifies them — even when they asked for the change
 * themselves.
 *
 * Entered (in the portal, or by staff from the signed form) → the associate
 * sees a bar on every portal page and gets an e-mail → on the confirm page they
 * compare the current and the new account, re-type the last 4 digits of the
 * new account, enter a fresh 6-digit code sent to their REGISTERED e-mail and
 * tick "These are my bank details" → Confirm (staff may now verify) or
 * "This is not me — cancel it" (cancelled; the MD and Finance are alerted).
 * No answer in 7 days: cancelled by itself; the old account stays.
 *
 * Node twin: src/services/associateBankConfirmation.js.
 */
final class AssociateBankConfirmation
{
    public const NOT_ME_MESSAGE = 'Cancelled. Your bank details have not changed. We have told our Managing Director and Finance team. If someone contacted you about your bank details, please reply in Messages.';

    /** "HDFC Bank ••••6789" plus "INR" — how a request is named in e-mails. */
    private static function name(array $b): string
    {
        return AssociateBankRepository::masked($b) . ' (' . $b['account_currency'] . ')';
    }

    /**
     * A request was entered: it now waits for the associate's confirmation (7 days) and they are
     * e-mailed. The bank-changed alert (MUST) is part of this e-mail.
     */
    public static function start(int $bankId, array $associate, bool $byAssociate, ?int $userId): void
    {
        $confirmBy = date('Y-m-d H:i:s', time() + BankDetailsCheck::CONFIRM_DAYS * 86400);
        AssociateBankRepository::markAwaiting($bankId, (int) $associate['id'], $confirmBy);
        $b = AssociateBankRepository::find($bankId);
        AuditLogRepository::log($userId, 'ASSOCIATE_BANK_CHANGE_REQUESTED', 'associate_bank_accounts', $bankId, 'status', null, 'awaiting_confirmation', $byAssociate ? 'by associate' : 'by staff');
        // ACCESS / SECURITY-CRITICAL e-mail (MUST — the bank-changed alert): always sent through the existing mail send.
        AssociateService::notify($associate, 'bank_changed', self::askMessage($b, $byAssociate), null, null, null, $userId);
    }

    public static function askMessage(array $b, bool $byAssociate): string
    {
        return ($byAssociate ? 'You asked' : 'NexaCrest recorded, from your signed form,') . ' to change your bank details to ' . self::name($b) . ' on ' . Dates::short(substr((string) $b['requested_at'], 0, 10)) . '. '
            . 'Please confirm them in your associate portal (Profile › Bank details) by ' . Dates::short(substr((string) $b['confirm_by'], 0, 10)) . ' — your commission is paid to them only after you confirm and NexaCrest has checked them. '
            . AssociateService::bankPaymentsSentence((int) $b['associate_id']) . ' If you did not ask for this, open the portal and click "This is not me — cancel it", or contact NexaCrest at once.';
    }

    /** The open request for the portal bar and the confirm page (null when nothing waits). */
    public static function open(int $associateId): ?array
    {
        $b = AssociateBankRepository::awaiting($associateId);
        if ($b === null || (string) $b['confirm_by'] < date('Y-m-d H:i:s')) {
            return null;
        }
        return $b;
    }

    /**
     * A fresh 6-digit code, e-mailed to the associate's REGISTERED e-mail; valid 10 minutes.
     * ACCESS / SECURITY-CRITICAL e-mail (MUST, a one-time code): sent on the security path of the existing mail send.
     */
    public static function sendCode(array $associate, array $b): bool
    {
        if (empty($associate['email'])) {
            return false;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        AssociateBankRepository::setCode((int) $b['id'], hash('sha256', $code), date('Y-m-d H:i:s', time() + BankDetailsCheck::CODE_MINUTES * 60));
        EmailService::sendPlainText((string) $associate['email'], 'NexaCrest associate portal — your code to confirm new bank details',
            "Hello {$associate['name']},\n\nYour code to confirm the new bank details " . self::name($b) . " is:\n\n{$code}\n\n"
            . 'It is valid for ' . BankDetailsCheck::CODE_MINUTES . " minutes. Never share it — NexaCrest staff will never ask you for it.\n"
            . "If you did not ask for new bank details, open your portal and click \"This is not me — cancel it\".\n\nNexaCrest International Private Limited", true, 'bank_confirm_code', 'associate');
        if (AssociatePortalService::showCodeOnScreen()) {
            $_SESSION['associate_bank_code_dev'] = $code; // dev servers only, as with the login code
        }
        return true;
    }

    /** The associate confirms. Returns the error shown beside the form, or null. */
    public static function confirm(array $associate, int $bankId, string $last4, string $code, bool $ticked): ?string
    {
        $b = AssociateBankRepository::find($bankId);
        if ($b === null || (int) $b['associate_id'] !== (int) $associate['id'] || $b['status'] !== 'awaiting_confirmation') {
            return 'These bank details are no longer waiting for your confirmation.';
        }
        if ((string) $b['confirm_by'] < date('Y-m-d H:i:s')) {
            return 'The 7 days to confirm are over — the change was cancelled. Send the new details again if you still want them.';
        }
        if (!$ticked) {
            return 'Tick "These are my bank details" to confirm.';
        }
        if (preg_replace('/\D/', '', $last4) !== (string) $b['account_last4']) {
            return 'The last 4 digits do not match the new account number — check them and type them again.';
        }
        if (empty($b['confirm_code_hash']) || (string) $b['confirm_code_expires'] < date('Y-m-d H:i:s')) {
            return 'Ask for a code first (or a new one — a code is valid for ' . BankDetailsCheck::CODE_MINUTES . ' minutes).';
        }
        if ((int) $b['confirm_code_attempts'] >= BankDetailsCheck::CODE_ATTEMPTS) {
            return 'Too many wrong codes — ask for a new code.';
        }
        if (!hash_equals((string) $b['confirm_code_hash'], hash('sha256', preg_replace('/\D/', '', $code) ?? ''))) {
            AssociateBankRepository::addCodeAttempt($bankId);
            return 'The code is not right — check the e-mail we sent you, or ask for a new code.';
        }
        if (!AssociateBankRepository::markConfirmed($bankId)) {
            return 'These bank details are no longer waiting for your confirmation.';
        }
        AuditLogRepository::log(null, 'ASSOCIATE_BANK_CONFIRMED', 'associate_bank_accounts', $bankId, 'status', 'awaiting_confirmation', 'pending', 'Confirmed by the associate (last 4 digits + e-mailed code)');
        AssociateService::notify($associate, 'bank_changed', 'Thank you — you confirmed your new bank details ' . self::name($b) . '. NexaCrest will now check them. ' . self::usableSentence((int) $associate['id']), null, null, null, null, false);
        foreach (PermissionRepository::usersWithPermission('manage_associate_bank') as $uid) {
            NotificationRepository::createInAppOnly((int) $uid, 'associate_bank_change', null, "Associate {$associate['name']} confirmed new bank details (" . self::name($b) . ') — verify them on their page.');
        }
        return null;
    }

    /** "You can be paid to them from about 18 Nov 2026 (…). Until then, payments go to your current account." */
    public static function usableSentence(int $associateId): string
    {
        $first = !AssociateBankRepository::hasVerified($associateId);
        $wait = BankDetailsCheck::waitDays($first);
        return 'You can be paid to them from about ' . Dates::short(date('Y-m-d', strtotime('+' . $wait . ' days'))) . ($wait > 0 ? " (we always wait {$wait} days after " . ($first ? 'adding a first account' : 'a change') . ', for your safety)' : '') . '. '
            . ($first ? '' : 'Until then, payments go to your current account.');
    }

    /** "This is not me — cancel it": cancelled at once; the MD and Finance are alerted. */
    public static function notMe(array $associate, int $bankId): bool
    {
        $b = AssociateBankRepository::find($bankId);
        if ($b === null || (int) $b['associate_id'] !== (int) $associate['id'] || !AssociateBankRepository::cancel($bankId, 'not_me')) {
            return false;
        }
        AuditLogRepository::log(null, 'ASSOCIATE_BANK_NOT_ME', 'associate_bank_accounts', $bankId, 'status', (string) $b['status'], 'cancelled', 'The associate said "This is not me" — possible fraud');
        AssociateService::notify($associate, 'bank_rejected', self::NOT_ME_MESSAGE, null, null, null, null, false);
        $by = (int) $b['requested_by_associate'] === 1 ? 'in the portal' : 'by ' . (\App\Repositories\UserRepository::findById((int) ($b['requested_by_user'] ?? 0))['name'] ?? 'NexaCrest staff');
        BankDetailsCheck::alertMdAndFinance('Associate bank change refused — possible fraud: ' . $associate['name'],
            "Associate {$associate['name']} clicked \"This is not me — cancel it\" for the new bank details " . self::name($b) . ", entered {$by} on " . Dates::short(substr((string) $b['requested_at'], 0, 10)) . ".\n"
            . "The change is cancelled and their bank details have not changed. Please find out who entered them.\n"
            . 'Associate page: ' . rtrim((string) Env::get('APP_URL', ''), '/') . '/associates/' . (int) $associate['id'] . '#bank');
        return true;
    }

    /** Staff "Remind associate": the confirmation e-mail again. */
    public static function remind(int $bankId, int $userId): bool
    {
        $b = AssociateBankRepository::find($bankId);
        if ($b === null || $b['status'] !== 'awaiting_confirmation') {
            return false;
        }
        $a = AssociateRepository::find((int) $b['associate_id']);
        if ($a === null) {
            return false;
        }
        AssociateBankRepository::markReminded($bankId);
        AuditLogRepository::log($userId, 'ASSOCIATE_BANK_CONFIRM_REMINDED', 'associate_bank_accounts', $bankId);
        AssociateService::notify($a, 'bank_changed', 'Reminder: ' . self::askMessage($b, (int) $b['requested_by_associate'] === 1), null, null, null, $userId);
        return true;
    }

    /** Daily: requests not confirmed in 7 days are cancelled; the old account stays. Returns how many. */
    public static function expireOverdue(?string $now = null): int
    {
        $n = 0;
        foreach (AssociateBankRepository::overdue($now ?? date('Y-m-d H:i:s')) as $b) {
            if (!AssociateBankRepository::cancel((int) $b['id'], 'expired')) {
                continue;
            }
            $n++;
            AuditLogRepository::log(null, 'ASSOCIATE_BANK_CONFIRM_EXPIRED', 'associate_bank_accounts', (int) $b['id'], 'status', 'awaiting_confirmation', 'cancelled', 'Not confirmed within ' . BankDetailsCheck::CONFIRM_DAYS . ' days');
            $a = AssociateRepository::find((int) $b['associate_id']);
            if ($a !== null) {
                AssociateService::notify($a, 'bank_rejected', 'The new bank details ' . self::name($b) . ' were not confirmed within ' . BankDetailsCheck::CONFIRM_DAYS . ' days, so the change was cancelled. '
                    . AssociateService::bankPaymentsSentence((int) $a['id']) . ' Send the new details again in your portal if you still want them.');
            }
        }
        return $n;
    }

    /** The staff wording of a request's status (associate page › Bank details). */
    public static function staffStatus(array $b): string
    {
        $d = static fn ($v): string => Dates::short(substr((string) $v, 0, 10));
        return match ((string) $b['status']) {
            'awaiting_confirmation' => 'Waiting for the associate — ends ' . $d($b['confirm_by']),
            'pending' => 'Confirmed by the associate' . (!empty($b['confirmed_at']) ? ' ' . $d($b['confirmed_at']) : '') . ' — waiting for NexaCrest to verify',
            'verified' => 'Verified' . (!empty($b['usable_from']) ? ' — used from ' . $d($b['usable_from']) : ''),
            'cancelled' => match ((string) ($b['cancelled_how'] ?? '')) {
                'not_me' => 'Cancelled — the associate said "This is not me" (MD and Finance alerted)',
                'expired' => 'Cancelled — not confirmed within ' . BankDetailsCheck::CONFIRM_DAYS . ' days',
                'replaced' => 'Cancelled — replaced by newer details',
                default => 'Cancelled',
            },
            'rejected' => 'Rejected',
            'superseded' => 'Replaced',
            default => ucfirst((string) $b['status']),
        };
    }

    /** The associate's wording (portal › Profile). */
    public static function associateStatus(array $b): string
    {
        $d = static fn ($v): string => Dates::short(substr((string) $v, 0, 10));
        return match ((string) $b['status']) {
            'awaiting_confirmation' => 'Waiting for your confirmation — by ' . $d($b['confirm_by']),
            'pending' => 'You confirmed — being checked by NexaCrest',
            'verified' => 'Verified' . ($b['usable_from'] > date('Y-m-d') ? ' — used from ' . $d($b['usable_from']) : ''),
            'rejected' => 'Not accepted: ' . $b['rejected_reason'],
            'cancelled' => ($b['cancelled_how'] ?? '') === 'expired' ? 'Cancelled — not confirmed in time' : 'Cancelled',
            'superseded' => 'Replaced',
            default => (string) $b['status'],
        };
    }
}
