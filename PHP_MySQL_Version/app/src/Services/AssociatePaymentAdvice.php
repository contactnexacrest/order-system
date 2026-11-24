<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\TwigFilter;

/**
 * Owner decision D-410 point 8 (24 Nov 2026, mockup M14 confirmed twice): the Commission Payment Advice (CPA).
 *  - One advice per bank transfer to an associate: the payouts of one associate paid on the same day with the same
 *    bank reference (UTR / SWIFT). A take-back deducted from them is listed as a deduction.
 *  - Number NC/SC/CPA/A{4-digit associate no.}/{YY}/{NNN}, counted per associate per financial year (1 April; YY =
 *    the year it starts). Made once, when the payouts are marked paid; what it shows is frozen in context_json.
 *  - The MD may correct it: a new advice "Rev n" with a reason; the old one is kept and marked superseded.
 *  - Company details from Admin (company settings); no TAN. Same design as the Quotation / PI / CI (_layout).
 *  - Shows only the associate's commission — never costs, margins or supplier prices (CAFIN never reaches it).
 * Node twin: src/services/associatePaymentAdvice.js.
 */
final class AssociatePaymentAdvice
{
    public const TITLE = 'COMMISSION PAYMENT ADVICE';

    public static function associateNo(int $associateId): string
    {
        return 'A' . str_pad((string) $associateId, 4, '0', STR_PAD_LEFT);
    }

    /** The financial year's two-digit start year: 2026-04-01 … 2027-03-31 → "26". */
    public static function fyYy(string $date): string
    {
        $y = (int) substr($date, 0, 4);
        return substr((string) ((int) substr($date, 5, 2) >= 4 ? $y : $y - 1), -2);
    }

    public static function number(int $associateId, string $fyYy, int $seq): string
    {
        return 'NC/SC/CPA/' . self::associateNo($associateId) . '/' . $fyYy . '/' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * After payouts are marked paid: one advice for each bank transfer among them that has none yet.
     * @param list<int> $payoutIds
     * @return list<int> the advice ids made
     */
    public static function afterPaid(array $payoutIds, ?int $userId): array
    {
        if ($payoutIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $payoutIds));
        $rows = Database::connection()->query(
            "SELECT id, associate_id, paid_on, paid_reference FROM incentive_payouts
              WHERE id IN ({$in}) AND payee_kind = 'associate' AND status = 'paid' AND payment_advice_id IS NULL
                AND clawback_of_payout_id IS NULL AND COALESCE(paid_inr, 0) > 0"
        )->fetchAll();
        $groups = [];
        foreach ($rows as $r) {
            $groups[(int) $r['associate_id'] . '|' . $r['paid_on'] . '|' . (string) $r['paid_reference']][] = (int) $r['id'];
        }
        $made = [];
        foreach ($groups as $key => $ids) {
            try {
                // Paid one by one into the same bank transfer (always so abroad): the transfer's advice is corrected to include it (Rev n).
                [$aId, $day, $ref] = explode('|', $key, 3);
                $s = Database::connection()->prepare('SELECT * FROM associate_payment_advices WHERE associate_id = :a AND paid_on = :d AND COALESCE(paid_reference, \'\') = :r AND superseded_at IS NULL ORDER BY id DESC LIMIT 1');
                $s->execute(['a' => (int) $aId, 'd' => $day, 'r' => $ref]);
                $existing = $s->fetch() ?: null;
                if ($existing !== null) {
                    $linked = array_map('intval', Database::connection()->query('SELECT id FROM incentive_payouts WHERE payment_advice_id = ' . (int) $existing['id'])->fetchAll(\PDO::FETCH_COLUMN));
                    $made[] = self::create(array_values(array_unique(array_merge($linked, $ids))), $userId, $existing, 'Another order paid in the same bank transfer was added');
                    continue;
                }
                $made[] = self::create($ids, $userId, null, null);
            } catch (\Throwable $e) {
                error_log('[associate payment advice] ' . $e->getMessage()); // the payment itself is recorded; the advice can be made again
            }
        }
        return $made;
    }

    /**
     * Makes one advice for these payouts (same associate, day and reference). With $supersedes: a correction (Rev n).
     * @param list<int> $payoutIds
     */
    public static function create(array $payoutIds, ?int $userId, ?array $supersedes, ?string $reason): int
    {
        $pdo = Database::connection();
        $in = implode(',', array_map('intval', $payoutIds));
        $payouts = $pdo->query("SELECT p.*, o.order_reference, o.client_id, o.currency_code AS order_currency, o.associate_level_kind, o.associate_level_value, o.associate_rate_pct
                                 FROM incentive_payouts p JOIN orders o ON o.id = p.order_id WHERE p.id IN ({$in}) ORDER BY o.order_reference, p.id")->fetchAll();
        if ($payouts === []) {
            throw new \RuntimeException('No payouts for the advice.');
        }
        $first = $payouts[0];
        $associateId = (int) $first['associate_id'];
        $paidOn = (string) $first['paid_on'];
        $fy = $supersedes !== null ? (string) $supersedes['fy_yy'] : self::fyYy($paidOn);
        $own = !$pdo->inTransaction(); // inside a caller's transaction (tests, batch), it joins it
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            if ($supersedes !== null) {
                $seq = (int) $supersedes['seq'];
                $revision = (int) $pdo->query('SELECT COALESCE(MAX(revision), 0) + 1 FROM associate_payment_advices WHERE associate_id = ' . $associateId
                    . ' AND fy_yy = ' . $pdo->quote($fy) . ' AND seq = ' . $seq . ' FOR UPDATE')->fetchColumn();
            } else {
                $seq = (int) $pdo->query('SELECT COALESCE(MAX(seq), 0) + 1 FROM associate_payment_advices WHERE associate_id = ' . $associateId
                    . ' AND fy_yy = ' . $pdo->quote($fy) . ' FOR UPDATE')->fetchColumn();
                $revision = 0;
            }
            $number = self::number($associateId, $fy, $seq);
            $context = self::context($payouts, $number, $revision, $reason);
            $pdo->prepare('INSERT INTO associate_payment_advices (associate_id, advice_no, fy_yy, seq, revision, supersedes_id, revision_reason, paid_on, paid_reference, currency_code, total_paid, context_json, created_by)
                           VALUES (:a, :n, :fy, :s, :r, :sup, :why, :d, :ref, :cur, :tot, :ctx, :u)')
                ->execute(['a' => $associateId, 'n' => $number, 'fy' => $fy, 's' => $seq, 'r' => $revision, 'sup' => $supersedes['id'] ?? null, 'why' => $reason,
                    'd' => $paidOn, 'ref' => $first['paid_reference'], 'cur' => $context['currency'], 'tot' => $context['total_paid_raw'],
                    'ctx' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'u' => $userId]);
            $id = (int) $pdo->lastInsertId();
            $pdo->exec("UPDATE incentive_payouts SET payment_advice_id = {$id} WHERE id IN ({$in})");
            if ($supersedes !== null) {
                $pdo->prepare('UPDATE associate_payment_advices SET superseded_at = NOW() WHERE id = :id')->execute(['id' => (int) $supersedes['id']]);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $pdo->rollBack();
            }
            throw $e;
        }
        AuditLogRepository::log($userId, $supersedes !== null ? 'ASSOCIATE_PAYMENT_ADVICE_REVISED' : 'ASSOCIATE_PAYMENT_ADVICE_MADE', 'associate_payment_advices', $id,
            'advice_no', $supersedes['advice_no'] ?? null, $number . ($revision > 0 ? ' Rev ' . $revision : '') . ($reason !== null ? ' — reason: ' . $reason : ''));
        return $id;
    }

    /** MD correction: a new advice "Rev n" from the payouts as they are now; the old one is kept and marked superseded. */
    public static function revise(int $adviceId, string $reason, int $userId): ?string
    {
        $a = self::find($adviceId);
        if ($a === null) {
            return 'That payment advice no longer exists.';
        }
        if (!empty($a['superseded_at'])) {
            return 'This advice was already corrected — correct the latest one.';
        }
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 300) {
            return 'Give the reason for the correction (10 to 300 characters) — it is kept on the advice and in the Audit Log.';
        }
        $ids = array_map('intval', Database::connection()->query("SELECT id FROM incentive_payouts WHERE payment_advice_id = {$adviceId}")->fetchAll(\PDO::FETCH_COLUMN));
        if ($ids === []) {
            return 'No payments are linked to this advice.';
        }
        self::create($ids, $userId, $a, trim($reason));
        return null;
    }

    /** @param list<array<string,mixed>> $payouts @return array<string,mixed> everything the advice prints (frozen) */
    public static function context(array $payouts, string $number, int $revision, ?string $reason): array
    {
        $first = $payouts[0];
        $a = AssociateRepository::find((int) $first['associate_id']) ?? [];
        $india = AssociatePayoutService::isIndia($a);
        $cur = $india ? 'INR' : (string) ($first['currency_code'] ?: 'USD');
        // Rupees in Indian grouping (₹1,98,914.40), as on the confirmed M14 sample; other currencies as usual.
        $inr = static fn (float $v): string => '₹' . \App\Helpers\MoneyFormat::indian($v);
        $m = static fn (float $v): string => $cur === 'INR' ? $inr($v) : $cur . ' ' . number_format($v, 2);
        $lines = [];
        $gross = 0.0;
        $tds = 0.0;
        $paid = 0.0;
        foreach ($payouts as $i => $p) {
            $order = OrderRepository::find((int) $p['order_id']) ?? [];
            $client = Database::connection()->query('SELECT company_legal_name FROM clients WHERE id = ' . (int) $p['client_id'])->fetchColumn();
            $lineTds = (float) $p['tds_inr'];
            $linePaid = $india ? (float) $p['paid_inr'] : (float) $p['amount_fc'];
            $lineGross = $india ? $linePaid + $lineTds : $linePaid;
            $gross += $lineGross;
            $tds += $lineTds;
            $paid += $linePaid;
            $lines[] = [
                'no' => $i + 1,
                'order' => (string) $p['order_reference'],
                'client' => (string) $client,
                'fob' => (string) $p['order_currency'] . ' ' . number_format(OrderProductRepository::totalFobValue((int) $p['order_id']), 2),
                'pct' => AssociatePortalRepository::orderRateText($order) ?? ($p['associate_rate_pct'] !== null ? rtrim(rtrim(number_format((float) $p['associate_rate_pct'], 2), '0'), '.') . '%' : '—'),
                'gross' => $m($lineGross),
                'tds' => $lineTds > 0 ? $inr($lineTds) : '—',
                'paid' => $m($linePaid),
            ];
        }
        // Take-backs deducted from these payments (marked paid with "Deducted from payout no. …").
        $refs = array_map(static fn (array $p): string => 'Deducted from payout no. ' . (int) $p['id'], $payouts);
        $stmt = Database::connection()->prepare('SELECT p.amount_inr, p.note FROM incentive_payouts p WHERE p.associate_id = ? AND p.clawback_of_payout_id IS NOT NULL AND p.paid_reference IN (' . implode(',', array_fill(0, count($refs), '?')) . ') ORDER BY p.id');
        $deductions = [];
        $stmt->execute(array_merge([(int) $first['associate_id']], $refs));
        foreach ($stmt->fetchAll() as $d) {
            $deductions[] = ['text' => (string) ($d['note'] ?: 'Commission taken back'), 'amount' => '− ' . $inr(abs((float) $d['amount_inr']))];
        }
        $bank = AssociateBankRepository::usable((int) $first['associate_id'], (string) $first['paid_on']);
        $sectionRate = $tds > 0 ? trim('section ' . ($first['tds_section'] ?: '194H') . ($first['tds_rate'] !== null ? ', ' . AssociateTds::pct((float) $first['tds_rate']) : '')) : '';
        $company = DocumentDataAssembler::companyBlock();
        $paidOn = \App\Helpers\Dates::short((string) $first['paid_on']);
        $ref = (string) ($first['paid_reference'] ?? '');
        return [
            'doc_title' => self::TITLE,
            'number' => $number,
            'revision' => $revision,
            'revision_label' => $revision > 0 ? 'Rev ' . $revision : '',
            'revision_reason' => $reason,
            'paid_on' => $paidOn,
            'paid_reference' => $ref,
            'paid_via' => $india ? 'Bank transfer (NEFT / RTGS / IMPS)' : 'Bank transfer (SWIFT)',
            'associate_no' => self::associateNo((int) $first['associate_id']),
            'associate' => ['name' => (string) ($a['name'] ?? ''), 'pan' => (string) ($a['pan'] ?? ''), 'country' => $india ? 'India' : (string) ($a['country'] ?? '')],
            'bank' => $bank !== null ? trim((string) $bank['bank_name'] . ' · account ending ' . (string) $bank['account_last4'] . ((string) ($bank['ifsc'] ?? '') !== '' ? ' · IFSC ' . $bank['ifsc'] : ((string) ($bank['swift'] ?? '') !== '' ? ' · SWIFT ' . $bank['swift'] : ''))) : '—',
            'company' => ['legal_name' => (string) ($company['legal_name'] ?? ''), 'registered_office' => (string) ($company['registered_office'] ?? ''),
                'gstin' => (string) ($company['gstin'] ?? ''), 'iec_pan' => (string) ($company['iec_pan'] ?? ''), 'phone' => (string) ($company['phone'] ?? ''), 'email' => (string) ($company['email'] ?? '')],
            'currency' => $cur,
            'india' => $india,
            'lines' => $lines,
            'deductions' => $deductions,
            'total_gross' => $m($gross),
            'total_tds' => $tds > 0 ? $inr($tds) : '',
            'tds_text' => $sectionRate,
            'total_paid' => $m($paid),
            'total_paid_raw' => round($paid, 2),
            'headline' => 'PAID: ' . $m($paid) . ' was transferred to your bank account on ' . $paidOn . ($ref !== '' ? ' (bank ref ' . $ref . ')' : '') . '.',
        ];
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM associate_payment_advices WHERE id = :id');
        $s->execute(['id' => $id]);
        return $s->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> newest first; the associate sees only their own */
    public static function forAssociate(int $associateId): array
    {
        $s = Database::connection()->prepare('SELECT * FROM associate_payment_advices WHERE associate_id = :a ORDER BY paid_on DESC, id DESC');
        $s->execute(['a' => $associateId]);
        return $s->fetchAll();
    }

    /** "Bank transfers made this run" (Accounts): one line per advice whose payouts belong to the incentive month. */
    public static function forMonth(string $month): array
    {
        $s = Database::connection()->prepare(
            'SELECT DISTINCT c.*, a.name AS associate_name FROM associate_payment_advices c JOIN associates a ON a.id = c.associate_id
               JOIN incentive_payouts p ON p.payment_advice_id = c.id WHERE p.incentive_month = :m ORDER BY c.paid_on, a.name, c.id'
        );
        $s->execute(['m' => $month]);
        return $s->fetchAll();
    }

    /** @return array<string,mixed> */
    public static function storedContext(array $advice): array
    {
        return json_decode((string) $advice['context_json'], true, 512, JSON_THROW_ON_ERROR) + ['superseded' => !empty($advice['superseded_at'])];
    }

    public static function html(array $advice): string
    {
        $twig = new TwigEnvironment(new TwigFilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        $twig->addFilter(new TwigFilter('markdown_lite', [\App\Helpers\MarkdownLite::class, 'toHtml'], ['is_safe' => ['html']]));
        $c = self::storedContext($advice);
        return $twig->render('CPA/payment_advice.html.twig', $c + ['assets' => DocumentDataAssembler::assetsBlock(), 'order' => [], 'watermark' => ['enabled' => false],
            'meta' => ['document_reference' => $c['number'], 'generated_date' => $c['paid_on']]]);
    }

    public static function pdf(array $advice): string
    {
        return DocumentGenerationService::renderStandalonePdf(self::html($advice), (string) $advice['advice_no'] . ((int) $advice['revision'] > 0 ? ' Rev ' . (int) $advice['revision'] : ''));
    }

    public static function fileName(array $advice, string $ext): string
    {
        return str_replace('/', '-', (string) $advice['advice_no']) . ((int) $advice['revision'] > 0 ? '-Rev' . (int) $advice['revision'] : '') . '.' . $ext;
    }

    /** The Word copy (same content, DocxComponents building blocks). */
    public static function docx(array $advice): string
    {
        $c = self::storedContext($advice);
        $kv = static fn (array $pairs): array => array_map(static fn (array $r): array => ['label' => $r[0], 'value' => $r[1]], $pairs);
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        Docx\DocxComponents::applyDefaultStyle($phpWord);
        $section = Docx\DocxComponents::addSection($phpWord);
        Docx\DocxComponents::addHeader($section, ['company' => $c['company'], 'assets' => DocumentDataAssembler::assetsBlock()], self::TITLE, null);
        Docx\DocxComponents::addMetaBar($section, $kv([['ADVICE NO.', $c['number'] . ($c['revision_label'] !== '' ? ' ' . $c['revision_label'] : '')], ['PAID ON', $c['paid_on']],
            ['BANK REFERENCE', $c['paid_reference'] !== '' ? $c['paid_reference'] : '—'], ['ASSOCIATE NO.', $c['associate_no']]]));
        Docx\DocxComponents::addPlainParagraph($section, $c['headline'], ['bold' => true, 'color' => '2D7D56']);
        if ($c['revision_label'] !== '') {
            Docx\DocxComponents::addPlainParagraph($section, $c['revision_label'] . ' — corrected: ' . (string) $c['revision_reason'] . '. It replaces the earlier advice with the same number.', ['italic' => true]);
        }
        Docx\DocxComponents::addSectionTitle($section, '1. PAID BY');
        Docx\DocxComponents::addKvTable($section, $kv([['Company / Legal Entity', $c['company']['legal_name']], ['Registered Office', $c['company']['registered_office']],
            ['GSTIN', $c['company']['gstin']], ['IEC / PAN', $c['company']['iec_pan']], ['Email / Phone', trim($c['company']['email'] . ' · ' . $c['company']['phone'], ' ·')]]));
        Docx\DocxComponents::addSectionTitle($section, '2. PAID TO');
        Docx\DocxComponents::addKvTable($section, $kv(array_values(array_filter([['Associate', $c['associate']['name'] . ' (' . $c['associate_no'] . ')'],
            $c['associate']['pan'] !== '' ? ['PAN', $c['associate']['pan']] : null, ['Bank Account', $c['bank']], ['Paid Via', $c['paid_via'] . ($c['paid_reference'] !== '' ? ' · bank ref ' . $c['paid_reference'] : '')]]))));
        Docx\DocxComponents::addSectionTitle($section, '3. COMMISSION PAID');
        Docx\DocxComponents::addProductsTable($section, ['#', 'Order', 'Client', 'Value of the goods (FOB)', 'Your %', 'Commission (before tax)', 'TDS', 'Paid to you'],
            [4, 14, 20, 15, 8, 14, 11, 14], array_map(static fn (array $l): array => ['cells' => [(string) $l['no'], $l['order'], $l['client'], $l['fob'], $l['pct'], $l['gross'], $l['tds'], $l['paid']],
                'lineColor' => $l['no'] % 2 === 1 ? '1D6FA8' : '2D7D56', 'align' => [null, null, null, 'right', 'right', 'right', 'right', 'right']], $c['lines']));
        $totals = [['Commission before tax', $c['total_gross']]];
        foreach ($c['deductions'] as $d) {
            $totals[] = [$d['text'], $d['amount']];
        }
        if ($c['total_tds'] !== '') {
            $totals[] = ['Less: tax deducted at source (TDS) — ' . $c['tds_text'], $c['total_tds']];
        }
        $totals = $kv($totals);
        $totals[] = ['label' => 'TOTAL PAID TO YOUR BANK', 'value' => $c['total_paid'], 'highlight' => true];
        Docx\DocxComponents::addTotalsTable($section, $totals);
        Docx\DocxComponents::addSectionTitle($section, '4. HOW THE COMMISSION IS WORKED OUT');
        Docx\DocxComponents::addPlainParagraph($section, self::HOW_TEXT);
        if ($c['total_tds'] !== '') {
            Docx\DocxComponents::addSectionTitle($section, '5. TAX (TDS)');
            Docx\DocxComponents::addPlainParagraph($section, sprintf(self::TDS_TEXT, $c['total_tds']));
        }
        Docx\DocxComponents::addPlainParagraph($section, self::FOOT_TEXT, ['italic' => true, 'size' => 8]);
        $path = tempnam(sys_get_temp_dir(), 'cpa') . '.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        Docx\DocxComponents::cleanupTempFiles();
        return $bytes;
    }

    public const HOW_TEXT = 'Each commission is your % of the value of the goods (FOB) of the order, fixed when the buyer paid the order in full, at the rate the bank used for the buyer\'s payment. It does not change later. NexaCrest pays all bank charges.';
    public const TDS_TEXT = 'The tax deducted (%s) is deposited against your PAN. The TDS certificate (Form 16A) for this quarter will be available in your associate portal under Commission.';
    public const FOOT_TEXT = 'This is a computer-generated advice and needs no signature. It shows only your commission — never costs, margins or supplier prices.';
}
