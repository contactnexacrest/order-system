<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FinancialYear;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\OrderRepository;

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the Domestic Trade (DOM)
 * division — selling to Indian resellers with GST, in INR.
 *
 * A DOM order is an ordinary orders row (division DOM, currency INR, so the
 * division rules, access checks, lists and reports all apply) plus its own
 * domestic record and stages:
 *
 *   enquiry → quotation → order confirmation → purchase → dispatch →
 *   tax invoice → payment → closed
 *
 * No BL, LUT, FIRC or export benefits (those modules are off for DOM). The
 * tax invoice number is fixed when the goods move to dispatch — GST law wants
 * the invoice issued at the removal of the goods, and the e-way bill needs its
 * number — and the lines and totals are frozen from then on.
 *
 * DOM's own rules are its division settings (Admin › Divisions › Domestic
 * Trade): the default GST rate, the e-way bill threshold, e-invoicing and the
 * payment terms — standard Indian rules as starting values, to be confirmed by
 * the CA. GST rates per HSN / category are in Admin › GST rates.
 *
 * D-257 Phase 3: the Import & Distribution (IMP) division sells its stock to
 * Indian resellers through the same order: an IMP local sale is this order in
 * the IMP division, with IMP's own numbers (IMP/26-27/0001), the same GST
 * rules, and its goods issued from stock (ImportService) when the tax invoice
 * is fixed, instead of a supplier PO.
 * Port: domesticOrderService.js.
 */
final class DomesticOrderService
{
    public const CODE = 'DOM';

    /** D-257 Phase 3: the divisions that sell in India with this order (Domestic Trade, and Import & Distribution from stock). */
    public const LOCAL_CODES = ['DOM', 'IMP'];

    public const STAGES = [
        'enquiry' => 'Enquiry',
        'quotation' => 'Quotation',
        'order_confirmation' => 'Order confirmation',
        'purchase' => 'Purchase',
        'dispatch' => 'Dispatch',
        'tax_invoice' => 'Tax invoice',
        'payment' => 'Payment',
        'closed' => 'Closed',
    ];

    /** Starting values of DOM's own settings, used when a setting is missing (to be confirmed by the CA). */
    public const DEFAULTS = [
        'dom_default_gst_rate' => '18',
        'dom_eway_threshold_inr' => '50000',
        'dom_e_invoice_applicable' => '0',
        'dom_payment_terms_days' => '30',
    ];

    public const DOCUMENTS = [
        'DQT' => 'Quotation',
        'DOC' => 'Order confirmation',
        'GTI' => 'GST tax invoice',
        'DCH' => 'Delivery challan',
    ];

    /** The stage from which each document may be generated. */
    private const DOCUMENT_FROM = ['DQT' => 'quotation', 'DOC' => 'order_confirmation', 'GTI' => 'dispatch', 'DCH' => 'dispatch'];

    /** Lines may change only before the tax invoice number is fixed. */
    private const LINES_OPEN = ['enquiry', 'quotation', 'order_confirmation', 'purchase'];

    /**
     * HNT-L03 (QA 13 Nov 2026): the limits of one line, as for export order lines
     * (OrderLineValidator): quantity at most 10,00,000 (in thousandths here; owner decision
     * item 10a, 13 Nov 2026: the hard block is 1,000,000 in any unit), and
     * the line value and the invoice total at most 999999999999.99 (in paise). Without
     * them quantity × rate could pass what PHP's whole numbers hold and the GST maths
     * failed (HTTP 500) where Node worked it out.
     */
    public const MAX_LINE_QUANTITY_MILLI = 1000000000;
    public const MAX_AMOUNT_PAISE = 99999999999999;

    public static function divisionId(string $code = self::CODE): ?int
    {
        foreach (DivisionService::all() as $d) {
            if ($d['code'] === $code) {
                return (int) $d['id'];
            }
        }
        return null;
    }

    public static function isActive(string $code = self::CODE): bool
    {
        $id = self::divisionId($code);
        $d = $id !== null ? DivisionService::find($id) : null;
        return $d !== null && $d['status'] === 'active';
    }

    /** @param array<string,mixed> $order */
    public static function isDomesticOrder(array $order): bool
    {
        foreach (self::LOCAL_CODES as $code) {
            $id = self::divisionId($code);
            if ($id !== null && (int) ($order['division_id'] ?? 0) === $id) {
                return true;
            }
        }
        return false;
    }

    /** The division code of a local-sale order: IMP for Import & Distribution, else DOM. */
    public static function codeOfDivision(?int $divisionId): string
    {
        $imp = self::divisionId('IMP');
        return $imp !== null && $divisionId === $imp ? 'IMP' : self::CODE;
    }

    /** The division code of a local-sale order (by its orders row). */
    public static function codeOfOrder(int $orderId): string
    {
        $order = OrderRepository::find($orderId);
        return self::codeOfDivision($order !== null ? (int) $order['division_id'] : null);
    }

    /** The counter series of a document in a division: DOM keeps its own (GTI, QT…); IMP's are IMP-GTI, IMP-QT… */
    private static function series(string $code, string $series): string
    {
        return $code === self::CODE ? $series : $code . '-' . $series;
    }

    public static function setting(string $key): string
    {
        $id = self::divisionId();
        $v = $id !== null ? DomesticOrderRepository::divisionSetting($id, $key) : null;
        return $v !== null && $v !== '' ? $v : (self::DEFAULTS[$key] ?? '');
    }

    /** NexaCrest's GST state: the first two digits of its GSTIN (Settings). */
    public static function sellerStateCode(): string
    {
        $gstin = strtoupper(trim((string) CompanySettingsRepository::companyValue('gstin')));
        return preg_match('/^[0-9]{2}/', $gstin) === 1 ? substr($gstin, 0, 2) : '';
    }

    public static function stageIndex(string $stage): int
    {
        return (int) array_search($stage, array_keys(self::STAGES), true);
    }

    public static function atOrAfter(string $stage, string $than): bool
    {
        return self::stageIndex($stage) >= self::stageIndex($than);
    }

    /** The GST rate of a line: the longest HSN prefix that matches, else its category's, else DOM's default. */
    public static function gstRateFor(string $hsn, ?int $categoryId = null): string
    {
        $best = null;
        $bestLen = -1;
        $byCategory = null;
        foreach (DomesticOrderRepository::gstRates(true) as $r) {
            $prefix = (string) ($r['hsn_prefix'] ?? '');
            if ($prefix !== '' && str_starts_with($hsn, $prefix) && strlen($prefix) > $bestLen) {
                $best = (string) $r['rate_pct'];
                $bestLen = strlen($prefix);
            }
            if ($prefix === '' && $categoryId !== null && (int) ($r['category_id'] ?? 0) === $categoryId) {
                $byCategory = (string) $r['rate_pct'];
            }
        }
        return GstCalculator::money(GstCalculator::scaled($best ?? $byCategory ?? self::setting('dom_default_gst_rate'), 2));
    }

    // ------------------------------------------------------------ create

    /**
     * Creates a DOM order. Returns [orderId, null] or [null, error message].
     *
     * @param array<string,mixed> $in client_id, buyer_gstin, buyer_state_code, place_of_supply, delivery_address, payment_terms_days, buyer_po_ref
     * @return array{0:?int, 1:?string}
     */
    public static function create(array $in, int $userId, string $code = self::CODE): array
    {
        $code = in_array($code, self::LOCAL_CODES, true) ? $code : self::CODE;
        $divisionId = self::divisionId($code);
        if ($divisionId === null || !self::isActive($code)) {
            return [null, ($code === 'IMP' ? 'Import & Distribution' : 'Domestic Trade') . ' is not active yet. The MD activates it in Admin › Divisions.'];
        }
        $client = DomesticOrderRepository::client((int) ($in['client_id'] ?? 0));
        if ($client === null || (int) $client['is_active'] !== 1 || !DivisionService::allowsClient((int) $client['id']) && !DivisionService::seesAll()) {
            return [null, 'Please select a valid client.'];
        }
        [$fields, $error] = self::partyFields($in);
        if ($error !== null) {
            return [null, $error];
        }
        $seller = self::sellerStateCode();
        if ($seller === '') {
            return [null, 'NexaCrest\'s GSTIN is missing in Settings, so the GST state is unknown.'];
        }
        $currency = Database::connection()->query("SELECT id FROM currencies WHERE code = 'INR'")->fetchColumn();
        if ($currency === false) {
            return [null, 'The INR currency is missing.'];
        }
        $incoterm = Database::connection()->query('SELECT id FROM incoterms ORDER BY is_default DESC, id LIMIT 1')->fetchColumn();
        $preset = Database::connection()->query('SELECT id FROM payment_presets ORDER BY is_default DESC, id LIMIT 1')->fetchColumn();

        $orderId = DivisionService::withDivision($divisionId, static function () use ($client, $fields, $seller, $currency, $incoterm, $preset, $divisionId, $userId): int {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                $reference = ReferenceNumberService::generateOrderReference();
                $result = OrderRepository::createWithNextSequence((int) $client['id'], static fn (int $seq): array => [
                    'order_reference' => $reference,
                    'buyer_inquiry_ref' => $client['client_unique_number'],
                    'payment_preset_id' => (int) $preset,
                    'incoterm_id' => (int) $incoterm,
                    'port_of_loading_id' => null,
                    'port_of_discharge_id' => null,
                    'port_of_discharge_text' => null,
                    'currency_id' => (int) $currency,
                    'coo_type' => 'Not applicable',
                    'include_annexure_a' => false,
                    'estimated_total_cbm' => '',
                    'estimated_gross_weight_kg' => '',
                    'estimated_net_weight_kg' => '',
                    'indicative_freight_amount' => '',
                    'indicative_insurance_amount' => '',
                    'buyers_po_ref' => $fields['buyer_po_ref'] ?? 'NIL',
                    'quotation_date' => date('Y-m-d'),
                    'quotation_valid_until' => date('Y-m-d', strtotime('+15 days')),
                    'division_id' => $divisionId,
                ], $userId);
                $orderId = (int) $result['orderId'];
                // A domestic sale has no Incoterm, port or export payment preset: those ids are kept for the schema only.
                $pdo->prepare('UPDATE orders SET incoterm_code = NULL, country_of_destination = :c WHERE id = :id')->execute(['c' => 'India', 'id' => $orderId]);
                if (TestModeService::isEnabled()) {
                    OrderRepository::markTest($orderId);
                }
                DomesticOrderRepository::insert($orderId, $fields + ['seller_state_code' => $seller], $userId);
                DomesticOrderRepository::fillClientGst((int) $client['id'], $fields['buyer_gstin'], $fields['buyer_state_code'], $fields['place_of_supply']);
                $pdo->commit();
                return $orderId;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        });
        AuditLogRepository::log($userId, 'DOMESTIC_ORDER_CREATED', 'orders', $orderId, 'division', null, $code);
        return [$orderId, null];
    }

    /**
     * The buyer's GST details from a form, checked.
     *
     * @param array<string,mixed> $in
     * @return array{0: array<string,mixed>, 1: ?string}
     */
    public static function partyFields(array $in): array
    {
        $gstin = strtoupper(trim((string) ($in['buyer_gstin'] ?? '')));
        $state = trim((string) ($in['buyer_state_code'] ?? ''));
        $pos = trim((string) ($in['place_of_supply'] ?? ''));
        $days = trim((string) ($in['payment_terms_days'] ?? ''));
        $fields = [
            'buyer_gstin' => $gstin !== '' ? $gstin : null,
            'buyer_state_code' => $state !== '' ? $state : null,
            'place_of_supply' => $pos,
            'delivery_address' => trim((string) ($in['delivery_address'] ?? '')) ?: null,
            'payment_terms_days' => $days === '' ? (int) self::setting('dom_payment_terms_days') : (int) $days,
            'buyer_po_ref' => mb_substr(trim((string) ($in['buyer_po_ref'] ?? '')), 0, 80) ?: null,
        ];
        $error = match (true) {
            $gstin !== '' && !GstCalculator::isValidGstin($gstin) => 'The buyer\'s GSTIN is not valid (15 characters: state code, PAN, entity number, Z and a check character).',
            $state === '' || !DomesticOrderRepository::isState($state) => 'Choose the buyer\'s state.',
            $gstin !== '' && substr($gstin, 0, 2) !== $state => 'The buyer\'s GSTIN starts with state code ' . substr($gstin, 0, 2) . ', which does not match the state chosen.',
            $pos === '' || !DomesticOrderRepository::isState($pos) => 'Choose the place of supply (the state the goods are delivered to).',
            $days !== '' && (preg_match('/^\d{1,3}$/', $days) !== 1 || (int) $days > 365) => 'Payment terms are a number of days, 0 to 365.',
            mb_strlen((string) ($fields['delivery_address'] ?? '')) > 1000 => 'The delivery address is too long.',
            default => null,
        };
        return [$fields, $error];
    }

    /** Changes the buyer's GST details while the order is still before its tax invoice. */
    public static function saveParty(int $orderId, array $in, int $userId): ?string
    {
        $dom = DomesticOrderRepository::find($orderId);
        if ($dom === null) {
            return 'Order not found.';
        }
        if (!in_array($dom['stage'], self::LINES_OPEN, true)) {
            return 'The tax invoice number is fixed, so the buyer\'s GST details can no longer change.';
        }
        [$fields, $error] = self::partyFields($in);
        if ($error !== null) {
            return $error;
        }
        DomesticOrderRepository::update($orderId, $fields, $userId);
        AuditLogRepository::log($userId, 'DOMESTIC_PARTY_SAVED', 'orders', $orderId, 'place_of_supply', $dom['place_of_supply'], $fields['place_of_supply']);
        return null;
    }

    // ------------------------------------------------------------ lines

    /** @param array<string,mixed> $in description, hsn_code, quantity, unit, rate_inr, gst_rate_pct (blank = from the GST rates) */
    public static function addLine(int $orderId, array $in, int $userId): ?string
    {
        $dom = DomesticOrderRepository::find($orderId);
        if ($dom === null) {
            return 'Order not found.';
        }
        if (!in_array($dom['stage'], self::LINES_OPEN, true)) {
            return 'The tax invoice number is fixed, so the lines can no longer change.';
        }
        $description = trim((string) ($in['description'] ?? ''));
        $hsn = preg_replace('/\D/', '', (string) ($in['hsn_code'] ?? '')) ?? '';
        $qty = trim((string) ($in['quantity'] ?? ''));
        $unit = trim((string) ($in['unit'] ?? ''));
        $rate = trim((string) ($in['rate_inr'] ?? ''));
        $gst = trim((string) ($in['gst_rate_pct'] ?? ''));
        $error = match (true) {
            $description === '' || mb_strlen($description) > 500 => 'Enter what is sold (up to 500 characters).',
            strlen($hsn) < 4 || strlen($hsn) > 8 => 'Enter the HSN code (4 to 8 digits).',
            preg_match('/^\d{1,10}(\.\d{1,3})?$/', $qty) !== 1 || GstCalculator::scaled($qty, 3) <= 0 => 'Enter a quantity above zero (up to 3 decimals).',
            GstCalculator::scaled($qty, 3) > self::MAX_LINE_QUANTITY_MILLI => 'The quantity can be at most 10,00,000.', // HNT-L03, item 10a
            $unit === '' || mb_strlen($unit) > 20 => 'Enter the unit (e.g. SQM, PCS).',
            // Owner decision item 10a: above about 20 containers of its unit, only with "I confirm this quantity".
            ($large = QuantityLimits::check($qty, $unit, false, !empty($in[QuantityLimits::TICK]))) !== null => $large,
            preg_match('/^\d{1,11}(\.\d{1,2})?$/', $rate) !== 1 || GstCalculator::scaled($rate, 2) <= 0 => 'Enter the rate in ₹ above zero (up to 2 decimals).',
            // HNT-L03: quantity × rate, rounded to the paisa, at most MAX_AMOUNT_PAISE — checked
            // as rate ≤ limit ÷ quantity, so the check itself never multiplies past PHP_INT_MAX.
            GstCalculator::scaled($rate, 2) > intdiv(self::MAX_AMOUNT_PAISE * 1000 + 499, GstCalculator::scaled($qty, 3)) => 'The line value (quantity × rate) can be at most ₹9,99,99,99,99,999.99.',
            $gst !== '' && !in_array(GstCalculator::money(GstCalculator::scaled($gst, 2)), ['0.00', '0.25', '3.00', '5.00', '12.00', '18.00', '28.00', '40.00'], true) => 'The GST rate must be one of 0, 0.25, 3, 5, 12, 18, 28 or 40 %.',
            // D-257 Phase 3: an Import & Distribution sale comes out of stock, which is kept per product.
            self::codeOfOrder($orderId) === 'IMP' && \App\Repositories\ImportRepository::product((int) ($in['product_id'] ?? 0)) === null => 'Choose the product: an Import & Distribution sale comes out of stock.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        $gstPct = $gst !== '' ? GstCalculator::money(GstCalculator::scaled($gst, 2)) : self::gstRateFor($hsn);
        // HNT-L03: the invoice total (with GST, rounded to the rupee) stays within the limit too.
        $after = GstCalculator::compute(
            array_merge(DomesticOrderRepository::lines($orderId), [['quantity' => $qty, 'rate_inr' => $rate, 'gst_rate_pct' => $gstPct, 'hsn_code' => $hsn]]),
            (string) ($dom['seller_state_code'] ?? ''),
            (string) ($dom['place_of_supply'] ?? '')
        );
        if (GstCalculator::scaled($after['invoice_total'], 2) > self::MAX_AMOUNT_PAISE) {
            return 'With this line the invoice total would be more than ₹9,99,99,99,99,999.99.';
        }
        DomesticOrderRepository::addLine($orderId, [
            'product_id' => ($in['product_id'] ?? '') !== '' ? (int) $in['product_id'] : null,
            'description' => $description,
            'hsn_code' => $hsn,
            'quantity' => $qty,
            'unit' => mb_strtoupper($unit),
            'rate_inr' => $rate,
            'gst_rate_pct' => $gstPct,
        ], $userId);
        AuditLogRepository::log($userId, 'DOMESTIC_LINE_ADDED', 'orders', $orderId, 'line', null, $description);
        if (QuantityLimits::isLarge($qty, $unit)) {
            QuantityLimits::audit($userId, 'orders', $orderId, $description, $qty, mb_strtoupper($unit));
        }
        return null;
    }

    public static function removeLine(int $orderId, int $lineId, int $userId): ?string
    {
        $dom = DomesticOrderRepository::find($orderId);
        if ($dom === null || !in_array($dom['stage'], self::LINES_OPEN, true)) {
            return 'The tax invoice number is fixed, so the lines can no longer change.';
        }
        if (!DomesticOrderRepository::removeLine($orderId, $lineId)) {
            return 'Line not found.';
        }
        AuditLogRepository::log($userId, 'DOMESTIC_LINE_REMOVED', 'orders', $orderId, 'line', (string) $lineId, null);
        return null;
    }

    /** The GST figures of the order now (live lines), or the frozen invoice totals once the invoice number is fixed. */
    public static function totals(int $orderId): array
    {
        $dom = DomesticOrderRepository::find($orderId);
        return GstCalculator::compute(DomesticOrderRepository::lines($orderId), (string) ($dom['seller_state_code'] ?? ''), (string) ($dom['place_of_supply'] ?? ''));
    }

    // ------------------------------------------------------------ steps

    public static function savePurchase(int $orderId, array $in, int $userId): ?string
    {
        $dom = DomesticOrderRepository::find($orderId);
        $name = trim((string) ($in['supplier_name'] ?? ''));
        $ref = trim((string) ($in['supplier_po_ref'] ?? ''));
        $error = match (true) {
            $dom === null => 'Order not found.',
            !in_array($dom['stage'], ['order_confirmation', 'purchase'], true) => 'The purchase is recorded after the order confirmation and before dispatch.',
            self::codeOfOrder($orderId) === 'IMP' => 'An Import & Distribution sale comes out of stock: there is no supplier PO.',
            $name === '' || mb_strlen($name) > 255 => 'Enter the supplier.',
            $ref === '' || mb_strlen($ref) > 80 => 'Enter the supplier PO number.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        DomesticOrderRepository::update($orderId, ['supplier_name' => $name, 'supplier_po_ref' => $ref], $userId);
        AuditLogRepository::log($userId, 'DOMESTIC_PURCHASE_SAVED', 'orders', $orderId, 'supplier_po_ref', $dom['supplier_po_ref'], $ref);
        return null;
    }

    /** Whether this order's goods need an e-way bill (consignment value above DOM's threshold). */
    public static function needsEwayBill(array $dom, ?array $totals = null): bool
    {
        $value = $dom['invoice_total'] ?? ($totals['invoice_total'] ?? '0');
        return GstCalculator::scaled((string) $value, 2) > GstCalculator::scaled(self::setting('dom_eway_threshold_inr'), 2);
    }

    public static function saveDispatch(int $orderId, array $in, int $userId): ?string
    {
        $dom = DomesticOrderRepository::find($orderId);
        $date = trim((string) ($in['dispatch_date'] ?? ''));
        $transporter = trim((string) ($in['transporter_name'] ?? ''));
        $transporterId = strtoupper(trim((string) ($in['transporter_id'] ?? '')));
        $vehicle = strtoupper(preg_replace('/\s+/', '', (string) ($in['vehicle_no'] ?? '')) ?? '');
        $lr = trim((string) ($in['lr_no'] ?? ''));
        $km = trim((string) ($in['distance_km'] ?? ''));
        $eway = preg_replace('/\D/', '', (string) ($in['eway_bill_no'] ?? '')) ?? '';
        $ewayDate = trim((string) ($in['eway_bill_date'] ?? ''));
        $error = match (true) {
            $dom === null => 'Order not found.',
            $dom['stage'] !== 'dispatch' => 'Dispatch details are recorded at the Dispatch stage.',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false => 'Enter the dispatch date.',
            $date < (string) $dom['tax_invoice_date'] => 'The goods cannot leave before the tax invoice date (' . $dom['tax_invoice_date'] . ').',
            $transporter === '' || mb_strlen($transporter) > 150 => 'Enter the transporter.',
            $transporterId !== '' && preg_match('/^[0-9A-Z]{15}$/', $transporterId) !== 1 => 'The transporter ID is the transporter\'s 15-character GSTIN or TRANSIN.',
            preg_match('/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$/', $vehicle) !== 1 => 'Enter the vehicle number (e.g. KA01AB1234).',
            mb_strlen($lr) > 40 => 'The LR number is too long.',
            $km !== '' && (preg_match('/^\d{1,4}$/', $km) !== 1 || (int) $km > 4000) => 'The distance is in km, up to 4,000.',
            $eway !== '' && strlen($eway) !== 12 => 'An e-way bill number has 12 digits.',
            $eway !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ewayDate) !== 1 => 'Enter the e-way bill date.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        DomesticOrderRepository::update($orderId, [
            'dispatch_date' => $date, 'transporter_name' => $transporter, 'transporter_id' => $transporterId ?: null,
            'vehicle_no' => $vehicle, 'lr_no' => $lr ?: null, 'distance_km' => $km !== '' ? (int) $km : null,
            'eway_bill_no' => $eway ?: null, 'eway_bill_date' => $eway !== '' ? $ewayDate : null,
        ], $userId);
        AuditLogRepository::log($userId, 'DOMESTIC_DISPATCH_SAVED', 'orders', $orderId, 'vehicle_no', $dom['vehicle_no'], $vehicle);
        return null;
    }

    public static function markInvoiceSent(int $orderId, string $date, int $userId): ?string
    {
        $dom = DomesticOrderRepository::find($orderId);
        $error = match (true) {
            $dom === null => 'Order not found.',
            $dom['stage'] !== 'tax_invoice' => 'The invoice is marked as sent at the Tax invoice stage.',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 => 'Enter the date the invoice was sent.',
            $date < (string) $dom['tax_invoice_date'] => 'The invoice cannot be sent before its own date.',
            default => null,
        };
        if ($error !== null) {
            return $error;
        }
        DomesticOrderRepository::update($orderId, ['invoice_sent_on' => $date], $userId);
        AuditLogRepository::log($userId, 'DOMESTIC_INVOICE_SENT', 'orders', $orderId, 'invoice_sent_on', null, $date);
        return null;
    }

    /** Rule D-212: what a DOM receipt is, for which order and buyer, what it settles, and the bank reference. */
    public static function paymentDescription(array $order, array $dom, string $amount, string $bankReference, ?string $note): string
    {
        $buyer = (string) ($order['company_legal_name'] ?? '');
        // D-257 Phase 3: an Import & Distribution sale is a "local sale" (of imported stock).
        $kind = self::codeOfDivision(isset($order['division_id']) ? (int) $order['division_id'] : null) === 'IMP' ? 'Local sale' : 'Domestic sale';
        $text = !empty($dom['tax_invoice_no'])
            ? "{$kind} receipt of ₹" . GstCalculator::inr($amount) . " from {$buyer} against GST tax invoice {$dom['tax_invoice_no']} (order {$order['order_reference']}), bank ref {$bankReference}"
            : "{$kind} advance of ₹" . GstCalculator::inr($amount) . " from {$buyer} against order {$order['order_reference']}, bank ref {$bankReference}";
        $note = trim((string) $note);
        return $note !== '' ? $text . '; note: ' . $note : $text;
    }

    /**
     * QA batch 2 (ORD-12): the domestic order row is locked while a receipt is checked and written, so the same
     * bank reference (or more than the invoice) cannot be recorded twice by two people at the same moment.
     */
    public static function recordPayment(int $orderId, array $in, int $userId): ?string
    {
        $pdo = \App\Config\Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('SELECT order_id FROM domestic_orders WHERE order_id = :id FOR UPDATE')->execute(['id' => $orderId]);
            $error = self::recordPaymentLocked($orderId, $in, $userId);
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

    private static function recordPaymentLocked(int $orderId, array $in, int $userId): ?string
    {
        $order = OrderRepository::find($orderId);
        $dom = DomesticOrderRepository::find($orderId);
        $date = trim((string) ($in['received_on'] ?? ''));
        $amount = trim((string) ($in['amount_inr'] ?? ''));
        $ref = trim((string) ($in['bank_reference'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        $error = match (true) {
            $order === null || $dom === null => 'Order not found.',
            !self::atOrAfter((string) $dom['stage'], 'order_confirmation') || $dom['stage'] === 'closed' => 'Payments are recorded from the order confirmation until the order is closed.',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date > date('Y-m-d') => 'Enter the date the money reached the bank (not in the future).',
            preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount) !== 1 || GstCalculator::scaled($amount, 2) <= 0 => 'Enter the amount received in ₹.',
            $ref === '' || mb_strlen($ref) > 60 => 'Enter the bank reference (UTR / NEFT / RTGS / cheque number).',
            DomesticOrderRepository::bankReferenceUsed($ref) => 'This bank reference is already recorded. Check the payment is not entered twice.',
            mb_strlen($note) > 255 => 'The note is too long.',
            default => null,
        };
        // QA batch 2 (ORD-12): once the tax invoice is fixed, a receipt never takes the total paid above it.
        if ($error === null && $dom['invoice_total'] !== null) {
            $owed = GstCalculator::scaled((string) $dom['invoice_total'], 2) - GstCalculator::scaled(self::paidTotal($orderId), 2);
            if (GstCalculator::scaled($amount, 2) > $owed) {
                $error = 'This is more than the buyer still owes on tax invoice ' . $dom['tax_invoice_no'] . ' (₹' . GstCalculator::money(max(0, $owed)) . ').';
            }
        }
        if ($error !== null) {
            return $error;
        }
        $description = self::paymentDescription($order, $dom, $amount, $ref, $note);
        DomesticOrderRepository::addPayment($orderId, $date, $amount, $ref, $description, $note !== '' ? $note : null, $userId);
        AuditLogRepository::log($userId, 'DOMESTIC_PAYMENT_RECORDED', 'orders', $orderId, 'amount_inr', null, $amount);
        return null;
    }

    public static function paidTotal(int $orderId): string
    {
        $paise = 0;
        foreach (DomesticOrderRepository::payments($orderId) as $p) {
            $paise += GstCalculator::scaled((string) $p['amount_inr'], 2);
        }
        return GstCalculator::money($paise);
    }

    // ------------------------------------------------------------ stages

    /**
     * What still stops the order moving on from its stage (empty = it may move on).
     *
     * @return list<string>
     */
    public static function blockers(int $orderId): array
    {
        $dom = DomesticOrderRepository::find($orderId);
        if ($dom === null) {
            return ['Order not found.'];
        }
        $lines = DomesticOrderRepository::lines($orderId);
        $has = static fn (string $code): bool => DomesticOrderRepository::latestDocument($orderId, $code) !== null;
        $out = [];
        switch ($dom['stage']) {
            case 'enquiry':
                if ($lines === []) {
                    $out[] = 'Add at least one line.';
                }
                break;
            case 'quotation':
                if (!$has('DQT')) {
                    $out[] = 'Generate the quotation.';
                }
                break;
            case 'order_confirmation':
                if (empty($dom['buyer_gstin'])) {
                    $out[] = 'Enter the buyer\'s GSTIN (a reseller buys on its GST registration).';
                }
                if (!$has('DOC')) {
                    $out[] = 'Generate the order confirmation.';
                }
                break;
            case 'purchase':
                if (self::codeOfOrder($orderId) === 'IMP') {
                    // D-257 Phase 3: the goods come out of IMP's stock when the tax invoice is fixed.
                    $order = OrderRepository::find($orderId);
                    array_push($out, ...ImportService::stockShortfalls((int) $order['division_id'], $lines));
                } elseif (empty($dom['supplier_po_ref'])) {
                    $out[] = 'Record the supplier PO.';
                }
                if ($lines === []) {
                    $out[] = 'Add at least one line.';
                }
                break;
            case 'dispatch':
                if (empty($dom['dispatch_date']) || empty($dom['vehicle_no'])) {
                    $out[] = 'Record the dispatch (date, transporter and vehicle).';
                }
                if (self::needsEwayBill($dom) && empty($dom['eway_bill_no'])) {
                    $out[] = 'Enter the e-way bill number: the consignment is above ₹' . GstCalculator::inr(self::setting('dom_eway_threshold_inr')) . '.';
                }
                if (!$has('GTI')) {
                    $out[] = 'Generate the GST tax invoice.';
                }
                if (!$has('DCH')) {
                    $out[] = 'Generate the delivery challan.';
                }
                break;
            case 'tax_invoice':
                if (empty($dom['invoice_sent_on'])) {
                    $out[] = 'Mark the tax invoice as sent to the buyer.';
                }
                break;
            case 'payment':
                $due = GstCalculator::scaled((string) $dom['invoice_total'], 2) - GstCalculator::scaled(self::paidTotal($orderId), 2);
                if ($due > 100) { // ₹1 tolerance, as for every other receipt
                    $out[] = 'Still to receive: ₹' . GstCalculator::inr(GstCalculator::money($due)) . '.';
                }
                break;
            case 'closed':
                $out[] = 'The order is closed.';
                break;
        }
        return $out;
    }

    /** Moves the order to its next stage when nothing stops it. Returns null or the reason it cannot. */
    public static function advance(int $orderId, string $expectedStage, int $userId): ?string
    {
        $dom = DomesticOrderRepository::find($orderId);
        if ($dom === null) {
            return 'Order not found.';
        }
        if ($dom['stage'] !== $expectedStage) {
            return 'Someone has already moved this order on. The page shows where it is now.';
        }
        $blockers = self::blockers($orderId);
        if ($blockers !== []) {
            return implode(' ', $blockers);
        }
        $keys = array_keys(self::STAGES);
        $next = $keys[self::stageIndex((string) $dom['stage']) + 1];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (!DomesticOrderRepository::moveStage($orderId, (string) $dom['stage'], $next, $userId)) {
                $pdo->rollBack();
                return 'Someone has already moved this order on. The page shows where it is now.';
            }
            if ($next === 'dispatch') {
                self::fixTaxInvoice($orderId, $dom, $userId);
                // D-257 Phase 3: an Import & Distribution sale takes its goods out of stock now (locked, never below zero).
                $order = OrderRepository::find($orderId);
                if (self::codeOfDivision((int) $order['division_id']) === 'IMP') {
                    $stockError = ImportService::issueForSale((int) $order['division_id'], $orderId, (string) $order['order_reference'], DomesticOrderRepository::lines($orderId), $userId);
                    if ($stockError !== null) {
                        $pdo->rollBack();
                        return $stockError;
                    }
                }
            }
            if ($next === 'closed') {
                $pdo->prepare("UPDATE orders SET status = 'complete', is_locked = 1 WHERE id = :id")->execute(['id' => $orderId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditLogRepository::log($userId, 'DOMESTIC_STAGE_MOVED', 'orders', $orderId, 'stage', (string) $dom['stage'], $next);
        return null;
    }

    /** At dispatch the tax invoice number is fixed (DOM/26-27/0001) and the GST totals frozen. */
    private static function fixTaxInvoice(int $orderId, array $dom, int $userId): void
    {
        $date = date('Y-m-d');
        $fy = FinancialYear::label($date); // 2026-27
        $short = substr($fy, 2, 2) . '-' . substr($fy, 5, 2); // 26-27
        $code = self::codeOfOrder($orderId);
        $number = $code . '/' . $short . '/' . str_pad((string) DomesticOrderRepository::nextNumber(self::series($code, 'GTI'), $fy), 4, '0', STR_PAD_LEFT);
        $t = self::totals($orderId);
        DomesticOrderRepository::update($orderId, [
            'tax_invoice_no' => $number,
            'tax_invoice_date' => $date,
            'taxable_total' => $t['taxable_total'],
            'cgst_total' => $t['cgst_total'],
            'sgst_total' => $t['sgst_total'],
            'igst_total' => $t['igst_total'],
            'round_off' => $t['round_off'],
            'invoice_total' => $t['invoice_total'],
        ], $userId);
        // D-262: the company details the invoice prints are kept with it.
        \App\Config\Database::connection()->prepare('UPDATE domestic_orders SET company_snapshot_json = :j WHERE order_id = :o AND company_snapshot_json IS NULL')
            ->execute(['j' => OrderSnapshotService::companyValuesJson(), 'o' => $orderId]);
    }

    /** A reference for a DOM document: DOM/QT/26-27/0001 (the tax invoice uses its own fixed number). */
    public static function documentReference(int $orderId, string $code): string
    {
        if ($code === 'GTI') {
            return (string) (DomesticOrderRepository::find($orderId)['tax_invoice_no'] ?? '');
        }
        $existing = DomesticOrderRepository::latestDocument($orderId, $code);
        if ($existing !== null) {
            return (string) $existing['reference']; // a regenerated document keeps its number
        }
        $fy = FinancialYear::current();
        $short = substr($fy, 2, 2) . '-' . substr($fy, 5, 2);
        $series = ['DQT' => 'QT', 'DOC' => 'OC', 'DCH' => 'DC'][$code];
        $division = self::codeOfOrder($orderId);
        return $division . '/' . $series . '/' . $short . '/' . str_pad((string) DomesticOrderRepository::nextNumber(self::series($division, $series), $fy), 4, '0', STR_PAD_LEFT);
    }

    /** Whether a document may be generated now (its stage reached, not after closing). */
    public static function documentAllowed(array $dom, string $code): bool
    {
        return isset(self::DOCUMENT_FROM[$code]) && self::atOrAfter((string) $dom['stage'], self::DOCUMENT_FROM[$code]) && $dom['stage'] !== 'closed';
    }
}
