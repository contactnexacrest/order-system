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
}
