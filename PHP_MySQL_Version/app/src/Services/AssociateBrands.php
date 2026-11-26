<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Services\AuthService;
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

    /** D-431 (owner 26 Nov 2026): the reminder on the associate's Home and My clients pages. */
    public const BUYER_TIP = 'When you talk to buyers, introduce NexaCrest International. Prices and documents always come from NexaCrest. Never tell a buyer about your commission.';

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

    // ------------------------------------------------------------ D-432 brand managers

    /** D-432 (owner 26 Nov 2026): the brand a staff member looks after (users.brand_id); null = all brands, as before. */
    public static function userBrandId(?array $user = null): ?int
    {
        $user ??= AuthService::currentUser();
        $b = (int) ($user['brand_id'] ?? 0);
        return $b > 0 ? $b : null;
    }

    /** " AND {col} = {brand}" for a brand manager, else "" (the brand id is an integer, safe to inline). */
    public static function scopeSql(string $column = 'a.brand_id'): string
    {
        $b = self::userBrandId();
        return $b === null ? '' : " AND {$column} = {$b}";
    }

    /** Whether the signed-in staff member may see this associate (always, unless they manage another brand). */
    public static function inScope(?array $associate): bool
    {
        $b = self::userBrandId();
        return $b === null || ($associate !== null && (int) ($associate['brand_id'] ?? 0) === $b);
    }

    /** Company-wide pages a brand manager may not change (D-432: they change only their own brand). */
    private const COMPANY_WIDE_POSTS = ['/associates/ladder', '/associates/access-levels', '/associates/registrations/settings',
        '/pricing/commission-levels', '/pricing/commission-ladder', '/pricing/settings/associate-price-rule', '/admin/brands'];

    /**
     * D-432: for a brand manager, the refusal (404 text) for a page or action of another brand, or of a company-wide setting;
     * null = allowed. Run by the router after the route's own sign-in / permission / CSRF checks.
     */
    public static function refusalForPath(string $path, string $method): ?string
    {
        $mine = self::userBrandId();
        if ($mine === null) {
            return null;
        }
        $pdo = Database::connection();
        $brandOf = static function (string $sql, int $id) use ($pdo): ?int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $v = $stmt->fetchColumn();
            return $v === false || $v === null ? null : (int) $v;
        };
        $other = 'Not found.';
        if ($method === 'POST' && (in_array($path, self::COMPANY_WIDE_POSTS, true) || preg_match('#^/pricing/commission-levels/\d+#', $path) || preg_match('#^/associates/\d+/brand$#', $path) || preg_match('#^/admin/brands/\d+$#', $path))) { // also brand details: never a brand manager
            return 'Not found.'; // a company-wide setting or brand details — only staff who look after all brands change them
        }
        if (preg_match('#^/admin/brands/(\d+)#', $path, $m)) {
            return (int) $m[1] === $mine ? null : $other;
        }
        if (preg_match('#^/associates/(\d+)(/|$)#', $path, $m)) {
            return $brandOf('SELECT brand_id FROM associates WHERE id = :id', (int) $m[1]) === $mine ? null : $other;
        }
        if (preg_match('#^/associates/(?:payouts|tds)/(\d+)/#', $path, $m)) {
            return $brandOf('SELECT a.brand_id FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id WHERE p.id = :id', (int) $m[1]) === $mine ? null : $other;
        }
        if (preg_match('#^/associates/price-requests/(\d+)/#', $path, $m)) {
            return $brandOf('SELECT a.brand_id FROM price_requests r JOIN associates a ON a.id = r.associate_id WHERE r.id = :id', (int) $m[1]) === $mine ? null : $other;
        }
        if (preg_match('#^/associates/registrations/links/(\d+)/#', $path, $m)) {
            return $brandOf('SELECT brand_id FROM associate_registration_links WHERE id = :id', (int) $m[1]) === $mine ? null : $other;
        }
        if (preg_match('#^/associates/registrations/(\d+)/#', $path, $m)) {
            return $brandOf('SELECT l.brand_id FROM associate_registrations r JOIN associate_registration_links l ON l.id = r.link_id WHERE r.id = :id', (int) $m[1]) === $mine ? null : $other;
        }
        // D-435: the catalogue. A brand manager sees their brand's and the company-wide categories and products,
        // and changes only their brand's (and a product they added that has no category yet).
        $catBrand = 'SELECT COALESCE(c.brand_id, m.brand_id) FROM product_categories c LEFT JOIN product_categories m ON m.id = c.parent_id WHERE c.id = :id';
        $catExists = static function (int $id) use ($pdo): bool {
            $st = $pdo->prepare('SELECT COUNT(*) FROM product_categories WHERE id = :id');
            $st->execute(['id' => $id]);
            return (int) $st->fetchColumn() > 0;
        };
        $decide = static function (?int $brand, bool $own = false) use ($mine, $method, $other): ?string {
            if ($brand !== null && $brand !== $mine) {
                return $other;
            }
            if ($brand === null && $method === 'POST' && !$own) {
                return 'Not found.'; // company-wide: view only for a brand manager
            }
            return null;
        };
        if ($method === 'POST' && in_array($path, ['/pricing/settings', '/pricing/specifications'], true)) {
            if ($path === '/pricing/specifications') {
                $cid = (int) ($_POST['category_id'] ?? 0);
                return $cid > 0 && $catExists($cid) ? $decide($brandOf($catBrand, $cid)) : null; // no category: the controller refuses it
            }
            return 'Not found.';
        }
        if (preg_match('#^/products/categories/(\d+)(/|$)#', $path, $m)) {
            return $catExists((int) $m[1]) ? $decide($brandOf($catBrand, (int) $m[1])) : null;
        }
        $productBrand = static function (int $pid) use ($pdo, $brandOf, $catBrand): array {
            $st = $pdo->prepare('SELECT category_id, created_by, submitted_by FROM catalog_products WHERE id = :id');
            $st->execute(['id' => $pid]);
            $p = $st->fetch(\PDO::FETCH_ASSOC);
            if ($p === false) {
                return [false, null, false];
            }
            $mineToo = (int) ($p['created_by'] ?? 0) === (int) (AuthService::currentUser()['id'] ?? -1) || (int) ($p['submitted_by'] ?? 0) === (int) (AuthService::currentUser()['id'] ?? -1);
            return [true, $p['category_id'] !== null ? $brandOf($catBrand, (int) $p['category_id']) : null, $mineToo && $p['category_id'] === null];
        };
        if (preg_match('#^/products/(?:(\d+)|\{pid\})(/|$)#', $path, $m) || preg_match('#^/products/(\d+)/own-specs/#', $path, $m)) {
            [$found, $brand, $own] = $productBrand((int) $m[1]);
            return $found ? $decide($brand, $own) : null;
        }
        if (preg_match('#^/pricing/specifications/(\d+)(/|$)#', $path, $m)) {
            $st = $pdo->prepare('SELECT category_id, product_id FROM spec_definitions WHERE id = :id');
            $st->execute(['id' => (int) $m[1]]);
            $d = $st->fetch(\PDO::FETCH_ASSOC);
            if ($d === false) {
                return null;
            }
            if ($d['product_id'] !== null) {
                [, $brand, $own] = $productBrand((int) $d['product_id']);
                return $decide($brand, $own);
            }
            return $d['category_id'] !== null ? $decide($brandOf($catBrand, (int) $d['category_id'])) : $decide(null);
        }
        if (preg_match('#^/pricing/spec-options/(\d+)(/|$)#', $path, $m)) {
            $st = $pdo->prepare('SELECT d.category_id, COALESCE(o.product_id, d.product_id) AS product_id FROM spec_options o JOIN spec_definitions d ON d.id = o.spec_id WHERE o.id = :id');
            $st->execute(['id' => (int) $m[1]]);
            $d = $st->fetch(\PDO::FETCH_ASSOC);
            if ($d === false) {
                return null;
            }
            if ($d['product_id'] !== null) {
                [, $brand, $own] = $productBrand((int) $d['product_id']);
                return $decide($brand, $own);
            }
            return $d['category_id'] !== null ? $decide($brandOf($catBrand, (int) $d['category_id'])) : $decide(null);
        }
        return null;
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
