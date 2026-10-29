<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Owner decisions 2026-10-29 — the server-level Zoho safety lock, read
 * from .env (never from Admin -> Settings, so no screen can change it).
 * The three variables are always written out in .env:
 *
 *   ZOHO_INTEGRATION_ENABLED=false   true = this server may talk to Zoho at all
 *   ZOHO_ENVIRONMENT=test            test | production
 *   ZOHO_EINVOICE_ENABLED=false      true = GST e-invoice / e-way bill / GST filing may be used
 *
 * A missing or unrecognised value is read as the safe choice (off / test /
 * off). Nothing from development or testing may ever reach the GST portal
 * or any other government portal: the government features are allowed only
 * when the integration is on, the environment is production and
 * ZOHO_EINVOICE_ENABLED=true — and even then only for real orders. In test,
 * only customers whose name starts with TEST- may be sent, so the clean-up
 * script always finds everything testing made. The Node stack has the same
 * rules (services/zohoGuard.js).
 */
final class ZohoGuard
{
    public const VARIABLES = ['ZOHO_INTEGRATION_ENABLED', 'ZOHO_ENVIRONMENT', 'ZOHO_EINVOICE_ENABLED'];
    public const TEST_PREFIX = 'TEST-';

    public static function integrationEnabled(): bool
    {
        return strtolower((string) Env::get('ZOHO_INTEGRATION_ENABLED', 'false')) === 'true';
    }

    /** 'production' only when .env says exactly that; anything else is 'test'. */
    public static function environment(): string
    {
        return strtolower((string) Env::get('ZOHO_ENVIRONMENT', 'test')) === 'production' ? 'production' : 'test';
    }

    public static function isProduction(): bool
    {
        return self::environment() === 'production';
    }

    /** GST e-invoice / e-way bill / GST filing: only on, in production, with ZOHO_EINVOICE_ENABLED=true. */
    public static function governmentFeaturesAllowed(): bool
    {
        return self::integrationEnabled()
            && self::isProduction()
            && strtolower((string) Env::get('ZOHO_EINVOICE_ENABLED', 'false')) === 'true';
    }

    /** @return list<string> the three variables that are not written out in .env */
    public static function missingVariables(): array
    {
        return array_values(array_filter(self::VARIABLES, static fn (string $name): bool => Env::get($name) === null));
    }

    /** A Zoho URL for e-invoicing (IRN / IRP), e-way bills or GST filing. */
    public static function isGovernmentUrl(string $url): bool
    {
        $path = strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        return preg_match('#e-?invoic|e-?way|ewb|(^|[/_-])(irn|irp|gstn|gstr\w*)([/_-]|$)|gst-?fil|filing#', $path) === 1;
    }

    /**
     * Every Zoho request goes through here first.
     *
     * @throws \RuntimeException when this server may not make the request
     */
    public static function assertRequestAllowed(string $url): void
    {
        if (!self::integrationEnabled()) {
            throw new \RuntimeException('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED is not true in .env).');
        }
        if (self::isGovernmentUrl($url) && !self::governmentFeaturesAllowed()) {
            throw new \RuntimeException('Blocked: GST e-invoice, e-way bill and GST filing requests are allowed only in production with ZOHO_EINVOICE_ENABLED=true in .env.');
        }
    }

    /**
     * In test, only TEST- customers may be sent to Zoho.
     *
     * @throws \RuntimeException for any other name in test
     */
    public static function assertCustomerAllowed(string $name): void
    {
        if (!self::isProduction() && !str_starts_with($name, self::TEST_PREFIX)) {
            throw new \RuntimeException("Not sent: ZOHO_ENVIRONMENT is test, and only customers whose name starts with TEST- may go to Zoho (this one is \"{$name}\").");
        }
    }
}
