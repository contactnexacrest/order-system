<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Owner decision D-245 P5 (performance): while a report works through many orders, the
 * settings and per-order rows it needs are read once instead of several times per order.
 * Only code running inside within() sees the cache; outside, memo() simply calls through
 * (every normal page reads fresh values). PHP arrays are values, so callers never share
 * a mutable copy. Twin of Node src/helpers/batchCache.js.
 */
final class BatchCache
{
    /** @var array<string, mixed>|null */
    private static ?array $store = null;

    /** Runs $fn with a fresh cache; nested calls reuse the outer one. */
    public static function within(callable $fn): mixed
    {
        if (self::$store !== null) {
            return $fn();
        }
        self::$store = [];
        try {
            return $fn();
        } finally {
            self::$store = null;
        }
    }

    /** The cached result of $loader() for $key inside a batch; a plain call outside one. */
    public static function memo(string $key, callable $loader): mixed
    {
        if (self::$store === null) {
            return $loader();
        }
        if (!array_key_exists($key, self::$store)) {
            self::$store[$key] = $loader();
        }
        return self::$store[$key];
    }
}
