<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;

/**
 * Owner decision 2 Nov 2026 (D-235): TDS on associate commission, worked out
 * by the app so Accounts never has to guess (all values Admin-configurable in
 * Settings → Associate TDS, so the CA can change them later).
 *
 * India (section 194H): no TDS while the associate's commission in the
 * financial year (April–March) stays within the threshold (₹20,000). The
 * payment that takes the year past it carries TDS on itself plus a catch-up
 * on the commission paid earlier that year without TDS; every later payment
 * that year carries TDS on its full amount. Rate 2%; 20% (section 206AA)
 * when the associate has no PAN on file or it has not been verified.
 * TDS is rounded to the nearest rupee (section 288B).
 *
 * Abroad: no TDS by default (setting), but only once the Tax Residency
 * Certificate, Form 10F and the no-PE declaration are on file and accepted;
 * each payout also needs its Form 15CA (and 15CB, setting) before it is
 * marked paid.
 */
final class AssociateTds
{
    public const FOREIGN_DOCS = ['tax_residency' => 'Tax Residency Certificate (TRC)', 'form_10f' => 'Form 10F', 'no_pe' => 'No-PE declaration (no fixed place of business in India)'];

    /** @return array{section:string, rate:float, threshold:float, no_pan_rate:float, foreign:bool, cb_required:bool, zoho_tax_id:string} */
    public static function settings(): array
    {
        $s = IncentiveRepository::settings();
        return [
            'section' => trim((string) ($s['associate_tds_section'] ?? '194H')) ?: '194H',
            'rate' => (float) ($s['associate_tds_pct'] ?? 2.0),
            'threshold' => (float) ($s['associate_tds_threshold_inr'] ?? 20000),
            'no_pan_rate' => (float) ($s['associate_tds_no_pan_pct'] ?? 20.0),
            'foreign' => (string) ($s['associate_tds_foreign'] ?? '0') === '1',
            'cb_required' => (string) ($s['associate_15cb_required'] ?? '1') === '1',
            'zoho_tax_id' => trim((string) ($s['zoho_tds_tax_id'] ?? '')),
        ];
    }

    /** @return array{0:string,1:string} the Indian financial year (April–March) a date falls in */
    public static function fyBounds(string $date): array
    {
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);
        $start = $m >= 4 ? $y : $y - 1;
        return [sprintf('%04d-04-01', $start), sprintf('%04d-03-31', $start + 1)];
    }

    public static function fyLabel(string $date): string
    {
        $start = (int) substr(self::fyBounds($date)[0], 0, 4);
        return 'FY ' . $start . '-' . substr((string) ($start + 1), 2);
    }

    /** Quarter of the Indian financial year: Q1 Apr–Jun … Q4 Jan–Mar. */
    public static function quarter(string $date): int
    {
        $m = (int) substr($date, 5, 2);
        return $m >= 4 ? intdiv($m - 4, 3) + 1 : 4;
    }

    public static function isAbroad(array $associate): bool
    {
        return ($associate['based_in'] ?? 'india') !== 'india';
    }

    /** A PAN in the right shape with an accepted PAN card on file. */
    public static function panVerified(array $associate): bool
    {
        $pan = strtoupper(trim((string) ($associate['pan'] ?? '')));
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            return false;
        }
        $stmt = Database::connection()->prepare("SELECT 1 FROM associate_documents WHERE associate_id = :a AND kind = 'pan' AND status = 'accepted' LIMIT 1");
        $stmt->execute(['a' => $associate['id']]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<string> labels of the abroad papers (TRC, 10F, no-PE) not on file and accepted */
    public static function missingForeignDocs(int $associateId): array
    {
        $stmt = Database::connection()->prepare("SELECT DISTINCT kind FROM associate_documents WHERE associate_id = :a AND status = 'accepted'");
        $stmt->execute(['a' => $associateId]);
        $have = array_flip(array_column($stmt->fetchAll(), 'kind'));
        $missing = [];
        foreach (self::FOREIGN_DOCS as $kind => $label) {
            if (!isset($have[$kind])) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** @return list<string> the per-payout remittance forms (15CA, and 15CB when required) not yet uploaded for this payout */
    public static function missingPayoutDocs(int $payoutId): array
    {
        $stmt = Database::connection()->prepare("SELECT DISTINCT kind FROM associate_documents WHERE payout_id = :p AND status <> 'rejected'");
        $stmt->execute(['p' => $payoutId]);
        $have = array_flip(array_column($stmt->fetchAll(), 'kind'));
        $missing = [];
        if (!isset($have['form_15ca'])) {
            $missing[] = 'Form 15CA';
        }
        if (self::settings()['cb_required'] && !isset($have['form_15cb'])) {
            $missing[] = 'Form 15CB';
        }
        return $missing;
    }

    /**
     * The TDS on one associate payout paid on $paidOn.
     *
     * @return array{gross:float, rate:float, tds:float, net:float, section:?string, reason:string, catch_up:float, carried_in:float, shortfall:float, abroad:bool, missing_foreign:list<string>, missing_payout:list<string>, fy_total:float}
     */
    public static function compute(array $payout, string $paidOn): array
    {
        $s = self::settings();
        $gross = round((float) $payout['amount_inr'], 2);
        $associate = AssociateRepository::find((int) $payout['associate_id']) ?? ['id' => (int) $payout['associate_id'], 'based_in' => 'india'];
        $result = ['gross' => $gross, 'rate' => 0.0, 'tds' => 0.0, 'net' => $gross, 'section' => null, 'reason' => '', 'catch_up' => 0.0, 'carried_in' => 0.0, 'shortfall' => 0.0,
            'abroad' => self::isAbroad($associate), 'missing_foreign' => [], 'missing_payout' => [], 'fy_total' => 0.0];
        if ($result['abroad']) {
            $result['missing_foreign'] = self::missingForeignDocs((int) $associate['id']);
            $result['missing_payout'] = isset($payout['id']) ? self::missingPayoutDocs((int) $payout['id']) : [];
            if (!$s['foreign']) {
                $result['reason'] = $result['missing_foreign'] === []
                    ? 'abroad — no TDS (TRC/10F/no-PE on file)'
                    : 'abroad — no TDS once on file: ' . implode(', ', $result['missing_foreign']);
                return $result;
            }
        }
        [$from, $to] = self::fyBounds($paidOn);
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(amount_inr), 0) AS gross, COALESCE(SUM(CASE WHEN COALESCE(tds_inr, 0) = 0 THEN amount_inr ELSE 0 END), 0) AS untaxed
               FROM incentive_payouts
              WHERE associate_id = :a AND payee_kind = 'associate' AND status = 'paid' AND amount_inr > 0 AND clawback_of_payout_id IS NULL
                AND paid_on BETWEEN :f AND :t AND id <> :id"
        );
        $stmt->execute(['a' => $associate['id'], 'f' => $from, 't' => $to, 'id' => (int) ($payout['id'] ?? 0)]);
        $prior = $stmt->fetch();
        $priorGross = round((float) $prior['gross'], 2);
        $result['fy_total'] = round($priorGross + $gross, 2);
        $panOk = self::panVerified($associate);
        $rate = $panOk ? $s['rate'] : $s['no_pan_rate'];
        if ($result['fy_total'] <= $s['threshold'] + 0.005) {
            $result['reason'] = 'below threshold — ' . self::fyLabel($paidOn) . ' total ₹' . number_format($result['fy_total'], 2) . ' of ₹' . number_format($s['threshold'], 2);
            return $result;
        }
        // Catch-up only on the payment that takes the year past the threshold; once past it, each payment carries TDS on itself.
        $catchUp = $priorGross <= $s['threshold'] + 0.005 ? round((float) $prior['untaxed'], 2) : 0.0;
        // QA batch 4, MON-1: TDS is never more than the payout. Whatever the cap cuts off is carried to the
        // associate's next payout in the same financial year (the latest paid payout records what is still owed).
        $carryIn = self::shortfallCarried((int) $associate['id'], $from, $to, (int) ($payout['id'] ?? 0));
        $due = (float) round(($gross + $catchUp) * $rate / 100, 0) + $carryIn;
        $tds = min($gross, $due);
        $result['rate'] = $rate;
        $result['tds'] = $tds;
        $result['net'] = round($gross - $tds, 2);
        $result['section'] = $panOk ? $s['section'] : '206AA';
        $result['catch_up'] = $catchUp;
        $result['carried_in'] = $carryIn;
        $result['shortfall'] = round($due - $tds, 2);
        $result['reason'] = ($panOk ? $s['section'] . ' ' . self::pct($rate) : 'no PAN ' . self::pct($rate) . ' (206AA)')
            . ($catchUp > 0 ? ' — includes catch-up on ₹' . number_format($catchUp, 2) . ' paid earlier this ' . self::fyLabel($paidOn) . ' without TDS' : '')
            . ($carryIn > 0 ? ' — includes ₹' . number_format($carryIn, 2) . ' TDS carried from an earlier payout' : '')
            . ($result['shortfall'] > 0 ? ' — capped at the payout; ₹' . number_format($result['shortfall'], 2) . ' TDS carried to the next payout' : '');
        return $result;
    }

    /**
     * QA batch 4, MON-1: the TDS still owed from the associate's latest paid payout in the year
     * (a payout whose TDS was capped at the payout itself), carried into the next one.
     */
    public static function shortfallCarried(int $associateId, string $from, string $to, int $exceptPayoutId = 0): float
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(tds_shortfall_inr, 0) FROM incentive_payouts
              WHERE associate_id = :a AND payee_kind = 'associate' AND status = 'paid' AND clawback_of_payout_id IS NULL
                AND paid_on BETWEEN :f AND :t AND id <> :id
              ORDER BY paid_on DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['a' => $associateId, 'f' => $from, 't' => $to, 'id' => $exceptPayoutId]);
        return round((float) ($stmt->fetchColumn() ?: 0), 2);
    }

    public static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
    }

    /**
     * Owner rule D-212: the description of an associate commission payment,
     * built from the facts — gross, TDS (section and rate), net, the GST paid on top
     * (fresh-eyes review M5: the Zoho payment amount includes it) and the UTR.
     *
     * @param list<string> $orderRefs
     */
    public static function paymentDescription(string $associateName, array $orderRefs, float $gross, float $tds, ?float $rate, ?string $section, float $net, string $reference, float $gst = 0.0, string $referenceLabel = 'UTR'): string
    {
        $tdsPart = $tds > 0
            ? 'TDS u/s ' . ($section ?? self::settings()['section']) . ' ₹' . number_format($tds, 2) . ($rate !== null && $rate > 0 ? ' (' . self::pct($rate) . ')' : '')
            : 'no TDS';
        return 'Associate commission for ' . $associateName . ' — order' . (count($orderRefs) > 1 ? 's ' : ' ') . implode(', ', $orderRefs)
            . ' — gross ₹' . number_format($gross, 2) . ', ' . $tdsPart . ', net paid ₹' . number_format($net, 2)
            . ($gst > 0 ? ' + GST ₹' . number_format($gst, 2) . " on the associate's tax invoice (input credit), total paid ₹" . number_format(round($net + $gst, 2), 2) : '')
            // Live Zoho check 7 Nov 2026: a transfer abroad goes by SWIFT, so its reference is a SWIFT ref, not a UTR (rule D-212).
            . ($reference !== '' ? ' · ' . $referenceLabel . ' ' . $reference : '');
    }

    /**
     * Owner decision 2 Nov 2026: the quarterly TDS register — every associate
     * payout paid in the financial year (optionally one quarter).
     *
     * @return list<array<string,mixed>>
     */
    public static function register(string $fyStart, ?int $quarter = null): array
    {
        $from = $fyStart;
        $to = sprintf('%04d-03-31', (int) substr($fyStart, 0, 4) + 1);
        $stmt = Database::connection()->prepare(
            "SELECT p.id, p.paid_on, p.amount_inr, p.paid_inr, p.tds_inr, p.tds_rate, p.tds_section, p.tds_reason, p.tds_challan_no, p.tds_challan_date, p.paid_reference,
                    a.id AS associate_id, a.name, a.pan, a.based_in, a.country, o.order_reference, o.buyer_inquiry_ref,
                    (SELECT COUNT(*) FROM associate_documents d WHERE d.associate_id = a.id AND d.kind = 'form_16a' AND d.status <> 'rejected'
                        AND d.created_at >= :f2) AS form16a_count
               FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id JOIN orders o ON o.id = p.order_id
              WHERE p.payee_kind = 'associate' AND p.status = 'paid' AND p.amount_inr > 0 AND p.clawback_of_payout_id IS NULL
                AND p.paid_on BETWEEN :f AND :t
              ORDER BY p.paid_on, p.id"
        );
        $stmt->execute(['f' => $from, 't' => $to, 'f2' => $from]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['quarter'] = self::quarter((string) $r['paid_on']);
        }
        unset($r);
        return $quarter === null ? $rows : array_values(array_filter($rows, static fn (array $r): bool => (int) $r['quarter'] === $quarter));
    }

    /**
     * Owner decision 2 Nov 2026: reminders to Accounts (pay_incentives) —
     * deposit last month's TDS by the 7th (challan 281), and file Form 26Q
     * after each quarter (31 Jul, 31 Oct, 31 Jan, 31 May). Daily job.
     *
     * @return list<array{type:string, message:string}>
     */
    public static function reminders(string $today): array
    {
        $out = [];
        $pdo = Database::connection();
        $day = (int) substr($today, 8, 2);
        $month = (int) substr($today, 5, 2);
        if ($day <= 7) {
            $prevStart = date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month'));
            $prevEnd = date('Y-m-t', strtotime($prevStart));
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(tds_inr), 0) AS tds, COUNT(*) AS n FROM incentive_payouts
                                    WHERE payee_kind = 'associate' AND status = 'paid' AND tds_inr > 0 AND (tds_challan_no IS NULL OR tds_challan_no = '')
                                      AND paid_on BETWEEN :f AND :t");
            $stmt->execute(['f' => $prevStart, 't' => $prevEnd]);
            $r = $stmt->fetch();
            if ((float) $r['tds'] > 0) {
                $out[] = ['type' => 'associate_tds_deposit', 'message' => 'Deposit the TDS of ₹' . number_format((float) $r['tds'], 2) . ' deducted on associate commission in '
                    . date('F Y', strtotime($prevStart)) . ' (' . (int) $r['n'] . ' payment(s)) by ' . date('j F Y', strtotime(substr($today, 0, 7) . '-07'))
                    . ' with challan 281, then enter the challan no. in Associates → TDS register.'];
            }
        }
        // Form 26Q is due on the last day of the month after the quarter (31 May for Q4).
        $dueMonths = [7 => 1, 10 => 2, 1 => 3, 5 => 4];
        if (isset($dueMonths[$month]) && $day >= 20) {
            $q = $dueMonths[$month];
            $fyStartYear = $q === 4 ? (int) substr($today, 0, 4) - 1 : ((int) substr($today, 0, 4) - ($q === 3 ? 1 : 0));
            $rows = self::register(sprintf('%04d-04-01', $fyStartYear), $q);
            $tds = array_sum(array_map(static fn (array $r): float => (float) ($r['tds_inr'] ?? 0), $rows));
            if ($tds > 0) {
                $out[] = ['type' => 'associate_tds_26q', 'message' => 'File Form 26Q for Q' . $q . ' of FY ' . $fyStartYear . '-' . substr((string) ($fyStartYear + 1), 2)
                    . ' (TDS on associate commission ₹' . number_format($tds, 2) . ') by ' . date('j F Y', strtotime(date('Y-m-t', strtotime($today))))
                    . ', then upload each associate\'s Form 16A.'];
            }
        }
        return $out;
    }
}
