<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;

/**
 * Owner request (2026-10-26): the order-feedback comment box starts with a
 * suggested testimonial the client can keep or edit — written from this
 * order's own details (product, quantity, destination) so it reads like
 * theirs, and never the same text this client was offered or left on an
 * earlier order. A one-line input only ever got two or three words back.
 *
 * Deterministic per order (the same order always gets the same suggestion,
 * so a page reload doesn't reshuffle it); the wording varies by order.
 */
final class TestimonialSuggestionService
{
    private const OPENINGS = [
        'Our order of {goods} arrived in {destination} exactly as agreed.',
        'We are very pleased with the {goods} NexaCrest supplied to us in {destination}.',
        'The {goods} shipped to {destination} met our quality expectations in every detail.',
        'This was a smooth order from start to finish — {goods}, delivered to {destination} on schedule.',
    ];

    private const MIDDLES = [
        'The finish and dimensions matched what we approved, and the packing kept every piece safe in transit.',
        'The shipping documents were accurate and on time, which made clearance at our end straightforward.',
        'Communication was clear at every stage, from the quotation to the final documents.',
        'The team kept us updated on production and shipment without us ever having to chase.',
    ];

    private const CLOSINGS = [
        'We look forward to placing our next order with NexaCrest.',
        'We would happily recommend NexaCrest to other importers.',
        'Thank you to the whole team — we will be back for our next shipment.',
        'A reliable partner we are glad to keep working with.',
    ];

    public static function forOrder(int $orderId): string
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            return '';
        }
        $products = OrderProductRepository::forOrder($orderId);
        $previous = self::previousCommentsForClient((int) $order['client_id'], $orderId);
        return self::build($order, $products, $previous, self::earlierOrderReferences((int) $order['client_id'], $orderId));
    }

    /**
     * @param array<string,mixed> $order
     * @param array<int, array<string,mixed>> $products
     * @param string[] $previousComments what this client already has on earlier orders — never repeated
     * @param string[] $earlierOrderReferences the client's earlier orders — their wording is never reused
     */
    public static function build(array $order, array $products, array $previousComments = [], array $earlierOrderReferences = []): string
    {
        $facts = [
            '{goods}' => self::goods($products),
            '{destination}' => self::destination($order),
        ];
        $seed = crc32((string) ($order['order_reference'] ?? $order['id'] ?? ''));
        $combinations = count(self::OPENINGS) * count(self::MIDDLES) * count(self::CLOSINGS);
        $taken = array_map(static fn ($c): string => trim((string) $c), $previousComments);
        $usedCombinations = [];
        foreach ($earlierOrderReferences as $reference) {
            $usedCombinations[crc32((string) $reference) % $combinations] = true;
        }
        if (count($usedCombinations) >= $combinations) {
            $usedCombinations = [];
        }

        $text = '';
        for ($try = 0; $try < $combinations; $try++) {
            $n = ($seed + $try) % $combinations;
            if (isset($usedCombinations[$n])) {
                continue;
            }
            $text = strtr(
                self::OPENINGS[$n % 4] . ' ' . self::MIDDLES[intdiv($n, 4) % 4] . ' ' . self::CLOSINGS[intdiv($n, 16) % 4],
                $facts
            );
            if (!in_array($text, $taken, true)) {
                break;
            }
        }
        return $text;
    }

    /** @param array<int, array<string,mixed>> $products */
    private static function goods(array $products): string
    {
        $products = array_values(array_filter($products, static fn (array $p): bool => (int) ($p['is_active'] ?? 1) === 1));
        if ($products === []) {
            return 'goods';
        }
        $first = $products[0];
        $name = trim((string) ($first['description'] ?? '')) ?: 'goods';
        if (count($products) > 1) {
            return $name . ' and the rest of the order';
        }
        if (empty($first['quantity_is_tbc']) && (float) ($first['quantity'] ?? 0) > 0) {
            $qty = rtrim(rtrim(number_format((float) $first['quantity'], 3, '.', ','), '0'), '.');
            return trim($qty . ' ' . ($first['unit'] ?? '')) . ' of ' . $name;
        }
        return $name;
    }

    /** @param array<string,mixed> $order */
    private static function destination(array $order): string
    {
        foreach (['port_of_discharge_city', 'port_of_discharge_name', 'port_of_discharge_text', 'country_of_destination', 'consignee_country'] as $key) {
            $value = trim((string) ($order[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return 'our port';
    }

    /** @return string[] */
    private static function earlierOrderReferences(int $clientId, int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT order_reference FROM orders WHERE client_id = :client_id AND id < :order_id');
        $stmt->execute(['client_id' => $clientId, 'order_id' => $orderId]);
        return array_column($stmt->fetchAll(), 'order_reference');
    }

    /** @return string[] */
    private static function previousCommentsForClient(int $clientId, int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT comments FROM order_feedback WHERE client_id = :client_id AND order_id <> :order_id AND comments IS NOT NULL'
        );
        $stmt->execute(['client_id' => $clientId, 'order_id' => $orderId]);
        return array_column($stmt->fetchAll(), 'comments');
    }
}
