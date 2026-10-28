<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Config\Env;
use App\Controllers\OrderCommentController;
use App\Controllers\ReferenceDocController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5:
 *  - R5-16: with email sending off, posting in Order Communication must
 *    not claim the client was emailed.
 *  - R5-18: seeded Reference Library documents keep the
 *    __STORAGE_BASE_PATH__ placeholder in their path; downloads resolve it.
 */
final class MailOffWordingAndReferencePathTest extends DbTestCase
{
    protected function tearDown(): void
    {
        \App\Helpers\Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        Flash::pull();
    }

    private function setMail(string $value): void
    {
        Database::connection()->prepare("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'mail_sending_enabled'")->execute(['v' => $value]);
        if (method_exists(CompanySettingsRepository::class, 'resetCache')) {
            CompanySettingsRepository::resetCache();
        }
    }

    public function testPostingWithEmailSendingOffSaysTheClientWasNotEmailed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $original = (string) (CompanySettingsRepository::get('mail_sending_enabled') ?? '0');
        $this->setMail('0');
        try {
            $_POST = ['body' => 'Your goods are being packed.'];
            (new OrderCommentController())->post(['id' => (string) $orderId]);
            $message = Flash::pull()[0]['message'];
            self::assertStringContainsString('NOT emailed', $message);
            self::assertStringNotContainsString('has been emailed', $message);
        } finally {
            $this->setMail($original);
        }
    }

    public function testASeededReferenceDocumentPathWithThePlaceholderDownloads(): void
    {
        $name = 'r5-18-' . bin2hex(random_bytes(4)) . '.txt';
        $realPath = rtrim(Env::storageBasePath(), '/') . '/' . $name;
        file_put_contents($realPath, 'reference library test');
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO reference_library_documents (title, file_path, file_original_name, file_mime_type) VALUES ('R5-18 test', :p, 'test.txt', 'text/plain')")
            ->execute(['p' => '__STORAGE_BASE_PATH__/' . $name]);
        $id = (int) $pdo->lastInsertId();

        http_response_code(200);
        ob_start();
        (new ReferenceDocController())->customDownload(['id' => (string) $id]);
        $output = (string) ob_get_clean();
        @unlink($realPath);

        self::assertSame(200, http_response_code());
        self::assertSame('reference library test', $output);
    }
}
