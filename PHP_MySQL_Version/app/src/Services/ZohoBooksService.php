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

    /** Tests only: answer every Zoho request with $transport instead of the network (null restores real HTTP). */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
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
     * @throws \RuntimeException on any missing config, HTTP failure, or unexpected response
     * @return string the Zoho-side customerpayment_id
     */
    public static function pushRevenuePayment(
        int $clientId,
        string $companyLegalName,
        string $currencyCode,
        ?float $foreignAmount,
        float $amountInr,
        string $date,
        string $referenceNumber
    ): string {
        ZohoGuard::assertCustomerAllowed($companyLegalName);
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $contactId = self::findOrCreateContact($config, $accessToken, $clientId, $companyLegalName, $currencyCode);

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

        return $paymentId;
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
     * Books customer whose name starts with TEST-, with its payments.
     *
     * @return list<array{contact_id: string, name: string, payments: list<array{payment_id: string, label: string}>}>
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
            $contacts[] = ['contact_id' => (string) $row['contact_id'], 'name' => (string) ($row['contact_name'] ?? ''), 'payments' => $payments];
        }
        return $contacts;
    }

    /**
     * Deletes one Books record made while testing — 'customerpayment' or
     * 'contact' (payments first: Books refuses to delete a contact that
     * still has transactions). A record already gone counts as deleted.
     *
     * @throws \RuntimeException on any other failure
     */
    public static function deleteRecord(string $kind, string $id): void
    {
        $paths = ['customerpayment' => 'customerpayments', 'contact' => 'contacts'];
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
                'gst_treatment' => 'overseas',
                'currency_id'   => $currencyId,
            ],
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
