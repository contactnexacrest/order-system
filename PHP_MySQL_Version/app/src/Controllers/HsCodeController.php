<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\HsCodeRepository;
use App\Services\AuthService;

/**
 * Master list order creation's HS code field picks from — a genuine gap
 * before this: there was no format enforcement at all (the app's own
 * built-in default, '6802.93', was itself wrong — a real Indian HS code
 * is 6 or 8 plain digits, no dot). Deliberately gated behind its own
 * permission (manage_hs_codes), separate from ordinary order-entry
 * access, so a new code always passes through a privileged person
 * before it can ever appear on an order.
 */
final class HsCodeController
{
    public function index(array $params): void
    {
        View::render('hs_codes/index', ['codes' => HsCodeRepository::all()], 'layout/base');
    }

    public function create(array $params): void
    {
        $code = trim((string) ($_POST['code'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if (!preg_match('/^\d{6}$|^\d{8}$/', $code)) {
            Flash::set('error', 'HS code must be exactly 6 or 8 digits, no dots or other characters.');
            header('Location: /hs-codes');
            return;
        }
        if ($description === '') {
            Flash::set('error', 'A description is required.');
            header('Location: /hs-codes');
            return;
        }
        if (HsCodeRepository::findByCode($code)) {
            Flash::set('error', "HS code {$code} already exists.");
            header('Location: /hs-codes');
            return;
        }

        $user = AuthService::currentUser();
        $id = HsCodeRepository::create($code, $description, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'HS_CODE_ADDED', 'hs_codes', $id, null, null, "{$code}: {$description}");
        Flash::set('success', "HS code {$code} added.");
        header('Location: /hs-codes');
    }

    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description === '') {
            Flash::set('error', 'A description is required.');
            header('Location: /hs-codes');
            return;
        }
        $existing = HsCodeRepository::all();
        $row = null;
        foreach ($existing as $r) {
            if ((int) $r['id'] === $id) {
                $row = $r;
            }
        }
        if (!$row) {
            Flash::set('error', 'HS code not found.');
            header('Location: /hs-codes');
            return;
        }

        $user = AuthService::currentUser();
        HsCodeRepository::updateDescription($id, $description);
        AuditLogRepository::log((int) $user['id'], 'HS_CODE_UPDATED', 'hs_codes', $id, 'description', $row['description'], $description);
        Flash::set('success', 'Description updated.');
        header('Location: /hs-codes');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $user = AuthService::currentUser();
        HsCodeRepository::toggleActive($id);
        AuditLogRepository::log((int) $user['id'], 'HS_CODE_TOGGLED', 'hs_codes', $id, null, null, null);
        Flash::set('success', 'Status updated.');
        header('Location: /hs-codes');
    }

    public function delete(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $existing = HsCodeRepository::all();
        $row = null;
        foreach ($existing as $r) {
            if ((int) $r['id'] === $id) {
                $row = $r;
            }
        }
        if (!$row) {
            Flash::set('error', 'HS code not found.');
            header('Location: /hs-codes');
            return;
        }
        if (HsCodeRepository::usageCount($row['code']) > 0) {
            Flash::set('error', 'This HS code is already used on at least one order — deactivate it instead of deleting.');
            header('Location: /hs-codes');
            return;
        }

        $user = AuthService::currentUser();
        HsCodeRepository::delete($id);
        AuditLogRepository::log((int) $user['id'], 'HS_CODE_DELETED', 'hs_codes', $id, null, $row['code'], null);
        Flash::set('success', 'HS code deleted.');
        header('Location: /hs-codes');
    }
}
