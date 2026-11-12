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
    /** D-336: one block per annexure — Annexure A unless $which is 'B'. */
    public static function find(int $orderId, string $which = 'A'): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_annexure_terms WHERE order_id = :order_id AND `which` = :which LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId, 'which' => OrderAnnexureRepository::which($which)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function upsert(int $orderId, string $contentHtml, int $updatedBy, string $which = 'A'): void
    {
        $which = OrderAnnexureRepository::which($which);
        $pdo = Database::connection();
        $existing = self::find($orderId, $which);
        if ($existing) {
            $pdo->prepare(
                'UPDATE order_annexure_terms SET content_html = :content_html, updated_by = :updated_by WHERE order_id = :order_id AND `which` = :which'
            )->execute(['content_html' => $contentHtml, 'updated_by' => $updatedBy, 'order_id' => $orderId, 'which' => $which]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO order_annexure_terms (order_id, `which`, content_html, updated_by) VALUES (:order_id, :which, :content_html, :updated_by)'
        )->execute(['order_id' => $orderId, 'which' => $which, 'content_html' => $contentHtml, 'updated_by' => $updatedBy]);
    }
}
