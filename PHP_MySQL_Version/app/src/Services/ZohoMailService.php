<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanySettingsRepository;

/**
 * docs/schema.sql Section AI — sends through the Zoho Mail API (OAuth
 * self-client refresh-token flow). Every public method here either
 * returns a result or throws — MailSenderService is the one place that
 * catches those throws and falls back to SMTP, so this class is free to
 * fail loudly and correctly on any bad response.
 *
 * NOTE: written against Zoho Mail API v1's documented shape
 * (accounts.zoho.com OAuth token endpoint; mail.zoho.com/api/accounts/
 * {accountId}/messages to send, .../messages/attachments to pre-upload an
 * attachment). This account has no live Zoho credentials yet to verify
 * the exact request/response shape against — re-check field names here
 * against Zoho's current API docs the first time real credentials are
 * configured, using the "Send Test Email" button on /settings.
 */
final class ZohoMailService
{
    public static function isEnabled(): bool
    {
        return (string) CompanySettingsRepository::get('zoho_mail_enabled') === '1';
    }

    /**
     * @param array<int, array{path:string, name:string}> $attachments
     * @throws \RuntimeException on any missing config, HTTP failure, or unexpected response
     */
    public static function send(string $to, string $subject, string $body, array $attachments = []): bool
    {
        $clientId = (string) CompanySettingsRepository::get('zoho_client_id');
        $clientSecret = (string) CompanySettingsRepository::get('zoho_client_secret');
        $refreshToken = (string) CompanySettingsRepository::get('zoho_refresh_token');
        $accountId = (string) CompanySettingsRepository::get('zoho_account_id');
        $fromAddress = (string) CompanySettingsRepository::get('zoho_from_address');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '' || $accountId === '' || $fromAddress === '') {
            throw new \RuntimeException('Zoho Mail is enabled but not fully configured — fill in every zoho_* setting.');
        }

        $accountsDomain = (string) (CompanySettingsRepository::get('zoho_accounts_domain') ?: 'accounts.zoho.com');
        $apiDomain = (string) (CompanySettingsRepository::get('zoho_api_domain') ?: 'mail.zoho.com');

        $accessToken = self::getAccessToken($accountsDomain, $clientId, $clientSecret, $refreshToken);

        $uploaded = [];
        foreach ($attachments as $att) {
            if (!is_file($att['path'])) {
                continue;
            }
            $uploaded[] = self::uploadAttachment($apiDomain, $accountId, $accessToken, $att['path'], $att['name']);
        }

        $payload = [
            'fromAddress' => $fromAddress,
            'toAddress'   => $to,
            'subject'     => $subject,
            'content'     => $body,
            'mailFormat'  => 'plaintext',
        ];
        if (!empty($uploaded)) {
            $payload['attachments'] = $uploaded;
        }

        $response = self::request(
            'POST',
            "https://{$apiDomain}/api/accounts/{$accountId}/messages",
            $payload,
            ["Authorization: Zoho-oauthtoken {$accessToken}", 'Content-Type: application/json']
        );

        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || (($decoded['status']['code'] ?? null) !== 200 && !isset($decoded['data']))) {
            throw new \RuntimeException('Zoho Mail send failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }

        return true;
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
            throw new \RuntimeException('Zoho OAuth token refresh failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }

        return (string) $decoded['access_token'];
    }

    /** @return array{storeName:?string, attachmentPath:?string, attachmentName:string} */
    private static function uploadAttachment(string $apiDomain, string $accountId, string $accessToken, string $path, string $name): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Could not read attachment for Zoho upload: {$name}");
        }

        $response = self::rawRequest(
            'POST',
            "https://{$apiDomain}/api/accounts/{$accountId}/messages/attachments?fileName=" . rawurlencode($name),
            $bytes,
            ["Authorization: Zoho-oauthtoken {$accessToken}", 'Content-Type: application/octet-stream']
        );

        $decoded = json_decode($response['body'], true);
        $data = $decoded['data'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            throw new \RuntimeException('Zoho attachment upload failed (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 500));
        }

        return [
            'storeName'      => $data['storeName'] ?? null,
            'attachmentPath' => $data['attachmentPath'] ?? null,
            'attachmentName' => $data['attachmentName'] ?? $name,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    private static function request(string $method, string $url, array $payload, array $headers, bool $formEncoded = false): array
    {
        $body = $formEncoded ? http_build_query($payload) : json_encode($payload);
        return self::rawRequest($method, $url, (string) $body, $headers);
    }

    /** @param string[] $headers @return array{status:int, body:string} */
    private static function rawRequest(string $method, string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Zoho API request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $result];
    }
}
