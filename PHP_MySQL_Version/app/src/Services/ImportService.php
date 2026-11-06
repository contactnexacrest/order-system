<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FinancialYear;
use App\Repositories\AuditLogRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\ImportRepository;

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the Import & Distribution (IMP)
 * division — import goods into India, hold them in stock, sell them locally.
 *
 * An import (a "shipment") runs through its own stages:
 *
 *   ordered → shipped → cleared → received → closed
 *
 *  - ordered:  the import PO to the foreign supplier, in its currency; the
 *              LC where NexaCrest is the applicant; payments abroad.
 *  - shipped:  the inbound BL is recorded.
 *  - cleared:  the bill of entry, the customs exchange rate, the CHA and the
 *              charges in INR. Moving on to "cleared" works out the duty and
 *              the landed cost per item and freezes them.
 *  - received: moving on puts the goods into stock at their landed cost.
 *  - closed:   the supplier is paid in full and the IGST input credit taken.
 *
 * Landed cost per item = purchase × exchange rate + freight + insurance (both
 * spread by value; together they make the assessable value) + basic customs
 * duty + social welfare surcharge + CHA, port and other charges (spread by
 * value). IGST paid on import is input credit, so it is not a cost. Duty
 * rates per HSN are editable starting values, to be confirmed by the CA and
 * the customs agent.
 *
 * Stock is valued at weighted average cost and never goes below zero: an
 * issue locks the balance row first. The local sale is a Domestic Trade
 * order in the IMP division (GST tax invoice, challan, e-way bill, payments
 * under IMP's own numbers); its goods are issued from stock when its tax
 * invoice is fixed. Port: importService.js.
 */
final class ImportService
{
    public const CODE = 'IMP';

    public const STAGES = [
        'ordered' => 'Ordered',
        'shipped' => 'Shipped',
        'cleared' => 'Cleared',
        'received' => 'In stock',
        'closed' => 'Closed',
    ];

    public const PAYMENT_KINDS = [
        'advance' => 'Supplier advance',
        'balance' => 'Supplier balance',
        'lc' => 'LC retirement',
    ];

    /** Starting values of IMP's own settings, used when a setting is missing (to be confirmed by the CA / customs agent). */
    public const DEFAULTS = [
        'imp_default_bcd_pct' => '10',
        'imp_default_sws_pct' => '10',
        'imp_default_igst_pct' => '18',
        'imp_margin_pct' => '20',
        'imp_lc_bank' => '',
        'imp_default_cha' => '',
    ];

    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CNY', 'JPY', 'AED', 'SGD', 'CHF', 'AUD', 'CAD'];

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

    // ------------------------------------------------------------ maths (pure)

    /** A float rounded half away from zero to a whole number (the same in PHP and Node). */
    public static function roundHalfAway(float $x): int
    {
        return (int) ($x >= 0 ? floor($x + 0.5) : -floor(-$x + 0.5));
    }

    /**
     * Spreads a whole number of paise over weights, in proportion; the last
     * share takes what rounding leaves, so the shares always add up exactly.
     *
     * @param list<int> $weights
     * @return list<int>
     */
    public static function spread(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $out = [];
        $given = 0;
        $n = count($weights);
        foreach ($weights as $i => $w) {
            if ($i === $n - 1) {
                $out[] = $total - $given;
                break;
            }
            $share = $sum > 0 ? self::roundHalfAway((float) $total * (float) $w / (float) $sum) : 0;
            $out[] = $share;
            $given += $share;
        }
        return $out;
    }

    /** Scale-4 integer => "12.3456". */
    public static function fmt4(int $v): string
    {
        $sign = $v < 0 ? '-' : '';
        $v = abs($v);
        return $sign . intdiv($v, 10000) . '.' . str_pad((string) ($v % 10000), 4, '0', STR_PAD_LEFT);
    }

    /** Cents => "12,345.67" (western grouping, for foreign currency). */
    public static function fc(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . number_format(intdiv($cents, 100)) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A line's value in the supplier's currency, in cents (quantity × unit price, rounded to the cent). */
    public static function lineValueCents(string $quantity, string $unitPrice): int
    {
        return GstCalculator::roundDiv(GstCalculator::scaled($quantity, 3) * GstCalculator::scaled($unitPrice, 2), 1000);
    }

    /**
     * Duty and landed cost of an import (pure: no database).
     *
     * @param list<array<string,mixed>> $lines each: quantity, unit_price_fc, bcd_pct, sws_pct, igst_pct (+ anything else, kept)
     * @param array<string,string> $costs freight_inr, insurance_inr, cha_inr, port_inr, other_inr
     * @return array<string,mixed>
     */
    public static function compute(array $lines, string $fxRate, array $costs): array
    {
        $fx = GstCalculator::scaled($fxRate, 4);
        $values = [];
        $cents = [];
        foreach ($lines as $l) {
            $c = self::lineValueCents((string) $l['quantity'], (string) $l['unit_price_fc']);
            $cents[] = $c;
            $values[] = GstCalculator::roundDiv($c * $fx, 10000);
        }
        $p = static fn (string $k): int => GstCalculator::scaled((string) ($costs[$k] ?? '0'), 2);
        $freight = self::spread($p('freight_inr'), $values);
        $insurance = self::spread($p('insurance_inr'), $values);
        $charges = self::spread($p('cha_inr') + $p('port_inr') + $p('other_inr'), $values);
        $out = [];
        $t = ['value_fc' => 0, 'value' => 0, 'assessable' => 0, 'bcd' => 0, 'sws' => 0, 'igst' => 0, 'charges' => 0, 'landed' => 0];
        foreach ($lines as $i => $l) {
            $assessable = $values[$i] + $freight[$i] + $insurance[$i];
            $bcd = GstCalculator::roundDiv($assessable * GstCalculator::scaled((string) $l['bcd_pct'], 2), 10000);
            $sws = GstCalculator::roundDiv($bcd * GstCalculator::scaled((string) $l['sws_pct'], 2), 10000);
            $igst = GstCalculator::roundDiv(($assessable + $bcd + $sws) * GstCalculator::scaled((string) $l['igst_pct'], 2), 10000);
            $landed = $assessable + $bcd + $sws + $charges[$i];
            $qty = GstCalculator::scaled((string) $l['quantity'], 3);
            $out[] = array_merge($l, [
                'value_fc' => GstCalculator::money($cents[$i]),
                'value_inr' => GstCalculator::money($values[$i]),
                'freight_share' => GstCalculator::money($freight[$i]),
                'insurance_share' => GstCalculator::money($insurance[$i]),
                'assessable_inr' => GstCalculator::money($assessable),
                'bcd_inr' => GstCalculator::money($bcd),
                'sws_inr' => GstCalculator::money($sws),
                'igst_inr' => GstCalculator::money($igst),
                'charges_share' => GstCalculator::money($charges[$i]),
                'landed_inr' => GstCalculator::money($landed),
                'landed_unit_inr' => self::fmt4($qty > 0 ? GstCalculator::roundDiv($landed * 100000, $qty) : 0),
            ]);
            $t['value_fc'] += $cents[$i];
            $t['value'] += $values[$i];
            $t['assessable'] += $assessable;
            $t['bcd'] += $bcd;
            $t['sws'] += $sws;
            $t['igst'] += $igst;
            $t['charges'] += $charges[$i];
            $t['landed'] += $landed;
        }
        return [
            'lines' => $out,
            'value_total_fc' => GstCalculator::money($t['value_fc']),
            'value_total_inr' => GstCalculator::money($t['value']),
            'freight_inr' => GstCalculator::money($p('freight_inr')),
            'insurance_inr' => GstCalculator::money($p('insurance_inr')),
            'assessable_total' => GstCalculator::money($t['assessable']),
            'bcd_total' => GstCalculator::money($t['bcd']),
            'sws_total' => GstCalculator::money($t['sws']),
            'duty_total' => GstCalculator::money($t['bcd'] + $t['sws']),
            'igst_total' => GstCalculator::money($t['igst']),
            'charges_total' => GstCalculator::money($t['charges']),
            'landed_total' => GstCalculator::money($t['landed']),
        ];
    }

    /** The suggested selling price per unit (before GST): landed cost per unit ÷ (1 − margin). */
    public static function suggestedPrice(string $landedUnit, string $marginPct): string
    {
        $denominator = 10000 - GstCalculator::scaled($marginPct, 2);
        if ($denominator <= 0) {
            return '0.00';
        }
        return GstCalculator::money(GstCalculator::roundDiv(GstCalculator::scaled($landedUnit, 4) * 10000, $denominator * 100));
    }

    /** The duty rates for an HSN: the longest matching HSN start, else IMP's defaults. @return array{bcd:string, sws:string, igst:string} */
    public static function dutyFor(string $hsn): array
    {
        $best = null;
        $bestLen = -1;
        foreach (ImportRepository::dutyRates(true) as $r) {
            $prefix = (string) $r['hsn_prefix'];
            if ($prefix !== '' && str_starts_with($hsn, $prefix) && strlen($prefix) > $bestLen) {
                $best = $r;
                $bestLen = strlen($prefix);
            }
        }
        $pct = static fn (string $v): string => GstCalculator::money(GstCalculator::scaled($v, 2));
        return $best !== null
            ? ['bcd' => $pct((string) $best['bcd_pct']), 'sws' => $pct((string) $best['sws_pct']), 'igst' => $pct((string) $best['igst_pct'])]
            : ['bcd' => $pct(self::setting('imp_default_bcd_pct')), 'sws' => $pct(self::setting('imp_default_sws_pct')), 'igst' => $pct(self::setting('imp_default_igst_pct'))];
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
        return 'IMP/' . $label . '/' . $short . '/' . str_pad((string) DomesticOrderRepository::nextNumber($series, $fy), 4, '0', STR_PAD_LEFT);
    }

    /** The PO value in the supplier's currency, in cents. */
    public static function poValueCents(int $shipmentId): int
    {
        $c = 0;
        foreach (ImportRepository::lines($shipmentId) as $l) {
            $c += self::lineValueCents((string) $l['quantity'], (string) $l['unit_price_fc']);
        }
        return $c;
    }

    public static function paidCents(int $shipmentId): int
    {
        $c = 0;
        foreach (ImportRepository::payments($shipmentId) as $p) {
            $c += GstCalculator::scaled((string) $p['amount_fc'], 2);
        }
        return $c;
    }

    /** The current figures of an import: frozen once cleared, else worked out from its lines now. */
    public static function figures(array $s): array
    {
        $lines = ImportRepository::lines((int) $s['id']);
        $fx = (string) ($s['customs_fx_rate'] ?? '');
        return self::compute($lines, $fx !== '' ? $fx : '0', [
            'freight_inr' => (string) $s['freight_inr'], 'insurance_inr' => (string) $s['insurance_inr'],
            'cha_inr' => (string) $s['cha_inr'], 'port_inr' => (string) $s['port_inr'], 'other_inr' => (string) $s['other_inr'],
        ]);
    }

    // ------------------------------------------------------------ create and edit

    /**
     * Creates an import (its PO number is taken now). Returns [id, null] or [null, why not].
     *
     * @param array<string,mixed> $in supplier_name, supplier_country, supplier_address, currency_code, incoterm, po_date, payment_terms
     * @return array{0:?int, 1:?string}
     */
    public static function create(array $in, int $userId): array
    {
        $divisionId = self::divisionId();
        if ($divisionId === null || !self::isActive()) {
            return [null, 'Import & Distribution is not active yet. The MD activates it in Admin › Divisions.'];
        }
        [$fields, $error] = self::headerFields($in);
        if ($error !== null) {
            return [null, $error];
        }
        $fields['division_id'] = $divisionId;
        $fields['reference'] = self::nextReference('IMP-PO', 'PO');
        $id = ImportRepository::insert($fields, $userId);
        AuditLogRepository::log($userId, 'IMPORT_CREATED', 'import_shipments', $id, 'reference', null, $fields['reference']);
        return [$id, null];
    }

    /** @return array{0: array<string,mixed>, 1: ?string} */
    public static function headerFields(array $in): array
    {
        $name = trim((string) ($in['supplier_name'] ?? ''));
        $country = trim((string) ($in['supplier_country'] ?? ''));
        $address = trim((string) ($in['supplier_address'] ?? ''));
        $currency = strtoupper(trim((string) ($in['currency_code'] ?? '')));
        $incoterm = strtoupper(trim((string) ($in['incoterm'] ?? '')));
        $date = trim((string) ($in['po_date'] ?? ''));
        $terms = trim((string) ($in['payment_terms'] ?? ''));
        $fields = [
            'supplier_name' => $name, 'supplier_country' => $country, 'supplier_address' => $address !== '' ? $address : null,
            'currency_code' => $currency, 'incoterm' => $incoterm !== '' ? $incoterm : null, 'po_date' => $date,
            'payment_terms' => $terms !== '' ? $terms : null,
        ];
        $error = match (true) {
            $name === '' || mb_strlen($name) > 255 => 'Enter the foreign supplier.',
            $country === '' || mb_strlen($country) > 80 => 'Enter the supplier\'s country.',
            strtoupper($country) === 'INDIA' => 'An import comes from a supplier abroad; buy from an Indian supplier as a Domestic Trade purchase.',
            mb_strlen($address) > 1000 => 'The supplier address is too long.',
            !in_array($currency, self::CURRENCIES, true) => 'Choose the currency of the supplier\'s invoice.',
            $incoterm !== '' && !in_array($incoterm, ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'], true) => 'Choose a valid Incoterm.',
            !self::isDate($date) || $date > date('Y-m-d') => 'Enter the PO date (not in the future).',
            mb_strlen($terms) > 255 => 'The payment terms are too long.',
            default => null,
        };
        return [$fields, $error];
    }

    public static function saveHeader(int $id, array $in, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        if ($s === null) {
            return 'Import not found.';
        }
        if ($s['stage'] !== 'ordered') {
            return 'The PO can change only while the goods are not shipped.';
        }
        [$fields, $error] = self::headerFields($in);
        if ($error !== null) {
            return $error;
        }
        // QA batch 2 (ORD-08): payments are recorded in the PO's currency — it cannot change under them.
        if ($fields['currency_code'] !== (string) $s['currency_code'] && ImportRepository::payments($id) !== []) {
            return 'The supplier has already been paid in ' . $s['currency_code'] . ', so the PO currency can no longer change.';
        }
        // A PO generated before this change is out of date: generate it again before the goods ship.
        $changed = false;
        foreach ($fields as $col => $value) {
            if ((string) ($s[$col] ?? '') !== (string) ($value ?? '')) {
                $changed = true;
            }
        }
        ImportRepository::update($id, $fields + ($changed ? ['ipo_generated_at' => null] : []), $userId);
        AuditLogRepository::log($userId, 'IMPORT_PO_SAVED', 'import_shipments', $id, 'supplier_name', $s['supplier_name'], $fields['supplier_name']);
        return null;
    }

    /** @param array<string,mixed> $in product_id, description, hsn_code, quantity, unit, unit_price_fc, bcd_pct, sws_pct, igst_pct (blank = from the duty rates) */
    public static function addLine(int $id, array $in, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        if ($s === null) {
            return 'Import not found.';
        }
        if ($s['stage'] !== 'ordered') {
            return 'The lines can change only while the goods are not shipped.';
        }
        $product = ImportRepository::product((int) ($in['product_id'] ?? 0));
        $description = trim((string) ($in['description'] ?? ''));
        $hsn = preg_replace('/\D/', '', (string) ($in['hsn_code'] ?? '')) ?? '';
        if ($hsn === '' && $product !== null) {
            $hsn = preg_replace('/\D/', '', (string) ($product['hs_code'] ?? '')) ?? '';
        }
        $qty = trim((string) ($in['quantity'] ?? ''));
        $unit = trim((string) ($in['unit'] ?? ''));
        $price = trim((string) ($in['unit_price_fc'] ?? ''));
        $pcts = [];
        foreach (['bcd_pct', 'sws_pct', 'igst_pct'] as $k) {
            $pcts[$k] = trim((string) ($in[$k] ?? ''));
        }
        $badPct = static fn (string $v): bool => $v !== '' && (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) !== 1 || GstCalculator::scaled($v, 2) > 15000);
        $error = match (true) {
            $product === null || (int) $product['is_active'] !== 1 => 'Choose the product (stock is kept per product).',
            mb_strlen($description) > 500 => 'The description is too long (up to 500 characters).',
            strlen($hsn) < 4 || strlen($hsn) > 8 => 'Enter the HSN code (4 to 8 digits).',
            preg_match('/^\d{1,7}(\.\d{1,3})?$/', $qty) !== 1 || GstCalculator::scaled($qty, 3) <= 0 => 'Enter a quantity above zero (up to 3 decimals).',
            $unit === '' || mb_strlen($unit) > 20 => 'Enter the unit (e.g. SQM, PCS).',
            preg_match('/^\d{1,9}(\.\d{1,2})?$/', $price) !== 1 || GstCalculator::scaled($price, 2) <= 0 => 'Enter the unit price in the supplier\'s currency, above zero (up to 2 decimals).',
            self::lineValueCents($qty, $price) > 99999999999 => 'The line value is too large; split it into several lines.',
            $badPct($pcts['bcd_pct']) || $badPct($pcts['sws_pct']) || $badPct($pcts['igst_pct']) => 'A duty rate is a percentage, 0 to 150, with up to 2 decimals.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        $duty = self::dutyFor($hsn);
        $pct = static fn (string $v, string $fallback): string => $v !== '' ? GstCalculator::money(GstCalculator::scaled($v, 2)) : $fallback;
        ImportRepository::addLine($id, [
            'product_id' => (int) $product['id'],
            'description' => $description !== '' ? $description : (string) $product['name'],
            'hsn_code' => $hsn,
            'quantity' => $qty,
            'unit' => mb_strtoupper($unit),
            'unit_price_fc' => $price,
            'bcd_pct' => $pct($pcts['bcd_pct'], $duty['bcd']),
            'sws_pct' => $pct($pcts['sws_pct'], $duty['sws']),
            'igst_pct' => $pct($pcts['igst_pct'], $duty['igst']),
        ], $userId);
        ImportRepository::update($id, ['ipo_generated_at' => null], $userId); // QA batch 2 (ORD-08): the PO changed
        AuditLogRepository::log($userId, 'IMPORT_LINE_ADDED', 'import_shipments', $id, 'line', null, (string) $product['product_code'] . ' × ' . $qty);
        return null;
    }

    public static function removeLine(int $id, int $lineId, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        if ($s === null || $s['stage'] !== 'ordered') {
            return 'The lines can change only while the goods are not shipped.';
        }
        // QA batch 2 (ORD-08): the PO value never falls below what the supplier has already been paid.
        $line = null;
        foreach (ImportRepository::lines($id) as $l) {
            if ((int) $l['id'] === $lineId) {
                $line = $l;
            }
        }
        if ($line === null) {
            return 'Line not found.';
        }
        $after = self::poValueCents($id) - self::lineValueCents((string) $line['quantity'], (string) $line['unit_price_fc']);
        if ($after < self::paidCents($id)) {
            return 'Removing this line would take the PO value (' . $s['currency_code'] . ' ' . self::fc($after) . ') below what the supplier has already been paid (' . $s['currency_code'] . ' ' . self::fc(self::paidCents($id)) . ').';
        }
        if (!ImportRepository::removeLine($id, $lineId)) {
            return 'Line not found.';
        }
        ImportRepository::update($id, ['ipo_generated_at' => null], $userId);
        AuditLogRepository::log($userId, 'IMPORT_LINE_REMOVED', 'import_shipments', $id, 'line', (string) $lineId, null);
        return null;
    }

    /** The LC opened by NexaCrest (applicant) for this import — a record of it. */
    public static function saveLc(int $id, array $in, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        $no = trim((string) ($in['lc_no'] ?? ''));
        $bank = trim((string) ($in['lc_bank'] ?? ''));
        $amount = trim((string) ($in['lc_amount_fc'] ?? ''));
        $expiry = trim((string) ($in['lc_expiry'] ?? ''));
        $margin = trim((string) ($in['lc_margin_inr'] ?? ''));
        $error = match (true) {
            $s === null => 'Import not found.',
            $s['stage'] === 'closed' => 'The import is closed.',
            $no === '' || mb_strlen($no) > 40 => 'Enter the LC number.',
            $bank === '' || mb_strlen($bank) > 150 => 'Enter the bank that opened the LC.',
            preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount) !== 1 || GstCalculator::scaled($amount, 2) <= 0 => 'Enter the LC amount in ' . ($s['currency_code'] ?? 'the supplier\'s currency') . '.',
            !self::isDate($expiry) => 'Enter the LC expiry date.',
            $margin !== '' && preg_match('/^\d{1,11}(\.\d{1,2})?$/', $margin) !== 1 => 'The margin money is an amount in ₹.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        ImportRepository::update($id, ['lc_no' => $no, 'lc_bank' => $bank, 'lc_amount_fc' => $amount, 'lc_expiry' => $expiry, 'lc_margin_inr' => $margin !== '' ? $margin : null], $userId);
        AuditLogRepository::log($userId, 'IMPORT_LC_SAVED', 'import_shipments', $id, 'lc_no', $s['lc_no'], $no);
        return null;
    }

    public static function saveShipment(int $id, array $in, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        $bl = trim((string) ($in['bl_no'] ?? ''));
        $blDate = trim((string) ($in['bl_date'] ?? ''));
        $vessel = trim((string) ($in['vessel'] ?? ''));
        $pol = trim((string) ($in['port_of_loading'] ?? ''));
        $error = match (true) {
            $s === null => 'Import not found.',
            !in_array($s['stage'], ['ordered', 'shipped'], true) => 'The BL is recorded before the goods are cleared.',
            $bl === '' || mb_strlen($bl) > 40 => 'Enter the bill of lading (BL) number.',
            !self::isDate($blDate) || $blDate < (string) $s['po_date'] => 'Enter the BL date (on or after the PO date).',
            mb_strlen($vessel) > 100 || mb_strlen($pol) > 100 => 'The vessel or port of loading is too long.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        ImportRepository::update($id, ['bl_no' => $bl, 'bl_date' => $blDate, 'vessel' => $vessel !== '' ? $vessel : null, 'port_of_loading' => $pol !== '' ? $pol : null], $userId);
        AuditLogRepository::log($userId, 'IMPORT_BL_SAVED', 'import_shipments', $id, 'bl_no', $s['bl_no'], $bl);
        return null;
    }

    /** The bill of entry, the customs exchange rate, the CHA, the clearance date and the charges in INR. */
    public static function saveClearance(int $id, array $in, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        $boe = preg_replace('/\D/', '', (string) ($in['boe_no'] ?? '')) ?? '';
        $boeDate = trim((string) ($in['boe_date'] ?? ''));
        $port = strtoupper(trim((string) ($in['port_code'] ?? '')));
        $cha = trim((string) ($in['cha_name'] ?? ''));
        $fx = trim((string) ($in['customs_fx_rate'] ?? ''));
        $cleared = trim((string) ($in['clearance_date'] ?? ''));
        $money = [];
        foreach (['freight_inr', 'insurance_inr', 'cha_inr', 'port_inr', 'other_inr'] as $k) {
            $money[$k] = trim((string) ($in[$k] ?? ''));
        }
        $badMoney = false;
        foreach ($money as $v) {
            if ($v !== '' && preg_match('/^\d{1,11}(\.\d{1,2})?$/', $v) !== 1) {
                $badMoney = true;
            }
        }
        $error = match (true) {
            $s === null => 'Import not found.',
            $s['stage'] !== 'shipped' => 'Customs clearance is recorded once the goods are shipped and before they are cleared.',
            strlen($boe) < 6 || strlen($boe) > 8 => 'Enter the bill of entry number (6 to 8 digits).',
            !self::isDate($boeDate) || $boeDate < (string) $s['bl_date'] => 'Enter the bill of entry date (on or after the BL date).',
            preg_match('/^IN[A-Z0-9]{3}[0-9A-Z]$/', $port) !== 1 => 'Enter the Indian port code of the bill of entry (6 characters, e.g. INNSA1).',
            $cha === '' || mb_strlen($cha) > 150 => 'Enter the customs agent (CHA).',
            preg_match('/^\d{1,3}(\.\d{1,4})?$/', $fx) !== 1 || GstCalculator::scaled($fx, 4) <= 0 => 'Enter the customs exchange rate on the bill of entry (₹ per ' . $s['currency_code'] . ', up to 4 decimals).',
            !self::isDate($cleared) || $cleared < $boeDate || $cleared > date('Y-m-d') => 'Enter the clearance date (on or after the bill of entry date, not in the future).',
            $badMoney => 'Each charge is an amount in ₹ (up to 2 decimals); leave it empty for none.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        $fields = ['boe_no' => $boe, 'boe_date' => $boeDate, 'port_code' => $port, 'cha_name' => $cha, 'customs_fx_rate' => $fx, 'clearance_date' => $cleared];
        foreach ($money as $k => $v) {
            $fields[$k] = $v !== '' ? $v : '0';
        }
        ImportRepository::update($id, $fields, $userId);
        AuditLogRepository::log($userId, 'IMPORT_CLEARANCE_SAVED', 'import_shipments', $id, 'boe_no', $s['boe_no'], $boe);
        return null;
    }

    public static function saveIgstCredit(int $id, string $date, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        $error = match (true) {
            $s === null => 'Import not found.',
            !in_array($s['stage'], ['cleared', 'received'], true) => 'The IGST input credit is recorded after the goods are cleared.',
            !self::isDate($date) || $date < (string) $s['boe_date'] || $date > date('Y-m-d') => 'Enter the date the IGST credit was taken (on or after the bill of entry date, not in the future).',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        ImportRepository::update($id, ['igst_credit_on' => $date], $userId);
        AuditLogRepository::log($userId, 'IMPORT_IGST_CREDIT', 'import_shipments', $id, 'igst_credit_on', null, $date);
        return null;
    }

    /**
     * D-257 Zoho: the customs duty and IGST paid for the bill of entry
     * (ICEGATE e-payment) — its date and challan / bank reference (D-212).
     * It goes to Zoho Books as a payment to Customs on the duty bill.
     */
    public static function saveDutyPayment(int $id, array $in, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        $date = trim((string) ($in['duty_paid_on'] ?? ''));
        $ref = trim((string) ($in['duty_bank_reference'] ?? ''));
        $error = match (true) {
            $s === null => 'Import not found.',
            empty($s['boe_no']) || $s['bcd_total'] === null => 'Record the bill of entry (customs clearance) first: the duty is worked out from it.',
            !empty($s['duty_zoho_payment_id']) => 'This duty payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it in Books.',
            !self::isDate($date) || $date < (string) $s['boe_date'] || $date > date('Y-m-d') => 'Enter the date the duty was paid (on or after the bill of entry date, not in the future).',
            $ref === '' || mb_strlen($ref) > 60 => 'Enter the challan or bank reference of the duty payment (e.g. ICEGATE challan CIN).',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        ImportRepository::update($id, ['duty_paid_on' => $date, 'duty_bank_reference' => $ref], $userId);
        AuditLogRepository::log($userId, 'IMPORT_DUTY_PAID', 'import_shipments', $id, 'duty_bank_reference', $s['duty_bank_reference'], $ref);
        return null;
    }

    /** Rule D-212: what a payment abroad is, to whom, for which import, at what rate, and the bank reference. */
    public static function paymentDescription(array $s, string $kind, string $amountFc, string $fxRate, string $amountInr, string $bankReference, ?string $note): string
    {
        $what = ['advance' => 'Import supplier advance', 'balance' => 'Import supplier balance payment', 'lc' => 'LC retirement payment'][$kind];
        $text = "{$what} of {$s['currency_code']} " . self::fc(GstCalculator::scaled($amountFc, 2))
            . ' (₹' . GstCalculator::inr($amountInr) . ' at ' . $fxRate . ") to {$s['supplier_name']} ({$s['supplier_country']}) against import PO {$s['reference']}"
            . ($kind === 'lc' && !empty($s['lc_no']) ? ", LC {$s['lc_no']}" : '')
            . ", SWIFT/UTR ref {$bankReference}";
        $note = trim((string) $note);
        return $note !== '' ? $text . '; note: ' . $note : $text;
    }

    /**
     * QA batch 2 (ORD-12): the import row is locked while a payment is checked and written, so two people
     * recording the same SWIFT/UTR (or together paying more than the PO) at the same moment cannot both pass.
     */
    public static function recordPayment(int $id, array $in, int $userId): ?string
    {
        $pdo = \App\Config\Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('SELECT id FROM import_shipments WHERE id = :id FOR UPDATE')->execute(['id' => $id]);
            $error = self::recordPaymentLocked($id, $in, $userId);
            if ($own) {
                $error === null ? $pdo->commit() : $pdo->rollBack();
            }
            return $error;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function recordPaymentLocked(int $id, array $in, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        $date = trim((string) ($in['paid_on'] ?? ''));
        $kind = (string) ($in['kind'] ?? '');
        $amount = trim((string) ($in['amount_fc'] ?? ''));
        $fx = trim((string) ($in['fx_rate'] ?? ''));
        $ref = trim((string) ($in['bank_reference'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        $error = match (true) {
            $s === null => 'Import not found.',
            $s['stage'] === 'closed' => 'The import is closed.',
            !isset(self::PAYMENT_KINDS[$kind]) => 'Choose what the payment is (advance, balance or LC retirement).',
            $kind === 'lc' && empty($s['lc_no']) => 'Record the LC first.',
            !self::isDate($date) || $date > date('Y-m-d') => 'Enter the date the money left the bank (not in the future).',
            preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) !== 1 || GstCalculator::scaled($amount, 2) <= 0 => 'Enter the amount paid in ' . $s['currency_code'] . '.',
            preg_match('/^\d{1,3}(\.\d{1,4})?$/', $fx) !== 1 || GstCalculator::scaled($fx, 4) <= 0 => 'Enter the bank\'s exchange rate (₹ per ' . $s['currency_code'] . ', up to 4 decimals).',
            $ref === '' || mb_strlen($ref) > 60 => 'Enter the SWIFT / UTR reference of the remittance.',
            ImportRepository::bankReferenceUsed($ref) => 'This bank reference is already recorded. Check the payment is not entered twice.',
            self::paidCents($id) + GstCalculator::scaled($amount, 2) > self::poValueCents($id) => 'This is more than the PO still owes (' . $s['currency_code'] . ' ' . self::fc(self::poValueCents($id) - self::paidCents($id)) . ').',
            mb_strlen($note) > 255 => 'The note is too long.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        $inr = GstCalculator::money(GstCalculator::roundDiv(GstCalculator::scaled($amount, 2) * GstCalculator::scaled($fx, 4), 10000));
        $description = self::paymentDescription($s, $kind, $amount, $fx, $inr, $ref, $note);
        ImportRepository::addPayment($id, [
            'paid_on' => $date, 'kind' => $kind, 'amount_fc' => $amount, 'fx_rate' => $fx, 'amount_inr' => $inr,
            'bank_reference' => $ref, 'description' => $description, 'note' => $note !== '' ? $note : null,
        ], $userId);
        AuditLogRepository::log($userId, 'IMPORT_PAYMENT_RECORDED', 'import_shipments', $id, 'amount_fc', null, $amount);
        return null;
    }

    public static function markPoGenerated(int $id, int $userId): void
    {
        ImportRepository::update($id, ['ipo_generated_at' => date('Y-m-d H:i:s')], $userId);
        // D-262: the company details the import PO prints are kept from its first generation.
        \App\Config\Database::connection()->prepare('UPDATE import_shipments SET company_snapshot_json = :j WHERE id = :id AND company_snapshot_json IS NULL')
            ->execute(['j' => OrderSnapshotService::companyValuesJson(), 'id' => $id]);
    }

    // ------------------------------------------------------------ stages

    /** @return list<string> what still stops the import moving on (empty = it may move on) */
    public static function blockers(int $id): array
    {
        $s = ImportRepository::find($id);
        if ($s === null) {
            return ['Import not found.'];
        }
        $lines = ImportRepository::lines($id);
        $out = [];
        switch ($s['stage']) {
            case 'ordered':
                if ($lines === []) {
                    $out[] = 'Add at least one line.';
                }
                if (empty($s['ipo_generated_at'])) {
                    $out[] = 'Generate the import PO.';
                }
                if (empty($s['bl_no'])) {
                    $out[] = 'Record the bill of lading (BL).';
                }
                break;
            case 'shipped':
                if (empty($s['boe_no']) || empty($s['clearance_date']) || empty($s['customs_fx_rate'])) {
                    $out[] = 'Record the customs clearance (bill of entry, exchange rate, CHA, clearance date).';
                }
                break;
            case 'cleared':
                break; // moving on puts the goods into stock
            case 'received':
                $due = self::poValueCents($id) - self::paidCents($id);
                if ($due > 0) {
                    $out[] = 'Still to pay the supplier: ' . $s['currency_code'] . ' ' . self::fc($due) . '.';
                }
                if (empty($s['igst_credit_on'])) {
                    $out[] = 'Record when the IGST paid on import was taken as input credit.';
                }
                break;
            case 'closed':
                $out[] = 'The import is closed.';
                break;
        }
        return $out;
    }

    /** Moves the import to its next stage when nothing stops it. Returns null or why not. */
    public static function advance(int $id, string $expectedStage, int $userId): ?string
    {
        $s = ImportRepository::find($id);
        if ($s === null) {
            return 'Import not found.';
        }
        if ($s['stage'] !== $expectedStage) {
            return 'Someone has already moved this import on. The page shows where it is now.';
        }
        $blockers = self::blockers($id);
        if ($blockers !== []) {
            return implode(' ', $blockers);
        }
        $keys = array_keys(self::STAGES);
        $next = $keys[(int) array_search($s['stage'], $keys, true) + 1];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (!ImportRepository::moveStage($id, (string) $s['stage'], $next, $userId)) {
                $pdo->rollBack();
                return 'Someone has already moved this import on. The page shows where it is now.';
            }
            if ($next === 'cleared') {
                self::freeze($s, $userId);
            }
            if ($next === 'received') {
                self::receiveIntoStock($s, $userId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditLogRepository::log($userId, 'IMPORT_STAGE_MOVED', 'import_shipments', $id, 'stage', (string) $s['stage'], $next);
        return null;
    }

    /** At clearance the duty and the landed cost per item are worked out and frozen. */
    private static function freeze(array $s, int $userId): void
    {
        $f = self::figures($s);
        foreach ($f['lines'] as $l) {
            ImportRepository::setLineFigures((int) $l['id'], $l);
        }
        ImportRepository::update((int) $s['id'], [
            'value_total_inr' => $f['value_total_inr'], 'assessable_total' => $f['assessable_total'], 'bcd_total' => $f['bcd_total'],
            'sws_total' => $f['sws_total'], 'igst_total' => $f['igst_total'], 'landed_total' => $f['landed_total'],
        ], $userId);
    }

    /** Puts every line into stock at its frozen landed cost (weighted average). */
    private static function receiveIntoStock(array $s, int $userId): void
    {
        $today = date('Y-m-d');
        foreach (ImportRepository::lines((int) $s['id']) as $l) {
            $b = ImportRepository::lockBalance((int) $s['division_id'], (int) $l['product_id']);
            $qty = GstCalculator::scaled((string) $b['qty_on_hand'], 3) + GstCalculator::scaled((string) $l['quantity'], 3);
            $value = GstCalculator::scaled((string) $b['value_inr'], 2) + GstCalculator::scaled((string) $l['landed_inr'], 2);
            ImportRepository::setBalance((int) $s['division_id'], (int) $l['product_id'], self::fmt3($qty), GstCalculator::money($value));
            ImportRepository::addMovement([
                'division_id' => (int) $s['division_id'], 'product_id' => (int) $l['product_id'], 'moved_on' => $today, 'kind' => 'receipt',
                'quantity' => (string) $l['quantity'], 'value_inr' => (string) $l['landed_inr'], 'shipment_id' => (int) $s['id'],
                'note' => "Received from import {$s['reference']} (bill of entry {$s['boe_no']})",
            ], $userId);
        }
        ImportRepository::update((int) $s['id'], ['received_on' => $today], $userId);
    }

    /** Scale-3 integer => "12.500". */
    public static function fmt3(int $v): string
    {
        $sign = $v < 0 ? '-' : '';
        $v = abs($v);
        return $sign . intdiv($v, 1000) . '.' . str_pad((string) ($v % 1000), 3, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------ stock out (local sale)

    /**
     * What stops a local sale's lines being issued from stock: every line must
     * be a product, and the stock on hand must cover each product.
     *
     * @param list<array<string,mixed>> $saleLines domestic_order_lines rows
     * @return list<string>
     */
    public static function stockShortfalls(int $divisionId, array $saleLines): array
    {
        $need = [];
        $out = [];
        foreach ($saleLines as $l) {
            if (empty($l['product_id'])) {
                $out[] = "Line {$l['line_no']} ({$l['description']}) is not linked to a product, so it cannot come out of stock.";
                continue;
            }
            $need[(int) $l['product_id']] = ($need[(int) $l['product_id']] ?? 0) + GstCalculator::scaled((string) $l['quantity'], 3);
        }
        foreach ($need as $productId => $qty) {
            $b = ImportRepository::balance($divisionId, $productId);
            $have = $b !== null ? GstCalculator::scaled((string) $b['qty_on_hand'], 3) : 0;
            if ($have < $qty) {
                $p = ImportRepository::product($productId);
                $out[] = 'Not enough stock of ' . ($p['name'] ?? "product {$productId}") . ': the sale needs ' . self::trim3($qty) . ', on hand ' . self::trim3($have) . '.';
            }
        }
        return $out;
    }

    private static function trim3(int $v): string
    {
        $s = self::fmt3($v);
        return rtrim(rtrim($s, '0'), '.');
    }

    /**
     * Issues a local sale's lines from stock, inside the caller's transaction.
     * Each balance row is locked first, so two sales at once can never take
     * the same stock twice. Returns null, or why not (the caller rolls back).
     *
     * @param list<array<string,mixed>> $saleLines
     */
    public static function issueForSale(int $divisionId, int $orderId, string $orderReference, array $saleLines, int $userId): ?string
    {
        $today = date('Y-m-d');
        foreach ($saleLines as $l) {
            if (empty($l['product_id'])) {
                return "Line {$l['line_no']} is not linked to a product, so it cannot come out of stock.";
            }
            $productId = (int) $l['product_id'];
            $b = ImportRepository::lockBalance($divisionId, $productId);
            $have = GstCalculator::scaled((string) $b['qty_on_hand'], 3);
            $value = GstCalculator::scaled((string) $b['value_inr'], 2);
            $qty = GstCalculator::scaled((string) $l['quantity'], 3);
            if ($have < $qty) {
                $p = ImportRepository::product($productId);
                return 'Not enough stock of ' . ($p['name'] ?? "product {$productId}") . ': the sale needs ' . self::trim3($qty) . ', on hand ' . self::trim3($have) . '.';
            }
            // Weighted average: the issue takes its share of the value; the last unit takes all that is left.
            $out = $qty === $have ? $value : self::roundHalfAway((float) $value * (float) $qty / (float) $have);
            ImportRepository::setBalance($divisionId, $productId, self::fmt3($have - $qty), GstCalculator::money($value - $out));
            ImportRepository::addMovement([
                'division_id' => $divisionId, 'product_id' => $productId, 'moved_on' => $today, 'kind' => 'issue',
                'quantity' => self::fmt3(-$qty), 'value_inr' => GstCalculator::money(-$out), 'order_id' => $orderId,
                'note' => "Issued to local sale {$orderReference}",
            ], $userId);
        }
        return null;
    }

    // ------------------------------------------------------------ reports

    /** The divisions the current person's reports cover (IMP only; company level: IMP, whatever the header choice is). */
    public static function reportDivisionIds(): array
    {
        $imp = self::divisionId();
        if ($imp === null) {
            return [];
        }
        return DivisionService::allows($imp) ? [$imp] : [];
    }

    /** @return list<array<string,mixed>> stock on hand with its average cost and suggested selling price */
    public static function stockReport(): array
    {
        $margin = self::setting('imp_margin_pct');
        $rows = [];
        foreach (ImportRepository::stockOnHand(self::reportDivisionIds()) as $r) {
            $qty = GstCalculator::scaled((string) $r['qty_on_hand'], 3);
            $avg = $qty > 0 ? GstCalculator::roundDiv(GstCalculator::scaled((string) $r['value_inr'], 2) * 100000, $qty) : 0;
            $rows[] = array_merge($r, ['avg_cost_inr' => self::fmt4($avg), 'suggested_price_inr' => self::suggestedPrice(self::fmt4($avg), $margin)]);
        }
        return $rows;
    }
}
