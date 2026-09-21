<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Real gap this closes: the client portal (a real buyer's own login, not
 * an internal staff screen) printed raw MySQL DATETIME strings verbatim
 * — "2026-09-21 03:26:42" — right next to documents whose own PDFs render
 * every date as "21 September 2026" (DocumentDataAssembler::formatDate()).
 * A client comparing their order list against the document they just
 * downloaded would see two different date styles for the same system.
 * This gives the plain-PHP view layer (client portal today; any other
 * client- or staff-facing screen going forward) the same human format,
 * without pulling the document-generation service into a view concern it
 * has nothing to do with.
 */
final class Dates
{
    /**
     * 'YYYY-MM-DD' -> '21 September 2026'; a full DATETIME also gets a
     * ', HH:MM AM/PM' suffix, since a "when did this happen" timestamp
     * (order created, document generated) is more useful with a time than
     * a bare date is.
     */
    public static function human(?string $value): string
    {
        if (!$value) {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return $value;
        }
        $hasTime = (bool) preg_match('/\d{1,2}:\d{2}/', $value);
        return $hasTime ? $dt->format('d F Y, h:i A') : $dt->format('d F Y');
    }
}
