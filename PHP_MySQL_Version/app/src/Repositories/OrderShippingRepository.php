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
                (order_id, shipping_line, vessel_name, voyage_number, etd, eta, container_type, container_count, container_no, seal_no)
             VALUES (:order_id, :shipping_line, :vessel, :voyage, :etd, :eta, :container_type, :container_count, :container_no, :seal_no)
             ON DUPLICATE KEY UPDATE
                shipping_line = VALUES(shipping_line), vessel_name = VALUES(vessel_name),
                voyage_number = VALUES(voyage_number), etd = VALUES(etd), eta = VALUES(eta),
                container_type = VALUES(container_type), container_count = VALUES(container_count),
                container_no = VALUES(container_no), seal_no = VALUES(seal_no)'
        )->execute([
            'order_id'        => $orderId,
            'shipping_line'   => $data['shipping_line'] ?? null,
            'vessel'          => $data['vessel_name'] ?? null,
            'voyage'          => $data['voyage_number'] ?? null,
            'etd'             => ($data['etd'] ?? null) ?: null,
            'eta'             => ($data['eta'] ?? null) ?: null,
            'container_type'  => $data['container_type'] ?? null,
            'container_count' => ($data['container_count'] ?? null) ?: null,
            'container_no'    => $data['container_no'] ?? null,
            'seal_no'         => $data['seal_no'] ?? null,
        ]);
    }

    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_shipping WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * BL issued: the actual gate that lets Commercial Invoice (Stage 8) be
     * generated — CI's date must match the BL date. Upserts (QA Round 4,
     * N-02) because staff can reach "Confirm BL Issued" without ever
     * having saved "Shipping / Vessel Details" first — a plain UPDATE
     * would silently affect zero rows and the BL number/date would never
     * actually be stored anywhere, even though the stage gate still passes.
     */
    public static function recordBl(int $orderId, string $blNumber, string $blDate): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_shipping (order_id, bl_number, bl_date)
             VALUES (:order_id, :bl_number, :bl_date)
             ON DUPLICATE KEY UPDATE bl_number = VALUES(bl_number), bl_date = VALUES(bl_date)'
        )->execute(['bl_number' => $blNumber, 'bl_date' => $blDate, 'order_id' => $orderId]);
    }

    /**
     * Attaches the actual scanned BL document to the already-recorded BL
     * number/date. Upserts (N-02) for the same reason as recordBl() above
     * — the scanned copy can be attached in a separate step from
     * confirming the BL number, and the row still might not exist yet.
     */
    public static function attachBlCopy(int $orderId, int $fileId): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_shipping (order_id, bl_copy_file_id)
             VALUES (:order_id, :file_id)
             ON DUPLICATE KEY UPDATE bl_copy_file_id = VALUES(bl_copy_file_id)'
        )->execute(['file_id' => $fileId, 'order_id' => $orderId]);
    }

    /** Upserts (N-02) — see recordBl() above. */
    public static function recordBlOriginalsReceived(int $orderId, int $count): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_shipping (order_id, bl_originals_received_at, bl_originals_received_count)
             VALUES (:order_id, NOW(), :count)
             ON DUPLICATE KEY UPDATE bl_originals_received_at = NOW(), bl_originals_received_count = VALUES(bl_originals_received_count)'
        )->execute(['count' => $count, 'order_id' => $orderId]);
    }

    /** Upserts (N-02) — see recordBl() above. */
    public static function recordBlEndorsed(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_shipping (order_id, bl_endorsed_at, bl_endorsed_by)
             VALUES (:order_id, NOW(), :user_id)
             ON DUPLICATE KEY UPDATE bl_endorsed_at = NOW(), bl_endorsed_by = VALUES(bl_endorsed_by)'
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

    /**
     * docs/schema.sql Section BS — records the draft BL most recently
     * received from the CHA as the order's CURRENT one (clearing any
     * earlier approval — a new draft always needs its own review).
     * Upserts because a draft can arrive before "Shipping / Vessel
     * Details" has ever been saved, i.e. before any order_shipping row
     * exists yet.
     */
    public static function attachDraftBl(int $orderId, int $fileId): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_shipping (order_id, draft_bl_file_id, draft_bl_uploaded_at, draft_bl_approved_at, draft_bl_approved_by)
             VALUES (:order_id, :file_id, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE
                draft_bl_file_id = VALUES(draft_bl_file_id),
                draft_bl_uploaded_at = VALUES(draft_bl_uploaded_at),
                draft_bl_approved_at = NULL,
                draft_bl_approved_by = NULL'
        )->execute(['order_id' => $orderId, 'file_id' => $fileId]);
    }

    public static function approveDraftBl(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_shipping SET draft_bl_approved_at = NOW(), draft_bl_approved_by = :user_id WHERE order_id = :order_id'
        )->execute(['user_id' => $userId, 'order_id' => $orderId]);
    }
}
