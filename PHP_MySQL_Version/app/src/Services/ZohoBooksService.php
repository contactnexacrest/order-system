<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;

/**
 * CA / Accounting module (Phase 3) — pushes a settled revenue leg to Zoho
 * Books as a Customer Payment, via the OAuth self-client refresh-token
 * flow (same shape as ZohoMailService, a separate credential set since
 * Zoho Mail and Zoho Books are different API scopes). Every public method
 * here either returns a result or throws — CaSyncService is the one place
 * that catches those throws, logs them to zoho_sync_log, and moves on to
 * the next leg, so this class is free to fail loudly and correctly on any
 * bad response.
 *
 * NOTE: written against Zoho Books API v3's documented shape
 * (accounts.zoho.com OAuth token endpoint; www.zohoapis.com/books/v3/...
 * for contacts and customer payments). This account has no live Zoho
 * Books credentials yet to verify the exact request/response shape
 * against — re-check field names here against Zoho's current API docs
 * the first time real credentials are configured, using the "Sync Now"
 * button on /ca/zoho-sync (exactly the caveat ZohoMailService already
 * carries for the same reason).
 */
final class ZohoBooksService
{
    public static function isEnabled(): bool
    {
        return (string) CompanySettingsRepository::get('zoho_books_enabled') === '1';
    }

    /**
     * Pushes one settlement leg's INR-actual amount as a Zoho Books
     * Customer Payment against the order's client (creating the Zoho
     * contact on first use, cached on clients.zoho_contact_id after).
     *
     * @throws \RuntimeException on any missing config, HTTP failure, or unexpected response
     * @return string the Zoho-side customerpayment_id
     */
    public static function pushRevenuePayment(
        int $clientId,
        string $companyLegalName,
        float $amountInr,
        string $date,
        string $referenceNumber
    ): string {
        $config = self::config();
        $accessToken = self::getAccessToken($config['accountsDomain'], $config['clientId'], $config['clientSecret'], $config['refreshToken']);

        $contactId = self::findOrCreateContact($config, $accessToken, $clientId, $companyLegalName);

        $payload = [
            'customer_id'      => $contactId,
            'payment_mode'     => 'banktransfer',
            'amount'           => $amountInr,
            'date'             => $date,
            'reference_number' => $referenceNumber,
            'account_id'       => $config['depositAccountId'],
        ];

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

    /** @return array{clientId:string, clientSecret:string, refreshToken:string, organizationId:string, depositAccountId:string, accountsDomain:string, apiDomain:string} */
    private static function config(): array
    {
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
            'accountsDomain' => (string) (CompanySettingsRepository::get('zoho_books_accounts_domain') ?: 'accounts.zoho.com'),
            'apiDomain' => (string) (CompanySettingsRepository::get('zoho_books_api_domain') ?: 'www.zohoapis.com'),
        ];
    }

    /** @param array<string,string> $config */
    private static function findOrCreateContact(array $config, string $accessToken, int $clientId, string $companyLegalName): string
    {
        $client = ClientRepository::find($clientId);
        if ($client !== null && !empty($client['zoho_contact_id'])) {
            return (string) $client['zoho_contact_id'];
        }

        $searchResponse = self::request(
            'GET',
            "https://{$config['apiDomain']}/books/v3/contacts?organization_id=" . rawurlencode($config['organizationId']) . '&contact_name=' . rawurlencode($companyLegalName),
            [],
            self::authHeaders($accessToken)
        );
        $searchDecoded = json_decode($searchResponse['body'], true);
        $existing = $searchDecoded['contacts'][0]['contact_id'] ?? null;
        if (is_string($existing) && $existing !== '') {
            ClientRepository::setZohoContactId($clientId, $existing);
            return $existing;
        }

        $createResponse = self::request(
            'POST',
            "https://{$config['apiDomain']}/books/v3/contacts?organization_id=" . rawurlencode($config['organizationId']),
            ['contact_name' => $companyLegalName, 'contact_type' => 'customer'],
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

    /** @return string[] */
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
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method !== 'GET') {
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
