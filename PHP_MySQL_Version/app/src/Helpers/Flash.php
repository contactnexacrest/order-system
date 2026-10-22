<?php

declare(strict_types=1);

namespace App\Helpers;

final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type:string, message:string}> */
    public static function pull(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $messages;
    }

    /**
     * Batch 3 #4 — carries a form's submitted values across a redirect-on-validation-failure, so
     * the create/edit view can re-populate the form instead of the buyer/client having to retype
     * everything. Call right before the redirect on failure; never call on success (the next
     * request's old() read-and-clear is a one-shot, so a leftover value would wrongly resurface
     * on some unrelated later visit to the same form if the form were never actually re-shown).
     *
     * @param array<string, mixed> $data usually $_POST as-is
     */
    public static function setOld(array $data): void
    {
        $_SESSION['_flash_old'] = $data;
    }

    /** Returns and clears the old() data in one step — same read-once contract as pull(). */
    public static function pullOld(): array
    {
        $old = $_SESSION['_flash_old'] ?? [];
        unset($_SESSION['_flash_old']);
        return $old;
    }

    /**
     * External QA report R-02: `catch (\Throwable $e) { Flash::set('error',
     * $e->getMessage()); }` is this app's standard way (~30 call sites) to
     * surface a deliberately-thrown business exception's message (e.g.
     * `new \RuntimeException('friendly text here')`) to the user — correct
     * for that case. The bug is that the same broad `\Throwable` catch also
     * catches genuinely unexpected errors (a PDOException with raw SQL
     * internals, a TypeError from malformed input, ...) and shows THEIR raw
     * message the exact same way, leaking internals. This shows the real
     * message only for the one exception type this codebase actually
     * throws on purpose for a user-facing reason; anything else gets a
     * generic message instead, with the real error still logged.
     */
    public static function setErrorFromException(\Throwable $e, string $fallback = 'Something went wrong. Please try again.'): void
    {
        self::set('error', self::describeException($e, $fallback));
    }

    /**
     * Same decision as setErrorFromException(), for the handful of call
     * sites that build a longer flash message with the exception text
     * embedded in a friendlier sentence (e.g. "X saved, but the upload
     * failed: {reason}") rather than setting the flash directly.
     */
    public static function describeException(\Throwable $e, string $fallback = 'Something went wrong. Please try again.'): string
    {
        // \PDOException extends \RuntimeException in PHP's own SPL
        // hierarchy, so it would otherwise pass the check below and leak
        // raw SQLSTATE/driver text — exactly the R-02 bug, just one level
        // deeper. It must always be treated as unexpected, never trusted.
        if ($e instanceof \RuntimeException && !($e instanceof \PDOException)) {
            return $e->getMessage();
        }
        error_log('[UNEXPECTED ERROR shown generically] ' . $e->getMessage());
        return $fallback;
    }
}
