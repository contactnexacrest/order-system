<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\LookupRepository;

/**
 * CA / Accounting module — the Zoho Books connection. Phase 3 pushes a
 * settled revenue leg as a Customer Payment (one-way, this system to
 * Zoho); Phase 4 imports expenses (one-way, Zoho to this system) — both
 * via the same OAuth self-client refresh-token flow (same shape as
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
    /** @var array<string, bool> whether each Books organisation is GST-registered */
    private static array $gstRegistered = [];

    /** Tests only: answer every Zoho request with $transport instead of the network (null restores real HTTP). */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
        self::$tokens = [];
        self::$gstRegistered = [];
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
        ?string $invoiceNumber = null
    ): array {
        ZohoGuard::assertCustomerAllowed($companyLegalName);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $contactId = self::findOrCreateContact($config, $accessToken, $clientId, $companyLegalName, $currencyCode);
        $payload = self::paymentPayload($config, $contactId, $currencyCode, $foreignAmount, $amountInr, $date, $referenceNumber);
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
        string $invoiceNumber
    ): float {
        ZohoGuard::assertCustomerAllowed($companyLegalName);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $contactId = self::findOrCreateContact($config, $accessToken, $clientId, $companyLegalName, $currencyCode);
        $payload = self::paymentPayload($config, $contactId, $currencyCode, $foreignAmount, $amountInr, $date, $referenceNumber);
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
        // so the result is read back from the invoice list, never from the
        // response.
        $response = $existing === null
            ? self::request('POST', "https://{$config['apiDomain']}/books/v3/invoices{$query}", $payload, self::authHeaders($accessToken))
            : self::request('PUT', "https://{$config['apiDomain']}/books/v3/invoices/" . rawurlencode((string) $existing['invoice_id']) . $query, $payload, self::authHeaders($accessToken));
        $saved = self::findInvoiceByNumber($config, $accessToken, (string) $invoice['invoice_number']);
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

        return ['zoho_invoice_id' => (string) $saved['invoice_id'], 'zoho_invoice_number' => (string) $saved['invoice_number'], 'status' => $status, 'created' => $existing === null];
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
            $contacts[] = ['contact_id' => (string) $row['contact_id'], 'name' => (string) ($row['contact_name'] ?? ''), 'payments' => $payments, 'invoices' => $invoices];
        }
        return $contacts;
    }

    /**
     * Deletes one Books record made while testing — 'customerpayment',
     * 'invoice' or 'contact' (payments, then invoices, then the contact:
     * Books refuses to delete a contact that still has transactions). A
     * record already gone counts as deleted.
     *
     * @throws \RuntimeException on any other failure
     */
    public static function deleteRecord(string $kind, string $id): void
    {
        $paths = ['customerpayment' => 'customerpayments', 'invoice' => 'invoices', 'contact' => 'contacts'];
        if (!isset($paths[$kind])) {
            throw new \InvalidArgumentException("Unknown Zoho Books record kind {$kind}.");
        }
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);
        $response = self::request('DELETE', "https://{$config['apiDomain']}/books/v3/{$paths[$kind]}/" . rawurlencode($id) . '?organization_id=' . rawurlencode($config['organizationId']), [], self::authHeaders($accessToken));
        if ($response['status'] === 404) {
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Zoho Books {$kind} {$id} delete failed (HTTP " . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }
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
    private static function paymentPayload(array $config, string $contactId, string $currencyCode, ?float $foreignAmount, float $amountInr, string $date, string $referenceNumber): array
    {
        $payload = [
            'customer_id'      => $contactId,
            'payment_mode'     => 'banktransfer',
            'amount'           => round($amountInr, 2),
            'date'             => $date,
            'reference_number' => $referenceNumber,
            'account_id'       => $config['depositAccountId'],
        ];
        if ($currencyCode !== 'INR') {
            if ($foreignAmount === null || $foreignAmount <= 0) {
                throw new \RuntimeException("Payment {$referenceNumber} has no {$currencyCode} amount recorded, so its rate cannot be worked out.");
            }
            $payload['amount'] = round($foreignAmount, 2);
            $payload['exchange_rate'] = round($amountInr / $foreignAmount, 6);
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
        $invoice = self::findInvoiceByNumber($config, $accessToken, $invoiceNumber);
        if ($invoice === null || (string) $invoice['customer_id'] !== $contactId) {
            throw new \RuntimeException("Invoice {$invoiceNumber} was not found in Zoho Books for this customer.");
        }
        $applied = round(min($amount, (float) ($invoice['balance'] ?? 0)), 2);
        return [$applied > 0 ? [['invoice_id' => (string) $invoice['invoice_id'], 'amount_applied' => $applied]] : [], $applied];
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
        }
        return self::$gstRegistered[$config['organizationId']];
    }

    private static function assertContactCurrency(string $companyLegalName, string $contactCurrency, string $currencyCode): void
    {
        if ($contactCurrency !== '' && $contactCurrency !== $currencyCode) {
            throw new \RuntimeException("{$companyLegalName} is set up in Zoho Books in {$contactCurrency}, but this payment is in {$currencyCode}. A Zoho Books customer has one currency — ask the CA how to book it.");
        }
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
        // minutes, and a Stage 2 run makes many calls: one token (valid an
        // hour) is reused for 50 minutes.
        $cached = self::$tokens[$refreshToken] ?? null;
        if ($cached !== null && $cached['expires'] > time()) {
            return $cached['token'];
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

        self::$tokens[$refreshToken] = ['token' => (string) $decoded['access_token'], 'expires' => time() + 3000];
        return (string) $decoded['access_token'];
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    private static function request(string $method, string $url, array $payload, array $headers, bool $formEncoded = false): array
    {
        ZohoGuard::assertRequestAllowed($url);
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
