<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateBrandRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\PricingRepository;

/**
 * D-431 (owner 25–26 Nov 2026): brands for divisions, associate side only.
 *
 * - A brand is the division name an associate sees (name only, no logo) with the line under it.
 *   Buyers never see a brand: their form, e-mails, documents and portal stay NexaCrest.
 * - Per-brand associate rules: the associate's own value first, then the brand's, then the
 *   company setting (empty = follows the company setting, also when it changes later).
 * - Company-wide only: payout run and day, cut-off, waiting days, TDS, KYC, bank checks.
 */
final class AssociateBrands
{
    public const STATUSES = ['active' => 'Active', 'planned' => 'Planned', 'inactive' => 'Not used'];

    /** The rules a brand may set (key => label, min, max, kind). */
    public const RULES = [
        'min_order_inr' => ['Minimum order value that earns and counts as a step (₹, FOB of the goods)', 0, 100000000, 'money'],
        'group_days' => ['Same client within (days) = one ladder step', AssociateRules::GROUP_DAYS_MIN, AssociateRules::GROUP_DAYS_MAX, 'int'],
        'price_rule' => ["Commission in the client's price", null, null, 'choice'],
        'price_fixed_pct' => ["The fixed % in the client's price", 0, 50, 'pct'],
        'price_requests_per_day' => ['Price requests per day', 0, 1000, 'int'],
        'price_valid_days' => ['A price stays valid for (days)', 1, 365, 'int'],
    ];

    /** The company default for the line under a brand's name. */
    public const DEFAULT_TAGLINE = 'a division of NexaCrest International Private Limited';

    /** @var array<int, ?array<string,mixed>> brand per associate, for one request */
    private static array $forAssociate = [];
    /** @var array<int, array<string,string>> */
    private static array $settings = [];

    public static function reset(): void
    {
        self::$forAssociate = [];
        self::$settings = [];
        AssociateBrandRepository::reset();
    }

    /** The associate's brand (null = NexaCrest: no brand, exactly as before). */
    public static function forAssociateId(?int $associateId): ?array
    {
        if ($associateId === null || $associateId <= 0) {
            return null;
        }
        if (!array_key_exists($associateId, self::$forAssociate)) {
            $stmt = Database::connection()->prepare('SELECT brand_id FROM associates WHERE id = :id');
            $stmt->execute(['id' => $associateId]);
            $b = (int) ($stmt->fetchColumn() ?: 0);
            self::$forAssociate[$associateId] = $b > 0 ? AssociateBrandRepository::find($b) : null;
        }
        return self::$forAssociate[$associateId];
    }

    public static function forAssociate(?array $associate): ?array
    {
        if ($associate === null) {
            return null;
        }
        if (array_key_exists('brand_id', $associate)) {
            return !empty($associate['brand_id']) ? AssociateBrandRepository::find((int) $associate['brand_id']) : null;
        }
        return self::forAssociateId(isset($associate['id']) ? (int) $associate['id'] : null);
    }

    /** @return array<string,string> the brand's own rule values */
    public static function settings(int $brandId): array
    {
        return self::$settings[$brandId] ??= AssociateBrandRepository::settings($brandId);
    }

    /** One rule of the associate's brand, or null (= follow the company setting). */
    public static function rule(?int $associateId, string $key): ?string
    {
        $b = self::forAssociateId($associateId);
        if ($b === null) {
            return null;
        }
        $v = self::settings((int) $b['id'])[$key] ?? null;
        return $v !== null && $v !== '' ? $v : null;
    }

    /** The company values the brand rules fall back to (Associates › Settings, Admin › Pricing, Price access). */
    public static function companyValues(): array
    {
        $inc = IncentiveRepository::settings();
        $price = AssociateRules::companyPriceRule();
        $ps = PricingService::settings();
        return [
            'min_order_inr' => (string) (float) ($inc['associate_counting_threshold_inr'] ?? 500000),
            'group_days' => (string) AssociateRules::companyGroupDays(),
            'price_rule' => $price['rule'],
            'price_fixed_pct' => (string) (float) ((PricingRepository::settings() + PricingService::DEFAULTS)['associate_price_fixed_pct'] ?? 5),
            'price_requests_per_day' => (string) (int) $ps['price_request_daily_limit'],
            'price_valid_days' => (string) (int) $ps['price_request_valid_days'],
        ];
    }

    /** The brand's own ladder, or [] (follows the company ladder). */
    public static function ladderFor(?array $associate): array
    {
        $b = self::forAssociate($associate);
        return $b !== null ? AssociateBrandRepository::ladder((int) $b['id']) : [];
    }

    /** The name an associate sees at the top of the portal and e-mails. */
    public static function displayName(?array $brand): string
    {
        return $brand !== null ? (string) $brand['name'] : 'NexaCrest';
    }

    public static function tagline(?array $brand): string
    {
        return $brand !== null ? (string) $brand['tagline'] : '';
    }

    /** The footer every associate page and e-mail of a branded associate carries. */
    public static function legalLine(?array $brand): string
    {
        if ($brand === null) {
            return '';
        }
        return $brand['name'] . ' is a division of NexaCrest International Private Limited. Your agreement and your payments are with NexaCrest International Private Limited.';
    }

    /**
     * Validates a brand rules form (empty = follows the company setting).
     *
     * @param array<string,mixed> $post
     * @return array{0: array<string,?string>, 1: list<string>} values to save, errors
     */
    public static function validateRules(array $post): array
    {
        $out = [];
        $errors = [];
        foreach (self::RULES as $key => [$label, $min, $max, $kind]) {
            $raw = trim((string) ($post[$key] ?? ''));
            if ($raw === '') {
                $out[$key] = null;
                continue;
            }
            if ($kind === 'choice') {
                if (!isset(AssociateRules::PRICE_RULES[$raw])) {
                    $errors[] = "Choose a valid option for \"{$label}\".";
                } else {
                    $out[$key] = $raw;
                }
                continue;
            }
            $raw = str_replace(',', '', $raw);
            $n = $kind === 'int' ? filter_var($raw, FILTER_VALIDATE_INT) : filter_var($raw, FILTER_VALIDATE_FLOAT);
            if ($n === false || $n < $min || $n > $max) {
                $errors[] = "\"{$label}\" must be between {$min} and {$max}, or empty to follow the company setting.";
                continue;
            }
            $out[$key] = $kind === 'int' ? (string) (int) $n : number_format((float) $n, 2, '.', '');
        }
        if (($out['price_rule'] ?? null) !== 'fixed') {
            $out['price_fixed_pct'] = null;
        } elseif (($out['price_fixed_pct'] ?? null) === null) {
            $errors[] = 'Enter the fixed % for "A fixed %".';
        }
        return [$out, $errors];
    }

    /**
     * D-431: an e-mail to an associate in their brand's name — the subject's "NexaCrest" becomes the brand name, the
     * body starts with the brand and its line and ends with the legal line. No brand: unchanged.
     *
     * @return array{0: string, 1: string}
     */
    public static function email(?array $associate, string $subject, string $body): array
    {
        $brand = self::forAssociate($associate);
        if ($brand === null) {
            return [$subject, $body];
        }
        $name = (string) $brand['name'];
        $subject = str_replace('NexaCrest', $name, $subject);
        $body = str_replace('NexaCrest relationship manager', $name . ' relationship manager', $body);
        $body = preg_replace('/\n\nNexaCrest International Private Limited$/', "\n\n{$name} associates team", $body) ?? $body;
        return [$subject, $name . ' — ' . $brand['tagline'] . "\n\n" . $body . "\n\n" . self::legalLine($brand)];
    }

    /** Login address codes that are already pages of the portal. */
    public const RESERVED_CODES = ['code'];

    /** A login address code: lower-case letters, digits and hyphens, 2–30 characters. */
    public static function validCode(string $code): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?$/', $code) && strlen($code) >= 2 && !in_array($code, self::RESERVED_CODES, true);
    }

    public const SESSION_KEY = '_associate_login_brand';
    public const COOKIE = 'nx_assoc_brand';

    /** Remembers the brand of a login address in the session and, for a year, in the browser. */
    public static function rememberLoginBrand(array $brand): void
    {
        $_SESSION[self::SESSION_KEY] = (string) $brand['code'];
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            setcookie(self::COOKIE, (string) $brand['code'], ['expires' => time() + 365 * 86400, 'path' => '/associate', 'secure' => !\App\Config\Env::isLocal(), 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    /** The brand shown on the login pages before the associate is known (login address used, or remembered); null = NexaCrest. */
    public static function loginBrand(): ?array
    {
        $code = (string) ($_SESSION[self::SESSION_KEY] ?? ($_COOKIE[self::COOKIE] ?? ''));
        return $code !== '' && self::validCode($code) ? AssociateBrandRepository::findByCode($code) : null;
    }
}
