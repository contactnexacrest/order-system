<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Minimal .env loader.
 *
 * Judgment call (flagged in ARCHITECTURE.md): the original stack proposal
 * named vlucas/phpdotenv for this. Parsing KEY=VALUE lines is a handful of
 * lines of code, so this hand-rolled loader replaces that dependency —
 * one fewer Composer package to install on a shared-hosting account, same
 * end result (values land in $_ENV / getenv()). If you'd rather use the
 * real vlucas/phpdotenv package instead, swap the body of load() for
 * Dotenv\Dotenv::createImmutable($path)->load() — nothing else in the
 * app needs to change, since everything else reads via Env::get().
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $envFilePath): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($envFilePath) || !is_readable($envFilePath)) {
            throw new \RuntimeException(
                "Environment file not found or not readable: {$envFilePath}. " .
                "Copy .env.example to .env in the same folder and fill in real values."
            );
        }

        $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Could not read environment file: {$envFilePath}");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip matching surrounding quotes, if any.
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key === '') {
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function isLocal(): bool
    {
        return strtolower(self::get('APP_ENV', 'production') ?? 'production') === 'local';
    }
}
