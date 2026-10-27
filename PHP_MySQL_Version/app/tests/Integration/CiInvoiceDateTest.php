<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DocumentController;
use App\Repositories\DocumentRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-27): the Commercial Invoice can be dated today or
 * earlier (e.g. to match the BL). The picked date is what the CI and the
 * COO prep sheet print; left as today, nothing changes.
 */
final class CiInvoiceDateTest extends DbTestCase
{
    public function testAPickedDateIsStoredAndUsedWhereverTheCiDateIsPrinted(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');
        $date = date('Y-m-d', strtotime('-3 days'));

        $ci = DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true, $date); // override: out of sequence in a fresh test order

        $document = DocumentRepository::find((int) $ci['document_id']);
        self::assertSame($date, $document['document_date']);
        self::assertSame($date, DocumentRepository::printedDate($document));
        $printed = (new \DateTimeImmutable($date))->format('j F Y');
        self::assertSame($printed, DocumentDataAssembler::assemble($orderId, 'COOPREP')['order']['ci_date'], 'the COO prep sheet shows the CI date that was picked');
    }

    public function testLeftAsTodayTheCiCarriesTheDayItWasGenerated(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');

        $ci = DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);

        $document = DocumentRepository::find((int) $ci['document_id']);
        self::assertNull($document['document_date']);
        self::assertSame($document['generated_at'], DocumentRepository::printedDate($document));
    }

    public function testAFutureInvoiceDateIsRefused(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');
        $_POST = ['document_type' => 'CI', 'document_date' => date('Y-m-d', strtotime('+1 day'))];

        ob_start();
        (new DocumentController())->generate(['id' => (string) $orderId]);
        ob_get_clean();

        self::assertNull(DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CI'));
        $_POST = [];
    }
}
