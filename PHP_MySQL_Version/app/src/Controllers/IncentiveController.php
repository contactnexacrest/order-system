<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\FileUploadService;
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

    public function associates(array $params): void
    {
        // QA Round 6, R6-N07: editing an associate that does not exist is "not found".
        if (isset($params['id']) && AssociateRepository::find((int) $params['id']) === null) {
            http_response_code(404);
            echo 'Associate not found.';
            return;
        }
        $associates = AssociateRepository::all();
        $clientsByAssociate = [];
        foreach ($associates as $a) {
            $clientsByAssociate[(int) $a['id']] = AssociateRepository::clients((int) $a['id']);
        }
        View::render('incentives/associates', [
            'associates' => $associates,
            'clientsByAssociate' => $clientsByAssociate,
            'unlinkedClients' => AssociateRepository::unlinkedClients(),
            'currencies' => LookupRepository::currencies(),
            'editing' => isset($params['id']) ? AssociateRepository::find((int) $params['id']) : null,
        ], 'layout/base');
    }

    public function saveAssociate(array $params): void
    {
        $user = AuthService::currentUser();
        $id = isset($params['id']) ? (int) $params['id'] : null;
        if ($id !== null && \App\Helpers\RecordCheck::missing('associates', $id, '/associates', 'associate')) {
            return;
        }
        $data = [];
        foreach (AssociateRepository::FIELDS as $f) {
            $v = trim((string) ($_POST[$f] ?? ''));
            $data[$f] = $v === '' ? null : $v;
        }
        $errors = [];
        if ($data['name'] === null || mb_strlen((string) $data['name']) > 150) {
            $errors[] = 'Enter the associate\'s name (up to 150 characters).';
        }
        if (!in_array($data['based_in'], ['india', 'abroad'], true)) {
            $errors[] = 'Choose whether the associate is in India or abroad.';
        }
        if (!preg_match('/^[A-Z]{3}$/', (string) $data['currency_code'])) {
            $errors[] = 'Choose the currency the associate is paid in.';
        }
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address, or leave it empty.';
        }
        if ($data['agreement_signed_on'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['agreement_signed_on'])) {
            $errors[] = 'Enter the agreement date as a date.';
        }
        foreach (['rate_1_5', 'rate_6_10', 'rate_11_20', 'rate_21_40', 'rate_41_plus'] as $r) {
            if (!is_numeric($data[$r]) || (float) $data[$r] < 0 || (float) $data[$r] > 20) {
                $errors[] = 'Enter every commission rate as a % from 0 to 20.';
                break;
            }
        }
        $data['status'] = $data['status'] === 'inactive' ? 'inactive' : 'active';
        // Owner decision 2026-10-30: the signed agreement is kept with its
        // date — a signing date needs the signed copy on file, and the other
        // way round.
        $existing = $id !== null ? AssociateRepository::find($id) : null;
        $hasUpload = !empty($_FILES['agreement_file']['name']);
        if ($data['agreement_signed_on'] !== null && !$hasUpload && empty($existing['agreement_file_id'])) {
            $errors[] = 'Attach the signed agreement (PDF) with its signing date.';
        }
        if (($hasUpload || !empty($existing['agreement_file_id'])) && $data['agreement_signed_on'] === null) {
            $errors[] = 'Enter the date the agreement was signed with its signed copy.';
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: ' . ($id ? "/associates/{$id}/edit" : '/associates'));
            return;
        }
        $fileId = null;
        if ($hasUpload) {
            try {
                $fileId = FileUploadService::handleUpload('agreement_file', 'associate_agreement', 'associates/agreements', null, null, (int) $user['id'], null, (string) $data['name'], 'Signed associate agreement');
            } catch (\Throwable $e) {
                Flash::setErrorFromException($e);
                header('Location: ' . ($id ? "/associates/{$id}/edit" : '/associates'));
                return;
            }
        }
        if ($id === null) {
            $id = AssociateRepository::create($data, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_ADDED', 'associates', $id, null, null, (string) $data['name']);
            Flash::set('success', "Associate \"{$data['name']}\" added.");
        } else {
            AssociateRepository::update($id, $data);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_UPDATED', 'associates', $id, null, null, (string) json_encode($data));
            Flash::set('success', "Associate \"{$data['name']}\" saved.");
        }
        if ($fileId !== null) {
            AssociateRepository::setAgreementFile($id, $fileId);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_AGREEMENT_UPLOADED', 'associates', $id, 'agreement_file_id', isset($existing['agreement_file_id']) ? (string) $existing['agreement_file_id'] : null, (string) $fileId);
        }
        header('Location: /associates');
    }

    /** The signed agreement — internal (manage_incentives only), every download audit-logged. */
    public function downloadAgreement(array $params): void
    {
        $associate = AssociateRepository::find((int) $params['id']);
        $file = !empty($associate['agreement_file_id']) ? \App\Repositories\FileStoreRepository::find((int) $associate['agreement_file_id']) : null;
        if ($file === null || !is_file((string) $file['server_path'])) {
            http_response_code(404);
            echo 'No signed agreement is on file for this associate.';
            return;
        }
        AuditLogRepository::log((int) AuthService::currentUser()['id'], 'ASSOCIATE_AGREEMENT_DOWNLOADED', 'associates', (int) $associate['id'], null, null, (string) $file['original_filename']);
        $name = preg_replace('/[\x00-\x1F\x7F"\/\\\\]/', '-', (string) $file['original_filename']) ?? 'agreement.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize((string) $file['server_path']));
        header('Cache-Control: private, no-store');
        readfile((string) $file['server_path']);
    }

    public function linkClient(array $params): void
    {
        $user = AuthService::currentUser();
        $associateId = (int) $params['id'];
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $registeredOn = (string) ($_POST['registered_on'] ?? '');
        if (AssociateRepository::find($associateId) === null || $clientId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $registeredOn)) {
            Flash::set('error', 'Choose the client and the date the associate registered the lead.');
            header('Location: /associates');
            return;
        }
        AssociateRepository::linkClient($clientId, $associateId, $registeredOn);
        AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_CLIENT_LINKED', 'clients', $clientId, 'associate_id', null, (string) $associateId);
        Flash::set('success', 'Client linked to the associate.');
        header('Location: /associates');
    }

    public function unlinkClient(array $params): void
    {
        $user = AuthService::currentUser();
        $clientId = (int) $params['clientId'];
        if (\App\Helpers\RecordCheck::missing('clients', $clientId, '/associates', 'client link', ['associate_id' => (int) $params['id']])) {
            return;
        }
        AssociateRepository::linkClient($clientId, null, null);
        AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_CLIENT_UNLINKED', 'clients', $clientId, 'associate_id', (string) $params['id'], null);
        Flash::set('success', 'Client unlinked from the associate.');
        header('Location: /associates');
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
                ['Person', 'Kind', 'Order', 'Role', 'Amount (INR)', 'Amount (currency)', 'Currency', 'Status', 'Paid on', 'Paid (INR)', 'TDS (INR)'],
                array_map(static fn (array $r): array => [$r['user_name'] ?? $r['associate_name'], $r['payee_kind'], $r['buyer_inquiry_ref'] ?: $r['order_reference'],
                    $r['payee_kind'] === 'associate' ? 'Associate (agreement)' : (IncentiveRepository::ROLES[$r['role']] ?? $r['role']),
                    $r['amount_inr'], $r['amount_fc'], $r['currency_code'], $r['status'], $r['paid_on'], $r['paid_inr'], $r['tds_inr']], $rows));
        }
        View::render('incentives/payouts', [
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
        $count = 0;
        foreach ($ids as $id) {
            $payout = IncentiveRepository::findPayout($id);
            if ($payout === null || $payout['status'] !== 'due') {
                continue;
            }
            $paidInr = isset($params['payoutId']) && is_numeric($_POST['paid_inr'] ?? null) ? (float) $_POST['paid_inr'] : (float) $payout['amount_inr'];
            $tds = isset($params['payoutId']) && is_numeric($_POST['tds_inr'] ?? null) ? (float) $_POST['tds_inr'] : null;
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
            if (!IncentiveService::markPaid($id, $paidOn, $paidInr, $tds, (int) $user['id'])) {
                continue;
            }
            AuditLogRepository::log((int) $user['id'], 'INCENTIVE_PAID', 'incentive_payouts', $id, 'status', 'due', 'paid');
            $count++;
        }
        // QA Round 6, R6-N11: nothing selected (or all already paid) is not a success.
        if ($count === 0) {
            Flash::set('warning', 'Nothing was recorded — no payout due for payment was selected.');
        } else {
            Flash::set('success', $count === 1 ? 'Payment recorded.' : "{$count} payments recorded.");
        }
        header('Location: /incentives/payouts?month=' . rawurlencode($month));
    }
}
