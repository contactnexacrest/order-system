<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\VendorBankChangeRepository;
use App\Repositories\VendorDetailsRepository;
use App\Services\AuthService;
use App\Services\VendorBankChangeService;

/**
 * Owner decisions 11 Nov 2026, point 18 (D-294): a supplier or logistics partner confirms new
 * bank details through the no-login link e-mailed to their registered e-mail (Confirm / "This is
 * not us — cancel it"); staff then verify them on the supplier's page.
 * Node twin: src/controllers/vendorBankChangeController.js.
 */
final class VendorBankChangeController
{
    // ------------------------------------------------------------ public (no login)

    private static function changeOrExpired(string $token): ?array
    {
        $c = VendorBankChangeRepository::findByToken($token);
        if ($c === null) {
            View::render('vendor_details/link_expired', [], 'layout/bare');
            return null;
        }
        return $c;
    }

    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $c = self::changeOrExpired($token);
        if ($c === null) {
            return;
        }
        $kind = (string) $c['vendor_kind'];
        $vendor = VendorDetailsRepository::find($kind, (int) $c['vendor_id']);
        View::render('vendor_details/bank_confirm', ['wrapClass' => 'intake-wrap', 'token' => $token, 'c' => $c, 'meta' => VendorDetailsRepository::KINDS[$kind],
            'vendor' => $vendor, 'current' => VendorDetailsRepository::bankDetails($vendor)], 'layout/bare');
    }

    public function confirm(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $c = self::changeOrExpired($token);
        if ($c === null) {
            return;
        }
        $error = VendorBankChangeService::confirm($c, (string) ($_POST['last4'] ?? ''), !empty($_POST['ours']));
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /vendor-bank-confirm/{$token}");
            return;
        }
        View::render('vendor_details/bank_confirm_done', ['confirmed' => true], 'layout/bare');
    }

    public function notUs(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $c = self::changeOrExpired($token);
        if ($c === null) {
            return;
        }
        VendorBankChangeService::notUs($c);
        View::render('vendor_details/bank_confirm_done', ['confirmed' => false], 'layout/bare');
    }

    // ------------------------------------------------------------ staff (supplier / logistics partner page)

    private static function back(string $kind, int $id): void
    {
        header('Location: ' . sprintf(VendorDetailsRepository::KINDS[$kind]['edit'], $id) . '#bank-changes');
    }

    private static function act(string $kind, array $params, string $action): void
    {
        $id = (int) ($params['id'] ?? 0);
        $changeId = (int) ($params['changeId'] ?? 0);
        $userId = (int) AuthService::currentUserId();
        $error = match ($action) {
            'verify' => VendorBankChangeService::verify($changeId, $kind, $id, $userId, $_POST, false),
            'check' => VendorBankChangeService::verify($changeId, $kind, $id, $userId, $_POST, true),
            'reject' => VendorBankChangeService::reject($changeId, $kind, $id, $userId, (string) ($_POST['reason'] ?? '')),
            default => (static function () use ($changeId, $kind, $id, $userId): ?string {
                $c = VendorBankChangeRepository::find($changeId);
                return $c === null || $c['vendor_kind'] !== $kind || (int) $c['vendor_id'] !== $id ? 'Bank change not found.' : VendorBankChangeService::resend($changeId, $userId);
            })(),
        };
        Flash::set($error === null ? 'success' : 'error', $error ?? [
            'verify' => 'Bank details verified. They are used from the date shown; until then payments go to the account on file.',
            'check' => 'First check recorded. A different person now verifies it.',
            'reject' => 'Bank change rejected.',
            'resend' => 'Confirmation link e-mailed again.',
        ][$action]);
        self::back($kind, $id);
    }

    public function supplierVerify(array $p): void { self::act('supplier', $p, 'verify'); }
    public function supplierCheck(array $p): void { self::act('supplier', $p, 'check'); }
    public function supplierReject(array $p): void { self::act('supplier', $p, 'reject'); }
    public function supplierResend(array $p): void { self::act('supplier', $p, 'resend'); }
    public function partnerVerify(array $p): void { self::act('logistics_partner', $p, 'verify'); }
    public function partnerCheck(array $p): void { self::act('logistics_partner', $p, 'check'); }
    public function partnerReject(array $p): void { self::act('logistics_partner', $p, 'reject'); }
    public function partnerResend(array $p): void { self::act('logistics_partner', $p, 'resend'); }
}
