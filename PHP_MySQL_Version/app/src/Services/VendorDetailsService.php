<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Helpers\BankDetailsInput;
use App\Repositories\AuditLogRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\VendorDetailsRepository;

/**
 * Owner request (2026-10-27): staff-side handling of a supplier's or
 * logistics partner's bank details and of the Vendor Details Link, shared
 * by the Suppliers and the Logistics Partners directories.
 */
final class VendorDetailsService
{
    /** D-294: the vendor's e-mail as it was before this save (the REGISTERED e-mail the confirm link goes to). */
    private static array $emailBefore = [];

    /** Validates the form's bank section without saving — null when it is fine. */
    public static function bankInputError(string $kind, ?int $id): ?string
    {
        $vendor = $id !== null ? VendorDetailsRepository::find($kind, $id) : null;
        if ($vendor !== null) {
            self::$emailBefore[$kind . ':' . $id] = trim((string) ($vendor['email'] ?? ''));
        }
        $current = VendorDetailsRepository::bankDetails($vendor);
        return BankDetailsInput::collect($_POST, $current, false)['error'];
    }

    /**
     * Owner decisions 11 Nov 2026, point 18 (D-294): bank details typed by staff (on the vendor's behalf,
     * e.g. from their e-mail) no longer replace the account in use. They become a change waiting for the
     * vendor's confirmation — a link e-mailed to their REGISTERED e-mail (the one on file before this save;
     * for a new record, the e-mail entered with it) — then for staff verification (VendorBankChangeService).
     */
    public static function applyStaffBankInput(string $kind, int $id, int $userId): ?string
    {
        $vendor = VendorDetailsRepository::find($kind, $id);
        $current = VendorDetailsRepository::bankDetails($vendor);
        $result = BankDetailsInput::collect($_POST, $current, false);
        if ($result['error'] !== null) {
            return $result['error'];
        }
        if ($result['bank'] !== null) {
            $registered = self::$emailBefore[$kind . ':' . $id] ?? trim((string) ($vendor['email'] ?? ''));
            $r = VendorBankChangeService::propose($kind, $id, $result['bank'], 'staff', $userId, $registered !== '' ? $registered : null);
            \App\Helpers\Flash::set('info', $r['emailed_to'] !== null
                ? 'The new bank details are saved as a change: a confirmation link was e-mailed to ' . $r['emailed_to'] . '. They are used only after they confirm (within ' . BankDetailsCheck::CONFIRM_DAYS . ' days) and someone other than you verifies them.'
                : 'The new bank details are saved as a change, but there is no registered e-mail to send the confirmation link to — add their e-mail, then use "Send the link again" under Bank details changes.');
        }
        return null;
    }

    /** Makes a fresh link (replacing any older one), emails it to the vendor when an email is on file, and returns it. */
    public static function sendDetailsLink(string $kind, int $id, int $userId): array
    {
        $meta = VendorDetailsRepository::KINDS[$kind];
        $vendor = VendorDetailsRepository::find($kind, $id);
        $link = rtrim(Env::get('APP_URL', ''), '/') . '/vendor-details/' . VendorDetailsRepository::generateDetailsToken($kind, $id);
        $email = trim((string) ($vendor['email'] ?? ''));
        if ($email !== '') {
            $rendered = EmailTemplateRepository::renderOrDefault(
                'vendor_details_link',
                ['{vendor_name}' => (string) $vendor[$meta['name']], '{vendor_details_link}' => $link],
                'NexaCrest — please confirm your company and bank details',
                "Hello,\n\n"
                    . "To set you up for payments from NexaCrest, please fill in your company and bank details on this secure link — no login needed:\n{vendor_details_link}\n\n"
                    . "Please keep a cancelled cheque or bank letter ready; our team may ask for it to verify the account. The link works once and for 30 days.\n\n"
                    . "NexaCrest International Private Limited"
            );
            $sent = EmailService::sendPlainText($email, $rendered['subject'], $rendered['body'], false, 'supplier_links', 'supplier');
        } else {
            $sent = false;
        }
        AuditLogRepository::log($userId, 'VENDOR_DETAILS_LINK_GENERATED', $meta['table'], $id, null, null, null,
            $sent ? 'Emailed to ' . $email : ($email !== '' ? 'Not e-mailed (' . EmailPolicy::notSentReason('supplier_links', 'supplier') . ') — hand the link over directly' : 'No email on file — hand the link over directly'));
        // D-280: emailed_to only when the e-mail really went out.
        return ['link' => $link, 'emailed_to' => $sent ? $email : null, 'email' => $email !== '' ? $email : null,
            'message' => EmailDispatchService::linkMailOutcome('Vendor Details Link', $email !== '' ? $email : null, $sent, $link, 'supplier_links', 'supplier')];
    }
}
