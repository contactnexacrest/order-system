<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\FieldValidator;
use App\Helpers\Flash;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Services\AuthService;
use App\Services\MoneyVisibility;
use App\Services\PaymentDescription;

/**
 * Owner decision D-215 (5a): an order's own payment schedule — advance,
 * the steps in between, balance. Each step in between is its own payment:
 * remittance recorded with its bank reference, cleared, then pushed to Zoho
 * Books by the revenue sync (CaSyncService). Port:
 * paymentMilestoneController.js (same rules and messages).
 */
final class PaymentMilestoneController
{
    private static function back(int $orderId): void
    {
        header("Location: /orders/{$orderId}#payment-schedule");
    }

    private static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** @return array{0: list<array{pct: float, due_text: string}>, 1: ?string} the steps, or the error */
    public static function parseSteps(array $pcts, array $dues): array
    {
        $steps = [];
        $count = max(count($pcts), count($dues));
        for ($i = 0; $i < $count; $i++) {
            $pctText = trim((string) ($pcts[$i] ?? ''));
            $due = rtrim(trim((string) ($dues[$i] ?? '')), '. ');
            if ($pctText === '' && $due === '') {
                continue;
            }
            $n = count($steps) + 1;
            if ($error = ($pctText === '' ? "Milestone {$n} %: enter its share of the FOB value." : FieldValidator::decimal($pctText, "Milestone {$n} %", 0.01, 99))) {
                return [[], $error];
            }
            if (round((float) $pctText, 2) != (float) $pctText) {
                return [[], "Milestone {$n} %: use at most 2 decimal places."];
            }
            if (mb_strlen($due) < 5 || mb_strlen($due) > 200) {
                return [[], "Milestone {$n}: say when it is due, in 5 to 200 characters (e.g. \"on shipment — within 3 Calendar Days of receiving the scanned Bill of Lading\")."];
            }
            $steps[] = ['pct' => (float) $pctText, 'due_text' => $due];
        }
        if (count($steps) > PaymentMilestoneRepository::MAX_STEPS) {
            return [[], 'An order can have at most ' . PaymentMilestoneRepository::MAX_STEPS . ' milestones between the advance and the balance.'];
        }
        return [$steps, null];
    }

    public function saveSchedule(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if (!MoneyVisibility::can(MoneyVisibility::SELLING)) {
            Flash::set('error', 'The payment schedule carries selling prices your department does not see.');
            self::back($orderId);
            return;
        }
        $payment = OrderPaymentStatusRepository::find($orderId);
        if (!empty($payment['advance_cleared_at'])) {
            Flash::set('error', 'The payment schedule is fixed once the advance is cleared — change it with a payment terms amendment.');
            self::back($orderId);
            return;
        }
        if (PaymentMilestoneRepository::anyMoneyRecorded($orderId)) {
            Flash::set('error', 'A milestone payment is already recorded — the schedule can no longer be changed.');
            self::back($orderId);
            return;
        }
        [$steps, $error] = self::parseSteps((array) ($_POST['milestone_pct'] ?? []), (array) ($_POST['milestone_due'] ?? []));
        $advancePct = (float) $order['advance_pct'];
        $total = array_sum(array_map(static fn (array $s): float => round($s['pct'], 2), $steps));
        if ($error === null && round($advancePct + $total, 2) >= 100) {
            $error = 'Advance ' . self::pct($advancePct) . '% + milestones ' . self::pct($total) . '% leave nothing for the balance — together they must stay below 100%.';
        }
        if ($error !== null) {
            Flash::set('error', $error);
            self::back($orderId);
            return;
        }
        $before = PaymentMilestoneRepository::forOrder($orderId);
        PaymentMilestoneRepository::replaceSchedule($orderId, $steps, $advancePct);
        $summary = self::summary($advancePct, $steps);
        AuditLogRepository::log(AuthService::currentUserId(), 'PAYMENT_SCHEDULE_SET', 'orders', $orderId, 'payment_milestones',
            self::summary($advancePct, array_map(static fn (array $m): array => ['pct' => (float) $m['pct'], 'due_text' => (string) $m['due_text']], $before)), $summary);
        Flash::set('success', ($steps === [] ? 'Payment schedule back to advance and balance: ' : 'Payment schedule saved: ') . $summary . '. Regenerate the Proforma Invoice so the buyer sees it.');
        self::back($orderId);
    }

    /** @param list<array{pct: float, due_text: string}> $steps */
    public static function summary(float $advancePct, array $steps): string
    {
        $parts = [self::pct($advancePct) . '% advance'];
        $total = 0.0;
        foreach ($steps as $s) {
            $parts[] = self::pct($s['pct']) . '% ' . $s['due_text'];
            $total += round($s['pct'], 2);
        }
        $parts[] = self::pct(round(100 - $advancePct - $total, 2)) . '% balance';
        return implode(' · ', $parts);
    }

    private static function milestoneFor(int $orderId, array $params): ?array
    {
        $m = PaymentMilestoneRepository::find((int) ($params['milestoneId'] ?? 0));
        return $m !== null && (int) $m['order_id'] === $orderId ? $m : null;
    }

    public function record(array $params): void
    {
        $orderId = (int) $params['id'];
        $m = self::milestoneFor($orderId, $params);
        if ($m === null) {
            http_response_code(404);
            echo 'Milestone not found.';
            return;
        }
        if (!DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'PI')) {
            Flash::set('error', 'Generate the Proforma Invoice first — the buyer pays each milestone against it.');
            self::back($orderId);
            return;
        }
        if ($m['received_amount'] !== null) {
            Flash::set('warning', "Milestone {$m['seq']} remittance is already recorded — nothing changed.");
            self::back($orderId);
            return;
        }
        $amount = (float) ($_POST['amount'] ?? 0);
        $rate = (float) ($_POST['exchange_rate'] ?? 0);
        $bankRef = trim((string) ($_POST['bank_reference'] ?? ''));
        $receivedOn = trim((string) ($_POST['received_on'] ?? '')) ?: date('Y-m-d');
        $note = trim((string) ($_POST['staff_note'] ?? ''));
        $error = FieldValidator::moneyAmount($amount, "The milestone {$m['seq']} amount")
            ?? FieldValidator::exchangeRate($rate)
            ?? FieldValidator::bankReference($bankRef)
            ?? (\DateTime::createFromFormat('!Y-m-d', $receivedOn) === false || $receivedOn > date('Y-m-d') ? 'Enter the date the money was received — today or earlier.' : null)
            ?? (mb_strlen($note) > 255 ? 'The note can be at most 255 characters.' : null);
        if ($error !== null) {
            Flash::set('error', $error);
            self::back($orderId);
            return;
        }
        $userId = (int) AuthService::currentUserId();
        PaymentMilestoneRepository::recordReceived((int) $m['id'], $amount, $receivedOn, $rate, $bankRef, $note, $userId);
        $description = PaymentDescription::milestonePayment(PaymentMilestoneRepository::find((int) $m['id']) ?? $m);
        AuditLogRepository::log($userId, 'MILESTONE_PAYMENT_RECORDED', 'order_payment_milestones', (int) $m['id'], 'received_amount', null, $description);
        Flash::set('success', "Recorded: {$description}. Mark it cleared once your bank confirms receipt.");
        self::back($orderId);
    }

    public function clear(array $params): void
    {
        $orderId = (int) $params['id'];
        $m = self::milestoneFor($orderId, $params);
        if ($m === null) {
            http_response_code(404);
            echo 'Milestone not found.';
            return;
        }
        if ($m['received_amount'] === null) {
            Flash::set('error', "Record the milestone {$m['seq']} remittance before marking it cleared.");
            self::back($orderId);
            return;
        }
        if ($m['cleared_on'] !== null) {
            Flash::set('warning', "Milestone {$m['seq']} is already cleared — nothing changed.");
            self::back($orderId);
            return;
        }
        $clearedOn = trim((string) ($_POST['cleared_on'] ?? '')) ?: date('Y-m-d');
        if (\DateTime::createFromFormat('!Y-m-d', $clearedOn) === false || $clearedOn > date('Y-m-d') || $clearedOn < (string) $m['received_on']) {
            Flash::set('error', 'Enter the date your bank cleared it — on or after the day it was received, and not in the future.');
            self::back($orderId);
            return;
        }
        $userId = (int) AuthService::currentUserId();
        PaymentMilestoneRepository::markCleared((int) $m['id'], $clearedOn, $userId);
        AuditLogRepository::log($userId, 'MILESTONE_PAYMENT_CLEARED', 'order_payment_milestones', (int) $m['id'], 'cleared_on', null, $clearedOn);
        Flash::set('success', "Milestone {$m['seq']} cleared. It goes to Zoho Books with the next revenue sync.");
        self::back($orderId);
    }
}
