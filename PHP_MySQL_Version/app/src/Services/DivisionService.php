<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * Owner decision D-257 (5 Nov 2026): divisions. One company, several
 * divisions (Export today; Domestic Trade, Merchant Trade and Import &
 * Distribution later). Every order belongs to one division for life; every
 * staff member works in one division and sees only it; company-level people
 * (permission see_all_divisions) see every division, or one they pick.
 *
 * Divisions stay invisible until two or more are ACTIVE: with one active
 * division nobody sees a switcher, a Division filter or a Division field.
 *
 * This class is the one place that decides "which divisions does this
 * request see". Every list, report, count and order check asks it. Port:
 * divisionService.js.
 */
final class DivisionService
{
    public const EXPORT_ID = 1;
    public const SESSION_CHOICE = '_division_choice';

    /** @var list<array<string,mixed>>|null */
    private static ?array $all = null;
    /** @var array{key:string, ids:list<int>|null}|null */
    private static ?array $scope = null;
    /** @var list<int> forced scope stack (e.g. while generating a document for one order) */
    private static array $forced = [];
    private static bool $resolving = false;
    /** @var array<string, array<string,string>> */
    private static array $settingsCache = [];
    /** @var array<int, array<string,bool>> */
    private static array $modulesCache = [];

    public static function resetCache(): void
    {
        self::$all = null;
        self::$scope = null;
        self::$settingsCache = [];
        self::$modulesCache = [];
        self::$outside = null;
        self::$forced = [];
    }

    /** @return list<array<string,mixed>> every division, in order */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = Database::connection()->query('SELECT * FROM divisions ORDER BY sort_order, id')->fetchAll();
        }
        return self::$all;
    }

    /** @return list<array<string,mixed>> */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), static fn (array $d): bool => $d['status'] === 'active'));
    }

    public static function find(int $id): ?array
    {
        foreach (self::all() as $d) {
            if ((int) $d['id'] === $id) {
                return $d;
            }
        }
        return null;
    }

    /** Divisions become visible (switcher, filters, fields) only when two or more are active. */
    public static function isMulti(): bool
    {
        return count(self::active()) >= 2;
    }

    public static function seesAll(?array $user = null): bool
    {
        $user ??= AuthService::currentUser();
        if (!$user) {
            return false;
        }
        return PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'see_all_divisions');
    }

    /** The division a person works in (company-level people: Export until they choose). */
    public static function userDivisionId(?array $user): int
    {
        return (int) ($user['division_id'] ?? self::EXPORT_ID) ?: self::EXPORT_ID;
    }

    /**
     * Which divisions this request sees: null = every division (no filter),
     * else the list of division ids. No signed-in staff user (cron, CLI,
     * client portal) = no filter.
     *
     * @return list<int>|null
     */
    public static function currentIds(): ?array
    {
        if (self::$forced !== []) {
            return [end(self::$forced)];
        }
        if (self::$resolving) {
            return null; // re-entered while working out the scope (a setting read during sign-in checks)
        }
        self::$resolving = true;
        try {
            $user = AuthService::currentUser();
        } finally {
            self::$resolving = false;
        }
        if (!$user) {
            return null;
        }
        $key = $user['id'] . ':' . ($user['role_id'] ?? '-') . ':' . ($_SESSION[self::SESSION_CHOICE] ?? '');
        if (self::$scope !== null && self::$scope['key'] === $key) {
            return self::$scope['ids'];
        }
        self::$resolving = true;
        try {
            $seesAll = self::seesAll($user);
        } finally {
            self::$resolving = false;
        }
        if ($seesAll) {
            $choice = $_SESSION[self::SESSION_CHOICE] ?? 'all';
            $ids = null;
            if ($choice !== 'all' && self::isMulti()) {
                $d = self::find((int) $choice);
                $ids = ($d !== null && $d['status'] === 'active') ? [(int) $d['id']] : null;
            }
        } else {
            $ids = [self::userDivisionId($user)];
        }
        self::$scope = ['key' => $key, 'ids' => $ids];
        return $ids;
    }

    /** The single division this request works in, or null when it sees all. */
    public static function currentId(): ?int
    {
        $ids = self::currentIds();
        return $ids !== null && count($ids) === 1 ? $ids[0] : null;
    }

    /** "col IN (…)" for the current scope, or null when nothing needs filtering. */
    public static function condition(string $column = 'o.division_id'): ?string
    {
        $ids = self::currentIds();
        if ($ids === null) {
            return null;
        }
        return $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
    }

    /** " AND col IN (…)" or "" — ids are integers, safe to inline. */
    public static function sql(string $column = 'o.division_id'): string
    {
        $c = self::condition($column);
        return $c === null ? '' : ' AND ' . $c;
    }

    /**
     * " AND col IN (orders of the current scope)" for a table that carries an
     * order_id, or "" when nothing needs filtering. $allowNull keeps rows that
     * belong to no order (company-level rows).
     */
    public static function orderIdSql(string $column, bool $allowNull = false): string
    {
        $c = self::condition('dv_o.division_id');
        if ($c === null) {
            return '';
        }
        $in = "{$column} IN (SELECT dv_o.id FROM orders dv_o WHERE {$c})";
        return $allowNull ? " AND ({$column} IS NULL OR {$in})" : " AND {$in}";
    }

    /**
     * " AND …" limiting `clients {$alias}` to the clients of the current
     * scope: linked to one of its divisions in client_divisions, or (Export
     * only) linked to none — every client from before divisions is Export's.
     */
    public static function clientSql(string $alias = 'c'): string
    {
        $ids = self::currentIds();
        if ($ids === null) {
            return '';
        }
        $in = implode(',', array_map('intval', $ids));
        $sql = " AND (EXISTS (SELECT 1 FROM client_divisions cdv WHERE cdv.client_id = {$alias}.id AND cdv.division_id IN ({$in}))";
        if (in_array(self::EXPORT_ID, $ids, true)) {
            $sql .= " OR NOT EXISTS (SELECT 1 FROM client_divisions cdv2 WHERE cdv2.client_id = {$alias}.id)";
        }
        return $sql . ')';
    }

    /** Whether the current scope may see this client. */
    public static function allowsClient(int $clientId): bool
    {
        $ids = self::currentIds();
        if ($ids === null) {
            return true;
        }
        $stmt = Database::connection()->prepare('SELECT division_id FROM client_divisions WHERE client_id = :c');
        $stmt->execute(['c' => $clientId]);
        $linked = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if ($linked === []) {
            return in_array(self::EXPORT_ID, $ids, true);
        }
        return array_intersect($linked, $ids) !== [];
    }

    /** Links a client to a division (a client may buy from several). An unlinked client is Export's, so keep that link too. */
    public static function linkClient(int $clientId, int $divisionId): void
    {
        $pdo = Database::connection();
        if ($divisionId !== self::EXPORT_ID) {
            $has = $pdo->prepare('SELECT 1 FROM client_divisions WHERE client_id = :c LIMIT 1');
            $has->execute(['c' => $clientId]);
            if ($has->fetchColumn() === false) {
                $exp = $pdo->prepare('SELECT 1 FROM orders WHERE client_id = :c AND division_id = :d LIMIT 1');
                $exp->execute(['c' => $clientId, 'd' => self::EXPORT_ID]);
                if ($exp->fetchColumn() !== false) {
                    $pdo->prepare('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)')->execute(['c' => $clientId, 'd' => self::EXPORT_ID]);
                }
            }
        }
        $pdo->prepare('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)')->execute(['c' => $clientId, 'd' => $divisionId]);
    }

    /** @var array{c:string, ids:array<int,true>}|null orders outside the current scope */
    private static ?array $outside = null;

    /** Whether the current scope may see this order (one query per request, whatever the number of calls). */
    public static function allowsOrderId(int $orderId): bool
    {
        $c = self::condition('division_id');
        if ($c === null) {
            return true;
        }
        if (self::$outside === null || self::$outside['c'] !== $c) {
            $ids = Database::connection()->query('SELECT id FROM orders WHERE NOT (' . $c . ')')->fetchAll(\PDO::FETCH_COLUMN);
            self::$outside = ['c' => $c, 'ids' => array_fill_keys(array_map('intval', $ids), true)];
        }
        return !isset(self::$outside['ids'][$orderId]);
    }

    /** Whether the current scope includes this division. */
    public static function allows(int $divisionId): bool
    {
        $ids = self::currentIds();
        return $ids === null || in_array($divisionId, $ids, true);
    }

    /**
     * The division a new record (order, client, associate) belongs to:
     * the person's own division; for company-level people the division they
     * picked, else (one active division) Export, else the one they chose on
     * the form (validated), else their own.
     */
    public static function forNewRecord(?int $chosen = null): int
    {
        $user = AuthService::currentUser();
        if (!$user) {
            return self::EXPORT_ID;
        }
        if (!self::seesAll($user)) {
            return self::userDivisionId($user);
        }
        if ($chosen !== null) {
            $d = self::find($chosen);
            if ($d !== null && $d['status'] === 'active') {
                return (int) $d['id'];
            }
        }
        $current = self::currentId();
        if ($current !== null) {
            return $current;
        }
        $active = self::active();
        return count($active) === 1 ? (int) $active[0]['id'] : self::userDivisionId($user);
    }

    /** Whether a new-order/new-client form must ask which division (company level, several active, "All" chosen). */
    public static function needsChoiceOnCreate(): bool
    {
        return self::isMulti() && self::seesAll() && self::currentId() === null;
    }

    /** Runs $fn with the scope forced to one division (documents, numbering for an order). */
    public static function withDivision(int $divisionId, callable $fn): mixed
    {
        self::$forced[] = $divisionId;
        self::$settingsCache = [];
        try {
            return $fn();
        } finally {
            array_pop(self::$forced);
            self::$settingsCache = [];
        }
    }

    /**
     * For the rest of this request, work in an order's own division (order
     * pages: numbering, settings and lists follow the order). Called once the
     * access check has passed.
     */
    public static function enterOrderDivision(int $divisionId): void
    {
        self::$forced[] = $divisionId;
        self::$settingsCache = [];
    }

    public static function leaveOrderDivision(): void
    {
        array_pop(self::$forced);
        self::$settingsCache = [];
    }

    public static function choose(string $choice): void
    {
        if ($choice === 'all') {
            $_SESSION[self::SESSION_CHOICE] = 'all';
        } else {
            $d = self::find((int) $choice);
            if ($d !== null && $d['status'] === 'active') {
                $_SESSION[self::SESSION_CHOICE] = (string) (int) $d['id'];
            }
        }
        self::$scope = null;
        self::$settingsCache = [];
    }

    /** The switcher label for company-level people ("All divisions" or a division name). */
    public static function choiceLabel(): string
    {
        $id = self::currentId();
        $d = $id !== null ? self::find($id) : null;
        return $d !== null ? (string) $d['name'] : 'All divisions';
    }

    /** Whether the header switcher shows: company level and two or more active divisions. */
    public static function showSwitcher(): bool
    {
        return self::isMulti() && self::seesAll();
    }

    /**
     * A division's own setting values (scope: company | pricing | incentive),
     * applied over the company values only while the request works in exactly
     * one division. Export has none, so nothing changes.
     *
     * @return array<string,string>
     */
    public static function settingOverrides(string $scope): array
    {
        $id = self::currentId();
        if ($id === null) {
            return [];
        }
        $key = $scope . ':' . $id;
        if (!isset(self::$settingsCache[$key])) {
            $stmt = Database::connection()->prepare('SELECT setting_key, setting_value FROM division_settings WHERE division_id = :d AND scope = :s');
            $stmt->execute(['d' => $id, 's' => $scope]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
            self::$settingsCache[$key] = $out;
        }
        return self::$settingsCache[$key];
    }

    /** @param array<string,string> $values */
    public static function overlay(string $scope, array $values): array
    {
        $over = self::settingOverrides($scope);
        return $over === [] ? $values : array_replace($values, $over);
    }

    public static function overrideFor(string $scope, string $key): ?string
    {
        $over = self::settingOverrides($scope);
        return array_key_exists($key, $over) ? $over[$key] : null;
    }

    // ---------------------------------------------------------------- modules

    /** Module registry: key => [label, URL path patterns that belong to the module]. */
    public const MODULES = [
        'sales_documents'   => ['Quotation, PI and order confirmation', []],
        'supplier_purchase' => ['Supplier purchase and RFQ', ['#^/procurement(?:/|$)#']],
        'export_shipping'   => ['Export shipping (BL, shipping bill, CHA, COO)', []],
        'lut_igst'          => ['LUT / IGST on export and GST refunds', ['#^/ca/gst-refunds(?:/|$)#']],
        'export_benefits'   => ['Export benefits (RoDTEP, drawback)', ['#^/ca/export-benefits(?:/|$)#']],
        'firc_fema'         => ['FIRC / eBRC / FEMA', ['#^/reports/missing-ebrc(?:/|$)#']],
        'ecgc'              => ['ECGC', []],
        'associates'        => ['Associates, price requests and pricing', ['#^/associates(?:/|$)#', '#^/reports/associates(?:/|$)#']],
        'team_incentive'    => ['Team incentive and CAFIN', ['#^/incentives(?:/|$)#', '#^/my-incentives(?:/|$)#', '#^/reports/incentives(?:/|$)#']],
        // D-257 Phase 3: imports, landed cost and stock — on for Import & Distribution only.
        'import_stock'      => ['Import, stock and landed cost', ['#^/import(?:/|$)#']],
        // D-257 Phase 4: merchant trade deals and their RBI checks — on for Merchant Trade only.
        'merchant_trade'    => ['Merchant trade', ['#^/merchant(?:/|$)#']],
    ];

    /** Whether a module is on for the current scope ("All" = on in any active division). */
    public static function moduleEnabled(string $module): bool
    {
        $ids = self::currentIds() ?? array_map(static fn (array $d): int => (int) $d['id'], self::active());
        foreach ($ids as $id) {
            if (self::moduleEnabledFor($id, $module)) {
                return true;
            }
        }
        return false;
    }

    public static function moduleEnabledFor(int $divisionId, string $module): bool
    {
        if (!isset(self::$modulesCache[$divisionId])) {
            $stmt = Database::connection()->prepare('SELECT module_key, is_enabled FROM division_modules WHERE division_id = :d');
            $stmt->execute(['d' => $divisionId]);
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[(string) $row['module_key']] = (int) $row['is_enabled'] === 1;
            }
            self::$modulesCache[$divisionId] = $map;
        }
        // A module with no row is on — Export's modules all start on.
        return self::$modulesCache[$divisionId][$module] ?? true;
    }

    /** The module a URL belongs to, when that module is switched off for the current scope; else null. */
    public static function disabledModuleForPath(string $path): ?string
    {
        foreach (self::MODULES as $key => [$label, $patterns]) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path) && !self::moduleEnabled($key)) {
                    return $label;
                }
            }
        }
        return null;
    }
}
