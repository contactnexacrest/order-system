<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\IncentiveRepository;

/**
 * D-261 (owner approval 6 Nov 2026, mockups mock13): associate portal access levels.
 *
 * Each associate is on Starter, Quoting, Full or Custom. What Starter / Quoting / Full
 * show is the MD's matrix (Admin › Associates › Access levels); a Custom associate has
 * their own ticks. This class is the one place that decides — every portal page, menu
 * item, column, download and notice asks allows(). Hidden features are refused by the
 * server (the lock page, HTTP 403), not only hidden from the menu. Cost, margin,
 * supplier prices and CAFIN are never shown at any level (unchanged).
 */
final class AssociateAccess
{
    public const LEVELS = ['starter' => 'Starter', 'quoting' => 'Quoting', 'full' => 'Full'];
    public const ALL_LEVELS = self::LEVELS + ['custom' => 'Custom'];

    /** feature_key => [label, help]. 'profile' is always on. */
    public const FEATURES = [
        'profile' => ['Profile, bank & agreement', 'Always on'],
        'clients' => ['My clients', 'Count and list of clients they brought in'],
        'orders' => ["Clients' orders and stage", 'Order number, stage, last update'],
        'fob_value' => ['Show FOB order value', 'Order value of their own orders'],
        'commission' => ['Show commission per order', 'Amount earned, due, paid'],
        'commission_pct' => ['Show commission % on screen', 'The rate behind each amount'],
        'registration_links' => ['Registration links', 'Invite clients, personal code'],
        'catalogue' => ['Catalogue (no prices)', 'Products in the categories opened for them'],
        'get_price' => ['Get price / quoting', 'Within Price access categories; 10 a day, valid 7 days'],
        'price_requests' => ['Price requests ("Other")', 'Ask staff for a price not in the list'],
        'messages' => ['Messages', 'Messages with NexaCrest'],
        'order_documents' => ['Documents of their orders', 'PI, CI and other issued documents'],
        'tds_certificates' => ['TDS certificates', 'Form 16A / TDS deducted'],
        'commission_statement' => ['Commission statement download', 'PDF/CSV of commission'],
        'notice_amounts' => ['Order amounts in messages and e-mails', 'Amounts in notifications they receive'],
    ];

    /** The recommended defaults (mockup 6). "Restore recommended defaults" puts these back. */
    public const DEFAULTS = [
        'starter' => ['clients', 'orders', 'commission', 'registration_links', 'messages', 'tds_certificates'],
        'quoting' => ['clients', 'orders', 'fob_value', 'commission', 'commission_pct', 'registration_links', 'catalogue', 'get_price', 'price_requests', 'messages', 'tds_certificates', 'commission_statement'],
        'full' => ['clients', 'orders', 'fob_value', 'commission', 'commission_pct', 'registration_links', 'catalogue', 'get_price', 'price_requests', 'messages', 'order_documents', 'tds_certificates', 'commission_statement', 'notice_amounts'],
    ];

    /** Kinds of the associate's own documents that are TDS paperwork (shown only with "TDS certificates"). */
    public const TDS_KINDS = ['form_16a', 'form_15ca', 'form_15cb'];

    /** @var array<string, array<string,bool>>|null */
    private static ?array $matrixCache = null;

    public static function resetCache(): void
    {
        self::$matrixCache = null;
    }

    /** @return array<string, array<string,bool>> level => feature => allowed (the MD's matrix). */
    public static function matrix(): array
    {
        if (self::$matrixCache !== null) {
            return self::$matrixCache;
        }
        $m = self::defaultMatrix();
        foreach (Database::connection()->query('SELECT level, feature_key, allowed FROM associate_access_level_settings')->fetchAll() as $r) {
            if (isset($m[$r['level']]) && isset(self::FEATURES[$r['feature_key']]) && $r['feature_key'] !== 'profile') {
                $m[$r['level']][$r['feature_key']] = (int) $r['allowed'] === 1;
            }
        }
        return self::$matrixCache = $m;
    }

    /** @return array<string, array<string,bool>> */
    public static function defaultMatrix(): array
    {
        $m = [];
        foreach (array_keys(self::LEVELS) as $level) {
            foreach (array_keys(self::FEATURES) as $f) {
                $m[$level][$f] = $f === 'profile' || in_array($f, self::DEFAULTS[$level], true);
            }
        }
        return $m;
    }

    public static function level(array $associate): string
    {
        $l = (string) ($associate['access_level'] ?? 'full');
        return isset(self::ALL_LEVELS[$l]) ? $l : 'full';
    }

    /** @return array<string,bool> feature => allowed for this associate. */
    public static function features(array $associate): array
    {
        $level = self::level($associate);
        if ($level !== 'custom') {
            return self::matrix()[$level];
        }
        $out = self::matrix()['starter']; // a Custom associate starts from Starter for anything not ticked yet
        $stmt = Database::connection()->prepare('SELECT feature_key, allowed FROM associate_access_features WHERE associate_id = :a');
        $stmt->execute(['a' => (int) $associate['id']]);
        foreach ($stmt->fetchAll() as $r) {
            if (isset(self::FEATURES[$r['feature_key']])) {
                $out[$r['feature_key']] = (int) $r['allowed'] === 1;
            }
        }
        $out['profile'] = true;
        return $out;
    }

    public static function allows(array $associate, string $feature): bool
    {
        if ($feature === 'profile') {
            return true;
        }
        return (bool) (self::features($associate)[$feature] ?? false);
    }

    /**
     * The feature(s) a portal address needs (any one of them opens it); null when only the
     * login itself is needed (home, profile, bank, agreement, logout).
     * @return list<string>|null
     */
    public static function featuresForPath(string $method, string $path, array $query = [], array $post = []): ?array
    {
        $path = rtrim($path, '/');
        $method = strtoupper($method);
        $rules = [
            ['#^/associate/clients(/\d+/certificate)?$#', 'GET', ['clients']],
            ['#^/associate/(invitations|code)(/.*)?$#', 'POST', ['registration_links']],
            ['#^/associate/orders(/\d+)?$#', 'GET', ['orders']],
            ['#^/associate/(prices|catalogue)$#', 'GET', ['catalogue']],
            ['#^/associate/catalogue/\d+$#', 'GET', ['get_price']],
            ['#^/associate/catalogue/\d+/price$#', 'POST', !empty($post['other']) ? ['price_requests'] : ['get_price']],
            ['#^/associate/price-requests/\d+$#', 'GET', ['get_price', 'price_requests']],
            ['#^/associate/commission/\d+/invoice$#', 'POST', ['commission']],
            ['#^/associate/messages$#', '*', ['messages']],
        ];
        foreach ($rules as [$re, $m, $features]) {
            if (($m === '*' || $m === $method) && preg_match($re, $path) === 1) {
                return $features;
            }
        }
        if ($path === '/associate/commission' && $method === 'GET') {
            return (($query['format'] ?? '') === 'csv' || ($query['print'] ?? '') === '1') ? ['commission_statement'] : ['commission', 'tds_certificates'];
        }
        return null;
    }

    /** True when the associate may open this address. */
    public static function allowsPath(array $associate, string $method, string $path, array $query = [], array $post = []): bool
    {
        $needed = self::featuresForPath($method, $path, $query, $post);
        if ($needed === null) {
            return true;
        }
        foreach ($needed as $f) {
            if (self::allows($associate, $f)) {
                return true;
            }
        }
        return false;
    }

    /**
     * One order row for the portal with what this associate may not see taken out on the
     * server: the FOB value (order, lines, basis) and the commission % / level.
     */
    public static function filterOrder(array $associate, array $o): array
    {
        $fob = self::allows($associate, 'fob_value');
        $pct = self::allows($associate, 'commission_pct');
        if (!$fob) {
            $o['fob_value'] = null;
        }
        if (!$pct && isset($o['commission']) && is_array($o['commission'])) {
            $o['commission']['level'] = null;
            $o['commission']['rate'] = null;
            $o['commission']['rate_text'] = null; // D-292
        }
        // D-263 (owner 6 Nov 2026): an associate never sees the ladder level name (it points to an order-count band) —
        // only their rate, and only with "Show commission %".
        if ($pct && isset($o['commission']) && is_array($o['commission'])) {
            $o['commission']['level'] = self::rateOnly($o['commission']['level'] ?? null);
        }
        if (isset($o['commission']['detail']) && is_array($o['commission']['detail'])) {
            $d = $o['commission']['detail'];
            if (!$fob) {
                foreach ($d['lines'] as $i => $l) {
                    $d['lines'][$i]['fob'] = null;
                }
                $d['goods_fc'] = null;
                $d['basis_fc'] = null;
                $d['basis_inr'] = null;
                $d['reduction_fc'] = 0;
            }
            if (!$pct) {
                $d['level_label'] = null;
                $d['rate'] = null;
            } else {
                $d['level_label'] = self::rateOnly($d['level_label'] ?? null);
            }
            // D-263: never the running count of their orders nor the ladder threshold (owner rule: no total business).
            $d['counting_number'] = null;
            $d['threshold_inr'] = null;
            $d['portal'] = true;
            $d['show_fob'] = $fob;
            $d['show_pct'] = $pct;
            $o['commission']['detail'] = $d;
        }
        return $o;
    }

    /**
     * D-263: "Level 2 — 5%" → "5%" (the level name, which points to the ladder band, is dropped).
     * Text after the rate (e.g. "(for now — fixed when the PI is issued)") is kept.
     */
    public static function rateOnly(?string $levelText): ?string
    {
        if ($levelText === null || $levelText === '') {
            return $levelText;
        }
        $parts = explode(' — ', $levelText, 2);
        return count($parts) === 2 && preg_match('/^\s*(\d|USD)/', $parts[1]) === 1 ? $parts[1] : $levelText;
    }

    /**
     * A notice / e-mail for the associate with what they may not see blanked out:
     * money amounts without "Order amounts in messages and e-mails", percentages without
     * "Show commission % on screen".
     */
    public static function maskNotice(array $associate, string $message): string
    {
        // D-263: the order's FOB in a notice follows "Show FOB order value" (the commission amount itself stays).
        if (!self::allows($associate, 'fob_value')) {
            $message = (string) preg_replace('/(invoiced FOB of the goods|invoice value of the goods),\s*(?:[A-Z]{3}\s?)?-?\d[\d,]*(?:\.\d+)?/u', '$1', $message);
        }
        if (!self::allows($associate, 'notice_amounts')) {
            $message = (string) preg_replace('/(?:\b(?:USD|EUR|GBP|INR|AED|AUD|CAD|SGD|JPY|CNY|CHF|SAR)\s?|₹\s?|Rs\.?\s?)-?\d[\d,]*(?:\.\d+)?/u', '[amount in your portal]', $message);
        }
        if (!self::allows($associate, 'commission_pct')) {
            $message = (string) preg_replace('/\b\d+(?:\.\d+)?\s?%/u', '[rate in your agreement]', $message);
        }
        return $message;
    }

    // ------------------------------------------------------------ changes (MD only)

    /**
     * Sets an associate's level (and, for Custom, the ticks). Refused unless the user is
     * the MD and a reason of 10+ characters is given. @return string|null error
     */
    public static function setLevel(array $associate, string $level, array $customTicks, string $reason, array $user): ?string
    {
        if (!IncentiveService::isMd($user)) {
            return 'Only the Managing Director can change an associate\'s portal access.';
        }
        if (!isset(self::ALL_LEVELS[$level])) {
            return 'Choose a level.';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give the reason for the change (at least 10 characters).';
        }
        $before = self::describe($associate);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE associates SET access_level = :l WHERE id = :id')->execute(['l' => $level, 'id' => (int) $associate['id']]);
            if ($level === 'custom') {
                $pdo->prepare('DELETE FROM associate_access_features WHERE associate_id = :a')->execute(['a' => (int) $associate['id']]);
                $ins = $pdo->prepare('INSERT INTO associate_access_features (associate_id, feature_key, allowed) VALUES (:a, :f, :v)');
                foreach (array_keys(self::FEATURES) as $f) {
                    if ($f !== 'profile') {
                        $ins->execute(['a' => (int) $associate['id'], 'f' => $f, 'v' => in_array($f, $customTicks, true) ? 1 : 0]);
                    }
                }
            }
            $after = self::describe(['access_level' => $level] + $associate);
            $pdo->prepare('INSERT INTO associate_access_log (associate_id, changed_by, old_value, new_value, reason) VALUES (:a, :u, :o, :n, :r)')
                ->execute(['a' => (int) $associate['id'], 'u' => (int) $user['id'], 'o' => $before, 'n' => $after, 'r' => mb_substr($reason, 0, 500)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        \App\Repositories\AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_ACCESS_CHANGED', 'associates', (int) $associate['id'], 'access_level', $before, self::describe(['access_level' => $level] + $associate));
        return null;
    }

    /** Saves the level matrix ($ticks[level][] = feature). MD only, reason needed. @return string|null error */
    public static function saveMatrix(array $ticks, string $reason, array $user, bool $restoreDefaults = false): ?string
    {
        if (!IncentiveService::isMd($user)) {
            return 'Only the Managing Director can change the level settings.';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give the reason for the change (at least 10 characters).';
        }
        $before = self::matrix();
        $new = $restoreDefaults ? self::defaultMatrix() : [];
        if (!$restoreDefaults) {
            foreach (array_keys(self::LEVELS) as $level) {
                foreach (array_keys(self::FEATURES) as $f) {
                    $new[$level][$f] = $f === 'profile' || in_array($f, (array) ($ticks[$level] ?? []), true);
                }
            }
        }
        $changes = [];
        foreach ($new as $level => $fs) {
            foreach ($fs as $f => $on) {
                if ($f !== 'profile' && $on !== ($before[$level][$f] ?? false)) {
                    $changes[] = self::LEVELS[$level] . ': ' . self::FEATURES[$f][0] . ($on ? ' on' : ' off');
                }
            }
        }
        if ($changes === []) {
            return 'Nothing changed.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('INSERT INTO associate_access_level_settings (level, feature_key, allowed) VALUES (:l, :f, :v) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)');
            foreach ($new as $level => $fs) {
                foreach ($fs as $f => $on) {
                    if ($f !== 'profile') {
                        $up->execute(['l' => $level, 'f' => $f, 'v' => $on ? 1 : 0]);
                    }
                }
            }
            $pdo->prepare('INSERT INTO associate_access_log (associate_id, changed_by, old_value, new_value, reason) VALUES (NULL, :u, :o, :n, :r)')
                ->execute(['u' => (int) $user['id'], 'o' => $restoreDefaults ? 'Level settings' : null, 'n' => ($restoreDefaults ? 'Recommended defaults restored: ' : '') . implode('; ', $changes), 'r' => mb_substr($reason, 0, 500)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::resetCache();
        \App\Repositories\AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_ACCESS_LEVELS_CHANGED', 'associate_access_level_settings', null, 'matrix', null, implode('; ', $changes));
        return null;
    }

    /** "Starter" or "Custom: My clients, …" — what the history table shows. */
    public static function describe(array $associate): string
    {
        $level = self::level($associate);
        if ($level !== 'custom') {
            return self::ALL_LEVELS[$level];
        }
        $on = [];
        foreach (self::features($associate) as $f => $allowed) {
            if ($allowed && $f !== 'profile') {
                $on[] = self::FEATURES[$f][0];
            }
        }
        return 'Custom: ' . ($on === [] ? 'profile only' : implode(', ', $on));
    }

    /** @return list<array<string,mixed>> newest first; null associate = the level-settings history. */
    public static function history(?int $associateId, int $limit = 50): array
    {
        $sql = 'SELECT l.*, u.name AS changed_by_name FROM associate_access_log l LEFT JOIN users u ON u.id = l.changed_by WHERE '
            . ($associateId === null ? 'l.associate_id IS NULL' : 'l.associate_id = :a') . ' ORDER BY l.id DESC LIMIT ' . max(1, $limit);
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($associateId === null ? [] : ['a' => $associateId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,int> level => number of associates on it (Starter, Quoting, Full, Custom). */
    public static function countsPerLevel(): array
    {
        $out = array_fill_keys(array_keys(self::ALL_LEVELS), 0);
        foreach (Database::connection()->query('SELECT access_level, COUNT(*) AS n FROM associates GROUP BY access_level')->fetchAll() as $r) {
            $out[$r['access_level']] = (int) $r['n'];
        }
        return $out;
    }

    // ------------------------------------------------------------ track record / upgrade

    public static function upgradeAfter(): int
    {
        return max(1, (int) (IncentiveRepository::settings()['associate_upgrade_after_orders'] ?? 3));
    }

    /**
     * Clients brought in, orders, order value per currency, and how many orders are
     * completed and fully paid (owner, mockup 2).
     * @return array{clients:int, orders:int, value:array<string,float>, completed_paid:int, list:list<array<string,mixed>>}
     */
    public static function trackRecord(int $associateId): array
    {
        $pdo = Database::connection();
        $c = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE associate_id = :a');
        $c->execute(['a' => $associateId]);
        $stmt = $pdo->prepare(
            "SELECT o.id, o.order_reference, o.status, o.currency_code, o.created_at, cl.company_legal_name,
                    (SELECT COALESCE(SUM(op.fob_value), 0) FROM order_products op WHERE op.order_id = o.id AND op.is_active = 1 AND op.line_type <> 'service') AS order_value
             FROM orders o JOIN clients cl ON cl.id = o.client_id
             WHERE cl.associate_id = :a AND o.status NOT IN ('cancelled','lost')
             ORDER BY o.created_at DESC, o.id DESC"
        );
        $stmt->execute(['a' => $associateId]);
        $list = [];
        $value = [];
        $completedPaid = 0;
        foreach ($stmt->fetchAll() as $o) {
            $paid = IncentiveService::fullyPaidOn((int) $o['id']) !== null;
            $done = in_array($o['status'], ['complete', 'shipped_docs_sent'], true);
            $o['fully_paid'] = $paid;
            $o['completed'] = $done;
            if ($paid && $done) {
                $completedPaid++;
            }
            $cur = (string) ($o['currency_code'] ?: 'USD');
            $value[$cur] = ($value[$cur] ?? 0.0) + (float) $o['order_value'];
            $list[] = $o;
        }
        return ['clients' => (int) $c->fetchColumn(), 'orders' => count($list), 'value' => $value, 'completed_paid' => $completedPaid, 'list' => $list];
    }

    /** True when the MD should see "Ready for the Quoting level?" for this associate. */
    public static function suggestUpgrade(array $associate, ?int $completedPaid = null): bool
    {
        if (self::level($associate) !== 'starter') {
            return false;
        }
        $n = $completedPaid ?? self::trackRecord((int) $associate['id'])['completed_paid'];
        if ($n < self::upgradeAfter()) {
            return false;
        }
        $dismissed = $associate['upgrade_dismissed_at_count'] ?? null;
        return $dismissed === null || $n > (int) $dismissed; // "Not yet" hides it until another order is completed and paid
    }

    /** "Not yet": hides the suggestion until one more order is completed and fully paid. MD only. */
    public static function dismissUpgrade(array $associate, array $user): ?string
    {
        if (!IncentiveService::isMd($user)) {
            return 'Only the Managing Director can answer this.';
        }
        $n = self::trackRecord((int) $associate['id'])['completed_paid'];
        Database::connection()->prepare('UPDATE associates SET upgrade_dismissed_at_count = :n WHERE id = :id')->execute(['n' => $n, 'id' => (int) $associate['id']]);
        return null;
    }

    /** @return list<array{id:int, name:string, completed_paid:int}> associates the MD is asked about (dashboard). */
    public static function upgradeSuggestions(): array
    {
        $out = [];
        foreach (Database::connection()->query("SELECT * FROM associates WHERE access_level = 'starter' AND status NOT IN ('ended','inactive')")->fetchAll() as $a) {
            $n = self::trackRecord((int) $a['id'])['completed_paid'];
            if (self::suggestUpgrade($a, $n)) {
                $out[] = ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'completed_paid' => $n];
            }
        }
        return $out;
    }
}
