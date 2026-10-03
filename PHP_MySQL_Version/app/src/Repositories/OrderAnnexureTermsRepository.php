<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * The single free-form "Additional Terms" rich-text block per order (one
 * row, unlike order_annexure_products which is many-per-order) — see
 * docs/schema.sql Section AT.
 */
final class OrderAnnexureTermsRepository
{
    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_annexure_terms WHERE order_id = :order_id LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function upsert(int $orderId, string $contentHtml, int $updatedBy): void
    {
        $pdo = Database::connection();
        $existing = self::find($orderId);
        if ($existing) {
            $pdo->prepare(
                'UPDATE order_annexure_terms SET content_html = :content_html, updated_by = :updated_by WHERE order_id = :order_id'
            )->execute(['content_html' => $contentHtml, 'updated_by' => $updatedBy, 'order_id' => $orderId]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO order_annexure_terms (order_id, content_html, updated_by) VALUES (:order_id, :content_html, :updated_by)'
        )->execute(['order_id' => $orderId, 'content_html' => $contentHtml, 'updated_by' => $updatedBy]);
    }
}
