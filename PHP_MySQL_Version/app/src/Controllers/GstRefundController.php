<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\FieldValidator;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\GstRefundRepository;
use App\Services\AuthService;
use App\Services\CaFyLockGuard;
use App\Services\PaymentDescription;

/**
 * Owner decision 2026-10-31 (option A, confirmed): CA → GST refunds (LUT).
 * NexaCrest exports under LUT and sells nothing in India, so the GST paid on
 * purchases comes back as a refund claimed for a whole period (RFD-01). This
 * page records each claim, then each amount the bank credits for it (with
 * its RFD-05 / UTR reference — rule D-212), and any amount refused. Every
 * credit goes to Zoho Books as a journal at the next sync. Port:
 * gstRefundController.js.
 */
final class GstRefundController
{
    private const BACK = 'Location: /ca/gst-refunds';

    public function index(array $params): void
    {
        $claims = GstRefundRepository::claims();
        foreach ($claims as &$claim) {
            $claim['period_label'] = PaymentDescription::periodLabel((string) $claim['period_from'], (string) $claim['period_to']);
            $claim['gst_on_bills'] = GstRefundRepository::gstOnBills((string) $claim['period_from'], (string) $claim['period_to']);
            $claim['outstanding'] = round(max(0.0, (float) $claim['amount_claimed'] - (float) $claim['amount_credited'] - (float) $claim['amount_refused']), 2);
            $claim['credit_rows'] = array_map(static fn (array $c): array => $c + ['description' => PaymentDescription::gstRefund($c + ['period_from' => $claim['period_from'], 'period_to' => $claim['period_to'], 'claim_arn' => $claim['claim_arn']])], GstRefundRepository::creditsFor((int) $claim['id']));
        }
        unset($claim);
        $sum = static fn (string $key): float => round(array_sum(array_map(static fn (array $c): float => (float) $c[$key], $claims)), 2);
        View::render('ca/gst_refunds', [
            'claims' => $claims,
            'totals' => ['claimed' => $sum('amount_claimed'), 'credited' => $sum('amount_credited'), 'refused' => $sum('amount_refused'), 'outstanding' => $sum('outstanding')],
            'parts' => GstRefundRepository::PARTS,
        ], 'layout/base');
    }

    public function recordClaim(array $params): void
    {
        $user = AuthService::currentUser();
        $from = trim((string) ($_POST['period_from'] ?? ''));
        $to = trim((string) ($_POST['period_to'] ?? ''));
        $arn = strtoupper(trim((string) ($_POST['claim_arn'] ?? '')));
        $filedOn = trim((string) ($_POST['filed_on'] ?? ''));
        $amount = trim((string) ($_POST['amount_claimed'] ?? ''));
        $error = match (true) {
            !self::isDate($from) || !self::isDate($to) || $to < $from => 'Enter the period the claim covers (from and to; "to" not before "from").',
            preg_match('/^[A-Z0-9][A-Z0-9-]{5,29}$/', $arn) !== 1 => 'Enter the RFD-01 claim no. (ARN) as on the GST portal: 6 to 30 letters and digits.',
            GstRefundRepository::arnTaken($arn) => "Claim {$arn} is already recorded.",
            !self::isDate($filedOn) || $filedOn > date('Y-m-d') || $filedOn < $from => 'Enter the date the claim was filed (not in the future, not before the period starts).',
            default => FieldValidator::moneyAmount(is_numeric($amount) ? (float) $amount : NAN, 'The amount claimed'),
        };
        if ($error !== null) {
            Flash::set('error', $error . ' Nothing was saved.');
            header(self::BACK);
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($filedOn, (int) $user['id'], $roleId, 'gst_refund_claims', 0, 'record')) {
            header(self::BACK);
            return;
        }
        $id = GstRefundRepository::createClaim($from, $to, $arn, $filedOn, (float) $amount, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'GST_REFUND_CLAIM_RECORDED', 'gst_refund_claims', $id, 'amount_claimed', null, number_format((float) $amount, 2, '.', ''), 'Claim ' . $arn . ', ' . PaymentDescription::periodLabel($from, $to));
        Flash::set('success', 'Claim ' . $arn . ' for ' . PaymentDescription::periodLabel($from, $to) . ' recorded: ₹' . number_format((float) $amount, 2) . ' claimed.');
        header(self::BACK);
    }

    /** Money the bank credited for a claim (and, with it, any amount refused). */
    public function recordCredit(array $params): void
    {
        $user = AuthService::currentUser();
        $claim = GstRefundRepository::findClaim((int) $params['id']);
        if ($claim === null) {
            http_response_code(404);
            echo 'Claim not found.';
            return;
        }
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $creditedOn = trim((string) ($_POST['credited_on'] ?? ''));
        $reference = trim((string) ($_POST['bank_reference'] ?? ''));
        $part = (string) ($_POST['part'] ?? '');
        $refused = trim((string) ($_POST['amount_refused'] ?? ''));
        $refusedAmount = $refused === '' ? 0.0 : (float) $refused;
        $open = round((float) $claim['amount_claimed'] - (float) $claim['amount_credited'] - (float) $claim['amount_refused'], 2);
        $error = match (true) {
            !is_numeric($amount) => 'Enter the amount the bank credited.',
            ($e = FieldValidator::moneyAmount((float) $amount, 'The amount credited')) !== null => $e,
            !self::isDate($creditedOn) || $creditedOn > date('Y-m-d') || $creditedOn < (string) $claim['filed_on'] => 'Enter the date the bank credited it (not in the future, not before the claim was filed).',
            ($e = FieldValidator::bankReference($reference, 'The bank reference (RFD-05 / UTR)')) !== null => $e,
            !array_key_exists($part, GstRefundRepository::PARTS) => 'Choose whether this is the provisional part or the final / balance.',
            $refused !== '' && (!is_numeric($refused) || $refusedAmount < 0) => 'Enter the amount refused as a number (or leave it empty).',
            round((float) $amount + $refusedAmount, 2) > $open + 0.005 => 'Credited plus refused (₹' . number_format((float) $amount + $refusedAmount, 2) . ') is more than what is still open on this claim (₹' . number_format(max(0, $open), 2) . ').',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error . ' Nothing was saved.');
            header(self::BACK);
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($creditedOn, (int) $user['id'], $roleId, 'gst_refund_credits', (int) $claim['id'], 'record')) {
            header(self::BACK);
            return;
        }
        $id = GstRefundRepository::addCredit((int) $claim['id'], (float) $amount, $creditedOn, $reference, $part, (int) $user['id']);
        if ($refusedAmount > 0) {
            GstRefundRepository::addRefused((int) $claim['id'], $refusedAmount, '₹' . number_format($refusedAmount, 2) . ' refused (with the credit of ' . date('j M Y', strtotime($creditedOn)) . ')');
        }
        $description = PaymentDescription::gstRefund(GstRefundRepository::findCredit($id) ?? []);
        AuditLogRepository::log((int) $user['id'], 'GST_REFUND_CREDITED', 'gst_refund_credits', $id, 'amount', null, number_format((float) $amount, 2, '.', ''), $description . ($refusedAmount > 0 ? ' · ₹' . number_format($refusedAmount, 2) . ' refused' : ''));
        Flash::set('success', 'Recorded: ' . $description . '. It goes to Zoho Books at the next sync.');
        header(self::BACK);
    }

    /** A credit typed wrongly: removed only while it is not in Zoho Books yet. */
    public function deleteCredit(array $params): void
    {
        $user = AuthService::currentUser();
        $credit = GstRefundRepository::findCredit((int) $params['creditId']);
        if ($credit === null) {
            http_response_code(404);
            echo 'Credit not found.';
            return;
        }
        if (!empty($credit['zoho_journal_id'])) {
            Flash::set('error', 'This refund credit is already in Zoho Books (journal ' . $credit['zoho_journal_id'] . '), so it cannot be changed here. Ask the CA to correct it there.');
            header(self::BACK);
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow((string) $credit['credited_on'], (int) $user['id'], $roleId, 'gst_refund_credits', (int) $credit['id'], 'delete')) {
            header(self::BACK);
            return;
        }
        GstRefundRepository::deleteCredit((int) $credit['id']);
        AuditLogRepository::log((int) $user['id'], 'GST_REFUND_CREDIT_REMOVED', 'gst_refund_credits', (int) $credit['id'], 'amount', number_format((float) $credit['amount'], 2, '.', ''), null, PaymentDescription::gstRefund($credit));
        Flash::set('success', 'Refund credit removed. Record it again with the right figures.');
        header(self::BACK);
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
