<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Services\Docx\DocxDocumentBuilder;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision C-16 (confirmed twice, 1 Nov 2026): under CFR the buyer
 * arranges the insurance. The Buyer PO's freight sentence follows the
 * incoterm (CIF freight and insurance, CFR freight only, FOB none), and the
 * "Shipment Booking (CFR/CIF)" clause asks CFR buyers for freight only.
 * The Node suite has the same test (cfrWording.test.js).
 */
final class CfrWordingTest extends DbTestCase
{
    private const CIF = 'Freight & Insurance: invoiced separately by Debit Note before shipment.';
    private const CFR = 'Freight: invoiced separately by Debit Note before shipment (insurance arranged by the buyer).';

    private function text(int $fileId, bool $docx): string
    {
        $path = (string) FileStoreRepository::find($fileId)['server_path'];
        $raw = $docx ? (string) shell_exec('unzip -p ' . escapeshellarg($path) . ' word/document.xml') : (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' -');
        $raw = $docx ? (string) preg_replace('/<[^>]+>/', '', $raw) : $raw;
        return (string) preg_replace('/\s+/', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_XML1));
    }

    public function testTheSentenceFollowsTheIncoterm(): void
    {
        self::assertSame(' ' . self::CIF, DocxDocumentBuilder::freightTermsSentence('CIF'));
        self::assertSame(' ' . self::CFR, DocxDocumentBuilder::freightTermsSentence('cfr'));
        self::assertSame('', DocxDocumentBuilder::freightTermsSentence('FOB'));
    }

    public function testTheBuyerPoPdfAndWordPrintTheRightSentence(): void
    {
        $user = $this->createTestUser('Admin');
        foreach (['CIF' => self::CIF, 'CFR' => self::CFR, 'FOB' => null] as $incoterm => $expected) {
            $orderId = $this->createTestOrder($this->createTestClient(), $incoterm);
            $r = DocumentGenerationService::generate($orderId, 'BUYERPO', $user, null, true, true);
            foreach ([$this->text((int) $r['pdf_file_id'], false), $this->text((int) $r['docx_file_id'], true)] as $i => $text) {
                $what = "{$incoterm} " . ($i === 0 ? 'PDF' : 'Word');
                self::assertStringNotContainsString('(CFR/CIF): invoiced separately', $text, "{$what}: the old sentence is gone");
                if ($expected === null) {
                    self::assertStringNotContainsString('invoiced separately by Debit Note', $text, "{$what}: no freight sentence on FOB");
                } else {
                    self::assertStringContainsString($expected, $text, $what);
                }
            }
        }
    }

    public function testTheShipmentBookingClauseAsksCfrBuyersForFreightOnly(): void
    {
        $clause = (string) Database::connection()->query("SELECT clause_text FROM tc_clauses WHERE clause_title = 'Shipment Booking (CFR/CIF)'")->fetchColumn();
        self::assertSame("Shipment Booking: For CFR/CIF orders, shipment booking will be confirmed only after full freight payment (and, for CIF orders, the insurance premium) is received and cleared in NexaCrest's bank account against the Debit Note. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is verified.", $clause);
        $user = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), 'CFR');
        $r = DocumentGenerationService::generate($orderId, 'PI', $user, null, false, true);
        $text = $this->text((int) $r['pdf_file_id'], false);
        self::assertStringContainsString('full freight payment (and, for CIF orders, the insurance premium)', $text);
        self::assertStringNotContainsString('full freight and insurance payment', $text);
    }
}
