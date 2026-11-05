<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\ProductCategoryRepository;

/**
 * Owner decision D-257 Phase 1 (5 Nov 2026): what a generated document
 * takes from the product categories of the order's goods lines — the
 * packing words, the quality documents to list, and which T&C clause
 * sets print.
 *
 * A Natural Stone order (every goods line in the system category Natural
 * Stone — whose own settings never change a document — or in a category
 * whose clause set is natural_stone or empty, or no goods lines at all)
 * gets exactly today's words: "Wooden Crates", "Crate",
 * "Net Weight (stone only)", "Material / Stone Type", the stone tolerance,
 * no extra quality documents and the stone clause set. That is proved by
 * StoneDocumentsUnchangedTest against captures made before Phase 1.
 *
 * Port: src/services/categoryDocumentProfile.js.
 */
final class CategoryDocumentProfile
{
    public const STONE_SET = 'natural_stone';
    public const STONE_PACKING = 'Wooden Crates';
    public const STONE_TOLERANCE = '±2 mm on L and W · ±0.5 mm on thickness';
    public const GENERAL_TOLERANCE = 'As per the approved drawing / specification';

    /** @return array<string,mixed> */
    public static function stone(): array
    {
        return [
            'stone_only'       => true,
            'clause_sets'      => [self::STONE_SET],
            'packing_label'    => self::STONE_PACKING,
            'package_noun'     => 'Crate',
            'package_nouns'    => 'Crates',
            'package_lower'    => 'crate',
            'package_upper'    => 'CRATE',
            'package_count_label' => 'No. of Packages / Crates',
            'net_weight_label' => 'Net Weight (stone only)',
            'material_label'   => 'Material / Stone Type',
            'tolerance_default' => self::STONE_TOLERANCE,
            'quality_documents' => [],
        ];
    }

    /** @return array<string,mixed> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT COALESCE(op.category_id, (SELECT pc.id FROM product_categories pc WHERE pc.is_system = 1 ORDER BY pc.id LIMIT 1)) AS cid
               FROM order_products op
              WHERE op.order_id = :o AND op.line_type = 'goods' AND op.is_active = 1"
        );
        $stmt->execute(['o' => $orderId]);
        $categories = [];
        foreach (array_filter(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))) as $cid) {
            $c = ProductCategoryRepository::find($cid);
            if ($c !== null) {
                $categories[] = $c;
            }
        }
        return self::fromCategories($categories);
    }

    /**
     * @param list<array<string,mixed>> $categories
     * @return array<string,mixed>
     */
    public static function fromCategories(array $categories): array
    {
        $profile = self::stone();
        if ($categories === []) {
            return $profile;
        }
        usort($categories, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $sets = [];
        $packings = [];
        $quality = [];
        $allStone = true;
        foreach ($categories as $c) {
            if (!empty($c['is_system'])) {
                // Natural Stone (the system category) always prints today's documents,
                // whatever its settings say: a change there would alter every current
                // PDF, which needs the owner's double confirmation (CLAUDE.md).
                $sets[self::STONE_SET] = true;
                $packings[self::STONE_PACKING] = true;
                continue;
            }
            $set = trim((string) ($c['tc_clause_set'] ?? '')) ?: self::STONE_SET;
            $sets[$set] = true;
            $isStone = $set === self::STONE_SET;
            $allStone = $allStone && $isStone;
            $packing = trim((string) ($c['packing_type'] ?? ''));
            $packings[$packing !== '' ? $packing : ($isStone ? self::STONE_PACKING : 'Packages')] = true;
            foreach (ProductCategoryRepository::qualityDocuments($c) as $doc) {
                $quality[mb_strtolower($doc)] ??= ['name' => $doc, 'category' => (string) $c['name']];
            }
        }
        $profile['clause_sets'] = array_keys($sets);
        sort($profile['clause_sets']);
        $profile['quality_documents'] = array_values($quality);
        if ($allStone) {
            // Still today's words, even if a stone category names its own packing.
            return $profile;
        }
        $packingLabels = array_keys($packings);
        return array_merge($profile, [
            'stone_only'       => false,
            'packing_label'    => count($packingLabels) === 1 ? $packingLabels[0] : 'Packages',
            'package_noun'     => 'Package',
            'package_nouns'    => 'Packages',
            'package_lower'    => 'package',
            'package_upper'    => 'PACKAGE',
            'package_count_label' => 'No. of Packages',
            'net_weight_label' => 'Net Weight (goods only)',
            'material_label'   => 'Material / Product Type',
            'tolerance_default' => self::GENERAL_TOLERANCE,
        ]);
    }
}
