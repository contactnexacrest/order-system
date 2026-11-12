<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Owner decision D-346 (12 Nov 2026): the client's single "Billing Address"
 * box is gone. clients.billing_address (still read by documents, Zoho and the
 * domestic documents) is always composed from the structured parts, the same
 * way on every path (New / Edit / Duplicate Client, the Quotation and PI
 * intake forms): "Line 1, Line 2, City Postcode, Country" — city and postcode
 * joined by a space, everything else by ", ", empty parts skipped.
 * Node twin: src/helpers/clientAddress.js.
 */
final class ClientAddress
{
    public static function compose(?string $line1, ?string $line2, ?string $city, ?string $postcode, ?string $country): string
    {
        $cityPostcode = implode(' ', array_filter(
            [trim((string) $city), trim((string) $postcode)],
            static fn (string $part): bool => $part !== ''
        ));

        return implode(', ', array_filter(
            [trim((string) $line1), trim((string) $line2), $cityPostcode, trim((string) $country)],
            static fn (string $part): bool => $part !== ''
        ));
    }
}
