<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;
use App\Services\AssociateTds;
use App\Services\AuthService;
use App\Services\PermissionService;

/**
 * Owner decision 2 Nov 2026 (D-235): TDS on associate commission — the
 * settings (Settings → Associate TDS) and the quarterly TDS register with
 * the challan Accounts deposited each deduction with.
 */
final class AssociateTdsController
{
    /** setting key => [label, kind] */
    private const FIELDS = [
        'associate_tds_section' => 'TDS section',
        'associate_tds_pct' => 'TDS rate (%)',
        'associate_tds_threshold_inr' => 'Threshold per associate per financial year (₹)',
        'associate_tds_no_pan_pct' => 'Rate without a verified PAN (%)',
        'associate_tds_foreign' => 'Deduct TDS for associates abroad',
        'associate_15cb_required' => 'Form 15CB required for payments abroad',
        'zoho_tds_tax_id' => 'Zoho Books TDS tax id',
    ];

    public function settings(array $params): void
    {
        View::render('settings/associate_tds', ['s' => AssociateTds::settings()], 'layout/base');
    }

    public function saveSettings(array $params): void
    {
        $user = AuthService::currentUser();
        $section = strtoupper(trim((string) ($_POST['associate_tds_section'] ?? '')));
        $rate = filter_var($_POST['associate_tds_pct'] ?? '', FILTER_VALIDATE_FLOAT);
        $threshold = filter_var($_POST['associate_tds_threshold_inr'] ?? '', FILTER_VALIDATE_FLOAT);
        $noPan = filter_var($_POST['associate_tds_no_pan_pct'] ?? '', FILTER_VALIDATE_FLOAT);
        $taxId = trim((string) ($_POST['zoho_tds_tax_id'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!preg_match('/^[0-9]{3}[A-Z]{0,3}$/', $section) || $rate === false || $rate < 0 || $rate > 30 || $threshold === false || $threshold < 0 || $threshold > 10000000
            || $noPan === false || $noPan < 0 || $noPan > 40 || !preg_match('/^[0-9A-Za-z_-]{0,40}$/', $taxId)) {
            Flash::set('error', 'Enter the section like 194H, the rate as 0–30 %, the threshold as ₹0–1,00,00,000, the rate without PAN as 0–40 %, and the Zoho tax id as letters and digits only (or leave it blank).');
            header('Location: /settings/associate-tds');
            return;
        }
        if (mb_strlen($reason) < 10) {
            Flash::set('error', 'Give the reason for the change (at least 10 characters), e.g. "CA confirmed 194H at 2% on 2 Nov".');
            header('Location: /settings/associate-tds');
            return;
        }
        $values = [
            'associate_tds_section' => $section,
            'associate_tds_pct' => number_format((float) $rate, 2, '.', ''),
            'associate_tds_threshold_inr' => (string) round((float) $threshold, 2),
            'associate_tds_no_pan_pct' => number_format((float) $noPan, 2, '.', ''),
            'associate_tds_foreign' => !empty($_POST['associate_tds_foreign']) ? '1' : '0',
            'associate_15cb_required' => !empty($_POST['associate_15cb_required']) ? '1' : '0',
            'zoho_tds_tax_id' => $taxId,
        ];
        $before = IncentiveRepository::settings();
        $changed = 0;
        foreach ($values as $k => $v) {
            if ((string) ($before[$k] ?? '') !== $v) {
                IncentiveRepository::setSetting($k, $v, (int) $user['id']);
                AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_TDS_SETTING_CHANGED', 'incentive_settings', null, $k, $before[$k] ?? null, $v, $reason);
                $changed++;
            }
        }
        Flash::set('success', $changed === 0 ? 'Nothing changed.' : 'Saved. Payments recorded from now on use the new values; payments already recorded keep the TDS they were paid with.');
        header('Location: /settings/associate-tds');
    }

    public function register(array $params): void
    {
        $fyStart = (string) ($_GET['fy'] ?? '');
        if (!preg_match('/^\d{4}-04-01$/', $fyStart)) {
            $fyStart = AssociateTds::fyBounds(date('Y-m-d'))[0];
        }
        $quarter = isset($_GET['q']) && in_array((int) $_GET['q'], [1, 2, 3, 4], true) ? (int) $_GET['q'] : null;
        $rows = AssociateTds::register($fyStart, $quarter);
        $fyLabel = AssociateTds::fyLabel($fyStart);
        if (($_GET['format'] ?? '') === 'csv') {
            \App\Helpers\Csv::stream('associate-tds-' . str_replace(' ', '', $fyLabel) . ($quarter !== null ? '-Q' . $quarter : '') . '.csv',
                ['Quarter', 'Paid on', 'Associate', 'PAN', 'Based in', 'Order', 'Gross (INR)', 'TDS (INR)', 'Section', 'Rate %', 'Net paid (INR)', 'Reason', 'Bank reference', 'Challan no.', 'Challan date', 'Form 16A uploaded'],
                array_map(static fn (array $r): array => ['Q' . $r['quarter'], $r['paid_on'], $r['name'], $r['pan'], $r['based_in'] === 'india' ? 'India' : 'Abroad ' . $r['country'],
                    $r['buyer_inquiry_ref'] ?: $r['order_reference'], number_format((float) $r['paid_inr'] + (float) ($r['tds_inr'] ?? 0), 2, '.', ''), number_format((float) ($r['tds_inr'] ?? 0), 2, '.', ''),
                    $r['tds_section'], $r['tds_rate'], $r['paid_inr'], $r['tds_reason'], $r['paid_reference'], $r['tds_challan_no'], $r['tds_challan_date'], (int) $r['form16a_count'] > 0 ? 'yes' : 'no'], $rows));
        }
        $user = AuthService::currentUser();
        View::render('associates/tds', [
            'rows' => $rows, 'fyStart' => $fyStart, 'fyLabel' => $fyLabel, 'quarter' => $quarter,
            'canEditChallan' => PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'pay_incentives'),
        ], 'layout/base');
    }

    public function saveChallan(array $params): void
    {
        $user = AuthService::currentUser();
        $id = (int) $params['payoutId'];
        $p = IncentiveRepository::findPayout($id);
        $back = '/associates/tds' . (isset($_POST['fy']) && preg_match('/^\d{4}-04-01$/', (string) $_POST['fy']) ? '?fy=' . $_POST['fy'] : '');
        if ($p === null || $p['payee_kind'] !== 'associate' || $p['status'] !== 'paid' || (float) ($p['tds_inr'] ?? 0) <= 0) {
            http_response_code(404);
            echo 'No TDS payment found.';
            return;
        }
        $no = trim((string) ($_POST['tds_challan_no'] ?? ''));
        $date = (string) ($_POST['tds_challan_date'] ?? '');
        if (!preg_match('/^[0-9A-Za-z\/ -]{5,40}$/', $no) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d') || $date < (string) $p['paid_on']) {
            Flash::set('error', 'Enter the challan (CIN / BSR + serial) as 5–40 letters or digits, and its date — on or after the payment date, and not in the future.');
            header('Location: ' . $back);
            return;
        }
        IncentiveRepository::setPayoutChallan($id, $no, $date);
        AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_TDS_CHALLAN_RECORDED', 'incentive_payouts', $id, 'tds_challan_no', $p['tds_challan_no'] ?? null, $no . ' (' . $date . ')');
        Flash::set('success', 'Challan saved.');
        header('Location: ' . $back);
    }
}
