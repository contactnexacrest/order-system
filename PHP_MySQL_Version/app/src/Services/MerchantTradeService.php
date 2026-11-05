<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FinancialYear;
use App\Repositories\AuditLogRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\MerchantTradeRepository;
use App\Repositories\NotificationRepository;

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the Merchant Trade (MTT)
 * division — RBI "merchanting trade": buy from a supplier abroad, sell to a
 * buyer abroad, the goods go straight from one to the other and never enter
 * India. A deal runs through its own stages:
 *
 *   contracted → shipped → settled → closed
 *
 *  - contracted: both sides, the goods (buy and sell price per unit), the
 *                PO to the supplier; advances on either leg.
 *  - shipped:    the BL (and a switch BL where the bank allows it).
 *  - settled:    the sale invoice is issued, the buyer has paid in full and
 *                the supplier is paid in full.
 *  - closed:     the AD bank has every document on its checklist.
 *
 * Two foreign-currency legs, each in its own currency: buy (paid to the
 * supplier) and sell (received from the buyer). The margin is tracked in ₹
 * at the rates actually realised and in USD (at the USD leg's realised rate,
 * else the MTT setting mtt_usd_inr_rate).
 *
 * RBI checks, all MTT settings TO BE CONFIRMED WITH THE AD BANK / CA: the
 * whole deal within mtt_max_months_total (9) months of its first payment or
 * receipt; foreign-exchange outlay (paid out before the buyer's money covers
 * it) within mtt_max_outlay_months (4); no advance to the supplier above
 * mtt_advance_limit_usd (USD 5,00,000) unless backed by an LC or bank
 * guarantee (a payment that would cross it is refused); the AD bank's
 * document checklist (mtt_bank_documents). The daily alert job reminds the
 * team before a limit is reached and when it is passed.
 * Port: merchantTradeService.js.
 */
final class MerchantTradeService
{
    public const CODE = 'MTT';

    public const STAGES = [
        'contracted' => 'Contracted',
        'shipped' => 'Shipped',
        'settled' => 'Settled',
        'closed' => 'Closed',
    ];

    public const LEGS = [
        'buy' => 'Paid to the supplier (buy leg)',
        'sell' => 'Received from the buyer (sell leg)',
    ];

    public const BACKING = ['none' => 'None', 'lc' => 'Letter of credit', 'bg' => 'Bank guarantee'];

    /** Starting values of MTT's own settings, used when a setting is missing (to be confirmed with the AD bank / CA). */
    public const DEFAULTS = [
        'mtt_max_months_total' => '9',
        'mtt_max_outlay_months' => '4',
        'mtt_advance_limit_usd' => '500000',
        'mtt_usd_inr_rate' => '84.0000',
        'mtt_bank_documents' => "Sale contract or buyer's order|Purchase contract or supplier's proforma|Supplier's invoice|Bill of lading|Our sale invoice|Inward remittance advice (sell leg)|Outward remittance SWIFT copy (buy leg)",
        'mtt_ad_bank_approval' => '',
    ];

    public const INCOTERMS = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    /** Days before a limit when the "due soon" reminder goes out. */
    public const WARN_DAYS = 30;

    public static function divisionId(): ?int
    {
        foreach (DivisionService::all() as $d) {
            if ($d['code'] === self::CODE) {
                return (int) $d['id'];
            }
        }
        return null;
    }

    public static function isActive(): bool
    {
        $id = self::divisionId();
        $d = $id !== null ? DivisionService::find($id) : null;
        return $d !== null && $d['status'] === 'active';
    }

    public static function setting(string $key): string
    {
        $id = self::divisionId();
        $v = $id !== null ? DomesticOrderRepository::divisionSetting($id, $key) : null;
        return $v !== null && $v !== '' ? $v : (self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string,string> doc_key => label, from the MTT setting mtt_bank_documents */
    public static function bankDocuments(): array
    {
        $out = [];
        foreach (explode('|', self::setting('mtt_bank_documents')) as $label) {
            $label = trim($label);
            if ($label !== '') {
                $out[self::docKey($label)] = $label;
            }
        }
        return $out;
    }

    public static function docKey(string $label): string
    {
        return substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-'), 0, 80);
    }

    // ------------------------------------------------------------ maths and dates (pure)

    /** 'YYYY-MM-DD' plus whole months, the day kept or cut to the month's last day (31 Jan + 1 month = 28/29 Feb). */
    public static function addMonths(string $date, int $months): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($date, 0, 10)));
        $total = $y * 12 + ($m - 1) + $months;
        $ny = intdiv($total, 12);
        $nm = $total % 12 + 1;
        $last = (int) date('t', mktime(0, 0, 0, $nm, 1, $ny));
        return sprintf('%04d-%02d-%02d', $ny, $nm, min($d, $last));
    }

    /** Whole days from $from to $to (negative when $to is earlier). */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC')) / 86400);
    }

    /** "d M Y" for messages. */
    public static function dmy(string $date): string
    {
        return (new \DateTimeImmutable(substr($date, 0, 10)))->format('d M Y');
    }

    /**
     * The deal's money on both legs and its margin (pure).
     *
     * @param list<array<string,mixed>> $lines quantity, buy_price_fc, sell_price_fc
     * @param list<array<string,mixed>> $payments leg, amount_fc, amount_inr
     * @return array<string,mixed>
     */
    public static function money(array $deal, array $lines, array $payments, string $usdRateSetting): array
    {
        $sale = 0;
        $purchase = 0;
        foreach ($lines as $l) {
            $sale += ImportService::lineValueCents((string) $l['quantity'], (string) $l['sell_price_fc']);
            $purchase += ImportService::lineValueCents((string) $l['quantity'], (string) $l['buy_price_fc']);
        }
        $t = ['buy' => ['fc' => 0, 'inr' => 0], 'sell' => ['fc' => 0, 'inr' => 0]];
        foreach ($payments as $p) {
            $t[$p['leg']]['fc'] += GstCalculator::scaled((string) $p['amount_fc'], 2);
            $t[$p['leg']]['inr'] += GstCalculator::scaled((string) $p['amount_inr'], 2);
        }
        $rate = static fn (array $leg): ?int => $leg['fc'] > 0 ? GstCalculator::roundDiv($leg['inr'] * 10000, $leg['fc']) : null;
        $sellRate = $rate($t['sell']);
        $buyRate = $rate($t['buy']);
        $usdRate = match (true) {
            $deal['sale_currency'] === 'USD' && $sellRate !== null => $sellRate,
            $deal['purchase_currency'] === 'USD' && $buyRate !== null => $buyRate,
            default => GstCalculator::scaled($usdRateSetting, 4),
        };
        $margin = $t['sell']['inr'] - $t['buy']['inr'];
        return [
            'sale_cents' => $sale,
            'purchase_cents' => $purchase,
            'received_cents' => $t['sell']['fc'],
            'paid_cents' => $t['buy']['fc'],
            'received_inr' => GstCalculator::money($t['sell']['inr']),
            'paid_inr' => GstCalculator::money($t['buy']['inr']),
            'sell_rate' => $sellRate !== null ? ImportService::fmt4($sellRate) : null,
            'buy_rate' => $buyRate !== null ? ImportService::fmt4($buyRate) : null,
            'usd_rate' => ImportService::fmt4($usdRate),
            'margin_inr' => GstCalculator::money($margin),
            'margin_usd_cents' => $usdRate > 0 ? GstCalculator::roundDiv($margin * 10000, $usdRate) : 0,
            'margin_pct' => $t['sell']['inr'] > 0 ? GstCalculator::money(GstCalculator::roundDiv($margin * 10000, $t['sell']['inr'])) : null,
            'contract_margin_cents' => $deal['sale_currency'] === $deal['purchase_currency'] ? $sale - $purchase : null,
            'final' => $sale > 0 && $t['sell']['fc'] === $sale && $t['buy']['fc'] === $purchase,
        ];
    }

    /** A buy payment's value in USD cents: its own amount when the purchase is in USD, else its ₹ at the USD rate. */
    public static function usdCents(array $deal, array $payment, string $usdRateSetting): int
    {
        if ($deal['purchase_currency'] === 'USD') {
            return GstCalculator::scaled((string) $payment['amount_fc'], 2);
        }
        $rate = GstCalculator::scaled($usdRateSetting, 4);
        return $rate > 0 ? GstCalculator::roundDiv(GstCalculator::scaled((string) $payment['amount_inr'], 2) * 10000, $rate) : 0;
    }

    /** The supplier payments made before the buyer's first receipt (a receipt on the same day covers it). */
    public static function advances(array $payments): array
    {
        $firstReceipt = null;
        foreach ($payments as $p) {
            if ($p['leg'] === 'sell' && ($firstReceipt === null || (string) $p['moved_on'] < $firstReceipt)) {
                $firstReceipt = (string) $p['moved_on'];
            }
        }
        return array_values(array_filter($payments, static fn (array $p): bool => $p['leg'] === 'buy' && ($firstReceipt === null || (string) $p['moved_on'] < $firstReceipt)));
    }

    /**
     * The periods money paid out to the supplier was not yet covered by the
     * buyer's money (in ₹ at the realised rates). Receipts on a day count
     * before payments on that day.
     *
     * @return list<array{from:string, to:?string}> to = null while still open
     */
    public static function outlayPeriods(array $payments): array
    {
        usort($payments, static fn (array $a, array $b): int => [(string) $a['moved_on'], $a['leg'] === 'sell' ? 0 : 1] <=> [(string) $b['moved_on'], $b['leg'] === 'sell' ? 0 : 1]);
        $cum = 0;
        $periods = [];
        $open = null;
        foreach ($payments as $p) {
            $inr = GstCalculator::scaled((string) $p['amount_inr'], 2);
            $cum += $p['leg'] === 'buy' ? $inr : -$inr;
            if ($cum > 0 && $open === null) {
                $open = (string) $p['moved_on'];
            } elseif ($cum <= 0 && $open !== null) {
                $periods[] = ['from' => $open, 'to' => (string) $p['moved_on']];
                $open = null;
            }
        }
        if ($open !== null) {
            $periods[] = ['from' => $open, 'to' => null];
        }
        return $periods;
    }

    /**
     * The RBI checks of a deal (pure). Each: key, label, level (ok | info |
     * warn | breach) and text.
     *
     * @param array<string,string> $docs doc_key => label (the checklist)
     * @param array<string,string> $received doc_key => received_on
     * @param array<string,string> $s settings mtt_max_months_total, mtt_max_outlay_months, mtt_advance_limit_usd, mtt_usd_inr_rate
     * @return list<array{key:string, label:string, level:string, text:string}>
     */
    public static function rbiChecks(array $deal, array $payments, array $docs, array $received, array $s, string $today): array
    {
        $out = [];
        $months = (int) $s['mtt_max_months_total'];
        $outlayMonths = (int) $s['mtt_max_outlay_months'];
        $closed = $deal['stage'] === 'closed' && !empty($deal['closed_on']);

        // 1. The whole deal within N months of its first payment or receipt.
        $start = null;
        foreach ($payments as $p) {
            if ($start === null || (string) $p['moved_on'] < $start) {
                $start = (string) $p['moved_on'];
            }
        }
        $label = "Whole deal within {$months} months";
        if ($start === null) {
            $out[] = ['key' => 'total', 'label' => $label, 'level' => 'info', 'text' => "Starts with the first payment or receipt; the deal must then finish within {$months} months."];
        } else {
            $due = self::addMonths($start, $months);
            if ($closed) {
                $out[] = (string) $deal['closed_on'] <= $due
                    ? ['key' => 'total', 'label' => $label, 'level' => 'ok', 'text' => 'Finished on ' . self::dmy((string) $deal['closed_on']) . ', within the limit (' . self::dmy($due) . ').']
                    : ['key' => 'total', 'label' => $label, 'level' => 'breach', 'text' => 'Finished on ' . self::dmy((string) $deal['closed_on']) . ', after the limit (' . self::dmy($due) . '). Report it to the AD bank.'];
            } else {
                $left = self::daysBetween($today, $due);
                $out[] = match (true) {
                    $left < 0 => ['key' => 'total', 'label' => $label, 'level' => 'breach', 'text' => 'Overdue: the deal had to finish by ' . self::dmy($due) . ' (started ' . self::dmy($start) . '). Report it to the AD bank.'],
                    $left <= self::WARN_DAYS => ['key' => 'total', 'label' => $label, 'level' => 'warn', 'text' => 'Due soon: finish by ' . self::dmy($due) . " ({$left} days left)."],
                    default => ['key' => 'total', 'label' => $label, 'level' => 'ok', 'text' => 'Finish by ' . self::dmy($due) . " ({$left} days left)."],
                };
            }
        }

        // 2. Foreign-exchange outlay within N months.
        $label = "Outlay within {$outlayMonths} months";
        $periods = self::outlayPeriods($payments);
        if ($periods === []) {
            $out[] = ['key' => 'outlay', 'label' => $label, 'level' => $payments === [] ? 'info' : 'ok', 'text' => $payments === [] ? 'No money has moved yet.' : 'No outlay: the buyer\'s money came before the supplier was paid.'];
        } else {
            $check = null;
            foreach ($periods as $p) {
                $due = self::addMonths($p['from'], $outlayMonths);
                if ($p['to'] !== null) {
                    $check = $p['to'] > $due
                        ? ['level' => 'breach', 'text' => 'Outlay from ' . self::dmy($p['from']) . ' to ' . self::dmy($p['to']) . ' was longer than the limit (' . self::dmy($due) . '). Report it to the AD bank.']
                        : ($check ?? ['level' => 'ok', 'text' => 'Outlay from ' . self::dmy($p['from']) . ' covered on ' . self::dmy($p['to']) . ', within the limit.']);
                    if ($check['level'] === 'breach') {
                        break;
                    }
                    continue;
                }
                $left = self::daysBetween($today, $due);
                $check = match (true) {
                    $left < 0 => ['level' => 'breach', 'text' => 'Overdue: money paid to the supplier since ' . self::dmy($p['from']) . ' had to be covered by the buyer by ' . self::dmy($due) . '. Report it to the AD bank.'],
                    $left <= self::WARN_DAYS => ['level' => 'warn', 'text' => 'Due soon: the buyer\'s money must cover the outlay since ' . self::dmy($p['from']) . ' by ' . self::dmy($due) . " ({$left} days left)."],
                    default => ['level' => 'ok', 'text' => 'Outlay open since ' . self::dmy($p['from']) . ': cover it by ' . self::dmy($due) . " ({$left} days left)."],
                };
            }
            $out[] = ['key' => 'outlay', 'label' => $label] + $check;
        }

        // 3. No advance above the limit unless backed by an LC / BG.
        $limitCents = GstCalculator::scaled($s['mtt_advance_limit_usd'], 2);
        $label = 'Advance to the supplier within USD ' . ImportService::fc($limitCents) . ' unless backed by an LC or bank guarantee';
        $adv = 0;
        foreach (self::advances($payments) as $p) {
            $adv += self::usdCents($deal, $p, $s['mtt_usd_inr_rate']);
        }
        $backing = (string) ($deal['advance_backing'] ?? 'none');
        $out[] = match (true) {
            $adv === 0 => ['key' => 'advance', 'label' => $label, 'level' => 'ok', 'text' => 'No advance paid to the supplier before the buyer paid.'],
            $backing !== 'none' => ['key' => 'advance', 'label' => $label, 'level' => 'ok', 'text' => 'Advance USD ' . ImportService::fc($adv) . ' backed by ' . strtolower(self::BACKING[$backing]) . ' ' . (string) $deal['backing_ref'] . '.'],
            $adv <= $limitCents => ['key' => 'advance', 'label' => $label, 'level' => 'ok', 'text' => 'Advance USD ' . ImportService::fc($adv) . ', within the limit.'],
            default => ['key' => 'advance', 'label' => $label, 'level' => 'breach', 'text' => 'Advance USD ' . ImportService::fc($adv) . ' is above the limit and not backed by an LC or bank guarantee. Report it to the AD bank.'],
        };

        // 4. The AD bank's document checklist.
        $missing = array_values(array_diff_key($docs, $received));
        $label = 'Documents with the AD bank';
        $have = count($docs) - count($missing);
        $out[] = $missing === []
            ? ['key' => 'documents', 'label' => $label, 'level' => 'ok', 'text' => 'All ' . count($docs) . ' documents are with the bank.']
            : ['key' => 'documents', 'label' => $label, 'level' => in_array($deal['stage'], ['settled', 'closed'], true) ? 'warn' : 'info',
                'text' => "{$have} of " . count($docs) . ' with the bank. Missing: ' . implode('; ', $missing) . '.'];
        return $out;
    }

    /** @return array<string,string> the RBI settings */
    public static function rbiSettings(): array
    {
        $out = [];
        foreach (['mtt_max_months_total', 'mtt_max_outlay_months', 'mtt_advance_limit_usd', 'mtt_usd_inr_rate'] as $k) {
            $out[$k] = self::setting($k);
        }
        return $out;
    }

    /** The RBI checks of a stored deal, today. */
    public static function checksFor(array $deal, ?string $today = null): array
    {
        return self::rbiChecks($deal, MerchantTradeRepository::payments((int) $deal['id']), self::bankDocuments(),
            MerchantTradeRepository::documents((int) $deal['id']), self::rbiSettings(), $today ?? date('Y-m-d'));
    }

    /** The worst level of a deal's checks: breach > warn > ok/info. */
    public static function worstLevel(array $checks): string
    {
        $levels = array_column($checks, 'level');
        return in_array('breach', $levels, true) ? 'breach' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    /** The deal's money and margin from the database. */
    public static function figures(array $deal): array
    {
        return self::money($deal, MerchantTradeRepository::lines((int) $deal['id']), MerchantTradeRepository::payments((int) $deal['id']), self::setting('mtt_usd_inr_rate'));
    }

    // ------------------------------------------------------------ helpers

    private static function isDate(string $s): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 && strtotime($s) !== false;
    }

    private static function nextReference(string $series, string $label): string
    {
        $fy = FinancialYear::current();
        $short = substr($fy, 2, 2) . '-' . substr($fy, 5, 2);
        return 'MTT/' . ($label !== '' ? $label . '/' : '') . $short . '/' . str_pad((string) DomesticOrderRepository::nextNumber($series, $fy), 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------ create and edit

    /** @return array{0: array<string,mixed>, 1: ?string} */
    public static function headerFields(array $in): array
    {
        $t = static fn (string $k): string => trim((string) ($in[$k] ?? ''));
        $f = [
            'deal_date' => $t('deal_date'),
            'supplier_name' => $t('supplier_name'), 'supplier_country' => $t('supplier_country'), 'supplier_address' => $t('supplier_address'),
            'purchase_currency' => strtoupper($t('purchase_currency')), 'purchase_incoterm' => strtoupper($t('purchase_incoterm')),
            'buyer_name' => $t('buyer_name'), 'buyer_country' => $t('buyer_country'), 'buyer_address' => $t('buyer_address'),
            'sale_currency' => strtoupper($t('sale_currency')), 'sale_incoterm' => strtoupper($t('sale_incoterm')),
            'origin_country' => $t('origin_country'), 'port_of_loading' => $t('port_of_loading'), 'port_of_discharge' => $t('port_of_discharge'),
        ];
        $india = static fn (string $c): bool => strtoupper($c) === 'INDIA';
        $error = match (true) {
            !self::isDate($f['deal_date']) || $f['deal_date'] > date('Y-m-d') => 'Enter the deal date (not in the future).',
            $f['supplier_name'] === '' || mb_strlen($f['supplier_name']) > 255 => 'Enter the supplier abroad.',
            $f['supplier_country'] === '' || mb_strlen($f['supplier_country']) > 80 => 'Enter the supplier\'s country.',
            $f['buyer_name'] === '' || mb_strlen($f['buyer_name']) > 255 => 'Enter the buyer abroad.',
            $f['buyer_country'] === '' || mb_strlen($f['buyer_country']) > 80 => 'Enter the buyer\'s country.',
            $india($f['supplier_country']) || $india($f['buyer_country']) => 'In a merchant trade both the supplier and the buyer are abroad. Buying from or selling to India is an export, import or domestic sale.',
            $f['origin_country'] === '' || mb_strlen($f['origin_country']) > 80 => 'Enter the country the goods come from.',
            $india($f['origin_country']) => 'Goods made in India and shipped abroad are an export, not a merchant trade.',
            mb_strlen($f['supplier_address']) > 1000 || mb_strlen($f['buyer_address']) > 1000 => 'An address is too long.',
            !in_array($f['purchase_currency'], ImportService::CURRENCIES, true) => 'Choose the currency of the purchase (the supplier\'s invoice).',
            !in_array($f['sale_currency'], ImportService::CURRENCIES, true) => 'Choose the currency of the sale (our invoice to the buyer).',
            ($f['purchase_incoterm'] !== '' && !in_array($f['purchase_incoterm'], self::INCOTERMS, true)) || ($f['sale_incoterm'] !== '' && !in_array($f['sale_incoterm'], self::INCOTERMS, true)) => 'Choose a valid Incoterm.',
            mb_strlen($f['port_of_loading']) > 100 || mb_strlen($f['port_of_discharge']) > 100 => 'A port name is too long.',
            default => null,
        };
        foreach (['supplier_address', 'buyer_address', 'purchase_incoterm', 'sale_incoterm', 'port_of_loading', 'port_of_discharge'] as $k) {
            $f[$k] = $f[$k] !== '' ? $f[$k] : null;
        }
        return [$f, $error];
    }

    /** Creates a deal (its reference and PO number are taken now). @return array{0:?int, 1:?string} */
    public static function create(array $in, int $userId): array
    {
        $divisionId = self::divisionId();
        if ($divisionId === null || !self::isActive()) {
            return [null, 'Merchant Trade is not active yet. The MD activates it in Admin › Divisions.'];
        }
        [$fields, $error] = self::headerFields($in);
        if ($error !== null) {
            return [null, $error];
        }
        $fields['division_id'] = $divisionId;
        $fields['reference'] = self::nextReference('MTT-DEAL', '');
        $fields['po_no'] = self::nextReference('MTT-PO', 'PO');
        $id = MerchantTradeRepository::insert($fields, $userId);
        AuditLogRepository::log($userId, 'MERCHANT_DEAL_CREATED', 'merchant_deals', $id, 'reference', null, $fields['reference']);
        return [$id, null];
    }

    public static function saveHeader(int $id, array $in, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        if ($d === null) {
            return 'Deal not found.';
        }
        if ($d['stage'] !== 'contracted') {
            return 'The deal can change only before the goods are shipped.';
        }
        if (MerchantTradeRepository::payments($id) !== [] && (strtoupper(trim((string) ($in['purchase_currency'] ?? ''))) !== $d['purchase_currency'] || strtoupper(trim((string) ($in['sale_currency'] ?? ''))) !== $d['sale_currency'])) {
            return 'Money has already moved on this deal, so its currencies cannot change.';
        }
        [$fields, $error] = self::headerFields($in);
        if ($error !== null) {
            return $error;
        }
        MerchantTradeRepository::update($id, $fields, $userId);
        AuditLogRepository::log($userId, 'MERCHANT_DEAL_SAVED', 'merchant_deals', $id, 'buyer_name', $d['buyer_name'], $fields['buyer_name']);
        return null;
    }

    public static function addLine(int $id, array $in, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        if ($d === null) {
            return 'Deal not found.';
        }
        if ($d['stage'] !== 'contracted') {
            return 'The goods can change only before they are shipped.';
        }
        $description = trim((string) ($in['description'] ?? ''));
        $hsn = preg_replace('/\D/', '', (string) ($in['hsn_code'] ?? '')) ?? '';
        $qty = trim((string) ($in['quantity'] ?? ''));
        $unit = trim((string) ($in['unit'] ?? ''));
        $buy = trim((string) ($in['buy_price_fc'] ?? ''));
        $sell = trim((string) ($in['sell_price_fc'] ?? ''));
        $price = static fn (string $v): bool => preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v) === 1 && GstCalculator::scaled($v, 2) > 0;
        $error = match (true) {
            $description === '' || mb_strlen($description) > 500 => 'Enter the description of the goods (up to 500 characters).',
            strlen($hsn) < 4 || strlen($hsn) > 8 => 'Enter the HS code (4 to 8 digits).',
            preg_match('/^\d{1,7}(\.\d{1,3})?$/', $qty) !== 1 || GstCalculator::scaled($qty, 3) <= 0 => 'Enter a quantity above zero (up to 3 decimals).',
            $unit === '' || mb_strlen($unit) > 20 => 'Enter the unit (e.g. MT, SQM, PCS).',
            !$price($buy) => 'Enter the buy price per unit in ' . $d['purchase_currency'] . ', above zero (up to 2 decimals).',
            !$price($sell) => 'Enter the sell price per unit in ' . $d['sale_currency'] . ', above zero (up to 2 decimals).',
            ImportService::lineValueCents($qty, $buy) > 99999999999 || ImportService::lineValueCents($qty, $sell) > 99999999999 => 'The line value is too large; split it into several lines.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        MerchantTradeRepository::addLine($id, ['description' => $description, 'hsn_code' => $hsn, 'quantity' => $qty, 'unit' => mb_strtoupper($unit), 'buy_price_fc' => $buy, 'sell_price_fc' => $sell], $userId);
        AuditLogRepository::log($userId, 'MERCHANT_LINE_ADDED', 'merchant_deals', $id, 'line', null, $description . ' × ' . $qty);
        return null;
    }

    public static function removeLine(int $id, int $lineId, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        if ($d === null || $d['stage'] !== 'contracted') {
            return 'The goods can change only before they are shipped.';
        }
        if (!MerchantTradeRepository::removeLine($id, $lineId)) {
            return 'Line not found.';
        }
        AuditLogRepository::log($userId, 'MERCHANT_LINE_REMOVED', 'merchant_deals', $id, 'line', (string) $lineId, null);
        return null;
    }

    /** The LC or bank guarantee that backs an advance to the supplier (RBI). */
    public static function saveBacking(int $id, array $in, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        $backing = (string) ($in['advance_backing'] ?? '');
        $ref = trim((string) ($in['backing_ref'] ?? ''));
        $error = match (true) {
            $d === null => 'Deal not found.',
            $d['stage'] === 'closed' => 'The deal is closed.',
            !isset(self::BACKING[$backing]) => 'Choose what backs the advance (none, LC or bank guarantee).',
            $backing !== 'none' && ($ref === '' || mb_strlen($ref) > 60) => 'Enter the LC or bank guarantee number.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        MerchantTradeRepository::update($id, ['advance_backing' => $backing, 'backing_ref' => $backing !== 'none' ? $ref : null], $userId);
        AuditLogRepository::log($userId, 'MERCHANT_BACKING_SAVED', 'merchant_deals', $id, 'advance_backing', (string) $d['advance_backing'], $backing);
        return null;
    }

    /** The bill of lading, and a switch BL where the bank allows it. */
    public static function saveShipment(int $id, array $in, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        $bl = trim((string) ($in['bl_no'] ?? ''));
        $blDate = trim((string) ($in['bl_date'] ?? ''));
        $vessel = trim((string) ($in['vessel'] ?? ''));
        $switch = ($in['switch_bl'] ?? '') === '1';
        $switchNo = trim((string) ($in['switch_bl_no'] ?? ''));
        $switchRef = trim((string) ($in['switch_bl_ref'] ?? ''));
        $error = match (true) {
            $d === null => 'Deal not found.',
            !in_array($d['stage'], ['contracted', 'shipped'], true) => 'The BL is recorded before the deal is settled.',
            $bl === '' || mb_strlen($bl) > 40 => 'Enter the bill of lading (BL) number.',
            !self::isDate($blDate) || $blDate < (string) $d['deal_date'] || $blDate > date('Y-m-d') => 'Enter the BL date (on or after the deal date, not in the future).',
            mb_strlen($vessel) > 100 => 'The vessel is too long.',
            $switch && ($switchNo === '' || mb_strlen($switchNo) > 40) => 'Enter the number of the switch BL.',
            $switch && ($switchRef === '' || mb_strlen($switchRef) > 255) => 'Enter who issued the switch BL and the bank\'s consent (e.g. carrier agent, bank letter reference).',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        MerchantTradeRepository::update($id, [
            'bl_no' => $bl, 'bl_date' => $blDate, 'vessel' => $vessel !== '' ? $vessel : null,
            'switch_bl' => $switch ? 1 : 0, 'switch_bl_no' => $switch ? $switchNo : null, 'switch_bl_ref' => $switch ? $switchRef : null,
        ], $userId);
        AuditLogRepository::log($userId, 'MERCHANT_BL_SAVED', 'merchant_deals', $id, 'bl_no', $d['bl_no'], $bl . ($switch ? ' / switch ' . $switchNo : ''));
        return null;
    }

    /** Ticks (or unticks, $date '') one document of the AD bank's checklist. */
    public static function saveDocument(int $id, string $docKey, string $date, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        $docs = self::bankDocuments();
        $error = match (true) {
            $d === null => 'Deal not found.',
            $d['stage'] === 'closed' => 'The deal is closed.',
            !isset($docs[$docKey]) => 'Choose a document from the bank\'s checklist.',
            $date !== '' && (!self::isDate($date) || $date > date('Y-m-d')) => 'Enter the date the bank received it (not in the future).',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        MerchantTradeRepository::setDocument($id, $docKey, $date !== '' ? $date : null, $userId);
        AuditLogRepository::log($userId, 'MERCHANT_BANK_DOCUMENT', 'merchant_deals', $id, $docKey, null, $date !== '' ? $date : 'removed');
        return null;
    }

    /** Rule D-212: what the money is, to or from whom, for which deal, at what rate, and the bank reference. */
    public static function paymentDescription(array $d, string $leg, bool $isAdvance, string $amountFc, string $fxRate, string $amountInr, string $bankReference, ?string $note): string
    {
        $cur = $leg === 'buy' ? $d['purchase_currency'] : $d['sale_currency'];
        $money = "{$cur} " . ImportService::fc(GstCalculator::scaled($amountFc, 2)) . ' (₹' . GstCalculator::inr($amountInr) . " at {$fxRate})";
        if ($leg === 'buy') {
            $text = ($isAdvance ? 'Merchant trade supplier advance' : 'Merchant trade supplier payment') . " of {$money} to {$d['supplier_name']} ({$d['supplier_country']}) against merchant trade deal {$d['reference']}, PO {$d['po_no']}"
                . ($isAdvance && $d['advance_backing'] !== 'none' ? ', backed by ' . strtolower(self::BACKING[$d['advance_backing']]) . ' ' . $d['backing_ref'] : '');
        } else {
            $text = ($isAdvance ? 'Merchant trade buyer advance received' : 'Merchant trade sale receipt') . " of {$money} from {$d['buyer_name']} ({$d['buyer_country']}) against merchant trade deal {$d['reference']}"
                . (!empty($d['sale_invoice_no']) ? ", invoice {$d['sale_invoice_no']}" : '');
        }
        $text .= ", SWIFT/UTR ref {$bankReference}";
        $note = trim((string) $note);
        return $note !== '' ? $text . '; note: ' . $note : $text;
    }

    /**
     * Records money on one leg. The deal row is locked first, so two
     * payments at once are checked one after the other (never more than is
     * owed, never an unbacked advance above the limit).
     */
    public static function recordPayment(int $id, array $in, int $userId): ?string
    {
        $leg = (string) ($in['leg'] ?? '');
        $date = trim((string) ($in['moved_on'] ?? ''));
        $amount = trim((string) ($in['amount_fc'] ?? ''));
        $fx = trim((string) ($in['fx_rate'] ?? ''));
        $ref = trim((string) ($in['bank_reference'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $d = MerchantTradeRepository::lock($id);
            $error = $d === null ? 'Deal not found.' : self::paymentError($d, $leg, $date, $amount, $fx, $ref, $note);
            if ($error !== null) {
                $pdo->rollBack();
                return $error;
            }
            $payments = MerchantTradeRepository::payments($id);
            $inr = GstCalculator::money(GstCalculator::roundDiv(GstCalculator::scaled($amount, 2) * GstCalculator::scaled($fx, 4), 10000));
            $new = ['leg' => $leg, 'moved_on' => $date, 'amount_fc' => $amount, 'amount_inr' => $inr];
            // A supplier payment before any money from the buyer (a receipt on the same day covers it) is an advance;
            // a buyer's payment before our sale invoice is an advance received.
            $covered = false;
            foreach ($payments as $p) {
                if ($p['leg'] === 'sell' && (string) $p['moved_on'] <= $date) {
                    $covered = true;
                }
            }
            $isAdvance = $leg === 'buy' ? !$covered : empty($d['sale_invoice_no']);
            if ($leg === 'buy' && $isAdvance && $d['advance_backing'] === 'none') {
                $usd = 0;
                foreach (self::advances(array_merge($payments, [$new])) as $p) {
                    $usd += self::usdCents($d, $p, self::setting('mtt_usd_inr_rate'));
                }
                $limit = GstCalculator::scaled(self::setting('mtt_advance_limit_usd'), 2);
                if ($usd > $limit) {
                    $pdo->rollBack();
                    return 'This would make the advance to the supplier USD ' . ImportService::fc($usd) . ', above the USD ' . ImportService::fc($limit)
                        . ' limit for an advance not backed by an LC or bank guarantee (RBI merchanting rule, to be confirmed with the AD bank). Record the LC or bank guarantee first.';
                }
            }
            $description = self::paymentDescription($d, $leg, $isAdvance, $amount, $fx, $inr, $ref, $note);
            MerchantTradeRepository::addPayment($id, [
                'leg' => $leg, 'moved_on' => $date, 'amount_fc' => $amount, 'fx_rate' => $fx, 'amount_inr' => $inr,
                'bank_reference' => $ref, 'description' => $description, 'note' => $note !== '' ? $note : null,
            ], $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        AuditLogRepository::log($userId, 'MERCHANT_PAYMENT_RECORDED', 'merchant_deals', $id, $leg, null, $amount);
        return null;
    }

    private static function paymentError(array $d, string $leg, string $date, string $amount, string $fx, string $ref, string $note): ?string
    {
        if ($d['stage'] === 'closed') {
            return 'The deal is closed.';
        }
        if (!isset(self::LEGS[$leg])) {
            return 'Choose the leg: paid to the supplier or received from the buyer.';
        }
        $cur = $leg === 'buy' ? $d['purchase_currency'] : $d['sale_currency'];
        $f = self::figures($d);
        $owed = $leg === 'buy' ? $f['purchase_cents'] - $f['paid_cents'] : $f['sale_cents'] - $f['received_cents'];
        return match (true) {
            !self::isDate($date) || $date > date('Y-m-d') || $date < (string) $d['deal_date'] => 'Enter the date the money moved through the bank (on or after the deal date, not in the future).',
            preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) !== 1 || GstCalculator::scaled($amount, 2) <= 0 => "Enter the amount in {$cur}.",
            preg_match('/^\d{1,3}(\.\d{1,4})?$/', $fx) !== 1 || GstCalculator::scaled($fx, 4) <= 0 => "Enter the bank's exchange rate (₹ per {$cur}, up to 4 decimals).",
            $ref === '' || mb_strlen($ref) > 60 => 'Enter the SWIFT / UTR reference.',
            MerchantTradeRepository::bankReferenceUsed($ref) => 'This bank reference is already recorded. Check the payment is not entered twice.',
            GstCalculator::scaled($amount, 2) > $owed => ($leg === 'buy' ? 'This is more than the supplier is still owed' : 'This is more than the buyer still owes') . " ({$cur} " . ImportService::fc(max(0, $owed)) . ').',
            mb_strlen($note) > 255 => 'The note is too long.',
            default => null,
        };
    }

    public static function markPoGenerated(int $id, int $userId): void
    {
        MerchantTradeRepository::update($id, ['po_generated_at' => date('Y-m-d H:i:s')], $userId);
    }

    /** Fixes the sale invoice number the first time the invoice is generated (it needs the BL). Returns why not, or null. */
    public static function fixInvoice(int $id, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        if ($d === null) {
            return 'Deal not found.';
        }
        if (MerchantTradeRepository::lines($id) === []) {
            return 'Add the goods first.';
        }
        if (empty($d['bl_no'])) {
            return 'Record the BL first: the sale invoice goes with the shipping documents.';
        }
        $fields = ['invoice_generated_at' => date('Y-m-d H:i:s')];
        if (empty($d['sale_invoice_no'])) {
            $fields['sale_invoice_no'] = self::nextReference('MTT-INV', 'I'); // MTT/I/26-27/0001: 16 characters, the most a GST-registered Books org accepts (ZOH-9)
            $fields['invoice_date'] = date('Y-m-d');
        }
        MerchantTradeRepository::update($id, $fields, $userId);
        if (isset($fields['sale_invoice_no'])) {
            AuditLogRepository::log($userId, 'MERCHANT_INVOICE_FIXED', 'merchant_deals', $id, 'sale_invoice_no', null, $fields['sale_invoice_no']);
        }
        return null;
    }

    // ------------------------------------------------------------ stages

    /** @return list<string> what still stops the deal moving on (empty = it may move on) */
    public static function blockers(int $id): array
    {
        $d = MerchantTradeRepository::find($id);
        if ($d === null) {
            return ['Deal not found.'];
        }
        $out = [];
        switch ($d['stage']) {
            case 'contracted':
                if (MerchantTradeRepository::lines($id) === []) {
                    $out[] = 'Add the goods.';
                }
                if (empty($d['po_generated_at'])) {
                    $out[] = 'Generate the purchase order to the supplier.';
                }
                if (empty($d['bl_no'])) {
                    $out[] = 'Record the bill of lading (BL).';
                }
                break;
            case 'shipped':
                $f = self::figures($d);
                if (empty($d['sale_invoice_no'])) {
                    $out[] = 'Generate the sale invoice to the buyer.';
                }
                if ($f['received_cents'] < $f['sale_cents']) {
                    $out[] = 'Still to receive from the buyer: ' . $d['sale_currency'] . ' ' . ImportService::fc($f['sale_cents'] - $f['received_cents']) . '.';
                }
                if ($f['paid_cents'] < $f['purchase_cents']) {
                    $out[] = 'Still to pay the supplier: ' . $d['purchase_currency'] . ' ' . ImportService::fc($f['purchase_cents'] - $f['paid_cents']) . '.';
                }
                break;
            case 'settled':
                $missing = array_diff_key(self::bankDocuments(), MerchantTradeRepository::documents($id));
                if ($missing !== []) {
                    $out[] = 'Documents still to give the AD bank: ' . implode('; ', $missing) . '.';
                }
                break;
            case 'closed':
                $out[] = 'The deal is closed.';
                break;
        }
        return $out;
    }

    /** Moves the deal to its next stage when nothing stops it. Returns null or why not. */
    public static function advance(int $id, string $expectedStage, int $userId): ?string
    {
        $d = MerchantTradeRepository::find($id);
        if ($d === null) {
            return 'Deal not found.';
        }
        if ($d['stage'] !== $expectedStage) {
            return 'Someone has already moved this deal on. The page shows where it is now.';
        }
        $blockers = self::blockers($id);
        if ($blockers !== []) {
            return implode(' ', $blockers);
        }
        $keys = array_keys(self::STAGES);
        $next = $keys[(int) array_search($d['stage'], $keys, true) + 1];
        if (!MerchantTradeRepository::moveStage($id, (string) $d['stage'], $next, $userId)) {
            return 'Someone has already moved this deal on. The page shows where it is now.';
        }
        if ($next === 'closed') {
            MerchantTradeRepository::update($id, ['closed_on' => date('Y-m-d')], $userId);
        }
        AuditLogRepository::log($userId, 'MERCHANT_STAGE_MOVED', 'merchant_deals', $id, 'stage', (string) $d['stage'], $next);
        return null;
    }

    // ------------------------------------------------------------ reminders (daily alert job)

    /**
     * Reminds the team (manage_purchasing, in MTT or company level) of every
     * RBI check that is due soon or passed, once per deal, check and level.
     * Run from cron/check_alerts.php. @return int reminders created
     */
    public static function runAlerts(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $created = 0;
        foreach (MerchantTradeRepository::openDeals() as $d) {
            $recipients = null;
            foreach (self::checksFor($d, $today) as $c) {
                if (!in_array($c['level'], ['warn', 'breach'], true) || !MerchantTradeRepository::markAlertSent((int) $d['id'], $c['key'], $c['level'])) {
                    continue;
                }
                $recipients ??= MerchantTradeRepository::alertRecipients((int) $d['division_id']);
                $message = mb_substr("Merchant trade {$d['reference']} ({$d['buyer_name']}): {$c['label']} — {$c['text']}", 0, 500);
                foreach ($recipients as $userId) {
                    NotificationRepository::create($userId, null, 'merchant_trade_rbi', null, $message);
                    $created++;
                }
            }
        }
        return $created;
    }

    // ------------------------------------------------------------ reports

    /** The divisions the current person's reports cover (MTT only, when the person may see it). */
    public static function reportDivisionIds(): array
    {
        $mtt = self::divisionId();
        return $mtt !== null && DivisionService::allows($mtt) ? [$mtt] : [];
    }

    /** @return list<array<string,mixed>> every deal with its money, margin and RBI status */
    public static function reportRows(): array
    {
        $rows = [];
        foreach (MerchantTradeRepository::list(self::reportDivisionIds()) as $d) {
            $checks = self::checksFor($d);
            $rows[] = $d + ['figures' => self::figures($d), 'checks' => $checks, 'worst' => self::worstLevel($checks)];
        }
        return $rows;
    }
}
