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
    private static function canManagePricing(): bool
    {
        $u = AuthService::currentUser();
        return $u !== null && \App\Services\PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, 'manage_pricing');
    }

    private static function back(int $orderId, string $type, string $message): void
    {
        Flash::set($type, $message);
        header("Location: /orders/{$orderId}#financials-12d");
    }

    public function settings(array $params): void
    {
        View::render('incentives/settings', [
            'settings' => IncentiveService::settings(),
            'roles' => IncentiveRepository::allRoles(),
            'activeRoles' => IncentiveRepository::roles(),
            'users' => UserRepository::listActive(),
            'userRoles' => IncentiveRepository::userRoles(),
            'canManagePricing' => self::canManagePricing(),
            'readyCount' => count(IncentiveService::readyToLock()),
        ], 'layout/base');
    }

    /**
     * Owner decision D-245 P4: the program switch, the reminder days, the
     * roles (an editable list whose active shares add up to 100%; changing
     * them is the MD's — manage_pricing) and each person's roles.
     */
    public function saveSettings(array $params): void
    {
        $user = AuthService::currentUser();
        $userId = (int) $user['id'];
        $errors = [];
        $reminder = (string) ($_POST['lock_reminder_days'] ?? '3');
        if (!ctype_digit($reminder) || (int) $reminder > 28) {
            $errors[] = 'Enter the reminder before a payout run as 0 to 28 days (0 = no reminder).';
        }
        $roleRows = null;
        if (isset($_POST['roles']) || trim((string) ($_POST['new_role_label'] ?? '')) !== '') {
            if (!self::canManagePricing()) {
                $errors[] = 'Only the MD can change the incentive roles and their shares.';
            } else {
                [$roleRows, $roleErrors] = self::parseRoles();
                $errors = array_merge($errors, $roleErrors);
            }
        }
        // D-259: caps per role (MD only — manage_pricing). Empty = no cap.
        $caps = null;
        if (isset($_POST['caps_present']) || isset($_POST['caps'])) {
            if (!self::canManagePricing()) {
                $errors[] = 'Only the MD can change the incentive caps.';
            } else {
                [$caps, $capErrors] = self::parseCaps();
                $errors = array_merge($errors, $capErrors);
            }
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: /incentives/settings');
            return;
        }
        if ($caps !== null) {
            $capText = static fn (): string => (string) json_encode(array_map(static fn (array $r): array => [$r['cap_per_order'], $r['cap_per_month']], IncentiveRepository::allRoles()));
            $before = $capText();
            IncentiveRepository::saveCaps($caps, $userId);
            $after = $capText();
            if ($before !== $after) {
                AuditLogRepository::log($userId, 'INCENTIVE_CAPS_CHANGED', 'incentive_roles', null, 'caps', $before, $after);
            }
        }
        $old = IncentiveRepository::settings();
        $values = ['program_enabled' => !empty($_POST['program_enabled']) ? '1' : '0', 'lock_reminder_days' => (string) (int) $reminder];
        foreach ($values as $key => $value) {
            if (($old[$key] ?? null) !== $value) {
                IncentiveRepository::setSetting($key, $value, $userId);
                AuditLogRepository::log($userId, 'INCENTIVE_SETTING_CHANGED', 'incentive_settings', null, $key, $old[$key] ?? null, $value);
            }
        }
        if ($roleRows !== null) {
            $before = array_map(static fn (array $r): string => $r['label'] . ' ' . $r['share_pct'] . '%' . ($r['is_active'] ? '' : ' (inactive)'), IncentiveRepository::allRoles());
            IncentiveRepository::saveRoles($roleRows, $userId);
            $after = array_map(static fn (array $r): string => $r['label'] . ' ' . $r['share_pct'] . '%' . ($r['is_active'] ? '' : ' (inactive)'), IncentiveRepository::allRoles());
            if ($before !== $after) {
                AuditLogRepository::log($userId, 'INCENTIVE_ROLES_EDITED', 'incentive_roles', null, 'roles', (string) json_encode($before), (string) json_encode($after));
            }
        }
        $rolesByUser = [];
        // Keys are "u<user id>", the same form as the Node stack (whose form parser would read bare numbers as a list).
        $active = IncentiveRepository::roles();
        foreach ((array) ($_POST['user_roles'] ?? []) as $key => $roles) {
            $uid = (int) ltrim((string) $key, 'u');
            if ($uid > 0) {
                $rolesByUser[$uid] = array_values(array_filter((array) $roles, static fn ($r): bool => isset($active[$r])));
            }
        }
        if (isset($_POST['user_roles_present']) && $rolesByUser != IncentiveRepository::userRoles()) {
            IncentiveRepository::replaceUserRoles($rolesByUser);
            AuditLogRepository::log($userId, 'INCENTIVE_ROLES_CHANGED', 'user_incentive_roles', null, null, null, (string) json_encode($rolesByUser));
        }
        Flash::set('success', 'Team incentive settings saved.');
        header('Location: /incentives/settings');
    }

    /**
     * D-259: the caps table from the form — caps[role_key][order|month] in rupees; empty = no cap.
     *
     * @return array{0: array<string, array{order:?float, month:?float}>, 1: list<string>}
     */
    private static function parseCaps(): array
    {
        $errors = [];
        $out = [];
        $roles = IncentiveRepository::allRoles();
        foreach ((array) ($_POST['caps'] ?? []) as $key => $c) {
            if (!isset($roles[$key]) || !is_array($c)) {
                continue;
            }
            $vals = [];
            foreach (['order', 'month'] as $k) {
                $v = trim((string) ($c[$k] ?? ''));
                if ($v === '') {
                    $vals[$k] = null;
                } elseif (!is_numeric($v) || (float) $v < 0 || (float) $v > 1000000000) {
                    $errors[] = 'Enter the ' . $roles[$key]['label'] . ' cap ' . ($k === 'order' ? 'per order' : 'per month') . ' as rupees from 0 to 1,00,00,00,000, or leave it empty for no cap.';
                    $vals[$k] = null;
                } else {
                    $vals[$k] = round((float) $v, 2);
                }
            }
            $out[(string) $key] = $vals;
        }
        return [$out, array_values(array_unique($errors))];
    }

    /**
     * The roles table from the form: label, share %, active; a new role from
     * new_role_label / new_role_share. Active shares must add up to 100%.
     *
     * @return array{0: list<array<string,mixed>>, 1: list<string>}
     */
    private static function parseRoles(): array
    {
        $errors = [];
        $rows = [];
        $all = IncentiveRepository::allRoles();
        $order = 0;
        foreach ($all as $key => $r) {
            $in = (array) ($_POST['roles'][$key] ?? []);
            $label = trim((string) ($in['label'] ?? $r['label']));
            $share = (string) ($in['share_pct'] ?? $r['share_pct']);
            if ($label === '' || mb_strlen($label) > 80) {
                $errors[] = 'Every role needs a name (up to 80 characters).';
                break;
            }
            if (!is_numeric($share) || (float) $share < 0 || (float) $share > 100) {
                $errors[] = 'Enter every role\'s usual share as a % from 0 to 100.';
                break;
            }
            $rows[] = ['role_key' => $key, 'label' => $label, 'share_pct' => round((float) $share, 2), 'is_active' => isset($_POST['roles']) ? !empty($in['is_active']) : $r['is_active'], 'sort_order' => ++$order];
        }
        $newLabel = trim((string) ($_POST['new_role_label'] ?? ''));
        if ($newLabel !== '') {
            $newShare = (string) ($_POST['new_role_share'] ?? '');
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($newLabel)), '_');
            if ($key === '' || mb_strlen($newLabel) > 80) {
                $errors[] = 'Give the new role a name (letters or numbers, up to 80 characters).';
            } elseif (isset($all[$key]) || $key === 'associate') {
                $errors[] = 'A role named "' . $newLabel . '" already exists.';
            } elseif (!is_numeric($newShare) || (float) $newShare < 0 || (float) $newShare > 100) {
                $errors[] = 'Enter the new role\'s usual share as a % from 0 to 100.';
            } else {
                $rows[] = ['role_key' => substr($key, 0, 40), 'label' => $newLabel, 'share_pct' => round((float) $newShare, 2), 'is_active' => true, 'sort_order' => ++$order];
            }
        }
        $total = array_sum(array_map(static fn (array $r): float => $r['is_active'] ? $r['share_pct'] : 0.0, $rows));
        if ($errors === [] && abs($total - 100) > 0.001) {
            $errors[] = 'The usual shares of the active roles must add up to 100% (they add up to ' . rtrim(rtrim(number_format($total, 2), '0'), '.') . '%).';
        }
        return [$rows, $errors];
    }

    /** Deletes an incentive role that was never used; a used role can only be deactivated. */
    public function deleteRole(array $params): void
    {
        $user = AuthService::currentUser();
        $key = (string) ($params['key'] ?? '');
        $all = IncentiveRepository::allRoles();
        if (!self::canManagePricing()) {
            Flash::set('error', 'Only the MD can change the incentive roles.');
        } elseif (!isset($all[$key])) {
            Flash::set('error', 'That role does not exist.');
        } elseif (IncentiveRepository::roleInUse($key)) {
            Flash::set('error', 'The role "' . $all[$key]['label'] . '" is in use (people, orders or payouts) — deactivate it instead.');
        } elseif ($all[$key]['is_active'] && $all[$key]['share_pct'] > 0) {
            Flash::set('error', 'Set the role "' . $all[$key]['label'] . '" to 0% or deactivate it first, so the active shares still add up to 100%.');
        } else {
            IncentiveRepository::deleteRole($key);
            AuditLogRepository::log((int) $user['id'], 'INCENTIVE_ROLE_DELETED', 'incentive_roles', $all[$key]['id'], 'label', $all[$key]['label'], null);
            Flash::set('success', 'Role "' . $all[$key]['label'] . '" deleted.');
        }
        header('Location: /incentives/settings');
    }

    /**
     * Owner decision D-245 P4: the order's own team incentive (MD only, with a
     * reason) — the global rule, a % of the order's profit or a fixed amount —
     * and, D-245 P3, the associate's commission mode.
     */
    public function saveOrder(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        if (OrderRepository::find($orderId) === null) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if (!self::canManagePricing()) {
            self::back($orderId, 'error', 'Only the MD can change an order\'s incentive.');
            return;
        }
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && !empty($row['locked_at'])) {
            self::back($orderId, 'error', 'This order\'s incentive is locked. Unlock it (with a reason) to change it.');
            return;
        }
        $fields = [];
        $mode = (string) ($_POST['incentive_mode'] ?? ($row['incentive_mode'] ?? 'global'));
        $rawValue = trim((string) ($_POST['incentive_value'] ?? ''));
        $reason = trim((string) ($_POST['incentive_reason'] ?? ''));
        if (!in_array($mode, ['global', 'pct', 'fixed'], true)) {
            self::back($orderId, 'error', 'Choose how the team incentive is set for this order.');
            return;
        }
        $value = null;
        if ($mode !== 'global') {
            if (!is_numeric($rawValue) || (float) $rawValue < 0 || ($mode === 'pct' && (float) $rawValue > 100) || (float) $rawValue > 100000000) {
                self::back($orderId, 'error', $mode === 'pct' ? 'Enter the % of the order\'s profit (0 to 100).' : 'Enter the incentive for this order as an amount of 0 or more.');
                return;
            }
            $value = round((float) $rawValue, $mode === 'pct' ? 4 : 2);
        }
        $old = IncentiveService::orderRule($row);
        $amountChanged = $mode !== $old['mode'] || ($value !== null && abs($value - (float) $old['value']) > 0.00001);
        if ($amountChanged) {
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
                self::back($orderId, 'error', 'Say why the team incentive is set this way for this order (10 to 500 characters).');
                return;
            }
            if ($mode !== 'global') {
                $calc = IncentiveService::calculate($orderId);
                $amount = $mode === 'fixed' ? (float) $value : round($calc['basis'] * (float) $value / 100, 2);
                if ($amount > $calc['basis'] + 0.005) {
                    self::back($orderId, 'error', 'The team incentive (₹' . number_format($amount, 2) . ') cannot be more than the order\'s profit after the associate\'s commission (₹' . number_format($calc['basis'], 2) . ').');
                    return;
                }
            }
            $fields += ['incentive_mode' => $mode, 'incentive_value' => $value, 'incentive_reason' => $mode === 'global' ? null : $reason];
        }
        // Owner decision D-245 P3: forcing an associate's commission on ("Yes", e.g. on a repeat order)
        // is the MD's decision alone, with a written reason; it is audited.
        if (isset($_POST['associate_mode'])) {
            $aMode = (string) $_POST['associate_mode'];
            if (!in_array($aMode, ['auto', 'yes', 'no'], true)) {
                self::back($orderId, 'error', 'Choose whether the associate earns commission on this order.');
                return;
            }
            $wasYes = ($row['associate_mode'] ?? 'auto') === 'yes';
            $modeReason = trim((string) ($_POST['associate_mode_reason'] ?? ''));
            if ($aMode === 'yes' && (!$wasYes || $modeReason !== (string) ($row['associate_mode_reason'] ?? ''))) {
                if (!\App\Services\AssociateService::canChangeLink($user)) {
                    self::back($orderId, 'error', 'Only the MD can force the associate\'s commission on for an order.');
                    return;
                }
                if (mb_strlen($modeReason) < 10 || mb_strlen($modeReason) > 300) {
                    self::back($orderId, 'error', 'Say why the associate\'s commission is forced on for this order (10 to 300 characters).');
                    return;
                }
                $fields['associate_mode_reason'] = $modeReason;
                $fields['associate_mode_set_by'] = (int) $user['id'];
                AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_COMMISSION_FORCED', 'orders', $orderId, 'associate_mode', (string) ($row['associate_mode'] ?? 'auto'), 'yes', $modeReason);
            } elseif ($aMode !== 'yes' && $wasYes) {
                $fields['associate_mode_reason'] = null;
                $fields['associate_mode_set_by'] = null;
            }
            if ($aMode !== ($row['associate_mode'] ?? 'auto')) {
                $fields['associate_mode'] = $aMode;
                IncentiveRepository::addHistory($orderId, 'associate_mode', (string) ($row['associate_mode'] ?? 'auto'), $aMode, $aMode === 'yes' ? $modeReason : null, (int) $user['id']);
            }
        }
        if ($fields === []) {
            self::back($orderId, 'warning', 'Nothing changed.');
            return;
        }
        IncentiveRepository::saveOrder($orderId, $fields, (int) $user['id']);
        if ($amountChanged) {
            $describe = static fn (array $r): string => match ($r['mode']) {
                'fixed' => 'fixed ₹' . number_format((float) $r['value'], 2),
                'pct' => rtrim(rtrim(number_format((float) $r['value'], 4), '0'), '.') . '% of profit',
                default => 'global rule',
            };
            IncentiveRepository::addHistory($orderId, 'amount', $describe($old), $describe(['mode' => $mode, 'value' => $value]), $reason, (int) $user['id']);
        }
        AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVE_SET', 'orders', $orderId, 'incentive', $row !== null ? (string) json_encode(array_intersect_key($row, $fields)) : null, (string) json_encode($fields));
        self::back($orderId, 'success', 'Team incentive for this order saved.');
    }

    /**
     * Owner decision D-245 P4: who gets it on this order — a tick per role,
     * the people on each role and, optionally, the shares on this order
     * (they must add up to 100% across the ticked roles).
     */
    public function saveOrderPeople(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        if (!self::canManagePricing()) {
            self::back($orderId, 'error', 'Only the MD can change who gets an order\'s incentive.');
            return;
        }
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && !empty($row['locked_at'])) {
            self::back($orderId, 'error', 'This order\'s incentive is locked. Unlock it (with a reason) to change it.');
            return;
        }
        $before = IncentiveService::split($orderId, 100.0);
        $describe = static function (array $split): string {
            $parts = [];
            foreach ($split['roles'] as $r) {
                $parts[] = $r['label'] . ': ' . ($r['gets_it'] ? rtrim(rtrim(number_format($r['pct'], 2), '0'), '.') . '% ' . implode(', ', array_column($r['people'], 'name')) : 'no');
            }
            return implode('; ', $parts);
        };
        if (!empty($_POST['reset'])) {
            IncentiveRepository::replaceOrderPeople($orderId, []);
            IncentiveRepository::replaceOrderRoles($orderId, []);
            IncentiveRepository::saveOrder($orderId, ['people_customised' => 0, 'shares_customised' => 0], (int) $user['id']);
        } else {
            $active = array_map(static fn (array $u): int => (int) $u['id'], UserRepository::listActive());
            $custom = !empty($_POST['shares_customised']);
            $roles = [];
            $people = [];
            $total = 0.0;
            foreach (array_keys(IncentiveRepository::roles()) as $role) {
                $people[$role] = array_values(array_intersect(array_map('intval', (array) ($_POST['people'][$role] ?? [])), $active));
                $gets = !empty($_POST['gets_it'][$role]);
                $share = null;
                if ($custom && $gets) {
                    $raw = (string) ($_POST['share_pct'][$role] ?? '');
                    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                        self::back($orderId, 'error', 'Enter the share of every ticked role as a % from 0 to 100.');
                        return;
                    }
                    $share = round((float) $raw, 2);
                    $total += $share;
                }
                $roles[$role] = ['gets_it' => $gets, 'share_pct' => $share];
            }
            if ($custom && abs($total - 100) > 0.001) {
                self::back($orderId, 'error', 'The shares on this order must add up to 100% (they add up to ' . rtrim(rtrim(number_format($total, 2), '0'), '.') . '%).');
                return;
            }
            IncentiveRepository::replaceOrderPeople($orderId, $people);
            IncentiveRepository::replaceOrderRoles($orderId, $roles);
            IncentiveRepository::saveOrder($orderId, ['people_customised' => 1, 'shares_customised' => $custom ? 1 : 0], (int) $user['id']);
        }
        $after = IncentiveService::split($orderId, 100.0);
        IncentiveRepository::addHistory($orderId, 'people', $describe($before), $describe($after), trim((string) ($_POST['people_note'] ?? '')) ?: null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVE_PEOPLE_SET', 'orders', $orderId, 'incentive_people', null, $describe($after));
        self::back($orderId, 'success', 'Who gets the incentive on this order saved.');
    }

    /** Owner decision D-245 P4: the MD locks a ready order (nothing locks by itself). */
    public function lock(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        if (!self::canManagePricing()) {
            self::back($orderId, 'error', 'Only the MD can lock an order\'s incentive.');
            return;
        }
        $error = IncentiveService::lockIfReady($orderId, (int) $user['id']);
        if ($error !== null) {
            self::back($orderId, 'error', $error);
            return;
        }
        AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVE_LOCKED', 'orders', $orderId);
        self::back($orderId, 'success', 'Incentive locked. The team is paid in the next payout run; the associate on their due date.');
    }

    /** The MD unlocks a locked order with a reason, until anything is paid. */
    public function recalculate(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        if (!self::canManagePricing()) {
            self::back($orderId, 'error', 'Only the MD can unlock an order\'s incentive.');
            return;
        }
        $reason = (string) ($_POST['unlock_reason'] ?? '');
        $error = IncentiveService::unlock($orderId, (int) $user['id'], $reason);
        if ($error !== null) {
            self::back($orderId, 'error', $error);
            return;
        }
        AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVE_UNLOCKED', 'orders', $orderId, 'locked', 'yes', 'no', trim($reason));
        self::back($orderId, 'success', 'Incentive unlocked. Change it, then lock it again.');
    }

    /** D-264: the MD corrects a fixed associate commission before it is paid, with a reason. */
    public function changeAssociateCommission(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        if (!self::canManagePricing()) {
            self::back($orderId, 'error', 'Only the MD can change an associate\'s commission.');
            return;
        }
        $error = \App\Services\AssociatePayoutService::changeCommission($orderId, trim((string) ($_POST['associate_amount_fc'] ?? '')), (string) ($_POST['associate_change_reason'] ?? ''), $user);
        if ($error !== null) {
            self::back($orderId, 'error', $error);
            return;
        }
        self::back($orderId, 'success', 'Commission changed at the same fixed rate. The associate has been told.');
    }

    /** Owner decision D-245 P4: the orders ready to lock (buyer fully paid, no claim open). */
    public function ready(array $params): void
    {
        $rows = [];
        foreach (IncentiveService::readyToLock() as $orderId) {
            $order = OrderRepository::find($orderId);
            $calc = IncentiveService::calculate($orderId);
            $rows[] = ['order' => $order, 'calc' => $calc, 'paid_on' => IncentiveService::fullyPaidOn($orderId)];
        }
        View::render('incentives/ready', [
            'rows' => $rows,
            'canManagePricing' => self::canManagePricing(),
            'nextRun' => \App\Services\AssociateCommission::payoutRunOn(date('Y-m-d')),
        ], 'layout/base');
    }

    /** Locks every ready order at once. */
    public function lockAll(array $params): void
    {
        $user = AuthService::currentUser();
        if (!self::canManagePricing()) {
            Flash::set('error', 'Only the MD can lock incentives.');
            header('Location: /incentives/ready');
            return;
        }
        $result = IncentiveService::lockAllReady((int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_INCENTIVES_LOCKED_ALL', 'order_incentives', null, 'locked', null, (string) $result['locked']);
        Flash::set($result['locked'] > 0 ? 'success' : 'warning', ($result['locked'] === 1 ? '1 order locked.' : $result['locked'] . ' orders locked.')
            . ($result['skipped'] !== [] ? ' Not locked: ' . implode(' ', $result['skipped']) : ''));
        header('Location: /incentives/ready');
    }

    public function mine(array $params): void
    {
        $user = AuthService::currentUser();
        View::render('incentives/mine', [
            'payouts' => IncentiveRepository::payoutsForUser((int) $user['id']),
        ], 'layout/base');
    }

    public function payouts(array $params): void
    {
        $months = IncentiveRepository::payoutMonths();
        $month = (string) ($_GET['month'] ?? '');
        if (!IncentiveService::isMonth($month)) {
            $month = $months[0] ?? IncentiveService::incentiveMonth(date('Y-m-d'));
        }
        $rows = IncentiveRepository::payoutsForMonth($month);
        if (($_GET['format'] ?? '') === 'csv') {
            \App\Helpers\Csv::stream('incentives-' . $month . '.csv',
                ['Person', 'Kind', 'Order', 'Role', 'Amount (INR)', 'Amount (currency)', 'Currency', 'Status', 'Paid on', 'Paid (INR)', 'TDS (INR)', 'TDS section', 'TDS rate %', 'TDS reason', 'Fixed at (INR per unit)', 'Bank debited (INR)', 'Bank charges (INR)', 'Payout difference (INR)'],
                array_map(static fn (array $r): array => [$r['user_name'] ?? $r['associate_name'], $r['payee_kind'], $r['buyer_inquiry_ref'] ?: $r['order_reference'],
                    $r['payee_kind'] === 'associate' ? 'Associate (agreement)' : IncentiveRepository::roleLabel((string) $r['role']),
                    $r['amount_inr'], $r['amount_fc'], $r['currency_code'], $r['status'], $r['paid_on'], $r['paid_inr'], $r['tds_inr'],
                    $r['tds_section'] ?? null, $r['tds_rate'] ?? null, $r['tds_reason'] ?? null, $r['fixed_rate'] ?? null, $r['bank_debited_inr'] ?? null, $r['bank_charges_inr'] ?? null, $r['payout_difference_inr'] ?? null], $rows));
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
            // D-264: what paying associates cost against their fixed commission — a company cost of the month, not on the orders.
            'payoutCosts' => \App\Services\AssociatePayoutService::payoutCostsForMonth($month),
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
                    Flash::set('error', 'This associate has no verified bank account usable on ' . \App\Helpers\Dates::short($paidOn) . ' — Accounts must verify their bank details first, and a new account is usable only after its waiting days.');
                    header('Location: /incentives/payouts?month=' . rawurlencode($month));
                    return;
                }
                $skipped = ($skipped ?? 0) + 1;
                continue;
            }
            // Owner decision D-245 P3: an associate's commission is paid only from its due date, and not while a buyer claim holds it.
            if (($block = \App\Services\AssociateCommission::payBlockReason($payout, $paidOn)) !== null) {
                if (isset($params['payoutId'])) {
                    Flash::set('error', $block);
                    header('Location: /incentives/payouts?month=' . rawurlencode($month));
                    return;
                }
                $skippedDue = ($skippedDue ?? 0) + 1;
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
            // QA batch 4, MON-1: an associate payout can be settled wholly or mostly by TDS (paid + TDS ≤ the commission,
            // checked above); a staff payout keeps TDS within the amount paid.
            if ($paidInr < 0 || ($tds !== null && $tds < 0) || $paidInr + (float) ($tds ?? 0) <= 0
                || ($tdsCalc === null && $tds !== null && $tds > $paidInr)) {
                Flash::set('error', 'Enter the amount paid and the TDS: neither below 0, and together above 0' . ($tdsCalc === null ? ' (TDS no more than the amount paid)' : '') . '.');
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
            // D-264: what the payment really cost — abroad, the rupees the bank debited (required) and the charges;
            // in India, the bank charges (optional). Never deducted from the commission.
            $bankDebited = null;
            $bankCharges = null;
            if ($payout['payee_kind'] === 'associate') {
                $india = \App\Services\AssociatePayoutService::isIndia(\App\Repositories\AssociateRepository::find((int) $payout['associate_id']));
                $chargesRaw = isset($params['payoutId']) ? trim((string) ($_POST['bank_charges_inr'] ?? '')) : '';
                if ($chargesRaw !== '' && (!is_numeric($chargesRaw) || (float) $chargesRaw < 0 || (float) $chargesRaw > 10000000)) {
                    Flash::set('error', 'Enter the bank charges in rupees (0 or more), or leave them empty.');
                    header('Location: /incentives/payouts?month=' . rawurlencode($month));
                    return;
                }
                $bankCharges = $chargesRaw !== '' ? round((float) $chargesRaw, 2) : null;
                if (!$india) {
                    $debitedRaw = isset($params['payoutId']) ? trim((string) ($_POST['bank_debited_inr'] ?? '')) : '';
                    if ($debitedRaw === '' || !is_numeric($debitedRaw) || (float) $debitedRaw <= 0 || (float) $debitedRaw > 1000000000) {
                        if (isset($params['payoutId'])) {
                            Flash::set('error', 'Enter the rupees the bank debited for this transfer abroad (from the bank advice).');
                            header('Location: /incentives/payouts?month=' . rawurlencode($month));
                            return;
                        }
                        $skippedAbroad = ($skippedAbroad ?? 0) + 1;
                        continue;
                    }
                    $bankDebited = round((float) $debitedRaw, 2);
                }
            }
            // QA Round 6, R6-N17: a second click records and logs nothing more.
            if (!IncentiveService::markPaid($id, $paidOn, $paidInr, $tds, (int) $user['id'], $paidVia, $paidReference)) {
                continue;
            }
            if ($payout['payee_kind'] === 'associate') {
                \App\Services\AssociatePayoutService::recordPaymentCosts($id, $bankDebited, $bankCharges);
            }
            AuditLogRepository::log((int) $user['id'], 'INCENTIVE_PAID', 'incentive_payouts', $id, 'status', 'due', 'paid');
            if ($tdsCalc !== null) {
                $recordedTds = (float) ($tds ?? 0);
                IncentiveRepository::setPayoutTds($id, $recordedTds > 0 ? ($overrideReason !== null && $tdsCalc['gross'] > 0 ? round($recordedTds / $tdsCalc['gross'] * 100, 2) : $tdsCalc['rate']) : 0.0,
                    $recordedTds > 0 ? ($tdsCalc['section'] ?? \App\Services\AssociateTds::settings()['section']) : null,
                    $overrideReason !== null ? 'changed by hand: ' . $overrideReason . ' (worked out: ₹' . number_format($tdsCalc['tds'], 2) . ', ' . $tdsCalc['reason'] . ')' : $tdsCalc['reason'],
                    $overrideReason);
                // QA batch 4, MON-1: the TDS the cap cut off is carried to the next payout (none when changed by hand).
                IncentiveRepository::setPayoutTdsShortfall($id, $overrideReason === null ? (float) ($tdsCalc['shortfall'] ?? 0) : 0.0);
                if ($overrideReason !== null) {
                    AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_TDS_OVERRIDDEN', 'incentive_payouts', $id, 'tds_inr', number_format($tdsCalc['tds'], 2, '.', ''), number_format($recordedTds, 2, '.', ''), $overrideReason);
                }
            }
            if ($payout['payee_kind'] === 'associate') {
                // D-245 P3: GST on top for a GST-registered associate whose tax invoice for this payout is on file.
                // Fresh-eyes review L2: on the gross billed (paid + TDS), as recorded just now.
                $gst = \App\Services\AssociateCommission::gstFor(['paid_inr' => $paidInr, 'tds_inr' => (float) ($tds ?? 0)] + $payout);
                if ($gst > 0) {
                    \App\Config\Database::connection()->prepare('UPDATE incentive_payouts SET gst_pct = COALESCE(gst_pct, :p), gst_inr = :g WHERE id = :id')
                        ->execute(['p' => \App\Services\AssociateCommission::settings()['gst_pct'], 'g' => $gst, 'id' => $id]);
                }
                \App\Services\AssociateService::onPayoutPaid($id, (int) $user['id']);
            }
            $count++;
        }
        // QA Round 6, R6-N11: nothing selected (or all already paid) is not a success.
        if ($count === 0) {
            Flash::set('warning', 'Nothing was recorded — no payout due for payment was selected.' . (!empty($skipped) ? " {$skipped} associate payout(s) skipped: no verified bank account yet." : '')
                . (!empty($skippedDue) ? " {$skippedDue} associate payout(s) skipped: not due yet, or held while a buyer claim is open." : '')
                . (!empty($skippedDocs) ? " {$skippedDocs} payout(s) to associates abroad skipped: TRC / Form 10F / no-PE / Form 15CA" . (\App\Services\AssociateTds::settings()['cb_required'] ? '/15CB' : '') . ' not yet on file — pay them one by one.' : '')
                . (!empty($skippedAbroad) ? " {$skippedAbroad} payout(s) to associates abroad skipped: record each one with the rupees the bank debited." : ''));
        } else {
            Flash::set('success', ($count === 1 ? 'Payment recorded.' : "{$count} payments recorded.") . (!empty($skipped) ? " {$skipped} associate payout(s) skipped: no verified bank account yet." : '')
                . (!empty($skippedDue) ? " {$skippedDue} associate payout(s) skipped: not due yet, or held while a buyer claim is open." : '')
                . (!empty($skippedDocs) ? " {$skippedDocs} payout(s) to associates abroad skipped: TRC / Form 10F / no-PE / Form 15CA" . (\App\Services\AssociateTds::settings()['cb_required'] ? '/15CB' : '') . ' not yet on file — pay them one by one.' : '')
                . (!empty($skippedAbroad) ? " {$skippedAbroad} payout(s) to associates abroad skipped: record each one with the rupees the bank debited." : ''));
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
