<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\BankDetailsInput;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\VendorDetailsRepository;

/**
 * Owner request (2026-10-27): public, no-login Vendor Details Link — a
 * supplier or logistics partner fills in their own company and bank
 * details. One use: the link is cleared on submit. Their bank details land
 * as "not verified" until staff check them; the existing account number is
 * never shown back (only its last four digits).
 */
final class VendorDetailsController
{
    private function vendorOrExpired(string $token): ?array
    {
        $found = VendorDetailsRepository::findByValidDetailsToken($token);
        if (!$found) {
            View::render('vendor_details/link_expired', [], 'layout/bare');
            return null;
        }
        return $found;
    }

    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $found = $this->vendorOrExpired($token);
        if (!$found) {
            return;
        }
        [$kind, $vendor] = $found;
        View::render('vendor_details/show', [
            'wrapClass' => 'intake-wrap',
            'token' => $token,
            'kind' => $kind,
            'meta' => VendorDetailsRepository::KINDS[$kind],
            'vendor' => $vendor,
            'bank' => VendorDetailsRepository::bankDetails($vendor),
        ], 'layout/bare');
    }

    public function submit(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $found = $this->vendorOrExpired($token);
        if (!$found) {
            return;
        }
        [$kind, $vendor] = $found;
        $meta = VendorDetailsRepository::KINDS[$kind];
        $id = (int) $vendor['id'];

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Enter a valid email address.');
            header("Location: /vendor-details/{$token}");
            return;
        }
        $result = BankDetailsInput::collect($_POST, VendorDetailsRepository::bankDetails($vendor), true);
        if ($result['error'] !== null) {
            Flash::set('error', $result['error'] . ' Nothing was saved.');
            header("Location: /vendor-details/{$token}");
            return;
        }

        $details = [];
        foreach (array_merge(VendorDetailsRepository::DETAIL_FIELDS, [$meta['contact']]) as $f) {
            if (array_key_exists($f, $_POST)) {
                $details[$f] = trim((string) $_POST[$f]) ?: null;
            }
        }
        VendorDetailsRepository::saveDetailsFromVendor($kind, $id, $details);
        if ($result['bank'] !== null) {
            VendorDetailsRepository::saveBankDetails($kind, $id, $result['bank'], 'vendor', null);
        }
        VendorDetailsRepository::markDetailsSubmitted($kind, $id);
        $after = VendorDetailsRepository::bankDetails(VendorDetailsRepository::find($kind, $id));
        AuditLogRepository::log(
            null,
            'VENDOR_DETAILS_SUBMITTED',
            $meta['table'],
            $id,
            'bank_account_number',
            null,
            VendorDetailsRepository::maskAccount($after['bank_account_number']),
            "{$vendor[$meta['name']]} filled in their details through the Vendor Details Link — verify the bank account before paying."
        );
        View::render('vendor_details/thanks', ['vendor' => $vendor, 'meta' => $meta], 'layout/bare');
    }
}
