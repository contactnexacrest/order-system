<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Owner decision 13 Nov 2026 (QA item 12, confirm 1 + confirm 2): a goods line that has dimensions and finish but no
 * category attributes prints "Dimensions: … · Finish: … · HS Code: … · Country of Origin: India" on the PDF. PHP
 * always did (an empty array is false in Twig); this test pins PHP's output, which the Node templates now match
 * (they tested `{% if p.attributes %}`, and an empty array is true in Nunjucks). PHP did not change.
 * Node twin: tests/integration/documentSpecLineItem12.test.js.
 */
final class DocumentSpecLineItem12Test extends DbTestCase
{
    private const SPEC = 'Dimensions: 300 x 60 x 2 cm · Finish: Polished · HS Code: 68022310 · Country of Origin: India';

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return trim((string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -')));
    }

    /** @return list<string> the spec lines under the goods lines ("1. … HS Code: … · Country of Origin: India") */
    private static function specLines(string $text): array
    {
        preg_match_all('/\b\d+\. (?:(?!\b\d+\. )[^·]){0,80}?(?:· [^·]{0,80}?){0,4}Country of Origin: India/u', $text, $m);
        return $m[0];
    }

    public static function layouts(): array
    {
        return [['classic', 'SQM', ['QT', 'PI', 'CI']], ['detailed', 'KG', ['QT', 'PI', 'OC', 'CI']]];
    }

    /** @param list<string> $codes */
    #[DataProvider('layouts')]
    public function testItem12PdfSpecLineCarriesDimensionsAndFinishWithoutCategoryAttributes(string $layout, string $unit, array $codes): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $pdo = Database::connection();
        $pdo->prepare('UPDATE orders SET line_table_layout = :layout WHERE id = :id')->execute(['layout' => $layout, 'id' => $orderId]);
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, $unit, '45.50');
        $pdo->prepare("UPDATE order_products SET hs_code = '68022310' WHERE order_id = :id")->execute(['id' => $orderId]);

        $evidenceDir = (string) getenv('ITEM12_EVIDENCE_DIR');
        $found = [];
        foreach ($codes as $code) {
            $doc = DocumentGenerationService::generate($orderId, $code, $userId, null, false, true);
            $lines = self::specLines($this->pdfText((int) $doc['pdf_file_id']));
            if ($evidenceDir !== '') {
                file_put_contents("{$evidenceDir}/php_{$layout}_{$code}_pdf_spec_lines.txt", implode("\n", $lines) . "\n");
            }
            $found[$code] = array_filter($lines, static fn (string $l): bool => str_ends_with($l, self::SPEC)) !== [];
        }
        self::assertSame(array_fill_keys($codes, true), $found, "Item 12: the {$layout} PDF spec line prints Dimensions and Finish");
    }
}
