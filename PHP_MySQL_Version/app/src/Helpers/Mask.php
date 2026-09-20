<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Spec Section 14 — "DATA MASKING: Client email/phone masked on UI based
 * on permission. Stored plain in DB. Masked at render time." Deliberately
 * a pure display-time transform, not a DB concern — the raw value is what
 * every controller/repository already passes around (documents sent to
 * the buyer need the real address), this only ever runs inside a view
 * just before echoing.
 */
final class Mask
{
    public static function email(?string $email): string
    {
        if ($email === null || $email === '') {
            return '—';
        }
        $at = strpos($email, '@');
        if ($at === false) {
            return str_repeat('•', min(strlen($email), 6));
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $visible = mb_substr($local, 0, 2);
        return $visible . str_repeat('•', max(3, mb_strlen($local) - 2)) . '@' . $domain;
    }

    public static function phone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '—';
        }
        $digitsOnly = preg_replace('/\D/', '', $phone);
        $lastFour = substr($digitsOnly, -4);
        return '•••• ' . $lastFour;
    }
}
