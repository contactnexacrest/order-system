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
    /** Validates the form's bank section without saving — null when it is fine. */
    public static function bankInputError(string $kind, ?int $id): ?string
    {
        $current = VendorDetailsRepository::bankDetails($id !== null ? VendorDetailsRepository::find($kind, $id) : null);
        return BankDetailsInput::collect($_POST, $current, false)['error'];
    }

    /**
     * Saves the form's bank section entered by staff (on the vendor's behalf,
     * e.g. from their email). "verified" is set only when staff tick that they
     * checked it (cancelled cheque / bank letter); any change without the
     * tick clears the mark.
     */
    public static function applyStaffBankInput(string $kind, int $id, int $userId): ?string
    {
        $vendor = VendorDetailsRepository::find($kind, $id);
        $current = VendorDetailsRepository::bankDetails($vendor);
        $result = BankDetailsInput::collect($_POST, $current, false);
        if ($result['error'] !== null) {
            return $result['error'];
        }
        $verifiedTick = !empty($_POST['bank_verified']);
        $table = VendorDetailsRepository::KINDS[$kind]['table'];
        if ($result['bank'] !== null) {
            VendorDetailsRepository::saveBankDetails($kind, $id, $result['bank'], 'staff', $verifiedTick ? $userId : null);
            AuditLogRepository::log($userId, 'VENDOR_BANK_DETAILS_SAVED', $table, $id, 'bank_account_number', VendorDetailsRepository::maskAccount($current['bank_account_number']), VendorDetailsRepository::maskAccount($result['bank']['bank_account_number'] ?? $current['bank_account_number']), $verifiedTick ? 'Entered by staff and marked verified' : 'Entered by staff');
        } elseif ($verifiedTick && $current['has_bank'] && empty($current['bank_verified_at'])) {
            VendorDetailsRepository::markBankVerified($kind, $id, $userId);
            AuditLogRepository::log($userId, 'VENDOR_BANK_DETAILS_VERIFIED', $table, $id, null, null, VendorDetailsRepository::maskAccount($current['bank_account_number']));
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
            EmailService::sendPlainText($email, $rendered['subject'], $rendered['body']);
        }
        AuditLogRepository::log($userId, 'VENDOR_DETAILS_LINK_GENERATED', $meta['table'], $id, null, null, null, $email !== '' ? 'Emailed to ' . $email : 'No email on file — hand the link over directly');
        return ['link' => $link, 'emailed_to' => $email !== '' ? $email : null];
    }
}
