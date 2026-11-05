<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\LookupRepository;

/**
 * CA / Accounting module — the Zoho Books connection. It sends what the
 * app owns to Books (Commercial Invoices and Debit Notes, buyer and LC
 * payments, suppliers' and logistics partners' bills and the payments to
 * them, order expenses, order costs and export benefit journals), reads
 * them back so ZohoRestoreService can put back anything changed or
 * deleted there, and imports the expenses the CA books in Books (owner
 * decisions 2026-10-29; see docs/SOP/ca-03-zoho-sync.md) — all via the
 * same OAuth self-client refresh-token flow (same shape as
 * ZohoMailService, a separate credential set since Zoho Mail and Zoho
 * Books are different API scopes). Every public method here either
 * returns a result or throws — CaSyncService is the one place that
 * catches those throws, logs them to zoho_sync_log, and moves on to the
 * next item, so this class is free to fail loudly and correctly on any
 * bad response.
 *
 * Zoho integration Stage 1 (2026-10-29): NexaCrest's Zoho One account
 * lives in Zoho's Indian data centre, so accounts.zoho.in /
 * www.zohoapis.in are the defaults. A buyer is created in Books as an
 * "overseas" customer in the order's own currency, and a payment goes in
 * as the foreign amount at the actual rate (INR actual / foreign amount),
 * so Books treats the sale as an export and the bank gets the INR actual.
 * Every Zoho change made while building this is logged in
 * docs/zoho/zoho-change-log.csv.
 */
final class ZohoBooksService
{
    /** @var (callable(string, string, array, array, bool): array{status:int, body:string})|null tests swap in a fake Zoho; null = real HTTP */
    private static $transport = null;

    /** @var array<string, array{token: string, expires: int}> access tokens by refresh token — Zoho limits how many may be issued, so one run reuses one */
    private static array $tokens = [];
    /** @var array<string, array{0:string,1:string,2:string,3:string}> token => how to refresh it (D-239: re-fetch on 401) */
    private static array $tokenOwners = [];
    /** @var array<string, bool> whether each Books organisation is GST-registered */
    private static array $gstRegistered = [];
    /** @var array<string, string> each GST-registered Books organisation's own GSTIN */
    private static array $orgGstin = [];
    /** @var array<string, array<string, string>> tax id by tax name (GST18, IGST18, ...), per organisation */
    private static array $taxes = [];

    /** Tests only: answer every Zoho request with $transport instead of the network (null restores real HTTP). */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
        self::$tokens = [];
        self::$tokenOwners = [];
        // Tests: a fresh fake Zoho starts with no shared token and no calls counted (D-239).
        \App\Config\Database::connection()->exec('DELETE FROM zoho_token_cache');
        \App\Config\Database::connection()->exec('DELETE FROM zoho_api_usage');
        ZohoApiBudget::setTestLimits($transport !== null ? 1000000 : null, $transport !== null ? 1000000 : null);
        self::$gstRegistered = [];
        self::$orgGstin = [];
        self::$taxes = [];
        self::$accounts = [];
    }

    /** On only when this server allows Zoho (.env, ZohoGuard) and the zoho_books_enabled setting is ticked. */
    public static function isEnabled(): bool
    {
        return ZohoGuard::integrationEnabled() && (string) CompanySettingsRepository::get('zoho_books_enabled') === '1';
    }

    /**
     * Pushes one settlement leg as a Zoho Books Customer Payment against
     * the order's client (creating the Zoho contact on first use, cached
     * on clients.zoho_contact_id after). A foreign-currency leg goes in as
     * its foreign amount at the actual rate (INR actual / foreign amount),
     * so the deposit account receives exactly the INR actual.
     *
     * Stage 2: with $invoiceNumber (the leg's invoice already in Books),
     * the payment is applied to it straight away, up to what the invoice
     * still owes; without, it stays unapplied (an advance held until the
     * CI is in Books) and applyPaymentToInvoice() applies it later.
     *
     * @throws \RuntimeException on any missing config, HTTP failure, or unexpected response
     * @return array{payment_id: string, applied: float} the Zoho-side customerpayment_id and the amount applied to the invoice
     */
    public static function pushRevenuePayment(
        int $clientId,
        string $companyLegalName,
        string $currencyCode,
        ?float $foreignAmount,
        float $amountInr,
        string $date,
        string $referenceNumber,
        ?string $invoiceNumber = null,
        float $bankChargesInr = 0.0,
        string $description = ''
    ): array {
        ZohoGuard::assertCustomerAllowed($companyLegalName);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $contactId = self::findOrCreateContact($config, $accessToken, $clientId, $companyLegalName, $currencyCode);
        $payload = self::paymentPayload($config, $contactId, $currencyCode, $foreignAmount, $amountInr, $date, $referenceNumber, $bankChargesInr, $description);
        $applied = 0.0;
        if ($invoiceNumber !== null) {
            [$payload['invoices'], $applied] = self::invoiceApplication($config, $accessToken, $invoiceNumber, $contactId, (float) $payload['amount']);
        }

        $response = self::request(
            'POST',
            "https://{$config['apiDomain']}/books/v3/customerpayments?organization_id=" . rawurlencode($config['organizationId']),
            $payload,
            self::authHeaders($accessToken)
        );

        $decoded = json_decode($response['body'], true);
        $paymentId = $decoded['payment']['payment_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($paymentId)) {
            throw new \RuntimeException('Zoho Books customer payment failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }

        return ['payment_id' => $paymentId, 'applied' => $applied];
    }

    /**
     * Stage 2: applies a payment already in Books (pushed unapplied — the
     * advance, held until the CI arrived) to its invoice, up to what the
     * invoice still owes. Books needs the whole payment re-sent with the
     * invoice added, so the same leg details are passed again.
     *
     * @throws \RuntimeException on any failure
     * @return float the amount applied (in the order's currency)
     */
    public static function applyPaymentToInvoice(
        string $paymentId,
        int $clientId,
        string $companyLegalName,
        string $currencyCode,
        ?float $foreignAmount,
        float $amountInr,
        string $date,
        string $referenceNumber,
        string $invoiceNumber,
        float $bankChargesInr = 0.0,
        string $description = ''
    ): float {
        ZohoGuard::assertCustomerAllowed($companyLegalName);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $contactId = self::findOrCreateContact($config, $accessToken, $clientId, $companyLegalName, $currencyCode);
        $payload = self::paymentPayload($config, $contactId, $currencyCode, $foreignAmount, $amountInr, $date, $referenceNumber, $bankChargesInr, $description);
        [$payload['invoices'], $applied] = self::invoiceApplication($config, $accessToken, $invoiceNumber, $contactId, (float) $payload['amount']);
        if ($applied <= 0) {
            throw new \RuntimeException("Invoice {$invoiceNumber} in Zoho Books has nothing left to pay, so payment {$referenceNumber} stays unapplied there — ask the CA how to book it.");
        }

        $response = self::request(
            'PUT',
            "https://{$config['apiDomain']}/books/v3/customerpayments/" . rawurlencode($paymentId) . '?organization_id=' . rawurlencode($config['organizationId']),
            $payload,
            self::authHeaders($accessToken)
        );
        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !isset($decoded['payment'])) {
            throw new \RuntimeException('Zoho Books payment update failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return $applied;
    }

    /**
     * Stage 2: creates or updates the Books invoice for an order's
     * Commercial Invoice or freight Debit Note (ZohoInvoiceData::build()),
     * numbered with our own document reference, and marks it as sent (no
     * email goes out) so payments can be applied to it. The invoice is
     * always looked up by number first, so a retry never makes a second
     * one — and an invoice with that number on another customer is refused.
     *
     * @param array<string, mixed> $invoice ZohoInvoiceData::build()
     * @throws \RuntimeException on any failure
     * @return array{zoho_invoice_id: string, zoho_invoice_number: string, status: string, created: bool}
     */
    public static function pushInvoice(array $invoice): array
    {
        ZohoGuard::assertCustomerAllowed((string) $invoice['company_legal_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $contactId = self::findOrCreateContact($config, $accessToken, (int) $invoice['client_id'], (string) $invoice['company_legal_name'], (string) $invoice['currency_code']);
        $gst = self::isGstRegistered($config, $accessToken);

        $lineItems = [];
        foreach ($invoice['lines'] as $line) {
            $item = ['name' => $line['name'], 'description' => $line['description'], 'quantity' => $line['quantity'], 'rate' => $line['rate']];
            // Owner decision 2 Nov 2026 (D-240): a line picked from a product already in Books is linked to its item.
            if (!empty($line['item_id'])) {
                $item['item_id'] = (string) $line['item_id'];
            }
            if ($line['unit'] !== null) {
                $item['unit'] = $line['unit'];
            }
            if ($line['hs_code'] !== null) {
                // A Books organisation that is not GST-registered rejects
                // hsn_or_sac (seen in the test organisation, Z-028), so
                // there the HS code goes into the description instead.
                if ($gst) {
                    $item['hsn_or_sac'] = $line['hs_code'];
                } else {
                    $item['description'] .= '. HS code ' . $line['hs_code'];
                }
            }
            $lineItems[] = $item;
        }
        $payload = [
            'customer_id' => $contactId,
            'invoice_number' => $invoice['invoice_number'],
            'date' => $invoice['date'],
            'reference_number' => $invoice['reference_number'],
            'line_items' => $lineItems,
        ];
        if ($invoice['due_date'] !== null) {
            $payload['due_date'] = $invoice['due_date'];
        }
        if ($invoice['currency_code'] !== 'INR') {
            $payload['exchange_rate'] = round((float) $invoice['exchange_rate'], 6);
        }
        if ($gst) {
            $payload['gst_treatment'] = 'overseas';
        }
        // D-257 Phase 2: the Division reporting tag, only once two or more divisions are active (none today).
        if (($tags = self::divisionTags(self::orderDivisionId($invoice['order_id'] ?? null))) !== []) {
            $payload['tags'] = $tags;
        }

        if ($gst && strlen((string) $invoice['invoice_number']) > 16) {
            // GST: a tax invoice number may have at most 16 characters (Books
            // refuses longer ones in a GST-registered organisation, Z-134).
            // Every reference has used the 2-digit year since 2026-10-29.
            throw new \RuntimeException(ZohoInvoiceData::KIND_LABELS[$invoice['kind']] . " {$invoice['invoice_number']} not sent: a GST invoice number may have at most 16 characters and this one has " . strlen((string) $invoice['invoice_number']) . '. Book it in Zoho Books by hand, or generate it again after the 2-digit-year change (SC/CI/26/...).');
        }
        $query = '?organization_id=' . rawurlencode($config['organizationId']) . '&ignore_auto_number_generation=true';
        $existing = self::findInvoiceByNumber($config, $accessToken, (string) $invoice['invoice_number']);
        if ($existing !== null && (string) $existing['customer_id'] !== $contactId) {
            throw new \RuntimeException("Invoice number {$invoice['invoice_number']} is already used in Zoho Books for " . ($existing['customer_name'] ?? 'another customer') . '. Nothing was changed.');
        }
        if ($existing !== null && (string) ($existing['status'] ?? '') !== 'draft') {
            // Books refuses to change a sent invoice without a reason
            // (code 110701, seen in the test organisation, Z-049); it keeps
            // the reason in the invoice's history.
            $payload['reason'] = 'Revised in the order system: ' . ZohoInvoiceData::KIND_LABELS[$invoice['kind']] . " {$invoice['invoice_number']} revision {$invoice['revision']} approved.";
        }
        // Zoho has been seen answering HTTP 500 with a cut-off body to a
        // create or update that did go through (test organisation, Z-029),
        // so when the response cannot be read the result is read back from
        // the invoice list (asked again if it lags behind the create).
        $response = $existing === null
            ? self::request('POST', "https://{$config['apiDomain']}/books/v3/invoices{$query}", $payload, self::authHeaders($accessToken))
            : self::request('PUT', "https://{$config['apiDomain']}/books/v3/invoices/" . rawurlencode((string) $existing['invoice_id']) . $query, $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        $saved = is_array($decoded) && ($decoded['code'] ?? null) === 0 && isset($decoded['invoice']['invoice_id']) && (string) ($decoded['invoice']['invoice_number'] ?? '') === (string) $invoice['invoice_number']
            ? $decoded['invoice']
            : null;
        $saved ??= self::awaitInvoiceByNumber($config, $accessToken, (string) $invoice['invoice_number']);
        if ($saved === null || abs((float) $saved['total'] - (float) $invoice['total']) > 0.01) {
            throw new \RuntimeException('Zoho Books invoice ' . ($existing === null ? 'creation' : 'update') . " for {$invoice['invoice_number']} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }

        $status = (string) ($saved['status'] ?? '');
        if ($status === 'draft') {
            $sent = self::request('POST', "https://{$config['apiDomain']}/books/v3/invoices/" . rawurlencode((string) $saved['invoice_id']) . '/status/sent?organization_id=' . rawurlencode($config['organizationId']), [], self::authHeaders($accessToken));
            if ($sent['status'] < 200 || $sent['status'] >= 300) {
                throw new \RuntimeException("Zoho Books invoice {$invoice['invoice_number']} was saved but could not be marked as sent (HTTP " . $sent['status'] . '): ' . substr($sent['body'], 0, 500));
            }
            $status = 'sent';
        }

        // Owner rule D-212: what the invoice is, as an internal comment in
        // Books (comments never print on Zoho's own invoice PDF, and our
        // own documents are unchanged). Added once, when Books first gets
        // the invoice; a failure here does not undo the invoice.
        if ($existing === null && !empty($invoice['summary'])) {
            try {
                self::request('POST', "https://{$config['apiDomain']}/books/v3/invoices/" . rawurlencode((string) $saved['invoice_id']) . '/comments?organization_id=' . rawurlencode($config['organizationId']),
                    ['description' => (string) $invoice['summary'], 'show_comment_to_clients' => false], self::authHeaders($accessToken));
            } catch (\Throwable $e) {
                ZohoSyncStop::rethrow($e); // D-239: a rate limit still stops the run
                // the invoice itself is in Books; the comment is a courtesy
            }
        }

        return ['zoho_invoice_id' => (string) $saved['invoice_id'], 'zoho_invoice_number' => (string) $saved['invoice_number'], 'status' => $status, 'created' => $existing === null];
    }

    /**
     * Stage 3: which Books account a bill or an expense is booked to, by
     * account name (looked up in the organisation's chart of accounts).
     * Defaults chosen 2026-10-29 for the CA to confirm.
     */
    public const BILL_ACCOUNTS = [
        'supplier'      => 'Cost of Goods Sold',
        'cha'           => 'Transportation Expense',
        'transport'     => 'Transportation Expense',
        'cha_transport' => 'Transportation Expense',
        'ocean_freight' => 'Transportation Expense',
        'other'         => 'Other Expenses',
    ];
    public const EXPENSE_ACCOUNTS = [
        'travel_fuel'      => 'Travel Expense',
        'travel_ticket'    => 'Travel Expense',
        'local_conveyance' => 'Travel Expense',
        'hotel'            => 'Travel Expense',
        'food'             => 'Travel Expense',
        'courier'          => 'Postage',
        'samples'          => 'Other Expenses',
        'other'            => 'Other Expenses',
    ];
    /** What an expense was paid from: the company account is the deposit account; these are found by name. */
    public const PAID_THROUGH_ACCOUNTS = ['company_card' => 'Company Card', 'staff' => 'Employee Reimbursements'];
    /** Owner decision 2026-10-29: the Books expense account for each order cost entry that goes to Books. */
    public const COST_ACCOUNTS = [
        'ecgc_insurance' => 'Insurance Expense',
        'due_diligence'  => 'Consultant Expense',
        'documentation'  => 'Export Documentation Charges',
        'commission'     => 'Commission Expense',
        'packing_crates' => 'Packing Material',
        'inspection_fee' => 'Inspection Charges',
        'courier'        => 'Postage',
        'other'          => 'Other Expenses',
    ];

    /** @var array<string, array<string, string>> account id by name, per organisation */
    private static array $accounts = [];

    /**
     * Stage 3: a supplier's or logistics partner's tax invoice as a Books
     * bill, under the vendor (created on first use), looked up by number
     * for that vendor first so a retry never makes a second one.
     *
     * In a GST-registered organisation the bill carries its GST: the
     * taxable value at the bill's own rate (GST / taxable: 5, 12, 18, 28
     * or 40 %) as IGST when the vendor's GSTIN is from another state, or
     * CGST + SGST (Books' GST group) when it is from NexaCrest's state —
     * compared by the first two digits of the two GSTINs. A bill without
     * GST from a registered vendor goes in at 0 %; from an unregistered
     * vendor, without tax. Refused with a message staff can act on: GST
     * from a vendor with no GSTIN in the app, a GST amount that is not one
     * of the GST rates of the taxable value. Not handled yet (CA to
     * decide): reverse charge and blocked input credit. In an organisation
     * that is not GST-registered the bill goes in at its total.
     *
     * With $zohoBillId (owner decision 2026-10-29: a bill changed directly
     * in Books) the same bill is put back to the app's values instead.
     *
     * @param array<string,mixed> $bill ZohoPurchaseRepository row
     * @throws \RuntimeException on any failure
     * @return string the Zoho bill_id
     */
    public static function pushBill(array $bill, ?string $zohoBillId = null): string
    {
        ZohoGuard::assertCustomerAllowed((string) $bill['buyer_name']);
        self::assertVendorAllowed((string) $bill['vendor_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $line = self::billTax($config, $accessToken, $bill);
        $vendorId = self::findOrCreateVendor($config, $accessToken, $bill);
        $existing = $zohoBillId === null ? self::findBill($config, $accessToken, (string) $bill['bill_no'], $vendorId) : null;
        if ($existing !== null) {
            return (string) $existing['bill_id'];
        }

        $forKey = $bill['vendor_kind'] === 'supplier' ? 'supplier' : (string) $bill['bill_for'];
        $what = $bill['vendor_kind'] === 'supplier'
            ? 'Supplier ' . ($bill['bill_for'] === 'balance' ? 'balance' : 'advance') . ' bill'
            : (\App\Repositories\OrderVendorBillRepository::LOGISTICS_SERVICES[$bill['bill_for']] ?? 'Logistics') . ' bill';
        $payload = [
            'vendor_id' => $vendorId,
            'bill_number' => (string) $bill['bill_no'],
            'date' => (string) $bill['bill_date'],
            'reference_number' => (string) $bill['buyer_inquiry_ref'],
            'line_items' => [[
                'account_id' => self::accountId($config, $accessToken, self::BILL_ACCOUNTS[$forKey] ?? self::BILL_ACCOUNTS['other']),
                'name' => $what,
                // Owner rule D-212: a supplier bill also names the Supplier PO it is billed against.
                'description' => "{$what} for order {$bill['buyer_inquiry_ref']}" . ($bill['vendor_kind'] === 'supplier' && !empty($bill['supplier_po_reference']) ? " (Supplier PO {$bill['supplier_po_reference']})" : '') . ": taxable " . number_format((float) $bill['taxable_inr'], 2, '.', '') . ' + GST ' . number_format((float) $bill['gst_inr'], 2, '.', '') . (!empty($bill['note']) ? ". {$bill['note']}" : ''),
                'rate' => $line['rate'],
                'quantity' => 1,
            ] + ($line['tax_id'] !== null ? ['tax_id' => $line['tax_id']] : [])
              + ($line['reverse_charge_tax_id'] !== null ? ['reverse_charge_tax_id' => $line['reverse_charge_tax_id']] : [])],
        ] + ($line['reverse_charge_tax_id'] !== null ? ['is_reverse_charge_applied' => true] : []);
        if ($zohoBillId !== null) {
            $response = self::request('PUT', "https://{$config['apiDomain']}/books/v3/bills/" . rawurlencode($zohoBillId) . '?organization_id=' . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException("Zoho Books bill update for {$bill['bill_no']} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            return $zohoBillId;
        }
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/bills?organization_id=" . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        $saved = self::findBill($config, $accessToken, (string) $bill['bill_no'], $vendorId);
        if ($saved === null) {
            throw new \RuntimeException("Zoho Books bill creation for {$bill['bill_no']} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        if (abs((float) ($saved['total'] ?? $bill['total_inr']) - (float) $bill['total_inr']) > 1.0) {
            throw new \RuntimeException("Bill {$bill['bill_no']} was created in Zoho Books with total " . $saved['total'] . ', but the app has ' . $bill['total_inr'] . ' — check it in Books with the CA.');
        }
        return (string) $saved['bill_id'];
    }

    /**
     * Stage 3: one payment to a vendor as a Books vendor payment from the
     * deposit account. With $targetBill (the tax invoice already in
     * Books) it is applied to that bill, up to what the bill still owes;
     * without, it is held unapplied (an advance paid on a proforma) until
     * applyVendorPayment().
     *
     * @throws \RuntimeException on any failure
     * @return array{payment_id: string, applied: float}
     */
    public static function pushVendorPayment(array $payment, array $bill, ?array $targetBill): array
    {
        ZohoGuard::assertCustomerAllowed((string) $bill['buyer_name']);
        self::assertVendorAllowed((string) $bill['vendor_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $vendorId = self::findOrCreateVendor($config, $accessToken, $bill);
        $payload = self::vendorPaymentPayload($config, $vendorId, $payment, $bill);
        $applied = 0.0;
        if ($targetBill !== null) {
            [$payload['bills'], $applied] = self::billApplication($config, $accessToken, $targetBill, $vendorId, (float) $payload['amount']);
        }
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/vendorpayments?organization_id=" . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        $paymentId = $decoded['vendorpayment']['payment_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($paymentId)) {
            throw new \RuntimeException('Zoho Books vendor payment failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return ['payment_id' => $paymentId, 'applied' => $applied];
    }

    /**
     * Owner decision 2026-10-29: puts a vendor payment changed directly in
     * Books back to the app's values — applied in full to its bill when it
     * was applied before ($zohoBillId).
     *
     * @throws \RuntimeException on any failure
     */
    public static function restoreVendorPayment(array $payment, array $bill, ?string $zohoBillId): void
    {
        ZohoGuard::assertCustomerAllowed((string) $bill['buyer_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $vendorId = self::findOrCreateVendor($config, $accessToken, $bill);
        $payload = self::vendorPaymentPayload($config, $vendorId, $payment, $bill);
        if ($zohoBillId !== null) {
            $payload['bills'] = [['bill_id' => $zohoBillId, 'amount_applied' => $payload['amount']]];
        }
        $response = self::request('PUT', "https://{$config['apiDomain']}/books/v3/vendorpayments/" . rawurlencode((string) $payment['zoho_payment_id']) . '?organization_id=' . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Zoho Books vendor payment {$payment['payment_ref']} could not be put back (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
    }

    /**
     * Owner decision 2026-10-29: puts a customer payment changed directly in
     * Books back to the app's values (same amount, rate and bank charges as
     * sent) — applied in full to its invoice when it was applied before.
     *
     * @throws \RuntimeException on any failure
     */
    public static function restoreRevenuePayment(string $paymentId, int $clientId, string $companyLegalName, string $currencyCode, ?float $foreignAmount, float $amountInr, string $date, string $referenceNumber, ?string $zohoInvoiceId, float $bankChargesInr = 0.0, string $description = ''): void
    {
        ZohoGuard::assertCustomerAllowed($companyLegalName);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $contactId = self::findOrCreateContact($config, $accessToken, $clientId, $companyLegalName, $currencyCode);
        $payload = self::paymentPayload($config, $contactId, $currencyCode, $foreignAmount, $amountInr, $date, $referenceNumber, $bankChargesInr, $description);
        if ($zohoInvoiceId !== null) {
            $payload['invoices'] = [['invoice_id' => $zohoInvoiceId, 'amount_applied' => $payload['amount']]];
        }
        $response = self::request('PUT', "https://{$config['apiDomain']}/books/v3/customerpayments/" . rawurlencode($paymentId) . '?organization_id=' . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Zoho Books payment {$referenceNumber} could not be put back (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
    }

    /**
     * Stage 3: applies a vendor payment held unapplied (paid on a proforma)
     * to the tax invoice that replaced it, now that it is in Books.
     *
     * @throws \RuntimeException on any failure
     * @return float the amount applied
     */
    public static function applyVendorPayment(array $payment, array $bill, array $targetBill): float
    {
        ZohoGuard::assertCustomerAllowed((string) $bill['buyer_name']);
        self::assertVendorAllowed((string) $bill['vendor_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $vendorId = self::findOrCreateVendor($config, $accessToken, $bill);
        $payload = self::vendorPaymentPayload($config, $vendorId, $payment, $bill);
        [$payload['bills'], $applied] = self::billApplication($config, $accessToken, $targetBill, $vendorId, (float) $payload['amount']);
        if ($applied <= 0) {
            throw new \RuntimeException("Bill {$targetBill['bill_no']} in Zoho Books has nothing left to pay, so payment {$payment['payment_ref']} stays unapplied there — ask the CA how to book it.");
        }
        $response = self::request('PUT', "https://{$config['apiDomain']}/books/v3/vendorpayments/" . rawurlencode((string) $payment['zoho_payment_id']) . '?organization_id=' . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !isset($decoded['vendorpayment'])) {
            throw new \RuntimeException('Zoho Books vendor payment update failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return $applied;
    }

    /**
     * Stage 3: one order expense as a Books expense, paid through the
     * deposit account (company account), "Company Card" or "Employee
     * Reimbursements" (paid by staff). Its description starts with the
     * buyer's name and order reference, so a TEST- order's expenses are
     * found by the clean-up script.
     *
     * With $zohoExpenseId (owner decision 2026-10-29: an expense changed
     * directly in Books) the same expense is put back to the app's values.
     *
     * @throws \RuntimeException on any failure
     * @return string the Zoho expense_id
     */
    public static function pushExpense(array $expense, ?string $zohoExpenseId = null): string
    {
        ZohoGuard::assertCustomerAllowed((string) $expense['buyer_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $paidThrough = $expense['paid_by'] === 'company_account'
            ? $config['depositAccountId']
            : self::accountId($config, $accessToken, self::PAID_THROUGH_ACCOUNTS[$expense['paid_by']] ?? 'Employee Reimbursements');
        $label = \App\Repositories\OrderExpenseRepository::CATEGORIES[$expense['category']] ?? 'Expense';
        $payload = [
            'account_id' => self::accountId($config, $accessToken, self::EXPENSE_ACCOUNTS[$expense['category']] ?? self::EXPENSE_ACCOUNTS['other']),
            'paid_through_account_id' => $paidThrough,
            'amount' => round((float) $expense['amount_inr'], 2),
            'date' => (string) $expense['expense_date'],
            'reference_number' => "{$expense['buyer_inquiry_ref']}-EXP-{$expense['id']}",
            'description' => "{$expense['buyer_name']} / {$expense['buyer_inquiry_ref']}: {$label} — {$expense['details']}" . ($expense['paid_by'] === 'staff' && !empty($expense['staff_name']) ? " (paid by {$expense['staff_name']})" : ''),
        ];
        $url = "https://{$config['apiDomain']}/books/v3/expenses" . ($zohoExpenseId !== null ? '/' . rawurlencode($zohoExpenseId) : '') . '?organization_id=' . rawurlencode($config['organizationId']);
        $response = self::request($zohoExpenseId !== null ? 'PUT' : 'POST', $url, $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        $expenseId = $decoded['expense']['expense_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($expenseId)) {
            throw new \RuntimeException('Zoho Books expense failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return $expenseId;
    }

    /**
     * Owner decision 2026-10-29: one order cost entry as a Books expense,
     * from the account it was paid from (company account = the deposit
     * account). With $zohoExpenseId (already in Books) the same expense is
     * updated, e.g. after the inspection fee was corrected.
     *
     * @param array<string,mixed> $cost ZohoPurchaseRepository::costEntriesToSync() row
     * @throws \RuntimeException on any failure
     * @return string the Zoho expense_id
     */
    public static function pushCostEntry(array $cost, ?string $zohoExpenseId = null): string
    {
        ZohoGuard::assertCustomerAllowed((string) $cost['buyer_name']);
        $label = \App\Repositories\OrderCostEntryRepository::CATEGORIES[$cost['category']] ?? 'Order cost';
        if (!isset(\App\Repositories\OrderCostEntryRepository::PAID_FROM[$cost['paid_from'] ?? ''])) {
            throw new \RuntimeException("Order cost \"{$label}\" (INR " . number_format((float) $cost['amount_inr'], 2, '.', '') . ') not sent: it does not say what it was paid from. Remove it and record it again with "Paid from".');
        }
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $payload = [
            'account_id' => self::accountId($config, $accessToken, self::COST_ACCOUNTS[$cost['category']] ?? self::COST_ACCOUNTS['other']),
            'paid_through_account_id' => $cost['paid_from'] === 'company_account' ? $config['depositAccountId'] : self::accountId($config, $accessToken, self::PAID_THROUGH_ACCOUNTS['company_card']),
            'amount' => round((float) $cost['amount_inr'], 2),
            'date' => (string) $cost['cost_date'],
            'reference_number' => "{$cost['buyer_inquiry_ref']}-COST-{$cost['id']}",
            'description' => "{$cost['buyer_name']} / {$cost['buyer_inquiry_ref']}: {$label}" . (!empty($cost['description']) ? " — {$cost['description']}" : ''),
        ];
        $url = "https://{$config['apiDomain']}/books/v3/expenses" . ($zohoExpenseId !== null ? '/' . rawurlencode($zohoExpenseId) : '') . '?organization_id=' . rawurlencode($config['organizationId']);
        $response = self::request($zohoExpenseId !== null ? 'PUT' : 'POST', $url, $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        $expenseId = $decoded['expense']['expense_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($expenseId)) {
            throw new \RuntimeException('Zoho Books expense for order cost "' . $label . '" failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return $expenseId;
    }

    /**
     * Owner decision 1 Nov 2026 (AS-12): an associate's paid commission goes
     * to Books as the associate's own bill (the associate a vendor, the bill
     * numbered with their invoice) and a vendor payment from the bank with
     * the UTR, applied to it — instead of an expense. The bill is the gross
     * commission; the payment is what was paid, so any TDS deducted stays on
     * the bill for the CA to book against the TDS payable account.
     *
     * @param array<string,mixed> $cost ZohoPurchaseRepository::costEntriesToSync() row, incentive_kind 'associate'
     * @throws \RuntimeException on any failure
     * @return string the Zoho bill_id
     */
    public static function pushAssociateCommission(array $cost): string
    {
        ZohoGuard::assertCustomerAllowed((string) $cost['buyer_name']);
        $c = self::associateCommission($cost);
        // Owner decision D-256 (5 Nov 2026): GST on the associate's tax invoice goes on the bill as a GST tax,
        // so the bill total equals what was paid and the input credit is booked — never a bill without it.
        if ($c['gst_inr'] > 0 && $c['gst_tax_id'] === null) {
            throw new \RuntimeException("Associate commission on order {$cost['buyer_inquiry_ref']} not sent: GST of INR " . number_format($c['gst_inr'], 2, '.', '')
                . ' was paid on the associate\'s tax invoice, but no Zoho GST tax is chosen for commission (Associates → Commission settings → Zoho GST tax for commission). Choose it, then sync again.');
        }
        self::assertVendorAllowed((string) $c['associate']['name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $vendorId = (string) ($c['associate']['zoho_contact_id'] ?? '');
        if ($vendorId === '') {
            $name = (string) $c['associate']['name'];
            $query = '?organization_id=' . rawurlencode($config['organizationId']);
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts{$query}&contact_type=vendor&contact_name=" . rawurlencode($name), 'contacts') as $row) {
                if ((string) ($row['contact_name'] ?? '') === $name) {
                    $vendorId = (string) $row['contact_id'];
                }
            }
            if ($vendorId === '') {
                $payload = ['contact_name' => $name, 'company_name' => $name, 'contact_type' => 'vendor'] + (!empty($c['associate']['pan']) ? ['pan_no' => (string) $c['associate']['pan']] : []);
                if (self::isGstRegistered($config, $accessToken)) {
                    $payload += !empty($c['associate']['gstin']) ? ['gst_treatment' => 'business_gst', 'gst_no' => (string) $c['associate']['gstin']]
                        : (($c['associate']['based_in'] ?? 'india') === 'india' ? ['gst_treatment' => 'business_none'] : ['gst_treatment' => 'overseas']);
                }
                $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/contacts{$query}", $payload, self::authHeaders($accessToken));
                $vendorId = (string) (json_decode($response['body'], true)['contact']['contact_id'] ?? '');
                if ($response['status'] < 200 || $response['status'] >= 300 || $vendorId === '') {
                    throw new \RuntimeException('Zoho Books vendor creation for associate ' . $name . ' failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
                }
            }
            \App\Repositories\AssociateRepository::setFields((int) $c['associate']['id'], ['zoho_contact_id' => $vendorId]);
        }
        $bill = self::findBill($config, $accessToken, $c['bill_no'], $vendorId);
        if ($bill === null) {
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/bills?organization_id=" . rawurlencode($config['organizationId']),
                self::associateBillPayload($c, $cost, $vendorId, self::accountId($config, $accessToken, self::COST_ACCOUNTS['commission'])), self::authHeaders($accessToken));
            $bill = self::findBill($config, $accessToken, $c['bill_no'], $vendorId);
            if ($bill === null) {
                throw new \RuntimeException("Zoho Books bill for the associate commission on order {$cost['buyer_inquiry_ref']} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
        }
        $openBalance = ($c['tds_tax_id'] !== null ? $c['paid_inr'] : $c['gross_inr']) + $c['gst_inr'];
        if ($c['paid_inr'] > 0 && (float) ($bill['balance'] ?? $openBalance) >= $openBalance - 0.005) {
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/vendorpayments?organization_id=" . rawurlencode($config['organizationId']),
                self::associatePaymentPayload($c, $vendorId, (string) $bill['bill_id'], $config['depositAccountId']), self::authHeaders($accessToken));
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_string(json_decode($response['body'], true)['vendorpayment']['payment_id'] ?? null)) {
                throw new \RuntimeException("Zoho Books vendor payment for the associate commission on order {$cost['buyer_inquiry_ref']} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
        }
        return (string) $bill['bill_id'];
    }

    /**
     * The Zoho bill for an associate's commission: the gross commission, with the TDS tax when one
     * is chosen and (D-256) the GST tax for commission when GST was paid on their tax invoice.
     */
    public static function associateBillPayload(array $c, array $cost, string $vendorId, string $accountId): array
    {
        return [
            'vendor_id' => $vendorId,
            'bill_number' => $c['bill_no'],
            'date' => $c['paid_on'],
            'reference_number' => (string) $cost['buyer_inquiry_ref'],
            'line_items' => [[
                'account_id' => $accountId,
                'name' => 'Associate commission',
                'description' => $c['bill_description'],
                'rate' => $c['gross_inr'],
                'quantity' => 1,
            ] + ($c['gst_inr'] > 0 ? ['tax_id' => $c['gst_tax_id']] : [])],
        ] + ($c['tds_tax_id'] !== null ? ['tds_tax_id' => $c['tds_tax_id']] : []);
    }

    /** D-256: the payment is what was paid — the commission after TDS plus the GST paid on top. */
    public static function associatePaymentPayload(array $c, string $vendorId, string $billId, mixed $paidThroughAccountId): array
    {
        $amount = round($c['paid_inr'] + $c['gst_inr'], 2);
        return [
            'vendor_id' => $vendorId,
            'amount' => $amount,
            'date' => $c['paid_on'],
            'payment_mode' => 'Bank Transfer',
            'reference_number' => $c['reference'],
            'description' => $c['payment_description'],
            'paid_through_account_id' => $paidThroughAccountId,
            'bills' => [['bill_id' => $billId, 'amount_applied' => $amount]],
        ];
    }

    /**
     * Owner rule D-212: what the associate commission bill and payment say —
     * the associate, the order (and buyer), their invoice,
     * the TDS, and the bank reference. Built from the facts.
     *
     * @return array{associate: array<string,mixed>, bill_no: string, paid_on: string, gross_inr: float, paid_inr: float, tds_inr: float, reference: string, bill_description: string, payment_description: string}
     */
    public static function associateCommission(array $cost): array
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND status = 'paid' AND amount_inr > 0 AND clawback_of_payout_id IS NULL ORDER BY id");
        $stmt->execute(['o' => $cost['order_id']]);
        $payouts = $stmt->fetchAll();
        if ($payouts === []) {
            throw new \RuntimeException("Associate commission on order {$cost['buyer_inquiry_ref']} not sent: no paid commission found.");
        }
        $a = \App\Repositories\AssociateRepository::find((int) $payouts[0]['associate_id']);
        if ($a === null) {
            throw new \RuntimeException("Associate commission on order {$cost['buyer_inquiry_ref']} not sent: the associate is missing.");
        }
        $inc = \App\Repositories\IncentiveRepository::forOrder((int) $cost['order_id']) ?? [];
        $invoices = [];
        foreach ($payouts as $p) {
            $inv = \App\Repositories\AssociateDocumentRepository::invoiceForPayout((int) $p['id']);
            if ($inv !== null && !empty($inv['reference'])) {
                $invoices[] = (string) $inv['reference'];
            }
        }
        $paid = round(array_sum(array_map(static fn (array $p): float => (float) $p['paid_inr'], $payouts)), 2);
        $tds = round(array_sum(array_map(static fn (array $p): float => (float) ($p['tds_inr'] ?? 0), $payouts)), 2);
        $refs = array_values(array_unique(array_filter(array_map(static fn (array $p): string => (string) ($p['paid_reference'] ?? ''), $payouts))));
        $paidOn = max(array_map(static fn (array $p): string => (string) $p['paid_on'], $payouts));
        $billNo = $invoices !== [] ? substr(implode('/', $invoices), 0, 50) : 'COMM-' . $cost['buyer_inquiry_ref'] . '-' . $payouts[0]['id'];
        // Owner decision 2 Nov 2026 (D-235): the rate and section the app worked out for the TDS.
        $tdsRate = null;
        $tdsSection = null;
        foreach ($payouts as $p) {
            if ((float) ($p['tds_inr'] ?? 0) > 0) {
                $tdsRate ??= isset($p['tds_rate']) ? (float) $p['tds_rate'] : null;
                $tdsSection ??= $p['tds_section'] ?? null;
            }
        }
        $tdsTaxId = \App\Services\AssociateTds::settings()['zoho_tax_id'];
        // Owner decision D-245 P3: the level fixed at PI, on the invoiced FOB of the goods.
        $orderRow = \App\Repositories\OrderRepository::find((int) $cost['order_id']);
        $fixedLevel = $orderRow !== null ? \App\Services\AssociateCommission::fixedLevel($orderRow, (int) $a['id']) : null;
        $rate = $fixedLevel !== null ? \App\Services\AssociateCommission::levelLabel($fixedLevel)
            : (isset($inc['associate_rate_pct']) ? rtrim(rtrim(number_format((float) $inc['associate_rate_pct'], 2, '.', ''), '0'), '.') . '%' : '');
        $gst = round(array_sum(array_map(static fn (array $p): float => (float) ($p['gst_inr'] ?? 0), $payouts)), 2);
        $what = "Associate commission — {$a['name']} — for order {$cost['buyer_inquiry_ref']} ({$cost['buyer_name']})"
            . ($rate !== '' ? ", {$rate} on the invoiced FOB of the goods" : '') . (!empty($inc['associate_basis_fc']) ? ' (basis ' . ($inc['associate_currency'] ?? '') . ' ' . number_format((float) $inc['associate_basis_fc'], 2, '.', '') . ')' : '')
            . ($gst > 0 ? ' · GST ' . number_format($gst, 2, '.', '') . ' on the associate\'s tax invoice (input credit)' : '');
        return [
            'associate' => $a,
            'bill_no' => $billNo,
            'paid_on' => $paidOn,
            'gross_inr' => round($paid + $tds, 2),
            'paid_inr' => $paid,
            'tds_inr' => $tds,
            'reference' => implode(', ', $refs),
            'bill_description' => $what . ($invoices !== [] ? ' · associate invoice ' . implode(', ', $invoices) : ' · no associate invoice on file') . ($tds > 0 ? ' · TDS ' . number_format($tds, 2, '.', '') . ' deducted' : ''),
            'payment_description' => \App\Services\AssociateTds::paymentDescription((string) $a['name'], [(string) $cost['buyer_inquiry_ref']], round($paid + $tds, 2), $tds, $tdsRate, $tdsSection, $paid, implode(', ', $refs), $gst) . ' · against bill ' . $billNo,
            // With a Zoho TDS tax set in Settings the bill carries the TDS (Zoho's TDS feature) and its balance is the net; otherwise the
            // bill is the gross, the payment the net, and the TDS is left on the bill for the CA to book.
            'tds_tax_id' => $tds > 0 && $tdsTaxId !== '' ? $tdsTaxId : null,
            'tds_left_for_ca' => $tds > 0 && $tdsTaxId === '',
            // D-256: GST paid on top on the associate's tax invoice, booked on the bill with this Zoho tax.
            'gst_inr' => $gst,
            'gst_tax_id' => $gst > 0 && ($gstTaxId = trim((string) (\App\Repositories\IncentiveRepository::settings()['zoho_gst_commission_tax_id'] ?? ''))) !== '' ? $gstTaxId : null,
        ];
    }

    /** Owner decision 2026-10-29: where an export benefit lands when received. */
    public const BENEFIT_INCOME_ACCOUNT = 'Export Incentive Income';
    public const BENEFIT_SCRIP_ACCOUNT = 'Export Incentive e-Scrips';

    /**
     * Owner decision 2026-10-29: an export benefit, when received, as a
     * Books journal — Duty Drawback paid into the bank (the deposit
     * account), RoDTEP / RoSCTL credited as e-scrips (an asset account),
     * against Export Incentive Income. Any other scheme is refused: the CA
     * books it by hand. With $journalId (already in Books) the same journal
     * is updated, e.g. after a corrected receipt.
     *
     * @param array<string,mixed> $benefit CaExportBenefitRepository::forZohoSync() row
     * @throws \RuntimeException on any failure
     * @return string the Zoho journal_id
     */
    public static function pushBenefitJournal(array $benefit, ?string $journalId = null): string
    {
        $scheme = (string) $benefit['scheme_name'];
        $what = "{$scheme} " . number_format((float) $benefit['received_amount'], 2, '.', '');
        if (!empty($benefit['buyer_name'])) {
            ZohoGuard::assertCustomerAllowed((string) $benefit['buyer_name']);
        }
        if (strtoupper((string) $benefit['currency_code']) !== 'INR') {
            throw new \RuntimeException("Export benefit {$what} not sent: it is recorded in {$benefit['currency_code']}, not rupees. Ask the CA to book it.");
        }
        $key = strtoupper(str_replace([' ', '-'], '', $scheme));
        $kind = match (true) {
            str_contains($key, 'DRAWBACK') => 'bank',
            in_array($key, ['RODTEP', 'ROSCTL'], true) => 'scrip',
            default => null,
        };
        if ($kind === null) {
            throw new \RuntimeException("Export benefit {$what} not sent: only Duty Drawback, RoDTEP and RoSCTL go to Zoho Books automatically. Ask the CA to book \"{$scheme}\" by hand.");
        }
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $amount = round((float) $benefit['received_amount'], 2);
        $orderRef = (string) ($benefit['buyer_inquiry_ref'] ?? '');
        $payload = [
            'journal_date' => (string) $benefit['received_at'],
            'reference_number' => ($orderRef !== '' ? "{$orderRef}-" : '') . 'BEN-' . $benefit['id'],
            'notes' => trim(($benefit['buyer_name'] ?? '') . ($orderRef !== '' ? " / {$orderRef}" : '') . ": {$scheme} received" . (!empty($benefit['reference_number']) ? " (claim {$benefit['reference_number']})" : '')
                . ' · ₹' . number_format($amount, 2) . (!empty($benefit['received_reference']) ? ($kind === 'bank' ? ' · bank ref ' : ' · e-scrip ') . $benefit['received_reference'] : ''), ' :'),
            'line_items' => [
                ['account_id' => $kind === 'bank' ? $config['depositAccountId'] : self::accountId($config, $accessToken, self::BENEFIT_SCRIP_ACCOUNT), 'debit_or_credit' => 'debit', 'amount' => $amount, 'description' => $kind === 'bank' ? "{$scheme} paid into the bank" : "{$scheme} e-scrip credited"],
                ['account_id' => self::accountId($config, $accessToken, self::BENEFIT_INCOME_ACCOUNT), 'debit_or_credit' => 'credit', 'amount' => $amount, 'description' => "{$scheme} received"],
            ],
        ];
        $url = "https://{$config['apiDomain']}/books/v3/journals" . ($journalId !== null ? '/' . rawurlencode($journalId) : '') . '?organization_id=' . rawurlencode($config['organizationId']);
        $response = self::request($journalId !== null ? 'PUT' : 'POST', $url, $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        $id = $decoded['journal']['journal_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || $id === null) {
            throw new \RuntimeException("Zoho Books journal for export benefit {$what} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return (string) $id;
    }

    /**
     * Owner decision 2026-10-31 (GST under LUT): the account the CA moves the
     * claimed input credit into when a refund claim is filed (an asset, set
     * up once in Zoho Books); a refund the bank credits is taken out of it.
     */
    public const GST_REFUND_ACCOUNT = 'GST Refund Receivable';

    /**
     * Owner decision 2026-10-31 (GST under LUT, option A): a GST refund the
     * bank credited, as a Books journal — the deposit account debited, GST
     * Refund Receivable credited — with the description the app builds
     * (rule D-212). A claim number starting TEST- marks test data: its notes
     * then start TEST- too, so the test clean-up finds it.
     *
     * @param array<string,mixed> $credit GstRefundRepository::creditsForZohoSync() row
     * @throws \RuntimeException on any failure
     * @return string the Zoho journal_id
     */
    public static function pushGstRefundJournal(array $credit): string
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $amount = round((float) $credit['amount'], 2);
        $description = PaymentDescription::gstRefund($credit);
        $test = str_starts_with((string) $credit['claim_arn'], ZohoGuard::TEST_PREFIX);
        $payload = [
            'journal_date' => (string) $credit['credited_on'],
            'reference_number' => 'GSTREF-' . $credit['claim_arn'] . '-' . $credit['id'],
            'notes' => ($test ? ZohoGuard::TEST_PREFIX . ' ' : '') . $description,
            'line_items' => [
                ['account_id' => $config['depositAccountId'], 'debit_or_credit' => 'debit', 'amount' => $amount, 'description' => $description],
                ['account_id' => self::accountId($config, $accessToken, self::GST_REFUND_ACCOUNT), 'debit_or_credit' => 'credit', 'amount' => $amount, 'description' => 'GST refund under LUT, claim ' . $credit['claim_arn']],
            ],
        ];
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/journals?organization_id=" . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        $id = $decoded['journal']['journal_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || $id === null) {
            throw new \RuntimeException('Zoho Books journal for the GST refund of claim ' . $credit['claim_arn'] . ' (₹' . number_format($amount, 2, '.', '') . ') failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return (string) $id;
    }

    /**
     * Clean-up before go-live: every Books journal whose notes start with
     * TEST- (export benefits of TEST- orders).
     *
     * @return list<array{journal_id: string, label: string}>
     */
    public static function listTestJournals(): array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $out = [];
        foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/journals?organization_id=" . rawurlencode($config['organizationId']), 'journals') as $row) {
            if (str_starts_with((string) ($row['notes'] ?? ''), ZohoGuard::TEST_PREFIX)) {
                $out[] = ['journal_id' => (string) $row['journal_id'], 'label' => trim(($row['notes'] ?? '') . ' INR ' . ($row['total'] ?? '') . ' on ' . ($row['journal_date'] ?? ''))];
            }
        }
        return $out;
    }

    /**
     * Owner decision 2026-10-29 (the per-order "App vs Zoho" check): reads
     * only. A contact's invoices and customer payments (a customer), or
     * bills and vendor payments (a vendor), from the Books lists.
     *
     * @return array<string, list<array<string,mixed>>> keyed invoices / customerpayments, or bills / vendorpayments
     */
    public static function contactRecords(string $contactId, bool $vendor): array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $org = 'organization_id=' . rawurlencode($config['organizationId']);
        $out = [];
        foreach ($vendor ? ['bills' => 'bills', 'vendorpayments' => 'vendorpayments'] : ['invoices' => 'invoices', 'customerpayments' => 'customerpayments'] as $path => $key) {
            $out[$key] = self::getAllPages($config, $accessToken, "https://{$config['apiDomain']}/books/v3/{$path}?{$org}&" . ($vendor ? 'vendor_id=' : 'customer_id=') . rawurlencode($contactId), $key);
        }
        return $out;
    }

    /**
     * Owner decision 2026-10-29 (the app is the source of truth for what it
     * sends): every Books expense and every journal, id => total, read in
     * full pages — so one sync reads each list once, not each record.
     *
     * @return array<string, float>
     */
    public static function totalsById(string $kind): array
    {
        [$path, $key, $idKey] = ['expense' => ['expenses', 'expenses', 'expense_id'], 'journal' => ['journals', 'journals', 'journal_id']][$kind];
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $out = [];
        foreach (self::getAllPages($config, $accessToken, "https://{$config['apiDomain']}/books/v3/{$path}?organization_id=" . rawurlencode($config['organizationId']), $key) as $row) {
            $out[(string) $row[$idKey]] = (float) ($row['total'] ?? 0);
        }
        return $out;
    }

    /**
     * Whether Books still has this invoice (looked up by its number, the
     * way the app finds invoices — the single-invoice read can fail). The
     * row, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function invoiceByNumber(string $invoiceNumber): ?array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        return self::findInvoiceByNumber($config, $accessToken, $invoiceNumber);
    }

    /**
     * One Books record by id — an expense, journal, bill, customer payment
     * or vendor payment (reads only). Null when Books no longer has it.
     *
     * @return array<string,mixed>|null
     */
    public static function readRecord(string $kind, string $id): ?array
    {
        [$path, $key] = ['expense' => ['expenses', 'expense'], 'journal' => ['journals', 'journal'], 'bill' => ['bills', 'bill'], 'customerpayment' => ['customerpayments', 'payment'], 'vendorpayment' => ['vendorpayments', 'vendorpayment']][$kind];
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $response = self::request('GET', "https://{$config['apiDomain']}/books/v3/{$path}/" . rawurlencode($id) . '?organization_id=' . rawurlencode($config['organizationId']), [], self::authHeaders($accessToken));
        if ($response['status'] === 404) {
            return null;
        }
        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !isset($decoded[$key])) {
            // Books answers an unknown id with an error code rather than 404 for some records.
            if (is_array($decoded) && in_array((int) ($decoded['code'] ?? 0), [1002, 4000, 4500, 1004], true)) {
                return null;
            }
            throw new \RuntimeException("Zoho Books {$kind} {$id} read failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 300));
        }
        return $decoded[$key];
    }

    /**
     * The "Test Books connection" button (Admin -> Settings): logs in with
     * the saved credentials and checks the organisation and the deposit
     * account exist, and which of this system's active currencies Books
     * has not turned on. Reads only — creates nothing in Zoho.
     *
     * @throws \RuntimeException on any missing config, login failure, unknown organisation or deposit account
     * @return array{organization: string, deposit_account: string, missing_currencies: list<string>}
     */
    public static function testConnection(): array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $organizations = self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/organizations", 'organizations');
        $organization = null;
        foreach ($organizations as $row) {
            if ((string) ($row['organization_id'] ?? '') === $config['organizationId']) {
                $organization = $row;
            }
        }
        if ($organization === null) {
            $seen = implode(', ', array_map(static fn (array $o): string => ($o['name'] ?? '?') . ' (' . ($o['organization_id'] ?? '?') . ')', $organizations));
            throw new \RuntimeException("Organization ID {$config['organizationId']} was not found. This Zoho login can see: " . ($seen !== '' ? $seen : 'no organisations') . '.');
        }

        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $accounts = self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/bankaccounts{$query}", 'bankaccounts');
        $deposit = null;
        foreach ($accounts as $row) {
            if ((string) ($row['account_id'] ?? '') === $config['depositAccountId']) {
                $deposit = $row;
            }
        }
        if ($deposit === null) {
            $seen = implode(', ', array_map(static fn (array $a): string => ($a['account_name'] ?? '?') . ' (' . ($a['account_id'] ?? '?') . ')', $accounts));
            throw new \RuntimeException("Deposit account ID {$config['depositAccountId']} was not found in Zoho Books. Its bank accounts are: " . ($seen !== '' ? $seen : 'none') . '.');
        }

        $booksCurrencies = array_map(static fn (array $c): string => (string) ($c['currency_code'] ?? ''), self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/settings/currencies{$query}", 'currencies'));
        $missing = [];
        foreach (LookupRepository::currencies() as $currency) {
            if (!in_array((string) $currency['code'], $booksCurrencies, true)) {
                $missing[] = (string) $currency['code'];
            }
        }

        return [
            'organization' => (string) ($organization['name'] ?? $config['organizationId']),
            'deposit_account' => (string) ($deposit['account_name'] ?? $config['depositAccountId']),
            'missing_currencies' => $missing,
        ];
    }

    /**
     * Every expense recorded in Zoho Books (Phase 4 — one-way import, the
     * reverse direction of pushRevenuePayment()). Reads only the Expenses
     * list endpoint's own summary fields — never a per-item detail call —
     * so an import with hundreds of expenses stays a handful of requests;
     * see ca-04-expenses.md for why Zoho's own TDS fields aren't read here.
     *
     * @throws \RuntimeException on any missing config, HTTP failure, or unexpected response
     * @return array<int, array{zohoExpenseId:string, category:string, description:?string, vendorName:?string, amount:float, currencyCode:string, expenseDate:string}>
     */
    public static function listExpenses(): array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $expenses = [];
        $page = 1;
        do {
            $response = self::request(
                'GET',
                "https://{$config['apiDomain']}/books/v3/expenses?organization_id=" . rawurlencode($config['organizationId']) . "&page={$page}&per_page=200",
                [],
                self::authHeaders($accessToken)
            );
            $decoded = json_decode($response['body'], true);
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || !isset($decoded['expenses'])) {
                throw new \RuntimeException('Zoho Books expense list failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }

            foreach ($decoded['expenses'] as $row) {
                if (empty($row['expense_id'])) {
                    continue;
                }
                $expenses[] = [
                    'zohoExpenseId' => (string) $row['expense_id'],
                    'category' => (string) ($row['account_name'] ?? 'Uncategorized'),
                    'description' => $row['description'] ?? null,
                    'vendorName' => $row['vendor_name'] ?? null,
                    'amount' => (float) ($row['total'] ?? 0),
                    'currencyCode' => (string) ($row['currency_code'] ?? 'INR'),
                    'expenseDate' => (string) ($row['date'] ?? date('Y-m-d')),
                    'referenceNumber' => (string) ($row['reference_number'] ?? ''),
                ];
            }

            $hasMore = (bool) ($decoded['page_context']['has_more_page'] ?? false);
            $page++;
        } while ($hasMore);

        return $expenses;
    }

    /** @return array{clientId:string, clientSecret:string, refreshToken:string, organizationId:string, depositAccountId:string, accountsDomain:string, apiDomain:string} */
    /**
     * Cleanup before go-live (cron/zoho_cleanup_test_records.php): every
     * Books customer whose name starts with TEST-, with its payments and
     * (Stage 2) its invoices.
     *
     * @return list<array{contact_id: string, name: string, payments: list<array{payment_id: string, label: string}>, invoices: list<array{invoice_id: string, label: string}>}>
     */
    public static function listTestContacts(): array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $query = '?organization_id=' . rawurlencode($config['organizationId']);

        $contacts = [];
        foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts{$query}&contact_name_startswith=TEST-", 'contacts') as $row) {
            $payments = [];
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/customerpayments{$query}&customer_id=" . rawurlencode((string) $row['contact_id']), 'customerpayments') as $payment) {
                $payments[] = [
                    'payment_id' => (string) $payment['payment_id'],
                    'label' => implode(' ', array_filter([(string) ($payment['reference_number'] ?? ''), (string) ($payment['currency_code'] ?? ''), (string) ($payment['amount'] ?? ''), 'on ' . ($payment['date'] ?? '')], static fn (string $part): bool => $part !== '')),
                ];
            }
            $invoices = [];
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/invoices{$query}&customer_id=" . rawurlencode((string) $row['contact_id']), 'invoices') as $invoice) {
                $invoices[] = [
                    'invoice_id' => (string) $invoice['invoice_id'],
                    'label' => implode(' ', array_filter([(string) ($invoice['invoice_number'] ?? ''), (string) ($invoice['currency_code'] ?? ''), (string) ($invoice['total'] ?? ''), 'on ' . ($invoice['date'] ?? '')], static fn (string $part): bool => $part !== '')),
                ];
            }
            // Stage 3: a TEST- vendor's payments and bills.
            $vendorPayments = [];
            $bills = [];
            if (($row['contact_type'] ?? '') === 'vendor') {
                foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/vendorpayments{$query}&vendor_id=" . rawurlencode((string) $row['contact_id']), 'vendorpayments') as $payment) {
                    $vendorPayments[] = ['payment_id' => (string) $payment['payment_id'], 'label' => trim(($payment['reference_number'] ?? '') . ' INR ' . ($payment['amount'] ?? '') . ' on ' . ($payment['date'] ?? ''))];
                }
                foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/bills{$query}&vendor_id=" . rawurlencode((string) $row['contact_id']), 'bills') as $bill) {
                    $bills[] = ['bill_id' => (string) $bill['bill_id'], 'label' => trim(($bill['bill_number'] ?? '') . ' INR ' . ($bill['total'] ?? '') . ' on ' . ($bill['date'] ?? ''))];
                }
            }
            $contacts[] = ['contact_id' => (string) $row['contact_id'], 'name' => (string) ($row['contact_name'] ?? ''), 'payments' => $payments, 'invoices' => $invoices, 'vendor_payments' => $vendorPayments, 'bills' => $bills];
        }
        return $contacts;
    }

    /**
     * Stage 3 clean-up: every Books expense whose description starts with
     * TEST- (an order expense's description starts with the buyer's name).
     *
     * @return list<array{expense_id: string, label: string}>
     */
    public static function listTestExpenses(): array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $out = [];
        $page = 1;
        do {
            $response = self::request('GET', "https://{$config['apiDomain']}/books/v3/expenses?organization_id=" . rawurlencode($config['organizationId']) . "&page={$page}&per_page=200", [], self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            if ($response['status'] < 200 || $response['status'] >= 300 || !isset($decoded['expenses'])) {
                throw new \RuntimeException('Zoho Books expense list failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            foreach ($decoded['expenses'] as $row) {
                if (str_starts_with((string) ($row['description'] ?? ''), ZohoGuard::TEST_PREFIX)) {
                    $out[] = ['expense_id' => (string) $row['expense_id'], 'label' => trim(($row['description'] ?? '') . ' INR ' . ($row['total'] ?? '') . ' on ' . ($row['date'] ?? ''))];
                }
            }
            $page++;
        } while ((bool) ($decoded['page_context']['has_more_page'] ?? false));
        return $out;
    }

    /**
     * Deletes one Books record made while testing — 'customerpayment',
     * 'invoice', 'vendorpayment', 'bill', 'expense' or 'contact' (payments,
     * then invoices and bills, then the contact: Books refuses to delete a
     * contact that still has transactions). A record already gone counts as
     * deleted, and returns false so the clean-up does not log a deletion that
     * never happened.
     *
     * @throws \RuntimeException on any other failure
     * @return bool true when Books deleted it, false when it was already gone
     */
    public static function deleteRecord(string $kind, string $id): bool
    {
        $paths = ['customerpayment' => 'customerpayments', 'invoice' => 'invoices', 'vendorpayment' => 'vendorpayments', 'bill' => 'bills', 'expense' => 'expenses', 'journal' => 'journals', 'contact' => 'contacts'];
        if (!isset($paths[$kind])) {
            throw new \InvalidArgumentException("Unknown Zoho Books record kind {$kind}.");
        }
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $response = self::request('DELETE', "https://{$config['apiDomain']}/books/v3/{$paths[$kind]}/" . rawurlencode($id) . '?organization_id=' . rawurlencode($config['organizationId']), [], self::authHeaders($accessToken));
        if ($response['status'] === 404) {
            return false;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Zoho Books {$kind} {$id} delete failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return true;
    }

    /**
     * Owner decision 2 Nov 2026 (D-238): a client pushed to Books as a
     * customer on its own (Settings → zoho_sync_clients), not only when its
     * first invoice syncs. Creates it when $contactId is null (linking an
     * existing Books customer of the same name instead of creating a
     * duplicate), otherwise updates it. The currency is set only on create —
     * a Books customer's currency cannot change.
     *
     * @param array{company_legal_name:string, billing_address_line1:?string, billing_address_line2:?string, billing_city:?string, billing_postcode:?string, billing_country:?string, vat_eori_tax_no:?string, contact_person:?string, email:?string, phone:?string} $client
     * @return array{contact_id:string, action:string} action = created | linked | updated
     */
    public static function pushCustomerContact(array $client, ?string $contactId, string $currencyCode): array
    {
        $name = (string) $client['company_legal_name'];
        ZohoGuard::assertCustomerAllowed($name);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $gst = self::isGstRegistered($config, $accessToken);
        $payload = self::customerPayload($client, $gst);
        $action = 'updated';
        if ($contactId === null) {
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts{$query}&contact_name=" . rawurlencode($name), 'contacts') as $row) {
                if ((string) ($row['contact_name'] ?? '') === $name && (string) ($row['contact_type'] ?? 'customer') === 'customer') {
                    $contactId = (string) $row['contact_id'];
                    $action = 'linked';
                    break;
                }
            }
        }
        if ($contactId === null) {
            $currencyId = null;
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/settings/currencies{$query}", 'currencies') as $row) {
                if (($row['currency_code'] ?? null) === $currencyCode) {
                    $currencyId = (string) $row['currency_id'];
                }
            }
            if ($currencyId === null) {
                throw new \RuntimeException("Currency {$currencyCode} is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.");
            }
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/contacts{$query}", $payload + ['contact_type' => 'customer', 'currency_id' => $currencyId], self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            $newId = $decoded['contact']['contact_id'] ?? null;
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($newId)) {
                throw new \RuntimeException('Zoho Books customer creation failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            return ['contact_id' => $newId, 'action' => 'created'];
        }
        $response = self::request('PUT', "https://{$config['apiDomain']}/books/v3/contacts/" . rawurlencode($contactId) . $query, $payload, self::authHeaders($accessToken));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Zoho Books customer update failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        // A customer marked inactive earlier is made active again when the client is active in the app.
        self::request('POST', "https://{$config['apiDomain']}/books/v3/contacts/" . rawurlencode($contactId) . '/active' . $query, [], self::authHeaders($accessToken));
        return ['contact_id' => $contactId, 'action' => $action];
    }

    /** @return array<string, mixed> the customer fields the app owns (never amounts or costs) */
    public static function customerPayload(array $client, bool $gstRegistered): array
    {
        $name = (string) $client['company_legal_name'];
        $address = array_filter([
            'address' => (string) ($client['billing_address_line1'] ?? ''),
            'street2' => (string) ($client['billing_address_line2'] ?? ''),
            'city' => (string) ($client['billing_city'] ?? ''),
            'zip' => (string) ($client['billing_postcode'] ?? ''),
            'country' => (string) ($client['billing_country'] ?? ''),
        ], static fn (string $v): bool => trim($v) !== '');
        $payload = ['contact_name' => $name, 'company_name' => $name, 'billing_address' => $address];
        $person = array_filter([
            'first_name' => mb_substr(trim((string) ($client['contact_person'] ?? '')), 0, 100),
            'email' => trim((string) ($client['email'] ?? '')),
            'phone' => trim((string) ($client['phone'] ?? '')),
        ], static fn (string $v): bool => $v !== '');
        if ($person !== []) {
            $payload['contact_persons'] = [$person + ['is_primary_contact' => true]];
        }
        $vat = trim((string) ($client['vat_eori_tax_no'] ?? ''));
        $payload['notes'] = 'Kept in step by the NexaCrest order system' . ($vat !== '' ? " · VAT / EORI / Tax Reg. No.: {$vat}" : '') . '.';
        if ($gstRegistered) {
            $payload['gst_treatment'] = 'overseas';
        }
        return $payload;
    }

    /**
     * Owner decision 2 Nov 2026 (D-238): a product of the Products menu as a
     * Books sales item. Rate 0 — the catalogue holds sourcing costs only,
     * never a selling price, and no cost is ever sent to Books.
     *
     * @param array{name:string, specifications:?string, hs_code:?string} $product
     * @return array{item_id:string, action:string} action = created | linked | updated
     */
    public static function pushItem(array $product, ?string $itemId): array
    {
        $name = mb_substr((string) $product['name'], 0, 100);
        if (!ZohoGuard::isProduction() && !str_starts_with($name, 'TEST-')) {
            throw new \RuntimeException("Not sent: ZOHO_ENVIRONMENT is test, and only products whose name starts with TEST- may go to Zoho (this one is \"{$name}\").");
        }
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $payload = self::itemPayload($product, self::isGstRegistered($config, $accessToken));
        $action = 'updated';
        if ($itemId === null) {
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/items{$query}&name=" . rawurlencode($name), 'items') as $row) {
                if ((string) ($row['name'] ?? '') === $name) {
                    $itemId = (string) $row['item_id'];
                    $action = 'linked';
                    break;
                }
            }
        }
        if ($itemId === null) {
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/items{$query}", $payload, self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            $newId = $decoded['item']['item_id'] ?? null;
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($newId)) {
                throw new \RuntimeException('Zoho Books item creation failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            return ['item_id' => $newId, 'action' => 'created'];
        }
        $response = self::request('PUT', "https://{$config['apiDomain']}/books/v3/items/" . rawurlencode($itemId) . $query, $payload, self::authHeaders($accessToken));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Zoho Books item update failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        self::request('POST', "https://{$config['apiDomain']}/books/v3/items/" . rawurlencode($itemId) . '/active' . $query, [], self::authHeaders($accessToken));
        return ['item_id' => $itemId, 'action' => $action];
    }

    /** @return array<string, mixed> the item fields: name, description, HS code, rate 0 — never a cost */
    public static function itemPayload(array $product, bool $gstRegistered): array
    {
        $description = mb_substr(trim((string) ($product['specifications'] ?? '')), 0, 2000);
        $hs = trim((string) ($product['hs_code'] ?? ''));
        // Owner decision D-245 P2: a product has no fixed selling price any more (each order's price is
        // built from its cost and the seller), so the item's rate is 0. Costs are never sent.
        $rate = 0;
        $payload = ['name' => mb_substr((string) $product['name'], 0, 100), 'rate' => $rate, 'item_type' => 'sales', 'product_type' => 'goods'];
        if ($hs !== '') {
            if ($gstRegistered) {
                $payload['hsn_or_sac'] = $hs;
            } else {
                $description = trim($description . ($description !== '' ? '. ' : '') . 'HS code ' . $hs);
            }
        }
        $payload['description'] = $description;
        return $payload;
    }

    /** Owner decision 2 Nov 2026 (D-238): a client or product no longer active in the app — inactive in Books, never deleted. */
    public static function markInactive(string $kind, string $id): void
    {
        $path = ['contact' => 'contacts', 'item' => 'items'][$kind] ?? null;
        if ($path === null) {
            throw new \InvalidArgumentException("Unknown kind {$kind}");
        }
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/{$path}/" . rawurlencode($id) . '/inactive?organization_id=' . rawurlencode($config['organizationId']), [], self::authHeaders($accessToken));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Zoho Books could not mark the {$kind} inactive (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
    }

    // ---------------------------------------------------------------- D-257 Phase 2: divisions and domestic sales

    private static function orderDivisionId(mixed $orderId): int
    {
        if ($orderId === null) {
            return DivisionService::EXPORT_ID;
        }
        $stmt = \App\Config\Database::connection()->prepare('SELECT division_id FROM orders WHERE id = :id');
        $stmt->execute(['id' => (int) $orderId]);
        return (int) ($stmt->fetchColumn() ?: DivisionService::EXPORT_ID);
    }

    /**
     * The "Division" reporting tag for a Books record — only when two or more
     * divisions are active (with one it says nothing) and the tag is set up in
     * Settings: zoho_division_tag_id, and zoho_division_tag_option_<code> for
     * each division (e.g. zoho_division_tag_option_dom). Otherwise none, so
     * nothing sent to Books changes.
     *
     * @return list<array{tag_id:string, tag_option_id:string}>
     */
    public static function divisionTags(int $divisionId): array
    {
        if (!DivisionService::isMulti()) {
            return [];
        }
        $division = DivisionService::find($divisionId);
        $tagId = trim((string) CompanySettingsRepository::companyValue('zoho_division_tag_id'));
        $option = $division !== null ? trim((string) CompanySettingsRepository::companyValue('zoho_division_tag_option_' . strtolower((string) $division['code']))) : '';
        return $tagId !== '' && $option !== '' ? [['tag_id' => $tagId, 'tag_option_id' => $option]] : [];
    }

    /**
     * A Domestic Trade GST tax invoice as a Books invoice (pure: no network).
     * Same state: each line at the GST group (GST18 = CGST + SGST); another
     * state: IGST18. gst_treatment business_gst with the buyer's GSTIN (an
     * unregistered buyer: business_none), place of supply as Books' state code.
     * Rule D-212: the notes say what the invoice is, for which order and buyer.
     *
     * @param callable(string): string $taxIdByName
     * @return array<string,mixed>
     */
    public static function domesticInvoicePayload(int $orderId, string $contactId, callable $taxIdByName): array
    {
        $order = \App\Repositories\OrderRepository::find($orderId);
        $dom = \App\Repositories\DomesticOrderRepository::find($orderId);
        if ($order === null || $dom === null || empty($dom['tax_invoice_no'])) {
            throw new \RuntimeException('This domestic order has no tax invoice yet.');
        }
        $t = DomesticOrderService::totals($orderId);
        $lines = [];
        foreach ($t['lines'] as $l) {
            $rate = DomesticDocumentService::trimDecimals((string) $l['gst_rate_pct']);
            $lines[] = [
                'name' => mb_substr((string) $l['description'], 0, 100),
                'description' => (string) $l['description'],
                'hsn_or_sac' => (string) $l['hsn_code'],
                'quantity' => (float) $l['quantity'],
                'unit' => (string) $l['unit'],
                'rate' => (float) $l['rate_inr'],
                'tax_id' => $taxIdByName(($t['intra_state'] ? 'GST' : 'IGST') . $rate),
            ];
        }
        $pos = \App\Repositories\DomesticOrderRepository::stateAbbr((string) $dom['place_of_supply']);
        $payload = [
            'customer_id' => $contactId,
            'invoice_number' => (string) $dom['tax_invoice_no'],
            'reference_number' => (string) $order['order_reference'],
            'date' => (string) $dom['tax_invoice_date'],
            'due_date' => date('Y-m-d', strtotime((string) $dom['tax_invoice_date'] . ' +' . (int) $dom['payment_terms_days'] . ' days')),
            'gst_treatment' => !empty($dom['buyer_gstin']) ? 'business_gst' : 'business_none',
            'place_of_supply' => $pos,
            'line_items' => $lines,
            // ZOH-8: the rupee round-off printed on the tax invoice, so Books' total is the invoice total.
            'adjustment' => round((float) ($dom['round_off'] ?? 0), 2),
            'adjustment_description' => 'Round off',
            'notes' => "Domestic GST tax invoice {$dom['tax_invoice_no']} for order {$order['order_reference']}, {$order['company_legal_name']}, place of supply " . \App\Repositories\DomesticOrderRepository::stateName((string) $dom['place_of_supply']) . '.',
        ];
        if (!empty($dom['buyer_gstin'])) {
            $payload['gst_no'] = (string) $dom['buyer_gstin'];
        }
        if (($tags = self::divisionTags((int) $order['division_id'])) !== []) {
            $payload['tags'] = $tags;
        }
        return $payload;
    }

    /**
     * Sends a Domestic Trade tax invoice to Books (created once; a retry finds
     * it by number). The buyer becomes a Books customer in INR with its GSTIN.
     * Live-tested in the Zoho TEST organisation on 5 Nov 2026 (Zoho change log Z-647 onwards).
     */
    public static function pushDomesticInvoice(int $orderId): string
    {
        $order = \App\Repositories\OrderRepository::find($orderId);
        $dom = \App\Repositories\DomesticOrderRepository::find($orderId);
        if ($order === null || $dom === null || empty($dom['tax_invoice_no'])) {
            throw new \RuntimeException('This domestic order has no tax invoice yet.');
        }
        ZohoGuard::assertCustomerAllowed((string) $order['company_legal_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        if (strlen((string) $dom['tax_invoice_no']) > 16) {
            throw new \RuntimeException("Invoice number {$dom['tax_invoice_no']} is longer than 16 characters, which Zoho Books does not accept for a GST invoice.");
        }
        $contactId = self::domesticContact($config, $accessToken, $order, $dom);
        $payload = self::domesticInvoicePayload($orderId, $contactId, static fn (string $name): string => self::taxId($config, $accessToken, $name));
        $existing = self::findInvoiceByNumber($config, $accessToken, (string) $dom['tax_invoice_no']);
        if ($existing !== null) {
            $invoiceId = (string) $existing['invoice_id'];
            if ((string) ($existing['status'] ?? '') === 'draft') {
                self::markInvoiceSent($config, $accessToken, $invoiceId); // a retry after the app stopped before marking it
            }
        } else {
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/invoices{$query}&ignore_auto_number_generation=true", $payload, self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            $invoiceId = (string) ($decoded['invoice']['invoice_id'] ?? '');
            if ($invoiceId === '') {
                throw new \RuntimeException('Zoho Books invoice creation failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            // D-257 Zoho: sent (no e-mail goes out), so the buyer's receipts can be applied to it.
            self::markInvoiceSent($config, $accessToken, $invoiceId);
        }
        $__user = AuthService::currentUser();
        \App\Repositories\DomesticOrderRepository::update($orderId, ['zoho_invoice_id' => $invoiceId], $__user !== null ? (int) $__user['id'] : null);
        return $invoiceId;
    }

    // ---------------------------------------------------------------- D-257 Phase 3: imports

    /**
     * Books accounts for an import (names to be confirmed by the CA): the goods
     * and the customs duty go to stock (they are part of the landed cost); the
     * IGST paid at customs is held until it is taken as input credit.
     */
    public const IMPORT_ACCOUNTS = [
        // Live test 5 Nov 2026: Books' "Inventory Asset" is a stock account that needs an item on every line, so the
        // landed cost goes to an "Imported Goods Stock" asset account instead (name to be confirmed by the CA).
        'goods' => 'Imported Goods Stock',
        'duty' => 'Imported Goods Stock',
        'igst_paid' => 'IGST Paid on Import',
        'igst_credit' => 'Input IGST',
    ];

    /**
     * The foreign supplier's bill for an import (pure: no network), in the
     * supplier's currency at the customs exchange rate. gst_treatment
     * overseas: no GST on the bill itself (IGST is paid at customs).
     * Rule D-212: the notes say what the bill is, from whom, for which import.
     *
     * @param callable(string): string $accountIdByName
     * @return array<string,mixed>
     */
    public static function importBillPayload(array $s, array $lines, string $vendorId, callable $accountIdByName): array
    {
        $items = [];
        foreach ($lines as $l) {
            $items[] = [
                'account_id' => $accountIdByName(self::IMPORT_ACCOUNTS['goods']),
                'name' => mb_substr((string) $l['description'], 0, 100),
                'description' => (string) $l['description'],
                'hsn_or_sac' => (string) $l['hsn_code'],
                'quantity' => (float) $l['quantity'],
                'rate' => (float) $l['unit_price_fc'],
            ];
        }
        $payload = [
            'vendor_id' => $vendorId,
            'bill_number' => (string) $s['reference'],
            'reference_number' => (string) ($s['bl_no'] ?? ''),
            'date' => (string) ($s['bl_date'] ?: $s['po_date']),
            'currency_code' => (string) $s['currency_code'],
            'exchange_rate' => (float) $s['customs_fx_rate'],
            'gst_treatment' => 'overseas',
            'line_items' => $items,
            'notes' => "Import purchase from {$s['supplier_name']} ({$s['supplier_country']}) against import PO {$s['reference']}"
                . (!empty($s['bl_no']) ? ", BL {$s['bl_no']}" : '') . (!empty($s['boe_no']) ? ", bill of entry {$s['boe_no']}" : '') . '.',
        ];
        if (($tags = self::divisionTags((int) $s['division_id'])) !== []) {
            $payload['tags'] = $tags;
        }
        return $payload;
    }

    /**
     * The customs duty of an import as a Books bill from Customs (pure: no
     * network): basic customs duty and social welfare surcharge (added to the
     * stock value) and the IGST paid on import (held for input credit).
     *
     * @param callable(string): string $accountIdByName
     * @return array<string,mixed>
     */
    public static function customsDutyBillPayload(array $s, string $vendorId, callable $accountIdByName): array
    {
        if (empty($s['boe_no']) || $s['bcd_total'] === null) {
            throw new \RuntimeException('This import is not cleared yet.');
        }
        $what = "import PO {$s['reference']}, bill of entry {$s['boe_no']} dated {$s['boe_date']} at {$s['port_code']}";
        $payload = [
            'vendor_id' => $vendorId,
            'bill_number' => 'BE-' . $s['boe_no'],
            'reference_number' => (string) $s['reference'],
            'date' => (string) $s['boe_date'],
            'line_items' => [
                ['account_id' => $accountIdByName(self::IMPORT_ACCOUNTS['duty']), 'name' => 'Basic customs duty', 'description' => "Basic customs duty on {$what}", 'quantity' => 1, 'rate' => (float) $s['bcd_total']],
                ['account_id' => $accountIdByName(self::IMPORT_ACCOUNTS['duty']), 'name' => 'Social welfare surcharge', 'description' => "Social welfare surcharge on {$what}", 'quantity' => 1, 'rate' => (float) $s['sws_total']],
                ['account_id' => $accountIdByName(self::IMPORT_ACCOUNTS['igst_paid']), 'name' => 'IGST paid on import', 'description' => "IGST paid on import on {$what} (input credit)", 'quantity' => 1, 'rate' => (float) $s['igst_total']],
            ],
            'notes' => "Customs duty and IGST on {$what}, cleared through {$s['cha_name']}.",
        ];
        if (($tags = self::divisionTags((int) $s['division_id'])) !== []) {
            $payload['tags'] = $tags;
        }
        return $payload;
    }

    /**
     * The journal that takes the IGST paid on import as input credit (pure:
     * no network): debit Input IGST, credit IGST Paid on Import.
     *
     * @param callable(string): string $accountIdByName
     * @return array<string,mixed>
     */
    public static function importIgstCreditJournalPayload(array $s, callable $accountIdByName): array
    {
        if (empty($s['igst_credit_on']) || $s['igst_total'] === null) {
            throw new \RuntimeException('The IGST credit of this import is not recorded yet.');
        }
        $amount = round((float) $s['igst_total'], 2);
        $payload = [
            'journal_date' => (string) $s['igst_credit_on'],
            'reference_number' => 'BE-' . $s['boe_no'],
            'notes' => "IGST paid on import taken as input credit: import PO {$s['reference']}, bill of entry {$s['boe_no']} dated {$s['boe_date']}, ₹" . GstCalculator::inr((string) $s['igst_total']) . '.',
            'line_items' => [
                ['account_id' => $accountIdByName(self::IMPORT_ACCOUNTS['igst_credit']), 'debit_or_credit' => 'debit', 'amount' => $amount, 'description' => "Input IGST on bill of entry {$s['boe_no']}"],
                ['account_id' => $accountIdByName(self::IMPORT_ACCOUNTS['igst_paid']), 'debit_or_credit' => 'credit', 'amount' => $amount, 'description' => "IGST paid on import, bill of entry {$s['boe_no']}"],
            ],
        ];
        if (($tags = self::divisionTags((int) $s['division_id'])) !== []) {
            $payload['tags'] = $tags;
        }
        return $payload;
    }

    /** The import's clearance charges (₹), by line: freight, insurance, CHA, port and terminal, other. */
    public const IMPORT_CLEARANCE_CHARGES = [
        'freight_inr' => 'Freight',
        'insurance_inr' => 'Insurance',
        'cha_inr' => 'CHA charges',
        'port_inr' => 'Port and terminal charges',
        'other_inr' => 'Other clearance charges',
    ];

    public static function importClearanceTotal(array $s): float
    {
        $t = 0.0;
        foreach (array_keys(self::IMPORT_CLEARANCE_CHARGES) as $k) {
            $t += (float) ($s[$k] ?? 0);
        }
        return round($t, 2);
    }

    /**
     * D-257 Zoho: the clearance charges of an import (pure: no network) as
     * one Books bill from the CHA, to stock (they are part of the landed
     * cost). Rule D-212: each line and the notes say what it is and for
     * which import and bill of entry.
     *
     * @param callable(string): string $accountIdByName
     * @return array<string,mixed>
     */
    public static function importClearanceBillPayload(array $s, string $vendorId, callable $accountIdByName): array
    {
        $what = "import PO {$s['reference']}, bill of entry {$s['boe_no']} dated {$s['boe_date']}";
        $lines = [];
        foreach (self::IMPORT_CLEARANCE_CHARGES as $k => $label) {
            if ((float) ($s[$k] ?? 0) > 0) {
                $lines[] = ['account_id' => $accountIdByName(self::IMPORT_ACCOUNTS['goods']), 'name' => $label, 'description' => "{$label} on {$what}", 'quantity' => 1, 'rate' => round((float) $s[$k], 2)];
            }
        }
        $payload = [
            'vendor_id' => $vendorId,
            'bill_number' => 'CLR-' . $s['boe_no'],
            'reference_number' => (string) $s['reference'],
            'date' => (string) ($s['clearance_date'] ?: $s['boe_date']),
            'line_items' => $lines,
            'notes' => "Clearance charges on {$what}, through {$s['cha_name']} (part of the landed cost).",
        ];
        if (($tags = self::divisionTags((int) $s['division_id'])) !== []) {
            $payload['tags'] = $tags;
        }
        return $payload;
    }

    /**
     * Sends a cleared import to Books: the supplier's bill and the customs
     * duty bill (each created once; a retry finds it by number), and the IGST
     * credit journal once the credit is recorded. Live-tested in the Zoho TEST
     * organisation on 5 Nov 2026 (Z-647 onwards).
     *
     * @return array{bill_id:string, duty_bill_id:string}
     */
    public static function pushImport(int $shipmentId): array
    {
        $s = \App\Repositories\ImportRepository::find($shipmentId);
        if ($s === null || empty($s['boe_no']) || $s['bcd_total'] === null) {
            throw new \RuntimeException('This import is not cleared yet.');
        }
        self::assertVendorAllowed((string) $s['supplier_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $account = static fn (string $name): string => self::accountId($config, $accessToken, $name);
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $vendor = static fn (string $name, ?string $currency): string => self::namedContact($config, $accessToken, $name, 'vendor', $currency);
        $supplierId = $vendor((string) $s['supplier_name'], (string) $s['currency_code']);
        $customsName = ZohoGuard::isProduction() ? 'Indian Customs' : ZohoGuard::TEST_PREFIX . 'Indian Customs';
        $customsId = $vendor($customsName, null);
        $ids = [];
        $bills = [
            'bill_id' => [(string) $s['reference'], $supplierId, self::importBillPayload($s, \App\Repositories\ImportRepository::lines($shipmentId), $supplierId, $account), 'zoho_bill_id'],
            'duty_bill_id' => ['BE-' . $s['boe_no'], $customsId, self::customsDutyBillPayload($s, $customsId, $account), 'zoho_duty_bill_id'],
        ];
        if (self::importClearanceTotal($s) > 0) {
            // D-257 Zoho: the clearance charges as a bill from the CHA (landed cost).
            $chaName = trim((string) $s['cha_name']) !== '' ? (string) $s['cha_name'] : 'Import clearance agent';
            self::assertVendorAllowed($chaName);
            $chaId = self::namedContact($config, $accessToken, $chaName, 'vendor', null);
            $bills['clearance_bill_id'] = ['CLR-' . $s['boe_no'], $chaId, self::importClearanceBillPayload($s, $chaId, $account), 'zoho_clearance_bill_id'];
        }
        foreach ($bills as $key => [$number, $vendorId, $payload, $column]) {
            $existing = !empty($s[$column]) ? ['bill_id' => $s[$column]] : self::findBill($config, $accessToken, $number, $vendorId);
            if ($existing === null) {
                $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/bills{$query}", $payload, self::authHeaders($accessToken));
                $existing = self::findBill($config, $accessToken, $number, $vendorId);
                if ($existing === null) {
                    throw new \RuntimeException("Zoho Books bill {$number} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
                }
            }
            $ids[$key] = (string) $existing['bill_id'];
            $__user = AuthService::currentUser();
            \App\Repositories\ImportRepository::update($shipmentId, [$column => $ids[$key]], $__user !== null ? (int) $__user['id'] : null);
        }
        return $ids;
    }

    // ---------------------------------------------------------------- D-257 Phase 4: merchant trade

    /** Books accounts for a merchant trade deal (names to be confirmed by the CA): no GST, the goods never enter India. */
    public const MERCHANT_ACCOUNTS = [
        'sales' => 'Merchanting Trade Sales',
        'purchases' => 'Merchanting Trade Purchases',
    ];

    /**
     * Our sale invoice to the foreign buyer as a Books invoice (pure: no
     * network), in the sale currency at the rate realised on the buyer's
     * money (left to Books until money has come in). gst_treatment overseas,
     * no GST. Rule D-212: the notes say what the invoice is, for which deal,
     * buyer and route.
     *
     * @param callable(string): string $accountIdByName
     * @return array<string,mixed>
     */
    public static function merchantInvoicePayload(array $d, array $lines, string $customerId, callable $accountIdByName): array
    {
        if (empty($d['sale_invoice_no'])) {
            throw new \RuntimeException('This deal has no sale invoice yet.');
        }
        $items = [];
        foreach ($lines as $l) {
            $items[] = [
                'account_id' => $accountIdByName(self::MERCHANT_ACCOUNTS['sales']),
                'name' => mb_substr((string) $l['description'], 0, 100),
                'description' => (string) $l['description'],
                'hsn_or_sac' => (string) $l['hsn_code'],
                'quantity' => (float) $l['quantity'],
                'rate' => (float) $l['sell_price_fc'],
            ];
        }
        $f = MerchantTradeService::figures($d);
        $payload = [
            'customer_id' => $customerId,
            'invoice_number' => (string) $d['sale_invoice_no'],
            'reference_number' => (string) $d['reference'],
            'date' => (string) $d['invoice_date'],
            'currency_code' => (string) $d['sale_currency'],
            'gst_treatment' => 'overseas',
            'line_items' => $items,
            'notes' => "Merchant trade sale invoice {$d['sale_invoice_no']} for deal {$d['reference']} to {$d['buyer_name']} ({$d['buyer_country']}): goods from {$d['origin_country']} shipped directly to the buyer"
                . (!empty($d['bl_no']) ? ', BL ' . ($d['switch_bl'] ? $d['switch_bl_no'] . ' (switch BL)' : $d['bl_no']) : '') . '; they do not enter India.',
        ];
        if ($f['sell_rate'] !== null) {
            $payload['exchange_rate'] = (float) $f['sell_rate'];
        }
        if (($tags = self::divisionTags((int) $d['division_id'])) !== []) {
            $payload['tags'] = $tags;
        }
        return $payload;
    }

    /**
     * The foreign supplier's bill for a merchant trade deal (pure: no
     * network), in the purchase currency at the rate realised on the
     * payments to the supplier. Rule D-212: the notes say what the bill is.
     *
     * @param callable(string): string $accountIdByName
     * @return array<string,mixed>
     */
    public static function merchantBillPayload(array $d, array $lines, string $vendorId, callable $accountIdByName): array
    {
        $items = [];
        foreach ($lines as $l) {
            $items[] = [
                'account_id' => $accountIdByName(self::MERCHANT_ACCOUNTS['purchases']),
                'name' => mb_substr((string) $l['description'], 0, 100),
                'description' => (string) $l['description'],
                'hsn_or_sac' => (string) $l['hsn_code'],
                'quantity' => (float) $l['quantity'],
                'rate' => (float) $l['buy_price_fc'],
            ];
        }
        $f = MerchantTradeService::figures($d);
        $payload = [
            'vendor_id' => $vendorId,
            'bill_number' => (string) $d['po_no'],
            'reference_number' => (string) $d['reference'],
            'date' => (string) ($d['bl_date'] ?: $d['deal_date']),
            'currency_code' => (string) $d['purchase_currency'],
            'gst_treatment' => 'overseas',
            'line_items' => $items,
            'notes' => "Merchant trade purchase from {$d['supplier_name']} ({$d['supplier_country']}) against PO {$d['po_no']}, deal {$d['reference']}"
                . (!empty($d['bl_no']) ? ", BL {$d['bl_no']}" : '') . '; goods shipped directly to the buyer abroad.',
        ];
        if ($f['buy_rate'] !== null) {
            $payload['exchange_rate'] = (float) $f['buy_rate'];
        }
        if (($tags = self::divisionTags((int) $d['division_id'])) !== []) {
            $payload['tags'] = $tags;
        }
        return $payload;
    }

    /**
     * Sends a merchant trade deal to Books: the sale invoice (customer = the
     * foreign buyer) and the supplier's bill, each created once (a retry
     * finds it by number). Live-tested in the Zoho TEST organisation on 5 Nov
     * 2026 (Z-647 onwards).
     *
     * @return array{invoice_id:string, bill_id:string}
     */
    public static function pushMerchantDeal(int $dealId): array
    {
        $d = \App\Repositories\MerchantTradeRepository::find($dealId);
        if ($d === null || empty($d['sale_invoice_no'])) {
            throw new \RuntimeException('This deal has no sale invoice yet.');
        }
        ZohoGuard::assertCustomerAllowed((string) $d['buyer_name']);
        self::assertVendorAllowed((string) $d['supplier_name']);
        if (strlen((string) $d['sale_invoice_no']) > 16) {
            throw new \RuntimeException("Invoice number {$d['sale_invoice_no']} is longer than 16 characters, which Zoho Books does not accept.");
        }
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $account = static fn (string $name): string => self::accountId($config, $accessToken, $name);
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $contact = static fn (string $name, string $type, string $currency): string => self::namedContact($config, $accessToken, $name, $type, $currency);
        $lines = \App\Repositories\MerchantTradeRepository::lines($dealId);
        $__user = AuthService::currentUser();
        $userId = $__user !== null ? (int) $__user['id'] : null;
        $customerId = $contact((string) $d['buyer_name'], 'customer', (string) $d['sale_currency']);
        $existing = !empty($d['zoho_invoice_id']) ? ['invoice_id' => $d['zoho_invoice_id']] : self::findInvoiceByNumber($config, $accessToken, (string) $d['sale_invoice_no']);
        if ($existing === null) {
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/invoices{$query}&ignore_auto_number_generation=true", self::merchantInvoicePayload($d, $lines, $customerId, $account), self::authHeaders($accessToken));
            $invoiceId = (string) (json_decode($response['body'], true)['invoice']['invoice_id'] ?? '');
            if ($invoiceId === '') {
                throw new \RuntimeException('Zoho Books invoice creation failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            // D-257 Zoho: sent (no e-mail goes out), so the buyer's payments can be applied to it.
            self::markInvoiceSent($config, $accessToken, $invoiceId);
        } else {
            $invoiceId = (string) $existing['invoice_id'];
            if ((string) ($existing['status'] ?? '') === 'draft') {
                self::markInvoiceSent($config, $accessToken, $invoiceId);
            }
        }
        \App\Repositories\MerchantTradeRepository::update($dealId, ['zoho_invoice_id' => $invoiceId], $userId);
        $vendorId = $contact((string) $d['supplier_name'], 'vendor', (string) $d['purchase_currency']);
        $bill = !empty($d['zoho_bill_id']) ? ['bill_id' => $d['zoho_bill_id']] : self::findBill($config, $accessToken, (string) $d['po_no'], $vendorId);
        if ($bill === null) {
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/bills{$query}", self::merchantBillPayload($d, $lines, $vendorId, $account), self::authHeaders($accessToken));
            $bill = self::findBill($config, $accessToken, (string) $d['po_no'], $vendorId);
            if ($bill === null) {
                throw new \RuntimeException("Zoho Books bill {$d['po_no']} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
        }
        \App\Repositories\MerchantTradeRepository::update($dealId, ['zoho_bill_id' => (string) $bill['bill_id']], $userId);
        return ['invoice_id' => $invoiceId, 'bill_id' => (string) $bill['bill_id']];
    }

    // ---------------------------------------------------------------- D-257 Zoho: the new divisions' money

    /**
     * A named Books contact (customer or vendor) found by its exact name, or
     * created (foreign-currency contacts as overseas). Used for the import
     * supplier, Customs, and the merchant trade supplier and buyer.
     */
    private static function namedContact(array $config, string $accessToken, string $name, string $type, ?string $currency): string
    {
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $find = static function (string $n) use ($config, $accessToken, $query, $type): ?array {
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts{$query}&contact_type={$type}&contact_name=" . rawurlencode($n), 'contacts') as $row) {
                if ((string) ($row['contact_name'] ?? '') === $n) {
                    return $row;
                }
            }
            return null;
        };
        $row = $find($name);
        // ZOH-7: Books takes an invoice's / bill's currency from its contact — a same-named contact in another
        // currency gets its own "<name> (<currency>)" contact instead.
        if ($row !== null && $currency !== null && !empty($row['currency_code']) && (string) $row['currency_code'] !== $currency) {
            $name = "{$name} ({$currency})";
            $row = $find($name);
        }
        if ($row !== null) {
            return (string) $row['contact_id'];
        }
        $payload = ['contact_name' => $name, 'company_name' => $name, 'contact_type' => $type]
            + ($currency !== null ? ['currency_id' => self::currencyId($config, $accessToken, $currency), 'gst_treatment' => 'overseas'] : []);
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/contacts{$query}", $payload, self::authHeaders($accessToken));
        $id = json_decode($response['body'], true)['contact']['contact_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($id)) {
            throw new \RuntimeException("Zoho Books {$type} creation failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return $id;
    }

    /** Books' id of a currency (a contact takes currency_id, not the code — live test 5 Nov 2026). */
    private static function currencyId(array $config, string $accessToken, string $code): string
    {
        foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/settings/currencies?organization_id=" . rawurlencode($config['organizationId']), 'currencies') as $row) {
            if (($row['currency_code'] ?? null) === $code) {
                return (string) $row['currency_id'];
            }
        }
        throw new \RuntimeException("Currency {$code} is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.");
    }

    /**
     * Duplicate guard for a retry (the app stopped after Books saved the
     * payment but before the app saved its id): a payment of this contact
     * with the same bank reference and amount is the same payment.
     */
    private static function existingPayment(array $config, string $accessToken, bool $vendor, string $contactId, string $reference, float $amount): ?string
    {
        [$path, $key, $field] = $vendor ? ['vendorpayments', 'vendorpayments', 'vendor_id'] : ['customerpayments', 'customerpayments', 'customer_id'];
        $url = "https://{$config['apiDomain']}/books/v3/{$path}?organization_id=" . rawurlencode($config['organizationId']) . "&{$field}=" . rawurlencode($contactId) . '&reference_number=' . rawurlencode($reference);
        foreach (self::getList($config, $accessToken, $url, $key) as $row) {
            if ((string) ($row['reference_number'] ?? '') === $reference && abs((float) ($row['amount'] ?? 0) - $amount) <= 0.01) {
                return (string) $row['payment_id'];
            }
        }
        return null;
    }

    /** A Books invoice or bill by id, from the list (its balance, total and status). */
    private static function documentRow(array $config, string $accessToken, string $kind, string $number, string $id): ?array
    {
        $url = "https://{$config['apiDomain']}/books/v3/{$kind}s?organization_id=" . rawurlencode($config['organizationId']) . "&{$kind}_number=" . rawurlencode($number);
        foreach (self::getList($config, $accessToken, $url, "{$kind}s") as $row) {
            if ((string) ($row["{$kind}_id"] ?? '') === $id) {
                return $row;
            }
        }
        return null;
    }

    /** Marks a new Books invoice as sent (no e-mail goes out), so payments can be applied to it. */
    private static function markInvoiceSent(array $config, string $accessToken, string $invoiceId): void
    {
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/invoices/" . rawurlencode($invoiceId) . '/status/sent?organization_id=' . rawurlencode($config['organizationId']), [], self::authHeaders($accessToken));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $body = (string) $response['body'];
            // Already sent (a retry): Books says so — nothing to do.
            if (!str_contains(strtolower($body), 'already')) {
                throw new \RuntimeException('Zoho Books could not mark invoice ' . $invoiceId . ' as sent (HTTP ' . $response['status'] . '): ' . substr($body, 0, 500));
            }
        }
    }

    /**
     * The payment payload for the new divisions (pure: no network). Rule
     * D-212: the description is the app's own (what, for which order and
     * party, what it settles, bank reference); the bank reference is the
     * reference number (bank reconciliation). A foreign-currency payment
     * goes in its own currency at the rate the bank gave.
     *
     * @param array{contact_id:string, vendor:bool, amount:float, date:string, reference:string, description:string, rate:?float, apply_to:?string, apply_amount:float} $p
     * @return array<string,mixed>
     */
    public static function divisionPaymentPayload(array $p, string $depositAccountId): array
    {
        $payload = [
            ($p['vendor'] ? 'vendor_id' : 'customer_id') => $p['contact_id'],
            'payment_mode' => $p['vendor'] ? 'Bank Transfer' : 'banktransfer',
            'amount' => round($p['amount'], 2),
            'date' => $p['date'],
            'reference_number' => $p['reference'],
            'description' => $p['description'],
            ($p['vendor'] ? 'paid_through_account_id' : 'account_id') => $depositAccountId,
        ];
        if ($p['rate'] !== null) {
            $payload['exchange_rate'] = round($p['rate'], 6);
        }
        if ($p['apply_to'] !== null && $p['apply_amount'] > 0) {
            $payload[$p['vendor'] ? 'bills' : 'invoices'] = [[($p['vendor'] ? 'bill_id' : 'invoice_id') => $p['apply_to'], 'amount_applied' => round($p['apply_amount'], 2)]];
        }
        return $payload;
    }

    /**
     * Sends one payment of a new division and applies it to its invoice or
     * bill (up to what that still owes; any excess stays unapplied in Books
     * as an advance). A retry finds the payment by its bank reference.
     *
     * @return array{payment_id:string, applied:float}
     */
    private static function sendDivisionPayment(array $config, string $accessToken, bool $vendor, string $contactId, string $docKind, string $docNumber, string $docId, float $amount, string $date, string $reference, string $description, ?float $rate): array
    {
        $row = self::documentRow($config, $accessToken, $docKind, $docNumber, $docId);
        if ($row === null) {
            throw new \RuntimeException(ucfirst($docKind) . " {$docNumber} was not found in Zoho Books, so payment {$reference} was not sent. Sync again; if it stays, check the {$docKind} in Books.");
        }
        // The payment goes to the contact the invoice / bill is on (Books refuses any other — live test 5 Nov 2026).
        $contactId = (string) ($row[$vendor ? 'vendor_id' : 'customer_id'] ?? $contactId);
        $existing = self::existingPayment($config, $accessToken, $vendor, $contactId, $reference, round($amount, 2));
        if ($existing !== null) {
            return ['payment_id' => $existing, 'applied' => 0.0];
        }
        $applied = round(min($amount, (float) ($row['balance'] ?? 0)), 2);
        $payload = self::divisionPaymentPayload([
            'contact_id' => $contactId, 'vendor' => $vendor, 'amount' => $amount, 'date' => $date, 'reference' => $reference,
            'description' => $description, 'rate' => $rate, 'apply_to' => $docId, 'apply_amount' => $applied,
        ], $config['depositAccountId']);
        $path = $vendor ? 'vendorpayments' : 'customerpayments';
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/{$path}?organization_id=" . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        $id = $decoded[$vendor ? 'vendorpayment' : 'payment']['payment_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($id)) {
            throw new \RuntimeException('Zoho Books ' . ($vendor ? 'vendor' : 'customer') . " payment {$reference} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return ['payment_id' => $id, 'applied' => $applied];
    }

    /**
     * D-257 Zoho (ZOH-6/7): the buyer's INR, GST-registered Books customer
     * for Domestic Trade / local sales — kept apart from its export
     * (overseas, foreign-currency) customer, remembered in
     * clients.zoho_contact_id_inr. A same-named contact that is not INR, or
     * is overseas, gets an "<name> (INR)" contact instead.
     */
    private static function domesticContact(array $config, string $accessToken, array $order, array $dom): string
    {
        $client = ClientRepository::find((int) $order['client_id']);
        if (!empty($client['zoho_contact_id_inr'])) {
            return (string) $client['zoho_contact_id_inr'];
        }
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $name = (string) $order['company_legal_name'];
        $id = null;
        foreach ([$name, "{$name} (INR)"] as $candidate) {
            $existing = null;
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts{$query}&contact_type=customer&contact_name=" . rawurlencode($candidate), 'contacts') as $row) {
                if ((string) ($row['contact_name'] ?? '') === $candidate) {
                    $existing = $row;
                }
            }
            if ($existing === null) {
                $contact = [
                    'contact_name' => $candidate,
                    'company_name' => $name,
                    'contact_type' => 'customer',
                    'currency_code' => 'INR',
                    'gst_treatment' => !empty($dom['buyer_gstin']) ? 'business_gst' : 'business_none',
                    'place_of_contact' => \App\Repositories\DomesticOrderRepository::stateAbbr((string) ($dom['buyer_state_code'] ?? $dom['place_of_supply'])),
                ];
                if (!empty($dom['buyer_gstin'])) {
                    $contact['gst_no'] = (string) $dom['buyer_gstin'];
                }
                $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/contacts{$query}", $contact, self::authHeaders($accessToken));
                $id = (string) (json_decode($response['body'], true)['contact']['contact_id'] ?? '');
                if ($id === '') {
                    throw new \RuntimeException('Zoho Books contact creation failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
                }
                break;
            }
            if ((string) ($existing['currency_code'] ?? 'INR') === 'INR' && (string) ($existing['gst_treatment'] ?? '') !== 'overseas') {
                $id = (string) $existing['contact_id'];
                break;
            }
        }
        if ($id === null) {
            throw new \RuntimeException("Zoho Books already has \"{$name}\" and \"{$name} (INR)\" as customers that are not INR / GST — rename one in Books, then send again.");
        }
        \App\Config\Database::connection()->prepare('UPDATE clients SET zoho_contact_id_inr = :z WHERE id = :id')->execute(['z' => $id, 'id' => (int) $order['client_id']]);
        return $id;
    }

    /**
     * Domestic Trade (and Import & Distribution's local sale): a buyer's
     * receipt as a Books customer payment, applied to the GST tax invoice.
     * The invoice must be in Books first.
     *
     * @param array<string,mixed> $payment domestic_payments row
     * @return array{payment_id:string, applied:float}
     */
    public static function pushDomesticReceipt(array $payment): array
    {
        $orderId = (int) $payment['order_id'];
        $order = \App\Repositories\OrderRepository::find($orderId);
        $dom = \App\Repositories\DomesticOrderRepository::find($orderId);
        if ($order === null || $dom === null || empty($dom['zoho_invoice_id'])) {
            throw new \RuntimeException('The tax invoice of this order is not in Zoho Books yet.');
        }
        ZohoGuard::assertCustomerAllowed((string) $order['company_legal_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $contactId = self::domesticContact($config, $accessToken, $order, $dom);
        return self::sendDivisionPayment($config, $accessToken, false, $contactId, 'invoice', (string) $dom['tax_invoice_no'], (string) $dom['zoho_invoice_id'],
            (float) $payment['amount_inr'], (string) $payment['received_on'], (string) $payment['bank_reference'], (string) $payment['description'], null);
    }

    /**
     * Import & Distribution: a payment to the supplier abroad (advance,
     * balance or LC) as a Books vendor payment in the supplier's currency,
     * at the rate the bank gave, applied to the import supplier bill.
     *
     * @param array<string,mixed> $payment import_payments row
     * @return array{payment_id:string, applied:float}
     */
    public static function pushImportPayment(array $payment): array
    {
        $s = \App\Repositories\ImportRepository::find((int) $payment['shipment_id']);
        if ($s === null || empty($s['zoho_bill_id'])) {
            throw new \RuntimeException('The supplier bill of this import is not in Zoho Books yet.');
        }
        self::assertVendorAllowed((string) $s['supplier_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $vendorId = self::namedContact($config, $accessToken, (string) $s['supplier_name'], 'vendor', (string) $s['currency_code']);
        return self::sendDivisionPayment($config, $accessToken, true, $vendorId, 'bill', (string) $s['reference'], (string) $s['zoho_bill_id'],
            (float) $payment['amount_fc'], (string) $payment['paid_on'], (string) $payment['bank_reference'], (string) $payment['description'], (float) $payment['fx_rate']);
    }

    /** Rule D-212: the customs duty payment's description, built from the facts. */
    public static function importDutyPaymentDescription(array $s): string
    {
        $total = (string) round((float) $s['bcd_total'] + (float) $s['sws_total'] + (float) $s['igst_total'], 2);
        return "Customs duty and IGST paid to Indian Customs for import PO {$s['reference']}, bill of entry {$s['boe_no']} dated {$s['boe_date']} at {$s['port_code']}: "
            . 'basic customs duty ₹' . GstCalculator::inr((string) $s['bcd_total']) . ' + social welfare surcharge ₹' . GstCalculator::inr((string) $s['sws_total'])
            . ' + IGST ₹' . GstCalculator::inr((string) $s['igst_total']) . ' = ₹' . GstCalculator::inr($total) . ", against customs duty bill BE-{$s['boe_no']}, challan / bank ref {$s['duty_bank_reference']}";
    }

    /**
     * Import & Distribution: the customs duty and IGST payment as a Books
     * vendor payment to Customs, applied to the customs duty bill.
     *
     * @return array{payment_id:string, applied:float}
     */
    public static function pushImportDutyPayment(int $shipmentId): array
    {
        $s = \App\Repositories\ImportRepository::find($shipmentId);
        if ($s === null || empty($s['zoho_duty_bill_id']) || empty($s['duty_paid_on'])) {
            throw new \RuntimeException('The customs duty bill or its payment is not recorded yet.');
        }
        self::assertVendorAllowed((string) $s['supplier_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $customsName = ZohoGuard::isProduction() ? 'Indian Customs' : ZohoGuard::TEST_PREFIX . 'Indian Customs';
        $customsId = self::namedContact($config, $accessToken, $customsName, 'vendor', null);
        $amount = round((float) $s['bcd_total'] + (float) $s['sws_total'] + (float) $s['igst_total'], 2);
        return self::sendDivisionPayment($config, $accessToken, true, $customsId, 'bill', 'BE-' . $s['boe_no'], (string) $s['zoho_duty_bill_id'],
            $amount, (string) $s['duty_paid_on'], (string) $s['duty_bank_reference'], self::importDutyPaymentDescription($s), null);
    }

    /** Import & Distribution: the journal taking the import IGST as input credit (once the credit is recorded). */
    public static function pushImportIgstJournal(int $shipmentId): string
    {
        $s = \App\Repositories\ImportRepository::find($shipmentId);
        if ($s === null) {
            throw new \RuntimeException("Import #{$shipmentId} not found.");
        }
        self::assertVendorAllowed((string) $s['supplier_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $payload = self::importIgstCreditJournalPayload($s, static fn (string $name): string => self::accountId($config, $accessToken, $name));
        if (!ZohoGuard::isProduction()) {
            $payload['notes'] = ZohoGuard::TEST_PREFIX . ' ' . $payload['notes'];
        }
        $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/journals?organization_id=" . rawurlencode($config['organizationId']), $payload, self::authHeaders($accessToken));
        $id = json_decode($response['body'], true)['journal']['journal_id'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || $id === null) {
            throw new \RuntimeException("Zoho Books IGST credit journal for import {$s['reference']} failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return (string) $id;
    }

    /**
     * Merchant Trade: one payment leg — the buy leg as a Books vendor
     * payment on the supplier's bill, the sell leg as a customer payment on
     * the sale invoice — each in its own currency at the rate the bank gave.
     *
     * @param array<string,mixed> $payment merchant_payments row
     * @return array{payment_id:string, applied:float}
     */
    public static function pushMerchantPayment(array $payment): array
    {
        $d = \App\Repositories\MerchantTradeRepository::find((int) $payment['deal_id']);
        $buy = (string) $payment['leg'] === 'buy';
        if ($d === null || empty($d[$buy ? 'zoho_bill_id' : 'zoho_invoice_id'])) {
            throw new \RuntimeException('The ' . ($buy ? "supplier's bill" : 'sale invoice') . ' of this deal is not in Zoho Books yet.');
        }
        ZohoGuard::assertCustomerAllowed((string) $d['buyer_name']);
        self::assertVendorAllowed((string) $d['supplier_name']);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $contactId = $buy
            ? self::namedContact($config, $accessToken, (string) $d['supplier_name'], 'vendor', (string) $d['purchase_currency'])
            : self::namedContact($config, $accessToken, (string) $d['buyer_name'], 'customer', (string) $d['sale_currency']);
        return self::sendDivisionPayment($config, $accessToken, $buy, $contactId, $buy ? 'bill' : 'invoice', (string) ($buy ? $d['po_no'] : $d['sale_invoice_no']),
            (string) $d[$buy ? 'zoho_bill_id' : 'zoho_invoice_id'], (float) $payment['amount_fc'], (string) $payment['moved_on'], (string) $payment['bank_reference'], (string) $payment['description'], (float) $payment['fx_rate']);
    }

    /**
     * The "App vs Zoho" check for a new division's record (reads only):
     * the invoice / bills and the payments the app holds, beside what Books
     * holds for them. Same statuses as OrderZohoMatchService.
     *
     * @param list<array{group:string, item:string, kind:string, number:string, zoho_id:?string, amount:float, unit:string, vendor:bool, contact:?string}> $records
     * @return list<array{group:string, item:string, app:?string, books:?string, status:string, note:string}>
     */
    public static function matchRecords(array $records): array
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $cache = [];
        $rows = [];
        foreach ($records as $r) {
            $fmt = static fn (?float $v): ?string => $v === null ? null : $r['unit'] . ' ' . number_format($v, 2);
            if ($r['zoho_id'] === null || $r['zoho_id'] === '') {
                $rows[] = ['group' => $r['group'], 'item' => $r['item'], 'app' => $fmt($r['amount']), 'books' => null, 'status' => 'not_sent', 'note' => 'Goes at the next sync (or see the Zoho Sync Log).'];
                continue;
            }
            $books = null;
            if ($r['kind'] === 'invoice' || $r['kind'] === 'bill') {
                $row = self::documentRow($config, $accessToken, $r['kind'], $r['number'], (string) $r['zoho_id']);
                $books = $row !== null ? (float) ($row['total'] ?? 0) : null;
            } elseif ($r['kind'] === 'journal') {
                $cache['journal'] ??= self::totalsById('journal');
                $books = $cache['journal'][(string) $r['zoho_id']] ?? null;
            } else {
                $key = ($r['vendor'] ? 'v:' : 'c:') . $r['contact'];
                if (!isset($cache[$key])) {
                    $cache[$key] = [];
                    if ($r['contact'] !== null) {
                        foreach (self::contactRecords((string) $r['contact'], $r['vendor'])[$r['vendor'] ? 'vendorpayments' : 'customerpayments'] as $p) {
                            $cache[$key][(string) $p['payment_id']] = (float) ($p['amount'] ?? 0);
                        }
                    }
                }
                $books = $cache[$key][(string) $r['zoho_id']] ?? null;
            }
            $status = $books === null ? 'missing' : (abs($books - $r['amount']) <= 0.01 ? 'match' : 'different');
            $rows[] = ['group' => $r['group'], 'item' => $r['item'], 'app' => $fmt($r['amount']), 'books' => $fmt($books), 'status' => $status,
                'note' => $status === 'missing' ? 'Sent by the app, but Books no longer has it.' : ''];
        }
        return $rows;
    }

    /** The Books contact id of a named contact, if it exists (reads only; for the match check). */
    public static function contactIdByName(string $name, string $type): ?string
    {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts?organization_id=" . rawurlencode($config['organizationId']) . "&contact_type={$type}&contact_name=" . rawurlencode($name), 'contacts') as $row) {
            if ((string) ($row['contact_name'] ?? '') === $name) {
                return (string) $row['contact_id'];
            }
        }
        return null;
    }

    private static function config(): array
    {
        // The .env switch comes first, so "switched off on this server" is
        // what staff see — not a missing-credentials message.
        if (!ZohoGuard::integrationEnabled()) {
            throw new \RuntimeException('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED is not true in .env).');
        }
        $clientId = (string) CompanySettingsRepository::get('zoho_books_client_id');
        $clientSecret = (string) CompanySettingsRepository::get('zoho_books_client_secret');
        $refreshToken = (string) CompanySettingsRepository::get('zoho_books_refresh_token');
        $organizationId = (string) CompanySettingsRepository::get('zoho_books_organization_id');
        $depositAccountId = (string) CompanySettingsRepository::get('zoho_books_deposit_account_id');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '' || $organizationId === '' || $depositAccountId === '') {
            throw new \RuntimeException('Zoho Books is enabled but not fully configured — fill in every zoho_books_* setting.');
        }

        return [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'refreshToken' => $refreshToken,
            'organizationId' => $organizationId,
            'depositAccountId' => $depositAccountId,
            'accountsDomain' => (string) (CompanySettingsRepository::get('zoho_books_accounts_domain') ?: 'accounts.zoho.in'),
            'apiDomain' => (string) (CompanySettingsRepository::get('zoho_books_api_domain') ?: 'www.zohoapis.in'),
        ];
    }

    /** @param array<string,string> $config */
    /**
     * The buyer's Books contact: an "overseas" customer (export, no GST)
     * in the order's currency. A Books customer has one currency, so a
     * contact found or cached in another currency is refused with a clear
     * message instead of booking the amount in the wrong currency.
     */
    private static function findOrCreateContact(array $config, string $accessToken, int $clientId, string $companyLegalName, string $currencyCode): string
    {
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $client = ClientRepository::find($clientId);
        if ($client !== null && !empty($client['zoho_contact_id'])) {
            $contactId = (string) $client['zoho_contact_id'];
            $response = self::request('GET', "https://{$config['apiDomain']}/books/v3/contacts/" . rawurlencode($contactId) . $query, [], self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            if ($response['status'] < 200 || $response['status'] >= 300 || !isset($decoded['contact'])) {
                throw new \RuntimeException('Zoho Books contact lookup failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            self::assertContactCurrency($companyLegalName, (string) ($decoded['contact']['currency_code'] ?? ''), $currencyCode);
            return $contactId;
        }

        $search = self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts{$query}&contact_name=" . rawurlencode($companyLegalName), 'contacts');
        $existing = $search[0]['contact_id'] ?? null;
        if (is_string($existing) && $existing !== '') {
            self::assertContactCurrency($companyLegalName, (string) ($search[0]['currency_code'] ?? ''), $currencyCode);
            ClientRepository::setZohoContactId($clientId, $existing);
            return $existing;
        }

        $currencyId = null;
        foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/settings/currencies{$query}", 'currencies') as $row) {
            if (($row['currency_code'] ?? null) === $currencyCode) {
                $currencyId = (string) $row['currency_id'];
            }
        }
        if ($currencyId === null) {
            throw new \RuntimeException("Currency {$currencyCode} is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.");
        }

        $createResponse = self::request(
            'POST',
            "https://{$config['apiDomain']}/books/v3/contacts{$query}",
            [
                'contact_name'  => $companyLegalName,
                'company_name'  => $companyLegalName,
                'contact_type'  => 'customer',
                'currency_id'   => $currencyId,
            ] + (self::isGstRegistered($config, $accessToken) ? ['gst_treatment' => 'overseas'] : []),
            self::authHeaders($accessToken)
        );
        $createDecoded = json_decode($createResponse['body'], true);
        $newContactId = $createDecoded['contact']['contact_id'] ?? null;
        if ($createResponse['status'] < 200 || $createResponse['status'] >= 300 || !is_string($newContactId)) {
            throw new \RuntimeException('Zoho Books contact creation failed (HTTP ' . $createResponse['status'] . '): ' . substr($createResponse['body'], 0, 500));
        }

        ClientRepository::setZohoContactId($clientId, $newContactId);
        return $newContactId;
    }

    /**
     * The Customer Payment fields for one leg: the foreign amount at the
     * actual rate (INR actual / foreign amount), into the deposit account.
     *
     * @return array<string, mixed>
     */
    /**
     * $amountInr is what reached the deposit account. $bankChargesInr (the
     * LC receipt, owner decision 2026-10-29) is what the bank kept: the
     * payment then covers both, at (credited + charges) / foreign amount,
     * and Books books the charges as bank charges — in the payment's own
     * currency.
     */
    private static function paymentPayload(array $config, string $contactId, string $currencyCode, ?float $foreignAmount, float $amountInr, string $date, string $referenceNumber, float $bankChargesInr = 0.0, string $description = ''): array
    {
        $payload = [
            'customer_id'      => $contactId,
            'payment_mode'     => 'banktransfer',
            'amount'           => round($amountInr + $bankChargesInr, 2),
            'date'             => $date,
            'reference_number' => $referenceNumber,
            // Owner rule D-212: what the payment is, for which order, what it settles, its bank reference.
            'description'      => $description,
            'account_id'       => $config['depositAccountId'],
        ];
        if ($bankChargesInr > 0) {
            $payload['bank_charges'] = round($bankChargesInr, 2);
        }
        if ($currencyCode !== 'INR') {
            if ($foreignAmount === null || $foreignAmount <= 0) {
                throw new \RuntimeException("Payment {$referenceNumber} has no {$currencyCode} amount recorded, so its rate cannot be worked out.");
            }
            $rate = ($amountInr + $bankChargesInr) / $foreignAmount;
            $payload['amount'] = round($foreignAmount, 2);
            $payload['exchange_rate'] = round($rate, 6);
            if ($bankChargesInr > 0) {
                $payload['bank_charges'] = round($bankChargesInr / $rate, 2);
            }
        }
        return $payload;
    }

    /**
     * The "invoices" part of a payment: as much of $amount as invoice
     * $invoiceNumber still owes.
     *
     * @return array{0: list<array{invoice_id: string, amount_applied: float}>, 1: float}
     */
    private static function invoiceApplication(array $config, string $accessToken, string $invoiceNumber, string $contactId, float $amount): array
    {
        $invoice = self::awaitInvoiceByNumber($config, $accessToken, $invoiceNumber);
        if ($invoice === null || (string) $invoice['customer_id'] !== $contactId) {
            throw new \RuntimeException("Invoice {$invoiceNumber} was not found in Zoho Books for this customer.");
        }
        $applied = round(min($amount, (float) ($invoice['balance'] ?? 0)), 2);
        return [$applied > 0 ? [['invoice_id' => (string) $invoice['invoice_id'], 'amount_applied' => $applied]] : [], $applied];
    }

    /**
     * findInvoiceByNumber(), asked up to three times a few seconds apart:
     * for an invoice the app knows is in Books, as the list can lag a few
     * seconds behind a create (test organisation, 2026-10-29).
     *
     * @return array<string, mixed>|null
     */
    private static function awaitInvoiceByNumber(array $config, string $accessToken, string $invoiceNumber): ?array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                sleep(3);
            }
            $invoice = self::findInvoiceByNumber($config, $accessToken, $invoiceNumber);
            if ($invoice !== null) {
                return $invoice;
            }
        }
        return null;
    }

    /**
     * One invoice by its exact number, from the invoice list (the
     * single-invoice read has been seen failing with HTTP 500 in the test
     * organisation, Z-029; the list does not).
     *
     * @return array<string, mixed>|null
     */
    private static function findInvoiceByNumber(array $config, string $accessToken, string $invoiceNumber): ?array
    {
        $rows = self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/invoices?organization_id=" . rawurlencode($config['organizationId']) . '&invoice_number=' . rawurlencode($invoiceNumber), 'invoices');
        foreach ($rows as $row) {
            if ((string) ($row['invoice_number'] ?? '') === $invoiceNumber) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Whether the Books organisation is GST-registered. Only then are the
     * GST fields (gst_treatment, hsn_or_sac) sent: an organisation that is
     * not registered rejects them (the test organisation, Z-025 / Z-028).
     */
    private static function isGstRegistered(array $config, string $accessToken): bool
    {
        if (!isset(self::$gstRegistered[$config['organizationId']])) {
            $response = self::request('GET', "https://{$config['apiDomain']}/books/v3/organizations/" . rawurlencode($config['organizationId']) . '?organization_id=' . rawurlencode($config['organizationId']), [], self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            if ($response['status'] < 200 || $response['status'] >= 300 || !isset($decoded['organization'])) {
                throw new \RuntimeException('Zoho Books organisation lookup failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            self::$gstRegistered[$config['organizationId']] = (bool) ($decoded['organization']['is_registered_for_gst'] ?? false);
            self::$orgGstin[$config['organizationId']] = strtoupper((string) ($decoded['organization']['tax_settings']['tax_reg_no'] ?? ''));
        }
        return self::$gstRegistered[$config['organizationId']];
    }

    /** The Books sync go-live date (zoho_books_sync_start_date), or null when not set. */
    public static function syncStartDate(): ?string
    {
        $date = trim((string) CompanySettingsRepository::get('zoho_books_sync_start_date'));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    /**
     * The bill's one line: its value and GST tax (see pushBill()).
     *
     * Owner decision 2026-10-29 (CA advice): a transport bill marked
     * reverse charge (a GTA that charges no GST) goes with the 5% GST as
     * reverse charge — the bill stays at its taxable value and Books
     * works out the tax we owe and can claim back. IGST5 for a transporter
     * registered in another state, else GST5 (Books treats a vendor with
     * no GSTIN as in our own state).
     *
     * @return array{rate: float, tax_id: ?string, reverse_charge_tax_id: ?string}
     */
    private static function billTax(array $config, string $accessToken, array $bill): array
    {
        $taxable = round((float) $bill['taxable_inr'], 2);
        $gstAmount = round((float) $bill['gst_inr'], 2);
        if (!self::isGstRegistered($config, $accessToken)) {
            return ['rate' => round((float) $bill['total_inr'], 2), 'tax_id' => null, 'reverse_charge_tax_id' => null];
        }
        $vendorGstin = strtoupper(trim((string) ($bill['vendor_gstin'] ?? '')));
        $otherState = $vendorGstin !== '' && substr($vendorGstin, 0, 2) !== substr(self::$orgGstin[$config['organizationId']] ?? '', 0, 2);
        if (!empty($bill['gst_reverse_charge'])) {
            if ($gstAmount > 0) {
                throw new \RuntimeException("Bill {$bill['bill_no']} not sent: it is marked reverse charge but carries GST (" . number_format($gstAmount, 2, '.', '') . '). Under reverse charge the transporter charges no GST — correct the bill.');
            }
            return ['rate' => $taxable, 'tax_id' => null, 'reverse_charge_tax_id' => self::taxId($config, $accessToken, $otherState ? 'IGST5' : 'GST5')];
        }
        if ($vendorGstin === '') {
            if ($gstAmount > 0) {
                throw new \RuntimeException("Bill {$bill['bill_no']} not sent: it carries GST (" . number_format($gstAmount, 2, '.', '') . "), but {$bill['vendor_name']} has no GSTIN in the app. Add the GSTIN to the supplier / logistics partner, or correct the bill.");
            }
            return ['rate' => $taxable, 'tax_id' => null, 'reverse_charge_tax_id' => null];
        }
        $percent = null;
        foreach ([0, 5, 12, 18, 28, 40] as $candidate) {
            if (abs(round($taxable * $candidate / 100, 2) - $gstAmount) <= 1.0) {
                $percent = $candidate;
                break;
            }
        }
        if ($percent === null) {
            throw new \RuntimeException("Bill {$bill['bill_no']} not sent: GST " . number_format($gstAmount, 2, '.', '') . ' on taxable ' . number_format($taxable, 2, '.', '') . ' is not 5, 12, 18, 28 or 40 % — correct the bill (a bill with several GST rates needs the CA).');
        }
        return ['rate' => $taxable, 'tax_id' => self::taxId($config, $accessToken, ($otherState ? 'IGST' : 'GST') . $percent), 'reverse_charge_tax_id' => null];
    }

    /** A Books tax's id by its name (GST18 = CGST + SGST group, IGST18, ...). */
    private static function taxId(array $config, string $accessToken, string $name): string
    {
        if (!isset(self::$taxes[$config['organizationId']])) {
            self::$taxes[$config['organizationId']] = [];
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/settings/taxes?organization_id=" . rawurlencode($config['organizationId']), 'taxes') as $row) {
                self::$taxes[$config['organizationId']][(string) $row['tax_name']] = (string) $row['tax_id'];
            }
        }
        $id = self::$taxes[$config['organizationId']][$name] ?? null;
        if ($id === null) {
            throw new \RuntimeException("Zoho Books has no tax named \"{$name}\". Check Zoho Books -> Settings -> Taxes (or ask the CA), then sync again.");
        }
        return $id;
    }

    /** In test, only vendors whose name starts with TEST- may be sent, so the clean-up script finds them. */
    private static function assertVendorAllowed(string $name): void
    {
        if (!ZohoGuard::isProduction() && !str_starts_with($name, ZohoGuard::TEST_PREFIX)) {
            throw new \RuntimeException("Not sent: ZOHO_ENVIRONMENT is test, and only vendors whose name starts with TEST- may go to Zoho (this one is \"{$name}\").");
        }
    }

    /** The bill's vendor in Books (supplier or logistics partner), created on first use and remembered on its row. */
    private static function findOrCreateVendor(array $config, string $accessToken, array $bill): string
    {
        if (!empty($bill['vendor_zoho_contact_id'])) {
            return (string) $bill['vendor_zoho_contact_id'];
        }
        $name = (string) $bill['vendor_name'];
        $query = '?organization_id=' . rawurlencode($config['organizationId']);
        $found = null;
        foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/contacts{$query}&contact_type=vendor&contact_name=" . rawurlencode($name), 'contacts') as $row) {
            if ((string) ($row['contact_name'] ?? '') === $name) {
                $found = (string) $row['contact_id'];
            }
        }
        if ($found === null) {
            $payload = ['contact_name' => $name, 'company_name' => $name, 'contact_type' => 'vendor'];
            if (self::isGstRegistered($config, $accessToken)) {
                $payload += !empty($bill['vendor_gstin'])
                    ? ['gst_treatment' => 'business_gst', 'gst_no' => (string) $bill['vendor_gstin']]
                    : ['gst_treatment' => 'business_none'];
            }
            if (!empty($bill['vendor_pan'])) {
                $payload['pan_no'] = (string) $bill['vendor_pan'];
            }
            $response = self::request('POST', "https://{$config['apiDomain']}/books/v3/contacts{$query}", $payload, self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            $found = $decoded['contact']['contact_id'] ?? null;
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($found)) {
                throw new \RuntimeException('Zoho Books vendor creation failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
        }
        \App\Repositories\ZohoPurchaseRepository::setVendorContactId((string) $bill['vendor_kind'], (int) $bill['vendor_id'], $found);
        return $found;
    }

    /** @return array<string, mixed>|null one bill by its number for this vendor */
    private static function findBill(array $config, string $accessToken, string $billNumber, string $vendorId): ?array
    {
        $rows = self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/bills?organization_id=" . rawurlencode($config['organizationId']) . '&vendor_id=' . rawurlencode($vendorId) . '&bill_number=' . rawurlencode($billNumber), 'bills');
        foreach ($rows as $row) {
            if ((string) ($row['bill_number'] ?? '') === $billNumber && (string) ($row['vendor_id'] ?? $vendorId) === $vendorId) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function vendorPaymentPayload(array $config, string $vendorId, array $payment, array $bill): array
    {
        return [
            'vendor_id' => $vendorId,
            'amount' => round((float) $payment['amount_inr'], 2),
            'date' => (string) $payment['paid_at'],
            'payment_mode' => (string) $payment['payment_mode'],
            'reference_number' => (string) $payment['payment_ref'],
            'description' => self::vendorPaymentDescription($payment, $bill),
            'paid_through_account_id' => $config['depositAccountId'],
        ];
    }

    /**
     * Owner decision 2026-10-31: the bank UTR stays the reference (bank
     * reconciliation); the description says which payment it is, as the
     * customer payments do — supplier advance / balance, or a logistics
     * partner's advance / final payment — with the order and the bill.
     */
    private static function vendorPaymentDescription(array $payment, array $bill): string
    {
        $order = (string) ($bill['buyer_inquiry_ref'] ?? '');
        $billLabel = (\App\Repositories\OrderVendorBillRepository::DOC_KINDS[$bill['doc_kind'] ?? ''] ?? 'Bill') . ' ' . $bill['bill_no'];
        if (($bill['vendor_kind'] ?? '') === 'supplier') {
            $leg = ($payment['leg'] ?? '') === 'balance' ? 'balance' : 'advance';
            return "Supplier {$leg} payment for order {$order}"
                . (!empty($bill['supplier_po_reference']) ? " · Supplier PO {$bill['supplier_po_reference']}" : '')
                . " · against {$billLabel}";
        }
        $service = \App\Repositories\OrderVendorBillRepository::LOGISTICS_SERVICES[$bill['bill_for'] ?? ''] ?? 'Logistics';
        $kind = ($bill['payment_kind'] ?? '') === 'advance' ? 'advance' : 'final';
        return "{$service} — {$kind} payment for order {$order} · against {$billLabel}";
    }

    /** @return array{0: list<array{bill_id: string, amount_applied: float}>, 1: float} as much of $amount as the bill still owes */
    private static function billApplication(array $config, string $accessToken, array $targetBill, string $vendorId, float $amount): array
    {
        $row = self::findBill($config, $accessToken, (string) $targetBill['bill_no'], $vendorId);
        if ($row === null) {
            throw new \RuntimeException("Bill {$targetBill['bill_no']} was not found in Zoho Books for this vendor.");
        }
        $applied = round(min($amount, (float) ($row['balance'] ?? 0)), 2);
        return [$applied > 0 ? [['bill_id' => (string) $row['bill_id'], 'amount_applied' => $applied]] : [], $applied];
    }

    /** A Books account's id by its name; a missing account is refused with where to add it. */
    private static function accountId(array $config, string $accessToken, string $name): string
    {
        if (!isset(self::$accounts[$config['organizationId']])) {
            self::$accounts[$config['organizationId']] = [];
            foreach (self::getList($config, $accessToken, "https://{$config['apiDomain']}/books/v3/chartofaccounts?organization_id=" . rawurlencode($config['organizationId']), 'chartofaccounts') as $row) {
                self::$accounts[$config['organizationId']][(string) $row['account_name']] = (string) $row['account_id'];
            }
        }
        $id = self::$accounts[$config['organizationId']][$name] ?? null;
        if ($id === null) {
            throw new \RuntimeException("Zoho Books has no account named \"{$name}\". Add it in Zoho Books -> Accountant -> Chart of Accounts (or ask the CA which account to use), then sync again.");
        }
        return $id;
    }

    private static function assertContactCurrency(string $companyLegalName, string $contactCurrency, string $currencyCode): void
    {
        if ($contactCurrency !== '' && $contactCurrency !== $currencyCode) {
            throw new \RuntimeException("{$companyLegalName} is set up in Zoho Books in {$contactCurrency}, but this payment is in {$currencyCode}. A Zoho Books customer has one currency — ask the CA how to book it.");
        }
    }

    /** Every page of a Books list (per_page 200), for lists that can grow past one page. */
    private static function getAllPages(array $config, string $accessToken, string $url, string $key): array
    {
        $rows = [];
        $page = 1;
        do {
            $response = self::request('GET', "{$url}&page={$page}&per_page=200", [], self::authHeaders($accessToken));
            $decoded = json_decode($response['body'], true);
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || !isset($decoded[$key]) || !is_array($decoded[$key])) {
                throw new \RuntimeException("Zoho Books {$key} request failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            $rows = array_merge($rows, $decoded[$key]);
            $page++;
        } while ((bool) ($decoded['page_context']['has_more_page'] ?? false));
        return $rows;
    }

    /** @return list<array<string, mixed>> the $key list of a Books GET, or throws */
    private static function getList(array $config, string $accessToken, string $url, string $key): array
    {
        $response = self::request('GET', $url, [], self::authHeaders($accessToken));
        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || !isset($decoded[$key]) || !is_array($decoded[$key])) {
            throw new \RuntimeException("Zoho Books {$key} request failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
        return $decoded[$key];
    }

    private static function authHeaders(string $accessToken): array
    {
        return ["Authorization: Zoho-oauthtoken {$accessToken}", 'Content-Type: application/json'];
    }

    private static function getAccessToken(string $accountsDomain, string $clientId, string $clientSecret, string $refreshToken): string
    {
        // Zoho issues only a few access tokens per refresh token in ten
        // minutes. Owner decision 2 Nov 2026 (D-239): one token (valid an
        // hour) is shared for 50 minutes by every process — the hourly sync,
        // "Sync now", "Sync this order", the checks — through
        // zoho_token_cache (encrypted), refreshed under a database lock so
        // two requests never both fetch a new one.
        $cached = self::$tokens[$refreshToken] ?? null;
        if ($cached !== null && $cached['expires'] > time()) {
            return $cached['token'];
        }
        $key = hash('sha256', ZohoGuard::environment() . '|' . $refreshToken);
        $fromDb = self::cachedToken($key);
        if ($fromDb !== null) {
            return self::rememberToken($refreshToken, $fromDb['token'], $fromDb['expires'], [$accountsDomain, $clientId, $clientSecret, $refreshToken]);
        }
        $pdo = \App\Config\Database::connection();
        $lockName = 'nx_zoho_token_' . substr($key, 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(:n, 15)');
        $lock->execute(['n' => $lockName]);
        try {
            $fromDb = self::cachedToken($key); // another request may have refreshed it while we waited
            if ($fromDb !== null) {
                return self::rememberToken($refreshToken, $fromDb['token'], $fromDb['expires'], [$accountsDomain, $clientId, $clientSecret, $refreshToken]);
            }
            $response = self::request(
                'POST',
                "https://{$accountsDomain}/oauth/v2/token",
                [
                    'refresh_token' => $refreshToken,
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type'    => 'refresh_token',
                ],
                [],
                true // form-encoded, not JSON — Zoho's token endpoint expects application/x-www-form-urlencoded
            );

            $decoded = json_decode($response['body'], true);
            if ($response['status'] !== 200 || !is_array($decoded) || empty($decoded['access_token'])) {
                throw new \RuntimeException('Zoho Books OAuth token refresh failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
            }
            $expires = time() + 3000;
            $pdo->prepare('INSERT INTO zoho_token_cache (cache_key, token_enc, expires_at) VALUES (:k, :t, FROM_UNIXTIME(:x))
                           ON DUPLICATE KEY UPDATE token_enc = VALUES(token_enc), expires_at = VALUES(expires_at)')
                ->execute(['k' => $key, 't' => \App\Services\EncryptionService::encrypt((string) $decoded['access_token']), 'x' => $expires]);
            return self::rememberToken($refreshToken, (string) $decoded['access_token'], $expires, [$accountsDomain, $clientId, $clientSecret, $refreshToken]);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $lockName]);
        }
    }

    /** @return array{token:string, expires:int}|null */
    private static function cachedToken(string $key): ?array
    {
        $stmt = \App\Config\Database::connection()->prepare('SELECT token_enc, UNIX_TIMESTAMP(expires_at) AS x FROM zoho_token_cache WHERE cache_key = :k AND expires_at > NOW()');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $token = \App\Services\EncryptionService::decrypt((string) $row['token_enc']);
        return $token !== null && $token !== '' ? ['token' => $token, 'expires' => (int) $row['x']] : null;
    }

    /** @param array{0:string,1:string,2:string,3:string} $owner */
    private static function rememberToken(string $refreshToken, string $token, int $expires, array $owner): string
    {
        self::$tokens[$refreshToken] = ['token' => $token, 'expires' => $expires];
        self::$tokenOwners[$token] = $owner;
        return $token;
    }

    /** D-239: Zoho refused the token (401) — forget it everywhere, so the next call fetches a new one. */
    private static function forgetToken(string $token): void
    {
        $owner = self::$tokenOwners[$token] ?? null;
        unset(self::$tokenOwners[$token]);
        if ($owner === null) {
            return;
        }
        unset(self::$tokens[$owner[3]]);
        \App\Config\Database::connection()->prepare('DELETE FROM zoho_token_cache WHERE cache_key = :k')
            ->execute(['k' => hash('sha256', ZohoGuard::environment() . '|' . $owner[3])]);
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    private static function request(string $method, string $url, array $payload, array $headers, bool $formEncoded = false): array
    {
        ZohoGuard::assertRequestAllowed($url);
        // Owner decision 2 Nov 2026 (D-239): every call counts against this app's daily / per-minute allowance.
        ZohoApiBudget::acquire();
        $response = self::send($method, $url, $payload, $headers, $formEncoded);
        if ($response['status'] === 401) {
            // A token Zoho no longer accepts: forget it, fetch a new one, try once more.
            foreach ($headers as $i => $h) {
                if (str_starts_with($h, 'Authorization: Zoho-oauthtoken ')) {
                    $old = substr($h, strlen('Authorization: Zoho-oauthtoken '));
                    $owner = self::$tokenOwners[$old] ?? null;
                    if ($owner !== null) {
                        self::forgetToken($old);
                        $headers[$i] = 'Authorization: Zoho-oauthtoken ' . self::getAccessToken(...$owner);
                        ZohoApiBudget::acquire();
                        $response = self::send($method, $url, $payload, $headers, $formEncoded);
                    }
                    break;
                }
            }
        }
        ZohoApiBudget::assertNotRateLimited($response['status'], $response['body']);
        return $response;
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    private static function send(string $method, string $url, array $payload, array $headers, bool $formEncoded): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($method, $url, $payload, $headers, $formEncoded);
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method !== 'GET' && $method !== 'DELETE') {
            $opts[CURLOPT_POSTFIELDS] = $formEncoded ? http_build_query($payload) : json_encode($payload);
        }
        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Zoho Books API request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $result];
    }
}
