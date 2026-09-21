<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Single source of truth for the user's explicit standing instruction
 * that every reason field across the app have a minimum length — a
 * one-character "x" satisfies "not empty" but documents nothing useful
 * on the audit trail. Originally enforced only in
 * SuperAdminService/PermissionAdminController; this closes the gap for
 * every other reason field (settings edits, field-protection requests,
 * admin overrides, amendment requests, force password resets, marking an
 * order lost) that previously only checked for non-empty.
 */
final class ReasonValidator
{
    public const MIN_LENGTH = 10;

    /** @return string|null an error message if invalid, null if the reason passes */
    public static function check(string $reason): ?string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            return 'A reason is required — nothing was saved.';
        }
        if (mb_strlen($trimmed) < self::MIN_LENGTH) {
            return 'Reason must be at least ' . self::MIN_LENGTH . ' characters — describe why this change is being made.';
        }
        return null;
    }
}
