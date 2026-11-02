<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Csv;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\ClientRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\ComplianceTrackingService;
use App\Services\FileUploadService;
use App\Services\MoneyVisibility;

/**
 * Owner decision (2 Nov 2026, gap-list Q-6): ECGC buyer limit and shipment
 * declaration, incentive claim tracking, and the Missing eBRC / FIRC report.
 * Node twin: src/controllers/complianceTrackingController.js.
 */
final class ComplianceTrackingController
{
    /** POST /clients/{id}/ecgc — the buyer's ECGC-approved credit limit. */
    public function saveClientEcgc(array $params): void
    {
        $clientId = (int) $params['id'];
        $client = ClientRepository::find($clientId);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }
        $user = AuthService::currentUser();
        $back = "/clients/{$clientId}#ecgc";
        $amount = trim((string) ($_POST['ecgc_limit_amount'] ?? ''));
        $currency = strtoupper(trim((string) ($_POST['ecgc_limit_currency'] ?? '')));
        $approvedOn = trim((string) ($_POST['ecgc_limit_approved_on'] ?? ''));
        $validTo = trim((string) ($_POST['ecgc_limit_valid_to'] ?? ''));
        $ref = trim((string) ($_POST['ecgc_limit_ref'] ?? ''));
        $error = null;
        if ($amount === '' || !is_numeric($amount) || (float) $amount < 0 || (float) $amount > 9999999999) {
            $error = 'Enter the credit limit ECGC approved for this buyer (a number, 0 or more).';
        } elseif (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $error = 'Choose the currency of the ECGC limit.';
        } elseif ($approvedOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvedOn)) {
            $error = 'Enter the approval date as a date.';
        } elseif ($validTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validTo)) {
            $error = 'Enter the "valid until" date as a date.';
        } elseif ($validTo !== '' && $approvedOn !== '' && $validTo < $approvedOn) {
            $error = 'The limit cannot end before it was approved.';
        } elseif (mb_strlen($ref) > 100) {
            $error = 'The ECGC approval reference is too long (100 characters at most).';
        }
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: ' . $back);
            return;
        }
        $fileId = $client['ecgc_limit_file_id'] ?? null;
        if (!empty($_FILES['ecgc_limit_file']) && ($_FILES['ecgc_limit_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $fileId = FileUploadService::handleUpload('ecgc_limit_file', 'ecgc_limit_letter', 'clients/' . ($client['client_unique_number'] ?? $clientId) . '/ecgc', $clientId, null, (int) $user['id'], null, 'ECGC', 'ECGC credit-limit approval');
            } catch (\Throwable $e) {
                Flash::set('error', 'The approval letter could not be saved: ' . $e->getMessage());
                header('Location: ' . $back);
                return;
            }
        }
        $old = ($client['ecgc_limit_currency'] ?? '') . ' ' . ($client['ecgc_limit_amount'] ?? '—');
        Database::connection()->prepare(
            'UPDATE clients SET ecgc_limit_amount = :a, ecgc_limit_currency = :c, ecgc_limit_approved_on = :ap, ecgc_limit_valid_to = :vt, ecgc_limit_ref = :r, ecgc_limit_file_id = :f WHERE id = :id'
        )->execute([
            'a' => round((float) $amount, 2), 'c' => $currency, 'ap' => $approvedOn ?: null, 'vt' => $validTo ?: null,
            'r' => $ref ?: null, 'f' => $fileId, 'id' => $clientId,
        ]);
        AuditLogRepository::log((int) $user['id'], 'CLIENT_ECGC_LIMIT_SET', 'clients', $clientId, 'ecgc_limit_amount', $old, $currency . ' ' . number_format((float) $amount, 2, '.', ''), $ref !== '' ? 'ECGC ref ' . $ref : null);
        Flash::set('success', 'ECGC credit limit saved.');
        header('Location: ' . $back);
    }

    /** POST /orders/{id}/ecgc-declaration — shipment declared to ECGC. */
    public function declareShipment(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $back = "/orders/{$orderId}#ecgc-cover";
        $on = trim((string) ($_POST['ecgc_declared_on'] ?? ''));
        $ref = trim((string) ($_POST['ecgc_declaration_ref'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > date('Y-m-d')) {
            Flash::set('error', 'Enter the date the shipment was declared to ECGC (not in the future).');
            header('Location: ' . $back);
            return;
        }
        if (mb_strlen($ref) < 3 || mb_strlen($ref) > 100) {
            Flash::set('error', 'Enter the ECGC declaration reference (3 to 100 characters).');
            header('Location: ' . $back);
            return;
        }
        Database::connection()->prepare('UPDATE orders SET ecgc_declared_on = :d, ecgc_declaration_ref = :r WHERE id = :id')
            ->execute(['d' => $on, 'r' => $ref, 'id' => $orderId]);
        AuditLogRepository::log((int) $user['id'], 'ORDER_ECGC_DECLARED', 'orders', $orderId, 'ecgc_declared_on', $order['ecgc_declared_on'] ?? null, $on, 'ECGC declaration ' . $ref);
        Flash::set('success', 'Shipment declaration to ECGC recorded.');
        header('Location: ' . $back);
    }

    /** POST /orders/{id}/export-benefits/{benefitId}/tracking — sanctioned amount, deadline, status. */
    public function saveClaimTracking(array $params): void
    {
        $orderId = (int) $params['id'];
        $claim = CaExportBenefitRepository::find((int) $params['benefitId']);
        if (!$claim || (int) $claim['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Claim not found.';
            return;
        }
        $user = AuthService::currentUser();
        $error = ComplianceTrackingService::saveClaimTracking($claim, $_POST);
        if ($error !== null) {
            Flash::set('error', $error);
        } else {
            AuditLogRepository::log((int) $user['id'], 'EXPORT_BENEFIT_CLAIM_TRACKED', 'ca_export_benefits', (int) $claim['id'], 'claim_status',
                (string) ($claim['claim_status'] ?? 'filed'), (string) ($_POST['claim_status'] ?? ''),
                $claim['scheme_name'] . ' — sanctioned ' . (trim((string) ($_POST['sanctioned_amount'] ?? '')) ?: '—') . ', deadline ' . (trim((string) ($_POST['claim_deadline'] ?? '')) ?: 'default'));
            Flash::set('success', $claim['scheme_name'] . ' claim updated.');
        }
        header("Location: /orders/{$orderId}#step-13g-benefits");
    }

    /** GET /reports/missing-ebrc (?format=csv) */
    public function missingEbrcReport(array $params): void
    {
        $rows = ComplianceTrackingService::missingFircReport();
        $showAmounts = MoneyVisibility::can(MoneyVisibility::BUYER_PAYMENTS);
        if (($_GET['format'] ?? '') === 'csv') {
            $headers = ['Order', 'Buyer', 'Payment', 'Received on', 'Days since receipt', 'Owner'];
            if ($showAmounts) {
                array_splice($headers, 3, 0, ['Currency', 'Amount']);
            }
            $out = [];
            foreach ($rows as $r) {
                $line = [$r['order_reference'], $r['client'], $r['label'], $r['received_at'], $r['days'], $r['owners']];
                if ($showAmounts) {
                    array_splice($line, 3, 0, [$r['currency'], number_format($r['amount'], 2, '.', '')]);
                }
                $out[] = $line;
            }
            Csv::stream('missing-ebrc-firc-' . date('Y-m-d') . '.csv', $headers, $out);
        }
        View::render('reports/missing_ebrc', [
            'title' => 'Missing eBRC / FIRC',
            'rows' => $rows,
            'showAmounts' => $showAmounts,
            'alertDays' => (int) (\App\Repositories\CompanySettingsRepository::get('fema_realization_alert_days') ?? '270'),
        ]);
    }
}
