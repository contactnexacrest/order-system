<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Repositories\OrderRepository;

/**
 * QA Round 5, R5-25: a document's name in a message — its type and
 * reference ("QT SC/QT/2026/…"). Some internal documents (COOPREP) have no
 * reference of their own, which printed "COOPREP null" (Node) or
 * "COOPREP  " (PHP); those are named by their order instead ("COOPREP for
 * order SC/ORD/…"). Mirrors documentLabel.js on the Node stack.
 */
final class DocumentLabel
{
    public static function for(string $typeCode, ?string $reference, int $orderId): string
    {
        if ($reference !== null && trim($reference) !== '') {
            return "{$typeCode} {$reference}";
        }
        $order = OrderRepository::find($orderId);
        return $order ? "{$typeCode} for order {$order['order_reference']}" : $typeCode;
    }
}
