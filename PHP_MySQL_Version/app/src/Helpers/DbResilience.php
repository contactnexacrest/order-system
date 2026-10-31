<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * QA Round 7, R7-N04 (parity with Node's helpers/dbResilience.js): a short
 * database problem (too many connections, a restart, a network blip) fails
 * the request it touches with 503 "please try again" — not a 500 page that
 * reads like a bug.
 */
final class DbResilience
{
    public const UNAVAILABLE_MESSAGE = 'The database is busy or briefly unavailable. Nothing was saved — please wait a moment and try again.';

    /** MySQL / client error numbers that mean "unreachable right now". */
    private const TRANSIENT_CODES = [
        1040, // ER_CON_COUNT_ERROR: Too many connections
        1203, // ER_TOO_MANY_USER_CONNECTIONS
        1053, // ER_SERVER_SHUTDOWN
        2002, // CR_CONNECTION_ERROR: can't connect (refused / socket)
        2003, // CR_CONN_HOST_ERROR
        2006, // CR_SERVER_GONE_ERROR
        2013, // CR_SERVER_LOST
    ];

    public static function isTransient(?\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($code === 0 && preg_match('/SQLSTATE\[[0-9A-Z]+\] \[(\d+)\]/', $e->getMessage(), $m) === 1) {
            $code = (int) $m[1]; // a failed connect carries the number only in the message
        }
        return in_array($code, self::TRANSIENT_CODES, true);
    }

    /**
     * The response the last-resort exception handler sends.
     *
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function errorResponse(\Throwable $e, bool $local): array
    {
        if (self::isTransient($e)) {
            return ['status' => 503, 'headers' => ['Retry-After' => '10'], 'body' => '<h1>503 — Please try again</h1><p>' . self::UNAVAILABLE_MESSAGE . '</p>'];
        }
        return ['status' => 500, 'headers' => [], 'body' => $local ? '<pre>' . htmlspecialchars((string) $e) . '</pre>' : '<h1>500 — Something went wrong</h1><p>The error has been logged.</p>'];
    }
}
