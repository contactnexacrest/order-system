<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\IncentiveService;

/**
 * Team incentive program and associates (owner decision 2026-10-30,
 * mockups approved). Settings, associates and each order's incentive:
 * manage_incentives (you and the MD). Payouts: pay_incentives (Accounts) —
 * person, order and amount only. My Incentives: view_own_incentives — a
 * person's own amounts, once the buyer has fully paid.
 */
final class IncentiveController
{
    public function settings(array $params): void
    {
        View::render('incentives/settings', [
            'settings' => IncentiveService::settings(),
            'users' => UserRepository::listActive(),
            'userRoles' => IncentiveRepository::userRoles(),
        ], 'layout/base');
    }

    public function saveSettings(array $params): void
    {
        $user = AuthService::currentUser();
        $userId = (int) $user['id'];
        $before = IncentiveService::settings();

        $minProfit = filter_var($_POST['min_profit_inr'] ?? '', FILTER_VALIDATE_FLOAT);
        $bonus = filter_var($_POST['new_client_bonus_inr'] ?? '', FILTER_VALIDATE_FLOAT);
        $cutoff = (string) ($_POST['cutoff_day'] ?? '');
        $errors = [];
        if ($minProfit === false || $minProfit < 0) {
            $errors[] = 'Enter the minimum order profit as an amount of 0 or more.';
        }
        if ($bonus === false || $bonus < 0) {
            $errors[] = 'Enter the new client bonus as an amount of 0 or more (0 = off).';
        }
        if ($cutoff !== 'last' && (!ctype_digit($cutoff) || (int) $cutoff < 1 || (int) $cutoff > 28)) {
            $errors[] = 'Choose an incentive cut-off day from 1 to 28, or the last day of the month.';
        }
        $table = [];
        foreach ((array) ($_POST['table_from'] ?? []) as $i => $from) {
            $reward = $_POST['table_reward'][$i] ?? '';
            if (trim((string) $from) === '' && trim((string) $reward) === '') {
                continue;
            }
            if (!is_numeric($from) || !is_numeric($reward) || (float) $from <= 0 || (float) $reward <= 0) {
                $errors[] = 'Each reward table row needs a profit and a reward above 0 (leave both empty to drop the row).';
                break;
            }
            $table[] = ['from' => (float) $from, 'reward' => (float) $reward];
        }
        // QA Round 6, R6-N12: two rows with one profit threshold — which reward applies would be undefined.
        $froms = array_column($table, 'from');
        if (count($froms) !== count(array_unique($froms, SORT_REGULAR))) {
            $errors[] = 'Two reward table rows have the same order profit — each row needs its own.';
        }
        $split = [];
        foreach (array_keys(IncentiveRepository::ROLES) as $role) {
            $v = $_POST['split'][$role] ?? '';
            if (!is_numeric($v) || (float) $v < 0) {
                $errors[] = 'Enter every role\'s share as a % of 0 or more.';
                break;
            }
            $split[$role] = (float) $v;
        }
        if (count($split) === count(IncentiveRepository::ROLES) && abs(array_sum($split) - 100) > 0.001) {
            $errors[] = 'The role shares must add up to 100% (they add up to ' . rtrim(rtrim(number_format(array_sum($split), 2), '0'), '.') . '%).';
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: /incentives/settings');
            return;
        }

        // Orders already fully paid keep the settings they were paid under.
        IncentiveService::lockDue($userId);

        $enabled = !empty($_POST['program_enabled']);
        usort($table, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        $values = [
            'program_enabled' => $enabled ? '1' : '0',
            'min_profit_inr' => (string) $minProfit,
            'reward_table' => (string) json_encode($table),
            'new_client_bonus_inr' => (string) $bonus,
            'role_split' => (string) json_encode($split),
            'cutoff_day' => $cutoff,
        ];
        if ($enabled && $before['start_date'] === '') {
            $values['program_start_date'] = date('Y-m-d');
        }
        $old = IncentiveRepository::settings();
        foreach ($values as $key => $value) {
            if (($old[$key] ?? null) !== $value) {
                IncentiveRepository::setSetting($key, $value, $userId);
                AuditLogRepository::log($userId, 'INCENTIVE_SETTING_CHANGED', 'incentive_settings', null, $key, $old[$key] ?? null, $value);
            }
        }

        $rolesByUser = [];
        // Keys are "u<user id>", the same form as the Node stack (whose form parser would read bare numbers as a list).
        foreach ((array) ($_POST['user_roles'] ?? []) as $key => $roles) {
            $uid = (int) ltrim((string) $key, 'u');
            if ($uid > 0) {
                $rolesByUser[$uid] = array_values(array_filter((array) $roles, static fn ($r): bool => isset(IncentiveRepository::ROLES[$r])));
            }
        }
        if ($rolesByUser != IncentiveRepository::userRoles()) {
            IncentiveRepository::replaceUserRoles($rolesByUser);
            AuditLogRepository::log($userId, 'INCENTIVE_ROLES_CHANGED', 'user_incentive_roles', null, null, null, (string) json_encode($rolesByUser));
        }
        Flash::set('success', 'Team incentive settings saved.');
        header('Location: /incentives/settings');
    }

    public function saveOrder(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        if (OrderRepository::find($orderId) === null) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && !empty($row['locked_at'])) {
            Flash::set('error', 'This order\'s incentive is already fixed (the buyer has fully paid). Use "Work it out again" first.');
            header("Location: /orders/{$orderId}#financials-12d");
            return;
        }
        $override = filter_var($_POST['reward_override_inr'] ?? '', FILTER_VALIDATE_FLOAT);
        $mode = (string) ($_POST['associate_mode'] ?? 'auto');
        if ($override === false || $override < 0 || !in_array($mode, ['auto', 'yes', 'no'], true)) {
            Flash::set('error', 'Enter the reward for this order as an amount of 0 or more (0 = use the settings).');
            header("Location: /orders/{$orderId}#financials-12d");
            return;
        }
        $fields = ['reward_override_inr' => $override, 'is_excluded' => !empty($_POST['is_excluded']) ? 1 : 0, 'associate_mode' => $mode];
        IncentiveRepository::saveOrder($orderId, $fields, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVE_SET', 'orders', $orderId, 'incentive', $row !== null ? (string) json_encode(array_intersect_key($row, $fields)) : null, (string) json_encode($fields));
        Flash::set('success', 'Team incentive for this order saved.');
        header("Location: /orders/{$orderId}#financials-12d");
    }

    public function saveOrderPeople(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && !empty($row['locked_at'])) {
            Flash::set('error', 'This order\'s incentive is already fixed. Use "Work it out again" first.');
            header("Location: /orders/{$orderId}#financials-12d");
            return;
        }
        if (!empty($_POST['reset'])) {
            IncentiveRepository::replaceOrderPeople($orderId, []);
            IncentiveRepository::saveOrder($orderId, ['people_customised' => 0], (int) $user['id']);
        } else {
            $active = array_map(static fn (array $u): int => (int) $u['id'], UserRepository::listActive());
            $people = [];
            foreach (array_keys(IncentiveRepository::ROLES) as $role) {
                $people[$role] = array_values(array_intersect(array_map('intval', (array) ($_POST['people'][$role] ?? [])), $active));
            }
            IncentiveRepository::replaceOrderPeople($orderId, $people);
            IncentiveRepository::saveOrder($orderId, ['people_customised' => 1], (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVE_PEOPLE_SET', 'orders', $orderId, 'incentive_people', null, (string) json_encode($people));
        }
        Flash::set('success', 'People on this order saved.');
        header("Location: /orders/{$orderId}#financials-12d");
    }

    public function recalculate(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        $error = IncentiveService::unlock($orderId, (int) $user['id']);
        if ($error !== null) {
            Flash::set('error', $error);
        } else {
            IncentiveService::lockIfDue($orderId, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVE_RECALCULATED', 'orders', $orderId);
            Flash::set('success', 'Incentive worked out again.');
        }
        header("Location: /orders/{$orderId}#financials-12d");
    }

    public function mine(array $params): void
    {
        $user = AuthService::currentUser();
        IncentiveService::lockDue((int) $user['id']);
        View::render('incentives/mine', [
            'payouts' => IncentiveRepository::payoutsForUser((int) $user['id']),
            'cutoff' => IncentiveService::settings()['cutoff'],
        ], 'layout/base');
    }

    public function payouts(array $params): void
    {
        $user = AuthService::currentUser();
        IncentiveService::lockDue((int) $user['id']);
        $months = IncentiveRepository::payoutMonths();
        $month = (string) ($_GET['month'] ?? '');
        if (!IncentiveService::isMonth($month)) {
            $month = $months[0] ?? IncentiveService::incentiveMonth(date('Y-m-d'), IncentiveService::settings()['cutoff']);
        }
        $rows = IncentiveRepository::payoutsForMonth($month);
        if (($_GET['format'] ?? '') === 'csv') {
            \App\Helpers\Csv::stream('incentives-' . $month . '.csv',
                ['Person', 'Kind', 'Order', 'Role', 'Amount (INR)', 'Amount (currency)', 'Currency', 'Status', 'Paid on', 'Paid (INR)', 'TDS (INR)', 'TDS section', 'TDS rate %', 'TDS reason'],
                array_map(static fn (array $r): array => [$r['user_name'] ?? $r['associate_name'], $r['payee_kind'], $r['buyer_inquiry_ref'] ?: $r['order_reference'],
                    $r['payee_kind'] === 'associate' ? 'Associate (agreement)' : (IncentiveRepository::ROLES[$r['role']] ?? $r['role']),
                    $r['amount_inr'], $r['amount_fc'], $r['currency_code'], $r['status'], $r['paid_on'], $r['paid_inr'], $r['tds_inr'],
                    $r['tds_section'] ?? null, $r['tds_rate'] ?? null, $r['tds_reason'] ?? null], $rows));
        }
        // Owner decision 2 Nov 2026 (D-235): the TDS worked out for each associate payout still due, as of today.
        $tdsCalc = [];
        foreach ($rows as $r) {
            if ($r['payee_kind'] === 'associate' && $r['status'] === 'due' && (float) $r['amount_inr'] > 0) {
                $tdsCalc[(int) $r['id']] = \App\Services\AssociateTds::compute($r, date('Y-m-d'));
            }
        }
        View::render('incentives/payouts', [
            'tdsCalc' => $tdsCalc,
            'month' => $month,
            'months' => array_values(array_unique(array_merge([$month], $months))),
            'rows' => $rows,
            'cutoff' => IncentiveService::settings()['cutoff'],
        ], 'layout/base');
    }

    public function markPaid(array $params): void
    {
        $user = AuthService::currentUser();
        $month = (string) ($_POST['month'] ?? '');
        $month = IncentiveService::isMonth($month) ? $month : '';
        $ids = isset($params['payoutId']) ? [(int) $params['payoutId']] : array_map('intval', (array) ($_POST['payout_ids'] ?? []));
        $paidOn = (string) ($_POST['paid_on'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || $paidOn > date('Y-m-d')) {
            Flash::set('error', 'Enter the date it was paid (today or earlier).');
            header('Location: /incentives/payouts?month=' . rawurlencode($month));
            return;
        }
        // Owner rule D-212: how it was paid, with its reference (payroll month, UTR or cheque no.).
        $paidVia = (string) ($_POST['paid_via'] ?? '');
        $paidReference = trim((string) ($_POST['paid_reference'] ?? ''));
        if (!array_key_exists($paidVia, IncentiveRepository::PAID_VIA)) {
            Flash::set('error', 'Choose how it was paid (with the salary, bank transfer or cheque).');
            header('Location: /incentives/payouts?month=' . rawurlencode($month));
            return;
        }
        if ($error = \App\Helpers\FieldValidator::bankReference($paidReference, 'The payment reference')) {
            Flash::set('error', $error);
            header('Location: /incentives/payouts?month=' . rawurlencode($month));
            return;
        }
        $count = 0;
        foreach ($ids as $id) {
            $payout = IncentiveRepository::findPayout($id);
            if ($payout === null || $payout['status'] !== 'due' || (float) $payout['amount_inr'] < 0) {
                // A claw-back line (negative) is settled with the associate's next payment, never paid on its own.
                continue;
            }
            // Owner decision 1 Nov 2026 (AS-7): an associate is paid only into a verified bank account past its waiting days.
            if ($payout['payee_kind'] === 'associate' && \App\Repositories\AssociateBankRepository::usable((int) $payout['associate_id'], $paidOn) === null) {
                if (isset($params['payoutId'])) {
                    Flash::set('error', 'This associate has no verified bank account usable on ' . \App\Helpers\Dates::short($paidOn) . ' — Accounts must verify their bank details first (two people), and a new account is usable only after its waiting days.');
                    header('Location: /incentives/payouts?month=' . rawurlencode($month));
                    return;
                }
                $skipped = ($skipped ?? 0) + 1;
                continue;
            }
            // Owner decision 2 Nov 2026 (D-235): the app works out the TDS on an associate's commission.
            $tdsCalc = null;
            $overrideReason = null;
            if ($payout['payee_kind'] === 'associate') {
                $tdsCalc = \App\Services\AssociateTds::compute($payout, $paidOn);
                if ($tdsCalc['abroad']) {
                    if (isset($params['payoutId'])) {
                        self::storeRemittanceForms($payout, (int) $user['id']);
                        $tdsCalc = \App\Services\AssociateTds::compute($payout, $paidOn);
                    }
                    $missing = array_merge($tdsCalc['missing_foreign'], $tdsCalc['missing_payout']);
                    if ($missing !== []) {
                        if (isset($params['payoutId'])) {
                            Flash::set('error', 'Before paying an associate based abroad, these must be on file: ' . implode(', ', $missing)
                                . '. The TRC, Form 10F and no-PE declaration are accepted on the associate\'s Documents tab; Form 15CA' . (\App\Services\AssociateTds::settings()['cb_required'] ? ' and 15CB are' : ' is') . ' attached to this payment.');
                            header('Location: /incentives/payouts?month=' . rawurlencode($month));
                            return;
                        }
                        $skippedDocs = ($skippedDocs ?? 0) + 1;
                        continue;
                    }
                }
            }
            $paidInr = isset($params['payoutId']) && is_numeric($_POST['paid_inr'] ?? null) ? (float) $_POST['paid_inr'] : ($tdsCalc !== null ? $tdsCalc['net'] : (float) $payout['amount_inr']);
            $tds = isset($params['payoutId']) && is_numeric($_POST['tds_inr'] ?? null) ? (float) $_POST['tds_inr'] : ($tdsCalc !== null ? $tdsCalc['tds'] : null);
            if ($tdsCalc !== null) {
                if (abs((float) ($tds ?? 0) - $tdsCalc['tds']) > 0.5) {
                    $overrideReason = trim((string) ($_POST['tds_override_reason'] ?? ''));
                    if (mb_strlen($overrideReason) < 10) {
                        Flash::set('error', 'The TDS worked out for this payment is ₹' . number_format($tdsCalc['tds'], 2) . ' (' . $tdsCalc['reason'] . '). To record a different TDS, give the reason (at least 10 characters), e.g. the CA\'s advice or a lower-deduction certificate.');
                        header('Location: /incentives/payouts?month=' . rawurlencode($month));
                        return;
                    }
                }
                if ($paidInr + (float) ($tds ?? 0) > $tdsCalc['gross'] + 0.005) {
                    Flash::set('error', 'The amount paid plus the TDS cannot be more than the commission (₹' . number_format($tdsCalc['gross'], 2) . ').');
                    header('Location: /incentives/payouts?month=' . rawurlencode($month));
                    return;
                }
            }
            if ($paidInr <= 0 || ($tds !== null && ($tds < 0 || $tds > $paidInr))) {
                Flash::set('error', 'Enter the amount paid above 0, and TDS between 0 and that amount.');
                header('Location: /incentives/payouts?month=' . rawurlencode($month));
                return;
            }
            // QA Round 6, R6-N10: never more than the payout, never before its month.
            if ($paidInr > (float) $payout['amount_inr'] + 0.005) {
                Flash::set('error', 'The amount paid cannot be more than the payout (₹' . number_format((float) $payout['amount_inr'], 2) . ').');
                header('Location: /incentives/payouts?month=' . rawurlencode($month));
                return;
            }
            $earliest = IncentiveService::earliestPaidOn((string) $payout['incentive_month']);
            if ($paidOn < $earliest) {
                Flash::set('error', 'The date paid cannot be before ' . \App\Helpers\Dates::short($earliest) . ' for a payout of ' . $payout['incentive_month'] . '.');
                header('Location: /incentives/payouts?month=' . rawurlencode($month));
                return;
            }
            // QA Round 6, R6-N17: a second click records and logs nothing more.
            if (!IncentiveService::markPaid($id, $paidOn, $paidInr, $tds, (int) $user['id'], $paidVia, $paidReference)) {
                continue;
            }
            AuditLogRepository::log((int) $user['id'], 'INCENTIVE_PAID', 'incentive_payouts', $id, 'status', 'due', 'paid');
            if ($tdsCalc !== null) {
                $recordedTds = (float) ($tds ?? 0);
                IncentiveRepository::setPayoutTds($id, $recordedTds > 0 ? ($overrideReason !== null && $tdsCalc['gross'] > 0 ? round($recordedTds / $tdsCalc['gross'] * 100, 2) : $tdsCalc['rate']) : 0.0,
                    $recordedTds > 0 ? ($tdsCalc['section'] ?? \App\Services\AssociateTds::settings()['section']) : null,
                    $overrideReason !== null ? 'changed by hand: ' . $overrideReason . ' (worked out: ₹' . number_format($tdsCalc['tds'], 2) . ', ' . $tdsCalc['reason'] . ')' : $tdsCalc['reason'],
                    $overrideReason);
                if ($overrideReason !== null) {
                    AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_TDS_OVERRIDDEN', 'incentive_payouts', $id, 'tds_inr', number_format($tdsCalc['tds'], 2, '.', ''), number_format($recordedTds, 2, '.', ''), $overrideReason);
                }
            }
            if ($payout['payee_kind'] === 'associate') {
                \App\Services\AssociateService::onPayoutPaid($id, (int) $user['id']);
            }
            $count++;
        }
        // QA Round 6, R6-N11: nothing selected (or all already paid) is not a success.
        if ($count === 0) {
            Flash::set('warning', 'Nothing was recorded — no payout due for payment was selected.' . (!empty($skipped) ? " {$skipped} associate payout(s) skipped: no verified bank account yet." : '')
                . (!empty($skippedDocs) ? " {$skippedDocs} payout(s) to associates abroad skipped: TRC / Form 10F / no-PE / Form 15CA" . (\App\Services\AssociateTds::settings()['cb_required'] ? '/15CB' : '') . ' not yet on file — pay them one by one.' : ''));
        } else {
            Flash::set('success', ($count === 1 ? 'Payment recorded.' : "{$count} payments recorded.") . (!empty($skipped) ? " {$skipped} associate payout(s) skipped: no verified bank account yet." : '')
                . (!empty($skippedDocs) ? " {$skippedDocs} payout(s) to associates abroad skipped: TRC / Form 10F / no-PE / Form 15CA" . (\App\Services\AssociateTds::settings()['cb_required'] ? '/15CB' : '') . ' not yet on file — pay them one by one.' : ''));
        }
        header('Location: /incentives/payouts?month=' . rawurlencode($month));
    }

    /**
     * Owner decision 2 Nov 2026 (D-235): Form 15CA / 15CB attached to the
     * payment of an associate based abroad, kept on the associate's documents
     * against this payout.
     */
    private static function storeRemittanceForms(array $payout, int $userId): void
    {
        $associate = \App\Repositories\AssociateRepository::find((int) $payout['associate_id']);
        foreach (['form_15ca' => 'form_15ca_file', 'form_15cb' => 'form_15cb_file'] as $kind => $field) {
            if (empty($_FILES[$field]['name'] ?? '')) {
                continue;
            }
            try {
                $fileId = \App\Services\FileUploadService::handleUpload($field, 'associate_document', 'associates/documents', null, null, $userId, null, (string) ($associate['name'] ?? ''), \App\Repositories\AssociateDocumentRepository::KINDS[$kind]);
            } catch (\Throwable $e) {
                Flash::setErrorFromException($e);
                continue;
            }
            $docId = \App\Repositories\AssociateDocumentRepository::add((int) $payout['associate_id'], $kind, $fileId, (int) $payout['id'], null, false, $userId);
            AuditLogRepository::log($userId, 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', (int) $payout['associate_id'], 'kind', null, $kind);
        }
    }
}
