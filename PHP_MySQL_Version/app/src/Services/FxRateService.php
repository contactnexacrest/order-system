<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\PricingRepository;

/**
 * Owner decision D-301 (point 4, 11 Nov 2026): the USD → INR exchange rate.
 *
 *  - Automatic (default): fetched once a day (cron/fetch_fx_rate.php) from
 *    Frankfurter (European Central Bank rates), or open.er-api.com when that
 *    fails. Every fetch is kept (fx_rates) and shown on Pricing settings.
 *  - Pricing rate = market rate × (1 − cushion %); cushion 2 % by default,
 *    0 to 5 % with one decimal. It keeps our prices safe if the rupee gets
 *    stronger before the buyer pays.
 *  - A fetch that fails keeps the last rate in use, with a warning.
 *  - A change of more than 2 % against the rate in use waits for the MD's OK.
 *  - Manual: the "Pricing exchange rate" typed on Pricing settings (as before);
 *    it is also used until the first rate has been fetched.
 * Prices already issued on a Quotation or Proforma Invoice never change (they
 * are fixed on the order line).
 *
 * The HTTP call is injectable (a callable url => body|null) so tests never
 * touch the network. Node twin: src/services/fxRateService.js.
 */
final class FxRateService
{
    public const SOURCES = [
        ['name' => 'Frankfurter (European Central Bank)', 'url' => 'https://api.frankfurter.app/latest?from=USD&to=INR'],
        ['name' => 'open.er-api.com', 'url' => 'https://open.er-api.com/v6/latest/USD'],
    ];

    /** A daily change above this waits for the MD. */
    public const JUMP_PCT = 2.0;
    public const CUSHION_MIN = 0.0;
    public const CUSHION_MAX = 5.0;

    /** @var callable|null */
    private static $http = null;

    /** Tests: replace the HTTP fetcher (url => body string, or null on failure). */
    public static function setHttp(?callable $http): void
    {
        self::$http = $http;
    }

    private static function get(string $url): ?string
    {
        if (self::$http !== null) {
            return (self::$http)($url);
        }
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => false, 'header' => "Accept: application/json\r\nUser-Agent: exchange-rate-check\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    /** The rate from a source's JSON (₹ per USD), or null when it is not there or not sensible. */
    public static function parse(string $body): ?float
    {
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return null;
        }
        $v = $j['rates']['INR'] ?? null;
        if (!is_numeric($v)) {
            return null;
        }
        $v = (float) $v;
        return $v > 1 && $v < 1000 ? $v : null;
    }

    /** @return array{mode: string, cushion: float, manual: float} */
    public static function config(?array $raw = null): array
    {
        $raw ??= PricingRepository::settings() + PricingService::DEFAULTS;
        $cushion = is_numeric($raw['fx_cushion_pct'] ?? null) ? (float) $raw['fx_cushion_pct'] : 2.0;
        return [
            'mode' => ($raw['fx_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto',
            'cushion' => max(self::CUSHION_MIN, min(self::CUSHION_MAX, $cushion)),
            'manual' => (float) ($raw['pricing_fx_rate'] ?? 82),
        ];
    }

    /** market × (1 − cushion %), 4 decimals. */
    public static function pricingRateFor(float $market, float $cushionPct): float
    {
        return round($market * (1 - $cushionPct / 100), 4);
    }

    /** The newest rate in use (approved), or null. */
    public static function latestInUse(): ?array
    {
        return \App\Helpers\BatchCache::memo('fx_rate_in_use', static function (): ?array {
            $row = Database::connection()->query("SELECT * FROM fx_rates WHERE status = 'in_use' AND market_rate IS NOT NULL ORDER BY rate_date DESC, id DESC LIMIT 1")->fetch();
            return $row ?: null;
        });
    }

    /** Just the pricing rate (₹ per USD) prices are built with now. */
    public static function rate(?array $raw = null): float
    {
        $cfg = self::config($raw);
        if ($cfg['mode'] === 'manual') {
            return $cfg['manual'];
        }
        $row = self::latestInUse();
        return $row === null ? $cfg['manual'] : self::pricingRateFor((float) $row['market_rate'], $cfg['cushion']);
    }

    /**
     * The rate prices are built with now, and what to say about it.
     *
     * @return array{rate: float, mode: string, market: ?float, cushion: float, source: ?string, date: ?string,
     *               warnings: list<string>, waiting: ?array}
     */
    public static function current(?array $raw = null): array
    {
        $cfg = self::config($raw);
        $out = ['rate' => $cfg['manual'], 'mode' => $cfg['mode'], 'market' => null, 'cushion' => $cfg['cushion'], 'source' => null, 'date' => null, 'warnings' => [], 'waiting' => null];
        if ($cfg['mode'] === 'manual') {
            return $out;
        }
        $row = self::latestInUse();
        if ($row === null) {
            $out['warnings'][] = 'No market rate has been fetched yet — prices use the manual rate ₹' . number_format($cfg['manual'], 2) . ' until the first daily fetch.';
            return $out;
        }
        $out['market'] = (float) $row['market_rate'];
        $out['rate'] = self::pricingRateFor((float) $row['market_rate'], $cfg['cushion']);
        $out['source'] = (string) $row['source'];
        $out['date'] = (string) $row['rate_date'];
        $today = date('Y-m-d');
        $latest = Database::connection()->query('SELECT * FROM fx_rates ORDER BY rate_date DESC, id DESC LIMIT 1')->fetch() ?: null;
        if ($latest !== null && $latest['status'] === 'waiting_md') {
            $out['waiting'] = $latest;
            $out['warnings'][] = 'Today\'s market rate ₹' . number_format((float) $latest['market_rate'], 2) . ' is ' . self::signed((float) $latest['change_pct'])
                . ' against the rate in use — it waits for the MD\'s OK. Prices still use ₹' . number_format((float) $row['market_rate'], 2) . ' (' . $row['rate_date'] . ').';
        } elseif ($latest !== null && $latest['status'] === 'failed' && (string) $latest['rate_date'] === $today) {
            $out['warnings'][] = 'Today\'s rate could not be fetched (' . (string) $latest['note'] . ') — prices keep the rate of ' . $row['rate_date'] . '.';
        } elseif ((string) $row['rate_date'] < date('Y-m-d', strtotime('-1 day')) && ($latest === null || $latest['status'] !== 'rejected')) {
            $out['warnings'][] = 'The rate in use is from ' . $row['rate_date'] . ' — the daily fetch has not run since. Check the scheduled job.';
        }
        return $out;
    }

    /** "+2.40 %" / "−3.10 %". */
    public static function signed(float $v): string
    {
        return ($v < 0 ? '−' : '+') . number_format(abs($v), 2) . ' %';
    }

    /**
     * The daily fetch. Tries the main source, then the backup. A change over 2 %
     * against the rate in use waits for the MD (in-app notice to manage_pricing).
     *
     * @return array{status: string, market: ?float, source: ?string, change_pct: ?float, message: string}
     */
    public static function fetchDaily(?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $rate = null;
        $source = null;
        $errors = [];
        foreach (self::SOURCES as $s) {
            $body = self::get($s['url']);
            $v = $body !== null ? self::parse($body) : null;
            if ($v !== null) {
                $rate = $v;
                $source = $s['name'];
                break;
            }
            $errors[] = $s['name'] . ($body === null ? ' did not answer' : ' gave no INR rate');
        }
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        if ($rate === null) {
            $note = mb_substr(implode('; ', $errors), 0, 255);
            $existing = self::forDate($date);
            if ($existing === null || $existing['status'] === 'failed') {
                $pdo->prepare('INSERT INTO fx_rates (rate_date, market_rate, source, status, note, fetched_at) VALUES (:d, NULL, NULL, \'failed\', :n, :t)
                               ON DUPLICATE KEY UPDATE note = VALUES(note), fetched_at = VALUES(fetched_at)')
                    ->execute(['d' => $date, 'n' => $note, 't' => $now]);
            }
            return ['status' => 'failed', 'market' => null, 'source' => null, 'change_pct' => null, 'message' => 'Exchange rate not fetched: ' . $note . '. The last rate stays in use.'];
        }
        $inUse = self::latestInUseExcept($date);
        $change = $inUse !== null ? ($rate / (float) $inUse['market_rate'] - 1) * 100 : null;
        $status = $change !== null && abs($change) > self::JUMP_PCT ? 'waiting_md' : 'in_use';
        $existing = self::forDate($date);
        if ($existing !== null && in_array($existing['status'], ['in_use', 'rejected'], true) && $existing['decided_by'] !== null) {
            // The MD already decided on today's rate — a second run does not undo that.
            return ['status' => (string) $existing['status'], 'market' => (float) $existing['market_rate'], 'source' => (string) $existing['source'],
                'change_pct' => $existing['change_pct'] !== null ? (float) $existing['change_pct'] : null, 'message' => 'Today\'s rate was already decided by the MD.'];
        }
        $pdo->prepare('INSERT INTO fx_rates (rate_date, market_rate, source, status, change_pct, note, fetched_at) VALUES (:d, :r, :s, :st, :c, NULL, :t)
                       ON DUPLICATE KEY UPDATE market_rate = VALUES(market_rate), source = VALUES(source), status = VALUES(status),
                                               change_pct = VALUES(change_pct), note = NULL, fetched_at = VALUES(fetched_at)')
            ->execute(['d' => $date, 'r' => number_format($rate, 4, '.', ''), 's' => $source, 'st' => $status,
                'c' => $change !== null ? number_format($change, 3, '.', '') : null, 't' => $now]);
        $msg = 'Exchange rate ' . $date . ': ₹' . number_format($rate, 4) . ' per USD from ' . $source . ($change !== null ? ' (' . self::signed($change) . ')' : '') . '.';
        if ($status === 'waiting_md') {
            $text = 'The market rate moved ' . self::signed($change) . ' today (₹' . number_format((float) $inUse['market_rate'], 2) . ' → ₹' . number_format($rate, 2)
                . '). It is not used for prices until you approve it: Products › Pricing settings › Exchange rate.';
            foreach (PermissionRepository::usersWithPermission('manage_pricing') as $mdId) {
                NotificationRepository::createInAppOnly((int) $mdId, 'fx_rate_approval', null, mb_substr($text, 0, 500));
            }
            $msg .= ' Over ' . self::JUMP_PCT . ' % — waits for the MD.';
        }
        return ['status' => $status, 'market' => $rate, 'source' => $source, 'change_pct' => $change, 'message' => $msg];
    }

    public static function forDate(string $date): ?array
    {
        $st = Database::connection()->prepare('SELECT * FROM fx_rates WHERE rate_date = :d');
        $st->execute(['d' => $date]);
        return $st->fetch() ?: null;
    }

    private static function latestInUseExcept(string $date): ?array
    {
        $st = Database::connection()->prepare("SELECT * FROM fx_rates WHERE status = 'in_use' AND market_rate IS NOT NULL AND rate_date <> :d ORDER BY rate_date DESC, id DESC LIMIT 1");
        $st->execute(['d' => $date]);
        return $st->fetch() ?: null;
    }

    /** The MD's decision on a rate that moved more than 2 %. Returns an error, or null. */
    public static function decide(int $id, bool $approve, string $reason, int $userId): ?string
    {
        $st = Database::connection()->prepare('SELECT * FROM fx_rates WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch() ?: null;
        if ($row === null || $row['status'] !== 'waiting_md') {
            return 'That rate is not waiting for a decision.';
        }
        $reasonError = \App\Helpers\ReasonValidator::check($reason);
        if ($reasonError !== null) {
            return $reasonError;
        }
        Database::connection()->prepare('UPDATE fx_rates SET status = :s, decided_by = :u, decided_at = NOW(), decision_reason = :r WHERE id = :id')
            ->execute(['s' => $approve ? 'in_use' : 'rejected', 'u' => $userId, 'r' => mb_substr($reason, 0, 255), 'id' => $id]);
        AuditLogRepository::log($userId, $approve ? 'FX_RATE_APPROVED' : 'FX_RATE_REJECTED', 'fx_rates', $id, 'status', 'waiting_md',
            ($approve ? 'in_use' : 'rejected') . ' — ₹' . number_format((float) $row['market_rate'], 4) . ' (' . $row['rate_date'] . ')', $reason);
        return null;
    }

    /** @return list<array<string,mixed>> newest first */
    public static function history(int $limit = 30): array
    {
        $st = Database::connection()->prepare('SELECT f.*, u.name AS decided_by_name FROM fx_rates f LEFT JOIN users u ON u.id = f.decided_by ORDER BY f.rate_date DESC, f.id DESC LIMIT ' . max(1, min(366, $limit)));
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Reads the exchange-rate part of the Pricing settings form.
     *
     * @return array{0: array<string,string>, 1: list<string>}
     */
    public static function readForm(array $post): array
    {
        $values = [];
        $errors = [];
        if (array_key_exists('fx_mode', $post)) {
            $mode = (string) $post['fx_mode'];
            if (!in_array($mode, ['auto', 'manual'], true)) {
                $errors[] = 'Choose Automatic or Manual for the exchange rate.';
            } else {
                $values['fx_mode'] = $mode;
            }
        }
        if (array_key_exists('fx_cushion_pct', $post)) {
            $c = trim((string) $post['fx_cushion_pct']);
            if (!preg_match('/^\d(\.\d)?$/', $c) || (float) $c < self::CUSHION_MIN || (float) $c > self::CUSHION_MAX) {
                $errors[] = 'Cushion below the market rate: enter 0 to 5 % with at most one decimal (e.g. 2 or 2.5).';
            } else {
                $values['fx_cushion_pct'] = number_format((float) $c, 1, '.', '');
            }
        }
        return [$values, $errors];
    }
}
