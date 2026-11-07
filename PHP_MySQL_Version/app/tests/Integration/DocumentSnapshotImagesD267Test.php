<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Services\RenderContextImages;
use App\Tests\Support\DbTestCase;

/**
 * D-267 (pending owner double confirmation): a document's stored render snapshot keeps each
 * image once, by SHA-256, in document_image_blobs instead of inline — the snapshot comes back
 * identical, a missing or altered image is refused, old inline snapshots still load, and the
 * image rows can never be changed or deleted. Node twin: documentSnapshotImagesD267.test.js.
 */
final class DocumentSnapshotImagesD267Test extends DbTestCase
{
    private function dataUri(string $seed, int $bytes): string
    {
        $raw = substr(str_repeat(hash('sha256', $seed, true), intdiv($bytes, 32) + 1), 0, $bytes);
        return 'data:image/png;base64,' . base64_encode($raw);
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        $seal = $this->dataUri('seal-' . bin2hex(random_bytes(4)), 400000);
        return [
            'meta' => ['document_reference' => 'NCI/PI/TEST', 'note' => 'data: not an image'],
            'assets' => ['logo_data_uri' => $this->dataUri('logo-' . bin2hex(random_bytes(4)), 20000), 'seal_data_uri' => $seal, 'signature_data_uri' => null],
            'signatory' => ['name' => 'Managing Director', 'company_seal_data_uri' => $seal, 'seal_data_uri' => $this->dataUri('dseal-' . bin2hex(random_bytes(4)), 30000)],
            'watermark' => ['enabled' => true, 'opacity' => 0.35, 'image_data_uri' => $this->dataUri('wm-' . bin2hex(random_bytes(4)), 10000)],
            'lines' => [['qty' => '120.0', 'rate' => 45.5], ['qty' => '80', 'rate' => 32.25]],
        ];
    }

    private function documentId(): int
    {
        $this->allowHandTypedGoodsLines();
        $orderId = $this->createTestOrder($this->createTestClient('d267-' . bin2hex(random_bytes(4)) . '@example.test'), 'CIF', 'Standard — New Buyer', 'USD');
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        $r = DocumentGenerationService::generate($orderId, 'PI', $this->createTestUser('Admin'), null, false, true);
        return (int) $r['document_id'];
    }

    private function rawColumn(int $id): string
    {
        $s = Database::connection()->prepare('SELECT render_context_json FROM documents WHERE id = :id');
        $s->execute(['id' => $id]);
        return (string) $s->fetchColumn();
    }

    private function setRaw(int $id, string $json): void
    {
        Database::connection()->prepare('UPDATE documents SET render_context_json = :j WHERE id = :id')->execute(['j' => $json, 'id' => $id]);
    }

    public function testTheSnapshotKeepsReferencesNotImagesAndComesBackIdentical(): void
    {
        $id = $this->documentId();
        $context = $this->context();
        $inline = strlen((string) json_encode($context));
        DocumentRepository::storeRenderContext($id, $context);

        $raw = $this->rawColumn($id);
        self::assertStringNotContainsString(';base64,', $raw, 'no image is kept inside the snapshot');
        self::assertStringContainsString('"__image_ref":"sha256:', $raw);
        self::assertLessThan($inline / 50, strlen($raw), 'the snapshot is a small fraction of its old size');
        self::assertSame($context, DocumentRepository::renderContext($id), 'every value, every image byte, comes back exactly');
    }

    public function testAnImageUsedTwiceIsStoredOnce(): void
    {
        $context = $this->context();
        $packed = RenderContextImages::pack($context);
        self::assertSame($packed['assets']['seal_data_uri'], $packed['signatory']['company_seal_data_uri']);
        RenderContextImages::pack($context); // a second document with the same seal
        $sha = substr($packed['assets']['seal_data_uri']['__image_ref'], 7);
        $n = Database::connection()->prepare('SELECT COUNT(*) FROM document_image_blobs WHERE sha256 = :s');
        $n->execute(['s' => $sha]);
        self::assertSame(1, (int) $n->fetchColumn());
        self::assertSame(hash('sha256', $context['assets']['seal_data_uri']), $sha);
    }

    public function testAMissingImageIsRefusedNamingTheDocumentAndTheImage(): void
    {
        $id = $this->documentId();
        $missing = str_repeat('ab', 32);
        $this->setRaw($id, (string) json_encode(['assets' => ['seal_data_uri' => ['__image_ref' => 'sha256:' . $missing, 'store' => 'document_image_blobs', 'mime' => 'image/png', 'length' => 10]]]));
        try {
            DocumentRepository::renderContext($id);
            self::fail('a missing image must stop the re-render');
        } catch (\RuntimeException $e) {
            self::assertSame(sprintf('Document #%d cannot be re-rendered: its stored image "assets.seal_data_uri" (sha256 %s) is missing from document_image_blobs. Nothing was changed; restore that image from a backup.', $id, $missing), $e->getMessage());
        }
    }

    public function testAnAlteredImageIsRefused(): void
    {
        $id = $this->documentId();
        $claimed = hash('sha256', 'the original seal ' . bin2hex(random_bytes(4)));
        Database::connection()->prepare('INSERT INTO document_image_blobs (sha256, mime_type, byte_length, data_uri) VALUES (:s, :m, :l, :d)')
            ->execute(['s' => $claimed, 'm' => 'image/png', 'l' => 30, 'd' => 'data:image/png;base64,QUxURVJFRA==']);
        $this->setRaw($id, (string) json_encode(['signatory' => ['seal_data_uri' => ['__image_ref' => 'sha256:' . $claimed, 'store' => 'document_image_blobs', 'mime' => 'image/png', 'length' => 30]]]));
        try {
            DocumentRepository::renderContext($id);
            self::fail('an altered image must stop the re-render');
        } catch (\RuntimeException $e) {
            self::assertSame(sprintf('Document #%d cannot be re-rendered: its stored image "signatory.seal_data_uri" does not match its SHA-256 (%s) — it has been altered. Nothing was changed; restore that image from a backup.', $id, $claimed), $e->getMessage());
        }
    }

    public function testASnapshotStoredBeforeTheChangeStillLoadsAsItWas(): void
    {
        $id = $this->documentId();
        $context = $this->context();
        $this->setRaw($id, (string) json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION));
        self::assertSame($context, DocumentRepository::renderContext($id));
    }

    public function testStoredImagesCanNeverBeChangedOrDeleted(): void
    {
        $packed = RenderContextImages::pack(['x' => $this->dataUri('lock-' . bin2hex(random_bytes(4)), 100)]);
        $sha = substr($packed['x']['__image_ref'], 7);
        foreach (["UPDATE document_image_blobs SET data_uri = 'data:image/png;base64,AA==' WHERE sha256 = :s", 'DELETE FROM document_image_blobs WHERE sha256 = :s'] as $sql) {
            try {
                Database::connection()->prepare($sql)->execute(['s' => $sha]);
                self::fail('the database must refuse: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('Document images are kept for record', $e->getMessage());
            }
        }
    }

    public function testTheFinalCopyIsRebuiltFromTheNewSnapshot(): void
    {
        $id = $this->documentId();
        self::assertStringNotContainsString(';base64,', $this->rawColumn($id), 'generate() stores references only');
        DocumentGenerationService::finalizeApproval($id);
        self::assertNotEmpty(DocumentRepository::find($id)['pdf_file_id']);
    }
}
