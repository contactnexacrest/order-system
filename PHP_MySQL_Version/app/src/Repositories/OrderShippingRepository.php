<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderShippingRepository
{
    public static function upsert(int $orderId, array $data): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_shipping
                (order_id, shipping_line, vessel_name, voyage_number, etd, eta, container_type, container_no, seal_no)
             VALUES (:order_id, :shipping_line, :vessel, :voyage, :etd, :eta, :container_type, :container_no, :seal_no)
             ON DUPLICATE KEY UPDATE
                shipping_line = VALUES(shipping_line), vessel_name = VALUES(vessel_name),
                voyage_number = VALUES(voyage_number), etd = VALUES(etd), eta = VALUES(eta),
                container_type = VALUES(container_type), container_no = VALUES(container_no), seal_no = VALUES(seal_no)'
        )->execute([
            'order_id'       => $orderId,
            'shipping_line'  => $data['shipping_line'] ?? null,
            'vessel'         => $data['vessel_name'] ?? null,
            'voyage'         => $data['voyage_number'] ?? null,
            'etd'            => $data['etd'] ?: null,
            'eta'            => $data['eta'] ?: null,
            'container_type' => $data['container_type'] ?? null,
            'container_no'   => $data['container_no'] ?? null,
            'seal_no'        => $data['seal_no'] ?? null,
        ]);
    }

    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_shipping WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /** BL issued: the actual gate that lets Commercial Invoice (Stage 8) be generated — CI's date must match the BL date. */
    public static function recordBl(int $orderId, string $blNumber, string $blDate): void
    {
        Database::connection()->prepare(
            'UPDATE order_shipping SET bl_number = :bl_number, bl_date = :bl_date WHERE order_id = :order_id'
        )->execute(['bl_number' => $blNumber, 'bl_date' => $blDate, 'order_id' => $orderId]);
    }

    public static function recordBlOriginalsReceived(int $orderId, int $count): void
    {
        Database::connection()->prepare(
            'UPDATE order_shipping SET bl_originals_received_at = NOW(), bl_originals_received_count = :count WHERE order_id = :order_id'
        )->execute(['count' => $count, 'order_id' => $orderId]);
    }

    public static function recordBlEndorsed(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_shipping SET bl_endorsed_at = NOW(), bl_endorsed_by = :user_id WHERE order_id = :order_id'
        )->execute(['user_id' => $userId, 'order_id' => $orderId]);
    }

    public static function recordCourierSent(int $orderId, string $trackingNumber): void
    {
        Database::connection()->prepare(
            'UPDATE order_shipping SET courier_tracking_number = :tracking, courier_sent_at = NOW() WHERE order_id = :order_id'
        )->execute(['tracking' => $trackingNumber, 'order_id' => $orderId]);
    }

    public static function recordScannedBlSent(int $orderId): void
    {
        Database::connection()->prepare(
            'UPDATE order_shipping SET scanned_bl_sent_to_buyer_at = NOW() WHERE order_id = :order_id'
        )->execute(['order_id' => $orderId]);
    }
}
