<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Helpers\Dates;
use App\Repositories\AuditLogRepository;
use App\Repositories\VendorBankChangeRepository;
use App\Repositories\VendorDetailsRepository;

/**
 * Owner decisions 11 Nov 2026, point 18 (D-294): the same rules as for associates apply to
 * a supplier's or logistics partner's bank details.
 *
 * A change (typed by staff, or by the vendor through the Vendor Details Link) does not replace
 * the account in use. A confirmation link is e-mailed to the vendor's REGISTERED e-mail (the
 * one on file before the change): the page shows current vs new, they re-type the last 4 digits
 * of the new account and tick "These are our bank details" — Confirm, or "This is not us — cancel
 * it" (cancelled; the MD and Finance are alerted). No answer in 7 days: cancelled, the old
 * account stays. Staff verify only after the confirmation (BankDetailsCheck: one person other
 * than the enterer, at least 2 checks; or two people). After the waiting days (first account /
 * change) the new details are copied onto the supplier as verified.
 *
 * Node twin: src/services/vendorBankChangeService.js.
 */
final class VendorBankChangeService
{
    /** "Bank of Baroda ••••4321" */
    public static function name(array $c, ?array $vendor = null): string
    {
        $last4 = $c['account_last4'] ?? null;
        if ($last4 === null && $vendor !== null) {
            $last4 = substr((string) VendorDetailsRepository::bankDetails($vendor)['bank_account_number'], -4);
        }
        return trim((string) ($c['bank_name'] ?? '') . ' ••••' . (string) $last4);
    }

    /**
     * A change of bank details is recorded and the confirmation link e-mailed to the registered e-mail.
     * @param ?string $registeredEmail the vendor's e-mail on file BEFORE this change
     * @return array{id:int, emailed_to:?string}
     */
    public static function propose(string $kind, int $vendorId, array $bank, string $source, ?int $userId, ?string $registeredEmail): array
    {
        $vendor = VendorDetailsRepository::find($kind, $vendorId);
        $last4 = !empty($bank['bank_account_number']) ? substr((string) $bank['bank_account_number'], -4)
            : (substr((string) VendorDetailsRepository::bankDetails($vendor)['bank_account_number'], -4) ?: null);
        $raw = bin2hex(random_bytes(32));
        $email = trim((string) $registeredEmail) !== '' ? trim((string) $registeredEmail) : null;
        $id = VendorBankChangeRepository::create($kind, $vendorId, $bank, $source, $userId, $email, hash('sha256', $raw),
            date('Y-m-d H:i:s', time() + BankDetailsCheck::CONFIRM_DAYS * 86400), $last4);
        $meta = VendorDetailsRepository::KINDS[$kind];
        AuditLogRepository::log($userId, 'VENDOR_BANK_CHANGE_REQUESTED', $meta['table'], $vendorId, 'bank_account_number',
            VendorDetailsRepository::maskAccount(VendorDetailsRepository::bankDetails($vendor)['bank_account_number']), '••••' . $last4,
            ($source === 'vendor' ? 'Entered by them through the details link' : 'Entered by staff') . ' — waiting for their confirmation (' . ($email ?? 'no registered e-mail') . ')');
        if ($email !== null) {
            self::sendLink($kind, $vendor, VendorBankChangeRepository::find($id) ?? [], $raw, $email);
        }
        return ['id' => $id, 'emailed_to' => $email];
    }

    /** ACCESS / SECURITY-CRITICAL e-mail (MUST — the bank-changed alert with its confirm link): sent on the security path of the existing mail send. */
    private static function sendLink(string $kind, ?array $vendor, array $c, string $raw, string $email): void
    {
        $meta = VendorDetailsRepository::KINDS[$kind];
        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/vendor-bank-confirm/' . $raw;
        EmailService::sendPlainText($email, 'NexaCrest — please confirm your new bank details',
            'Hello' . ($vendor !== null ? ' ' . $vendor[$meta['name']] : '') . ",\n\n"
            . 'New bank details were recorded for you at NexaCrest: ' . self::name($c, $vendor) . ".\n"
            . "NexaCrest pays into them only after you confirm them here (no login needed), by " . Dates::short(substr((string) $c['confirm_by'], 0, 10)) . ":\n{$link}\n\n"
            . "If you did not send new bank details, open the link and click \"This is not us — cancel it\". Until then, payments go to the account you had.\n\n"
            . 'NexaCrest International Private Limited', true, 'vendor_bank_confirm', 'supplier');
    }

    /** Staff "Send the link again" (e.g. after adding the e-mail): a fresh link to the e-mail on file, 7 more days. */
    public static function resend(int $changeId, int $userId): ?string
    {
        $c = VendorBankChangeRepository::find($changeId);
        if ($c === null || $c['status'] !== 'awaiting_confirmation') {
            return 'This change is no longer waiting for their confirmation.';
        }
        $vendor = VendorDetailsRepository::find((string) $c['vendor_kind'], (int) $c['vendor_id']);
        $email = trim((string) ($vendor['email'] ?? ''));
        if ($email === '') {
            return 'There is no e-mail on file — add their e-mail first.';
        }
        $raw = bin2hex(random_bytes(32));
        VendorBankChangeRepository::newToken($changeId, hash('sha256', $raw), date('Y-m-d H:i:s', time() + BankDetailsCheck::CONFIRM_DAYS * 86400), $email);
        AuditLogRepository::log($userId, 'VENDOR_BANK_CONFIRM_REMINDED', VendorDetailsRepository::KINDS[$c['vendor_kind']]['table'], (int) $c['vendor_id'], null, null, null, 'Confirmation link e-mailed again to ' . $email);
        self::sendLink((string) $c['vendor_kind'], $vendor, VendorBankChangeRepository::find($changeId) ?? $c, $raw, $email);
        return null;
    }

    /** The vendor confirms through the link: last 4 digits + "These are our bank details". Returns the error or null. */
    public static function confirm(array $c, string $last4, bool $ticked): ?string
    {
        if (!$ticked) {
            return 'Tick "These are our bank details" to confirm.';
        }
        if (preg_replace('/\D/', '', $last4) !== (string) $c['account_last4']) {
            return 'The last 4 digits do not match the new account number — check them and type them again.';
        }
        if (!VendorBankChangeRepository::move((int) $c['id'], 'awaiting_confirmation', 'pending', ['confirmed_at' => 'NOW()', 'confirm_token_hash' => null])) {
            return 'These bank details are no longer waiting for your confirmation.';
        }
        AuditLogRepository::log(null, 'VENDOR_BANK_CONFIRMED', VendorDetailsRepository::KINDS[$c['vendor_kind']]['table'], (int) $c['vendor_id'], 'bank_account_number', null, '••••' . $c['account_last4'], 'Confirmed by them through the link e-mailed to ' . ($c['sent_to_email'] ?? '—'));
        return null;
    }

    /** "This is not us — cancel it": cancelled at once; the MD and Finance are alerted. */
    public static function notUs(array $c): bool
    {
        if (!VendorBankChangeRepository::move((int) $c['id'], 'awaiting_confirmation', 'cancelled', ['cancelled_at' => 'NOW()', 'cancelled_how' => 'not_me', 'confirm_token_hash' => null])) {
            return false;
        }
        $kind = (string) $c['vendor_kind'];
        $meta = VendorDetailsRepository::KINDS[$kind];
        $vendor = VendorDetailsRepository::find($kind, (int) $c['vendor_id']);
        AuditLogRepository::log(null, 'VENDOR_BANK_NOT_US', $meta['table'], (int) $c['vendor_id'], 'bank_account_number', null, '••••' . $c['account_last4'], 'They said "This is not us" — possible fraud');
        $by = $c['entered_source'] === 'vendor' ? 'through the Vendor Details Link' : 'by ' . (\App\Repositories\UserRepository::findById((int) ($c['entered_by'] ?? 0))['name'] ?? 'NexaCrest staff');
        BankDetailsCheck::alertMdAndFinance('Bank change refused by the ' . $meta['label'] . ' — possible fraud: ' . ($vendor[$meta['name']] ?? ''),
            ucfirst($meta['label']) . ' ' . ($vendor[$meta['name']] ?? '') . ' clicked "This is not us — cancel it" for the new bank details ' . self::name($c, $vendor) . ", entered {$by} on "
            . Dates::short(substr((string) $c['entered_at'], 0, 10)) . ".\nThe change is cancelled and their bank details have not changed. Please find out who entered them.\n"
            . 'Record: ' . rtrim((string) Env::get('APP_URL', ''), '/') . sprintf($meta['edit'], (int) $c['vendor_id']));
        return true;
    }

    /** Staff verify (or first-check in the two-people setting). Returns the error or null. */
    public static function verify(int $changeId, string $kind, int $vendorId, int $userId, array $post, bool $firstCheck): ?string
    {
        $c = VendorBankChangeRepository::find($changeId);
        if ($c === null || $c['vendor_kind'] !== $kind || (int) $c['vendor_id'] !== $vendorId) {
            return 'Bank change not found.';
        }
        if ($c['status'] === 'awaiting_confirmation') {
            return 'Verify is available once they confirm the new details (link sent to their registered e-mail).';
        }
        if ($c['status'] !== 'pending') {
            return 'This change is no longer waiting to be checked.';
        }
        if (($e = BankDetailsCheck::selfCheckError(isset($c['entered_by']) ? (int) $c['entered_by'] : null, $userId, (string) ($post['self_reason'] ?? ''))) !== null) {
            return $e;
        }
        $two = BankDetailsCheck::people() === 'two';
        if ($firstCheck && !empty($c['first_checked_by'])) {
            return 'The first check is already recorded — a different person now verifies it.';
        }
        $selfReason = (int) ($c['entered_by'] ?? 0) === $userId ? trim((string) ($post['self_reason'] ?? '')) : null;
        $table = VendorDetailsRepository::KINDS[$kind]['table'];
        if ($firstCheck || !$two || empty($c['first_checked_by'])) {
            if ($two && !$firstCheck) {
                return 'Record the first check before verifying.';
            }
            if (!$two && $firstCheck) {
                return 'One person verifies bank details — use Verify.';
            }
            [$checks, $err] = BankDetailsCheck::readChecks($post);
            if ($err !== null) {
                return $err;
            }
            $set = $checks + ['self_verify_reason' => $selfReason];
            if ($firstCheck) {
                if (!VendorBankChangeRepository::move($changeId, 'pending', 'pending', $set + ['first_checked_by' => $userId, 'first_checked_at' => 'NOW()'])) {
                    return 'This change is no longer waiting to be checked.';
                }
                AuditLogRepository::log($userId, 'VENDOR_BANK_FIRST_CHECK', $table, $vendorId, null, null, BankDetailsCheck::checksText($checks), $selfReason);
                return null;
            }
        } else {
            if ((int) $c['first_checked_by'] === $userId) {
                return 'A different person must verify — you already acted on this change.';
            }
            $set = ['self_verify_reason' => $selfReason ?? $c['self_verify_reason']];
        }
        $vendor = VendorDetailsRepository::find($kind, $vendorId);
        $first = !VendorDetailsRepository::bankDetails($vendor)['has_bank'];
        $usable = date('Y-m-d', strtotime('+' . BankDetailsCheck::waitDays($first) . ' days'));
        if (!VendorBankChangeRepository::move($changeId, 'pending', 'verified', $set + ['verified_by' => $userId, 'verified_at' => 'NOW()', 'usable_from' => $usable])) {
            return 'This change is no longer waiting to be checked.';
        }
        $after = VendorBankChangeRepository::find($changeId) ?? $c;
        AuditLogRepository::log($userId, 'VENDOR_BANK_DETAILS_VERIFIED', $table, $vendorId, 'bank_account_number', null, '••••' . $c['account_last4'],
            trim('Checked: ' . BankDetailsCheck::checksText($after) . '. Used from ' . $usable . '.' . ($selfReason ? ' Own entry — reason: ' . $selfReason : '')));
        self::applyDue($kind, $vendorId);
        return null;
    }

    public static function reject(int $changeId, string $kind, int $vendorId, int $userId, string $reason): ?string
    {
        $c = VendorBankChangeRepository::find($changeId);
        $reason = trim($reason);
        if ($c === null || $c['vendor_kind'] !== $kind || (int) $c['vendor_id'] !== $vendorId) {
            return 'Bank change not found.';
        }
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            return 'Give the reason (10 to 300 characters).';
        }
        foreach (['awaiting_confirmation', 'pending'] as $from) {
            if (VendorBankChangeRepository::move($changeId, $from, 'rejected', ['rejected_reason' => $reason, 'confirm_token_hash' => null])) {
                AuditLogRepository::log($userId, 'VENDOR_BANK_CHANGE_REJECTED', VendorDetailsRepository::KINDS[$kind]['table'], $vendorId, null, null, null, $reason);
                return null;
            }
        }
        return 'This change is no longer waiting to be checked.';
    }

    /** Verified changes whose waiting days are over are copied onto the supplier (verified). Returns how many. */
    public static function applyDue(?string $kind = null, ?int $vendorId = null, ?string $today = null): int
    {
        $n = 0;
        foreach (VendorBankChangeRepository::due($today ?? date('Y-m-d'), $kind, $vendorId) as $c) {
            if (!VendorBankChangeRepository::move((int) $c['id'], 'verified', 'applied', ['applied_at' => 'NOW()'])) {
                continue;
            }
            $bank = [];
            foreach (VendorDetailsRepository::BANK_FIELDS as $f) {
                $bank[$f] = $c[$f] ?? null;
            }
            $bank['bank_account_number'] = VendorBankChangeRepository::number($c);
            VendorDetailsRepository::saveBankDetails((string) $c['vendor_kind'], (int) $c['vendor_id'], $bank, (string) $c['entered_source'], (int) $c['verified_by']);
            AuditLogRepository::log((int) $c['verified_by'], 'VENDOR_BANK_DETAILS_SAVED', VendorDetailsRepository::KINDS[$c['vendor_kind']]['table'], (int) $c['vendor_id'], 'bank_account_number', null, '••••' . $c['account_last4'], 'Confirmed by them, verified and its waiting days over — now in use');
            $n++;
        }
        return $n;
    }

    /** Daily: changes not confirmed in 7 days are cancelled (the old account stays); due changes are put in use. */
    public static function daily(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $expired = 0;
        foreach (VendorBankChangeRepository::overdue($now) as $c) {
            if (VendorBankChangeRepository::move((int) $c['id'], 'awaiting_confirmation', 'cancelled', ['cancelled_at' => 'NOW()', 'cancelled_how' => 'expired', 'confirm_token_hash' => null])) {
                $expired++;
                AuditLogRepository::log(null, 'VENDOR_BANK_CONFIRM_EXPIRED', VendorDetailsRepository::KINDS[$c['vendor_kind']]['table'], (int) $c['vendor_id'], null, null, null, 'Not confirmed within ' . BankDetailsCheck::CONFIRM_DAYS . ' days — cancelled; the old account stays');
            }
        }
        return ['expired' => $expired, 'applied' => self::applyDue(null, null, substr($now, 0, 10))];
    }

    public static function statusText(array $c): string
    {
        $d = static fn ($v): string => Dates::short(substr((string) $v, 0, 10));
        return match ((string) $c['status']) {
            'awaiting_confirmation' => 'Waiting for their confirmation — ends ' . $d($c['confirm_by']) . (empty($c['sent_to_email']) ? ' (no registered e-mail — add it, then send the link again)' : ''),
            'pending' => 'Confirmed by them ' . $d($c['confirmed_at']) . ' — waiting for NexaCrest to verify',
            'verified' => 'Verified — used from ' . $d($c['usable_from']),
            'applied' => 'In use since ' . $d($c['applied_at']),
            'rejected' => 'Rejected — ' . (string) $c['rejected_reason'],
            'cancelled' => match ((string) ($c['cancelled_how'] ?? '')) {
                'not_me' => 'Cancelled — they said "This is not us" (MD and Finance alerted)',
                'expired' => 'Cancelled — not confirmed within ' . BankDetailsCheck::CONFIRM_DAYS . ' days',
                'replaced' => 'Cancelled — replaced by newer details',
                default => 'Cancelled',
            },
            default => (string) $c['status'],
        };
    }
}
